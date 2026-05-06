<?php

declare(strict_types=1);

namespace App\Application\Asset\Result;

final readonly class SearchAssetsResult
{
    /**
     * @param list<SearchAssetsFile> $files
     * @param list<UserError> $userErrors
     */
    public function __construct(
        public array $files,
        public int $totalCount,
        public SearchAssetsPageInfo $pageInfo,
        public array $userErrors,
    ) {
    }
}
