<?php

declare(strict_types=1);

namespace App\Domain\Asset\Exception;

final class InvalidMimeTypeException extends AssetDomainException
{
    public static function forReason(string $reason = 'Invalid mime type', ?\Throwable $previous = null): self
    {
        return new self($reason, 0, $previous);
    }
}
