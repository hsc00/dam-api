<?php

declare(strict_types=1);

namespace App\Domain\Asset;

use App\Domain\Asset\Exception\AssetDomainException;
use App\Domain\Asset\ValueObject\AccountId;
use App\Domain\Asset\ValueObject\UploadId;
use DateTimeImmutable;

final class Asset
{
    private string $id;
    private UploadId $uploadId;
    private AccountId $accountId;
    private AssetStatus $status;
    private ?string $filename = null;
    private ?string $contentType = null;
    private ?int $size = null;
    private DateTimeImmutable $createdAt;
    private DateTimeImmutable $updatedAt;

    private function __construct(string $id, UploadId $uploadId, AccountId $accountId, AssetStatus $status, DateTimeImmutable $now)
    {
        $this->id = $id;
        $this->uploadId = $uploadId;
        $this->accountId = $accountId;
        $this->status = $status;
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    public static function createPending(UploadId $uploadId, AccountId $accountId): self
    {
        $now = new DateTimeImmutable();

        return new self(id: self::generateUuidV4(), uploadId: $uploadId, accountId: $accountId, status: AssetStatus::PENDING, now: $now);
    }

    private static function generateUuidV4(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        $hex = bin2hex($bytes);

        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12),
        );
    }

    /**
     * @throws AssetDomainException
     */
    public function markUploaded(string $filename, string $contentType, int $size): void
    {
        if ($this->status === AssetStatus::UPLOADED) {
            throw new AssetDomainException('Asset already uploaded');
        }

        if ($this->status !== AssetStatus::PENDING) {
            throw new AssetDomainException('Cannot upload asset from current state');
        }

        if ($size < 0) {
            throw new AssetDomainException('Asset size must be non-negative');
        }

        $trimmedFilename = trim($filename);
        if ($trimmedFilename === '') {
            throw new AssetDomainException('Filename must be non-empty');
        }

        $trimmedContentType = trim($contentType);
        if ($trimmedContentType === '') {
            throw new AssetDomainException('Content type must be non-empty');
        }

        $this->filename = $trimmedFilename;
        $this->contentType = $trimmedContentType;
        $this->size = $size;
        $this->status = AssetStatus::UPLOADED;
        $this->updatedAt = new DateTimeImmutable();
    }

    /**
     * @throws AssetDomainException
     */
    public function markFailed(): void
    {
        if ($this->status === AssetStatus::UPLOADED) {
            throw new AssetDomainException('Cannot mark an uploaded asset as failed');
        }

        $this->status = AssetStatus::FAILED;
        $this->updatedAt = new DateTimeImmutable();
    }

    public function getId(): string
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

    public function getStatus(): AssetStatus
    {
        return $this->status;
    }

    public function getFilename(): ?string
    {
        return $this->filename;
    }

    public function getContentType(): ?string
    {
        return $this->contentType;
    }

    public function getSize(): ?int
    {
        return $this->size;
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
        return $this->id === $other->id;
    }
}
