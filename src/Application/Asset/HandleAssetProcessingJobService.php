<?php

declare(strict_types=1);

namespace App\Application\Asset;

use App\Application\Asset\Command\HandleAssetProcessingJobCommand;
use App\Application\Asset\Exception\TerminalAssetProcessingException;
use App\Application\Asset\Result\HandleAssetProcessingJobResult;
use App\Application\Asset\Result\TerminalStatusCacheStoreResult as AssetTerminalStatusCacheStoreResult;
use App\Domain\Asset\Asset;
use App\Domain\Asset\AssetRepositoryInterface;
use App\Domain\Asset\AssetStatus;
use App\Domain\Asset\Exception\RepositoryUnavailableException;
use App\Domain\Asset\Exception\StaleAssetWriteException;
use App\Domain\Asset\ValueObject\AssetId;
use App\Domain\Asset\ValueObject\UploadCompletionProofValue;

final class HandleAssetProcessingJobService
{
    private const REPOSITORY_FAILURE_REASON = 'Repository failure';

    public function __construct(
        private readonly AssetRepositoryInterface $assets,
        private readonly AssetProcessorInterface $assetProcessor,
        private readonly AssetTerminalStatusCacheInterface $assetTerminalStatusCache,
    ) {
    }

    public function handle(HandleAssetProcessingJobCommand $command): HandleAssetProcessingJobResult
    {
        $asset = $this->findAsset($command->assetId);

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
            static fn (string $assetId, AssetTerminalStatusCacheStoreResult $cacheResult): HandleAssetProcessingJobResult => HandleAssetProcessingJobResult::processedUploaded(
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
            static fn (string $assetId, AssetTerminalStatusCacheStoreResult $cacheResult): HandleAssetProcessingJobResult => HandleAssetProcessingJobResult::processedFailed(
                $assetId,
                $cacheResult->stored,
                $processingErrorMessage,
                $cacheResult->error,
            ),
        );
    }

    /**
     * @param \Closure(string, AssetTerminalStatusCacheStoreResult): HandleAssetProcessingJobResult $terminalResultFactory
     */
    private function persistTerminalAsset(Asset $asset, \Closure $terminalResultFactory): HandleAssetProcessingJobResult
    {
        $staleResult = $this->saveAsset($asset);

        if ($staleResult instanceof HandleAssetProcessingJobResult) {
            return $staleResult;
        }

        return $terminalResultFactory(
            (string) $asset->getId(),
            $this->cacheTerminalStatus($asset),
        );
    }

    private function saveAsset(Asset $asset): ?HandleAssetProcessingJobResult
    {
        try {
            $this->assets->save($asset);
        } catch (StaleAssetWriteException $exception) {
            return $this->staleResult($asset->getId(), $exception);
        } catch (\Throwable $exception) {
            throw RepositoryUnavailableException::forReason(self::REPOSITORY_FAILURE_REASON, $exception);
        }

        return null;
    }

    private function staleResult(AssetId $assetId, StaleAssetWriteException $exception): HandleAssetProcessingJobResult
    {
        $currentAsset = $this->findAsset($assetId);

        if ($currentAsset !== null && $currentAsset->getStatus() !== AssetStatus::PROCESSING) {
            return HandleAssetProcessingJobResult::skippedAssetNotProcessing((string) $assetId, $currentAsset->getStatus());
        }

        throw RepositoryUnavailableException::forReason(self::REPOSITORY_FAILURE_REASON, $exception);
    }

    private function processingCompletionProof(Asset $asset): UploadCompletionProofValue
    {
        $completionProof = $asset->getCompletionProof();

        if (! $completionProof instanceof UploadCompletionProofValue) {
            throw new \LogicException('Processing assets must include a completion proof.');
        }

        return $completionProof;
    }

    private function cacheTerminalStatus(Asset $asset): AssetTerminalStatusCacheStoreResult
    {
        try {
            // Terminal status caching is best-effort after MySQL persistence succeeds.
            $this->assetTerminalStatusCache->store($asset->getId(), $asset->getStatus());
        } catch (\Throwable $exception) {
            return AssetTerminalStatusCacheStoreResult::failed($this->terminalStatusCacheError($exception));
        }

        return AssetTerminalStatusCacheStoreResult::storedSuccessfully();
    }

    private function findAsset(AssetId $assetId): ?Asset
    {
        try {
            return $this->assets->findById($assetId);
        } catch (\Throwable $exception) {
            throw RepositoryUnavailableException::forReason(self::REPOSITORY_FAILURE_REASON, $exception);
        }
    }

    private function terminalStatusCacheError(\Throwable $exception): string
    {
        $message = trim($exception->getMessage());

        if ($message === '') {
            return get_debug_type($exception);
        }

        return get_debug_type($exception) . ': ' . $message;
    }
}
