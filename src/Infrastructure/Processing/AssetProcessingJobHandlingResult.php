<?php

declare(strict_types=1);

namespace App\Infrastructure\Processing;

use App\Application\Asset\Result\AssetProcessingJobOutcome;
use App\Application\Asset\Result\HandleAssetProcessingJobResult;
use App\Domain\Asset\AssetStatus;

final readonly class AssetProcessingJobHandlingResult
{
    private function __construct(
        public AssetProcessingJobHandlingOutcome $outcome,
        public AssetProcessingJobDelivery $delivery,
        public ?string $assetId = null,
        public ?AssetStatus $assetStatus = null,
        public ?bool $terminalStatusCached = null,
        public ?string $terminalStatusCacheError = null,
        public ?string $processingErrorMessage = null,
        public ?string $queuePayload = null,
    ) {
    }

    public static function discardedInvalidAssetId(): self
    {
        return new self(AssetProcessingJobHandlingOutcome::DISCARDED_INVALID_ASSET_ID, AssetProcessingJobDelivery::DISCARD);
    }

    public static function discardedMalformedPayload(): self
    {
        return new self(AssetProcessingJobHandlingOutcome::DISCARDED_MALFORMED_PAYLOAD, AssetProcessingJobDelivery::DISCARD);
    }

    public static function discardedUnknownAsset(string $assetId): self
    {
        return new self(AssetProcessingJobHandlingOutcome::DISCARDED_UNKNOWN_ASSET, AssetProcessingJobDelivery::DISCARD, $assetId);
    }

    public static function deadLettered(
        string $assetId,
        string $processingErrorMessage,
        ?string $queuePayload = null,
        ?AssetStatus $assetStatus = null,
        ?bool $terminalStatusCached = null,
        ?string $terminalStatusCacheError = null,
    ): self {
        return new self(
            outcome: AssetProcessingJobHandlingOutcome::DEAD_LETTERED,
            delivery: AssetProcessingJobDelivery::DEAD_LETTER,
            assetId: $assetId,
            assetStatus: $assetStatus,
            terminalStatusCached: $terminalStatusCached,
            terminalStatusCacheError: $terminalStatusCacheError,
            processingErrorMessage: $processingErrorMessage,
            queuePayload: $queuePayload,
        );
    }

    public static function processedFailed(
        string $assetId,
        bool $terminalStatusCached,
        string $processingErrorMessage,
        ?string $terminalStatusCacheError = null,
    ): self {
        return new self(
            AssetProcessingJobHandlingOutcome::PROCESSED_ASSET_FAILED,
            AssetProcessingJobDelivery::HANDLED,
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
            AssetProcessingJobHandlingOutcome::PROCESSED_ASSET_UPLOADED,
            AssetProcessingJobDelivery::HANDLED,
            $assetId,
            AssetStatus::UPLOADED,
            $terminalStatusCached,
            $terminalStatusCacheError,
        );
    }

    public static function retryableProcessingFailure(string $assetId, string $processingErrorMessage, ?string $queuePayload = null): self
    {
        return new self(
            outcome: AssetProcessingJobHandlingOutcome::RETRYABLE_PROCESSING_FAILURE,
            delivery: AssetProcessingJobDelivery::RETRY,
            assetId: $assetId,
            processingErrorMessage: $processingErrorMessage,
            queuePayload: $queuePayload,
        );
    }

    public static function skippedAssetNotProcessing(string $assetId, AssetStatus $assetStatus): self
    {
        return new self(
            AssetProcessingJobHandlingOutcome::SKIPPED_ASSET_NOT_PROCESSING,
            AssetProcessingJobDelivery::DISCARD,
            $assetId,
            $assetStatus,
        );
    }

    public function queuedPayload(): ?string
    {
        return $this->queuePayload;
    }

    public static function fromApplicationResult(HandleAssetProcessingJobResult $result): self
    {
        return match ($result->outcome) {
            AssetProcessingJobOutcome::DISCARDED_UNKNOWN_ASSET => self::discardedUnknownAsset((string) $result->assetId),
            AssetProcessingJobOutcome::PROCESSED_ASSET_FAILED => self::processedFailed(
                (string) $result->assetId,
                $result->terminalStatusCached ?? false,
                (string) $result->processingErrorMessage,
                $result->terminalStatusCacheError,
            ),
            AssetProcessingJobOutcome::PROCESSED_ASSET_UPLOADED => self::processedUploaded(
                (string) $result->assetId,
                $result->terminalStatusCached ?? false,
                $result->terminalStatusCacheError,
            ),
            AssetProcessingJobOutcome::SKIPPED_ASSET_NOT_PROCESSING => self::skippedAssetNotProcessing(
                (string) $result->assetId,
                $result->assetStatus ?? AssetStatus::PENDING,
            ),
        };
    }
}
