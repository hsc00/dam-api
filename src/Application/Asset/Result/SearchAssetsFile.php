<?php

declare(strict_types=1);

namespace App\Application\Asset\Result;

use App\Domain\Asset\Asset;
use App\Domain\Asset\AssetStatus;

final readonly class SearchAssetsFile
{
    public function __construct(
        public string $id,
        public string $fileName,
        public string $mimeType,
        public AssetStatus $status,
    ) {
    }

    public static function fromAsset(Asset $asset): self
    {
        return new self(
            id: (string) $asset->getId(),
            fileName: $asset->getFileName(),
            mimeType: $asset->getMimeType(),
            status: $asset->getStatus(),
        );
    }
}
