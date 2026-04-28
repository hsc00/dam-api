<?php

declare(strict_types=1);

namespace App\Application\Asset\Result;

final readonly class StartUploadBatchResult
{
    /**
     * @param list<StartUploadBatchFileResult> $files
     * @param list<UserError> $userErrors
     */
    public function __construct(
        public array $files,
        public array $userErrors = [],
    ) {
        foreach ($this->files as $i => $file) {
            if (! $file instanceof StartUploadBatchFileResult) {
                throw new \InvalidArgumentException(sprintf('files[%d] must be an instance of StartUploadBatchFileResult', $i));
            }
        }

        foreach ($this->userErrors as $i => $userError) {
            if (! $userError instanceof UserError) {
                throw new \InvalidArgumentException(sprintf('userErrors[%d] must be an instance of UserError', $i));
            }
        }
    }
}
