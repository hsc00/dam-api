<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Domain\Asset\Asset;
use App\Domain\Asset\AssetRepositoryInterface;
use App\Domain\Asset\AssetStatus;
use App\Domain\Asset\Exception\StaleAssetWriteException;
use App\Domain\Asset\ValueObject\AccountId;
use App\Domain\Asset\ValueObject\AssetId;
use App\Domain\Asset\ValueObject\UploadCompletionProofValue;
use App\Domain\Asset\ValueObject\UploadId;
use DateTimeImmutable;
use PDO;
use UnexpectedValueException;

final class MySQLAssetRepository implements AssetRepositoryInterface
{
    private const DATETIME_FORMAT = 'Y-m-d H:i:s.u';
    private const FILE_NAME_COLLATION = 'utf8mb4_0900_ai_ci';
    private const LIKE_ESCAPE_CHARACTER = '!';
    private const STALE_WRITE_MESSAGE = 'Cannot save stale asset state.';
    private const UNEXPECTED_ROW_SHAPE_MESSAGE = 'Unexpected asset row shape.';

    public function __construct(
        private readonly PDO $connection,
    ) {
    }

    public function save(Asset $asset): void
    {
        $persistedAsset = $this->findById($asset->getId());

        if ($persistedAsset !== null) {
            if ($this->assetParameters($asset) === $this->assetParameters($persistedAsset)) {
                return;
            }

            $this->assertSafeUpdate($asset, $persistedAsset);
            $this->updateMutableFields($asset, $persistedAsset);

            return;
        }

        $statement = $this->connection->prepare(
            'INSERT INTO assets (
                id,
                upload_id,
                account_id,
                file_name,
                mime_type,
                status,
                chunk_count,
                completion_proof,
                created_at,
                updated_at
            ) VALUES (
                :id,
                :upload_id,
                :account_id,
                :file_name,
                :mime_type,
                :status,
                :chunk_count,
                :completion_proof,
                :created_at,
                :updated_at
            )',
        );

