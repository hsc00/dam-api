<?php

declare(strict_types=1);

namespace App\Tests\Integration\Infrastructure\Persistence;

use App\Domain\Asset\Asset;
use App\Domain\Asset\AssetRepositoryInterface;
use App\Domain\Asset\AssetStatus;
use App\Domain\Asset\ValueObject\AccountId;
use App\Domain\Asset\ValueObject\AssetId;
use App\Domain\Asset\ValueObject\UploadCompletionProofValue;
use App\Domain\Asset\ValueObject\UploadId;
use App\Infrastructure\Persistence\MySQLAssetRepository;
use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

final class MySQLAssetRepositoryTest extends BaseMySQLAssetRepositoryTestCase
{
    private const CREATED_AT_2026_04_01_1300 = '2026-04-01 13:00:00.000000';
    private const PADDED_REPORT_QUERY = '  repORT  ';

    #[Test]
    public function itReturnsAssetWhenSavingAndReadingAPendingAsset(): void
    {
        $this->withTemporaryDatabase(function (PDO $connection): void {
            // Arrange
            $repository = $this->createRepository($connection);
            $asset = $this->pendingAsset(
                assetId: '11111111-1111-4111-8111-111111111111',
                uploadId: '22222222-2222-4222-8222-222222222222',
                accountId: 'account-pending',
                fileName: 'Pending-image.PNG',
                createdAt: '2026-04-01 10:00:00.000000',
            );

            // Act
            $repository->save($asset);

            // Assert
            $this->assertFoundByIdAndUploadId($repository, $asset);
        });
    }

    #[Test]
    public function itReturnsAssetWhenSavingAndReadingAPendingAssetWithMultipleChunks(): void
    {
        $this->withTemporaryDatabase(function (PDO $connection): void {
            // Arrange
            $repository = new MySQLAssetRepository($connection);
            $asset = $this->pendingAsset(
                assetId: '12121212-1212-4121-8121-121212121212',
                uploadId: '34343434-3434-4434-8434-343434343434',
                accountId: 'account-pending-chunks',
                fileName: 'chunked-pending-image.png',
                createdAt: '2026-04-01 10:15:00.000000',
                chunkCount: 5,
            );

            // Act
            $repository->save($asset);
            $persistedAsset = $repository->findByUploadId($asset->getUploadId());

            // Assert
            self::assertNotNull($persistedAsset);
            self::assertSame(5, $persistedAsset->getChunkCount());
            $this->assertAssetMatches($asset, $persistedAsset);
        });
    }

    #[Test]
    public function itReturnsAssetWhenSavingAndReadingAnUploadedAsset(): void
    {
        $this->withTemporaryDatabase(function (PDO $connection): void {
            // Arrange
            $repository = $this->createRepository($connection);
            $asset = $this->uploadedAsset(
                assetId: '33333333-3333-4333-8333-333333333333',
                uploadId: '44444444-4444-4444-8444-444444444444',
                accountId: 'account-uploaded',
                fileName: 'uploaded-image.png',
                completionProof: 'etag-uploaded-asset',
                persistedState: $this->persistedState('2026-04-01 09:00:00.000000', 3, '2026-04-01 09:05:00.000000'),
            );

            // Act
            $repository->save($asset);

            // Assert
            $this->assertFoundByIdAndUploadId($repository, $asset);
        });
    }

    #[Test]
    public function itReturnsUpdatedRowWhenAssetStateChanges(): void
    {
        $this->withTemporaryDatabase(function (PDO $connection): void {
            // Arrange
            $repository = $this->createRepository($connection);
            $asset = $this->pendingAsset(
                assetId: '55555555-5555-4555-8555-555555555555',
                uploadId: '66666666-6666-4666-8666-666666666666',
                accountId: 'account-state-change',
                fileName: 'asset-before-upload.png',
                createdAt: '2020-01-01 12:00:00.000000',
            );
            $repository->save($asset);
            $asset->markProcessing(new UploadCompletionProofValue('etag-after-upload'));

            // Act
            $repository->save($asset);
            $persistedAsset = $repository->findById($asset->getId());

            // Assert
            $this->assertPersistedSingleRowMatches($connection, $asset, $persistedAsset);
        });
    }

