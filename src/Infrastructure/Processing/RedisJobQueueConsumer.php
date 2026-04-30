<?php

declare(strict_types=1);

namespace App\Infrastructure\Processing;

use App\Infrastructure\Processing\Exception\RedisJobQueueConsumerException;

final class RedisJobQueueConsumer implements AssetProcessingJobConsumerInterface
{
    private const DEFAULT_BLOCK_TIMEOUT_SECONDS = 5;
    private const DEFAULT_QUEUE_NAME = 'asset-processing';
    private const DEFAULT_RESERVE_POLL_INTERVAL_MICROSECONDS = 250_000;
    private const DEFAULT_VISIBILITY_TIMEOUT_SECONDS = 30;
    private const RESERVED_QUEUE_SUFFIX = ':reserved';
    private const RESERVED_QUEUE_SEQUENCE_SUFFIX = ':sequence';
    private const RESERVED_ENTRY_PREFIX = 'v1';

    private const REQUEUE_RESERVED_JOB_SCRIPT = <<<'LUA'
if redis.call('LREM', KEYS[2], 1, ARGV[1]) > 0 then
    redis.call('RPUSH', KEYS[1], ARGV[2])

    return 1
end

return 0
LUA;

    private const RESERVE_JOB_SCRIPT = <<<'LUA'
local payload = redis.call('RPOP', KEYS[1])

if not payload then
    return false
end

local reservationId = tostring(redis.call('INCR', KEYS[3]))
local time = redis.call('TIME')
local reservedAtMilliseconds = tostring((tonumber(time[1]) * 1000) + math.floor(tonumber(time[2]) / 1000))
local reservation = ARGV[1] .. '|' .. reservationId .. '|' .. reservedAtMilliseconds .. '|' .. payload

redis.call('LPUSH', KEYS[2], reservation)

return reservation
LUA;

    private readonly ?\Closure $recoverExpiredJobs;
    private readonly string $reservedQueueName;

    /**
     * @param \Closure(string, string, int): ?string $reserveJob
     * @param \Closure(string, string): void $acknowledgeJob
     * @param \Closure(string, string, string): void $releaseJob
     * @param null|\Closure(string, string, int): void $recoverExpiredJobs
     */
    public function __construct(
        private readonly \Closure $reserveJob,
        private readonly \Closure $acknowledgeJob,
        private readonly \Closure $releaseJob,
        private readonly string $queueName = self::DEFAULT_QUEUE_NAME,
        ?string $reservedQueueName = null,
        private readonly int $blockTimeoutSeconds = self::DEFAULT_BLOCK_TIMEOUT_SECONDS,
        private readonly int $visibilityTimeoutSeconds = self::DEFAULT_VISIBILITY_TIMEOUT_SECONDS,
        ?\Closure $recoverExpiredJobs = null,
    ) {
        $this->reservedQueueName = $reservedQueueName ?? $queueName . self::RESERVED_QUEUE_SUFFIX;
        $this->recoverExpiredJobs = $recoverExpiredJobs;
    }

    public static function fromConnectionConfiguration(
        string $host,
        int $port,
        ?string $password = null,
        string $queueName = self::DEFAULT_QUEUE_NAME,
        int $blockTimeoutSeconds = self::DEFAULT_BLOCK_TIMEOUT_SECONDS,
        int $visibilityTimeoutSeconds = self::DEFAULT_VISIBILITY_TIMEOUT_SECONDS,
    ): self {
        $redis = null;

        return new self(
            static function (string $targetQueue, string $reservedQueue, int $timeout) use (&$redis, $host, $port, $password): ?string {
                $redis ??= self::connect($host, $port, $password);

                return self::reserveJob(
                    $redis,
                    $targetQueue,
                    $reservedQueue,
                    self::reservationQueueSequenceKey($reservedQueue),
                    $timeout,
                );
            },
            static function (string $reservedQueue, string $reservation) use (&$redis, $host, $port, $password): void {
                $redis ??= self::connect($host, $port, $password);

                self::acknowledgeJob($redis, $reservedQueue, $reservation);
            },
            static function (string $targetQueue, string $reservedQueue, string $reservation) use (&$redis, $host, $port, $password): void {
                $redis ??= self::connect($host, $port, $password);

                self::releaseJob($redis, $targetQueue, $reservedQueue, $reservation);
            },
            $queueName,
            null,
            $blockTimeoutSeconds,
            $visibilityTimeoutSeconds,
            static function (string $targetQueue, string $reservedQueue, int $timeout) use (&$redis, $host, $port, $password): void {
                $redis ??= self::connect($host, $port, $password);

                self::recoverExpiredJobs($redis, $targetQueue, $reservedQueue, $timeout);
            },
        );
    }

    public function reserveNext(): ?ReservedAssetProcessingJob
    {
        if ($this->recoverExpiredJobs !== null) {
            ($this->recoverExpiredJobs)($this->queueName, $this->reservedQueueName, $this->visibilityTimeoutSeconds);
        }

        $reservation = ($this->reserveJob)($this->queueName, $this->reservedQueueName, $this->blockTimeoutSeconds);

        if ($reservation === null) {
            return null;
        }

        $payload = self::payloadFromReservation($reservation);

        return new ReservedAssetProcessingJob(
            $payload,
            function () use ($reservation): void {
                ($this->acknowledgeJob)($this->reservedQueueName, $reservation);
            },
            function () use ($reservation): void {
                ($this->releaseJob)($this->queueName, $this->reservedQueueName, $reservation);
            },
        );
    }

