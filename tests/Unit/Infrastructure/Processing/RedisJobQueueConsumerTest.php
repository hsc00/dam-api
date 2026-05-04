<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\Processing;

use App\Infrastructure\Processing\AssetProcessingJobPayload;
use App\Infrastructure\Processing\RedisJobQueueConsumer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RedisJobQueueConsumerTest extends TestCase
{
    #[Test]
    public function itReturnsTheNextReservedJob(): void
    {
        // Arrange
        $consumer = new RedisJobQueueConsumer(
            static function (string $queueName, string $reservedQueueName, int $timeout): string {
                self::assertSame('asset-processing', $queueName);
                self::assertSame('asset-processing:reserved', $reservedQueueName);
                self::assertSame(5, $timeout);

                return self::payload();
            },
            static function (string $reservedQueueName, string $payload): never {
                self::assertIsString($reservedQueueName);
                self::assertIsString($payload);

                self::fail('Acknowledge should not be called while reserving.');
            },
            static function (string $queueName, string $reservedQueueName, string $reservation, string $payload): never {
                self::assertIsString($queueName);
                self::assertIsString($reservedQueueName);
                self::assertIsString($reservation);
                self::assertIsString($payload);

                self::fail('Release should not be called while reserving.');
            },
            static function (string $queueName, string $reservedQueueName, string $reservation, string $payload): never {
                self::assertIsString($queueName);
                self::assertIsString($reservedQueueName);
                self::assertIsString($reservation);
                self::assertIsString($payload);

                self::fail('Dead-letter should not be called while reserving.');
            },
        );

        // Act
        $reservation = $consumer->reserveNext();

        // Assert
        self::assertNotNull($reservation);
        self::assertSame(self::payload(), $reservation->payload());
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

                return self::payload();
            },
            static function (string $reservedQueueName, string $payload): never {
                self::assertIsString($reservedQueueName);
                self::assertIsString($payload);

                self::fail('Acknowledge should not be called while reserving.');
            },
            static function (string $queueName, string $reservedQueueName, string $reservation, string $payload): never {
                self::assertIsString($queueName);
                self::assertIsString($reservedQueueName);
                self::assertIsString($reservation);
                self::assertIsString($payload);

                self::fail('Release should not be called while reserving.');
            },
            static function (string $queueName, string $reservedQueueName, string $reservation, string $payload): never {
                self::assertIsString($queueName);
                self::assertIsString($reservedQueueName);
                self::assertIsString($reservation);
                self::assertIsString($payload);

                self::fail('Dead-letter should not be called while reserving.');
            },
            'asset-processing',
            null,
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

                return self::payload();
            },
            static function (string $reservedQueueName, string $payload) use (&$acknowledgedReservedQueue, &$acknowledgedPayload): void {
                $acknowledgedReservedQueue = $reservedQueueName;
                $acknowledgedPayload = $payload;
            },
            static function (string $queueName, string $reservedQueueName, string $reservation, string $payload): never {
                self::assertIsString($queueName);
                self::assertIsString($reservedQueueName);
                self::assertIsString($reservation);
                self::assertIsString($payload);

                self::fail('Release should not be called while acknowledging.');
            },
            static function (string $queueName, string $reservedQueueName, string $reservation, string $payload): never {
                self::assertIsString($queueName);
                self::assertIsString($reservedQueueName);
                self::assertIsString($reservation);
                self::assertIsString($payload);

                self::fail('Dead-letter should not be called while acknowledging.');
            },
        );

        // Act
        $reservation = $consumer->reserveNext();

        self::assertNotNull($reservation);
        $reservation->acknowledge();

        // Assert
        $expectedReservedQueueName = 'asset-processing:reserved';
        $expectedPayload = self::payload();

        self::assertSame(expected: $expectedReservedQueueName, actual: $acknowledgedReservedQueue);
        self::assertSame(expected: $expectedPayload, actual: $acknowledgedPayload);
    }

    #[Test]
    public function itUnwrapsReservationMetadataBeforeExposingTheWorkerPayload(): void
    {
        // Arrange
        $reservationEntry = 'v1|42|1700000000000|' . self::payload();
        $acknowledgedReservation = null;
        $releasedReservation = null;
        $releasedPayload = null;
        $deadLetteredReservation = null;
        $deadLetteredPayload = null;
        $consumer = new RedisJobQueueConsumer(
            static fn (string $_queueName, string $_reservedQueueName, int $_timeout): string => $reservationEntry,
            static function (string $reservedQueueName, string $reservation) use (&$acknowledgedReservation): void {
                self::assertSame('asset-processing:reserved', $reservedQueueName);
                $acknowledgedReservation = $reservation;
            },
            static function (string $queueName, string $reservedQueueName, string $reservation, string $payload) use (&$releasedReservation, &$releasedPayload): void {
                self::assertSame('asset-processing', $queueName);
                self::assertSame('asset-processing:reserved', $reservedQueueName);
                $releasedReservation = $reservation;
                $releasedPayload = $payload;
            },
            static function (string $queueName, string $reservedQueueName, string $reservation, string $payload) use (&$deadLetteredReservation, &$deadLetteredPayload): void {
                self::assertSame('asset-processing:failed', $queueName);
                self::assertSame('asset-processing:reserved', $reservedQueueName);
                $deadLetteredReservation = $reservation;
                $deadLetteredPayload = $payload;
            },
        );

        // Act
        $reservation = $consumer->reserveNext();

        self::assertNotNull($reservation);
        $reservation->acknowledge();
        $reservation->release(self::payload(2));
        $reservation->deadLetter(self::payload(3));

        // Assert
        self::assertSame(self::payload(), $reservation->payload());
        self::assertSame(expected: $reservationEntry, actual: $acknowledgedReservation);
        self::assertSame(expected: $reservationEntry, actual: $releasedReservation);
        self::assertSame(expected: self::payload(2), actual: $releasedPayload);
        self::assertSame(expected: $reservationEntry, actual: $deadLetteredReservation);
        self::assertSame(expected: self::payload(3), actual: $deadLetteredPayload);
    }

    #[Test]
    public function itReleasesReservedJobsBackToTheReadyQueueWithTheUpdatedPayload(): void
    {
        // Arrange
        $releasedQueueName = null;
        $releasedReservedQueueName = null;
        $releasedReservation = null;
        $releasedPayload = null;
        $consumer = new RedisJobQueueConsumer(
            static fn (string $_queueName, string $_reservedQueueName, int $_timeout): string => self::payload(),
            static function (string $reservedQueueName, string $payload): never {
                self::assertIsString($reservedQueueName);
                self::assertIsString($payload);

                self::fail('Acknowledge should not be called while releasing.');
            },
            static function (string $queueName, string $reservedQueueName, string $reservation, string $payload) use (&$releasedQueueName, &$releasedReservedQueueName, &$releasedReservation, &$releasedPayload): void {
                $releasedQueueName = $queueName;
                $releasedReservedQueueName = $reservedQueueName;
                $releasedReservation = $reservation;
                $releasedPayload = $payload;
            },
            static function (string $queueName, string $reservedQueueName, string $reservation, string $payload): never {
                self::assertIsString($queueName);
                self::assertIsString($reservedQueueName);
                self::assertIsString($reservation);
                self::assertIsString($payload);

                self::fail('Dead-letter should not be called while releasing.');
            },
        );

        // Act
        $reservation = $consumer->reserveNext();

        self::assertNotNull($reservation);
        $reservation->release(self::payload(2));

        // Assert
        $expectedQueueName = 'asset-processing';
        $expectedReservedQueueName = 'asset-processing:reserved';
        $expectedPayload = self::payload(2);

        self::assertSame(expected: $expectedQueueName, actual: $releasedQueueName);
        self::assertSame(expected: $expectedReservedQueueName, actual: $releasedReservedQueueName);
        self::assertNotNull($releasedReservation);
        self::assertSame(expected: $expectedPayload, actual: $releasedPayload);
    }

    #[Test]
    public function itMovesDeadLetteredJobsToTheFailedQueueWithTheUpdatedPayload(): void
    {
        // Arrange
        $deadLetterQueueName = null;
        $deadLetterReservedQueueName = null;
        $deadLetterReservation = null;
        $deadLetterPayload = null;
        $consumer = new RedisJobQueueConsumer(
            static fn (string $_queueName, string $_reservedQueueName, int $_timeout): string => self::payload(),
            static function (string $reservedQueueName, string $payload): never {
                self::assertIsString($reservedQueueName);
                self::assertIsString($payload);

                self::fail('Acknowledge should not be called while dead-lettering.');
            },
            static function (string $queueName, string $reservedQueueName, string $reservation, string $payload): never {
                self::assertIsString($queueName);
                self::assertIsString($reservedQueueName);
                self::assertIsString($reservation);
                self::assertIsString($payload);

                self::fail('Release should not be called while dead-lettering.');
            },
            static function (string $queueName, string $reservedQueueName, string $reservation, string $payload) use (&$deadLetterQueueName, &$deadLetterReservedQueueName, &$deadLetterReservation, &$deadLetterPayload): void {
                $deadLetterQueueName = $queueName;
                $deadLetterReservedQueueName = $reservedQueueName;
                $deadLetterReservation = $reservation;
                $deadLetterPayload = $payload;
            },
        );

        // Act
        $reservation = $consumer->reserveNext();

        self::assertNotNull($reservation);
        $reservation->deadLetter(self::payload(3));

        // Assert
        self::assertSame('asset-processing:failed', $deadLetterQueueName);
        self::assertSame('asset-processing:reserved', $deadLetterReservedQueueName);
        self::assertNotNull($deadLetterReservation);
        self::assertSame(expected: self::payload(3), actual: $deadLetterPayload);
    }

    #[Test]
    public function itUsesTheUpdatedPayloadWhenAFactoryWiredReservationIsReleased(): void
    {
        // Arrange
        $reservationEntry = 'v1|42|1700000000000|' . self::payload();
        $redis = new class ($reservationEntry) {
            /** @var list<array{0: string, 1: string, 2: string, 3: string}> */
            public array $releasedReservations = [];

            public function __construct(
                private readonly string $reservationEntry,
            ) {
            }

            /**
             * @param array<int, string> $arguments
             */
            public function eval(string $script, array $arguments, int $keyCount): mixed
            {
                if ($keyCount === 3) {
                    return $this->reservationEntry;
                }

                if ($keyCount === 2) {
                    $this->releasedReservations[] = [
                        (string) ($arguments[0] ?? ''),
                        (string) ($arguments[1] ?? ''),
                        (string) ($arguments[2] ?? ''),
                        (string) ($arguments[3] ?? ''),
                    ];

                    return 1;
                }

                throw new \RuntimeException('Unexpected eval invocation.');
            }

            /**
             * @return list<string>
             */
            public function lRange(string $queueName, int $start, int $stop): array
            {
                return [];
            }
        };
        $consumer = RedisJobQueueConsumer::fromRedisConnection($redis);

        // Act
        $reservation = $consumer->reserveNext();

        self::assertNotNull($reservation);
        $reservation->release(self::payload(2));

        // Assert
        self::assertSame([
            ['asset-processing', 'asset-processing:reserved', $reservationEntry, self::payload(2)],
        ], $redis->releasedReservations);
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
            static function (string $queueName, string $reservedQueueName, string $reservation, string $payload): never {
                self::assertIsString($queueName);
                self::assertIsString($reservedQueueName);
                self::assertIsString($reservation);
                self::assertIsString($payload);

                self::fail('Release should not be called when no payload is available.');
            },
            static function (string $queueName, string $reservedQueueName, string $reservation, string $payload): never {
                self::assertIsString($queueName);
                self::assertIsString($reservedQueueName);
                self::assertIsString($reservation);
                self::assertIsString($payload);

                self::fail('Dead-letter should not be called when no payload is available.');
            },
        );

        // Act
        $reservation = $consumer->reserveNext();

        // Assert
        self::assertNull($reservation);
    }

    private static function payload(int $retryCount = 0): string
    {
        return (new AssetProcessingJobPayload('123e4567-e89b-42d3-a456-426614174000', $retryCount))->toJson();
    }
}
