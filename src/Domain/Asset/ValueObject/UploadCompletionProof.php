<?php

declare(strict_types=1);

namespace App\Domain\Asset\ValueObject;

use App\Domain\Asset\UploadCompletionProofSource;

final readonly class UploadCompletionProof
{
    public string $name;

    public function __construct(string $name, public UploadCompletionProofSource $source)
    {
        $normalizedName = trim($name);

        if ($normalizedName === '') {
            throw new \InvalidArgumentException('Upload completion proof name cannot be empty');
        }

        $this->name = $normalizedName;
    }
}
