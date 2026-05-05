<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Asset;

use App\Application\Asset\AssetStatusCacheInterface;
use App\Application\Asset\Command\GetAssetQuery;
use App\Application\Asset\GetAssetService;
use App\Application\Asset\Result\AssetReadSource;
use App\Domain\Asset\Asset;
use App\Domain\Asset\AssetRepositoryInterface;
use App\Domain\Asset\AssetStatus;
use App\Domain\Asset\Exception\RepositoryUnavailableException;
use App\Domain\Asset\ValueObject\AccountId;
use App\Domain\Asset\ValueObject\UploadCompletionProofValue;
use App\Domain\Asset\ValueObject\UploadId;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class GetAssetServiceTest extends TestCase
{
    private AssetRepositoryInterface&MockObject $assets;
    private AssetStatusCacheInterface&MockObject $assetTerminalStatusCache;
    private GetAssetService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assets = $this->createMock(AssetRepositoryInterface::class);
        $this->assetTerminalStatusCache = $this->createMock(AssetStatusCacheInterface::class);
        $this->service = new GetAssetService($this->assets, $this->assetTerminalStatusCache);
    }

    #[Test]
    public function itReturnsTheCachedStatusWhenDurableOwnershipSucceedsAndTheCacheMatches(): void
    {
        // Arrange
        $asset = $this->createProcessingAsset();
        $this->assets
            ->expects($this->once())
            ->method('findById')
            ->willReturn($asset);
        $this->assetTerminalStatusCache
            ->expects($this->once())
            ->method('lookup')
            ->with($asset->getId())
            ->willReturn(AssetStatus::PROCESSING);
        $this->assetTerminalStatusCache
            ->expects($this->never())
            ->method('store');

        // Act
        $result = $this->service->getAsset(new GetAssetQuery('account-123', (string) $asset->getId()));

        // Assert
        self::assertNotNull($result);
        self::assertSame((string) $asset->getId(), $result->id);
        self::assertSame(AssetStatus::PROCESSING, $result->status);
        self::assertSame(AssetReadSource::FAST_CACHE, $result->readSource);
    }

    #[Test]
    public function itReturnsTheDurableStatusAndRepairsTheCacheWhenTheCachedStatusDiffers(): void
    {
        // Arrange
        $asset = $this->createProcessingAsset();
        $this->assets
            ->expects($this->once())
            ->method('findById')
            ->willReturn($asset);
        $this->assetTerminalStatusCache
            ->expects($this->once())
            ->method('lookup')
            ->with($asset->getId())
            ->willReturn(AssetStatus::FAILED);
        $this->assetTerminalStatusCache
            ->expects($this->once())
            ->method('store')
            ->with($asset->getId(), AssetStatus::PROCESSING);

        // Act
        $result = $this->service->getAsset(new GetAssetQuery('account-123', (string) $asset->getId()));

        // Assert
        self::assertNotNull($result);
        self::assertSame(AssetStatus::PROCESSING, $result->status);
        self::assertSame(AssetReadSource::DURABLE_STORE, $result->readSource);
    }

    #[Test]
    public function itReturnsTheDurableStatusAndSeedsTheCacheWhenTheLookupMisses(): void
    {
        // Arrange
        $asset = $this->createProcessingAsset();
        $this->assets
            ->expects($this->once())
            ->method('findById')
            ->willReturn($asset);
        $this->assetTerminalStatusCache
            ->expects($this->once())
            ->method('lookup')
            ->with($asset->getId())
            ->willReturn(null);
        $this->assetTerminalStatusCache
            ->expects($this->once())
            ->method('store')
            ->with($asset->getId(), AssetStatus::PROCESSING);

        // Act
        $result = $this->service->getAsset(new GetAssetQuery('account-123', (string) $asset->getId()));

        // Assert
        self::assertNotNull($result);
        self::assertSame(AssetStatus::PROCESSING, $result->status);
        self::assertSame(AssetReadSource::DURABLE_STORE, $result->readSource);
    }

    #[Test]
    public function itFallsBackToTheDurableStatusAndSeedsTheCacheWhenTheLookupThrows(): void
    {
        // Arrange
        $asset = $this->createProcessingAsset();
        $this->assets
            ->expects($this->once())
            ->method('findById')
            ->willReturn($asset);
        $this->assetTerminalStatusCache
            ->expects($this->once())
            ->method('lookup')
            ->with($asset->getId())
            ->willThrowException(new \RuntimeException('redis unavailable'));
        $this->assetTerminalStatusCache
            ->expects($this->once())
            ->method('store')
            ->with($asset->getId(), AssetStatus::PROCESSING);

        // Act
        $result = $this->service->getAsset(new GetAssetQuery('account-123', (string) $asset->getId()));

        // Assert
        self::assertNotNull($result);
        self::assertSame(AssetStatus::PROCESSING, $result->status);
        self::assertSame(AssetReadSource::DURABLE_STORE, $result->readSource);
    }

    #[Test]
    public function itSuppressesCacheSeedingFailuresAfterFallingBackToTheDurableStatus(): void
    {
        // Arrange
        $asset = $this->createProcessingAsset();
        $this->assets
            ->expects($this->once())
            ->method('findById')
            ->willReturn($asset);
        $this->assetTerminalStatusCache
            ->expects($this->once())
            ->method('lookup')
            ->with($asset->getId())
            ->willReturn(null);
        $this->assetTerminalStatusCache
            ->expects($this->once())
            ->method('store')
            ->with($asset->getId(), AssetStatus::PROCESSING)
            ->willThrowException(new \RuntimeException('seed failed'));

        // Act
        $result = $this->service->getAsset(new GetAssetQuery('account-123', (string) $asset->getId()));

        // Assert
        self::assertNotNull($result);
        self::assertSame(AssetStatus::PROCESSING, $result->status);
        self::assertSame(AssetReadSource::DURABLE_STORE, $result->readSource);
    }

    #[Test]
    public function itThrowsRepositoryUnavailableExceptionWhenTheDurableLookupFails(): void
    {
        // Arrange
        $failure = new \RuntimeException('mysql unavailable');
        $this->assets
            ->expects($this->once())
            ->method('findById')
            ->willThrowException($failure);
        $this->assetTerminalStatusCache
            ->expects($this->never())
            ->method('lookup');
        $this->assetTerminalStatusCache
            ->expects($this->never())
            ->method('store');

        // Act
        try {
            $this->service->getAsset(new GetAssetQuery('account-123', '123e4567-e89b-42d3-a456-426614174000'));

            self::fail('Expected getAsset() to throw when the durable lookup fails.');
        } catch (RepositoryUnavailableException $exception) {
            // Assert
            self::assertSame('Repository failure', $exception->getMessage());
            self::assertSame($failure, $exception->getPrevious());
        }
    }

    #[Test]
    public function itReturnsNullWhenTheDurableAssetDoesNotExist(): void
    {
        // Arrange
        $this->assets
            ->expects($this->once())
            ->method('findById')
            ->willReturn(null);
        $this->assetTerminalStatusCache
            ->expects($this->never())
            ->method('lookup');
        $this->assetTerminalStatusCache
            ->expects($this->never())
            ->method('store');

        // Act
        $result = $this->service->getAsset(new GetAssetQuery('account-123', '123e4567-e89b-42d3-a456-426614174000'));

        // Assert
        self::assertNull($result);
    }

    #[Test]
    public function itReturnsNullWhenTheDurableAssetBelongsToAnotherAccount(): void
    {
        // Arrange
        $asset = $this->createProcessingAsset('another-account');
        $this->assets
            ->expects($this->once())
            ->method('findById')
            ->willReturn($asset);
        $this->assetTerminalStatusCache
            ->expects($this->never())
            ->method('lookup');
        $this->assetTerminalStatusCache
            ->expects($this->never())
            ->method('store');

        // Act
        $result = $this->service->getAsset(new GetAssetQuery('account-123', (string) $asset->getId()));

        // Assert
        self::assertNull($result);
    }

    private function createPendingAsset(string $accountId = 'account-123'): Asset
    {
        return Asset::createPending(
            UploadId::generate(),
            new AccountId($accountId),
            'report.pdf',
            'application/pdf',
        );
    }

    private function createProcessingAsset(string $accountId = 'account-123'): Asset
    {
        $asset = $this->createPendingAsset($accountId);
        $asset->markProcessing(new UploadCompletionProofValue('etag-processing'));

        return $asset;
    }
}
