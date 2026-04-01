<?php

declare(strict_types=1);

namespace App\Domain\Asset\ValueObject;

final readonly class UploadParameter
{
    public string $name;

    public function __construct(string $name, public string $value)
    {
        $normalizedName = trim($name);

        if ($normalizedName === '') {
            throw new \InvalidArgumentException('Upload parameter name cannot be empty');
        }

        $this->name = $normalizedName;
    }
}
