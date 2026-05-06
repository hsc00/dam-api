<?php

declare(strict_types=1);

namespace App\GraphQL\Resolver;

use App\Application\Asset\Command\SearchAssetsQuery;
use App\Application\Asset\Result\SearchAssetsFile;
use App\Application\Asset\Result\SearchAssetsPageInfo;
use App\Application\Asset\Result\SearchAssetsResult;
use App\Application\Asset\Result\UserError;
use App\Application\Asset\SearchAssetsService;
use App\GraphQL\Exception\MissingAccountContextException;

final class SearchAssetsResolver
{
    private const DEFAULT_PAGE = 1;
    private const DEFAULT_PAGE_SIZE = 10;

    public function __construct(
        private readonly SearchAssetsService $searchAssetsService,
    ) {
    }

    /**
     * @param array<string, mixed> $args
     *
     * @return array{
     *     files: list<array{id: string, fileName: string, mimeType: string, status: string}>,
     *     totalCount: int,
     *     pageInfo: array{page: int, pageSize: int, totalPages: int},
     *     userErrors: list<array{code: string, message: string, field: string|null}>
     * }
     */
    public function resolve(array $args, mixed $context): array
    {
        $result = $this->searchAssetsService->searchAssets(
            new SearchAssetsQuery(
                accountId: $this->accountId($context),
                query: is_string($args['query'] ?? null) ? $args['query'] : '',
                page: is_int($args['page'] ?? null) ? $args['page'] : self::DEFAULT_PAGE,
                pageSize: is_int($args['pageSize'] ?? null) ? $args['pageSize'] : self::DEFAULT_PAGE_SIZE,
            ),
        );

        return $this->mapResult($result);
    }

    private function accountId(mixed $context): string
    {
        if (is_array($context) && isset($context['accountId']) && is_string($context['accountId'])) {
            return $context['accountId'];
        }

        throw MissingAccountContextException::missing();
    }

    /**
     * @return array{
     *     files: list<array{id: string, fileName: string, mimeType: string, status: string}>,
     *     totalCount: int,
     *     pageInfo: array{page: int, pageSize: int, totalPages: int},
     *     userErrors: list<array{code: string, message: string, field: string|null}>
     * }
     */
    private function mapResult(SearchAssetsResult $result): array
    {
        return [
            'files' => array_map(
                fn (SearchAssetsFile $file): array => $this->mapFile($file),
                $result->files,
            ),
            'totalCount' => $result->totalCount,
            'pageInfo' => $this->mapPageInfo($result->pageInfo),
            'userErrors' => array_map(
                fn (UserError $userError): array => $this->mapUserError($userError),
                $result->userErrors,
            ),
        ];
    }

    /**
     * @return array{id: string, fileName: string, mimeType: string, status: string}
     */
    private function mapFile(SearchAssetsFile $file): array
    {
        return [
            'id' => $file->id,
            'fileName' => $file->fileName,
            'mimeType' => $file->mimeType,
            'status' => $file->status->value,
        ];
    }

    /**
     * @return array{page: int, pageSize: int, totalPages: int}
     */
    private function mapPageInfo(SearchAssetsPageInfo $pageInfo): array
    {
        return [
            'page' => $pageInfo->page,
            'pageSize' => $pageInfo->pageSize,
            'totalPages' => $pageInfo->totalPages,
        ];
    }

    /**
     * @return array{code: string, message: string, field: string|null}
     */
    private function mapUserError(UserError $userError): array
    {
        return [
            'code' => $userError->code,
            'message' => $userError->message,
            'field' => $userError->field,
        ];
    }
}
