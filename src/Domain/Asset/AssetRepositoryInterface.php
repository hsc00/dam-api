<?php

declare(strict_types=1);

namespace App\Domain\Asset;

use App\Domain\Asset\ValueObject\AccountId;
use App\Domain\Asset\ValueObject\AssetId;
use App\Domain\Asset\ValueObject\UploadId;

interface AssetRepositoryInterface
{
    public function save(Asset $asset): void;

    public function findById(AssetId $assetId): ?Asset;

    public function findByUploadId(UploadId $uploadId): ?Asset;

    /**
     * Performs an account-scoped, status-scoped search using the trimmed query
     * as a plain-text, case-insensitive substring match against fileName.
     * Results are ordered by createdAt descending, then id ascending.
     * Implementations must return an empty list when the trimmed query is empty.
     *
     * @return list<Asset>
     */
    public function searchByFileName(AccountId $accountId, string $query, AssetStatus $status, int $offset, int $limit): array;

    /**
     * Counts account-scoped assets with the supplied status whose fileName
     * contains the trimmed query as a plain-text, case-insensitive substring.
     * Implementations must return 0 when the trimmed query is empty.
     */
    public function countByFileName(AccountId $accountId, string $query, AssetStatus $status): int;
}
