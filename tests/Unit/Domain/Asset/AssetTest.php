<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Asset;

use App\Domain\Asset\Asset;
use App\Domain\Asset\AssetStatus;
use App\Domain\Asset\Exception\AssetDomainException;
use App\Domain\Asset\ValueObject\AccountId;
use App\Domain\Asset\ValueObject\UploadId;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AssetTest extends TestCase
{
    private const FIRST_UPLOAD_ID = '123e4567-e89b-42d3-a456-426614174000';
    private const SECOND_UPLOAD_ID = '123e4567-e89b-42d3-a456-426614174001';
    private const ACCOUNT_ID = '123';
    private const FILENAME = 'image.png';
    private const CONTENT_TYPE = 'image/png';
    private const SIZE = 512;
    private const UUID_V4_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/';

    #[Test]
    public function itCreatesPendingAssetWithGeneratedIdentifierAndDefaultMetadata(): void
    {
        // Arrange
        $uploadId = new UploadId(self::FIRST_UPLOAD_ID);
        $accountId = new AccountId(self::ACCOUNT_ID);

        // Act
        $asset = Asset::createPending($uploadId, $accountId);

        // Assert
        self::assertMatchesRegularExpression(self::UUID_V4_PATTERN, $asset->getId());
        self::assertSame($uploadId, $asset->getUploadId());
        self::assertSame($accountId, $asset->getAccountId());
        self::assertSame(self::ACCOUNT_ID, (string) $asset->getAccountId());
        self::assertSame(AssetStatus::PENDING, $asset->getStatus());
        self::assertNull($asset->getFilename());
        self::assertNull($asset->getContentType());
        self::assertNull($asset->getSize());
        self::assertInstanceOf(DateTimeImmutable::class, $asset->getCreatedAt());
        self::assertSame($asset->getCreatedAt(), $asset->getUpdatedAt());
    }

    #[Test]
    public function itCreatesDistinctPendingAssetsAcrossCalls(): void
    {
        // Arrange
        $firstUploadId = new UploadId(self::FIRST_UPLOAD_ID);
        $secondUploadId = new UploadId(self::SECOND_UPLOAD_ID);
        $accountId = new AccountId(self::ACCOUNT_ID);

        // Act
        $firstAsset = Asset::createPending($firstUploadId, $accountId);
        $secondAsset = Asset::createPending($secondUploadId, $accountId);

        // Assert
        self::assertNotSame($firstAsset->getId(), $secondAsset->getId());
        self::assertFalse($firstAsset->equals($secondAsset));
    }

    #[Test]
    public function itMarksPendingAssetAsUploadedAndStoresMetadata(): void
    {
        // Arrange
        $asset = $this->createPendingAsset();
        $previousUpdatedAt = $asset->getUpdatedAt();

        // Act
        $asset->markUploaded(self::FILENAME, self::CONTENT_TYPE, self::SIZE);

        // Assert
        self::assertSame(AssetStatus::UPLOADED, $asset->getStatus());
        self::assertSame(self::FILENAME, $asset->getFilename());
        self::assertSame(self::CONTENT_TYPE, $asset->getContentType());
        self::assertSame(self::SIZE, $asset->getSize());
        self::assertNotSame($previousUpdatedAt, $asset->getUpdatedAt());
    }

    #[Test]
    public function itThrowsAssetDomainExceptionWhenMarkingUploadedAssetAsUploadedAgain(): void
    {
        // Arrange
        $asset = $this->createPendingAsset();
        $asset->markUploaded(self::FILENAME, self::CONTENT_TYPE, self::SIZE);
        $this->expectException(AssetDomainException::class);
        $this->expectExceptionMessage('Asset already uploaded');

        // Act
        $asset->markUploaded(self::FILENAME, self::CONTENT_TYPE, self::SIZE);
    }

    #[Test]
    public function itMarksPendingAssetAsFailed(): void
    {
        // Arrange
        $asset = $this->createPendingAsset();
        $previousUpdatedAt = $asset->getUpdatedAt();

        // Act
        $asset->markFailed();

        // Assert
        self::assertSame(AssetStatus::FAILED, $asset->getStatus());
        self::assertNotSame($previousUpdatedAt, $asset->getUpdatedAt());
    }

    #[Test]
    public function itThrowsAssetDomainExceptionWhenMarkingUploadedAssetAsFailed(): void
    {
        // Arrange
        $asset = $this->createPendingAsset();
        $asset->markUploaded(self::FILENAME, self::CONTENT_TYPE, self::SIZE);
        $this->expectException(AssetDomainException::class);
        $this->expectExceptionMessage('Cannot mark an uploaded asset as failed');

        // Act
        $asset->markFailed();
    }

    #[Test]
    public function itReturnsTrueWhenComparingSameAssetInstanceByIdentity(): void
    {
        // Arrange
        $asset = $this->createPendingAsset();

        // Act
        $isEqual = $asset->equals($asset);

        // Assert
        self::assertTrue($isEqual);
    }

    private function createPendingAsset(): Asset
    {
        return Asset::createPending(
            new UploadId(self::FIRST_UPLOAD_ID),
            new AccountId(self::ACCOUNT_ID),
        );
    }
}
