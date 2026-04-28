<?php

declare(strict_types=1);

namespace App\Application\Asset\Result;

final readonly class CompleteUploadSuccess
{
    /**
     * @param array{id: string, status: \App\Domain\Asset\AssetStatus} $asset
     */
    public function __construct(
        public array $asset,
    ) {
    }
}
