<?php

declare(strict_types=1);

namespace App\Application\Asset\Result;

final readonly class SearchAssetsPageInfo
{
    public const MAX_PAGE_SIZE = 50;

    public function __construct(
        public int $page,
        public int $pageSize,
        public int $totalPages,
    ) {
    }

    public static function fromTotalCount(int $page, int $pageSize, int $totalCount): self
    {
        $resolvedPage = max(1, $page);
        $resolvedPageSize = min(self::MAX_PAGE_SIZE, max(1, $pageSize));
        $totalPages = $totalCount === 0
            ? 0
            : intdiv($totalCount + $resolvedPageSize - 1, $resolvedPageSize);

        return new self($resolvedPage, $resolvedPageSize, $totalPages);
    }
}
