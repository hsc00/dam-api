<?php

declare(strict_types=1);

namespace App\Tests\Integration\Infrastructure\Persistence;

use App\Domain\Asset\Asset;
use App\Domain\Asset\AssetStatus;
use App\Domain\Asset\Exception\StaleAssetWriteException;
use App\Domain\Asset\ValueObject\AccountId;
use App\Domain\Asset\ValueObject\AssetId;
use App\Domain\Asset\ValueObject\UploadCompletionProofValue;
use App\Domain\Asset\ValueObject\UploadId;
use App\Infrastructure\Persistence\MySQLAssetRepository;
use App\Tests\Integration\Support\CompareAndSwapRacePdo;
use Closure;
use DateTimeImmutable;
use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class MySQLAssetRepositoryTest extends TestCase
{
    private const DATETIME_FORMAT = 'Y-m-d H:i:s.u';
    private const FILE_NAME_COLLATION = 'utf8mb4_0900_ai_ci';
    private const MIGRATION_FILE = __DIR__ . '/../../../../migrations/20260401120000_create_assets_table.sql';
    private const MIME_TYPE = 'image/png';

    /**
     * @var array{host: string, port: int, user: string, password: string}|null
     */
    private ?array $selectedConnection = null;

    #[Test]
    public function itReturnsAssetWhenSavingAndReadingAPendingAsset(): void
    {
        // Arrange & Act
        $this->withTemporaryDatabase(function (PDO $connection): void {
            $repository = new MySQLAssetRepository($connection);
            $asset = $this->pendingAsset(
                assetId: '11111111-1111-4111-8111-111111111111',
                uploadId: '22222222-2222-4222-8222-222222222222',
                accountId: 'account-pending',
                fileName: 'Pending-image.PNG',
                createdAt: '2026-04-01 10:00:00.000000',
            );

            $repository->save($asset);
            $foundById = $repository->findById($asset->getId());
            $foundByUploadId = $repository->findByUploadId($asset->getUploadId());

            // Assert
            self::assertNotNull($foundById);
            self::assertNotNull($foundByUploadId);
            $this->assertAssetMatches($asset, $foundById);
            $this->assertAssetMatches($asset, $foundByUploadId);
        });
    }

    #[Test]
    public function itReturnsAssetWhenSavingAndReadingAnUploadedAsset(): void
    {
        // Arrange & Act
        $this->withTemporaryDatabase(function (PDO $connection): void {
            $repository = new MySQLAssetRepository($connection);
            $asset = $this->uploadedAsset(
                assetId: '33333333-3333-4333-8333-333333333333',
                uploadId: '44444444-4444-4444-8444-444444444444',
                accountId: 'account-uploaded',
                fileName: 'uploaded-image.png',
                completionProof: 'etag-uploaded-asset',
                persistedState: $this->persistedState('2026-04-01 09:00:00.000000', 3, '2026-04-01 09:05:00.000000'),
            );

            $repository->save($asset);
            $foundById = $repository->findById($asset->getId());
            $foundByUploadId = $repository->findByUploadId($asset->getUploadId());

            // Assert
            self::assertNotNull($foundById);
            self::assertNotNull($foundByUploadId);
            $this->assertAssetMatches($asset, $foundById);
            $this->assertAssetMatches($asset, $foundByUploadId);
        });
    }

    #[Test]
    public function itReturnsUpdatedRowWhenAssetStateChanges(): void
    {
        // Arrange & Act
        $this->withTemporaryDatabase(function (PDO $connection): void {
            $repository = new MySQLAssetRepository($connection);
            $asset = $this->pendingAsset(
                assetId: '55555555-5555-4555-8555-555555555555',
                uploadId: '66666666-6666-4666-8666-666666666666',
                accountId: 'account-state-change',
                fileName: 'asset-before-upload.png',
                createdAt: '2020-01-01 12:00:00.000000',
            );

            $repository->save($asset);
            $asset->markUploaded(new UploadCompletionProofValue('etag-after-upload'));
            $repository->save($asset);
            $persistedAsset = $repository->findById($asset->getId());

            // Assert
            self::assertNotNull($persistedAsset);
            self::assertSame(1, $this->countAssets($connection));
            $this->assertAssetMatches($asset, $persistedAsset);
        });
    }

    #[Test]
    public function itReturnsAcceptedStateWhenUpdatedAtRemainsEqual(): void
    {
        // Arrange & Act
        $this->withTemporaryDatabase(function (PDO $connection): void {
            $repository = new MySQLAssetRepository($connection);
            $asset = $this->pendingAsset(
                assetId: '56565656-5656-4565-8565-565656565656',
                uploadId: '67676767-6767-4676-8676-676767676767',
                accountId: 'account-equal-updated-at',
                fileName: 'equal-updated-at.png',
                createdAt: '9999-01-01 00:00:00.000000',
            );
            $originalUpdatedAt = $asset->getUpdatedAt()->format(self::DATETIME_FORMAT);

            $repository->save($asset);
            $asset->markUploaded(new UploadCompletionProofValue('etag-equal-updated-at'));
            $repository->save($asset);
            $persistedAsset = $repository->findById($asset->getId());

            // Assert
            self::assertSame($originalUpdatedAt, $asset->getUpdatedAt()->format(self::DATETIME_FORMAT));
            self::assertNotNull($persistedAsset);
            self::assertSame(1, $this->countAssets($connection));
            $this->assertAssetMatches($asset, $persistedAsset);
        });
    }

    #[Test]
    public function itReturnsSingleRowWhenSavingUnchangedAssetTwice(): void
    {
        // Arrange & Act
        $this->withTemporaryDatabase(function (PDO $connection): void {
            $repository = new MySQLAssetRepository($connection);
            $asset = $this->pendingAsset(
                assetId: '77777777-0000-4777-8777-777777777777',
                uploadId: '88888888-0000-4888-8888-888888888888',
                accountId: 'account-idempotent-save',
                fileName: 'same-asset.png',
                createdAt: '2026-04-01 12:30:00.000000',
            );

            $repository->save($asset);
            $repository->save($asset);
            $persistedAsset = $repository->findById($asset->getId());

            // Assert
            self::assertNotNull($persistedAsset);
            self::assertSame(1, $this->countAssets($connection));
            $this->assertAssetMatches($asset, $persistedAsset);
        });
    }

    #[Test]
    public function itThrowsStaleAssetWriteExceptionWhenSameAssetIdentityChangesImmutableField(): void
    {
        // Arrange & Act
        $this->withTemporaryDatabase(function (PDO $connection): void {
            $repository = new MySQLAssetRepository($connection);
            $persistedAsset = $this->pendingAsset(
                assetId: '12121212-1212-4212-8212-121212121212',
                uploadId: '34343434-3434-4434-8434-343434343434',
                accountId: 'account-immutable-field-guard',
                fileName: 'original-name.png',
                createdAt: '2026-04-01 12:45:00.000000',
            );
            $assetWithChangedImmutableField = $this->uploadedAsset(
                assetId: (string) $persistedAsset->getId(),
                uploadId: (string) $persistedAsset->getUploadId(),
                accountId: (string) $persistedAsset->getAccountId(),
                fileName: 'renamed-after-upload.png',
                completionProof: 'etag-immutable-field-change',
                persistedState: $this->persistedState(
                    '2026-04-01 12:45:00.000000',
                    1,
                    '2026-04-01 12:50:00.000000',
                ),
            );

            $repository->save($persistedAsset);

            try {
                $repository->save($assetWithChangedImmutableField);
                self::fail('Expected immutable field change to throw a StaleAssetWriteException.');
            } catch (StaleAssetWriteException $exception) {
                self::assertSame('Cannot save stale asset state.', $exception->getMessage());
            }

            $storedAsset = $repository->findById($persistedAsset->getId());

            // Assert
            self::assertNotNull($storedAsset);
            self::assertSame(1, $this->countAssets($connection));
            $this->assertAssetMatches($persistedAsset, $storedAsset);
        });
    }

    #[Test]
    public function itThrowsStaleAssetWriteExceptionWhenSavingStaleAssetAndPreservesNewerPersistedState(): void
    {
        // Arrange & Act
        $this->withTemporaryDatabase(function (PDO $connection): void {
            $repository = new MySQLAssetRepository($connection);
            $staleAsset = $this->pendingAsset(
                assetId: '99999999-0000-4999-8999-999999999999',
                uploadId: 'aaaaaaaa-0000-4aaa-8aaa-aaaaaaaaaaaa',
                accountId: 'account-stale-save',
                fileName: 'stale-copy.png',
                createdAt: '2026-04-01 13:00:00.000000',
            );
            $newerAsset = $this->uploadedAsset(
                assetId: (string) $staleAsset->getId(),
                uploadId: (string) $staleAsset->getUploadId(),
                accountId: (string) $staleAsset->getAccountId(),
                fileName: $staleAsset->getFileName(),
                completionProof: 'etag-newer-state',
                persistedState: $this->persistedState(
                    '2026-04-01 13:00:00.000000',
                    1,
                    '2026-04-01 13:05:00.000000',
                ),
            );

            $repository->save($staleAsset);
            $repository->save($newerAsset);

            try {
                $repository->save($staleAsset);
                self::fail('Expected stale asset save to throw a StaleAssetWriteException.');
            } catch (StaleAssetWriteException $exception) {
                self::assertSame('Cannot save stale asset state.', $exception->getMessage());
            }

            $persistedAsset = $repository->findById($staleAsset->getId());

            // Assert
            self::assertNotNull($persistedAsset);
            self::assertSame(1, $this->countAssets($connection));
            $this->assertAssetMatches($newerAsset, $persistedAsset);
        });
    }

    #[Test]
    public function itThrowsStaleAssetWriteExceptionWhenCompareAndSwapUpdateLosesRace(): void
    {
        // Arrange & Act
        $this->withTemporaryDatabase(function (PDO $connection): void {
            $repository = new MySQLAssetRepository($connection);
            $persistedAsset = $this->pendingAsset(
                assetId: '10101010-1010-4010-8010-101010101010',
                uploadId: '20202020-2020-4020-8020-202020202020',
                accountId: 'account-compare-and-swap-race',
                fileName: 'race-window.png',
                createdAt: '2026-04-01 13:15:00.000000',
            );
            $uploadedAsset = $this->uploadedAsset(
                assetId: (string) $persistedAsset->getId(),
                uploadId: (string) $persistedAsset->getUploadId(),
                accountId: (string) $persistedAsset->getAccountId(),
                fileName: $persistedAsset->getFileName(),
                completionProof: 'etag-race-winner',
                persistedState: $this->persistedState(
                    '2026-04-01 13:15:00.000000',
                    1,
                    '2026-04-01 13:20:00.000000',
                ),
            );
            $concurrentPersistedAsset = Asset::reconstitute(
                new AssetId((string) $persistedAsset->getId()),
                new UploadId((string) $persistedAsset->getUploadId()),
                new AccountId((string) $persistedAsset->getAccountId()),
                $persistedAsset->getFileName(),
                $persistedAsset->getMimeType(),
                AssetStatus::FAILED,
                $this->persistedState(
                    '2026-04-01 13:15:00.000000',
                    1,
                    '2026-04-01 13:18:00.000000',
                ),
            );

            $repository->save($persistedAsset);

            $raceConnection = $this->createCompareAndSwapRaceConnection(
                $this->currentDatabaseName($connection),
                function () use ($connection, $persistedAsset, $concurrentPersistedAsset): void {
                    $this->forceFailedAssetState($connection, $persistedAsset, $concurrentPersistedAsset->getUpdatedAt());
                },
            );
            $raceRepository = new MySQLAssetRepository($raceConnection);

            try {
                $raceRepository->save($uploadedAsset);
                self::fail('Expected compare-and-swap update to throw a StaleAssetWriteException.');
            } catch (StaleAssetWriteException $exception) {
                self::assertSame('Cannot save stale asset state.', $exception->getMessage());
            }

            $persistedAfterRace = $repository->findById($persistedAsset->getId());

            // Assert
            self::assertNotNull($persistedAfterRace);
            self::assertSame(1, $this->countAssets($connection));
            $this->assertAssetMatches($concurrentPersistedAsset, $persistedAfterRace);
        });
    }

    #[Test]
    public function itReturnsSuccessfullyWhenTheCompareAndSwapUpdateLosesARaceButTheRowAlreadyMatchesTheDesiredState(): void
    {
        // Arrange & Act
        $this->withTemporaryDatabase(function (PDO $connection): void {
            $repository = new MySQLAssetRepository($connection);
            $persistedAsset = $this->pendingAsset(
                assetId: '30303030-3030-4030-8030-303030303030',
                uploadId: '40404040-4040-4040-8040-404040404040',
                accountId: 'account-compare-and-swap-idempotent-race',
                fileName: 'race-idempotent.png',
                createdAt: '2026-04-01 13:25:00.000000',
            );
            $uploadedAsset = $this->uploadedAsset(
                assetId: (string) $persistedAsset->getId(),
                uploadId: (string) $persistedAsset->getUploadId(),
                accountId: (string) $persistedAsset->getAccountId(),
                fileName: $persistedAsset->getFileName(),
                completionProof: 'etag-idempotent-race-winner',
                persistedState: $this->persistedState(
                    '2026-04-01 13:25:00.000000',
                    1,
                    '2026-04-01 13:30:00.000000',
                ),
            );

            $repository->save($persistedAsset);

            $raceConnection = $this->createCompareAndSwapRaceConnection(
                $this->currentDatabaseName($connection),
                function () use ($connection, $uploadedAsset): void {
                    $this->forceUploadedAssetState($connection, $uploadedAsset);
                },
            );
            $raceRepository = new MySQLAssetRepository($raceConnection);

            $raceRepository->save($uploadedAsset);
            $persistedAfterRace = $repository->findById($persistedAsset->getId());

            // Assert
            self::assertNotNull($persistedAfterRace);
            self::assertSame(1, $this->countAssets($connection));
            $this->assertAssetMatches($uploadedAsset, $persistedAfterRace);
        });
    }

    #[Test]
    public function itReturnsNullWhenAnAssetIsMissing(): void
    {
        // Arrange & Act
        $this->withTemporaryDatabase(function (PDO $connection): void {
            $repository = new MySQLAssetRepository($connection);
            $missingAsset = $repository->findById(new AssetId('77777777-7777-4777-8777-777777777777'));
            $missingUpload = $repository->findByUploadId(new UploadId('88888888-8888-4888-8888-888888888888'));

            // Assert
            self::assertNull($missingAsset);
            self::assertNull($missingUpload);
        });
    }

    #[Test]
    public function itReturnsMatchingAssetsWhenSearchingByFileNameWithinAccountUsingDeterministicOrdering(): void
    {
        // Arrange & Act
        $this->withTemporaryDatabase(function (PDO $connection): void {
            $repository = new MySQLAssetRepository($connection);
            $accountId = new AccountId('account-search');
            $expectedFirst = $this->uploadedAsset(
                assetId: '99999999-9999-4999-8999-999999999999',
                uploadId: 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
                accountId: (string) $accountId,
                fileName: 'Quarterly Report.pdf',
                completionProof: 'etag-quarterly-report',
                persistedState: $this->persistedState('2026-04-01 14:00:00.000000', 1, '2026-04-01 14:05:00.000000'),
            );
            $expectedSecond = $this->pendingAsset(
                assetId: '11111111-aaaa-4111-8111-111111111111',
                uploadId: 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb',
                accountId: (string) $accountId,
                fileName: 'report-draft.png',
                createdAt: '2026-04-01 13:00:00.000000',
            );
            $expectedThird = $this->pendingAsset(
                assetId: '22222222-bbbb-4222-8222-222222222222',
                uploadId: 'cccccccc-cccc-4ccc-8ccc-cccccccccccc',
                accountId: (string) $accountId,
                fileName: 'REPORT-appendix.png',
                createdAt: '2026-04-01 13:00:00.000000',
            );
            $sameAccountNonMatch = $this->pendingAsset(
                assetId: '33333333-cccc-4333-8333-333333333333',
                uploadId: 'dddddddd-dddd-4ddd-8ddd-dddddddddddd',
                accountId: (string) $accountId,
                fileName: 'invoice.png',
                createdAt: '2026-04-01 15:00:00.000000',
            );
            $otherAccountMatch = $this->pendingAsset(
                assetId: '44444444-dddd-4444-8444-444444444444',
                uploadId: 'eeeeeeee-eeee-4eee-8eee-eeeeeeeeeeee',
                accountId: 'account-other',
                fileName: 'external-report.png',
                createdAt: '2026-04-01 16:00:00.000000',
            );

            $repository->save($expectedThird);
            $repository->save($otherAccountMatch);
            $repository->save($expectedFirst);
            $repository->save($sameAccountNonMatch);
            $repository->save($expectedSecond);
            $results = $repository->searchByFileName($accountId, '  repORT  ');

            // Assert
            self::assertCount(3, $results);
            $this->assertAssetMatches($expectedFirst, $results[0]);
            $this->assertAssetMatches($expectedSecond, $results[1]);
            $this->assertAssetMatches($expectedThird, $results[2]);
        });
    }

    #[Test]
    public function itReturnsAnEmptyListWhenSearchQueryIsEmptyAfterTrimming(): void
    {
        // Arrange & Act
        $this->withTemporaryDatabase(function (PDO $connection): void {
            $repository = new MySQLAssetRepository($connection);
            $results = $repository->searchByFileName(new AccountId('account-empty-search'), " \n\t ");

            // Assert
            self::assertSame([], $results);
        }, false);
    }

    #[Test]
    public function itThrowsPdoExceptionWhenDifferentAssetReusesExistingUploadId(): void
    {
        // Arrange & Act
        $this->withTemporaryDatabase(function (PDO $connection): void {
            $repository = new MySQLAssetRepository($connection);
            $existingAsset = $this->pendingAsset(
                assetId: '55555555-eeee-4555-8555-555555555555',
                uploadId: 'ffffffff-ffff-4fff-8fff-ffffffffffff',
                accountId: 'account-duplicate-upload',
                fileName: 'existing.png',
                createdAt: '2026-04-01 11:00:00.000000',
            );
            $duplicateUploadIdAsset = $this->pendingAsset(
                assetId: '66666666-ffff-4666-8666-666666666666',
                uploadId: (string) $existingAsset->getUploadId(),
                accountId: 'account-duplicate-upload',
                fileName: 'replacement.png',
                createdAt: '2026-04-01 11:05:00.000000',
            );

            $repository->save($existingAsset);

            $this->expectException(PDOException::class);

            try {
                $repository->save($duplicateUploadIdAsset);
            } finally {
                $persistedAsset = $repository->findByUploadId($existingAsset->getUploadId());

                self::assertNotNull($persistedAsset);
                self::assertSame(1, $this->countAssets($connection));
                self::assertNull($repository->findById($duplicateUploadIdAsset->getId()));
                $this->assertAssetMatches($existingAsset, $persistedAsset);
            }
        });
    }

    /**
     * @param callable(PDO): void $assertions
     */
    private function withTemporaryDatabase(callable $assertions, bool $applyMigration = true): void
    {
        $serverConnection = $this->createServerConnectionOrSkip();
        $databaseName = 'dam_repository_' . bin2hex(random_bytes(6));
        $this->createDatabase($serverConnection, $databaseName);
        $databaseConnection = null;

        try {
            $databaseConnection = $this->createDatabaseConnection($databaseName);

            if ($applyMigration) {
                $databaseConnection->exec($this->migrationSql());
            }

            $assertions($databaseConnection);
        } finally {
            $databaseConnection = null;
            $this->dropDatabase($serverConnection, $databaseName);
        }
    }

    private function migrationSql(): string
    {
        $migrationSql = file_get_contents(self::MIGRATION_FILE);

        if ($migrationSql === false) {
            self::fail('Failed to read the assets table bootstrap migration.');
        }

        return $migrationSql;
    }

    private function createServerConnectionOrSkip(): PDO
    {
        if (! class_exists(PDO::class)) {
            self::markTestSkipped('PDO is not available in this PHP runtime.');
        }

        $connectionErrors = [];

        foreach ($this->connectionCandidates() as $candidate) {
            $dsn = sprintf(
                'mysql:host=%s;port=%d;charset=utf8mb4',
                $candidate['host'],
                $candidate['port'],
            );

            try {
                $connection = $this->createConnection($dsn, $candidate['user'], $candidate['password']);
                $this->selectedConnection = $candidate;

                return $connection;
            } catch (PDOException $exception) {
                $connectionErrors[] = sprintf(
                    '%s:%d (%s)',
                    $candidate['host'],
                    $candidate['port'],
                    $exception->getMessage(),
                );
            }
        }

        self::markTestSkipped(
            'MySQL is not reachable for integration tests. Tried: ' . implode('; ', $connectionErrors),
        );
    }

    private function createDatabaseConnection(string $databaseName): PDO
    {
        $selectedConnection = $this->selectedConnection;

        if ($selectedConnection === null) {
            self::fail('MySQL connection settings were not initialized.');
        }

        return $this->createConnection(
            $this->databaseDsn($selectedConnection, $databaseName),
            $selectedConnection['user'],
            $selectedConnection['password'],
        );
    }

    private function createCompareAndSwapRaceConnection(string $databaseName, Closure $beforeCompareAndSwap): PDO
    {
        $selectedConnection = $this->selectedConnection;

        if ($selectedConnection === null) {
            self::fail('MySQL connection settings were not initialized.');
        }

        return new CompareAndSwapRacePdo(
            $this->databaseDsn($selectedConnection, $databaseName),
            $selectedConnection['user'],
            $selectedConnection['password'],
            $beforeCompareAndSwap,
        );
    }

    private function createConnection(string $dsn, string $user, string $password): PDO
    {
        return new PDO(
            $dsn,
            $user,
            $password,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ],
        );
    }

    private function createDatabase(PDO $connection, string $databaseName): void
    {
        $connection->exec(
            sprintf(
                'CREATE DATABASE %s CHARACTER SET utf8mb4 COLLATE %s',
                $this->quoteIdentifier($databaseName),
                self::FILE_NAME_COLLATION,
            ),
        );
    }

    private function dropDatabase(PDO $connection, string $databaseName): void
    {
        $connection->exec('DROP DATABASE IF EXISTS ' . $this->quoteIdentifier($databaseName));
    }

    /**
     * @return list<array{host: string, port: int, user: string, password: string}>
     */
    private function connectionCandidates(): array
    {
        $user = $this->env('DB_USER', 'root');
        $password = $this->env('DB_PASSWORD', 'root');
        $candidates = [[
            'host' => $this->env('DB_HOST', '127.0.0.1'),
            'port' => $this->envInt('DB_PORT', 3306),
            'user' => $user,
            'password' => $password,
        ]];

        $fallbackCandidate = [
            'host' => '127.0.0.1',
            'port' => $this->envInt('DB_HOST_PORT', 3307),
            'user' => $user,
            'password' => $password,
        ];

        if (
            $fallbackCandidate['host'] !== $candidates[0]['host']
            || $fallbackCandidate['port'] !== $candidates[0]['port']
        ) {
            $candidates[] = $fallbackCandidate;
        }

        return $candidates;
    }

    private function env(string $name, string $defaultValue): string
    {
        $value = getenv($name);

        if ($value === false) {
            return $defaultValue;
        }

        $trimmedValue = trim($value);

        return $trimmedValue === '' ? $defaultValue : $trimmedValue;
    }

    private function envInt(string $name, int $defaultValue): int
    {
        $value = getenv($name);

        if ($value === false) {
            return $defaultValue;
        }

        $trimmedValue = trim($value);

        if ($trimmedValue === '' || ! ctype_digit($trimmedValue)) {
            return $defaultValue;
        }

        return (int) $trimmedValue;
    }

    private function currentDatabaseName(PDO $connection): string
    {
        $statement = $connection->prepare('SELECT DATABASE() AS database_name');
        $statement->execute();
        $row = $statement->fetch();

        if (! is_array($row)) {
            self::fail('Unexpected database name query result.');
        }

        $databaseName = $row['database_name'] ?? null;

        if (! is_string($databaseName) || trim($databaseName) === '') {
            self::fail('Unexpected database name query result.');
        }

        return $databaseName;
    }

    private function forceFailedAssetState(PDO $connection, Asset $asset, DateTimeImmutable $updatedAt): void
    {
        $statement = $connection->prepare(
            'UPDATE assets
             SET status = :status,
                 updated_at = :updated_at
             WHERE id = :id',
        );
        $statement->execute([
            'id' => (string) $asset->getId(),
            'status' => AssetStatus::FAILED->value,
            'updated_at' => $updatedAt->format(self::DATETIME_FORMAT),
        ]);
    }

    private function forceUploadedAssetState(PDO $connection, Asset $asset): void
    {
        $statement = $connection->prepare(
            'UPDATE assets
             SET status = :status,
                 chunk_count = :chunk_count,
                 completion_proof = :completion_proof,
                 updated_at = :updated_at
             WHERE id = :id',
        );
        $statement->execute([
            'id' => (string) $asset->getId(),
            'status' => $asset->getStatus()->value,
            'chunk_count' => $asset->getChunkCount(),
            'completion_proof' => $asset->getCompletionProof()?->value,
            'updated_at' => $asset->getUpdatedAt()->format(self::DATETIME_FORMAT),
        ]);
    }

    private function countAssets(PDO $connection): int
    {
        $statement = $connection->prepare('SELECT COUNT(*) AS asset_count FROM assets');
        $statement->execute();
        $row = $statement->fetch();

        if (! is_array($row)) {
            self::fail('Unexpected count query result.');
        }

        $assetCount = $row['asset_count'] ?? null;

        if (! is_numeric($assetCount)) {
            self::fail('Unexpected count query result.');
        }

        return (int) $assetCount;
    }

    private function pendingAsset(
        string $assetId,
        string $uploadId,
        string $accountId,
        string $fileName,
        string $createdAt,
        ?string $updatedAt = null,
        int $chunkCount = 1,
    ): Asset {
        return Asset::reconstitute(
            new AssetId($assetId),
            new UploadId($uploadId),
            new AccountId($accountId),
            $fileName,
            self::MIME_TYPE,
            AssetStatus::PENDING,
            $this->persistedState($createdAt, $chunkCount, $updatedAt),
        );
    }

    /**
     * @param array{createdAt: DateTimeImmutable, chunkCount: int, updatedAt: DateTimeImmutable} $persistedState
     */
    private function uploadedAsset(
        string $assetId,
        string $uploadId,
        string $accountId,
        string $fileName,
        string $completionProof,
        array $persistedState,
    ): Asset {
        return Asset::reconstituteUploaded(
            new AssetId($assetId),
            new UploadId($uploadId),
            new AccountId($accountId),
            $fileName,
            self::MIME_TYPE,
            new UploadCompletionProofValue($completionProof),
            $persistedState,
        );
    }

    /**
     * @return array{createdAt: DateTimeImmutable, chunkCount: int, updatedAt: DateTimeImmutable}
     */
    private function persistedState(string $createdAt, int $chunkCount = 1, ?string $updatedAt = null): array
    {
        return [
            'createdAt' => new DateTimeImmutable($createdAt, new \DateTimeZone('UTC')),
            'chunkCount' => $chunkCount,
            'updatedAt' => new DateTimeImmutable($updatedAt ?? $createdAt, new \DateTimeZone('UTC')),
        ];
    }

    private function assertAssetMatches(Asset $expected, Asset $actual): void
    {
        self::assertSame((string) $expected->getId(), (string) $actual->getId());
        self::assertSame((string) $expected->getUploadId(), (string) $actual->getUploadId());
        self::assertSame((string) $expected->getAccountId(), (string) $actual->getAccountId());
        self::assertSame($expected->getFileName(), $actual->getFileName());
        self::assertSame($expected->getMimeType(), $actual->getMimeType());
        self::assertSame($expected->getStatus(), $actual->getStatus());
        self::assertSame($expected->getChunkCount(), $actual->getChunkCount());
        self::assertSame(
            $expected->getCompletionProof()?->value,
            $actual->getCompletionProof()?->value,
        );
        self::assertSame(
            $expected->getCreatedAt()->setTimezone(new \DateTimeZone('UTC'))->format(self::DATETIME_FORMAT),
            $actual->getCreatedAt()->setTimezone(new \DateTimeZone('UTC'))->format(self::DATETIME_FORMAT),
        );
        self::assertSame(
            $expected->getUpdatedAt()->setTimezone(new \DateTimeZone('UTC'))->format(self::DATETIME_FORMAT),
            $actual->getUpdatedAt()->setTimezone(new \DateTimeZone('UTC'))->format(self::DATETIME_FORMAT),
        );
    }

    private function quoteIdentifier(string $identifier): string
    {
        return '`' . str_replace('`', '``', $identifier) . '`';
    }

    /**
     * @param array{host: string, port: int, user: string, password: string} $connection
     */
    private function databaseDsn(array $connection, string $databaseName): string
    {
        return sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            $connection['host'],
            $connection['port'],
            $databaseName,
        );
    }
}
