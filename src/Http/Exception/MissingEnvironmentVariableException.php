<?php

declare(strict_types=1);

namespace App\Http\Exception;

final class MissingEnvironmentVariableException extends \RuntimeException
{
    public static function forName(string $name): self
    {
        return new self(sprintf('Required environment variable "%s" is not set', $name));
    }
}
