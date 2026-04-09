<?php

declare(strict_types=1);

namespace App\GraphQL\Exception;

use RuntimeException;

final class SchemaLoadException extends RuntimeException
{
    public static function unableToLoad(): self
    {
        return new self('Unable to load GraphQL schema.');
    }
}
