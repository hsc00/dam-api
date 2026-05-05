<?php

declare(strict_types=1);

namespace App\Application\Asset\Result;

use App\Domain\Asset\Asset;
use App\Domain\Asset\AssetStatus;

final readonly class GetAssetResult
{
    public function __construct(
        public string $id,
        public AssetStatus $status,
        public AssetReadSource $readSource,
    ) {
    }

    public static function fromAsset(Asset $asset, AssetStatus $status, AssetReadSource $readSource): self
    {
        return new self(
            id: (string) $asset->getId(),
            status: $status,
            readSource: $readSource,
        );
    }
}
