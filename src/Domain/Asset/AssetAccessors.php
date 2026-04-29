<?php

declare(strict_types=1);

namespace App\Domain\Asset;

use App\Domain\Asset\ValueObject\AccountId;
use App\Domain\Asset\ValueObject\AssetId;
use App\Domain\Asset\ValueObject\UploadCompletionProofValue;
use App\Domain\Asset\ValueObject\UploadId;
use DateTimeImmutable;

trait AssetAccessors
{
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

    public function equals(self $other): bool
    {
        return $this->id->equals($other->id);
    }
}
