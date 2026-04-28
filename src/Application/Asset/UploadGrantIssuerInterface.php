<?php

declare(strict_types=1);

namespace App\Application\Asset;

use App\Domain\Asset\Asset;

interface UploadGrantIssuerInterface
{
    public function issueForAsset(Asset $asset): string;
}
