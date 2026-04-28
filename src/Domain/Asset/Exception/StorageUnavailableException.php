<?php

declare(strict_types=1);

namespace App\Domain\Asset\Exception;

final class StorageUnavailableException extends AssetDomainException
{
    public static function forReason(string $reason = 'Storage unavailable', ?\Throwable $previous = null): self
    {
        return new self($reason, 0, $previous);
    }
}
