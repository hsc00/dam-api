<?php

declare(strict_types=1);

namespace App\Application\Outbox;

interface OutboxRepositoryInterface
{
    public function enqueue(string $queueName, string $payload): void;
}
