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
        // Validate that $files is a sequential list and contains only
        // StartUploadBatchFileCommand instances. Fail fast for invalid payloads.
        if (! array_is_list($this->files)) {
            throw new \InvalidArgumentException('StartUploadBatchCommand::$files must be a sequential list (list<StartUploadBatchFileCommand>).');
        }

        foreach ($this->files as $i => $item) {
            if (! $item instanceof StartUploadBatchFileCommand) {
                $given = \get_debug_type($item);

                throw new \InvalidArgumentException(sprintf('StartUploadBatchCommand::$files[%d] must be instance of StartUploadBatchFileCommand, %s given', $i, $given));
            }
        }
    }
}
