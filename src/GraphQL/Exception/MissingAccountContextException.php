<?php

declare(strict_types=1);

namespace App\GraphQL\Exception;

use RuntimeException;

final class MissingAccountContextException extends RuntimeException
{
    public static function missing(): self
    {
        return new self('Missing account context.');
    }
}
