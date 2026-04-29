<?php

declare(strict_types=1);

namespace App\Infrastructure\Processing;

use App\Application\Asset\AssetProcessingJobDispatcherInterface;
use App\Domain\Asset\ValueObject\AssetId;

final class MockAssetProcessingJobDispatcher implements AssetProcessingJobDispatcherInterface
{
    /**
     * @var list<string>
     */
    private array $dispatchedAssetIds = [];

    public function dispatch(AssetId $assetId): void
    {
        $this->dispatchedAssetIds[] = (string) $assetId;
    }

    /** @return list<string> */
    public function dispatchedAssetIds(): array
    {
        return $this->dispatchedAssetIds;
    }
}
