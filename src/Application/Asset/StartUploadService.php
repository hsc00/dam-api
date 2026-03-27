<?php

declare(strict_types=1);

namespace App\Application\Asset;

use App\Domain\Asset\AssetRepositoryInterface;
use App\Domain\Asset\ValueObject\UploadId;
use App\Domain\Asset\ValueObject\AccountId;
use App\Domain\Asset\Asset;

final class StartUploadService
{
    public function __construct(private AssetRepositoryInterface $repository)
    {
    }

    /**
     * @return array{asset: Asset, uploadTarget: null|string, uploadGrant: string}
     */
    public function start(string $uploadId, string $accountId): array
    {
        $asset = Asset::createPending(new UploadId($uploadId), new AccountId($accountId));
        $this->repository->save($asset);

        return [
            'asset' => $asset,
            'uploadTarget' => null,
            'uploadGrant' => '',
        ];
    }
}
