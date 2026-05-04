<?php

declare(strict_types=1);

namespace App\Infrastructure\Processing;

use App\Application\Asset\AssetStatusCacheInterface;
use App\Domain\Asset\AssetStatus;
use App\Domain\Asset\ValueObject\AssetId;

final class NullAssetStatusCache implements AssetStatusCacheInterface
{
    public function lookup(AssetId $assetId): ?AssetStatus
    {
        return null;
    }

    public function store(AssetId $assetId, AssetStatus $status): void
    {
        // Intentionally no-op: HTTP requests must remain functional when cache
        // population is disabled or Redis is unavailable.
    }
}
