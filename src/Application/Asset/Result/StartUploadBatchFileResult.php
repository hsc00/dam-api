<?php

declare(strict_types=1);

namespace App\Application\Asset\Result;

final readonly class StartUploadBatchFileResult
{
    /**
     * @param list<UserError> $userErrors
     */
    public function __construct(
        public string $clientFileId,
        public ?StartUploadBatchFileSuccess $success,
        public array $userErrors,
    ) {
    }
}
