<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Asset;

use App\Domain\Asset\Asset;
use App\Domain\Asset\AssetStatus;
use App\Domain\Asset\Exception\AssetDomainException;
use App\Domain\Asset\ValueObject\AccountId;
use App\Domain\Asset\ValueObject\AssetId;
use App\Domain\Asset\ValueObject\UploadCompletionProofValue;
use App\Domain\Asset\ValueObject\UploadId;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AssetTest extends TestCase
{
    private const ASSET_ID = '123e4567-e89b-42d3-a456-426614174099';
    private const OTHER_ASSET_ID = '123e4567-e89b-42d3-a456-426614174098';
    private const FIRST_UPLOAD_ID = '123e4567-e89b-42d3-a456-426614174000';
    private const SECOND_UPLOAD_ID = '123e4567-e89b-42d3-a456-426614174001';
    private const ACCOUNT_ID = 'account-123';
    private const FILE_NAME = 'image.png';
    private const MIME_TYPE = 'image/png';
    private const COMPLETION_PROOF_VALUE = 'etag-value';
    private const UUID_V4_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i';

    #[Test]
    public function itCreatesPendingAssetWithGeneratedIdentifierAndRequiredMetadata(): void
    {
        // Arrange
        $beforeCreation = new DateTimeImmutable();
        $uploadId = new UploadId(self::FIRST_UPLOAD_ID);
        $accountId = new AccountId(self::ACCOUNT_ID);

        // Act
        $asset = Asset::createPending($uploadId, $accountId, '  ' . self::FILE_NAME . '  ', '  ' . self::MIME_TYPE . '  ');
        $afterCreation = new DateTimeImmutable();

        // Assert
        self::assertInstanceOf(AssetId::class, $asset->getId());
        self::assertMatchesRegularExpression(self::UUID_V4_PATTERN, $asset->getId()->value);
        self::assertNotSame((string) $uploadId, (string) $asset->getId());
        self::assertSame($uploadId, $asset->getUploadId());
        self::assertSame($accountId, $asset->getAccountId());
        self::assertSame(self::ACCOUNT_ID, (string) $asset->getAccountId());
        self::assertSame(self::FILE_NAME, $asset->getFileName());
        self::assertSame(self::MIME_TYPE, $asset->getMimeType());
        self::assertSame(AssetStatus::PENDING, $asset->getStatus());
        self::assertGreaterThanOrEqual($beforeCreation->getTimestamp(), $asset->getCreatedAt()->getTimestamp());
        self::assertLessThanOrEqual($afterCreation->getTimestamp(), $asset->getCreatedAt()->getTimestamp());
    }

    #[Test]
    #[DataProvider('invalidRequiredTextProvider')]
    public function itThrowsAssetDomainExceptionWhenCreatePendingReceivesInvalidRequiredText(string $fileName, string $mimeType, string $expectedMessage): void
    {
        // Arrange
        $uploadId = new UploadId(self::FIRST_UPLOAD_ID);
        $accountId = new AccountId(self::ACCOUNT_ID);
        $this->expectException(AssetDomainException::class);
        $this->expectExceptionMessage($expectedMessage);

        // Act
        Asset::createPending($uploadId, $accountId, $fileName, $mimeType);
    }

    #[Test]
    public function itReconstitutesAssetWithPersistedValues(): void
    {
        // Arrange
        $assetId = new AssetId(self::ASSET_ID);
        $uploadId = new UploadId(self::FIRST_UPLOAD_ID);
        $accountId = new AccountId(self::ACCOUNT_ID);
        $createdAt = new DateTimeImmutable('2026-01-20T12:34:56+00:00');

        // Act
        $asset = Asset::reconstitute(
            $assetId,
            $uploadId,
            $accountId,
            '  ' . self::FILE_NAME . '  ',
            '  ' . self::MIME_TYPE . '  ',
            AssetStatus::FAILED,
            $createdAt,
        );

        // Assert
        self::assertSame($assetId, $asset->getId());
        self::assertSame($uploadId, $asset->getUploadId());
        self::assertSame($accountId, $asset->getAccountId());
        self::assertSame(self::FILE_NAME, $asset->getFileName());
        self::assertSame(self::MIME_TYPE, $asset->getMimeType());
        self::assertSame(AssetStatus::FAILED, $asset->getStatus());
        self::assertSame($createdAt, $asset->getCreatedAt());
    }

    #[Test]
    public function itReconstitutesUploadedAssetWithPersistedValues(): void
    {
        // Arrange
        $assetId = new AssetId(self::ASSET_ID);
        $uploadId = new UploadId(self::FIRST_UPLOAD_ID);
        $accountId = new AccountId(self::ACCOUNT_ID);
        $createdAt = new DateTimeImmutable('2026-01-20T12:34:56+00:00');

        // Act
        $asset = Asset::reconstituteUploaded(
            $assetId,
            $uploadId,
            $accountId,
            '  ' . self::FILE_NAME . '  ',
            '  ' . self::MIME_TYPE . '  ',
            $createdAt,
            $this->createCompletionProofValue(),
        );

        // Assert
        self::assertSame($assetId, $asset->getId());
        self::assertSame($uploadId, $asset->getUploadId());
        self::assertSame($accountId, $asset->getAccountId());
        self::assertSame(self::FILE_NAME, $asset->getFileName());
        self::assertSame(self::MIME_TYPE, $asset->getMimeType());
        self::assertSame(AssetStatus::UPLOADED, $asset->getStatus());
        self::assertSame($createdAt, $asset->getCreatedAt());
    }

    #[Test]
    public function itThrowsAssetDomainExceptionWhenReconstitutingUploadedAssetWithoutCompletionProof(): void
    {
        // Arrange
        $this->expectException(AssetDomainException::class);
        $this->expectExceptionMessage('Uploaded assets must have completion proof');

        // Act
        Asset::reconstitute(
            new AssetId(self::ASSET_ID),
            new UploadId(self::FIRST_UPLOAD_ID),
            new AccountId(self::ACCOUNT_ID),
            self::FILE_NAME,
            self::MIME_TYPE,
            AssetStatus::UPLOADED,
            new DateTimeImmutable('2026-01-20T12:34:56+00:00'),
        );
    }

    #[Test]
    #[DataProvider('invalidRequiredTextProvider')]
    public function itThrowsAssetDomainExceptionWhenReconstitutingAssetWithInvalidRequiredText(string $fileName, string $mimeType, string $expectedMessage): void
    {
        // Arrange
        $this->expectException(AssetDomainException::class);
        $this->expectExceptionMessage($expectedMessage);

        // Act
        Asset::reconstitute(
            new AssetId(self::ASSET_ID),
            new UploadId(self::FIRST_UPLOAD_ID),
            new AccountId(self::ACCOUNT_ID),
            $fileName,
            $mimeType,
            AssetStatus::PENDING,
            new DateTimeImmutable('2026-01-20T12:34:56+00:00'),
        );
    }

    #[Test]
    public function itCreatesDistinctPendingAssetsAcrossCalls(): void
    {
        // Arrange
        $firstUploadId = new UploadId(self::FIRST_UPLOAD_ID);
        $secondUploadId = new UploadId(self::SECOND_UPLOAD_ID);
        $accountId = new AccountId(self::ACCOUNT_ID);

        // Act
        $firstAsset = Asset::createPending($firstUploadId, $accountId, self::FILE_NAME, self::MIME_TYPE);
        $secondAsset = Asset::createPending($secondUploadId, $accountId, self::FILE_NAME, self::MIME_TYPE);

        // Assert
        self::assertNotSame((string) $firstAsset->getId(), (string) $secondAsset->getId());
        self::assertFalse($firstAsset->equals($secondAsset));
    }

    #[Test]
    public function itMarksPendingAssetAsUploaded(): void
    {
        // Arrange
        $asset = $this->createPendingAsset();

        // Act
        $asset->markUploaded($this->createCompletionProofValue());

        // Assert
        self::assertSame(AssetStatus::UPLOADED, $asset->getStatus());
    }

    #[Test]
    public function itThrowsAssetDomainExceptionWhenMarkingUploadedAssetAsUploadedAgain(): void
    {
        // Arrange
        $asset = $this->createPendingAsset();
        $asset->markUploaded($this->createCompletionProofValue());
        $this->expectException(AssetDomainException::class);
        $this->expectExceptionMessage('Asset already uploaded');

        // Act
        $asset->markUploaded($this->createCompletionProofValue('second-proof'));
    }

    #[Test]
    public function itThrowsAssetDomainExceptionWhenMarkingFailedAssetAsUploaded(): void
    {
        // Arrange
        $asset = $this->createPendingAsset();
        $asset->markFailed();
        $this->expectException(AssetDomainException::class);
        $this->expectExceptionMessage('Cannot upload asset from current state');

        // Act
        $asset->markUploaded($this->createCompletionProofValue());
    }

    #[Test]
    public function itMarksPendingAssetAsFailed(): void
    {
        // Arrange
        $asset = $this->createPendingAsset();

        // Act
        $asset->markFailed();

        // Assert
        self::assertSame(AssetStatus::FAILED, $asset->getStatus());
    }

    #[Test]
    public function itThrowsAssetDomainExceptionWhenMarkingUploadedAssetAsFailed(): void
    {
        // Arrange
        $asset = $this->createPendingAsset();
        $asset->markUploaded($this->createCompletionProofValue());
        $this->expectException(AssetDomainException::class);
        $this->expectExceptionMessage('Cannot mark an uploaded asset as failed');

        // Act
        $asset->markFailed();
    }

    #[Test]
    public function itReturnsTrueWhenComparingAssetsWithSameIdentity(): void
    {
        // Arrange
        $firstAsset = Asset::reconstitute(
            new AssetId(self::ASSET_ID),
            new UploadId(self::FIRST_UPLOAD_ID),
            new AccountId(self::ACCOUNT_ID),
            self::FILE_NAME,
            self::MIME_TYPE,
            AssetStatus::PENDING,
            new DateTimeImmutable('2026-01-20T12:34:56+00:00'),
        );
        $secondAsset = Asset::reconstitute(
            new AssetId(self::ASSET_ID),
            new UploadId(self::SECOND_UPLOAD_ID),
            new AccountId('other-account'),
            'other-file.pdf',
            'application/pdf',
            AssetStatus::FAILED,
            new DateTimeImmutable('2026-01-21T12:34:56+00:00'),
        );

        // Act
        $isEqual = $firstAsset->equals($secondAsset);

        // Assert
        self::assertTrue($isEqual);
    }

    #[Test]
    public function itReturnsFalseWhenComparingAssetsWithDifferentIdentity(): void
    {
        // Arrange
        $firstAsset = Asset::reconstitute(
            new AssetId(self::ASSET_ID),
            new UploadId(self::FIRST_UPLOAD_ID),
            new AccountId(self::ACCOUNT_ID),
            self::FILE_NAME,
            self::MIME_TYPE,
            AssetStatus::PENDING,
            new DateTimeImmutable('2026-01-20T12:34:56+00:00'),
        );
        $secondAsset = Asset::reconstitute(
            new AssetId(self::OTHER_ASSET_ID),
            new UploadId(self::FIRST_UPLOAD_ID),
            new AccountId(self::ACCOUNT_ID),
            self::FILE_NAME,
            self::MIME_TYPE,
            AssetStatus::PENDING,
            new DateTimeImmutable('2026-01-20T12:34:56+00:00'),
        );

        // Act
        $isEqual = $firstAsset->equals($secondAsset);

        // Assert
        self::assertFalse($isEqual);
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function invalidRequiredTextProvider(): array
    {
        return [
            'empty file name' => ['', self::MIME_TYPE, 'File name must be non-empty'],
            'whitespace file name' => ['   ', self::MIME_TYPE, 'File name must be non-empty'],
            'empty mime type' => [self::FILE_NAME, '', 'Mime type must be non-empty'],
            'whitespace mime type' => [self::FILE_NAME, '   ', 'Mime type must be non-empty'],
        ];
    }

    private function createPendingAsset(string $uploadId = self::FIRST_UPLOAD_ID): Asset
    {
        return Asset::createPending(
            new UploadId($uploadId),
            new AccountId(self::ACCOUNT_ID),
            self::FILE_NAME,
            self::MIME_TYPE,
        );
    }

    private function createCompletionProofValue(string $value = self::COMPLETION_PROOF_VALUE): UploadCompletionProofValue
    {
        return new UploadCompletionProofValue($value);
    }
}
