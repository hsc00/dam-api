<?php

declare(strict_types=1);

namespace App\Domain\Asset;

use DateTimeImmutable;
use App\Domain\Asset\ValueObject\UploadId;
use App\Domain\Asset\ValueObject\AccountId;
use App\Domain\Asset\Exception\AssetDomainException;

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
        return new self(\Ramsey\Uuid\Uuid::uuid4()->toString(), $uploadId, $accountId, AssetStatus::PENDING, $now);
    }

    public function markUploaded(string $filename, string $contentType, int $size):void
    {
        if($this->status === AssetStatus::UPLOADED) {
            throw new AssetDomainException('Asset already uploaded');
        }
        $this->filename = $filename;
        $this->contentType = $contentType;
        $this->size = $size;
        $this->status = AssetStatus::UPLOADED;
        $this->updatedAt = new DateTimeImmutable();
    }

    public function markFailed(): void{
        if($this->status === AssetStatus::UPLOADED) {
            throw new AssetDomainException('Cannot mark an uploaded asset as failed');
        }
        $this->status = AssetStatus::FAILED;
        $this->updatedAt = new DateTimeImmutable();
    }

    public function getId(): string { return $this->id; }
    public function getUploadId(): UploadId { return $this->uploadId; }
    public function getAccountId(): AccountId { return $this->accountId; }
    public function getStatus(): AssetStatus { return $this->status; }
    public function getFilename(): ?string { return $this->filename; }
    public function getContentType(): ?string { return $this->contentType; }
    public function getSize(): ?int { return $this->size; }
    public function getCreatedAt(): DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): DateTimeImmutable { return $this->updatedAt; }

    public function equals(Asset $other): bool
    {
        return $this->id === $other->id;
    }

}

