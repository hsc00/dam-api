<?php

declare(strict_types=1);

namespace App\Application\Asset\Command;

final readonly class SearchAssetsQuery
{
    public function __construct(
        public string $accountId,
        public string $query,
        public int $page,
        public int $pageSize,
    ) {
    }
}
