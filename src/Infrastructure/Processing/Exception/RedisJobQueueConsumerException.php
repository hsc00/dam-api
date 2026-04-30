<?php

declare(strict_types=1);

namespace App\Infrastructure\Processing\Exception;

use RuntimeException;

final class RedisJobQueueConsumerException extends RuntimeException
{
    public static function acknowledgeFailed(): self
    {
        return new self('Failed to acknowledge asset processing job.');
    }

    public static function authenticationFailed(): self
    {
        return new self('Failed to authenticate with the Redis job queue consumer.');
    }

    public static function connectionFailed(): self
    {
        return new self('Failed to connect to the Redis job queue consumer.');
    }

    public static function deadLetterFailed(): self
    {
        return new self('Failed to dead-letter asset processing job.');
    }

    public static function releaseFailed(): self
    {
        return new self('Failed to release asset processing job.');
    }

    public static function recoveryFailed(): self
    {
        return new self('Failed to recover expired asset processing jobs.');
    }

    public static function reserveFailed(): self
    {
        return new self('Failed to reserve asset processing job.');
    }

    public static function extensionNotAvailable(): self
    {
        return new self('Redis extension is not available.');
    }
}
