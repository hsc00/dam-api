<?php

declare(strict_types=1);

namespace App\Infrastructure\Processing;

enum AssetProcessingJobDelivery: string
{
    case DISCARD = 'DISCARD';
    case HANDLED = 'HANDLED';
    case RETRY = 'RETRY';
}
