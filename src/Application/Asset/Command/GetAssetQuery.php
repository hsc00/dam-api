<?php

declare(strict_types=1);

namespace App\Application\Asset\Command;

final readonly class GetAssetQuery
{
    public function __construct(
        public string $accountId,
        public string $assetId,
    ) {
    }
}