    #[Test]
    public function itReturnsUpdatedRowWhenProcessingAssetTransitionsToUploaded(): void
    {
        $this->withTemporaryDatabase(function (PDO $connection): void {
            // Arrange
            $repository = $this->createRepository($connection);
            $asset = $this->processingAsset(
                assetId: '57575757-5757-4575-8575-575757575757',
                uploadId: '68686868-6868-4686-8686-686868686868',
                accountId: 'account-processing-uploaded',
                fileName: 'processed-asset.png',
                completionProof: 'etag-processing-uploaded',
                persistedState: $this->persistedState('2026-04-01 12:05:00.000000', 2, '2026-04-01 12:10:00.000000'),
            );
            $completionProof = $asset->getCompletionProof();
            self::assertNotNull($completionProof);
            $repository->save($asset);
            $asset->markUploaded($completionProof);

            // Act
            $repository->save($asset);
            $persistedAsset = $repository->findById($asset->getId());

            // Assert
            $this->assertPersistedSingleRowMatches($connection, $asset, $persistedAsset);
        });
    }

    #[Test]
    public function itReturnsUpdatedRowWhenProcessingAssetTransitionsBackToPending(): void
    {
        $this->withTemporaryDatabase(function (PDO $connection): void {
            // Arrange
            $repository = $this->createRepository($connection);
            $asset = $this->processingAsset(
                assetId: '58585858-5858-4585-8585-585858585858',
                uploadId: '69696969-6969-4696-8696-696969696969',
                accountId: 'account-processing-pending',
                fileName: 'processing-to-pending.png',
                completionProof: 'etag-processing-pending',
                persistedState: $this->persistedState('2026-04-01 12:12:00.000000', 3, '2026-04-01 12:18:00.000000'),
            );
            $repository->save($asset);
            $asset->restorePending();

            // Act
            $repository->save($asset);
            $persistedAsset = $repository->findById($asset->getId());

            // Assert
            self::assertNull($asset->getCompletionProof());
            $this->assertPersistedSingleRowMatches($connection, $asset, $persistedAsset);
        });
    }

    #[Test]
    public function itReturnsUpdatedRowWhenProcessingAssetTransitionsToFailed(): void
    {
        $this->withTemporaryDatabase(function (PDO $connection): void {
            // Arrange
            $repository = $this->createRepository($connection);
            $asset = $this->processingAsset(
                assetId: '59595959-5959-4595-8595-595959595959',
                uploadId: '6a6a6a6a-6a6a-46a6-86a6-6a6a6a6a6a6a',
                accountId: 'account-processing-failed',
                fileName: 'failed-after-processing.png',
                completionProof: 'etag-processing-failed',
                persistedState: $this->persistedState('2026-04-01 12:15:00.000000', 4, '2026-04-01 12:20:00.000000'),
            );
            $repository->save($asset);
            $asset->markFailed();

            // Act
            $repository->save($asset);
            $persistedAsset = $repository->findById($asset->getId());

            // Assert
            self::assertNull($asset->getCompletionProof());
            $this->assertPersistedSingleRowMatches($connection, $asset, $persistedAsset);
        });
    }

    #[Test]
    public function itReturnsAcceptedStateWhenUpdatedAtRemainsEqual(): void
    {
        $this->withTemporaryDatabase(function (PDO $connection): void {
            // Arrange
            $repository = $this->createRepository($connection);
            $asset = $this->pendingAsset(
                assetId: '56565656-5656-4565-8565-565656565656',
                uploadId: '67676767-6767-4676-8676-676767676767',
                accountId: 'account-equal-updated-at',
                fileName: 'equal-updated-at.png',
                createdAt: '9999-01-01 00:00:00.000000',
            );
            $originalUpdatedAt = $asset->getUpdatedAt()->format(self::DATETIME_FORMAT);
            $repository->save($asset);
            $asset->markProcessing(new UploadCompletionProofValue('etag-equal-updated-at'));

            // Act
            $repository->save($asset);
            $persistedAsset = $repository->findById($asset->getId());

            // Assert
            self::assertSame($originalUpdatedAt, $asset->getUpdatedAt()->format(self::DATETIME_FORMAT));
            $this->assertPersistedSingleRowMatches($connection, $asset, $persistedAsset);
        });
    }

    #[Test]
    public function itReturnsSingleRowWhenSavingUnchangedAssetTwice(): void
    {
        $this->withTemporaryDatabase(function (PDO $connection): void {
            // Arrange
            $repository = $this->createRepository($connection);
            $asset = $this->pendingAsset(
                assetId: '77777777-0000-4777-8777-777777777777',
                uploadId: '88888888-0000-4888-8888-888888888888',
                accountId: 'account-idempotent-save',
                fileName: 'same-asset.png',
                createdAt: '2026-04-01 12:30:00.000000',
            );

            // Act
            $repository->save($asset);
            $repository->save($asset);
            $persistedAsset = $repository->findById($asset->getId());

            // Assert
            $this->assertPersistedSingleRowMatches($connection, $asset, $persistedAsset);
        });
    }

