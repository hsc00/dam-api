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
    private const DEFAULT_CHUNK_COUNT = 1;
    private const INVALID_CHUNK_COUNT_MESSAGE = 'Chunk count must be between 1 and 100.';
    private const INVALID_UPDATED_AT_MESSAGE = 'Updated at must not be earlier than created at';
    private const MAX_CHUNK_COUNT = 100;

    private AssetId $id;
    private UploadId $uploadId;
    private AccountId $accountId;
    private string $fileName;
    private string $mimeType;
    private AssetStatus $status;
    private ?UploadCompletionProofValue $completionProof = null;
    private int $chunkCount;
    private DateTimeImmutable $createdAt;
    private ClockInterface $clock;
    private DateTimeImmutable $updatedAt;

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
     * Reconstitutes a non-uploaded Asset from persistence.
     * For UPLOADED status, use reconstituteUploaded() instead.
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
        if ($status === AssetStatus::UPLOADED && $completionProof === null) {
            throw new AssetDomainException('Uploaded assets must have completion proof');
        }

        if ($status !== AssetStatus::UPLOADED && $completionProof !== null) {
            throw new AssetDomainException('Only uploaded assets can have completion proof');
        }
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

    public function getId(): AssetId
    {
        return $this->id;
    }

    public function getUploadId(): UploadId
    {
        return $this->uploadId;
    }

    public function getAccountId(): AccountId
    {
        return $this->accountId;
    }

    public function getFileName(): string
    {
        return $this->fileName;
    }

    public function getMimeType(): string
    {
        return $this->mimeType;
    }

    public function getStatus(): AssetStatus
    {
        return $this->status;
    }

    public function getCompletionProof(): ?UploadCompletionProofValue
    {
        return $this->completionProof;
    }

    public function getChunkCount(): int
    {
        return $this->chunkCount;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function equals(Asset $other): bool
    {
        return $this->id->equals($other->id);
    }
}
