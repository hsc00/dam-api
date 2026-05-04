<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Asset;

use App\Application\Asset\AssetStatusCacheInterface;
use App\Application\Asset\Command\HandleAssetProcessingRetryExhaustionCommand;
use App\Application\Asset\HandleAssetProcessingRetryExhaustionService;
use App\Application\Asset\Result\AssetProcessingRetryExhaustionOutcome;
use App\Domain\Asset\Asset;
use App\Domain\Asset\AssetRepositoryInterface;
use App\Domain\Asset\AssetStatus;
use App\Domain\Asset\Exception\RepositoryUnavailableException;
use App\Domain\Asset\Exception\StaleAssetWriteException;
use App\Domain\Asset\ValueObject\AccountId;
use App\Domain\Asset\ValueObject\AssetId;
use App\Domain\Asset\ValueObject\UploadCompletionProofValue;
use App\Domain\Asset\ValueObject\UploadId;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class HandleAssetProcessingRetryExhaustionServiceTest extends TestCase
{
    private const UNKNOWN_ASSET_ID = '123e4567-e89b-42d3-a456-426614174000';

    private AssetRepositoryInterface&MockObject $assets;
    private AssetStatusCacheInterface&MockObject $assetTerminalStatusCache;
    private HandleAssetProcessingRetryExhaustionService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assets = $this->createMock(AssetRepositoryInterface::class);
        $this->assetTerminalStatusCache = $this->createMock(AssetStatusCacheInterface::class);
        $this->service = new HandleAssetProcessingRetryExhaustionService(
            $this->assets,
            $this->assetTerminalStatusCache,
        );
    }

    #[Test]
    public function itDoesNotProcessUnknownAssetsWhenAssetIsUnknown(): void
    {
        // Arrange
        $this->assets
            ->expects($this->once())
            ->method('findById');
        $this->assets
            ->expects($this->never())
            ->method('save');
        $this->assetTerminalStatusCache
            ->expects($this->never())
            ->method('store');

        // Act
        $result = $this->service->handle(new HandleAssetProcessingRetryExhaustionCommand(new AssetId(self::UNKNOWN_ASSET_ID)));

        // Assert
        self::assertSame(AssetProcessingRetryExhaustionOutcome::DISCARDED_UNKNOWN_ASSET, $result->outcome);
        self::assertSame(self::UNKNOWN_ASSET_ID, $result->assetId);
        self::assertNull($result->assetStatus);
        self::assertNull($result->terminalStatusCached);
        self::assertNull($result->terminalStatusCacheError);
    }

    #[Test]
    #[DataProvider('nonProcessingStatusProvider')]
    public function itDoesNotProcessAssetsWhenAssetIsNotInProcessingState(AssetStatus $status): void
    {
        // Arrange
        $asset = $this->createAssetWithStatus($status);
        $this->assets
            ->expects($this->once())
            ->method('findById')
            ->with(self::callback(static fn (AssetId $assetId): bool => (string) $assetId === (string) $asset->getId()))
            ->willReturn($asset);
        $this->assets
            ->expects($this->never())
            ->method('save');
        $this->assetTerminalStatusCache
            ->expects($this->never())
            ->method('store');

        // Act
        $result = $this->service->handle(new HandleAssetProcessingRetryExhaustionCommand($asset->getId()));

        // Assert
        self::assertSame(AssetProcessingRetryExhaustionOutcome::SKIPPED_ASSET_NOT_PROCESSING, $result->outcome);
        self::assertSame((string) $asset->getId(), $result->assetId);
        self::assertSame($status, $result->assetStatus);
        self::assertNull($result->terminalStatusCached);
        self::assertNull($result->terminalStatusCacheError);
    }

    #[Test]
    public function itReturnsMarkedAsFailedWhenRetryBudgetIsExhausted(): void
    {
        // Arrange
        $asset = $this->createAssetWithStatus(AssetStatus::PROCESSING);
        $saveCalled = false;
        $this->assets
            ->expects($this->once())
            ->method('findById')
            ->with(self::callback(static fn (AssetId $assetId): bool => (string) $assetId === (string) $asset->getId()))
            ->willReturn($asset);
        $this->assets
            ->expects($this->once())
            ->method('save')
            ->willReturnCallback(function (Asset $savedAsset) use ($asset, &$saveCalled): void {
                self::assertSame($asset, $savedAsset);
                self::assertSame(AssetStatus::FAILED, $savedAsset->getStatus());
                self::assertNull($savedAsset->getCompletionProof());
                $saveCalled = true;
            });
        $this->assetTerminalStatusCache
            ->expects($this->once())
            ->method('store')
            ->willReturnCallback(function (AssetId $assetId, AssetStatus $status) use ($asset, &$saveCalled): void {
                self::assertTrue($saveCalled);
                self::assertSame((string) $asset->getId(), (string) $assetId);
                self::assertSame(AssetStatus::FAILED, $status);
            });

        // Act
        $result = $this->service->handle(new HandleAssetProcessingRetryExhaustionCommand($asset->getId()));

        // Assert
        self::assertSame(AssetProcessingRetryExhaustionOutcome::MARKED_ASSET_FAILED, $result->outcome);
        self::assertSame((string) $asset->getId(), $result->assetId);
        self::assertSame(AssetStatus::FAILED, $result->assetStatus);
        self::assertTrue($saveCalled);
        self::assertTrue($result->terminalStatusCached);
        self::assertNull($result->terminalStatusCacheError);
    }

    #[Test]
    public function itReturnsTheCurrentTerminalStateWhenSavingAStaleAssetAndAnotherWorkerAlreadyMarkedItFailed(): void
    {
        // Arrange
        $asset = $this->createAssetWithStatus(AssetStatus::PROCESSING);
        $failedAsset = $this->reconstituteFailedCopy($asset);
        $this->assets
            ->expects($this->exactly(2))
            ->method('findById')
            ->willReturnOnConsecutiveCalls($asset, $failedAsset);
        $this->assets
            ->expects($this->once())
            ->method('save')
            ->willThrowException(new StaleAssetWriteException('Cannot save stale asset state.'));
        $this->assetTerminalStatusCache
            ->expects($this->never())
            ->method('store');

        // Act
        $result = $this->service->handle(new HandleAssetProcessingRetryExhaustionCommand($asset->getId()));

        // Assert
        self::assertSame(AssetProcessingRetryExhaustionOutcome::SKIPPED_ASSET_NOT_PROCESSING, $result->outcome);
        self::assertSame((string) $asset->getId(), $result->assetId);
        self::assertSame(AssetStatus::FAILED, $result->assetStatus);
        self::assertNull($result->terminalStatusCached);
        self::assertNull($result->terminalStatusCacheError);
    }

    #[Test]
    public function itThrowsRepositoryUnavailableWhenFindingTheAssetFails(): void
    {
        // Arrange
        $this->assets
            ->expects($this->once())
            ->method('findById')
            ->willThrowException(new \RuntimeException('database unavailable'));
        $this->assets
            ->expects($this->never())
            ->method('save');
        $this->assetTerminalStatusCache
            ->expects($this->never())
            ->method('store');

        // Act & Assert
        $this->expectException(RepositoryUnavailableException::class);
        $this->expectExceptionMessage('Repository failure');
        $this->service->handle(new HandleAssetProcessingRetryExhaustionCommand(new AssetId(self::UNKNOWN_ASSET_ID)));
    }

    #[Test]
    public function itThrowsRepositoryUnavailableWhenSavingTheFailedAssetFails(): void
    {
        // Arrange
        $asset = $this->createAssetWithStatus(AssetStatus::PROCESSING);
        $this->assets
            ->expects($this->once())
            ->method('findById')
            ->willReturn($asset);
        $this->assets
            ->expects($this->once())
            ->method('save')
            ->willThrowException(new \RuntimeException('database unavailable'));
        $this->assetTerminalStatusCache
            ->expects($this->never())
            ->method('store');

        // Act & Assert
        $this->expectException(RepositoryUnavailableException::class);
        $this->expectExceptionMessage('Repository failure');
        $this->service->handle(new HandleAssetProcessingRetryExhaustionCommand($asset->getId()));
    }

    #[Test]
    public function itDoesNotRollBackTheFailedStateWhenTerminalStatusCachingFails(): void
    {
        // Arrange
        $asset = $this->createAssetWithStatus(AssetStatus::PROCESSING);
        $this->assets
            ->expects($this->once())
            ->method('findById')
            ->willReturn($asset);
        $this->assets
            ->expects($this->once())
            ->method('save')
            ->with(self::callback(static fn (Asset $savedAsset): bool => $savedAsset->getStatus() === AssetStatus::FAILED && $savedAsset->getCompletionProof() === null));
        $this->assetTerminalStatusCache
            ->expects($this->once())
            ->method('store')
            ->willThrowException(new \RuntimeException('redis unavailable'));

        // Act
        $result = $this->service->handle(new HandleAssetProcessingRetryExhaustionCommand($asset->getId()));

        // Assert
        self::assertSame(AssetProcessingRetryExhaustionOutcome::MARKED_ASSET_FAILED, $result->outcome);
        self::assertSame(AssetStatus::FAILED, $result->assetStatus);
        self::assertFalse($result->terminalStatusCached);
        self::assertSame('RuntimeException: redis unavailable', $result->terminalStatusCacheError);
    }

    /**
     * @return array<string, array{0: AssetStatus}>
     */
    public static function nonProcessingStatusProvider(): array
    {
        return [
            'pending' => [AssetStatus::PENDING],
            'uploaded' => [AssetStatus::UPLOADED],
            'failed' => [AssetStatus::FAILED],
        ];
    }

    private function createAssetWithStatus(AssetStatus $status): Asset
    {
        return match ($status) {
            AssetStatus::PENDING => $this->createPendingAsset(),
            AssetStatus::PROCESSING => $this->createProcessingAsset(),
            AssetStatus::UPLOADED => $this->createUploadedAsset(),
            AssetStatus::FAILED => $this->createFailedAsset(),
        };
    }

    private function createPendingAsset(): Asset
    {
        return Asset::createPending(
            UploadId::generate(),
            new AccountId('account-123'),
            'report.pdf',
            'application/pdf',
        );
    }

    private function createProcessingAsset(): Asset
    {
        $asset = $this->createPendingAsset();
        $asset->markProcessing(new UploadCompletionProofValue('etag-processing'));

        return $asset;
    }

    private function createUploadedAsset(): Asset
    {
        $asset = $this->createPendingAsset();
        $asset->markUploaded(new UploadCompletionProofValue('etag-uploaded'));

        return $asset;
    }

    private function createFailedAsset(): Asset
    {
        $asset = $this->createPendingAsset();
        $asset->markFailed();

        return $asset;
    }

    private function reconstituteFailedCopy(Asset $asset): Asset
    {
        return Asset::reconstitute(
            new AssetId((string) $asset->getId()),
            new UploadId((string) $asset->getUploadId()),
            new AccountId((string) $asset->getAccountId()),
            $asset->getFileName(),
            $asset->getMimeType(),
            AssetStatus::FAILED,
            [
                'createdAt' => $asset->getCreatedAt(),
                'chunkCount' => $asset->getChunkCount(),
                'updatedAt' => $asset->getUpdatedAt(),
            ],
        );
    }
}
