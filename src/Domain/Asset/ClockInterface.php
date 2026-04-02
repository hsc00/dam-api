<?php

declare(strict_types=1);

namespace App\Domain\Asset;

use DateTimeImmutable;

interface ClockInterface
{
    public function now(): DateTimeImmutable;
}
