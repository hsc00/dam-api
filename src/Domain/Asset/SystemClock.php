<?php

declare(strict_types=1);

namespace App\Domain\Asset;

use DateTimeImmutable;

final class SystemClock implements ClockInterface
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable();
    }
}
