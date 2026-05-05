<?php

declare(strict_types=1);

namespace App\Application\Exception;

final class SuppressedFailure
{
    private const MAX_ACKNOWLEDGED_FAILURES = 16;

    /**
     * @var list<array{type: string}>
     */
    private static array $acknowledgedFailures = [];

    public static function acknowledge(\Throwable $suppressed): void
    {
        self::$acknowledgedFailures[] = [
            'type' => get_debug_type($suppressed),
        ];

        if (count(self::$acknowledgedFailures) > self::MAX_ACKNOWLEDGED_FAILURES) {
            array_shift(self::$acknowledgedFailures);
        }
    }

    public static function clearAcknowledgements(): void
    {
        self::$acknowledgedFailures = [];
    }
}
