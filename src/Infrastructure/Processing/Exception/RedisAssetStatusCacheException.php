<?php

declare(strict_types=1);

namespace App\Infrastructure\Processing\Exception;

use RuntimeException;

final class RedisAssetStatusCacheException extends RuntimeException
{
    public static function storeFailed(): self
    {
        return new self('Failed to cache asset status.');
    }

    public static function lookupFailed(): self
    {
        return new self('Failed to read cached asset status.');
    }

    public static function extensionNotAvailable(): self
    {
        return new self('Redis extension is not available.');
    }

    public static function connectionFailed(): self
    {
        return new self('Failed to connect to the Redis asset status cache.');
    }

    public static function authenticationFailed(): self
    {
        return new self('Failed to authenticate with the Redis asset status cache.');
    }
}
