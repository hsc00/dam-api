<?php

declare(strict_types=1);

namespace App\Application\Asset\Result;

final readonly class TerminalStatusCacheStoreResult
{
    private function __construct(
        public bool $stored,
        public ?string $error = null,
    ) {
    }

    public static function failed(string $error): self
    {
        return new self(false, $error);
    }

    public static function storedSuccessfully(): self
    {
        return new self(true);
    }
}
