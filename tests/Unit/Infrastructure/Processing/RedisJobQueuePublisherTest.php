<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\Processing;

use App\Domain\Asset\ValueObject\AssetId;
use App\Infrastructure\Processing\Exception\RedisJobQueuePublisherException;
use App\Infrastructure\Processing\RedisJobQueuePublisher;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RedisJobQueuePublisherTest extends TestCase
{
    private const ASSET_ID = '123e4567-e89b-42d3-a456-426614174000';

    #[Test]
    public function itPublishesAnAssetProcessingJobWithTheInitialRetryCount(): void
    {
        // Arrange
        $capturedQueueName = null;
        $capturedPayload = null;
        $publisher = new RedisJobQueuePublisher(
            static function (string $queueName, string $payload) use (&$capturedQueueName, &$capturedPayload): int {
                $capturedQueueName = $queueName;
                $capturedPayload = $payload;

                return 1;
            },
        );

        // Act
        $publisher->dispatch(new AssetId(self::ASSET_ID));

        // Assert
        self::assertSame('asset-processing', $capturedQueueName);
        self::assertIsString($capturedPayload);

        $queuePayload = \App\Infrastructure\Processing\AssetProcessingJobPayload::fromJson($capturedPayload);

        self::assertInstanceOf(\App\Infrastructure\Processing\AssetProcessingJobPayload::class, $queuePayload);
        self::assertSame(self::ASSET_ID, $queuePayload->assetId());
        self::assertSame(0, $queuePayload->retryCount());
    }

    #[Test]
    public function itThrowsWhenTheQueueWriteFails(): void
    {
        // Arrange
        $publisher = new RedisJobQueuePublisher(
            static function (string $_queueName, string $_payload): int {
                self::assertIsString($_queueName);
                self::assertIsString($_payload);

                throw RedisJobQueuePublisherException::publishFailed();
            },
        );

        // Act & Assert
        $this->expectException(RedisJobQueuePublisherException::class);
        $this->expectExceptionMessage('Failed to publish asset processing job.');
        $publisher->dispatch(new AssetId(self::ASSET_ID));
    }
}
