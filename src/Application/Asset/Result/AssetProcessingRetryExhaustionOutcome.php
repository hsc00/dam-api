<?php

declare(strict_types=1);

namespace App\Application\Asset\Result;

enum AssetProcessingRetryExhaustionOutcome: string
{
    case DISCARDED_UNKNOWN_ASSET = 'DISCARDED_UNKNOWN_ASSET';
    case MARKED_ASSET_FAILED = 'MARKED_ASSET_FAILED';
    case SKIPPED_ASSET_NOT_PROCESSING = 'SKIPPED_ASSET_NOT_PROCESSING';
}