    #[Test]
    public function itReturnsNullWhenAnAssetIsMissing(): void
    {
        $this->withTemporaryDatabase(function (PDO $connection): void {
            // Arrange
            $repository = $this->createRepository($connection);

            // Act
            $missingAsset = $repository->findById(new AssetId('77777777-7777-4777-8777-777777777777'));
            $missingUpload = $repository->findByUploadId(new UploadId('88888888-8888-4888-8888-888888888888'));

            // Assert
            self::assertNull($missingAsset);
            self::assertNull($missingUpload);
        });
    }

    #[Test]
    public function itReturnsMatchingAssetsWhenSearchingByFileNameWithinAccountAndStatusUsingDeterministicOrdering(): void
    {
        $this->withTemporaryDatabase(function (PDO $connection): void {
            // Arrange
            $repository = $this->createRepository($connection);
            $accountId = new AccountId('account-search');
            $expectedFirst = $this->uploadedAsset(
                assetId: '99999999-9999-4999-8999-999999999999',
                uploadId: 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
                accountId: (string) $accountId,
                fileName: 'Quarterly Report.pdf',
                completionProof: 'etag-quarterly-report',
                persistedState: $this->persistedState('2026-04-01 14:00:00.000000', 1, '2026-04-01 14:05:00.000000'),
            );
            $expectedSecond = $this->uploadedAsset(
                assetId: '11111111-aaaa-4111-8111-111111111111',
                uploadId: 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb',
                accountId: (string) $accountId,
                fileName: 'report-draft.png',
                completionProof: 'etag-report-draft',
                persistedState: $this->persistedState(self::CREATED_AT_2026_04_01_1300, 1, '2026-04-01 13:05:00.000000'),
            );
            $expectedThird = $this->uploadedAsset(
                assetId: '22222222-bbbb-4222-8222-222222222222',
                uploadId: 'cccccccc-cccc-4ccc-8ccc-cccccccccccc',
                accountId: (string) $accountId,
                fileName: 'REPORT-appendix.png',
                completionProof: 'etag-report-appendix',
                persistedState: $this->persistedState(self::CREATED_AT_2026_04_01_1300, 1, '2026-04-01 13:05:00.000000'),
            );
            $sameAccountStatusMismatch = $this->processingAsset(
                assetId: '23232323-bcbc-4232-8232-232323232323',
                uploadId: 'cdcdcdcd-cdcd-4dcd-8dcd-cdcdcdcdcdcd',
                accountId: (string) $accountId,
                fileName: 'report-processing.png',
                completionProof: 'etag-report-processing',
                persistedState: $this->persistedState('2026-04-01 15:00:00.000000', 1, '2026-04-01 15:05:00.000000'),
            );
            $sameAccountNonMatch = $this->pendingAsset(
                assetId: '33333333-cccc-4333-8333-333333333333',
                uploadId: 'dddddddd-dddd-4ddd-8ddd-dddddddddddd',
                accountId: (string) $accountId,
                fileName: 'invoice.png',
                createdAt: '2026-04-01 15:00:00.000000',
            );
            $otherAccountMatch = $this->pendingAsset(
                assetId: '44444444-dddd-4444-8444-444444444444',
                uploadId: 'eeeeeeee-eeee-4eee-8eee-eeeeeeeeeeee',
                accountId: 'account-other',
                fileName: 'external-report.png',
                createdAt: '2026-04-01 16:00:00.000000',
            );

            $repository->save($sameAccountStatusMismatch);
            $repository->save($expectedThird);
            $repository->save($otherAccountMatch);
            $repository->save($expectedFirst);
            $repository->save($sameAccountNonMatch);
            $repository->save($expectedSecond);

            // Act
            $totalCount = $repository->countByFileName($accountId, self::PADDED_REPORT_QUERY, AssetStatus::UPLOADED);
            $results = $repository->searchByFileName($accountId, self::PADDED_REPORT_QUERY, AssetStatus::UPLOADED, 0, 10);
            $pagedResults = $repository->searchByFileName($accountId, self::PADDED_REPORT_QUERY, AssetStatus::UPLOADED, 1, 1);

            // Assert
            self::assertSame(3, $totalCount);
            self::assertCount(3, $results);
            $this->assertAssetMatches($expectedFirst, $results[0]);
            $this->assertAssetMatches($expectedSecond, $results[1]);
            $this->assertAssetMatches($expectedThird, $results[2]);
            self::assertCount(1, $pagedResults);
            $this->assertAssetMatches($expectedSecond, $pagedResults[0]);
        });
    }

