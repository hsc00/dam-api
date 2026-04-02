<?php

declare(strict_types=1);

namespace App\Domain\Asset\ValueObject;

final readonly class UploadId
{
    private const UUID_PATTERN = '/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-4[0-9a-fA-F]{3}-[89abAB][0-9a-fA-F]{3}-[0-9a-fA-F]{12}$/';

    public static function isValid(string $value): bool
    {
        return preg_match(self::UUID_PATTERN, $value) === 1;
    }

    /**
     * @throws \InvalidArgumentException
     */
    public function __construct(public string $value)
    {
        if (! self::isValid($value)) {
            throw new \InvalidArgumentException('Invalid UploadId format');
        }
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
