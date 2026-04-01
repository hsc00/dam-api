<?php

declare(strict_types=1);

namespace App\Domain\Asset\ValueObject;

final readonly class UploadCompletionProofValue
{
    public string $value;

    public function __construct(string $value)
    {
        $normalizedValue = trim($value);

        if ($normalizedValue === '') {
            throw new \InvalidArgumentException('Upload completion proof value cannot be empty');
        }

        $this->value = $normalizedValue;
    }
}
