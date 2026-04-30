<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\Processing;

use App\Infrastructure\Processing\RedisJobQueueConsumer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RedisJobQueueConsumerTest extends TestCase
{
    private const PAYLOAD = '{"assetId":"123e4567-e89b-42d3-a456-426614174000","retryCount":0}';

    #[Test]
    public function itReturnsTheNextReservedJob(): void
    {
        // Arrange
        $consumer = new RedisJobQueueConsumer(
            static function (string $queueName, string $reservedQueueName, int $timeout): string {
                self::assertSame('asset-processing', $queueName);
                self::assertSame('asset-processing:reserved', $reservedQueueName);
                self::assertSame(5, $timeout);

                return self::PAYLOAD;
            },
            static function (string $reservedQueueName, string $payload): never {
                self::assertIsString($reservedQueueName);
                self::assertIsString($payload);

                self::fail('Acknowledge should not be called while reserving.');
            },
            static function (string $queueName, string $reservedQueueName, string $payload): never {
                self::assertIsString($queueName);
                self::assertIsString($reservedQueueName);
                self::assertIsString($payload);

                self::fail('Release should not be called while reserving.');
            },
        );

        // Act
        $reservation = $consumer->reserveNext();

        // Assert
        self::assertNotNull($reservation);
        self::assertSame(self::PAYLOAD, $reservation->payload());
    }

    #[Test]
    public function itRecoversExpiredReservationsBeforeReservingAnotherJob(): void
    {
        // Arrange
        $calls = [];
        $consumer = new RedisJobQueueConsumer(
            static function (string $queueName, string $reservedQueueName, int $timeout) use (&$calls): string {
                $calls[] = 'reserve';
                self::assertSame('asset-processing', $queueName);
                self::assertSame('asset-processing:reserved', $reservedQueueName);
                self::assertSame(5, $timeout);

                return self::PAYLOAD;
            },
            static function (string $reservedQueueName, string $payload): never {
                self::assertIsString($reservedQueueName);
                self::assertIsString($payload);

                self::fail('Acknowledge should not be called while reserving.');
            },
            static function (string $queueName, string $reservedQueueName, string $payload): never {
                self::assertIsString($queueName);
                self::assertIsString($reservedQueueName);
                self::assertIsString($payload);

                self::fail('Release should not be called while reserving.');
            },
            'asset-processing',
            null,
            5,
            30,
            static function (string $queueName, string $reservedQueueName, int $visibilityTimeoutSeconds) use (&$calls): void {
                $calls[] = 'recover';
                self::assertSame('asset-processing', $queueName);
                self::assertSame('asset-processing:reserved', $reservedQueueName);
                self::assertSame(30, $visibilityTimeoutSeconds);
            },
        );

        // Act
        $reservation = $consumer->reserveNext();

        // Assert
        self::assertNotNull($reservation);
        self::assertSame(['recover', 'reserve'], $calls);
    }

    #[Test]
    public function itAcknowledgesReservedJobsUsingTheReservedQueue(): void
    {
        // Arrange
        $acknowledgedReservedQueue = null;
        $acknowledgedPayload = null;
        $consumer = new RedisJobQueueConsumer(
            static function (string $queueName, string $reservedQueueName, int $timeout): string {
                self::assertSame('asset-processing', $queueName);
                self::assertSame('asset-processing:reserved', $reservedQueueName);
                self::assertSame(5, $timeout);

                return self::PAYLOAD;
            },
            static function (string $reservedQueueName, string $payload) use (&$acknowledgedReservedQueue, &$acknowledgedPayload): void {
                $acknowledgedReservedQueue = $reservedQueueName;
                $acknowledgedPayload = $payload;
            },
            static function (string $queueName, string $reservedQueueName, string $payload): never {
                self::assertIsString($queueName);
                self::assertIsString($reservedQueueName);
                self::assertIsString($payload);

                self::fail('Release should not be called while acknowledging.');
            },
        );

        // Act
        $reservation = $consumer->reserveNext();

        self::assertNotNull($reservation);
        $reservation->acknowledge();

        // Assert
        $expectedReservedQueueName = 'asset-processing:reserved';
        $expectedPayload = self::PAYLOAD;

        self::assertSame(expected: $expectedReservedQueueName, actual: $acknowledgedReservedQueue);
        self::assertSame(expected: $expectedPayload, actual: $acknowledgedPayload);
    }

    #[Test]
    public function itUnwrapsReservationMetadataBeforeExposingTheWorkerPayload(): void
    {
        // Arrange
        $reservationEntry = 'v1|42|1700000000000|' . self::PAYLOAD;
        $acknowledgedReservation = null;
        $releasedReservation = null;
        $consumer = new RedisJobQueueConsumer(
            static fn (string $_queueName, string $_reservedQueueName, int $_timeout): string => $reservationEntry,
            static function (string $reservedQueueName, string $reservation) use (&$acknowledgedReservation): void {
                self::assertSame('asset-processing:reserved', $reservedQueueName);
                $acknowledgedReservation = $reservation;
            },
            static function (string $queueName, string $reservedQueueName, string $reservation) use (&$releasedReservation): void {
                self::assertSame('asset-processing', $queueName);
                self::assertSame('asset-processing:reserved', $reservedQueueName);
                $releasedReservation = $reservation;
            },
        );

        // Act
        $reservation = $consumer->reserveNext();

        self::assertNotNull($reservation);
        $reservation->acknowledge();
        $reservation->release();

        // Assert
        self::assertSame(self::PAYLOAD, $reservation->payload());
        self::assertSame(expected: $reservationEntry, actual: $acknowledgedReservation);
        self::assertSame(expected: $reservationEntry, actual: $releasedReservation);
    }

    #[Test]
    public function itReleasesReservedJobsBackToTheReadyQueue(): void
    {
        // Arrange
        $releasedQueueName = null;
        $releasedReservedQueueName = null;
        $releasedPayload = null;
        $consumer = new RedisJobQueueConsumer(
            static fn (string $_queueName, string $_reservedQueueName, int $_timeout): string => self::PAYLOAD,
            static function (string $reservedQueueName, string $payload): never {
                self::assertIsString($reservedQueueName);
                self::assertIsString($payload);

                self::fail('Acknowledge should not be called while releasing.');
            },
            static function (string $queueName, string $reservedQueueName, string $payload) use (&$releasedQueueName, &$releasedReservedQueueName, &$releasedPayload): void {
                $releasedQueueName = $queueName;
                $releasedReservedQueueName = $reservedQueueName;
                $releasedPayload = $payload;
            },
        );

        // Act
        $reservation = $consumer->reserveNext();

        self::assertNotNull($reservation);
        $reservation->release();

        // Assert
        $expectedQueueName = 'asset-processing';
        $expectedReservedQueueName = 'asset-processing:reserved';
        $expectedPayload = self::PAYLOAD;

        self::assertSame(expected: $expectedQueueName, actual: $releasedQueueName);
        self::assertSame(expected: $expectedReservedQueueName, actual: $releasedReservedQueueName);
        self::assertSame(expected: $expectedPayload, actual: $releasedPayload);
    }

    #[Test]
    public function itReturnsNullWhenNoQueuedPayloadIsAvailable(): void
    {
        // Arrange
        $consumer = new RedisJobQueueConsumer(
            static function (string $queueName, string $reservedQueueName, int $timeout): ?string {
                self::assertSame('asset-processing', $queueName);
                self::assertSame('asset-processing:reserved', $reservedQueueName);
                self::assertSame(5, $timeout);

                return null;
            },
            static function (string $reservedQueueName, string $payload): never {
                self::assertIsString($reservedQueueName);
                self::assertIsString($payload);

                self::fail('Acknowledge should not be called when no payload is available.');
            },
            static function (string $queueName, string $reservedQueueName, string $payload): never {
                self::assertIsString($queueName);
                self::assertIsString($reservedQueueName);
                self::assertIsString($payload);

                self::fail('Release should not be called when no payload is available.');
            },
        );

        // Act
        $reservation = $consumer->reserveNext();

        // Assert
        self::assertNull($reservation);
    }
}
