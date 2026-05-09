<?php

declare(strict_types=1);

namespace App\Application\Asset;

enum AssetProcessingMetricCounter: string
{
    case DISCARDED_TOTAL = 'asset_processing_discarded_total';
    case PROCESSED_FAILURE_TOTAL = 'asset_processing_processed_failure_total';
    case PROCESSED_SUCCESS_TOTAL = 'asset_processing_processed_success_total';
    case PROCESSED_TOTAL = 'asset_processing_processed_total';
    case RETRY_TOTAL = 'asset_processing_retry_total';
}
