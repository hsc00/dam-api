<?php

declare(strict_types=1);

namespace App\Application\Asset\Result;

enum AssetReadSource: string
{
    case DURABLE_STORE = 'DURABLE_STORE';
    case FAST_CACHE = 'FAST_CACHE';
}
