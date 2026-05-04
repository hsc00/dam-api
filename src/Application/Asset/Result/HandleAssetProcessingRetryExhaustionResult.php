<?php

declare(strict_types=1);

namespace App\Application\Asset\Result;

use App\Domain\Asset\AssetStatus;

final readonly class HandleAssetProcessingRetryExhaustionResult
{
    private function __construct(
        public AssetProcessingRetryExhaustionOutcome $outcome,
        public ?string $assetId = null,
        public ?AssetStatus $assetStatus = null,
        public ?bool $terminalStatusCached = null,
        public ?string $terminalStatusCacheError = null,
    ) {
    }

    public static function discardedUnknownAsset(string $assetId): self
    {
        return new self(AssetProcessingRetryExhaustionOutcome::DISCARDED_UNKNOWN_ASSET, $assetId);
    }

    public static function markedFailed(string $assetId, bool $terminalStatusCached, ?string $terminalStatusCacheError = null): self
    {
        return new self(
            AssetProcessingRetryExhaustionOutcome::MARKED_ASSET_FAILED,
            $assetId,
            AssetStatus::FAILED,
            $terminalStatusCached,
            $terminalStatusCacheError,
        );
    }

    public static function skippedAssetNotProcessing(string $assetId, AssetStatus $assetStatus): self
    {
        return new self(AssetProcessingRetryExhaustionOutcome::SKIPPED_ASSET_NOT_PROCESSING, $assetId, $assetStatus);
    }
}
