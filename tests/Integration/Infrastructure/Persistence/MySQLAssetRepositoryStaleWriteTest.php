<?php

declare(strict_types=1);

namespace App\Tests\Integration\Infrastructure\Persistence;

use App\Domain\Asset\Asset;
use App\Domain\Asset\AssetStatus;
use App\Domain\Asset\Exception\StaleAssetWriteException;
use App\Domain\Asset\ValueObject\AccountId;
use App\Domain\Asset\ValueObject\AssetId;
use App\Domain\Asset\ValueObject\UploadId;
use App\Infrastructure\Persistence\MySQLAssetRepository;
use PDO;
use PHPUnit\Framework\Attributes\Test;

final class MySQLAssetRepositoryStaleWriteTest extends BaseMySQLAssetRepositoryTestCase
{
    private const CREATED_AT_1315 = '2026-04-01 13:15:00.000000';
    private const STALE_WRITE_MESSAGE = 'Cannot save stale asset state.';
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
                self::assertSame(self::STALE_WRITE_MESSAGE, $exception->getMessage());
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
                self::assertSame(self::STALE_WRITE_MESSAGE, $exception->getMessage());
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
                createdAt: self::CREATED_AT_1315,
            );
            $uploadedAsset = $this->uploadedAsset(
                assetId: (string) $persistedAsset->getId(),
                uploadId: (string) $persistedAsset->getUploadId(),
                accountId: (string) $persistedAsset->getAccountId(),
                fileName: $persistedAsset->getFileName(),
                completionProof: 'etag-race-winner',
                persistedState: $this->persistedState(
                    self::CREATED_AT_1315,
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
                    self::CREATED_AT_1315,
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
                self::assertSame(self::STALE_WRITE_MESSAGE, $exception->getMessage());
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
}
