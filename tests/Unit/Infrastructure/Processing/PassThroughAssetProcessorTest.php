<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\Processing;

use App\Application\Asset\Exception\TerminalAssetProcessingException;
use App\Domain\Asset\Asset;
use App\Domain\Asset\AssetStatus;
use App\Domain\Asset\ValueObject\AccountId;
use App\Domain\Asset\ValueObject\UploadCompletionProofValue;
use App\Domain\Asset\ValueObject\UploadId;
use App\Infrastructure\Processing\PassThroughAssetProcessor;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PassThroughAssetProcessorTest extends TestCase
{
    #[Test]
    public function itReturnsProcessingAssetReadyForTerminalPersistenceWhenAssetIsPassThrough(): void
    {
        // Arrange
        $processor = new PassThroughAssetProcessor();
        $asset = $this->createProcessingAsset();

        // Act
        $processor->process($asset);

        // Assert
        self::assertSame(AssetStatus::PROCESSING, $asset->getStatus());
        self::assertSame('etag-processing', $asset->getCompletionProof()?->value);
    }

    #[Test]
    public function itThrowsATerminalProcessingExceptionWhenProcessingIsRequestedForANonProcessingAsset(): void
    {
        // Arrange
        $processor = new PassThroughAssetProcessor();
        $asset = Asset::createPending(
            UploadId::generate(),
            new AccountId('account-123'),
            'report.pdf',
            'application/pdf',
        );

        // Act & Assert
        $this->expectException(TerminalAssetProcessingException::class);
        $this->expectExceptionMessage('Only processing assets can be processed.');
        $processor->process($asset);
    }

    private function createProcessingAsset(): Asset
    {
        $asset = Asset::createPending(
            UploadId::generate(),
            new AccountId('account-123'),
            'report.pdf',
            'application/pdf',
        );
        $asset->markProcessing(new UploadCompletionProofValue('etag-processing'));

        return $asset;
    }
}
