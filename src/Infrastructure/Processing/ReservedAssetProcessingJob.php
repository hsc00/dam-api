<?php

declare(strict_types=1);

namespace App\Infrastructure\Processing;

final readonly class ReservedAssetProcessingJob
{
    /**
     * @param \Closure(): void $acknowledgeJob
     * @param \Closure(string): void $releaseJob
     * @param \Closure(string): void $deadLetterJob
     */
    public function __construct(
        private string $payload,
        private \Closure $acknowledgeJob,
        private \Closure $releaseJob,
        private \Closure $deadLetterJob,
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

    public function release(?string $payload = null): void
    {
        ($this->releaseJob)($payload ?? $this->payload);
    }

    public function deadLetter(?string $payload = null): void
    {
        ($this->deadLetterJob)($payload ?? $this->payload);
    }
}
