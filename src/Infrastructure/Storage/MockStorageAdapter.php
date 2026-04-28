<?php

declare(strict_types=1);

namespace App\Infrastructure\Storage;

use App\Domain\Asset\Asset;
use App\Domain\Asset\StorageAdapterInterface;
use App\Domain\Asset\UploadCompletionProofSource;
use App\Domain\Asset\UploadHttpMethod;
use App\Domain\Asset\ValueObject\UploadCompletionProof;
use App\Domain\Asset\ValueObject\UploadTarget;
use DateTimeImmutable;

final class MockStorageAdapter implements StorageAdapterInterface
{
    private const COMPLETION_PROOF_NAME = 'etag';
    private const DETERMINISTIC_EXPIRY = '2100-01-01T00:00:00+00:00';
    private const URL_TEMPLATE = 'mock://uploads/%s/chunk/%d';

    /**
     * @return list<UploadTarget>
     */
    public function createUploadTargets(Asset $asset): array
    {
        $targets = [];

        $completionProof = new UploadCompletionProof(self::COMPLETION_PROOF_NAME, UploadCompletionProofSource::RESPONSE_HEADER);
        $expiry = new DateTimeImmutable(self::DETERMINISTIC_EXPIRY);

        for ($chunkIndex = 0; $chunkIndex < $asset->getChunkCount(); $chunkIndex++) {
            $targets[] = new UploadTarget(
                sprintf(self::URL_TEMPLATE, (string) $asset->getUploadId(), $chunkIndex),
                UploadHttpMethod::PUT,
                [],
                $completionProof,
                $expiry,
            );
        }

        return $targets;
    }
}
