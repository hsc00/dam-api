<?php

declare(strict_types=1);

namespace App\Application\Asset;

use App\Domain\Asset\Asset;
use App\Domain\Asset\AssetRepositoryInterface;

final class CompleteUploadService
{
    public function __construct(private AssetRepositoryInterface $repository)
    {
    }

    public function complete(string $assetId): Asset
    {
        $asset = $this->repository->findById($assetId);
        if ($asset === null) {
            throw new \InvalidArgumentException('Asset not found');
        }

        $asset->markUploaded('unknown', 'application/octet-stream', 0);
        $this->repository->save($asset);

        return $asset;
    }
}
