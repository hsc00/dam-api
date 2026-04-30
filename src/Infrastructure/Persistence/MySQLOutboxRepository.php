<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Application\Outbox\OutboxRepositoryInterface;
use PDO;

final class MySQLOutboxRepository implements OutboxRepositoryInterface
{
    public function __construct(
        private readonly PDO $connection,
    ) {
    }

    public function enqueue(string $queueName, string $payload): void
    {
        $statement = $this->connection->prepare(
            'INSERT INTO outbox_messages (id, `queue`, payload, attempts, created_at, published_at)
             VALUES (:id, :queue, :payload, 0, UTC_TIMESTAMP(6), NULL)'
        );

        $id = $this->generateUuidV4();

        $statement->execute([
            'id' => $id,
            'queue' => $queueName,
            'payload' => $payload,
        ]);
    }

    private function generateUuidV4(): string
    {
        $data = random_bytes(16);

        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);

        $hex = bin2hex($data);

        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12),
        );
    }
}
