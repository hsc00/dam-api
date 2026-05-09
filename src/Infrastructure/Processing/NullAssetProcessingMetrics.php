<?php

declare(strict_types=1);

namespace App\Infrastructure\Processing;

use App\Application\Asset\AssetProcessingMetricCounter;
use App\Application\Asset\AssetProcessingMetricsInterface;

final class NullAssetProcessingMetrics implements AssetProcessingMetricsInterface {
    public function incrementCounter(AssetProcessingMetricCounter $counter): void {
        // Intentionally no-op: worker processing must continue when metrics are disabled.
    }
}
