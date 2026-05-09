<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\Processing;

use App\Application\Asset\AssetProcessingMetricCounter;
use App\Application\Asset\AssetProcessingMetricsInterface;
use App\Application\Asset\AssetProcessorInterface;
use App\Application\Asset\AssetStatusCacheInterface;
use App\Application\Asset\Exception\RetryableAssetProcessingException;
use App\Application\Asset\Exception\TerminalAssetProcessingException;
use App\Application\Asset\HandleAssetProcessingJobService;
use App\Application\Asset\HandleAssetProcessingRetryExhaustionService;
use App\Domain\Asset\Asset;
use App\Domain\Asset\AssetRepositoryInterface;
use App\Domain\Asset\AssetStatus;
use App\Domain\Asset\ValueObject\AccountId;
use App\Domain\Asset\ValueObject\AssetId;
use App\Domain\Asset\ValueObject\UploadCompletionProofValue;
use App\Domain\Asset\ValueObject\UploadId;
use App\Infrastructure\Processing\AssetProcessingJobDelivery;
use App\Infrastructure\Processing\AssetProcessingJobHandlingOutcome;
use App\Infrastructure\Processing\AssetProcessingJobPayload;
use App\Infrastructure\Processing\AssetProcessingJobWorker;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class AssetProcessingJobWorkerTest extends TestCase
{
    private AssetRepositoryInterface&MockObject $assets;
    private AssetProcessorInterface&MockObject $assetProcessor;
    private AssetProcessingMetricsInterface&MockObject $metrics;
    private AssetStatusCacheInterface&MockObject $assetTerminalStatusCache;
    private LoggerInterface&MockObject $logger;
    private HandleAssetProcessingJobService $service;
    private AssetProcessingJobWorker $worker;
    /** @var list<AssetProcessingMetricCounter> */
    private array $recordedCounters;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assets = $this->createMock(AssetRepositoryInterface::class);
        $this->assetProcessor = $this->createMock(AssetProcessorInterface::class);
        $this->metrics = $this->createMock(AssetProcessingMetricsInterface::class);
        $this->assetTerminalStatusCache = $this->createMock(AssetStatusCacheInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->recordedCounters = [];
        $this->metrics
            ->method('incrementCounter')
            ->willReturnCallback(function (AssetProcessingMetricCounter $counter): void {
                $this->recordedCounters[] = $counter;
            });
        $this->service = new HandleAssetProcessingJobService(
            $this->assets,
            $this->assetProcessor,
            $this->assetTerminalStatusCache,
        );
        $this->worker = new AssetProcessingJobWorker(
            $this->service,
            new HandleAssetProcessingRetryExhaustionService($this->assets, $this->assetTerminalStatusCache),
            $this->logger,
            $this->metrics,
        );
    }

    #[Test]
    public function itLogsWarningsWhenThePayloadIsMalformed(): void
    {
        // Arrange
        $payload = '{"assetId":';
        $this->assets
            ->expects($this->never())
            ->method('findById');
        $this->assets
            ->expects($this->never())
            ->method('save');
        $this->assetProcessor
            ->expects($this->never())
            ->method('process');
        $this->assetTerminalStatusCache
            ->expects($this->never())
            ->method('store');
        $this->logger
            ->expects($this->once())
            ->method('warning')
            ->with('Discarded malformed asset processing job payload.', [
                'payloadLength' => strlen($payload),
                'payloadSha256' => hash('sha256', $payload),
            ]);
        $this->metrics
            ->expects($this->once())
            ->method('incrementCounter')
            ->with(AssetProcessingMetricCounter::DISCARDED_TOTAL);

        // Act
        $result = $this->worker->consume($payload);

        // Assert
        self::assertSame(AssetProcessingJobHandlingOutcome::DISCARDED_MALFORMED_PAYLOAD, $result->outcome);
        self::assertSame([AssetProcessingMetricCounter::DISCARDED_TOTAL], $this->recordedCounters);
    }

    #[Test]
    public function itSanitizesInvalidAssetIdPayloadLogging(): void
    {
        // Arrange
        $payload = self::payload('not-a-uuid');
        $this->assets
            ->expects($this->never())
            ->method('findById');
        $this->assets
            ->expects($this->never())
            ->method('save');
        $this->assetProcessor
            ->expects($this->never())
            ->method('process');
        $this->assetTerminalStatusCache
            ->expects($this->never())
            ->method('store');
        $this->logger
            ->expects($this->once())
            ->method('warning')
            ->with('Discarded asset processing job with invalid asset id.', [
                'payloadLength' => strlen($payload),
                'payloadSha256' => hash('sha256', $payload),
            ]);
        $this->metrics
            ->expects($this->once())
            ->method('incrementCounter')
            ->with(AssetProcessingMetricCounter::DISCARDED_TOTAL);

        // Act
        $result = $this->worker->consume($payload);

        // Assert
        self::assertSame(AssetProcessingJobHandlingOutcome::DISCARDED_INVALID_ASSET_ID, $result->outcome);
        self::assertSame([AssetProcessingMetricCounter::DISCARDED_TOTAL], $this->recordedCounters);
    }

    #[Test]
    public function itLogsErrorsWhenTheAssetDoesNotExist(): void
    {
        // Arrange
        $assetId = '123e4567-e89b-42d3-a456-426614174000';
        $payload = self::payload($assetId);
        $this->assets
            ->expects($this->once())
            ->method('findById')
            ->with(self::callback(static fn (AssetId $candidate): bool => (string) $candidate === $assetId))
            ->willReturn(null);
        $this->assets
            ->expects($this->never())
            ->method('save');
        $this->assetProcessor
            ->expects($this->never())
            ->method('process');
        $this->assetTerminalStatusCache
            ->expects($this->never())
            ->method('store');
        $this->logger
            ->expects($this->once())
            ->method('error')
            ->with('Discarded asset processing job for unknown asset.', ['assetId' => $assetId]);
        $this->logger
            ->expects($this->never())
            ->method('warning');
        $this->logger
            ->expects($this->never())
            ->method('info');
        $this->metrics
            ->expects($this->once())
            ->method('incrementCounter')
            ->with(AssetProcessingMetricCounter::DISCARDED_TOTAL);

        // Act
        $result = $this->worker->consume($payload);

        // Assert
        self::assertSame(AssetProcessingJobHandlingOutcome::DISCARDED_UNKNOWN_ASSET, $result->outcome);
        self::assertSame(AssetProcessingJobDelivery::DISCARD, $result->delivery);
        self::assertSame($assetId, $result->assetId);
        self::assertSame([AssetProcessingMetricCounter::DISCARDED_TOTAL], $this->recordedCounters);
    }

    #[Test]
    public function itLogsInfoWhenTheAssetIsNotInProcessingState(): void
    {
        // Arrange
        $asset = $this->createFailedAsset();
        $payload = self::payload((string) $asset->getId());
        $this->assets
            ->expects($this->once())
            ->method('findById')
            ->willReturn($asset);
        $this->assets
            ->expects($this->never())
            ->method('save');
        $this->assetProcessor
            ->expects($this->never())
            ->method('process');
        $this->assetTerminalStatusCache
            ->expects($this->never())
            ->method('store');
        $this->logger
            ->expects($this->once())
            ->method('info')
            ->with('Skipped asset processing job because asset is not in PROCESSING state.', [
                'assetId' => (string) $asset->getId(),
                'status' => 'FAILED',
            ]);
        $this->metrics
            ->expects($this->once())
            ->method('incrementCounter')
            ->with(AssetProcessingMetricCounter::DISCARDED_TOTAL);

        // Act
        $result = $this->worker->consume($payload);

        // Assert
        self::assertSame(AssetProcessingJobHandlingOutcome::SKIPPED_ASSET_NOT_PROCESSING, $result->outcome);
        self::assertSame([AssetProcessingMetricCounter::DISCARDED_TOTAL], $this->recordedCounters);
    }

    #[Test]
    public function itMarksProcessingAssetsAsUploadedWithoutLoggingWhenProcessingAndCachingSucceed(): void
    {
        // Arrange
        $asset = $this->createProcessingAsset();
        $payload = self::payload((string) $asset->getId(), 8);
        $this->assets
            ->expects($this->once())
            ->method('findById')
            ->willReturn($asset);
        $this->assetProcessor
            ->expects($this->once())
            ->method('process')
            ->with($asset);
        $this->assets
            ->expects($this->once())
            ->method('save')
            ->with(self::callback(static fn (Asset $savedAsset): bool => $savedAsset->getStatus() === AssetStatus::UPLOADED));
        $this->assetTerminalStatusCache
            ->expects($this->once())
            ->method('store')
            ->with(
                self::callback(static fn (AssetId $assetId): bool => (string) $assetId === (string) $asset->getId()),
                AssetStatus::UPLOADED,
            );
        $this->logger
            ->expects($this->never())
            ->method('info');
        $this->logger
            ->expects($this->never())
            ->method('warning');
        $this->metrics
            ->expects($this->exactly(2))
            ->method('incrementCounter');

        // Act
        $result = $this->worker->consume($payload);

        // Assert
        self::assertSame(AssetProcessingJobHandlingOutcome::PROCESSED_ASSET_UPLOADED, $result->outcome);
        self::assertSame(AssetProcessingJobDelivery::HANDLED, $result->delivery);
        self::assertTrue($result->terminalStatusCached);
        self::assertSame([
            AssetProcessingMetricCounter::PROCESSED_TOTAL,
            AssetProcessingMetricCounter::PROCESSED_SUCCESS_TOTAL,
        ], $this->recordedCounters);
    }

    #[Test]
    public function itLogsWarningsWhenTerminalProcessingFailsAndTheAssetIsMarkedFailed(): void
    {
        // Arrange
        $asset = $this->createProcessingAsset();
        $payload = self::payload((string) $asset->getId(), 1);
        $this->assets
            ->expects($this->once())
            ->method('findById')
            ->willReturn($asset);
        $this->assetProcessor
            ->expects($this->once())
            ->method('process')
            ->with($asset)
            ->willThrowException(new TerminalAssetProcessingException('processor crashed'));
        $this->assets
            ->expects($this->once())
            ->method('save')
            ->with(self::callback(static fn (Asset $savedAsset): bool => $savedAsset->getStatus() === AssetStatus::FAILED && $savedAsset->getCompletionProof() === null));
        $this->assetTerminalStatusCache
            ->expects($this->once())
            ->method('store')
            ->with(
                self::callback(static fn (AssetId $assetId): bool => (string) $assetId === (string) $asset->getId()),
                AssetStatus::FAILED,
            );
        $this->logger
            ->expects($this->once())
            ->method('warning')
            ->with('Asset processing failed and the asset was marked as FAILED.', [
                'assetId' => (string) $asset->getId(),
                'status' => 'FAILED',
                'reason' => 'processor crashed',
                'terminalStatusCached' => true,
            ]);
        $this->logger
            ->expects($this->never())
            ->method('info');
        $this->metrics
            ->expects($this->exactly(2))
            ->method('incrementCounter');

        // Act
        $result = $this->worker->consume($payload);

        // Assert
        self::assertSame(AssetProcessingJobHandlingOutcome::PROCESSED_ASSET_FAILED, $result->outcome);
        self::assertSame(AssetProcessingJobDelivery::HANDLED, $result->delivery);
        self::assertTrue($result->terminalStatusCached);
        self::assertSame([
            AssetProcessingMetricCounter::PROCESSED_TOTAL,
            AssetProcessingMetricCounter::PROCESSED_FAILURE_TOTAL,
        ], $this->recordedCounters);
    }

    #[Test]
    public function itIncrementsRetryCountAndReturnsRetryDeliveryWhenProcessingFailsBelowTheRetryLimit(): void
    {
        // Arrange
        $asset = $this->createProcessingAsset();
        $payload = self::payload((string) $asset->getId(), 1);
        $this->assets
            ->expects($this->once())
            ->method('findById')
            ->willReturn($asset);
        $this->assetProcessor
            ->expects($this->once())
            ->method('process')
            ->with($asset)
            ->willThrowException(new RetryableAssetProcessingException('temporary processor outage'));
        $this->assets
            ->expects($this->never())
            ->method('save');
        $this->assetTerminalStatusCache
            ->expects($this->never())
            ->method('store');
        $this->logger
            ->expects($this->once())
            ->method('warning')
            ->with('Released asset processing job after retryable processing failure.', [
                'assetId' => (string) $asset->getId(),
                'reason' => 'temporary processor outage',
            ]);
        $this->logger
            ->expects($this->never())
            ->method('error');
        $this->logger
            ->expects($this->never())
            ->method('info');
        $this->metrics
            ->expects($this->once())
            ->method('incrementCounter')
            ->with(AssetProcessingMetricCounter::RETRY_TOTAL);

        // Act
        $result = $this->worker->consume($payload);

        // Assert
        self::assertSame(AssetProcessingJobHandlingOutcome::RETRYABLE_PROCESSING_FAILURE, $result->outcome);
        self::assertSame(AssetProcessingJobDelivery::RETRY, $result->delivery);
        self::assertSame((string) $asset->getId(), $result->assetId);
        self::assertSame('temporary processor outage', $result->processingErrorMessage);
        self::assertSame(self::payload((string) $asset->getId(), 2), $result->queuedPayload());
        self::assertSame([AssetProcessingMetricCounter::RETRY_TOTAL], $this->recordedCounters);
    }

    #[Test]
    public function itDeadLettersJobsAndMarksTheAssetFailedWhenTheRetryLimitIsExceeded(): void
    {
        // Arrange
        $asset = $this->createProcessingAsset();
        $payload = self::payload((string) $asset->getId(), 2);
        $this->assets
            ->expects($this->exactly(2))
            ->method('findById')
            ->willReturnOnConsecutiveCalls($asset, $asset);
        $this->assetProcessor
            ->expects($this->once())
            ->method('process')
            ->with($asset)
            ->willThrowException(new RetryableAssetProcessingException('temporary processor outage'));
        $this->assets
            ->expects($this->once())
            ->method('save')
            ->with(self::callback(static fn (Asset $savedAsset): bool => $savedAsset->getStatus() === AssetStatus::FAILED && $savedAsset->getCompletionProof() === null));
        $this->assetTerminalStatusCache
            ->expects($this->once())
            ->method('store')
            ->with(
                self::callback(static fn (AssetId $assetId): bool => (string) $assetId === (string) $asset->getId()),
                AssetStatus::FAILED,
            );
        $this->logger
            ->expects($this->once())
            ->method('warning')
            ->with('Moved asset processing job to failed queue after retry budget exhausted.', [
                'assetId' => (string) $asset->getId(),
                'reason' => 'temporary processor outage',
                'status' => 'FAILED',
                'terminalStatusCached' => true,
            ]);
        $this->logger
            ->expects($this->never())
            ->method('error');
        $this->logger
            ->expects($this->never())
            ->method('info');
        $this->metrics
            ->expects($this->exactly(2))
            ->method('incrementCounter');

        // Act
        $result = $this->worker->consume($payload);

        // Assert
        self::assertSame(AssetProcessingJobHandlingOutcome::DEAD_LETTERED, $result->outcome);
        self::assertSame(AssetProcessingJobDelivery::DEAD_LETTER, $result->delivery);
        self::assertSame((string) $asset->getId(), $result->assetId);
        self::assertSame(AssetStatus::FAILED, $result->assetStatus);
        self::assertTrue($result->terminalStatusCached);
        self::assertSame('temporary processor outage', $result->processingErrorMessage);
        self::assertSame(self::payload((string) $asset->getId(), 3), $result->queuedPayload());
        self::assertSame([
            AssetProcessingMetricCounter::PROCESSED_TOTAL,
            AssetProcessingMetricCounter::PROCESSED_FAILURE_TOTAL,
        ], $this->recordedCounters);
    }

    #[Test]
    public function itDiscardsExhaustedRetriesWhenTheAssetCannotBeFoundDuringExhaustionHandling(): void
    {
        // Arrange
        $asset = $this->createProcessingAsset();
        $payload = self::payload((string) $asset->getId(), 2);
        $this->assets
            ->expects($this->exactly(2))
            ->method('findById')
            ->willReturnOnConsecutiveCalls($asset, null);
        $this->assetProcessor
            ->expects($this->once())
            ->method('process')
            ->with($asset)
            ->willThrowException(new RetryableAssetProcessingException('temporary processor outage'));
        $this->assets
            ->expects($this->never())
            ->method('save');
        $this->assetTerminalStatusCache
            ->expects($this->never())
            ->method('store');
        $this->logger
            ->expects($this->once())
            ->method('error')
            ->with('Discarded asset processing job for unknown asset.', ['assetId' => (string) $asset->getId()]);
        $this->logger
            ->expects($this->never())
            ->method('warning');
        $this->logger
            ->expects($this->never())
            ->method('info');
        $this->metrics
            ->expects($this->once())
            ->method('incrementCounter')
            ->with(AssetProcessingMetricCounter::DISCARDED_TOTAL);

        // Act
        $result = $this->worker->consume($payload);

        // Assert
        self::assertSame(AssetProcessingJobHandlingOutcome::DISCARDED_UNKNOWN_ASSET, $result->outcome);
        self::assertSame(AssetProcessingJobDelivery::DISCARD, $result->delivery);
        self::assertSame((string) $asset->getId(), $result->assetId);
        self::assertNull($result->queuedPayload());
        self::assertSame([AssetProcessingMetricCounter::DISCARDED_TOTAL], $this->recordedCounters);
    }

    #[Test]
    public function itDiscardsExhaustedRetriesWhenTheAssetIsAlreadyNoLongerProcessing(): void
    {
        // Arrange
        $processingAsset = $this->createProcessingAsset();
        $failedAsset = $this->createFailedAsset();
        $payload = self::payload((string) $processingAsset->getId(), 2);
        $this->assets
            ->expects($this->exactly(2))
            ->method('findById')
            ->willReturnOnConsecutiveCalls($processingAsset, $failedAsset);
        $this->assetProcessor
            ->expects($this->once())
            ->method('process')
            ->with($processingAsset)
            ->willThrowException(new RetryableAssetProcessingException('temporary processor outage'));
        $this->assets
            ->expects($this->never())
            ->method('save');
        $this->assetTerminalStatusCache
            ->expects($this->never())
            ->method('store');
        $this->logger
            ->expects($this->once())
            ->method('info')
            ->with('Skipped asset processing job because asset is not in PROCESSING state.', [
                'assetId' => (string) $processingAsset->getId(),
                'status' => 'FAILED',
            ]);
        $this->logger
            ->expects($this->never())
            ->method('warning');
        $this->logger
            ->expects($this->never())
            ->method('error');
        $this->metrics
            ->expects($this->once())
            ->method('incrementCounter')
            ->with(AssetProcessingMetricCounter::DISCARDED_TOTAL);

        // Act
        $result = $this->worker->consume($payload);

        // Assert
        self::assertSame(AssetProcessingJobHandlingOutcome::SKIPPED_ASSET_NOT_PROCESSING, $result->outcome);
        self::assertSame(AssetProcessingJobDelivery::DISCARD, $result->delivery);
        self::assertSame((string) $processingAsset->getId(), $result->assetId);
        self::assertSame(AssetStatus::FAILED, $result->assetStatus);
        self::assertNull($result->queuedPayload());
        self::assertSame([AssetProcessingMetricCounter::DISCARDED_TOTAL], $this->recordedCounters);
    }

    #[Test]
    public function itLogsWarningsWhenTerminalStatusCachingFailsAfterPersistingAnUploadedAsset(): void
    {
        // Arrange
        $asset = $this->createProcessingAsset();
        $payload = self::payload((string) $asset->getId(), 3);
        $this->assets
            ->expects($this->once())
            ->method('findById')
            ->willReturn($asset);
        $this->assetProcessor
            ->expects($this->once())
            ->method('process')
            ->with($asset);
        $this->assets
            ->expects($this->once())
            ->method('save')
            ->with(self::callback(static fn (Asset $savedAsset): bool => $savedAsset->getStatus() === AssetStatus::UPLOADED));
        $this->assetTerminalStatusCache
            ->expects($this->once())
            ->method('store')
            ->willThrowException(new \RuntimeException('redis unavailable'));
        $this->logger
            ->expects($this->once())
            ->method('warning')
            ->with('Persisted asset terminal state but failed to cache terminal status.', [
                'assetId' => (string) $asset->getId(),
                'status' => 'UPLOADED',
                'terminalStatusCacheError' => 'RuntimeException: redis unavailable',
            ]);
        $this->logger
            ->expects($this->never())
            ->method('info');
        $this->metrics
            ->expects($this->exactly(2))
            ->method('incrementCounter');

        // Act
        $result = $this->worker->consume($payload);

        // Assert
        self::assertSame(AssetProcessingJobHandlingOutcome::PROCESSED_ASSET_UPLOADED, $result->outcome);
        self::assertSame(AssetProcessingJobDelivery::HANDLED, $result->delivery);
        self::assertFalse($result->terminalStatusCached);
        self::assertSame([
            AssetProcessingMetricCounter::PROCESSED_TOTAL,
            AssetProcessingMetricCounter::PROCESSED_SUCCESS_TOTAL,
        ], $this->recordedCounters);
    }

    private static function payload(string $assetId, int $retryCount = 0): string
    {
        return (new AssetProcessingJobPayload($assetId, $retryCount))->toJson();
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

    private function createFailedAsset(): Asset
    {
        $asset = $this->createPendingAsset();
        $asset->markFailed();

        return $asset;
    }
}
