<?php

declare(strict_types=1);

namespace App\Application\Asset;

use App\Application\Asset\Result\TerminalStatusCacheStoreResult;
use App\Domain\Asset\Asset;
use App\Domain\Asset\AssetRepositoryInterface;
use App\Domain\Asset\AssetStatus;
use App\Domain\Asset\Exception\RepositoryUnavailableException;
use App\Domain\Asset\Exception\StaleAssetWriteException;
use App\Domain\Asset\ValueObject\AssetId;

final class TerminalAssetPersistenceService
{
    private const REPOSITORY_FAILURE_REASON = 'Repository failure';

    public function __construct(
        private readonly AssetRepositoryInterface $assets,
        private readonly AssetTerminalStatusCacheInterface $assetTerminalStatusCache,
    ) {
    }

    public function findAsset(AssetId $assetId): ?Asset
    {
        try {
            return $this->assets->findById($assetId);
        } catch (\Throwable $exception) {
            throw RepositoryUnavailableException::forReason(self::REPOSITORY_FAILURE_REASON, $exception);
        }
    }

    /**
     * @template TResult of object
     *
     * @param \Closure(string, TerminalStatusCacheStoreResult): TResult $terminalResultFactory
     * @param \Closure(string, AssetStatus): TResult $staleAssetResultFactory
     *
     * @return TResult
     */
    public function persistTerminalAsset(Asset $asset, \Closure $terminalResultFactory, \Closure $staleAssetResultFactory): object
    {
        $staleResult = $this->saveAsset($asset, $staleAssetResultFactory);

        if ($staleResult !== null) {
            return $staleResult;
        }

        return $terminalResultFactory(
            (string) $asset->getId(),
            $this->cacheTerminalStatus($asset),
        );
    }

    /**
     * @template TResult of object
     *
     * @param \Closure(string, AssetStatus): TResult $staleAssetResultFactory
     *
     * @return TResult|null
     */
    private function saveAsset(Asset $asset, \Closure $staleAssetResultFactory): ?object
    {
        try {
            $this->assets->save($asset);
        } catch (StaleAssetWriteException $exception) {
            return $this->staleResult($asset->getId(), $exception, $staleAssetResultFactory);
        } catch (\Throwable $exception) {
            throw RepositoryUnavailableException::forReason(self::REPOSITORY_FAILURE_REASON, $exception);
        }

        return null;
    }

    /**
     * @template TResult of object
     *
     * @param \Closure(string, AssetStatus): TResult $staleAssetResultFactory
     *
     * @return TResult
     */
    private function staleResult(AssetId $assetId, StaleAssetWriteException $exception, \Closure $staleAssetResultFactory): object
    {
        $currentAsset = $this->findAsset($assetId);

        if ($currentAsset !== null && $currentAsset->getStatus() !== AssetStatus::PROCESSING) {
            return $staleAssetResultFactory((string) $assetId, $currentAsset->getStatus());
        }

        throw RepositoryUnavailableException::forReason(self::REPOSITORY_FAILURE_REASON, $exception);
    }

    private function cacheTerminalStatus(Asset $asset): TerminalStatusCacheStoreResult
    {
        try {
            // Terminal status caching is best-effort after MySQL persistence succeeds.
            $this->assetTerminalStatusCache->store($asset->getId(), $asset->getStatus());
        } catch (\Exception $exception) {
            return TerminalStatusCacheStoreResult::failed($this->terminalStatusCacheError($exception));
        }

        return TerminalStatusCacheStoreResult::storedSuccessfully();
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
