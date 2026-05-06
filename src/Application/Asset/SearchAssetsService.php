<?php

declare(strict_types=1);

namespace App\Application\Asset;

use App\Application\Asset\Command\SearchAssetsQuery;
use App\Application\Asset\Result\SearchAssetsFile;
use App\Application\Asset\Result\SearchAssetsPageInfo;
use App\Application\Asset\Result\SearchAssetsResult;
use App\Application\Asset\Result\UserError;
use App\Domain\Asset\Asset;
use App\Domain\Asset\AssetRepositoryInterface;
use App\Domain\Asset\AssetStatus;
use App\Domain\Asset\Exception\RepositoryUnavailableException;
use App\Domain\Asset\ValueObject\AccountId;

final class SearchAssetsService
{
    private const EMPTY_QUERY_CODE = 'EMPTY_QUERY';
    private const EMPTY_QUERY_MESSAGE = 'Enter a file name to search.';
    private const REPOSITORY_FAILURE_REASON = 'Repository failure';

    public function __construct(
        private readonly AssetRepositoryInterface $assets,
    ) {
    }

    public function searchAssets(SearchAssetsQuery $query): SearchAssetsResult
    {
        $trimmedQuery = trim($query->query);
        $pageInfo = SearchAssetsPageInfo::fromTotalCount($query->page, $query->pageSize, 0);

        if ($trimmedQuery === '') {
            return new SearchAssetsResult(
                files: [],
                totalCount: 0,
                pageInfo: $pageInfo,
                userErrors: [new UserError(self::EMPTY_QUERY_CODE, self::EMPTY_QUERY_MESSAGE, 'query')],
            );
        }

        $accountId = new AccountId($query->accountId);

        try {
            $totalCount = $this->assets->countByFileName($accountId, $trimmedQuery, AssetStatus::UPLOADED);
            $pageInfo = SearchAssetsPageInfo::fromTotalCount($query->page, $query->pageSize, $totalCount);

            if ($totalCount === 0) {
                return new SearchAssetsResult(
                    files: [],
                    totalCount: 0,
                    pageInfo: $pageInfo,
                    userErrors: [],
                );
            }

            $offset = ($pageInfo->page - 1) * $pageInfo->pageSize;

            $assets = $this->assets->searchByFileName(
                $accountId,
                $trimmedQuery,
                AssetStatus::UPLOADED,
                $offset,
                $pageInfo->pageSize,
            );
        } catch (\Throwable $exception) {
            throw RepositoryUnavailableException::forReason(self::REPOSITORY_FAILURE_REASON, $exception);
        }

        return new SearchAssetsResult(
            files: array_map(
                static fn (Asset $asset): SearchAssetsFile => SearchAssetsFile::fromAsset($asset),
                $assets,
            ),
            totalCount: $totalCount,
            pageInfo: $pageInfo,
            userErrors: [],
        );
    }
}
