<?php

declare(strict_types=1);

namespace App\Application\Asset\Command;

final readonly class StartUploadBatchCommand
{
    /**
     * @param list<StartUploadBatchFileCommand> $files
     */
    public function __construct(
        public string $accountId,
        public array $files,
    ) {
    }
}
