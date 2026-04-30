<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Application\Transaction\TransactionManagerInterface;
use PDO;

final class PDOTransactionManager implements TransactionManagerInterface
{
    public function __construct(
        private readonly PDO $connection,
    ) {
    }

    public function beginTransaction(): void
    {
        $this->connection->beginTransaction();
    }

    public function commit(): void
    {
        $this->connection->commit();
    }

    public function rollBack(): void
    {
        if ($this->connection->inTransaction()) {
            $this->connection->rollBack();
        }
    }
}