    #[Test]
    public function itTreatsPercentAndUnderscoreSearchTermsAsLiterals(): void
    {
        $this->withTemporaryDatabase(function (PDO $connection): void {
            // Arrange
            $repository = $this->createRepository($connection);
            $accountId = new AccountId('account-literal-like');
            $percentMatch = $this->uploadedAsset(
                assetId: '77777777-7777-4777-8777-777777777777',
                uploadId: '78787878-7878-4787-8787-787878787878',
                accountId: (string) $accountId,
                fileName: 'budget 100% complete.pdf',
                completionProof: 'etag-budget-percent',
                persistedState: $this->persistedState('2026-04-01 12:00:00.000000', 1, '2026-04-01 12:05:00.000000'),
            );
            $percentWildcardFalsePositive = $this->uploadedAsset(
                assetId: '88888888-8888-4888-8888-888888888888',
                uploadId: '89898989-8989-4898-8989-898989898989',
                accountId: (string) $accountId,
                fileName: 'budget 100 percent complete.pdf',
                completionProof: 'etag-budget-plain',
                persistedState: $this->persistedState('2026-04-01 12:10:00.000000', 1, '2026-04-01 12:15:00.000000'),
            );
            $underscoreMatch = $this->uploadedAsset(
                assetId: '99999999-aaaa-4999-8999-999999999999',
                uploadId: '9a9a9a9a-9a9a-49a9-89a9-9a9a9a9a9a9a',
                accountId: (string) $accountId,
                fileName: 'report_2026.pdf',
                completionProof: 'etag-report-underscore',
                persistedState: $this->persistedState('2026-04-01 12:20:00.000000', 1, '2026-04-01 12:25:00.000000'),
            );
            $underscoreWildcardFalsePositive = $this->uploadedAsset(
                assetId: 'aaaaaaaa-bbbb-4aaa-8aaa-aaaaaaaaaaaa',
                uploadId: 'abababab-abab-4bab-8bab-abababababab',
                accountId: (string) $accountId,
                fileName: 'report-2026.pdf',
                completionProof: 'etag-report-hyphen',
                persistedState: $this->persistedState('2026-04-01 12:30:00.000000', 1, '2026-04-01 12:35:00.000000'),
            );
            $otherAccountLiteral = $this->uploadedAsset(
                assetId: 'bbbbbbbb-cccc-4bbb-8bbb-bbbbbbbbbbbb',
                uploadId: 'bcbcbcbc-bcbc-4cbc-8cbc-bcbcbcbcbcbc',
                accountId: 'account-other-literal-like',
                fileName: 'shared_2026%.pdf',
                completionProof: 'etag-other-account-literal',
                persistedState: $this->persistedState('2026-04-01 12:40:00.000000', 1, '2026-04-01 12:45:00.000000'),
            );

            $repository->save($percentMatch);
            $repository->save($percentWildcardFalsePositive);
            $repository->save($underscoreMatch);
            $repository->save($underscoreWildcardFalsePositive);
            $repository->save($otherAccountLiteral);

            // Act
            $percentCount = $repository->countByFileName($accountId, '%', AssetStatus::UPLOADED);
            $percentResults = $repository->searchByFileName($accountId, '%', AssetStatus::UPLOADED, 0, 10);
            $underscoreCount = $repository->countByFileName($accountId, '_', AssetStatus::UPLOADED);
            $underscoreResults = $repository->searchByFileName($accountId, '_', AssetStatus::UPLOADED, 0, 10);

            // Assert
            self::assertSame(1, $percentCount);
            self::assertCount(1, $percentResults);
            $this->assertAssetMatches($percentMatch, $percentResults[0]);
            self::assertSame(1, $underscoreCount);
            self::assertCount(1, $underscoreResults);
            $this->assertAssetMatches($underscoreMatch, $underscoreResults[0]);
        });
    }

    #[Test]
    public function itReturnsAnEmptyListWhenSearchQueryIsEmptyAfterTrimming(): void
    {
        $this->withTemporaryDatabase(function (PDO $connection): void {
            // Arrange
            $repository = $this->createRepository($connection);

            // Act
            $totalCount = $repository->countByFileName(new AccountId('account-empty-search'), " \n\t ", AssetStatus::UPLOADED);
            $results = $repository->searchByFileName(new AccountId('account-empty-search'), " \n\t ", AssetStatus::UPLOADED, 0, 10);

            // Assert
            self::assertSame(0, $totalCount);
            self::assertSame([], $results);
        }, false);
    }

