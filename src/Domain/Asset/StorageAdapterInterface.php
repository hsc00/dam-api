<?php

declare(strict_types=1);

namespace App\Domain\Asset;

use App\Domain\Asset\ValueObject\UploadTarget;

interface StorageAdapterInterface
{
    /**
     * @return list<UploadTarget>
     */
    public function createUploadTargets(Asset $asset): array;
}
