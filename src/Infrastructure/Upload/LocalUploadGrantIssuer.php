<?php

declare(strict_types=1);

namespace App\Infrastructure\Upload;

use App\Application\Asset\UploadGrantIssuerInterface;
use App\Domain\Asset\Asset;

final class LocalUploadGrantIssuer implements UploadGrantIssuerInterface
{
    private const TOKEN_PREFIX = 'local';

    public function __construct(
        private readonly string $secret,
    ) {
    }

    public function issueForAsset(Asset $asset): string
    {
        $payload = (string) $asset->getId();
        $signature = hash_hmac('sha256', $payload, $this->secret, true);

        return sprintf(
            '%s.%s.%s',
            self::TOKEN_PREFIX,
            $this->base64UrlEncode($payload),
            $this->base64UrlEncode($signature),
        );
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
