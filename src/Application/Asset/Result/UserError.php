<?php

declare(strict_types=1);

namespace App\Application\Asset\Result;

final readonly class UserError
{
    public function __construct(
        public string $code,
        public string $message,
        public ?string $field = null,
    ) {
    }
}
