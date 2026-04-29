<?php

declare(strict_types=1);

namespace App\Infrastructure\Processing\Exception;

use RuntimeException;

final class RedisJobQueuePublisherException extends RuntimeException
{
    public static function publishFailed(): self
    {
        return new self('Failed to publish asset processing job.');
    }

    public static function extensionNotAvailable(): self
    {
        return new self('Redis extension is not available.');
    }

    public static function connectionFailed(): self
    {
        return new self('Failed to connect to the Redis job queue.');
    }

    public static function authenticationFailed(): self
    {
        return new self('Failed to authenticate with the Redis job queue.');
    }
}
