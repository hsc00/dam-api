<?php

declare(strict_types=1);

namespace App\Domain\Asset\ValueObject;

final readonly class AccountId
{
    /**
     * @throws \InvalidArgumentException
     */
    public function __construct(public string $value)
    {
        $value = trim($value);

        if ($value === '') {
            throw new \InvalidArgumentException('AccountId cannot be empty');
        }

        $this->value = $value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
