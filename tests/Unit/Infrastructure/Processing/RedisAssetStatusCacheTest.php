<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\Processing;

use App\Domain\Asset\AssetStatus;
use App\Domain\Asset\ValueObject\AssetId;
use App\Infrastructure\Processing\Exception\RedisAssetStatusCacheException;
use App\Infrastructure\Processing\RedisAssetStatusCache;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RedisAssetStatusCacheTest extends TestCase
{
    private const ASSET_ID = '123e4567-e89b-42d3-a456-426614174000';

    #[Test]
    public function itStoresAssetStatusWithTheConfiguredTtlWhenCalled(): void
    {
        // Arrange
        $capturedKey = null;
        $capturedValue = null;
        $capturedTtl = null;
        $cache = new RedisAssetStatusCache(
            static function (string $key, string $value, int $ttl) use (&$capturedKey, &$capturedValue, &$capturedTtl): bool {
                $capturedKey = $key;
                $capturedValue = $value;
                $capturedTtl = $ttl;

                return true;
            },
            static fn (string $_key): null => null,
            120,
            0,
        );

        // Act
        $cache->store(new AssetId(self::ASSET_ID), AssetStatus::PROCESSING);

        // Assert
        $expectedKey = 'asset-status:' . self::ASSET_ID;
        $expectedValue = 'PROCESSING';
        $expectedTtl = 120;

        self::assertSame(expected: $expectedKey, actual: $capturedKey);
        self::assertSame(expected: $expectedValue, actual: $capturedValue);
        self::assertSame(expected: $expectedTtl, actual: $capturedTtl);
    }

    #[Test]
    public function itReturnsCachedAssetStatusWhenLookupSucceeds(): void
    {
        // Arrange
        $capturedKey = null;
        $cache = new RedisAssetStatusCache(
            static fn (string $_key, string $_value, int $_ttl): bool => true,
            static function (string $key) use (&$capturedKey): string {
                $capturedKey = $key;

                return AssetStatus::UPLOADED->value;
            },
            60,
            0,
        );

        // Act
        $status = $cache->lookup(new AssetId(self::ASSET_ID));

        // Assert
        self::assertSame(expected: 'asset-status:' . self::ASSET_ID, actual: $capturedKey);
        self::assertSame(AssetStatus::UPLOADED, $status);
    }

    #[Test]
    public function itReturnsNullWhenLookupMisses(): void
    {
        // Arrange
        $cache = new RedisAssetStatusCache(
            static fn (string $_key, string $_value, int $_ttl): bool => true,
            static fn (string $_key): null => null,
            60,
            0,
        );

        // Act
        $status = $cache->lookup(new AssetId(self::ASSET_ID));

        // Assert
        self::assertNull($status);
    }

    #[Test]
    public function itThrowsWhenTheCacheWriteFails(): void
    {
        // Arrange
        $cache = new RedisAssetStatusCache(
            static function (string $_key, string $_value, int $_ttl): bool {
                self::assertIsString($_key);
                self::assertIsString($_value);
                self::assertIsInt($_ttl);

                throw RedisAssetStatusCacheException::storeFailed();
            },
            static fn (string $_key): null => null,
            60,
            0,
        );

        // Act & Assert
        $this->expectException(RedisAssetStatusCacheException::class);
        $this->expectExceptionMessage('Failed to cache asset status.');
        $cache->store(new AssetId(self::ASSET_ID), AssetStatus::FAILED);
    }
}
