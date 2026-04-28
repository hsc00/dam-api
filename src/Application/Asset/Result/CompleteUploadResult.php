<?php

declare(strict_types=1);

namespace App\Application\Asset\Result;

final readonly class CompleteUploadResult
{
    /**
     * @param list<UserError> $userErrors
     */
    public function __construct(
        public ?CompleteUploadSuccess $success,
        public array $userErrors,
    ) {
    }
}
