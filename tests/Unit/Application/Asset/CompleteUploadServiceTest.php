<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Asset;

use App\Application\Asset\Command\CompleteUploadCommand;
use App\Application\Asset\CompleteUploadService;
use App\Application\Asset\UploadGrantIssuerInterface;
use App\Domain\Asset\Asset;
use App\Domain\Asset\AssetRepositoryInterface;
use App\Domain\Asset\AssetStatus;
use App\Domain\Asset\ValueObject\AccountId;
use App\Domain\Asset\ValueObject\UploadId;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class CompleteUploadServiceTest extends TestCase
{
    private AssetRepositoryInterface&MockObject $assets;
    private UploadGrantIssuerInterface&MockObject $uploadGrantIssuer;
    private CompleteUploadService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assets = $this->createMock(AssetRepositoryInterface::class);
        $this->uploadGrantIssuer = $this->createMock(UploadGrantIssuerInterface::class);
        $this->service = new CompleteUploadService($this->assets, $this->uploadGrantIssuer);
    }

    #[Test]
    public function itMarksPendingAssetAsUploadedWhenGrantAndCompletionProofAreValid(): void
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
        self::assertSame(AssetStatus::UPLOADED, $result->success->asset['status']);
        self::assertCount(1, $savedAssets);
        self::assertSame(AssetStatus::UPLOADED, $savedAssets[0]->getStatus());
        self::assertSame('etag-123', $savedAssets[0]->getCompletionProof()?->value);
    }

    #[Test]
    public function itReturnsValidationErrorsWhenAssetIdGrantOrCompletionProofAreInvalid(): void
    {
        // Arrange
        $this->assets
            ->expects($this->never())
            ->method('findById');
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

    private function createPendingAsset(string $accountId = 'account-123'): Asset
    {
        return Asset::createPending(
            UploadId::generate(),
            new AccountId($accountId),
            'report.pdf',
            'application/pdf',
        );
    }
}
