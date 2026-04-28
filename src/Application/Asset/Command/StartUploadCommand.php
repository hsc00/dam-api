<?php

declare(strict_types=1);

namespace App\Application\Asset\Command;

final readonly class StartUploadCommand
{
    public function __construct(
        public string $accountId,
        public string $fileName,
        public string $mimeType,
        public int $fileSizeBytes,
        public string $checksumSha256,
    ) {
    }
}
