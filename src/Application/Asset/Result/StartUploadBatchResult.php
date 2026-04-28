<?php

declare(strict_types=1);

namespace App\Application\Asset\Result;

final readonly class StartUploadBatchResult
{
    /**
     * @param list<StartUploadBatchFileResult> $files
     */
    public function __construct(
        public array $files,
    ) {
        foreach ($this->files as $i => $file) {
            if (! $file instanceof StartUploadBatchFileResult) {
                throw new \InvalidArgumentException(sprintf('files[%d] must be an instance of StartUploadBatchFileResult', $i));
            }
        }
    }
}
