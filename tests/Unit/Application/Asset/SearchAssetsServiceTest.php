<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Asset;

use App\Application\Asset\Command\SearchAssetsQuery;
use App\Application\Asset\Result\SearchAssetsFile;
use App\Application\Asset\Result\SearchAssetsPageInfo;
use App\Application\Asset\SearchAssetsService;
use App\Domain\Asset\Asset;
use App\Domain\Asset\AssetRepositoryInterface;
use App\Domain\Asset\AssetStatus;
use App\Domain\Asset\Exception\RepositoryUnavailableException;
use App\Domain\Asset\ValueObject\AccountId;
use App\Domain\Asset\ValueObject\UploadCompletionProofValue;
use App\Domain\Asset\ValueObject\UploadId;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class SearchAssetsServiceTest extends TestCase
{
    private AssetRepositoryInterface&MockObject $assets;
    private SearchAssetsService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assets = $this->createMock(AssetRepositoryInterface::class);
        $this->service = new SearchAssetsService($this->assets);
    }

    #[Test]
    public function itReturnsAUserErrorAndSkipsRepositorySearchWhenTheTrimmedQueryIsEmpty(): void
    {
        // Arrange
        $this->assets
            ->expects($this->never())
            ->method('countByFileName');

        $this->assets
            ->expects($this->never())
            ->method('searchByFileName');

        // Act
        $result = $this->service->searchAssets(new SearchAssetsQuery('account-123', " \n\t ", 1, 10));

        // Assert
        self::assertSame([], $result->files);
        self::assertSame(0, $result->totalCount);
        self::assertSame(1, $result->pageInfo->page);
        self::assertSame(10, $result->pageInfo->pageSize);
        self::assertSame(0, $result->pageInfo->totalPages);
        self::assertCount(1, $result->userErrors);
        self::assertSame('EMPTY_QUERY', $result->userErrors[0]->code);
        self::assertSame('Enter a file name to search.', $result->userErrors[0]->message);
        self::assertSame('query', $result->userErrors[0]->field);
    }

    #[Test]
    public function itReturnsPaginatedResultsWhenSearchingByFileName(): void
    {
        // Arrange
        $uploadedNewest = $this->createUploadedAsset('report-final.png');
        $uploadedMiddle = $this->createUploadedAsset('report-summary.png');
        $callOrder = [];

        $this->assets
            ->expects($this->once())
            ->method('countByFileName')
            ->willReturnCallback(static function (AccountId $accountId, string $query, AssetStatus $status) use (&$callOrder): int {
                self::assertSame('account-123', (string) $accountId);
                self::assertSame('report', $query);
                self::assertSame(AssetStatus::UPLOADED, $status);

                $callOrder[] = 'count';

                return 3;
            });

        $this->assets
            ->expects($this->once())
            ->method('searchByFileName')
            ->willReturnCallback(static function (AccountId $accountId, string $query, AssetStatus $status, int $offset, int $limit) use (&$callOrder, $uploadedNewest, $uploadedMiddle): array {
                self::assertSame(['count'], $callOrder, 'countByFileName() should run before searchByFileName().');
                self::assertSame('account-123', (string) $accountId);
                self::assertSame('report', $query);
                self::assertSame(AssetStatus::UPLOADED, $status);
                self::assertSame(0, $offset);
                self::assertSame(2, $limit);

                $callOrder[] = 'search';

                return [$uploadedNewest, $uploadedMiddle];
            });

        // Act
        $result = $this->service->searchAssets(new SearchAssetsQuery('account-123', '  report  ', 1, 2));

        // Assert
        self::assertSame([], $result->userErrors);
        self::assertSame(3, $result->totalCount);
        self::assertSame(1, $result->pageInfo->page);
        self::assertSame(2, $result->pageInfo->pageSize);
        self::assertSame(2, $result->pageInfo->totalPages);
        self::assertSame(['count', 'search'], $callOrder);
        self::assertSame(
            ['report-final.png', 'report-summary.png'],
            array_map(static fn (SearchAssetsFile $file): string => $file->fileName, $result->files),
        );
        self::assertSame(
            [AssetStatus::UPLOADED, AssetStatus::UPLOADED],
            array_map(static fn (SearchAssetsFile $file): AssetStatus => $file->status, $result->files),
        );
    }

    #[Test]
    public function itReturnsCorrectOffsetWhenRequestingSecondPage(): void
    {
        // Arrange
        $uploadedSecond = $this->createUploadedAsset('report-appendix.png');

        $this->assets
            ->expects($this->once())
            ->method('countByFileName')
            ->with(
                $this->callback(static fn (AccountId $accountId): bool => (string) $accountId === 'account-123'),
                'report',
                AssetStatus::UPLOADED,
            )
            ->willReturn(2);

        $this->assets
            ->expects($this->once())
            ->method('searchByFileName')
            ->with(
                $this->callback(static fn (AccountId $accountId): bool => (string) $accountId === 'account-123'),
                'report',
                AssetStatus::UPLOADED,
                1,
                1,
            )
            ->willReturn([$uploadedSecond]);

        // Act
        $result = $this->service->searchAssets(new SearchAssetsQuery('account-123', 'report', 2, 1));

        // Assert
        self::assertSame([], $result->userErrors);
        self::assertSame(2, $result->totalCount);
        self::assertSame(2, $result->pageInfo->page);
        self::assertSame(1, $result->pageInfo->pageSize);
        self::assertSame(2, $result->pageInfo->totalPages);
        self::assertCount(1, $result->files);
        self::assertSame('report-appendix.png', $result->files[0]->fileName);
        self::assertSame(AssetStatus::UPLOADED, $result->files[0]->status);
    }

    #[Test]
    public function itReturnsAnEmptyResultAndSkipsRepositorySearchWhenNoAssetsMatch(): void
    {
        // Arrange
        $this->assets
            ->expects($this->once())
            ->method('countByFileName')
            ->with(
                $this->callback(static fn (AccountId $accountId): bool => (string) $accountId === 'account-123'),
                'report',
                AssetStatus::UPLOADED,
            )
            ->willReturn(0);

        $this->assets
            ->expects($this->never())
            ->method('searchByFileName');

        // Act
        $result = $this->service->searchAssets(new SearchAssetsQuery('account-123', '  report  ', 1, 10));

        // Assert
        self::assertSame([], $result->userErrors);
        self::assertSame([], $result->files);
        self::assertSame(0, $result->totalCount);
        self::assertSame(1, $result->pageInfo->page);
        self::assertSame(10, $result->pageInfo->pageSize);
        self::assertSame(0, $result->pageInfo->totalPages);
    }

    #[Test]
    public function itReturnsMaxPageSizeWhenRequestedSizeExceedsLimit(): void
    {
        // Arrange
        $matchingAssets = array_map(
            fn (int $index): Asset => $this->createUploadedAsset(sprintf('report-%02d.png', $index)),
            range(1, SearchAssetsPageInfo::MAX_PAGE_SIZE + 1),
        );

        $this->assets
            ->expects($this->once())
            ->method('countByFileName')
            ->willReturn(SearchAssetsPageInfo::MAX_PAGE_SIZE + 1);

        $this->assets
            ->expects($this->once())
            ->method('searchByFileName')
            ->with(
                $this->callback(static fn (AccountId $accountId): bool => (string) $accountId === 'account-123'),
                'report',
                AssetStatus::UPLOADED,
                0,
                SearchAssetsPageInfo::MAX_PAGE_SIZE,
            )
            ->willReturn(array_slice($matchingAssets, 0, SearchAssetsPageInfo::MAX_PAGE_SIZE));

        // Act
        $result = $this->service->searchAssets(new SearchAssetsQuery(
            'account-123',
            'report',
            1,
            SearchAssetsPageInfo::MAX_PAGE_SIZE + 1,
        ));

        // Assert
        self::assertSame(SearchAssetsPageInfo::MAX_PAGE_SIZE + 1, $result->totalCount);
        self::assertSame(1, $result->pageInfo->page);
        self::assertSame(SearchAssetsPageInfo::MAX_PAGE_SIZE, $result->pageInfo->pageSize);
        self::assertSame(2, $result->pageInfo->totalPages);
        self::assertCount(SearchAssetsPageInfo::MAX_PAGE_SIZE, $result->files);
    }

    #[Test]
    public function itTranslatesRepositoryFailuresAtTheApplicationBoundary(): void
    {
        // Arrange
        $failure = new \RuntimeException('mysql unavailable');
        $this->assets
            ->expects($this->once())
            ->method('countByFileName')
            ->willReturn(1);

        $this->assets
            ->expects($this->once())
            ->method('searchByFileName')
            ->willThrowException($failure);

        // Act
        try {
            $this->service->searchAssets(new SearchAssetsQuery('account-123', 'report', 1, 10));

            self::fail('Expected searchAssets() to throw when the repository search fails.');
        } catch (RepositoryUnavailableException $exception) {
            // Assert
            self::assertSame('Repository failure', $exception->getMessage());
            self::assertSame($failure, $exception->getPrevious());
        }
    }

    #[Test]
    public function itTranslatesRepositoryCountFailuresAtTheApplicationBoundary(): void
    {
        // Arrange
        $failure = new \RuntimeException('mysql unavailable during count');
        $this->assets
            ->expects($this->once())
            ->method('countByFileName')
            ->willThrowException($failure);

        $this->assets
            ->expects($this->never())
            ->method('searchByFileName');

        // Act
        try {
            $this->service->searchAssets(new SearchAssetsQuery('account-123', 'report', 1, 10));

            self::fail('Expected searchAssets() to throw when the repository count fails.');
        } catch (RepositoryUnavailableException $exception) {
            // Assert
            self::assertSame('Repository failure', $exception->getMessage());
            self::assertSame($failure, $exception->getPrevious());
        }
    }

    #[Test]
    public function itThrowsInvalidArgumentExceptionWhenAccountIdIsEmpty(): void
    {
        // Arrange
        $this->assets
            ->expects($this->never())
            ->method('countByFileName');

        $this->assets
            ->expects($this->never())
            ->method('searchByFileName');

        // Act
        $result = $this->service->searchAssets(new SearchAssetsQuery(" \n\t ", 'report', 1, 10));

        // Assert
        self::assertSame([], $result->files);
        self::assertSame(0, $result->totalCount);
        self::assertSame(1, $result->pageInfo->page);
        self::assertSame(10, $result->pageInfo->pageSize);
        self::assertSame(0, $result->pageInfo->totalPages);
        self::assertCount(1, $result->userErrors);
        self::assertSame('INVALID_ACCOUNT_ID', $result->userErrors[0]->code);
        self::assertSame('AccountId cannot be empty', $result->userErrors[0]->message);
        self::assertSame('accountId', $result->userErrors[0]->field);
    }

    private function createPendingAsset(string $fileName, string $accountId = 'account-123'): Asset
    {
        return Asset::createPending(
            UploadId::generate(),
            new AccountId($accountId),
            $fileName,
            'image/png',
        );
    }

    private function createUploadedAsset(string $fileName, string $accountId = 'account-123'): Asset
    {
        $asset = $this->createPendingAsset($fileName, $accountId);
        $asset->markUploaded(new UploadCompletionProofValue('etag-uploaded'));

        return $asset;
    }
}
