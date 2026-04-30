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
    private const DEFAULT_CHUNK_COUNT = 1;
    private const CHUNK_COUNT = 4;
    private const ASSET_ID = '123e4567-e89b-42d3-a456-426614174099';
    private const OTHER_ASSET_ID = '123e4567-e89b-42d3-a456-426614174098';
    private const FIRST_UPLOAD_ID = '123e4567-e89b-42d3-a456-426614174000';
    private const SECOND_UPLOAD_ID = '123e4567-e89b-42d3-a456-426614174001';
    private const ACCOUNT_ID = 'account-123';
    private const FILE_NAME = 'image.png';
    private const MIME_TYPE = 'image/png';
    private const COMPLETION_PROOF_VALUE = 'etag-value';
    private const CREATED_AT = '2020-01-20T12:34:56+00:00';
    private const UPDATED_AT = '2020-01-21T12:34:56+00:00';
    private const INVALID_CHUNK_COUNT_MESSAGE = 'Chunk count must be between 1 and 100.';
    private const INVALID_UPDATED_AT_MESSAGE = 'Updated at must not be earlier than created at';
    private const UUID_V4_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i';

    #[Test]
    public function itReturnsPendingAssetWhenCreatedWithGeneratedIdentifierAndRequiredMetadata(): void
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
        self::assertSame(self::DEFAULT_CHUNK_COUNT, $asset->getChunkCount());
        self::assertGreaterThanOrEqual($beforeCreation->format('U.u'), $asset->getCreatedAt()->format('U.u'));
        self::assertLessThanOrEqual($afterCreation->format('U.u'), $asset->getCreatedAt()->format('U.u'));
        self::assertSame($asset->getCreatedAt(), $asset->getUpdatedAt());
    }

    #[Test]
    public function itReturnsPendingAssetWhenCreatedWithRequestedChunkCount(): void
    {
        // Arrange
        $uploadId = new UploadId(self::FIRST_UPLOAD_ID);
        $accountId = new AccountId(self::ACCOUNT_ID);

        // Act
        $asset = Asset::createPending($uploadId, $accountId, self::FILE_NAME, self::MIME_TYPE, self::CHUNK_COUNT);

        // Assert
        self::assertSame(self::CHUNK_COUNT, $asset->getChunkCount());
        self::assertSame(AssetStatus::PENDING, $asset->getStatus());
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
    #[DataProvider('invalidChunkCountProvider')]
    public function itThrowsAssetDomainExceptionWhenCreatePendingReceivesInvalidChunkCount(int $chunkCount): void
    {
        // Arrange
        $this->expectException(AssetDomainException::class);
        $this->expectExceptionMessage(self::INVALID_CHUNK_COUNT_MESSAGE);

        // Act
        Asset::createPending(
            new UploadId(self::FIRST_UPLOAD_ID),
            new AccountId(self::ACCOUNT_ID),
            self::FILE_NAME,
            self::MIME_TYPE,
            $chunkCount,
        );
    }

    #[Test]
    public function itReturnsAssetWhenReconstitutedWithPersistedValues(): void
    {
        // Arrange
        $assetId = new AssetId(self::ASSET_ID);
        $uploadId = new UploadId(self::FIRST_UPLOAD_ID);
        $accountId = new AccountId(self::ACCOUNT_ID);
        $createdAt = new DateTimeImmutable(self::CREATED_AT);
        $updatedAt = new DateTimeImmutable(self::UPDATED_AT);

        // Act
        $asset = Asset::reconstitute(
            $assetId,
            $uploadId,
            $accountId,
            '  ' . self::FILE_NAME . '  ',
            '  ' . self::MIME_TYPE . '  ',
            AssetStatus::FAILED,
            $this->persistedState($createdAt, self::CHUNK_COUNT, $updatedAt),
        );

        // Assert
        self::assertSame($assetId, $asset->getId());
        self::assertSame($uploadId, $asset->getUploadId());
        self::assertSame($accountId, $asset->getAccountId());
        self::assertSame(self::FILE_NAME, $asset->getFileName());
        self::assertSame(self::MIME_TYPE, $asset->getMimeType());
        self::assertSame(AssetStatus::FAILED, $asset->getStatus());
        self::assertSame(self::CHUNK_COUNT, $asset->getChunkCount());
        self::assertSame($createdAt, $asset->getCreatedAt());
        self::assertSame($updatedAt, $asset->getUpdatedAt());
    }

    #[Test]
    public function itReturnsUploadedAssetWhenReconstitutedWithPersistedValues(): void
    {
        // Arrange
        $assetId = new AssetId(self::ASSET_ID);
        $uploadId = new UploadId(self::FIRST_UPLOAD_ID);
        $accountId = new AccountId(self::ACCOUNT_ID);
        $createdAt = new DateTimeImmutable(self::CREATED_AT);
        $updatedAt = new DateTimeImmutable(self::UPDATED_AT);

        // Act
        $asset = Asset::reconstituteUploaded(
            $assetId,
            $uploadId,
            $accountId,
            '  ' . self::FILE_NAME . '  ',
            '  ' . self::MIME_TYPE . '  ',
            $this->createCompletionProofValue(),
            $this->persistedState($createdAt, self::CHUNK_COUNT, $updatedAt),
        );

        // Assert
        self::assertSame($assetId, $asset->getId());
        self::assertSame($uploadId, $asset->getUploadId());
        self::assertSame($accountId, $asset->getAccountId());
        self::assertSame(self::FILE_NAME, $asset->getFileName());
        self::assertSame(self::MIME_TYPE, $asset->getMimeType());
        self::assertSame(AssetStatus::UPLOADED, $asset->getStatus());
        self::assertSame(self::CHUNK_COUNT, $asset->getChunkCount());
        self::assertSame($createdAt, $asset->getCreatedAt());
        self::assertSame($updatedAt, $asset->getUpdatedAt());
        self::assertEquals($this->createCompletionProofValue(), $asset->getCompletionProof());
    }

    #[Test]
    public function itReturnsAssetWhenReconstitutedWithMinimumValidChunkCount(): void
    {
        // Arrange
        $createdAt = new DateTimeImmutable(self::CREATED_AT);
        $updatedAt = new DateTimeImmutable(self::UPDATED_AT);

        // Act
        $asset = Asset::reconstitute(
            new AssetId(self::ASSET_ID),
            new UploadId(self::FIRST_UPLOAD_ID),
            new AccountId(self::ACCOUNT_ID),
            self::FILE_NAME,
            self::MIME_TYPE,
            AssetStatus::PENDING,
            $this->persistedState($createdAt, self::DEFAULT_CHUNK_COUNT, $updatedAt),
        );

        // Assert
        self::assertSame(self::DEFAULT_CHUNK_COUNT, $asset->getChunkCount());
        self::assertSame($updatedAt, $asset->getUpdatedAt());
    }

    #[Test]
    public function itDefaultsChunkCountAndUpdatedAtWhenReconstitutingAssetWithoutOptionalPersistedStateFields(): void
    {
        // Arrange
        $createdAt = new DateTimeImmutable(self::CREATED_AT);

        // Act
        $asset = Asset::reconstitute(
            new AssetId(self::ASSET_ID),
            new UploadId(self::FIRST_UPLOAD_ID),
            new AccountId(self::ACCOUNT_ID),
            self::FILE_NAME,
            self::MIME_TYPE,
            AssetStatus::PENDING,
            [
                'createdAt' => $createdAt,
            ],
        );

        // Assert
        self::assertSame(self::DEFAULT_CHUNK_COUNT, $asset->getChunkCount());
        self::assertSame($createdAt, $asset->getUpdatedAt());
    }

    #[Test]
    public function itDefaultsChunkCountAndUpdatedAtWhenReconstitutingUploadedAssetWithoutOptionalPersistedStateFields(): void
    {
        // Arrange
        $createdAt = new DateTimeImmutable(self::CREATED_AT);

        // Act
        $asset = Asset::reconstituteUploaded(
            new AssetId(self::ASSET_ID),
            new UploadId(self::FIRST_UPLOAD_ID),
            new AccountId(self::ACCOUNT_ID),
            self::FILE_NAME,
            self::MIME_TYPE,
            $this->createCompletionProofValue(),
            [
                'createdAt' => $createdAt,
            ],
        );

        // Assert
        self::assertSame(self::DEFAULT_CHUNK_COUNT, $asset->getChunkCount());
        self::assertSame($createdAt, $asset->getUpdatedAt());
    }

    #[Test]
    #[DataProvider('invalidChunkCountProvider')]
    public function itThrowsAssetDomainExceptionWhenReconstitutingAssetWithInvalidChunkCount(int $chunkCount): void
    {
        // Arrange
        $this->expectException(AssetDomainException::class);
        $this->expectExceptionMessage(self::INVALID_CHUNK_COUNT_MESSAGE);

        // Act
        Asset::reconstitute(
            new AssetId(self::ASSET_ID),
            new UploadId(self::FIRST_UPLOAD_ID),
            new AccountId(self::ACCOUNT_ID),
            self::FILE_NAME,
            self::MIME_TYPE,
            AssetStatus::PENDING,
            [
                'createdAt' => new DateTimeImmutable(self::CREATED_AT),
                'chunkCount' => $chunkCount,
                'updatedAt' => new DateTimeImmutable(self::UPDATED_AT),
            ],
        );
    }

    #[Test]
    #[DataProvider('invalidChunkCountProvider')]
    public function itThrowsAssetDomainExceptionWhenReconstitutingUploadedAssetWithInvalidChunkCount(int $chunkCount): void
    {
        // Arrange
        $this->expectException(AssetDomainException::class);
        $this->expectExceptionMessage(self::INVALID_CHUNK_COUNT_MESSAGE);

        // Act
        Asset::reconstituteUploaded(
            new AssetId(self::ASSET_ID),
            new UploadId(self::FIRST_UPLOAD_ID),
            new AccountId(self::ACCOUNT_ID),
            self::FILE_NAME,
            self::MIME_TYPE,
            $this->createCompletionProofValue(),
            [
                'createdAt' => new DateTimeImmutable(self::CREATED_AT),
                'chunkCount' => $chunkCount,
                'updatedAt' => new DateTimeImmutable(self::UPDATED_AT),
            ],
        );
    }

    #[Test]
    public function itThrowsAssetDomainExceptionWhenReconstitutingAssetWithUpdatedAtEarlierThanCreatedAt(): void
    {
        // Arrange
        $this->expectException(AssetDomainException::class);
        $this->expectExceptionMessage(self::INVALID_UPDATED_AT_MESSAGE);

        // Act
        Asset::reconstitute(
            new AssetId(self::ASSET_ID),
            new UploadId(self::FIRST_UPLOAD_ID),
            new AccountId(self::ACCOUNT_ID),
            self::FILE_NAME,
            self::MIME_TYPE,
            AssetStatus::FAILED,
            [
                'createdAt' => new DateTimeImmutable(self::UPDATED_AT),
                'chunkCount' => self::CHUNK_COUNT,
                'updatedAt' => new DateTimeImmutable(self::CREATED_AT),
            ],
        );
    }

    #[Test]
    public function itThrowsAssetDomainExceptionWhenReconstitutingUploadedAssetWithUpdatedAtEarlierThanCreatedAt(): void
    {
        // Arrange
        $this->expectException(AssetDomainException::class);
        $this->expectExceptionMessage(self::INVALID_UPDATED_AT_MESSAGE);

        // Act
        Asset::reconstituteUploaded(
            new AssetId(self::ASSET_ID),
            new UploadId(self::FIRST_UPLOAD_ID),
            new AccountId(self::ACCOUNT_ID),
            self::FILE_NAME,
            self::MIME_TYPE,
            $this->createCompletionProofValue(),
            [
                'createdAt' => new DateTimeImmutable(self::UPDATED_AT),
                'chunkCount' => self::CHUNK_COUNT,
                'updatedAt' => new DateTimeImmutable(self::CREATED_AT),
            ],
        );
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
            $this->persistedState(new DateTimeImmutable('2026-01-20T12:34:56+00:00')),
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
            $this->persistedState(new DateTimeImmutable('2026-01-20T12:34:56+00:00')),
        );
    }

    #[Test]
    public function itReturnsDistinctPendingAssetsWhenCreatedAcrossCalls(): void
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
    public function itReturnsAssetWithUploadedStatusWhenMarkedUploaded(): void
    {
        // Arrange
        $asset = $this->reconstituteAsset();
        $originalUpdatedAt = $asset->getUpdatedAt();

        // Act
        $asset->markUploaded($this->createCompletionProofValue());

        // Assert
        self::assertSame(AssetStatus::UPLOADED, $asset->getStatus());
        self::assertEquals($this->createCompletionProofValue(), $asset->getCompletionProof());
        self::assertGreaterThan($originalUpdatedAt->format('U.u'), $asset->getUpdatedAt()->format('U.u'));
    }

    #[Test]
    public function itReturnsAssetWithUploadedStatusWhenProcessingAssetIsMarkedUploaded(): void
    {
        // Arrange
        $asset = $this->reconstituteProcessingAsset();
        $originalUpdatedAt = $asset->getUpdatedAt();
        $completionProof = $asset->getCompletionProof();

        self::assertNotNull($completionProof);

        // Act
        $asset->markUploaded($completionProof);

        // Assert
        self::assertSame(AssetStatus::UPLOADED, $asset->getStatus());
        self::assertSame($completionProof, $asset->getCompletionProof());
        self::assertGreaterThan($originalUpdatedAt->format('U.u'), $asset->getUpdatedAt()->format('U.u'));
    }

    #[Test]
    public function itReturnsAssetWithPendingStatusWhenProcessingAssetIsRestoredToPending(): void
    {
        // Arrange
        $asset = $this->reconstituteProcessingAsset();
        $originalUpdatedAt = $asset->getUpdatedAt();

        // Act
        $asset->restorePending();

        // Assert
        self::assertSame(AssetStatus::PENDING, $asset->getStatus());
        self::assertNull($asset->getCompletionProof());
        self::assertGreaterThan($originalUpdatedAt->format('U.u'), $asset->getUpdatedAt()->format('U.u'));
    }

    #[Test]
    public function itThrowsAssetDomainExceptionWhenPendingAssetIsRestoredToPending(): void
    {
        // Arrange
        $asset = $this->reconstituteAsset();
        $originalUpdatedAt = $asset->getUpdatedAt();

        // Act
        $this->assertTransitionRejected(
            fn () => $asset->restorePending(),
            'Only processing assets can be restored to pending',
            $asset,
            $originalUpdatedAt,
        );
    }

    #[Test]
    public function itDoesNotMoveUpdatedAtBackwardWhenMarkingUploadedWithFuturePersistedUpdatedAt(): void
    {
        // Arrange
        $futureUpdatedAt = new DateTimeImmutable('2099-01-21T12:34:56+00:00');
        $asset = $this->reconstituteAsset(updatedAt: $futureUpdatedAt);

        // Act
        $asset->markUploaded($this->createCompletionProofValue());

        // Assert
        self::assertSame(AssetStatus::UPLOADED, $asset->getStatus());
        self::assertEquals($this->createCompletionProofValue(), $asset->getCompletionProof());
        self::assertSame($futureUpdatedAt, $asset->getUpdatedAt());
    }

    #[Test]
    public function itThrowsAssetDomainExceptionWhenMarkingUploadedAssetAsUploadedAgain(): void
    {
        // Arrange
        $asset = $this->reconstituteUploadedAsset();
        $originalUpdatedAt = $asset->getUpdatedAt();

        // Act
        $this->assertTransitionRejected(
            fn () => $asset->markUploaded(new UploadCompletionProofValue('second-proof')),
            'Asset already uploaded',
            $asset,
            $originalUpdatedAt,
        );
    }

    #[Test]
    public function itThrowsAssetDomainExceptionWhenMarkingFailedAssetAsUploaded(): void
    {
        // Arrange
        $asset = $this->reconstituteAsset(status: AssetStatus::FAILED);
        $originalUpdatedAt = $asset->getUpdatedAt();

        // Act
        $this->assertTransitionRejected(
            fn () => $asset->markUploaded(new UploadCompletionProofValue(self::COMPLETION_PROOF_VALUE)),
            'Cannot upload asset from current state',
            $asset,
            $originalUpdatedAt,
        );
    }

    #[Test]
    public function itReturnsAssetWithFailedStatusWhenMarkedFailed(): void
    {
        // Arrange
        $asset = $this->reconstituteAsset();
        $originalUpdatedAt = $asset->getUpdatedAt();

        // Act
        $asset->markFailed();

        // Assert
        self::assertSame(AssetStatus::FAILED, $asset->getStatus());
        self::assertGreaterThan($originalUpdatedAt->format('U.u'), $asset->getUpdatedAt()->format('U.u'));
    }

    #[Test]
    public function itReturnsClearedCompletionProofWhenProcessingAssetIsMarkedFailed(): void
    {
        // Arrange
        $asset = $this->reconstituteProcessingAsset();
        $originalUpdatedAt = $asset->getUpdatedAt();

        // Act
        $asset->markFailed();

        // Assert
        self::assertSame(AssetStatus::FAILED, $asset->getStatus());
        self::assertNull($asset->getCompletionProof());
        self::assertGreaterThan($originalUpdatedAt->format('U.u'), $asset->getUpdatedAt()->format('U.u'));
    }

    #[Test]
    public function itDoesNotMoveUpdatedAtBackwardWhenMarkingFailedWithFuturePersistedUpdatedAt(): void
    {
        // Arrange
        $futureUpdatedAt = new DateTimeImmutable('2099-01-21T12:34:56+00:00');
        $asset = $this->reconstituteAsset(updatedAt: $futureUpdatedAt);

        // Act
        $asset->markFailed();

        // Assert
        self::assertSame(AssetStatus::FAILED, $asset->getStatus());
        self::assertSame($futureUpdatedAt, $asset->getUpdatedAt());
    }

    #[Test]
    public function itDoesNotChangeUpdatedAtWhenMarkingFailedAssetAsFailedAgain(): void
    {
        // Arrange
        $asset = $this->reconstituteAsset(status: AssetStatus::FAILED);
        $originalUpdatedAt = $asset->getUpdatedAt();

        // Act
        $asset->markFailed();

        // Assert
        self::assertSame(AssetStatus::FAILED, $asset->getStatus());
        self::assertSame($originalUpdatedAt, $asset->getUpdatedAt());
    }

    #[Test]
    public function itThrowsAssetDomainExceptionWhenMarkingUploadedAssetAsFailed(): void
    {
        // Arrange
        $asset = $this->reconstituteUploadedAsset();
        $originalUpdatedAt = $asset->getUpdatedAt();

        // Act
        $this->assertTransitionRejected(
            fn () => $asset->markFailed(),
            'Cannot mark an uploaded asset as failed',
            $asset,
            $originalUpdatedAt,
        );
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
            $this->persistedState(new DateTimeImmutable('2026-01-20T12:34:56+00:00')),
        );
        $secondAsset = Asset::reconstitute(
            new AssetId(self::ASSET_ID),
            new UploadId(self::SECOND_UPLOAD_ID),
            new AccountId('other-account'),
            'other-file.pdf',
            'application/pdf',
            AssetStatus::FAILED,
            $this->persistedState(new DateTimeImmutable('2026-01-21T12:34:56+00:00')),
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
            $this->persistedState(new DateTimeImmutable('2026-01-20T12:34:56+00:00')),
        );
        $secondAsset = Asset::reconstitute(
            new AssetId(self::OTHER_ASSET_ID),
            new UploadId(self::FIRST_UPLOAD_ID),
            new AccountId(self::ACCOUNT_ID),
            self::FILE_NAME,
            self::MIME_TYPE,
            AssetStatus::PENDING,
            $this->persistedState(new DateTimeImmutable('2026-01-20T12:34:56+00:00')),
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

    /**
     * @return array<string, array{0: int}>
     */
    public static function invalidChunkCountProvider(): array
    {
        return [
            'zero chunks' => [0],
            'negative chunks' => [-1],
            'too many chunks' => [101],
        ];
    }

    private function reconstituteAsset(
        AssetStatus $status = AssetStatus::PENDING,
        ?DateTimeImmutable $createdAt = null,
        ?DateTimeImmutable $updatedAt = null,
        int $chunkCount = self::CHUNK_COUNT,
    ): Asset {
        $createdAt ??= new DateTimeImmutable(self::CREATED_AT);
        $updatedAt ??= new DateTimeImmutable(self::UPDATED_AT);

        return Asset::reconstitute(
            new AssetId(self::ASSET_ID),
            new UploadId(self::FIRST_UPLOAD_ID),
            new AccountId(self::ACCOUNT_ID),
            self::FILE_NAME,
            self::MIME_TYPE,
            $status,
            $this->persistedState($createdAt, $chunkCount, $updatedAt),
        );
    }

    private function reconstituteUploadedAsset(
        ?DateTimeImmutable $createdAt = null,
        ?DateTimeImmutable $updatedAt = null,
        int $chunkCount = self::CHUNK_COUNT,
        string $completionProof = self::COMPLETION_PROOF_VALUE,
    ): Asset {
        $createdAt ??= new DateTimeImmutable(self::CREATED_AT);
        $updatedAt ??= new DateTimeImmutable(self::UPDATED_AT);

        return Asset::reconstituteUploaded(
            new AssetId(self::ASSET_ID),
            new UploadId(self::FIRST_UPLOAD_ID),
            new AccountId(self::ACCOUNT_ID),
            self::FILE_NAME,
            self::MIME_TYPE,
            new UploadCompletionProofValue($completionProof),
            $this->persistedState($createdAt, $chunkCount, $updatedAt),
        );
    }

    private function reconstituteProcessingAsset(
        ?DateTimeImmutable $createdAt = null,
        ?DateTimeImmutable $updatedAt = null,
        int $chunkCount = self::CHUNK_COUNT,
        string $completionProof = self::COMPLETION_PROOF_VALUE,
    ): Asset {
        $createdAt ??= new DateTimeImmutable(self::CREATED_AT);
        $updatedAt ??= new DateTimeImmutable(self::UPDATED_AT);

        return Asset::reconstituteProcessing(
            new AssetId(self::ASSET_ID),
            new UploadId(self::FIRST_UPLOAD_ID),
            new AccountId(self::ACCOUNT_ID),
            self::FILE_NAME,
            self::MIME_TYPE,
            new UploadCompletionProofValue($completionProof),
            $this->persistedState($createdAt, $chunkCount, $updatedAt),
        );
    }

    /**
     * @return array{createdAt: DateTimeImmutable, chunkCount: int, updatedAt: DateTimeImmutable}
     */
    private function persistedState(
        DateTimeImmutable $createdAt,
        int $chunkCount = self::DEFAULT_CHUNK_COUNT,
        ?DateTimeImmutable $updatedAt = null,
    ): array {
        return [
            'createdAt' => $createdAt,
            'chunkCount' => $chunkCount,
            'updatedAt' => $updatedAt ?? $createdAt,
        ];
    }

    private function createCompletionProofValue(string $value = self::COMPLETION_PROOF_VALUE): UploadCompletionProofValue
    {
        return new UploadCompletionProofValue($value);
    }

    private function assertTransitionRejected(
        \Closure $transition,
        string $expectedMessage,
        Asset $asset,
        DateTimeImmutable $expectedUpdatedAt,
    ): void {
        try {
            $transition();
            self::fail('Expected AssetDomainException was not thrown');
        } catch (AssetDomainException $exception) {
            self::assertSame($expectedMessage, $exception->getMessage());
        }

        self::assertSame($expectedUpdatedAt, $asset->getUpdatedAt());
    }
}
