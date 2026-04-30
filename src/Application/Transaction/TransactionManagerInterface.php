<?php

declare(strict_types=1);

namespace App\Application\Transaction;

interface TransactionManagerInterface
{
    public function beginTransaction(): void;

    public function commit(): void;

    public function rollBack(): void;
}
