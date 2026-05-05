<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Asset;

use App\Application\Asset\AssetStatusCacheInterface;
use App\Application\Asset\Command\CompleteUploadCommand;
use App\Application\Asset\CompleteUploadService;
use App\Application\Asset\UploadGrantIssuerInterface;
use App\Application\Outbox\OutboxRepositoryInterface;
use App\Application\Transaction\TransactionManagerInterface;
use App\Domain\Asset\Asset;
use App\Domain\Asset\AssetRepositoryInterface;
use App\Domain\Asset\AssetStatus;
use App\Domain\Asset\Exception\RepositoryUnavailableException;
use App\Domain\Asset\ValueObject\AccountId;
use App\Domain\Asset\ValueObject\AssetId;
use App\Domain\Asset\ValueObject\UploadCompletionProofValue;
use App\Domain\Asset\ValueObject\UploadId;
use App\Infrastructure\Processing\Exception\RedisAssetStatusCacheException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class CompleteUploadServiceTest extends TestCase
{
    private AssetRepositoryInterface&MockObject $assets;
    private AssetStatusCacheInterface&MockObject $assetTerminalStatusCache;
    private TransactionManagerInterface&MockObject $transactionManager;
    private OutboxRepositoryInterface&MockObject $outboxRepository;
    private UploadGrantIssuerInterface&MockObject $uploadGrantIssuer;
    private CompleteUploadService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assets = $this->createMock(AssetRepositoryInterface::class);
        $this->assetTerminalStatusCache = $this->createMock(AssetStatusCacheInterface::class);
        $this->transactionManager = $this->createMock(TransactionManagerInterface::class);
        $this->outboxRepository = $this->createMock(OutboxRepositoryInterface::class);
        $this->uploadGrantIssuer = $this->createMock(UploadGrantIssuerInterface::class);
        $this->service = new CompleteUploadService(
            $this->assets,
            $this->uploadGrantIssuer,
            $this->transactionManager,
            $this->outboxRepository,
            $this->assetTerminalStatusCache,
        );
    }

    #[Test]
    public function itMarksPendingAssetAsProcessingAndDispatchesAProcessingJobWhenGrantAndCompletionProofAreValid(): void
    {
        // Arrange
        $asset = $this->createPendingAsset();
        $savedAssets = [];
        $this->assets
            ->expects($this->once())
            ->method('findById')
            ->willReturn($asset);
        $this->assets
            ->expects($this->once())
            ->method('save')
            ->willReturnCallback(static function (Asset $savedAsset) use (&$savedAssets): void {
                $savedAssets[] = $savedAsset;
            });
        $this->uploadGrantIssuer
            ->expects($this->once())
            ->method('issueForAsset')
            ->with($asset)
            ->willReturn('grant-123');
        $this->transactionManager->expects($this->once())->method('beginTransaction');
        $this->transactionManager->expects($this->once())->method('commit');
        $this->outboxRepository
            ->expects($this->once())
            ->method('enqueue')
            ->with('asset-processing', $this->callback(static function (string $payload) use ($asset): bool {
                /** @var array<string, mixed> $data */
                $data = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);

                return isset($data['assetId']) && $data['assetId'] === (string) $asset->getId();
            }));
        $this->assetTerminalStatusCache
            ->expects($this->once())
            ->method('store')
            ->with($asset->getId(), AssetStatus::PROCESSING);

        // Act
        $result = $this->service->completeUpload(
            new CompleteUploadCommand(
                accountId: 'account-123',
                assetId: (string) $asset->getId(),
                uploadGrant: 'grant-123',
                completionProof: 'etag-123',
            ),
        );

        // Assert
        self::assertNotNull($result->success);
        self::assertSame([], $result->userErrors);
        self::assertSame(AssetStatus::PROCESSING, $result->success->asset['status']);
        self::assertCount(1, $savedAssets);
        self::assertSame(AssetStatus::PROCESSING, $savedAssets[0]->getStatus());
        self::assertSame('etag-123', $savedAssets[0]->getCompletionProof()?->value);
    }

    #[Test]
    public function itReturnsSuccessfulCompletionWhenCachingProcessingStatusFails(): void
    {
        // Arrange
        $asset = $this->createPendingAsset();
        $savedAssets = [];
        $transactionState = new class () {
            public bool $committed = false;
        };
        $this->assets
            ->expects($this->once())
            ->method('findById')
            ->willReturn($asset);
        $this->assets
            ->expects($this->once())
            ->method('save')
            ->willReturnCallback(static function (Asset $savedAsset) use (&$savedAssets): void {
                $savedAssets[] = $savedAsset;
            });
        $this->uploadGrantIssuer
            ->expects($this->once())
            ->method('issueForAsset')
            ->with($asset)
            ->willReturn('grant-123');
        $this->transactionManager->expects($this->once())->method('beginTransaction');
        $this->transactionManager
            ->expects($this->once())
            ->method('commit')
            ->willReturnCallback(static function () use ($transactionState): void {
                $transactionState->committed = true;
            });
        $this->outboxRepository
            ->expects($this->once())
            ->method('enqueue')
            ->with('asset-processing', $this->callback(static function (string $payload) use ($asset): bool {
                /** @var array<string, mixed> $data */
                $data = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);

                return isset($data['assetId']) && $data['assetId'] === (string) $asset->getId();
            }));
        $this->assetTerminalStatusCache
            ->expects($this->once())
            ->method('store')
            ->willReturnCallback(static function (AssetId $assetId, AssetStatus $status) use ($asset, $transactionState): void {
                self::assertTrue($transactionState->committed);
                self::assertSame((string) $asset->getId(), (string) $assetId);
                self::assertSame(AssetStatus::PROCESSING, $status);

                throw RedisAssetStatusCacheException::connectionFailed();
            });

        // Act
        $result = $this->service->completeUpload(
            new CompleteUploadCommand(
                accountId: 'account-123',
                assetId: (string) $asset->getId(),
                uploadGrant: 'grant-123',
                completionProof: 'etag-123',
            ),
        );

        // Assert
        self::assertNotNull($result->success);
        self::assertSame([], $result->userErrors);
        self::assertSame(AssetStatus::PROCESSING, $result->success->asset['status']);
        self::assertCount(1, $savedAssets);
        self::assertSame(AssetStatus::PROCESSING, $savedAssets[0]->getStatus());
    }

    #[Test]
    public function itThrowsWhenOutboxEnqueueFailsAndDoesNotSaveTwice(): void
    {
        // Arrange
        $asset = $this->createPendingAsset();
        $savedAssets = [];
        $this->assets
            ->expects($this->once())
            ->method('findById')
            ->willReturn($asset);
        $this->assets
            ->expects($this->once())
            ->method('save')
            ->willReturnCallback(static function (Asset $savedAsset) use (&$savedAssets): void {
                $savedAssets[] = $savedAsset;
            });
        $this->uploadGrantIssuer
            ->expects($this->once())
            ->method('issueForAsset')
            ->with($asset)
            ->willReturn('grant-123');

        $this->transactionManager->expects($this->once())->method('beginTransaction');
        $this->transactionManager->expects($this->once())->method('rollBack');
        $this->outboxRepository
            ->expects($this->once())
            ->method('enqueue')
            ->willThrowException(new \RuntimeException('db down'));

        // Act
        try {
            $this->service->completeUpload(
                new CompleteUploadCommand(
                    accountId: 'account-123',
                    assetId: (string) $asset->getId(),
                    uploadGrant: 'grant-123',
                    completionProof: 'etag-123',
                ),
            );

            self::fail('Expected completeUpload() to throw when outbox enqueue fails.');
        } catch (RepositoryUnavailableException $exception) {
            // Assert
            self::assertSame('Repository failure', $exception->getMessage());
        }

        self::assertCount(1, $savedAssets);
    }

    #[Test]
    public function itReturnsValidationErrorsWhenAssetIdGrantOrCompletionProofAreInvalid(): void
    {
        // Arrange
        $this->assets
            ->expects($this->never())
            ->method('findById');
        $this->outboxRepository
            ->expects($this->never())
            ->method('enqueue');
        $this->uploadGrantIssuer
            ->expects($this->never())
            ->method('issueForAsset');

        // Act
        $result = $this->service->completeUpload(
            new CompleteUploadCommand(
                accountId: 'account-123',
                assetId: 'not-a-uuid',
                uploadGrant: '   ',
                completionProof: '   ',
            ),
        );

        // Assert
        self::assertNull($result->success);
        self::assertSame(['INVALID_ASSET_ID', 'INVALID_UPLOAD_GRANT', 'INVALID_COMPLETION_PROOF'], array_map(
            static fn ($userError): string => $userError->code,
            $result->userErrors,
        ));
    }

    #[Test]
    public function itReturnsAssetNotFoundWhenAssetDoesNotBelongToTheAccountContext(): void
    {
        // Arrange
        $asset = $this->createPendingAsset('another-account');
        $this->assets
            ->expects($this->once())
            ->method('findById')
            ->willReturn($asset);
        $this->assets
            ->expects($this->never())
            ->method('save');
        $this->outboxRepository
            ->expects($this->never())
            ->method('enqueue');
        $this->uploadGrantIssuer
            ->expects($this->never())
            ->method('issueForAsset');

        // Act
        $result = $this->service->completeUpload(
            new CompleteUploadCommand(
                accountId: 'account-123',
                assetId: (string) $asset->getId(),
                uploadGrant: 'grant-123',
                completionProof: 'etag-123',
            ),
        );

        // Assert
        self::assertNull($result->success);
        self::assertSame('ASSET_NOT_FOUND', $result->userErrors[0]->code);
    }

    #[Test]
    public function itReturnsAssetNotFoundWhenTheAssetDoesNotExist(): void
    {
        // Arrange
        $this->assets
            ->expects($this->once())
            ->method('findById')
            ->willReturn(null);
        $this->assets
            ->expects($this->never())
            ->method('save');
        $this->outboxRepository
            ->expects($this->never())
            ->method('enqueue');
        $this->uploadGrantIssuer
            ->expects($this->never())
            ->method('issueForAsset');

        // Act
        $result = $this->service->completeUpload(
            new CompleteUploadCommand(
                accountId: 'account-123',
                assetId: '123e4567-e89b-42d3-a456-426614174000',
                uploadGrant: 'grant-123',
                completionProof: 'etag-123',
            ),
        );

        // Assert
        self::assertNull($result->success);
        self::assertCount(1, $result->userErrors);
        self::assertSame('ASSET_NOT_FOUND', $result->userErrors[0]->code);
        self::assertSame('Asset not found.', $result->userErrors[0]->message);
        self::assertSame('assetId', $result->userErrors[0]->field);
    }

    #[Test]
    public function itReturnsAUserErrorWhenTheUploadGrantDoesNotMatchTheAsset(): void
    {
        // Arrange
        $asset = $this->createPendingAsset();
        $this->assets
            ->expects($this->once())
            ->method('findById')
            ->willReturn($asset);
        $this->assets
            ->expects($this->never())
            ->method('save');
        $this->outboxRepository
            ->expects($this->never())
            ->method('enqueue');
        $this->uploadGrantIssuer
            ->expects($this->once())
            ->method('issueForAsset')
            ->with($asset)
            ->willReturn('expected-grant');

        // Act
        $result = $this->service->completeUpload(
            new CompleteUploadCommand(
                accountId: 'account-123',
                assetId: (string) $asset->getId(),
                uploadGrant: 'wrong-grant',
                completionProof: 'etag-123',
            ),
        );

        // Assert
        self::assertNull($result->success);
        self::assertSame('INVALID_UPLOAD_GRANT', $result->userErrors[0]->code);
    }

    #[Test]
    #[DataProvider('invalidCompletionStateProvider')]
    public function itReturnsAUserErrorWhenTheAssetCannotBeCompletedFromItsCurrentState(AssetStatus $status, string $expectedMessage): void
    {
        // Arrange
        $asset = match ($status) {
            AssetStatus::UPLOADED => $this->createUploadedAsset(),
            AssetStatus::PROCESSING => $this->createProcessingAsset(),
            AssetStatus::FAILED => $this->createFailedAsset(),
            default => throw new \UnexpectedValueException('Unsupported asset status for this test.'),
        };
        $this->assets
            ->expects($this->once())
            ->method('findById')
            ->willReturn($asset);
        $this->assets
            ->expects($this->never())
            ->method('save');
        $this->outboxRepository
            ->expects($this->never())
            ->method('enqueue');
        $this->uploadGrantIssuer
            ->expects($this->once())
            ->method('issueForAsset')
            ->with($asset)
            ->willReturn('grant-123');

        // Act
        $result = $this->service->completeUpload(
            new CompleteUploadCommand(
                accountId: 'account-123',
                assetId: (string) $asset->getId(),
                uploadGrant: 'grant-123',
                completionProof: 'etag-123',
            ),
        );

        // Assert
        self::assertNull($result->success);
        self::assertCount(1, $result->userErrors);
        self::assertSame('INVALID_ASSET_STATE', $result->userErrors[0]->code);
        self::assertSame($expectedMessage, $result->userErrors[0]->message);
        self::assertSame('assetId', $result->userErrors[0]->field);
    }

    /**
     * @return array<string, array{0: AssetStatus, 1: string}>
     */
    public static function invalidCompletionStateProvider(): array
    {
        return [
            'already uploaded' => [AssetStatus::UPLOADED, 'Cannot process asset from current state'],
            'already processing' => [AssetStatus::PROCESSING, 'Asset already processing'],
            'failed asset' => [AssetStatus::FAILED, 'Cannot process asset from current state'],
        ];
    }

    private function createPendingAsset(string $accountId = 'account-123'): Asset
    {
        return Asset::createPending(
            UploadId::generate(),
            new AccountId($accountId),
            'report.pdf',
            'application/pdf',
        );
    }

    private function createProcessingAsset(string $accountId = 'account-123'): Asset
    {
        $asset = $this->createPendingAsset($accountId);
        $asset->markProcessing(new UploadCompletionProofValue('etag-processing'));

        return $asset;
    }

    private function createFailedAsset(string $accountId = 'account-123'): Asset
    {
        $asset = $this->createPendingAsset($accountId);
        $asset->markFailed();

        return $asset;
    }

    private function createUploadedAsset(string $accountId = 'account-123'): Asset
    {
        $asset = $this->createPendingAsset($accountId);
        $asset->markUploaded(new UploadCompletionProofValue('etag-uploaded'));

        return $asset;
    }
}
