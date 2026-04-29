<?php

declare(strict_types=1);

namespace App\Infrastructure\Processing;

use App\Application\Asset\AssetProcessingJobDispatcherInterface;
use App\Domain\Asset\ValueObject\AssetId;
use App\Infrastructure\Processing\Exception\RedisJobQueuePublisherException;

final class RedisJobQueuePublisher implements AssetProcessingJobDispatcherInterface
{
    private const DEFAULT_QUEUE_NAME = 'asset-processing';
    private const INITIAL_RETRY_COUNT = 0;

    /**
     * @param \Closure $publishJob
     * @phpstan-param \Closure(string, string): int $publishJob
     */
    public function __construct(
        private readonly \Closure $publishJob,
        private readonly string $queueName = self::DEFAULT_QUEUE_NAME,
    ) {
    }

    public static function fromConnectionConfiguration(
        string $host,
        int $port,
        ?string $password = null,
        string $queueName = self::DEFAULT_QUEUE_NAME,
    ): self {
        $redis = self::connect($host, $port, $password);

        return new self(
            static fn (string $targetQueue, string $payload): int => self::pushJob($redis, $targetQueue, $payload),
            $queueName,
        );
    }

    public function dispatch(AssetId $assetId): void
    {
        $payload = json_encode([
            'assetId' => (string) $assetId,
            'retryCount' => self::INITIAL_RETRY_COUNT,
        ], JSON_THROW_ON_ERROR);

        ($this->publishJob)($this->queueName, $payload);
    }

    private static function connect(string $host, int $port, ?string $password): object
    {
        $redisClass = 'Redis';

        if (! class_exists($redisClass)) {
            throw RedisJobQueuePublisherException::extensionNotAvailable();
        }

        $redis = new $redisClass();

        if (self::invokeRedisMethod($redis, 'connect', $host, $port) !== true) {
            throw RedisJobQueuePublisherException::connectionFailed();
        }

        if ($password !== null && $password !== '' && self::invokeRedisMethod($redis, 'auth', $password) !== true) {
            throw RedisJobQueuePublisherException::authenticationFailed();
        }

        return $redis;
    }

    private static function pushJob(object $redis, string $queueName, string $payload): int
    {
        $result = self::invokeRedisMethod($redis, 'rPush', $queueName, $payload);

        if (! is_int($result)) {
            throw RedisJobQueuePublisherException::publishFailed();
        }

        return $result;
    }

    private static function invokeRedisMethod(object $redis, string $method, mixed ...$arguments): mixed
    {
        return $redis->{$method}(...$arguments);
    }
}
