<?php

declare(strict_types=1);

namespace App\Domain\Asset\ValueObject;

final readonly class AccountId
{
    public function __construct(public string $value)
    {
        if (!preg_match('/^[0-9a-fA-F\-]{36}$/', $value)) {
            throw new \InvalidArgumentException('Invalid AccountId format');
        }
    }

    public function __toString(): string {
        return $this->value;
    }
}
