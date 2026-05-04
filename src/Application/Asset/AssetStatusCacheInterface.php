<?php

declare(strict_types=1);

namespace App\Application\Asset;

use App\Domain\Asset\AssetStatus;
use App\Domain\Asset\ValueObject\AssetId;

interface AssetStatusCacheInterface
{
    public function lookup(AssetId $assetId): ?AssetStatus;
    public function store(AssetId $assetId, AssetStatus $status): void;
}
