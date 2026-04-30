<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\Processing;

use App\Domain\Asset\AssetStatus;
use App\Domain\Asset\ValueObject\AssetId;
use App\Infrastructure\Processing\Exception\RedisAssetTerminalStatusCacheException;
use App\Infrastructure\Processing\RedisAssetTerminalStatusCache;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RedisAssetTerminalStatusCacheTest extends TestCase
{
    private const ASSET_ID = '123e4567-e89b-42d3-a456-426614174000';

    #[Test]
    public function itStoresTerminalAssetStatusWithTheConfiguredTtlWhenCalled(): void
    {
        // Arrange
        $capturedKey = null;
        $capturedValue = null;
        $capturedTtl = null;
        $cache = new RedisAssetTerminalStatusCache(
            static function (string $key, string $value, int $ttl) use (&$capturedKey, &$capturedValue, &$capturedTtl): bool {
                $capturedKey = $key;
                $capturedValue = $value;
                $capturedTtl = $ttl;

                return true;
            },
            120,
            0,
        );

        // Act
        $cache->store(new AssetId(self::ASSET_ID), AssetStatus::UPLOADED);

        // Assert
        $expectedKey = 'asset-terminal-status:' . self::ASSET_ID;
        $expectedValue = 'UPLOADED';
        $expectedTtl = 120;

        self::assertSame(expected: $expectedKey, actual: $capturedKey);
        self::assertSame(expected: $expectedValue, actual: $capturedValue);
        self::assertSame(expected: $expectedTtl, actual: $capturedTtl);
    }

    #[Test]
    public function itThrowsWhenTheCacheWriteFails(): void
    {
        // Arrange
        $cache = new RedisAssetTerminalStatusCache(
            static function (string $_key, string $_value, int $_ttl): bool {
                self::assertIsString($_key);
                self::assertIsString($_value);
                self::assertIsInt($_ttl);

                throw RedisAssetTerminalStatusCacheException::storeFailed();
            },
            60,
            0,
        );

        // Act & Assert
        $this->expectException(RedisAssetTerminalStatusCacheException::class);
        $this->expectExceptionMessage('Failed to cache terminal asset status.');
        $cache->store(new AssetId(self::ASSET_ID), AssetStatus::FAILED);
    }
}
