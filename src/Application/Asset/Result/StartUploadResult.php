<?php

declare(strict_types=1);

namespace App\Application\Asset\Result;

final readonly class StartUploadResult
{
    /**
     * @param list<UserError> $userErrors
     */
    public function __construct(
        public ?StartUploadSuccess $success,
        public array $userErrors,
    ) {
    }
}
