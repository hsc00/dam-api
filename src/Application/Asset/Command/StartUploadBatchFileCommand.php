<?php

declare(strict_types=1);

namespace App\Application\Asset\Command;

final readonly class StartUploadBatchFileCommand
{
    public function __construct(
        public string $clientFileId,
        public string $fileName,
        public string $mimeType,
        public int $chunkCount,
    ) {
    }
}
