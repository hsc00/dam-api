<?php

declare(strict_types=1);

namespace App\Application\Asset\Result;

enum AssetProcessingJobOutcome: string
{
    case DISCARDED_UNKNOWN_ASSET = 'DISCARDED_UNKNOWN_ASSET';
    case PROCESSED_ASSET_FAILED = 'PROCESSED_ASSET_FAILED';
    case PROCESSED_ASSET_UPLOADED = 'PROCESSED_ASSET_UPLOADED';
    case SKIPPED_ASSET_NOT_PROCESSING = 'SKIPPED_ASSET_NOT_PROCESSING';
}
