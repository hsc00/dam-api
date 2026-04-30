<?php

declare(strict_types=1);

namespace App\Application\Asset\Result;

use App\Domain\Asset\AssetStatus;

final readonly class HandleAssetProcessingJobResult
{
    private function __construct(
        public AssetProcessingJobOutcome $outcome,
        public ?string $assetId = null,
        public ?AssetStatus $assetStatus = null,
        public ?bool $terminalStatusCached = null,
        public ?string $terminalStatusCacheError = null,
        public ?string $processingErrorMessage = null,
    ) {
    }

    public static function discardedUnknownAsset(string $assetId): self
    {
        return new self(AssetProcessingJobOutcome::DISCARDED_UNKNOWN_ASSET, $assetId);
    }

    public static function processedFailed(
        string $assetId,
        bool $terminalStatusCached,
        string $processingErrorMessage,
        ?string $terminalStatusCacheError = null,
    ): self {
        return new self(
            AssetProcessingJobOutcome::PROCESSED_ASSET_FAILED,
            $assetId,
            AssetStatus::FAILED,
            $terminalStatusCached,
            $terminalStatusCacheError,
            $processingErrorMessage,
        );
    }

    public static function processedUploaded(string $assetId, bool $terminalStatusCached, ?string $terminalStatusCacheError = null): self
    {
        return new self(
            AssetProcessingJobOutcome::PROCESSED_ASSET_UPLOADED,
            $assetId,
            AssetStatus::UPLOADED,
            $terminalStatusCached,
            $terminalStatusCacheError,
        );
    }

    public static function skippedAssetNotProcessing(string $assetId, AssetStatus $assetStatus): self
    {
        return new self(AssetProcessingJobOutcome::SKIPPED_ASSET_NOT_PROCESSING, $assetId, $assetStatus);
    }
}
