<?php

declare(strict_types=1);

namespace App\Infrastructure\Processing;

use App\Application\Asset\AssetStatusCacheInterface;
use App\Domain\Asset\AssetStatus;
use App\Domain\Asset\ValueObject\AssetId;
use App\Infrastructure\Processing\Exception\RedisAssetStatusCacheException;

final class RedisAssetStatusCache implements AssetStatusCacheInterface
{
    private const DEFAULT_KEY_PREFIX = 'asset-status:';
    private const DEFAULT_TTL_JITTER_SECONDS = 30;
    private const DEFAULT_TTL_SECONDS = 300;

    /**
     * @phpstan-param \Closure(string, string, int): bool $storeStatus
     * @phpstan-param \Closure(string): (string|null) $lookupStatus
     */
    public function __construct(
        private readonly \Closure $storeStatus,
        private readonly \Closure $lookupStatus,
        private readonly int $ttlSeconds = self::DEFAULT_TTL_SECONDS,
        private readonly int $ttlJitterSeconds = self::DEFAULT_TTL_JITTER_SECONDS,
        private readonly string $keyPrefix = self::DEFAULT_KEY_PREFIX,
    ) {
        if ($ttlSeconds <= 0) {
            throw new \InvalidArgumentException('ttlSeconds must be a positive integer, got ' . $ttlSeconds . '.');
        }

        if ($ttlJitterSeconds < 0) {
            throw new \InvalidArgumentException('ttlJitterSeconds must be a non-negative integer, got ' . $ttlJitterSeconds . '.');
        }
    }

    public static function fromConnectionConfiguration(
        string $host,
        int $port,
        ?string $password = null,
        int $ttlSeconds = self::DEFAULT_TTL_SECONDS,
        int $ttlJitterSeconds = self::DEFAULT_TTL_JITTER_SECONDS,
        string $keyPrefix = self::DEFAULT_KEY_PREFIX,
    ): self {
        $redis = null;
        $connection = static function () use (&$redis, $host, $port, $password): object {
            $redis ??= self::connect($host, $port, $password);

            return $redis;
        };

        return new self(
            static fn (string $key, string $value, int $ttl): bool => self::writeStatus($connection(), $key, $value, $ttl),
            static fn (string $key): string|null => self::readStatus($connection(), $key),
            $ttlSeconds,
            $ttlJitterSeconds,
            $keyPrefix,
        );
    }

    public function lookup(AssetId $assetId): ?AssetStatus
    {
        $cachedStatus = ($this->lookupStatus)($this->keyPrefix . (string) $assetId);

        if ($cachedStatus === null) {
            return null;
        }

        if (! is_string($cachedStatus)) {
            throw RedisAssetStatusCacheException::lookupFailed();
        }

        return AssetStatus::tryFrom($cachedStatus);
    }

    public function store(AssetId $assetId, AssetStatus $status): void
    {
        $written = ($this->storeStatus)(
            $this->keyPrefix . (string) $assetId,
            $status->value,
            $this->ttlSeconds + random_int(0, $this->ttlJitterSeconds),
        );

        if ($written === false) {
            throw RedisAssetStatusCacheException::storeFailed();
        }
    }

    private static function connect(string $host, int $port, ?string $password): object
    {
        $redisClass = 'Redis';

        if (! class_exists($redisClass)) {
            throw RedisAssetStatusCacheException::extensionNotAvailable();
        }

        $redis = new $redisClass();

        if (self::invokeRedisMethod($redis, 'connect', $host, $port) !== true) {
            throw RedisAssetStatusCacheException::connectionFailed();
        }

        if ($password !== null && $password !== '' && self::invokeRedisMethod($redis, 'auth', $password) !== true) {
            throw RedisAssetStatusCacheException::authenticationFailed();
        }

        return $redis;
    }

    private static function writeStatus(object $redis, string $key, string $value, int $ttl): bool
    {
        $result = self::invokeRedisMethod($redis, 'setEx', $key, $ttl, $value);

        if ($result !== true) {
            throw RedisAssetStatusCacheException::storeFailed();
        }

        return true;
    }

    private static function readStatus(object $redis, string $key): string|null
    {
        $result = self::invokeRedisMethod($redis, 'get', $key);

        if ($result === null || $result === false) {
            return null;
        }

        if (! is_string($result)) {
            throw RedisAssetStatusCacheException::lookupFailed();
        }

        return $result;
    }

    private static function invokeRedisMethod(object $redis, string $method, mixed ...$arguments): mixed
    {
        return $redis->{$method}(...$arguments);
    }
}
