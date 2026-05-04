<?php

declare(strict_types=1);

namespace App\Application\Exception;

final class SuppressedFailure
{
    private const LOG_MESSAGE_TEMPLATE = 'Suppressed secondary failure [%s]: %s';

    public static function acknowledge(\Throwable $suppressed): void
    {
        error_log(sprintf(
            self::LOG_MESSAGE_TEMPLATE,
            get_debug_type($suppressed),
            $suppressed->getMessage(),
        ));
    }
}
