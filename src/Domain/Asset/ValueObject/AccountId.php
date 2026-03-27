<?php

declare(strict_types=1);

namespace App\Domain\Asset\ValueObject;

final readonly class AccountId
{
    public string $value;

    /**
     * @throws \InvalidArgumentException
     */
    public function __construct(string $value)
    {
        $trimmedValue = trim($value);

        if ($trimmedValue === '') {
            throw new \InvalidArgumentException('AccountId cannot be empty');
        }

        $this->value = $trimmedValue;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
