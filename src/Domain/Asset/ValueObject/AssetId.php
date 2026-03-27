<?php

declare(strict_types=1);

namespace App\Domain\Asset\ValueObject;

final readonly class AssetId
{
    private const UUID_PATTERN = '/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[1-8][0-9a-fA-F]{3}-[89abAB][0-9a-fA-F]{3}-[0-9a-fA-F]{12}$/';

    public string $value;

    /**
     * @throws \InvalidArgumentException
     */
    public function __construct(string $value)
    {
        if (! preg_match(self::UUID_PATTERN, $value)) {
            throw new \InvalidArgumentException('Invalid AssetId format');
        }

        $this->value = $value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
