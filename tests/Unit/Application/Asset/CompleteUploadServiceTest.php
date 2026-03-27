<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Asset;

use App\Application\Asset\CompleteUploadService;
use App\Domain\Asset\Asset;
use App\Domain\Asset\AssetRepositoryInterface;
use App\Domain\Asset\AssetStatus;
use App\Domain\Asset\Exception\AssetDomainException;
use App\Domain\Asset\ValueObject\AccountId;
use App\Domain\Asset\ValueObject\UploadId;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class CompleteUploadServiceTest extends TestCase
{
    private const ASSET_ID = 'asset-id';
    private const VALID_UPLOAD_ID = '123e4567-e89b-42d3-a456-426614174000';
    private const ACCOUNT_ID = 'account-123';

    private AssetRepositoryInterface&MockObject $repository;

    private CompleteUploadService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = $this->createMock(AssetRepositoryInterface::class);
        $this->service = new CompleteUploadService($this->repository);
    }

    #[Test]
    public function itMarksFoundAssetAsUploadedAndPersistsIt(): void
    {
        // Arrange
        $asset = $this->createPendingAsset();

        $this->repository
            ->expects($this->once())
            ->method('findById')
            ->with(self::ASSET_ID)
            ->willReturn($asset);

        $this->repository
            ->expects($this->once())
            ->method('save')
            ->with($this->callback(function (Asset $savedAsset) use ($asset): bool {
                self::assertSame($asset, $savedAsset);
                self::assertSame(AssetStatus::UPLOADED, $savedAsset->getStatus());
                self::assertSame('unknown', $savedAsset->getFilename());
                self::assertSame('application/octet-stream', $savedAsset->getContentType());
                self::assertSame(0, $savedAsset->getSize());

                return true;
            }));

        // Act
        $result = $this->service->complete(self::ASSET_ID);

        // Assert
        self::assertSame($asset, $result);
        self::assertSame(AssetStatus::UPLOADED, $result->getStatus());
        self::assertSame('application/octet-stream', $result->getContentType());
        self::assertSame('unknown', $result->getFilename());
    }

    #[Test]
    public function itThrowsInvalidArgumentExceptionWhenAssetCannotBeFound(): void
    {
        // Arrange
        $this->repository
            ->expects($this->once())
            ->method('findById')
            ->with(self::ASSET_ID)
            ->willReturn(null);

        $this->repository->expects($this->never())->method('save');
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Asset not found');

        // Act
        $this->service->complete(self::ASSET_ID);
    }

    #[Test]
    public function itThrowsAssetDomainExceptionWhenCompletingAnAlreadyUploadedAsset(): void
    {
        // Arrange
        $asset = $this->createPendingAsset();
        $asset->markUploaded('uploaded.png', 'image/png', 512);

        $this->repository
            ->expects($this->once())
            ->method('findById')
            ->with(self::ASSET_ID)
            ->willReturn($asset);

        $this->repository->expects($this->never())->method('save');
        $this->expectException(AssetDomainException::class);
        $this->expectExceptionMessage('Asset already uploaded');

        // Act
        $this->service->complete(self::ASSET_ID);
    }

    private function createPendingAsset(): Asset
    {
        return Asset::createPending(
            new UploadId(self::VALID_UPLOAD_ID),
            new AccountId(self::ACCOUNT_ID),
        );
    }
}
