<?php

declare(strict_types=1);

namespace App\Tests\Integration\Infrastructure\Persistence;

use App\Domain\Asset\Asset;
use App\Domain\Asset\AssetStatus;
use App\Domain\Asset\ValueObject\AccountId;
use App\Domain\Asset\ValueObject\AssetId;
use App\Domain\Asset\ValueObject\UploadCompletionProofValue;
use App\Domain\Asset\ValueObject\UploadId;
use App\Tests\Integration\Support\CompareAndSwapRacePdo;
use Closure;
use DateTimeImmutable;
use DateTimeZone;
use PDO;

abstract class BaseMySQLAssetRepositoryTestCase extends BaseAssetsTableTestCase
{
    protected const DATETIME_FORMAT = 'Y-m-d H:i:s.u';
    private const MIME_TYPE = 'image/png';

    /**
     * @param callable(PDO): void $assertions
     */
    protected function withTemporaryDatabase(callable $assertions, bool $applyMigration = true): void
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

    protected function createCompareAndSwapRaceConnection(string $databaseName, Closure $beforeCompareAndSwap): PDO
    {
        if ($this->selectedConnection === null) {
            self::fail('MySQL connection settings were not initialized.');
        }

        return new CompareAndSwapRacePdo(
            sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
                $this->selectedConnection['host'],
                $this->selectedConnection['port'],
                $databaseName,
            ),
            $this->selectedConnection['user'],
            $this->selectedConnection['password'],
            $beforeCompareAndSwap,
        );
    }

    protected function currentDatabaseName(PDO $connection): string
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

    protected function forceFailedAssetState(PDO $connection, Asset $asset, DateTimeImmutable $updatedAt): void
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

    protected function forceUploadedAssetState(PDO $connection, Asset $asset): void
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

    protected function countAssets(PDO $connection): int
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

    protected function pendingAsset(
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
    protected function uploadedAsset(
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
    protected function persistedState(string $createdAt, int $chunkCount = 1, ?string $updatedAt = null): array
    {
        return [
            'createdAt' => new DateTimeImmutable($createdAt, new DateTimeZone('UTC')),
            'chunkCount' => $chunkCount,
            'updatedAt' => new DateTimeImmutable($updatedAt ?? $createdAt, new DateTimeZone('UTC')),
        ];
    }

    protected function assertAssetMatches(Asset $expected, Asset $actual): void
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
            $expected->getCreatedAt()->setTimezone(new DateTimeZone('UTC'))->format(self::DATETIME_FORMAT),
            $actual->getCreatedAt()->setTimezone(new DateTimeZone('UTC'))->format(self::DATETIME_FORMAT),
        );
        self::assertSame(
            $expected->getUpdatedAt()->setTimezone(new DateTimeZone('UTC'))->format(self::DATETIME_FORMAT),
            $actual->getUpdatedAt()->setTimezone(new DateTimeZone('UTC'))->format(self::DATETIME_FORMAT),
        );
    }
}
