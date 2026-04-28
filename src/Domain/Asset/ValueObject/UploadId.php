<?php

declare(strict_types=1);

namespace App\Domain\Asset\ValueObject;

final readonly class UploadId
{
    private const UUID_PATTERN = '/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-4[0-9a-fA-F]{3}-[89abAB][0-9a-fA-F]{3}-[0-9a-fA-F]{12}$/';

    public static function generate(): self
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        $hex = bin2hex($bytes);

        return new self(sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12),
        ));
    }

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
