<?php

declare(strict_types=1);

namespace App\Infrastructure\Processing;

interface AssetProcessingJobConsumerInterface
{
    public function reserveNext(): ?ReservedAssetProcessingJob;
}
