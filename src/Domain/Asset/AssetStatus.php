<?php

declare(strict_types=1);

namespace App\Domain\Asset;

enum AssetStatus: string
{
    case PENDING = 'PENDING';
    case PROCESSING = 'PROCESSING';
    case UPLOADED = 'UPLOADED';
    case FAILED = 'FAILED';
}
