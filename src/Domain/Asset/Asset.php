<?php

declare(strict_types=1);

namespace App\Domain\Asset;

use App\Domain\Asset\Exception\AssetDomainException;
use App\Domain\Asset\Exception\InvalidChunkCountException;
use App\Domain\Asset\Exception\InvalidFileNameException;
use App\Domain\Asset\Exception\InvalidMimeTypeException;
use App\Domain\Asset\ValueObject\AccountId;
use App\Domain\Asset\ValueObject\AssetId;
use App\Domain\Asset\ValueObject\UploadCompletionProofValue;
use App\Domain\Asset\ValueObject\UploadId;
use DateTimeImmutable;

final class Asset
{
    use AssetAccessors;

    private const DEFAULT_CHUNK_COUNT = 1;
    private const INVALID_CHUNK_COUNT_MESSAGE = 'Chunk count must be between 1 and 100.';
    private const INVALID_UPDATED_AT_MESSAGE = 'Updated at must not be earlier than created at';
    private const MAX_CHUNK_COUNT = 100;

    private function __construct(
        AssetId $id,
        UploadId $uploadId,
        AccountId $accountId,
        string $fileName,
        string $mimeType,
        AssetStatus $status,
        ?UploadCompletionProofValue $completionProof = null,
    ) {
        $this->id = $id;
        $this->uploadId = $uploadId;
        $this->accountId = $accountId;
        $this->fileName = self::normalizeRequiredText($fileName, RequiredTextField::FILE_NAME);
        $this->mimeType = self::normalizeRequiredText($mimeType, RequiredTextField::MIME_TYPE);
        self::assertCompletionProofMatchesStatus($status, $completionProof);
        $this->status = $status;
        $this->completionProof = $completionProof;
    }

    /**
     * @throws AssetDomainException
     */
    public static function createPending(
        UploadId $uploadId,
        AccountId $accountId,
        string $fileName,
        string $mimeType,
        int $chunkCount = self::DEFAULT_CHUNK_COUNT,
        ?ClockInterface $clock = null,
    ): self {
        $resolvedClock = $clock ?? new SystemClock();
        $now = $resolvedClock->now();

        $asset = new self(
            id: AssetId::generate(),
            uploadId: $uploadId,
            accountId: $accountId,
            fileName: $fileName,
            mimeType: $mimeType,
            status: AssetStatus::PENDING,
        );
        $asset->clock = $resolvedClock;

        $asset->initializeLifecycleState($now, $chunkCount);

        return $asset;
    }

    /**
     * Reconstitutes an Asset that does not require a completion proof from persistence.
     * For PROCESSING or UPLOADED status, use reconstituteProcessing() or reconstituteUploaded() instead.
     *
     * @param array{createdAt: DateTimeImmutable, chunkCount?: int, updatedAt?: DateTimeImmutable} $persistedState
     *
     * @throws AssetDomainException
     */
    public static function reconstitute(
        AssetId $id,
        UploadId $uploadId,
        AccountId $accountId,
        string $fileName,
        string $mimeType,
        AssetStatus $status,
        array $persistedState,
    ): self {
        $asset = new self(
            id: $id,
            uploadId: $uploadId,
            accountId: $accountId,
            fileName: $fileName,
            mimeType: $mimeType,
            status: $status,
        );
        $asset->clock = new SystemClock();

        $asset->initializeLifecycleState(
            $persistedState['createdAt'],
            $persistedState['chunkCount'] ?? self::DEFAULT_CHUNK_COUNT,
            $persistedState['updatedAt'] ?? null,
        );

        return $asset;
    }

    /**
     * @param array{createdAt: DateTimeImmutable, chunkCount?: int, updatedAt?: DateTimeImmutable} $persistedState
     *
     * @throws AssetDomainException
     */
    public static function reconstituteProcessing(
        AssetId $id,
        UploadId $uploadId,
        AccountId $accountId,
        string $fileName,
        string $mimeType,
        UploadCompletionProofValue $completionProof,
        array $persistedState,
    ): self {
        $asset = new self(
            id: $id,
            uploadId: $uploadId,
            accountId: $accountId,
            fileName: $fileName,
            mimeType: $mimeType,
            status: AssetStatus::PROCESSING,
            completionProof: $completionProof,
        );
        $asset->clock = new SystemClock();

        $asset->initializeLifecycleState(
            $persistedState['createdAt'],
            $persistedState['chunkCount'] ?? self::DEFAULT_CHUNK_COUNT,
            $persistedState['updatedAt'] ?? null,
        );

        return $asset;
    }

