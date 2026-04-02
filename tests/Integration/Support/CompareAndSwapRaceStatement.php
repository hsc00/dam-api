<?php

declare(strict_types=1);

namespace App\Tests\Integration\Support;

use Closure;
use PDOStatement;

final class CompareAndSwapRaceStatement extends PDOStatement
{
    private bool $hasTriggeredCompareAndSwapRace = false;

    protected function __construct(
        private readonly Closure $beforeCompareAndSwap,
    ) {
    }

    /**
     * @param array<string|int, mixed>|null $params
     */
    public function execute(?array $params = null): bool
    {
        if (! $this->hasTriggeredCompareAndSwapRace) {
            ($this->beforeCompareAndSwap)();
            $this->hasTriggeredCompareAndSwapRace = true;
        }

        return parent::execute($params);
    }
}
