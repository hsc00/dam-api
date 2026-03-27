<?php

declare(strict_types=1);

namespace App\Domain\Asset;

use App\Domain\Asset\ValueObject\AssetId;

interface AssetRepositoryInterface
{
    public function save(Asset $asset): void;

    public function findById(AssetId $assetId): ?Asset;
}