    #[Test]
    #[DataProvider('searchPaginationBoundaryProvider')]
    public function itHandlesSearchPaginationBoundaries(
        int $offset,
        int $limit,
        bool $expectException,
        ?string $exceptionClass,
        ?string $exceptionMessage,
        bool $needsAsset,
    ): void {
        $this->withTemporaryDatabase(function (PDO $connection) use ($offset, $limit, $expectException, $exceptionClass, $exceptionMessage, $needsAsset): void {
            // Arrange
            $repository = $this->createRepository($connection);
            $accountId = new AccountId('account-pagination-boundary');

            if ($needsAsset) {
                $asset = $this->uploadedAsset(
                    assetId: 'cccccccc-dddd-4ccc-8ccc-cccccccccccc',
                    uploadId: 'cdcdcdcd-dede-4dcd-8dcd-cdcdcdcdcdcd',
                    accountId: (string) $accountId,
                    fileName: 'report.pdf',
                    completionProof: 'etag-report',
                    persistedState: $this->persistedState('2026-04-01 17:00:00.000000', 1, '2026-04-01 17:05:00.000000'),
                );
                $repository->save($asset);
            }

            if ($expectException) {
                // Assert
                assert(is_string($exceptionClass));
                assert(is_string($exceptionMessage));
                /** @var class-string<\Throwable> $exceptionClass */
                /** @var string $exceptionMessage */

                // Assert
                $this->expectException($exceptionClass);
                $this->expectExceptionMessage($exceptionMessage);

                // Act
                $repository->searchByFileName($accountId, 'report', AssetStatus::UPLOADED, $offset, $limit);

                return;
            }

            // Act
            $results = $repository->searchByFileName($accountId, 'report', AssetStatus::UPLOADED, $offset, $limit);

            // Assert
            self::assertSame([], $results);
        });
    }

    /**
     * @return array<string, array{int, int, bool, ?class-string<\Throwable>, ?string, bool}>
     */
    public static function searchPaginationBoundaryProvider(): array
    {
        return [
            'limit-zero' => [0, 0, false, null, null, true],
            'offset-exceeds-total' => [5, 10, false, null, null, true],
            'negative-offset' => [-1, 10, true, \InvalidArgumentException::class, 'Search offset cannot be negative.', false],
            'negative-limit' => [0, -1, true, \InvalidArgumentException::class, 'Search limit cannot be negative.', false],
        ];
    }

    #[Test]
    public function itThrowsPdoExceptionWhenDifferentAssetReusesExistingUploadId(): void
    {
        $this->withTemporaryDatabase(function (PDO $connection): void {
            // Arrange
            $repository = $this->createRepository($connection);
            $existingAsset = $this->pendingAsset(
                assetId: '55555555-eeee-4555-8555-555555555555',
                uploadId: 'ffffffff-ffff-4fff-8fff-ffffffffffff',
                accountId: 'account-duplicate-upload',
                fileName: 'existing.png',
                createdAt: '2026-04-01 11:00:00.000000',
            );
            $duplicateUploadIdAsset = $this->pendingAsset(
                assetId: '66666666-ffff-4666-8666-666666666666',
                uploadId: (string) $existingAsset->getUploadId(),
                accountId: 'account-duplicate-upload',
                fileName: 'replacement.png',
                createdAt: '2026-04-01 11:05:00.000000',
            );
            $repository->save($existingAsset);
            $this->expectException(PDOException::class);

            // Act
            try {
                $repository->save($duplicateUploadIdAsset);
            } finally {
                // Assert
                $persistedAsset = $repository->findByUploadId($existingAsset->getUploadId());

                $this->assertPersistedSingleRowMatches($connection, $existingAsset, $persistedAsset);
                self::assertNull($repository->findById($duplicateUploadIdAsset->getId()));
            }
        });
    }

    private function createRepository(PDO $connection): AssetRepositoryInterface
    {
        return new MySQLAssetRepository($connection);
    }

    private function assertPersistedSingleRowMatches(PDO $connection, Asset $expectedAsset, ?Asset $actualAsset): void
    {
        self::assertNotNull($actualAsset);
        self::assertSame(1, $this->countAssets($connection));
        $this->assertAssetMatches($expectedAsset, $actualAsset);
    }

    private function assertFoundByIdAndUploadId(AssetRepositoryInterface $repository, Asset $asset): void
    {
        $foundById = $repository->findById($asset->getId());
        $foundByUploadId = $repository->findByUploadId($asset->getUploadId());

        self::assertNotNull($foundById);
        self::assertNotNull($foundByUploadId);
        $this->assertAssetMatches($asset, $foundById);
        $this->assertAssetMatches($asset, $foundByUploadId);
    }
}
