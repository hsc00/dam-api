<?php

declare(strict_types=1);

namespace App\Domain\Asset;

use App\Domain\Asset\Exception\AssetDomainException;
use App\Domain\Asset\ValueObject\AccountId;
use App\Domain\Asset\ValueObject\AssetId;
use App\Domain\Asset\ValueObject\UploadCompletionProofValue;
use App\Domain\Asset\ValueObject\UploadId;
use DateTimeImmutable;

final class Asset
{
    private AssetId $id;
    private UploadId $uploadId;
    private AccountId $accountId;
    private string $fileName;
    private string $mimeType;
    private AssetStatus $status;
    private ?UploadCompletionProofValue $completionProof = null;
    private DateTimeImmutable $createdAt;

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
        $this->fileName = self::normalizeRequiredText($fileName, 'File name must be non-empty');
        $this->mimeType = self::normalizeRequiredText($mimeType, 'Mime type must be non-empty');
        self::assertCompletionProofMatchesStatus($status, $completionProof);
        $this->status = $status;
        $this->completionProof = $completionProof;
    }

    /**
     * @throws AssetDomainException
     */
    public static function createPending(UploadId $uploadId, AccountId $accountId, string $fileName, string $mimeType): self
    {
        $asset = new self(
            id: AssetId::generate(),
            uploadId: $uploadId,
            accountId: $accountId,
            fileName: $fileName,
            mimeType: $mimeType,
            status: AssetStatus::PENDING,
        );

        $asset->createdAt = new DateTimeImmutable();

        return $asset;
    }

    /**
     * Reconstitutes a non-uploaded Asset from persistence.
     * For UPLOADED status, use reconstituteUploaded() instead.
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
        DateTimeImmutable $createdAt,
    ): self {
        $asset = new self(
            id: $id,
            uploadId: $uploadId,
            accountId: $accountId,
            fileName: $fileName,
            mimeType: $mimeType,
            status: $status,
        );

        $asset->createdAt = $createdAt;

        return $asset;
    }

    /**
     * @throws AssetDomainException
     */
    public static function reconstituteUploaded(
        AssetId $id,
        UploadId $uploadId,
        AccountId $accountId,
        string $fileName,
        string $mimeType,
        DateTimeImmutable $createdAt,
        UploadCompletionProofValue $completionProof,
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

        $asset->createdAt = $createdAt;

        return $asset;
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
    private static function normalizeRequiredText(string $value, string $message): string
    {
        $normalizedValue = trim($value);

        if ($normalizedValue === '') {
            throw new AssetDomainException($message);
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

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function equals(Asset $other): bool
    {
        return $this->id->equals($other->id);
    }
}
