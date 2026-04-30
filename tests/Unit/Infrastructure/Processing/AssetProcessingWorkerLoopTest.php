<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\Processing;

use App\Domain\Asset\Exception\RepositoryUnavailableException;
use App\Infrastructure\Processing\AssetProcessingJobConsumerInterface;
use App\Infrastructure\Processing\AssetProcessingJobHandlerInterface;
use App\Infrastructure\Processing\AssetProcessingJobHandlingResult;
use App\Infrastructure\Processing\AssetProcessingWorkerLoop;
use App\Infrastructure\Processing\Exception\RedisJobQueueConsumerException;
use App\Infrastructure\Processing\ReservedAssetProcessingJob;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class AssetProcessingWorkerLoopTest extends TestCase
{
    private const ASSET_ID = '123e4567-e89b-42d3-a456-426614174000';
    private const PAYLOAD = '{"assetId":"123e4567-e89b-42d3-a456-426614174000","retryCount":0}';

    private AssetProcessingJobConsumerInterface&MockObject $consumer;
    private AssetProcessingJobHandlerInterface&MockObject $handler;
    private LoggerInterface&MockObject $logger;
    /** @var list<int> */
    private array $sleepCalls;
    private AssetProcessingWorkerLoop $loop;

    protected function setUp(): void
    {
        parent::setUp();

        $this->consumer = $this->createMock(AssetProcessingJobConsumerInterface::class);
        $this->handler = $this->createMock(AssetProcessingJobHandlerInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->sleepCalls = [];
        $this->loop = new AssetProcessingWorkerLoop(
            $this->consumer,
            $this->handler,
            $this->logger,
            function (int $microseconds): void {
                $this->sleepCalls[] = $microseconds;
            },
            3,
            1_000,
            4_000,
        );
    }

    #[Test]
    public function itEmitsReleasedJobWhenDurablePersistenceFailsAfterReservation(): void
    {
        // Arrange
        $acknowledgeCalls = 0;
        $releaseCalls = 0;
        $errors = [];
        $reservation = new ReservedAssetProcessingJob(
            self::PAYLOAD,
            static function () use (&$acknowledgeCalls): void {
                $acknowledgeCalls++;
            },
            static function () use (&$releaseCalls): void {
                $releaseCalls++;
            },
        );
        $this->consumer
            ->expects($this->once())
            ->method('reserveNext')
            ->willReturn($reservation);
        $this->handler
            ->expects($this->once())
            ->method('consume')
            ->with(self::PAYLOAD)
            ->willThrowException(RepositoryUnavailableException::forReason('Repository failure'));
        $this->logger
            ->expects($this->once())
            ->method('error')
            ->willReturnCallback(static function (string $message, array $context = []) use (&$errors): void {
                $errors[] = [$message, $context];
            });
        $this->logger
            ->expects($this->never())
            ->method('critical');

        // Act
        $this->loop->runOnce();

        // Assert
        self::assertSame(0, $acknowledgeCalls);
        self::assertSame(1, $releaseCalls);
        self::assertSame([1_000], $this->sleepCalls);
        self::assertCount(1, $errors);
        self::assertSame('Asset processing worker iteration failed.', $errors[0][0]);
        self::assertSame(1, $errors[0][1]['consecutiveFailures']);
        self::assertSame(1_000, $errors[0][1]['backoffMicroseconds']);
        self::assertInstanceOf(RepositoryUnavailableException::class, $errors[0][1]['exception']);
    }

    #[Test]
    public function itEmitsReleasedJobWhenTheHandlerReturnsRetryDelivery(): void
    {
        // Arrange
        $acknowledgeCalls = 0;
        $releaseCalls = 0;
        $reservation = new ReservedAssetProcessingJob(
            self::PAYLOAD,
            static function () use (&$acknowledgeCalls): void {
                $acknowledgeCalls++;
            },
            static function () use (&$releaseCalls): void {
                $releaseCalls++;
            },
        );
        $this->consumer
            ->expects($this->once())
            ->method('reserveNext')
            ->willReturn($reservation);
        $this->handler
            ->expects($this->once())
            ->method('consume')
            ->with(self::PAYLOAD)
            ->willReturn(AssetProcessingJobHandlingResult::retryableProcessingFailure(self::ASSET_ID, 'temporary processor outage'));
        $this->logger
            ->expects($this->never())
            ->method('error');
        $this->logger
            ->expects($this->never())
            ->method('critical');

        // Act
        $this->loop->runOnce();

        // Assert
        self::assertSame(0, $acknowledgeCalls);
        self::assertSame(1, $releaseCalls);
        self::assertSame([], $this->sleepCalls);
    }

    #[Test]
    public function itReturnsAcknowledgementForDiscardedUnknownAssetsWithoutRequeueing(): void
    {
        // Arrange
        $acknowledgeCalls = 0;
        $releaseCalls = 0;
        $reservation = new ReservedAssetProcessingJob(
            self::PAYLOAD,
            static function () use (&$acknowledgeCalls): void {
                $acknowledgeCalls++;
            },
            static function () use (&$releaseCalls): void {
                $releaseCalls++;
            },
        );
        $this->consumer
            ->expects($this->once())
            ->method('reserveNext')
            ->willReturn($reservation);
        $this->handler
            ->expects($this->once())
            ->method('consume')
            ->with(self::PAYLOAD)
            ->willReturn(AssetProcessingJobHandlingResult::discardedUnknownAsset(self::ASSET_ID));
        $this->logger
            ->expects($this->never())
            ->method('error');
        $this->logger
            ->expects($this->never())
            ->method('critical');

        // Act
        $this->loop->runOnce();

        // Assert
        self::assertSame(1, $acknowledgeCalls);
        self::assertSame(0, $releaseCalls);
        self::assertSame([], $this->sleepCalls);
    }

    #[Test]
    public function itReturnsAcknowledgementForHandledTerminalFailuresWithoutRetrying(): void
    {
        // Arrange
        $acknowledgeCalls = 0;
        $releaseCalls = 0;
        $reservation = new ReservedAssetProcessingJob(
            self::PAYLOAD,
            static function () use (&$acknowledgeCalls): void {
                $acknowledgeCalls++;
            },
            static function () use (&$releaseCalls): void {
                $releaseCalls++;
            },
        );
        $this->consumer
            ->expects($this->once())
            ->method('reserveNext')
            ->willReturn($reservation);
        $this->handler
            ->expects($this->once())
            ->method('consume')
            ->with(self::PAYLOAD)
            ->willReturn(AssetProcessingJobHandlingResult::processedFailed(self::ASSET_ID, true, 'processor crashed'));
        $this->logger
            ->expects($this->never())
            ->method('error');
        $this->logger
            ->expects($this->never())
            ->method('critical');

        // Act
        $this->loop->runOnce();

        // Assert
        self::assertSame(1, $acknowledgeCalls);
        self::assertSame(0, $releaseCalls);
        self::assertSame([], $this->sleepCalls);
    }

    #[Test]
    public function itThrowsAfterBoundedBackoffWhenConsumerFailsRepeatedly(): void
    {
        // Arrange
        $errors = [];
        $criticals = [];
        $this->consumer
            ->expects($this->exactly(3))
            ->method('reserveNext')
            ->willThrowException(RedisJobQueueConsumerException::connectionFailed());
        $this->handler
            ->expects($this->never())
            ->method('consume');
        $this->logger
            ->expects($this->exactly(2))
            ->method('error')
            ->willReturnCallback(static function (string $message, array $context = []) use (&$errors): void {
                $errors[] = [$message, $context];
            });
        $this->logger
            ->expects($this->once())
            ->method('critical')
            ->willReturnCallback(static function (string $message, array $context = []) use (&$criticals): void {
                $criticals[] = [$message, $context];
            });

        // Act
        $this->loop->runOnce();
        $this->loop->runOnce();

        try {
            $this->loop->runOnce();
            self::fail('Expected the worker loop to stop after repeated infrastructure failures.');
        } catch (RedisJobQueueConsumerException $exception) {
            // Assert
            self::assertSame('Failed to connect to the Redis job queue consumer.', $exception->getMessage());
        }

        self::assertSame([1_000, 2_000], $this->sleepCalls);
        self::assertCount(2, $errors);
        self::assertSame(1, $errors[0][1]['consecutiveFailures']);
        self::assertSame(1_000, $errors[0][1]['backoffMicroseconds']);
        self::assertSame(2, $errors[1][1]['consecutiveFailures']);
        self::assertSame(2_000, $errors[1][1]['backoffMicroseconds']);
        self::assertCount(1, $criticals);
        self::assertSame('Asset processing worker stopped after repeated infrastructure failures.', $criticals[0][0]);
        self::assertSame(3, $criticals[0][1]['consecutiveFailures']);
        self::assertInstanceOf(RedisJobQueueConsumerException::class, $criticals[0][1]['exception']);
    }
}
