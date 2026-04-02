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
    private const CHUNK_INDEX = 0;
    private const COMPLETION_PROOF_NAME = 'etag';
    private const DETERMINISTIC_EXPIRY = '2100-01-01T00:00:00+00:00';
    private const URL_TEMPLATE = 'mock://uploads/%s/chunk/%d';

    public function createUploadTarget(Asset $asset): UploadTarget
    {
        return new UploadTarget(
            sprintf(self::URL_TEMPLATE, (string) $asset->getUploadId(), self::CHUNK_INDEX),
            UploadHttpMethod::PUT,
            [],
            new UploadCompletionProof(self::COMPLETION_PROOF_NAME, UploadCompletionProofSource::RESPONSE_HEADER),
            new DateTimeImmutable(self::DETERMINISTIC_EXPIRY),
        );
    }
}
