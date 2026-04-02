<?php

declare(strict_types=1);

namespace App\Tests\Integration\Support;

use Closure;
use PDO;
use PDOStatement;

final class CompareAndSwapRacePdo extends PDO
{
    private const UPDATE_ASSETS_PREFIX = 'UPDATE assets';

    public function __construct(
        string $dsn,
        string $user,
        string $password,
        private readonly Closure $beforeCompareAndSwap,
    ) {
        parent::__construct(
            $dsn,
            $user,
            $password,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ],
        );
    }

    /**
     * @param array<int, mixed> $options
     */
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        if (str_starts_with(ltrim($query), self::UPDATE_ASSETS_PREFIX)) {
            $options[PDO::ATTR_STATEMENT_CLASS] = [
                CompareAndSwapRaceStatement::class,
                [$this->beforeCompareAndSwap],
            ];
        }

        return parent::prepare($query, $options);
    }
}
