<?php

declare(strict_types=1);

namespace App\Application\Asset;

interface AssetProcessingMetricsInterface
{
    public function incrementCounter(AssetProcessingMetricCounter $counter): void;
}
