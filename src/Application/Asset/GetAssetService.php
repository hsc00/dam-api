<?php

declare(strict_types=1);

namespace App\Application\Asset;

use App\Application\Asset\Command\GetAssetQuery;
use App\Application\Asset\Result\AssetReadSource;
use App\Application\Asset\Result\GetAssetResult;
use App\Application\Exception\SuppressedFailure;
use App\Domain\Asset\Asset;
use App\Domain\Asset\AssetRepositoryInterface;
use App\Domain\Asset\AssetStatus;
use App\Domain\Asset\Exception\RepositoryUnavailableException;
use App\Domain\Asset\ValueObject\AccountId;
use App\Domain\Asset\ValueObject\AssetId;

final class GetAssetService
{
    private const REPOSITORY_FAILURE_REASON = 'Repository failure';

    public function __construct(
        private readonly AssetRepositoryInterface $assets,
        private readonly AssetStatusCacheInterface $assetStatusCache,
    ) {
    }

    public function getAsset(GetAssetQuery $query): ?GetAssetResult
    {
        $asset = $this->findDurableAsset(new AssetId($query->assetId));
        $accountId = new AccountId($query->accountId);

        if ($asset === null || (string) $asset->getAccountId() !== (string) $accountId) {
            return null;
        }

        $cachedStatus = $this->lookupCachedStatus($asset->getId());
        $durableStatus = $asset->getStatus();

        if ($cachedStatus === $durableStatus) {
            return GetAssetResult::fromAsset($asset, $cachedStatus, AssetReadSource::FAST_CACHE);
        }

        $this->seedCacheBestEffort($asset);

        return GetAssetResult::fromAsset($asset, $durableStatus, AssetReadSource::DURABLE_STORE);
    }

    private function findDurableAsset(AssetId $assetId): ?Asset
    {
        try {
            return $this->assets->findById($assetId);
        } catch (\Throwable $exception) {
            throw RepositoryUnavailableException::forReason(self::REPOSITORY_FAILURE_REASON, $exception);
        }
    }

    private function lookupCachedStatus(AssetId $assetId): ?AssetStatus
    {
        try {
            return $this->assetStatusCache->lookup($assetId);
        } catch (\Throwable $cacheLookupFailure) {
            SuppressedFailure::acknowledge($cacheLookupFailure);

            // Suppress $cacheLookupFailure because the durable store remains authoritative for reads.
            return null;
        }
    }

    private function seedCacheBestEffort(Asset $asset): void
    {
        try {
            $this->assetStatusCache->store($asset->getId(), $asset->getStatus());
        } catch (\Throwable $cacheSeedFailure) {
            SuppressedFailure::acknowledge($cacheSeedFailure);

            // Suppress $cacheSeedFailure because cache seeding must not change the read outcome.
            return;
        }
    }
}
