<?php

declare(strict_types=1);

namespace App\Domain\Asset\ValueObject;

final readonly class UploadId
{
    private const UUID_PATTERN = '/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-4[0-9a-fA-F]{3}-[89abAB][0-9a-fA-F]{3}-[0-9a-fA-F]{12}$/';

    /**
     * @throws \InvalidArgumentException
     */
    public function __construct(public string $value)
    {
        if (! preg_match(self::UUID_PATTERN, $value)) {
            throw new \InvalidArgumentException('Invalid UploadId format');
        }
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
