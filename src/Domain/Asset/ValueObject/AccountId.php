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

    /**
     * Named constructor for clearer call-sites.
     *
     * @throws \InvalidArgumentException
     */
    public static function fromString(string $value): self
    {
        return new self($value);
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
