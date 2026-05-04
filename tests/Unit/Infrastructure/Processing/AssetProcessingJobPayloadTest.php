<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\Processing;

use App\Domain\Asset\ValueObject\AssetId;
use App\Infrastructure\Processing\AssetProcessingJobPayload;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AssetProcessingJobPayloadTest extends TestCase
{
    private const ASSET_ID = '123e4567-e89b-42d3-a456-426614174000';

    #[Test]
    public function itRoundTripsSerializedPayloads(): void
    {
        // Arrange
        $payload = new AssetProcessingJobPayload(self::ASSET_ID, 3);

        // Act
        $decodedPayload = AssetProcessingJobPayload::fromJson($payload->toJson());

        // Assert
        self::assertInstanceOf(AssetProcessingJobPayload::class, $decodedPayload);
        self::assertSame(self::ASSET_ID, $decodedPayload->assetId());
        self::assertSame(3, $decodedPayload->retryCount());
        self::assertEquals(new AssetId(self::ASSET_ID), $decodedPayload->toAssetId());
    }

    #[Test]
    public function itBuildsTheInitialDispatchPayloadWithRetryCountZero(): void
    {
        // Arrange
        $assetId = new AssetId(self::ASSET_ID);

        // Act
        $payload = AssetProcessingJobPayload::initial($assetId);

        // Assert
        self::assertSame(self::ASSET_ID, $payload->assetId());
        self::assertSame(0, $payload->retryCount());
    }

    #[Test]
    public function itIncrementsRetryCountWhenPreparingAnotherAttempt(): void
    {
        // Arrange
        $payload = new AssetProcessingJobPayload(self::ASSET_ID, 2);

        // Act
        $nextPayload = $payload->incrementRetryCount();

        // Assert
        self::assertSame(self::ASSET_ID, $nextPayload->assetId());
        self::assertSame(3, $nextPayload->retryCount());
    }
}