    private static function connect(string $host, int $port, ?string $password): object
    {
        $redisClass = 'Redis';

        if (! class_exists($redisClass)) {
            throw RedisJobQueueConsumerException::extensionNotAvailable();
        }

        $redis = new $redisClass();

        if (self::invokeRedisMethod($redis, 'connect', $host, $port) !== true) {
            throw RedisJobQueueConsumerException::connectionFailed();
        }

        if ($password !== null && $password !== '' && self::invokeRedisMethod($redis, 'auth', $password) !== true) {
            throw RedisJobQueueConsumerException::authenticationFailed();
        }

        return $redis;
    }

    private static function reserveJob(
        object $redis,
        string $queueName,
        string $reservedQueueName,
        string $reservationQueueSequenceKey,
        int $blockTimeoutSeconds,
    ): ?string {
        $deadline = microtime(true) + max(0, $blockTimeoutSeconds);

        do {
            $result = self::invokeRedisMethod(
                $redis,
                'eval',
                self::RESERVE_JOB_SCRIPT,
                [$queueName, $reservedQueueName, $reservationQueueSequenceKey, self::RESERVED_ENTRY_PREFIX],
                3,
            );

            if ($result !== false && $result !== null) {
                if (! is_string($result)) {
                    throw RedisJobQueueConsumerException::reserveFailed();
                }

                return $result;
            }

            if ($blockTimeoutSeconds <= 0) {
                return null;
            }

            $remainingMicroseconds = (int) max(0, ($deadline - microtime(true)) * 1_000_000);

            if ($remainingMicroseconds <= 0) {
                return null;
            }

            usleep(min(self::DEFAULT_RESERVE_POLL_INTERVAL_MICROSECONDS, $remainingMicroseconds));
        } while (true);
    }

    private static function recoverExpiredJobs(
        object $redis,
        string $queueName,
        string $reservedQueueName,
        int $visibilityTimeoutSeconds,
    ): void {
        $reservations = self::invokeRedisMethod($redis, 'lRange', $reservedQueueName, 0, -1);

        if ($reservations === false) {
            throw RedisJobQueueConsumerException::recoveryFailed();
        }

        if (! is_array($reservations)) {
            throw RedisJobQueueConsumerException::recoveryFailed();
        }

        $nowMilliseconds = self::currentTimeMilliseconds();

        foreach ($reservations as $reservation) {
            if (! is_string($reservation)) {
                throw RedisJobQueueConsumerException::recoveryFailed();
            }

            if (! self::reservationHasExpired($reservation, $nowMilliseconds, $visibilityTimeoutSeconds)) {
                continue;
            }

            self::recoverReservation($redis, $queueName, $reservedQueueName, $reservation);
        }
    }

    private static function recoverReservation(
        object $redis,
        string $queueName,
        string $reservedQueueName,
        string $reservation,
    ): void {
        $result = self::invokeRedisMethod(
            $redis,
            'eval',
            self::REQUEUE_RESERVED_JOB_SCRIPT,
            [$queueName, $reservedQueueName, $reservation, self::payloadFromReservation($reservation)],
            2,
        );

        if (! is_int($result) || ($result !== 0 && $result !== 1)) {
            throw RedisJobQueueConsumerException::recoveryFailed();
        }
    }

    private static function reservationHasExpired(
        string $reservation,
        int $nowMilliseconds,
        int $visibilityTimeoutSeconds,
    ): bool {
        $reservedAtMilliseconds = self::reservedAtMilliseconds($reservation);

        if ($reservedAtMilliseconds === null) {
            return true;
        }

        return $reservedAtMilliseconds + ($visibilityTimeoutSeconds * 1000) <= $nowMilliseconds;
    }

    private static function payloadFromReservation(string $reservation): string
    {
        $parts = self::reservationParts($reservation);

        if ($parts === null) {
            return $reservation;
        }

        return $parts[3];
    }

    private static function reservedAtMilliseconds(string $reservation): ?int
    {
        $parts = self::reservationParts($reservation);

        if ($parts === null || ! ctype_digit($parts[2])) {
            return null;
        }

        return (int) $parts[2];
    }

    /**
     * @return array{0: string, 1: string, 2: string, 3: string}|null
     */
    private static function reservationParts(string $reservation): ?array
    {
        $parts = explode('|', $reservation, 4);

        if (count($parts) !== 4 || $parts[0] !== self::RESERVED_ENTRY_PREFIX) {
            return null;
        }

        return [$parts[0], $parts[1], $parts[2], $parts[3]];
    }

    private static function reservationQueueSequenceKey(string $reservedQueueName): string
    {
        return $reservedQueueName . self::RESERVED_QUEUE_SEQUENCE_SUFFIX;
    }

    private static function currentTimeMilliseconds(): int
    {
        return (int) floor(microtime(true) * 1000);
    }

    private static function acknowledgeJob(object $redis, string $reservedQueueName, string $reservation): void
    {
        $result = self::invokeRedisMethod($redis, 'lRem', $reservedQueueName, $reservation, 1);

        if (! is_int($result) || $result < 1) {
            throw RedisJobQueueConsumerException::acknowledgeFailed();
        }
    }

    private static function releaseJob(object $redis, string $queueName, string $reservedQueueName, string $reservation): void
    {
        $result = self::invokeRedisMethod(
            $redis,
            'eval',
            self::REQUEUE_RESERVED_JOB_SCRIPT,
            [$queueName, $reservedQueueName, $reservation, self::payloadFromReservation($reservation)],
            2,
        );

        if (! is_int($result) || $result !== 1) {
            throw RedisJobQueueConsumerException::releaseFailed();
        }
    }

    private static function invokeRedisMethod(object $redis, string $method, mixed ...$arguments): mixed
    {
        return $redis->{$method}(...$arguments);
    }
}
