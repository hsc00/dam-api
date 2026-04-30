<?php

declare(strict_types=1);

namespace App\Infrastructure\Processing;

final readonly class ReservedAssetProcessingJob
{
    public function __construct(
        private string $payload,
        private \Closure $acknowledgeJob,
        private \Closure $releaseJob,
    ) {
    }

    public function payload(): string
    {
        return $this->payload;
    }

    public function acknowledge(): void
    {
        ($this->acknowledgeJob)();
    }

    public function discard(): void
    {
        $this->acknowledge();
    }

    public function release(): void
    {
        ($this->releaseJob)();
    }
}
