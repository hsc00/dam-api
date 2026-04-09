<?php

declare(strict_types=1);

namespace App\Application\Asset\Result;

final readonly class StartUploadSuccess
{
    /**
     * @param array{id: string, status: \App\Domain\Asset\AssetStatus} $asset
     * @param array{
     *     url: string,
     *     method: string,
     *     signedHeaders: list<array{name: string, value: string}>,
     *     completionProof: array{name: string, source: string},
     *     expiresAt: string
     * } $uploadTarget
     */
    public function __construct(
        public array $asset,
        public array $uploadTarget,
        public string $uploadGrant,
    ) {
    }
}
