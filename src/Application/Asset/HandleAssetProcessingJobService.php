<?php

declare(strict_types=1);

namespace App\Application\Asset;

use App\Application\Asset\Command\HandleAssetProcessingJobCommand;
use App\Application\Asset\Exception\TerminalAssetProcessingException;
use App\Application\Asset\Result\HandleAssetProcessingJobResult;
use App\Application\Asset\Result\TerminalStatusCacheStoreResult;
use App\Domain\Asset\Asset;
use App\Domain\Asset\AssetRepositoryInterface;
use App\Domain\Asset\AssetStatus;
use App\Domain\Asset\ValueObject\UploadCompletionProofValue;

final class HandleAssetProcessingJobService
{
    private readonly \App\Application\Asset\TerminalAssetPersistenceService $terminalAssetPersistence;

    public function __construct(
        AssetRepositoryInterface $assets,
        private readonly AssetProcessorInterface $assetProcessor,
        AssetTerminalStatusCacheInterface $assetTerminalStatusCache,
    ) {
        $this->terminalAssetPersistence = new \App\Application\Asset\TerminalAssetPersistenceService($assets, $assetTerminalStatusCache);
    }

    public function handle(HandleAssetProcessingJobCommand $command): HandleAssetProcessingJobResult
    {
        $asset = $this->terminalAssetPersistence->findAsset($command->assetId);

        if ($asset === null) {
            $result = HandleAssetProcessingJobResult::discardedUnknownAsset((string) $command->assetId);
        } elseif ($asset->getStatus() !== AssetStatus::PROCESSING) {
            $result = HandleAssetProcessingJobResult::skippedAssetNotProcessing((string) $command->assetId, $asset->getStatus());
        } else {
            $result = $this->processAsset($asset);
        }

        return $result;
    }

    private function processAsset(Asset $asset): HandleAssetProcessingJobResult
    {
        try {
            $this->assetProcessor->process($asset);
        } catch (TerminalAssetProcessingException $processingFailure) {
            return $this->markAssetFailedAfterProcessingException($asset, $processingFailure->getMessage());
        }

        $asset->markUploaded($this->processingCompletionProof($asset));

        return $this->persistTerminalAsset(
            $asset,
            static fn (string $assetId, TerminalStatusCacheStoreResult $cacheResult): HandleAssetProcessingJobResult => HandleAssetProcessingJobResult::processedUploaded(
                $assetId,
                $cacheResult->stored,
                $cacheResult->error,
            ),
        );
    }

    private function markAssetFailedAfterProcessingException(Asset $asset, string $processingErrorMessage): HandleAssetProcessingJobResult
    {
        $asset->markFailed();

        return $this->persistTerminalAsset(
            $asset,
            static fn (string $assetId, TerminalStatusCacheStoreResult $cacheResult): HandleAssetProcessingJobResult => HandleAssetProcessingJobResult::processedFailed(
                $assetId,
                $cacheResult->stored,
                $processingErrorMessage,
                $cacheResult->error,
            ),
        );
    }

    /**
     * @param \Closure(string, TerminalStatusCacheStoreResult): HandleAssetProcessingJobResult $terminalResultFactory
     */
    private function persistTerminalAsset(Asset $asset, \Closure $terminalResultFactory): HandleAssetProcessingJobResult
    {
        return $this->terminalAssetPersistence->persistTerminalAsset(
            $asset,
            $terminalResultFactory,
            static fn (string $assetId, AssetStatus $assetStatus): HandleAssetProcessingJobResult => HandleAssetProcessingJobResult::skippedAssetNotProcessing(
                $assetId,
                $assetStatus,
            ),
        );
    }

    private function processingCompletionProof(Asset $asset): UploadCompletionProofValue
    {
        $completionProof = $asset->getCompletionProof();

        if (! $completionProof instanceof UploadCompletionProofValue) {
            throw new \LogicException('Processing assets must include a completion proof.');
        }

        return $completionProof;
    }
}