        try {
            $statement->execute($this->assetParameters($asset));
        } catch (\PDOException $exception) {
            // If another process inserted the same id concurrently we may get a
            // duplicate-key error. Re-read the row and treat identical
            // rows as a successful idempotent save; otherwise rethrow.
            if ($exception->getCode() === '23000') {
                $persisted = $this->findById($asset->getId());

                if ($persisted !== null && $this->assetParameters($asset) === $this->assetParameters($persisted)) {
                    return;
                }
            }

            throw $exception;
        }
    }

    public function findById(AssetId $assetId): ?Asset
    {
        return $this->findOne(
            'SELECT id, upload_id, account_id, file_name, mime_type, status, chunk_count, completion_proof, created_at, updated_at
             FROM assets
             WHERE id = :id
             LIMIT 1',
            [
                'id' => (string) $assetId,
            ],
        );
    }

    public function findByUploadId(UploadId $uploadId): ?Asset
    {
        return $this->findOne(
            'SELECT id, upload_id, account_id, file_name, mime_type, status, chunk_count, completion_proof, created_at, updated_at
             FROM assets
             WHERE upload_id = :upload_id
             LIMIT 1',
            [
                'upload_id' => (string) $uploadId,
            ],
        );
    }

    public function countByFileName(AccountId $accountId, string $query, AssetStatus $status): int
    {
        $likeQuery = $this->likeSearchQuery($query);

        if ($likeQuery === null) {
            return 0;
        }

        $statement = $this->connection->prepare(sprintf(
            "SELECT COUNT(*)
             FROM assets
             WHERE account_id = :account_id
               AND status = :status
               AND file_name COLLATE %s LIKE :file_name_query ESCAPE '%s'",
            self::FILE_NAME_COLLATION,
            self::LIKE_ESCAPE_CHARACTER,
        ));

        $statement->execute([
            'account_id' => (string) $accountId,
            'status' => $status->value,
            'file_name_query' => $likeQuery,
        ]);

        $count = $statement->fetchColumn();

        if ($count === false) {
            throw new UnexpectedValueException(self::UNEXPECTED_ROW_SHAPE_MESSAGE);
        }

        return (int) $count;
    }

    /**
     * @return list<Asset>
     */
    public function searchByFileName(AccountId $accountId, string $query, AssetStatus $status, int $offset, int $limit): array
    {
        $this->assertValidSearchPagination($offset, $limit);

        $likeQuery = $this->likeSearchQuery($query);

        if ($likeQuery === null || $limit === 0) {
            return [];
        }

        $statement = $this->connection->prepare(sprintf(
            "SELECT id, upload_id, account_id, file_name, mime_type, status, chunk_count, completion_proof, created_at, updated_at
             FROM assets
             WHERE account_id = :account_id
               AND status = :status
               AND file_name COLLATE %s LIKE :file_name_query ESCAPE '%s'
             ORDER BY created_at DESC, id ASC
             LIMIT :limit OFFSET :offset",
            self::FILE_NAME_COLLATION,
            self::LIKE_ESCAPE_CHARACTER,
        ));
        $statement->bindValue(':account_id', (string) $accountId);
        $statement->bindValue(':status', $status->value);
        $statement->bindValue(':file_name_query', $likeQuery);
        $statement->bindValue(':limit', $limit, PDO::PARAM_INT);
        $statement->bindValue(':offset', $offset, PDO::PARAM_INT);
        $statement->execute();

        $assets = [];

        while (($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
            if (! is_array($row)) {
                throw new UnexpectedValueException(self::UNEXPECTED_ROW_SHAPE_MESSAGE);
            }

            $assets[] = $this->mapAsset($row);
        }

        return $assets;
    }

    private function assertValidSearchPagination(int $offset, int $limit): void
    {
        if ($offset < 0) {
            throw new \InvalidArgumentException('Search offset cannot be negative.');
        }

        if ($limit < 0) {
            throw new \InvalidArgumentException('Search limit cannot be negative.');
        }
    }

    /**
     * @param array<string, mixed> $parameters
     */
    private function findOne(string $sql, array $parameters): ?Asset
    {
        $statement = $this->connection->prepare($sql);
        $statement->execute($parameters);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        if ($row === false) {
            return null;
        }

        if (! is_array($row)) {
            throw new UnexpectedValueException(self::UNEXPECTED_ROW_SHAPE_MESSAGE);
        }

        return $this->mapAsset($row);
    }

    private function assertSafeUpdate(Asset $asset, Asset $persistedAsset): void
    {
        if ($this->immutableAssetParameters($asset) !== $this->immutableAssetParameters($persistedAsset)) {
            throw new StaleAssetWriteException(self::STALE_WRITE_MESSAGE);
        }

        if (! $this->isAllowedStatusTransition($persistedAsset->getStatus(), $asset->getStatus())) {
            throw new StaleAssetWriteException(self::STALE_WRITE_MESSAGE);
        }

        if ($asset->getUpdatedAt() < $persistedAsset->getUpdatedAt()) {
            throw new StaleAssetWriteException(self::STALE_WRITE_MESSAGE);
        }
    }

    private function isAllowedStatusTransition(AssetStatus $persistedStatus, AssetStatus $nextStatus): bool
    {
        return match ($persistedStatus) {
            AssetStatus::PENDING => $nextStatus === AssetStatus::PROCESSING,
            AssetStatus::PROCESSING => $nextStatus === AssetStatus::PENDING || $nextStatus === AssetStatus::UPLOADED || $nextStatus === AssetStatus::FAILED,
            AssetStatus::UPLOADED,
            AssetStatus::FAILED => false,
        };
    }

    private function updateMutableFields(Asset $asset, Asset $persistedAsset): void
    {
        $parameters = $this->mutableUpdateParameters($asset, $persistedAsset);
        $statement = $this->connection->prepare(
            'UPDATE assets
             SET status = :status,
                 chunk_count = :chunk_count,
                 completion_proof = :completion_proof,
                 updated_at = :updated_at
             WHERE id = :id
               AND status = :expected_status
               AND updated_at = :expected_updated_at',
        );
        $statement->execute($parameters);

        if ($statement->rowCount() === 0) {
            $currentAsset = $this->findById($asset->getId());

            if (
                $currentAsset !== null
                && [
                    'status' => $currentAsset->getStatus()->value,
                    'chunk_count' => $currentAsset->getChunkCount(),
                    'completion_proof' => $currentAsset->getCompletionProof()?->value,
                    'updated_at' => $currentAsset->getUpdatedAt()->setTimezone(new \DateTimeZone('UTC'))->format(self::DATETIME_FORMAT),
                ] === [
                    'status' => $parameters['status'],
                    'chunk_count' => $parameters['chunk_count'],
                    'completion_proof' => $parameters['completion_proof'],
                    'updated_at' => $parameters['updated_at'],
                ]
            ) {
                return;
            }

            throw new StaleAssetWriteException(self::STALE_WRITE_MESSAGE);
        }
    }

    /**
     * @return array{
     *     id: string,
     *     upload_id: string,
     *     account_id: string,
     *     file_name: string,
     *     mime_type: string,
     *     status: string,
     *     chunk_count: int,
     *     completion_proof: string|null,
     *     created_at: string,
     *     updated_at: string
     * }
     */
    private function assetParameters(Asset $asset): array
    {
        return [
            'id' => (string) $asset->getId(),
            'upload_id' => (string) $asset->getUploadId(),
            'account_id' => (string) $asset->getAccountId(),
            'file_name' => $asset->getFileName(),
            'mime_type' => $asset->getMimeType(),
            'status' => $asset->getStatus()->value,
            'chunk_count' => $asset->getChunkCount(),
            'completion_proof' => $asset->getCompletionProof()?->value,
            'created_at' => $asset->getCreatedAt()->setTimezone(new \DateTimeZone('UTC'))->format(self::DATETIME_FORMAT),
            'updated_at' => $asset->getUpdatedAt()->setTimezone(new \DateTimeZone('UTC'))->format(self::DATETIME_FORMAT),
        ];
    }

    /**
     * @return array{
     *     id: string,
     *     upload_id: string,
     *     account_id: string,
     *     file_name: string,
     *     mime_type: string,
     *     created_at: string
     * }
     */
    private function immutableAssetParameters(Asset $asset): array
    {
        return [
            'id' => (string) $asset->getId(),
            'upload_id' => (string) $asset->getUploadId(),
            'account_id' => (string) $asset->getAccountId(),
            'file_name' => $asset->getFileName(),
            'mime_type' => $asset->getMimeType(),
            'created_at' => $asset->getCreatedAt()->setTimezone(new \DateTimeZone('UTC'))->format(self::DATETIME_FORMAT),
        ];
    }

    /**
     * @return array{
     *     id: string,
     *     status: string,
     *     chunk_count: int,
     *     completion_proof: string|null,
     *     updated_at: string,
     *     expected_status: string,
     *     expected_updated_at: string
     * }
     */
    private function mutableUpdateParameters(Asset $asset, Asset $persistedAsset): array
    {
        return [
            'id' => (string) $asset->getId(),
            'status' => $asset->getStatus()->value,
            'chunk_count' => $asset->getChunkCount(),
            'completion_proof' => $asset->getCompletionProof()?->value,
            'updated_at' => $asset->getUpdatedAt()->setTimezone(new \DateTimeZone('UTC'))->format(self::DATETIME_FORMAT),
            'expected_status' => $persistedAsset->getStatus()->value,
            'expected_updated_at' => $persistedAsset->getUpdatedAt()->setTimezone(new \DateTimeZone('UTC'))->format(self::DATETIME_FORMAT),
        ];
    }

    /**
     * @param array<string, mixed> $row
     */
    private function mapAsset(array $row): Asset
    {
        $id = $row['id'] ?? null;
        $uploadId = $row['upload_id'] ?? null;
        $accountId = $row['account_id'] ?? null;
        $fileName = $row['file_name'] ?? null;
        $mimeType = $row['mime_type'] ?? null;
        $status = $row['status'] ?? null;
        $chunkCount = $row['chunk_count'] ?? null;
        $completionProof = $row['completion_proof'] ?? null;
        $createdAt = $row['created_at'] ?? null;
        $updatedAt = $row['updated_at'] ?? null;

        if (
            ! is_string($id)
            || ! is_string($uploadId)
            || ! is_string($accountId)
            || ! is_string($fileName)
            || ! is_string($mimeType)
            || ! is_string($status)
            || ! is_numeric($chunkCount)
            || ($completionProof !== null && ! is_string($completionProof))
            || ! is_string($createdAt)
            || ! is_string($updatedAt)
        ) {
            throw new UnexpectedValueException(self::UNEXPECTED_ROW_SHAPE_MESSAGE);
        }

        $persistedState = [
            'createdAt' => $this->parseDateTime($createdAt, 'created_at'),
            'chunkCount' => (int) $chunkCount,
            'updatedAt' => $this->parseDateTime($updatedAt, 'updated_at'),
        ];

        $assetStatus = AssetStatus::from($status);

        return match ($assetStatus) {
            AssetStatus::PROCESSING => Asset::reconstituteProcessing(
                new AssetId($id),
                new UploadId($uploadId),
                new AccountId($accountId),
                $fileName,
                $mimeType,
                $this->requiredCompletionProofValue($completionProof),
                $persistedState,
            ),
            AssetStatus::UPLOADED => Asset::reconstituteUploaded(
                new AssetId($id),
                new UploadId($uploadId),
                new AccountId($accountId),
                $fileName,
                $mimeType,
                $this->requiredCompletionProofValue($completionProof),
                $persistedState,
            ),
            AssetStatus::PENDING,
            AssetStatus::FAILED => Asset::reconstitute(
                new AssetId($id),
                new UploadId($uploadId),
                new AccountId($accountId),
                $fileName,
                $mimeType,
                $assetStatus,
                $persistedState,
            ),
        };
    }

    private function parseDateTime(string $value, string $column): DateTimeImmutable
    {
        $dateTime = DateTimeImmutable::createFromFormat(self::DATETIME_FORMAT, $value, new \DateTimeZone('UTC'));

        if (! $dateTime instanceof DateTimeImmutable) {
            throw new UnexpectedValueException(sprintf('Unexpected %s value.', $column));
        }

        return $dateTime;
    }

    private function requiredCompletionProofValue(mixed $completionProof): UploadCompletionProofValue
    {
        if (! is_string($completionProof)) {
            throw new UnexpectedValueException('Assets in this state must include a completion proof.');
        }

        return new UploadCompletionProofValue($completionProof);
    }

    private function escapeLikePattern(string $value): string
    {
        return str_replace(
            [self::LIKE_ESCAPE_CHARACTER, '%', '_'],
            [
                self::LIKE_ESCAPE_CHARACTER . self::LIKE_ESCAPE_CHARACTER,
                self::LIKE_ESCAPE_CHARACTER . '%',
                self::LIKE_ESCAPE_CHARACTER . '_',
            ],
            $value,
        );
    }

    private function likeSearchQuery(string $query): ?string
    {
        $trimmedQuery = trim($query);

        if ($trimmedQuery === '') {
            return null;
        }

        return '%' . $this->escapeLikePattern($trimmedQuery) . '%';
    }
}
