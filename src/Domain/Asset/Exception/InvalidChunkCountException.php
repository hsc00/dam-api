<?php

declare(strict_types=1);

namespace App\Domain\Asset\Exception;

final class InvalidChunkCountException extends AssetDomainException
{
    public static function forReason(string $reason = 'Invalid chunk count', ?\Throwable $previous = null): self
    {
        return new self($reason, 0, $previous);
    }
}
