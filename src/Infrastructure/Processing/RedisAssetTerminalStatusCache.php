<?php

declare(strict_types=1);

namespace App\Infrastructure\Processing;

use App\Application\Asset\AssetTerminalStatusCacheInterface;
use App\Domain\Asset\AssetStatus;
use App\Domain\Asset\ValueObject\AssetId;
use App\Infrastructure\Processing\Exception\RedisAssetTerminalStatusCacheException;

final class RedisAssetTerminalStatusCache implements AssetTerminalStatusCacheInterface
{
    private const DEFAULT_KEY_PREFIX = 'asset-terminal-status:';
    private const DEFAULT_TTL_JITTER_SECONDS = 30;
    private const DEFAULT_TTL_SECONDS = 300;

    /**
     * @param \Closure $storeStatus
     * @phpstan-param \Closure(string, string, int): bool $storeStatus
     */
    public function __construct(
        private readonly \Closure $storeStatus,
        private readonly int $ttlSeconds = self::DEFAULT_TTL_SECONDS,
        private readonly int $ttlJitterSeconds = self::DEFAULT_TTL_JITTER_SECONDS,
        private readonly string $keyPrefix = self::DEFAULT_KEY_PREFIX,
    ) {
    }

    public static function fromConnectionConfiguration(
        string $host,
        int $port,
        ?string $password = null,
        int $ttlSeconds = self::DEFAULT_TTL_SECONDS,
        int $ttlJitterSeconds = self::DEFAULT_TTL_JITTER_SECONDS,
        string $keyPrefix = self::DEFAULT_KEY_PREFIX,
    ): self {
        $redis = self::connect($host, $port, $password);

        return new self(
            static fn (string $key, string $value, int $ttl): bool => self::writeStatus($redis, $key, $value, $ttl),
            $ttlSeconds,
            $ttlJitterSeconds,
            $keyPrefix,
        );
    }

    public function store(AssetId $assetId, AssetStatus $status): void
    {
        ($this->storeStatus)(
            $this->keyPrefix . (string) $assetId,
            $status->value,
            $this->ttlSeconds + random_int(0, $this->ttlJitterSeconds),
        );
    }

    private static function connect(string $host, int $port, ?string $password): object
    {
        $redisClass = 'Redis';

        if (! class_exists($redisClass)) {
            throw RedisAssetTerminalStatusCacheException::extensionNotAvailable();
        }

        $redis = new $redisClass();

        if (self::invokeRedisMethod($redis, 'connect', $host, $port) !== true) {
            throw RedisAssetTerminalStatusCacheException::connectionFailed();
        }

        if ($password !== null && $password !== '' && self::invokeRedisMethod($redis, 'auth', $password) !== true) {
            throw RedisAssetTerminalStatusCacheException::authenticationFailed();
        }

        return $redis;
    }

    private static function writeStatus(object $redis, string $key, string $value, int $ttl): bool
    {
        $result = self::invokeRedisMethod($redis, 'setEx', $key, $ttl, $value);

        if ($result !== true) {
            throw RedisAssetTerminalStatusCacheException::storeFailed();
        }

        return true;
    }

    private static function invokeRedisMethod(object $redis, string $method, mixed ...$arguments): mixed
    {
        return $redis->{$method}(...$arguments);
    }
}
