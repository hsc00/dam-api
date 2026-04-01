<?php

declare(strict_types=1);

namespace App\Domain\Asset;

use App\Domain\Asset\ValueObject\UploadTarget;

interface StorageAdapterInterface
{
    public function createUploadTarget(Asset $asset): UploadTarget;
}
