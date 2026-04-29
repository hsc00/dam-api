<?php

declare(strict_types=1);

namespace App\Application\Asset;

use App\Domain\Asset\ValueObject\AssetId;

interface AssetProcessingJobDispatcherInterface
{
    public function dispatch(AssetId $assetId): void;
}
