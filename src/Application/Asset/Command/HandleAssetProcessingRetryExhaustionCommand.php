<?php

declare(strict_types=1);

namespace App\Application\Asset\Command;

use App\Domain\Asset\ValueObject\AssetId;

final readonly class HandleAssetProcessingRetryExhaustionCommand
{
    public function __construct(
        public AssetId $assetId,
    ) {
    }
}
