<?php

declare(strict_types=1);

namespace App\Application\Asset;

use App\Application\Asset\Command\HandleAssetProcessingRetryExhaustionCommand;
use App\Application\Asset\Result\HandleAssetProcessingRetryExhaustionResult;
use App\Application\Asset\Result\TerminalStatusCacheStoreResult;
use App\Domain\Asset\AssetRepositoryInterface;
use App\Domain\Asset\AssetStatus;

final class HandleAssetProcessingRetryExhaustionService
{
    private readonly TerminalAssetPersistenceService $terminalAssetPersistence;

    public function __construct(
        AssetRepositoryInterface $assets,
        AssetTerminalStatusCacheInterface $assetTerminalStatusCache,
    ) {
        $this->terminalAssetPersistence = new TerminalAssetPersistenceService($assets, $assetTerminalStatusCache);
    }

    public function handle(HandleAssetProcessingRetryExhaustionCommand $command): HandleAssetProcessingRetryExhaustionResult
    {
        $asset = $this->terminalAssetPersistence->findAsset($command->assetId);

        if ($asset === null) {
            return HandleAssetProcessingRetryExhaustionResult::discardedUnknownAsset((string) $command->assetId);
        }

        if ($asset->getStatus() !== AssetStatus::PROCESSING) {
            return HandleAssetProcessingRetryExhaustionResult::skippedAssetNotProcessing((string) $command->assetId, $asset->getStatus());
        }

        $asset->markFailed();

        return $this->terminalAssetPersistence->persistTerminalAsset(
            $asset,
            static fn (string $assetId, TerminalStatusCacheStoreResult $cacheResult): HandleAssetProcessingRetryExhaustionResult => HandleAssetProcessingRetryExhaustionResult::markedFailed(
                $assetId,
                $cacheResult->stored,
                $cacheResult->error,
            ),
            static fn (string $assetId, AssetStatus $assetStatus): HandleAssetProcessingRetryExhaustionResult => HandleAssetProcessingRetryExhaustionResult::skippedAssetNotProcessing(
                $assetId,
                $assetStatus,
            ),
        );
    }
}