    /**
     * @param array{createdAt: DateTimeImmutable, chunkCount?: int, updatedAt?: DateTimeImmutable} $persistedState
     *
     * @throws AssetDomainException
     */
    public static function reconstituteUploaded(
        AssetId $id,
        UploadId $uploadId,
        AccountId $accountId,
        string $fileName,
        string $mimeType,
        UploadCompletionProofValue $completionProof,
        array $persistedState,
    ): self {
        $asset = new self(
            id: $id,
            uploadId: $uploadId,
            accountId: $accountId,
            fileName: $fileName,
            mimeType: $mimeType,
            status: AssetStatus::UPLOADED,
            completionProof: $completionProof,
        );
        $asset->clock = new SystemClock();

        $asset->initializeLifecycleState(
            $persistedState['createdAt'],
            $persistedState['chunkCount'] ?? self::DEFAULT_CHUNK_COUNT,
            $persistedState['updatedAt'] ?? null,
        );

        return $asset;
    }

    /**
     * @throws AssetDomainException
     */
    private function initializeLifecycleState(
        DateTimeImmutable $createdAt,
        int $chunkCount,
        ?DateTimeImmutable $updatedAt = null,
    ): void {
        $updatedAt ??= $createdAt;

        if ($chunkCount < self::DEFAULT_CHUNK_COUNT || $chunkCount > self::MAX_CHUNK_COUNT) {
            throw new InvalidChunkCountException(self::INVALID_CHUNK_COUNT_MESSAGE);
        }

        if ($updatedAt < $createdAt) {
            throw new AssetDomainException(self::INVALID_UPDATED_AT_MESSAGE);
        }

        $this->chunkCount = $chunkCount;
        $this->createdAt = $createdAt;
        $this->updatedAt = $updatedAt;
    }

    /**
     * @throws AssetDomainException
     */
    private static function assertCompletionProofMatchesStatus(AssetStatus $status, ?UploadCompletionProofValue $completionProof): void
    {
        if (self::statusRequiresCompletionProof($status) && $completionProof === null) {
            throw new AssetDomainException(match ($status) {
                AssetStatus::PROCESSING => 'Processing assets must have completion proof',
                AssetStatus::UPLOADED => 'Uploaded assets must have completion proof',
                default => 'Assets in this state must have completion proof',
            });
        }

        if (! self::statusRequiresCompletionProof($status) && $completionProof !== null) {
            throw new AssetDomainException('Only uploaded or processing assets can have completion proof');
        }
    }

    private static function statusRequiresCompletionProof(AssetStatus $status): bool
    {
        return $status === AssetStatus::PROCESSING || $status === AssetStatus::UPLOADED;
    }

    /**
     * @throws AssetDomainException
     */
    private static function normalizeRequiredText(string $value, RequiredTextField $field): string
    {
        $normalizedValue = trim($value);

        if ($normalizedValue === '') {
            // Throw a specific domain exception depending on the field identifier.
            // Use a match on the RequiredTextField backed enum to construct the
            // correct exception and throw it directly.
            throw match ($field) {
                RequiredTextField::FILE_NAME => new InvalidFileNameException('File name must be non-empty'),
                RequiredTextField::MIME_TYPE => new InvalidMimeTypeException('Mime type must be non-empty'),
            };
        }

        return $normalizedValue;
    }

    /**
     * @throws AssetDomainException
     */
    public function markUploaded(UploadCompletionProofValue $completionProof): void
    {
        if ($this->status === AssetStatus::UPLOADED) {
            throw new AssetDomainException('Asset already uploaded');
        }

        if ($this->status !== AssetStatus::PENDING) {
            throw new AssetDomainException('Cannot upload asset from current state');
        }

        $this->completionProof = $completionProof;
        $this->status = AssetStatus::UPLOADED;
        $nextUpdatedAt = $this->clock->now();

        if ($nextUpdatedAt > $this->updatedAt) {
            $this->updatedAt = $nextUpdatedAt;
        }
    }

    /**
     * @throws AssetDomainException
     */
    public function markProcessing(UploadCompletionProofValue $completionProof): void
    {
        if ($this->status === AssetStatus::PROCESSING) {
            throw new AssetDomainException('Asset already processing');
        }

        if ($this->status !== AssetStatus::PENDING) {
            throw new AssetDomainException('Cannot process asset from current state');
        }

        $this->completionProof = $completionProof;
        $this->status = AssetStatus::PROCESSING;
        $nextUpdatedAt = $this->clock->now();

        if ($nextUpdatedAt > $this->updatedAt) {
            $this->updatedAt = $nextUpdatedAt;
        }
    }

    /**
     * @throws AssetDomainException
     */
    public function markFailed(): void
    {
        if ($this->status === AssetStatus::UPLOADED) {
            throw new AssetDomainException('Cannot mark an uploaded asset as failed');
        }

        if ($this->status === AssetStatus::FAILED) {
            return;
        }

        $this->status = AssetStatus::FAILED;
        $nextUpdatedAt = $this->clock->now();

        if ($nextUpdatedAt > $this->updatedAt) {
            $this->updatedAt = $nextUpdatedAt;
        }
    }

}
