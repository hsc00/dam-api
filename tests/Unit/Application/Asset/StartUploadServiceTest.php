<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Asset;

use App\Application\Asset\Command\StartUploadBatchCommand;
use App\Application\Asset\Command\StartUploadBatchFileCommand;
use App\Application\Asset\Command\StartUploadCommand;
use App\Application\Asset\StartUploadService;
use App\Application\Asset\UploadGrantIssuerInterface;
use App\Domain\Asset\Asset;
use App\Domain\Asset\AssetRepositoryInterface;
use App\Domain\Asset\StorageAdapterInterface;
use App\Domain\Asset\UploadCompletionProofSource;
use App\Domain\Asset\UploadHttpMethod;
use App\Domain\Asset\ValueObject\UploadCompletionProof;
use App\Domain\Asset\ValueObject\UploadId;
use App\Domain\Asset\ValueObject\UploadTarget;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class StartUploadServiceTest extends TestCase
{
    private AssetRepositoryInterface&MockObject $assets;
    private StorageAdapterInterface&MockObject $storage;
    private UploadGrantIssuerInterface&MockObject $uploadGrantIssuer;
    private StartUploadService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assets = $this->createMock(AssetRepositoryInterface::class);
        $this->storage = $this->createMock(StorageAdapterInterface::class);
        $this->uploadGrantIssuer = $this->createMock(UploadGrantIssuerInterface::class);
        $this->service = new StartUploadService($this->assets, $this->storage, $this->uploadGrantIssuer);
    }

    #[Test]
    public function itReturnsAcceptedBatchResultsForFilesWithDistinctClientIdentifiers(): void
    {
        // Arrange
        $savedAssets = [];
        $this->assets
            ->expects($this->exactly(2))
            ->method('save')
            ->willReturnCallback(static function (Asset $asset) use (&$savedAssets): void {
                $savedAssets[] = $asset;
            });
        $this->storage
            ->expects($this->exactly(2))
            ->method('createUploadTargets')
            ->willReturnCallback(fn (Asset $asset): array => $this->uploadTargetsFor((string) $asset->getUploadId(), $asset->getChunkCount()));
        $this->uploadGrantIssuer
            ->expects($this->exactly(2))
            ->method('issueForAsset')
            ->willReturnCallback(static fn (Asset $asset): string => 'grant-' . (string) $asset->getId());

        // Act
        $result = $this->service->startUploadBatch(
            new StartUploadBatchCommand(
                accountId: 'account-123',
                files: [
                    new StartUploadBatchFileCommand('alpha', 'first.png', 'image/png', 100),
                    new StartUploadBatchFileCommand('beta', 'second.png', 'image/png', 1),
                ],
            ),
        );

        // Assert
        self::assertCount(2, $result->files);
        self::assertSame([], $result->userErrors);
        self::assertSame('alpha', $result->files[0]->clientFileId);
        self::assertNotNull($result->files[0]->success);
        self::assertCount(100, $result->files[0]->success->uploadTargets);
        self::assertSame([], $result->files[0]->userErrors);
        self::assertSame('beta', $result->files[1]->clientFileId);
        self::assertNotNull($result->files[1]->success);
        self::assertCount(1, $result->files[1]->success->uploadTargets);
        self::assertSame([], $result->files[1]->userErrors);
        self::assertCount(2, $savedAssets);
        self::assertSame(100, $savedAssets[0]->getChunkCount());
        self::assertSame(1, $savedAssets[1]->getChunkCount());
        self::assertTrue(UploadId::isValid((string) $savedAssets[0]->getUploadId()));
    }

    #[Test]
    public function itAcceptsBatchWhenItContainsExactlyTheMaximumAllowedNumberOfFiles(): void
    {
        // Arrange
        $savedAssets = [];
        $this->assets
            ->expects($this->exactly(20))
            ->method('save')
            ->willReturnCallback(static function (Asset $asset) use (&$savedAssets): void {
                $savedAssets[] = $asset;
            });
        $this->storage
            ->expects($this->exactly(20))
            ->method('createUploadTargets')
            ->willReturnCallback(fn (Asset $asset): array => $this->uploadTargetsFor((string) $asset->getUploadId(), $asset->getChunkCount()));
        $this->uploadGrantIssuer
            ->expects($this->exactly(20))
            ->method('issueForAsset')
            ->willReturnCallback(static fn (Asset $asset): string => 'grant-' . (string) $asset->getId());

        // Act
        $result = $this->service->startUploadBatch(
            new StartUploadBatchCommand(
                accountId: 'account-123',
                files: array_map(
                    static fn (int $index): StartUploadBatchFileCommand => new StartUploadBatchFileCommand(
                        clientFileId: sprintf('file-%d', $index),
                        fileName: sprintf('file-%d.png', $index),
                        mimeType: 'image/png',
                        chunkCount: 1,
                    ),
                    range(1, 20),
                ),
            ),
        );

        // Assert
        self::assertSame([], $result->userErrors);
        self::assertCount(20, $result->files);
        self::assertNotNull($result->files[0]->success);
        self::assertNotNull($result->files[19]->success);
        self::assertCount(20, $savedAssets);
    }

    /**
     * @param list<StartUploadBatchFileCommand> $files
     */
    #[Test]
    #[DataProvider('invalidBatchSizeProvider')]
    public function itReturnsTopLevelBatchValidationErrorsWhenBatchSizeIsOutsideAllowedRange(array $files, string $expectedCode, string $expectedMessage): void
    {
        // Arrange
        $this->assets
            ->expects($this->never())
            ->method('save');
        $this->storage
            ->expects($this->never())
            ->method('createUploadTargets');
        $this->uploadGrantIssuer
            ->expects($this->never())
            ->method('issueForAsset');

        // Act
        $result = $this->service->startUploadBatch(
            new StartUploadBatchCommand(
                accountId: 'account-123',
                files: $files,
            ),
        );

        // Assert
        self::assertSame([], $result->files);
        self::assertCount(1, $result->userErrors);
        self::assertSame($expectedCode, $result->userErrors[0]->code);
        self::assertSame($expectedMessage, $result->userErrors[0]->message);
        self::assertSame('files', $result->userErrors[0]->field);
    }

    #[Test]
    public function itRejectsEveryOccurrenceOfADuplicatedClientFileIdWithinBatch(): void
    {
        // Arrange
        $this->assets
            ->expects($this->once())
            ->method('save');
        $this->storage
            ->expects($this->once())
            ->method('createUploadTargets')
            ->willReturnCallback(fn (Asset $asset): array => $this->uploadTargetsFor((string) $asset->getUploadId(), $asset->getChunkCount()));
        $this->uploadGrantIssuer
            ->expects($this->once())
            ->method('issueForAsset')
            ->willReturn('grant-unique');

        // Act
        $result = $this->service->startUploadBatch(
            new StartUploadBatchCommand(
                accountId: 'account-123',
                files: [
                    new StartUploadBatchFileCommand('dup', 'first.png', 'image/png', 1),
                    new StartUploadBatchFileCommand('dup', 'second.png', 'image/png', 1),
                    new StartUploadBatchFileCommand('unique', 'third.png', 'image/png', 1),
                ],
            ),
        );

        // Assert
        self::assertCount(3, $result->files);
        self::assertSame([], $result->userErrors);
        self::assertNull($result->files[0]->success);
        self::assertSame('DUPLICATE_CLIENT_FILE_ID', $result->files[0]->userErrors[0]->code);
        self::assertNull($result->files[1]->success);
        self::assertSame('DUPLICATE_CLIENT_FILE_ID', $result->files[1]->userErrors[0]->code);
        self::assertNotNull($result->files[2]->success);
        self::assertSame([], $result->files[2]->userErrors);
    }

    #[Test]
    public function itReturnsFileValidationErrorsWhenChunkCountOrClientFileIdIsInvalid(): void
    {
        // Arrange
        $this->assets
            ->expects($this->never())
            ->method('save');
        $this->storage
            ->expects($this->never())
            ->method('createUploadTargets');
        $this->uploadGrantIssuer
            ->expects($this->never())
            ->method('issueForAsset');

        // Act
        $result = $this->service->startUploadBatch(
            new StartUploadBatchCommand(
                accountId: 'account-123',
                files: [
                    new StartUploadBatchFileCommand('', 'first.png', 'image/png', 1),
                    new StartUploadBatchFileCommand('beta', 'second.png', 'image/png', 0),
                    new StartUploadBatchFileCommand('gamma', 'third.png', 'image/png', 101),
                ],
            ),
        );

        // Assert
        self::assertSame([], $result->userErrors);
        self::assertSame('INVALID_CLIENT_FILE_ID', $result->files[0]->userErrors[0]->code);
        self::assertSame('INVALID_CHUNK_COUNT', $result->files[1]->userErrors[0]->code);
        self::assertSame('Chunk count must be between 1 and 100.', $result->files[1]->userErrors[0]->message);
        self::assertSame('INVALID_CHUNK_COUNT', $result->files[2]->userErrors[0]->code);
        self::assertSame('Chunk count must be between 1 and 100.', $result->files[2]->userErrors[0]->message);
    }

    #[Test]
    public function itDelegatesSingleFileStartUploadToTheSharedInitiationLogic(): void
    {
        // Arrange
        $savedAssets = [];
        $this->assets
            ->expects($this->once())
            ->method('save')
            ->willReturnCallback(static function (Asset $asset) use (&$savedAssets): void {
                $savedAssets[] = $asset;
            });
        $this->storage
            ->expects($this->once())
            ->method('createUploadTargets')
            ->willReturnCallback(fn (Asset $asset): array => $this->uploadTargetsFor((string) $asset->getUploadId(), $asset->getChunkCount()));
        $this->uploadGrantIssuer
            ->expects($this->once())
            ->method('issueForAsset')
            ->willReturn('grant-single');

        // Act
        $result = $this->service->startUpload(
            new StartUploadCommand(
                accountId: 'account-123',
                fileName: 'single.png',
                mimeType: 'image/png',
                fileSizeBytes: 42,
                checksumSha256: 'checksum',
            ),
        );

        // Assert
        self::assertNotNull($result->success);
        self::assertSame([], $result->userErrors);
        self::assertSame('grant-single', $result->success->uploadGrant);
        self::assertStringContainsString('/chunk/0', $result->success->uploadTarget['url']);
        self::assertCount(1, $savedAssets);
        self::assertSame(1, $savedAssets[0]->getChunkCount());
    }

    /**
     * @return array<string, array{0: list<StartUploadBatchFileCommand>, 1: string, 2: string}>
     */
    public static function invalidBatchSizeProvider(): array
    {
        return [
            'empty batch' => [
                [],
                'EMPTY_BATCH',
                'At least one file is required.',
            ],
            'too many files' => [
                array_map(
                    static fn (int $index): StartUploadBatchFileCommand => new StartUploadBatchFileCommand(
                        clientFileId: sprintf('file-%d', $index),
                        fileName: sprintf('file-%d.png', $index),
                        mimeType: 'image/png',
                        chunkCount: 1,
                    ),
                    range(1, 21),
                ),
                'BATCH_TOO_LARGE',
                'You can upload at most 20 files in one request.',
            ],
        ];
    }

    /**
     * @return list<UploadTarget>
     */
    private function uploadTargetsFor(string $uploadId, int $chunkCount): array
    {
        $targets = [];

        for ($chunkIndex = 0; $chunkIndex < $chunkCount; $chunkIndex++) {
            $targets[] = new UploadTarget(
                sprintf('mock://uploads/%s/chunk/%d', $uploadId, $chunkIndex),
                UploadHttpMethod::PUT,
                [],
                new UploadCompletionProof('etag', UploadCompletionProofSource::RESPONSE_HEADER),
                new DateTimeImmutable('2100-01-01T00:00:00+00:00'),
            );
        }

        return $targets;
    }
}
