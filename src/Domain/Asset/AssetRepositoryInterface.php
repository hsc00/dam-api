<?php

declare(strict_types=1);

namespace App\Domain\Asset;

interface AssetRepositoryInterface
{
    public function save(Asset $asset): void;

    public function findById(string $id): ?Asset;
}
