<?php

declare(strict_types=1);

namespace App\Domain\Asset\Exception;

final class InvalidFileNameException extends AssetDomainException
{
    public static function forReason(string $reason = 'Invalid file name', ?\Throwable $previous = null): self
    {
        return new self($reason, 0, $previous);
    }
}
