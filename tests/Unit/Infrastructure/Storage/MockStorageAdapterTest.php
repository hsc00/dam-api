<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\Storage;

use App\Domain\Asset\Asset;
use App\Domain\Asset\AssetStatus;
use App\Domain\Asset\UploadCompletionProofSource;
use App\Domain\Asset\UploadHttpMethod;
use App\Domain\Asset\ValueObject\AccountId;
use App\Domain\Asset\ValueObject\AssetId;
use App\Domain\Asset\ValueObject\UploadId;
use App\Domain\Asset\ValueObject\UploadTarget;
use App\Infrastructure\Storage\MockStorageAdapter;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class MockStorageAdapterTest extends TestCase
{
    private const ACCOUNT_ID = 'account-123';
    private const CREATED_AT = '2026-04-01T12:00:00+00:00';
    private const DETERMINISTIC_EXPIRY = '2100-01-01T00:00:00+00:00';
    private const FILE_NAME = 'image.png';
    private const FIRST_ASSET_ID = '11111111-1111-4111-8111-111111111111';
    private const FIRST_UPLOAD_ID = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
    private const MIME_TYPE = 'image/png';
    private const SECOND_ASSET_ID = '22222222-2222-4222-8222-222222222222';
    private const SECOND_UPLOAD_ID = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb';
    private const UPDATED_AT = '2026-04-01T12:05:00+00:00';

    #[Test]
    public function itReturnsADomainValidDeterministicTargetForAnAcceptedAsset(): void
    {
        // Arrange
        $adapter = new MockStorageAdapter();
        $asset = $this->pendingAsset(self::FIRST_ASSET_ID, self::FIRST_UPLOAD_ID, 4);

        // Act
        $targets = $adapter->createUploadTargets($asset);

        // Assert
        self::assertSame([
            'mock://uploads/' . self::FIRST_UPLOAD_ID . '/chunk/0',
            'mock://uploads/' . self::FIRST_UPLOAD_ID . '/chunk/1',
            'mock://uploads/' . self::FIRST_UPLOAD_ID . '/chunk/2',
            'mock://uploads/' . self::FIRST_UPLOAD_ID . '/chunk/3',
        ], $this->targetUrls($targets));
        self::assertSame(UploadHttpMethod::PUT, $targets[0]->method);
        self::assertSame([], $targets[0]->signedHeaders);
        self::assertSame('etag', $targets[0]->completionProof->name);
        self::assertSame(UploadCompletionProofSource::RESPONSE_HEADER, $targets[0]->completionProof->source);
        self::assertEquals(new DateTimeImmutable(self::DETERMINISTIC_EXPIRY), $targets[0]->expiresAt);
    }

    #[Test]
    public function itReturnsTheSameUploadTargetsWhenCalledRepeatedlyForTheSameAsset(): void
    {
        // Arrange
        $adapter = new MockStorageAdapter();
        $asset = $this->pendingAsset(self::FIRST_ASSET_ID, self::FIRST_UPLOAD_ID, 2);

        // Act
        $firstTargets = $adapter->createUploadTargets($asset);
        $secondTargets = $adapter->createUploadTargets($asset);

        // Assert
        self::assertSame($this->targetsSnapshot($firstTargets), $this->targetsSnapshot($secondTargets));
    }

    #[Test]
    public function itReturnsDifferentUrlsForDifferentAssets(): void
    {
        // Arrange
        $adapter = new MockStorageAdapter();
        $firstAsset = $this->pendingAsset(self::FIRST_ASSET_ID, self::FIRST_UPLOAD_ID, 2);
        $secondAsset = $this->pendingAsset(self::SECOND_ASSET_ID, self::SECOND_UPLOAD_ID, 2);

        // Act
        $firstTargets = $adapter->createUploadTargets($firstAsset);
        $secondTargets = $adapter->createUploadTargets($secondAsset);

        // Assert
        self::assertSame([
            'mock://uploads/' . self::FIRST_UPLOAD_ID . '/chunk/0',
            'mock://uploads/' . self::FIRST_UPLOAD_ID . '/chunk/1',
        ], $this->targetUrls($firstTargets));
        self::assertSame([
            'mock://uploads/' . self::SECOND_UPLOAD_ID . '/chunk/0',
            'mock://uploads/' . self::SECOND_UPLOAD_ID . '/chunk/1',
        ], $this->targetUrls($secondTargets));
    }

    private function pendingAsset(string $assetId, string $uploadId, int $chunkCount = 1): Asset
    {
        return Asset::reconstitute(
            new AssetId($assetId),
            new UploadId($uploadId),
            new AccountId(self::ACCOUNT_ID),
            self::FILE_NAME,
            self::MIME_TYPE,
            AssetStatus::PENDING,
            [
                'createdAt' => new DateTimeImmutable(self::CREATED_AT),
                'chunkCount' => $chunkCount,
                'updatedAt' => new DateTimeImmutable(self::UPDATED_AT),
            ],
        );
    }

    /**
     * @param list<UploadTarget> $targets
     *
     * @return list<array{
     *     url: string,
     *     method: string,
     *     signedHeaders: list<string>,
     *     completionProofName: string,
     *     completionProofSource: string,
     *     expiresAt: string
     * }>
     */
    private function targetsSnapshot(array $targets): array
    {
        return array_map(
            fn (UploadTarget $target): array => [
                'url' => $target->url,
                'method' => $target->method->value,
                'signedHeaders' => array_map(
                    static fn ($signedHeader): string => $signedHeader->name . ':' . $signedHeader->value,
                    $target->signedHeaders,
                ),
                'completionProofName' => $target->completionProof->name,
                'completionProofSource' => $target->completionProof->source->value,
                'expiresAt' => $target->expiresAt->format(DATE_ATOM),
            ],
            $targets,
        );
    }

    /**
     * @param list<UploadTarget> $targets
     *
     * @return list<string>
     */
    private function targetUrls(array $targets): array
    {
        return array_map(
            static fn (UploadTarget $target): string => $target->url,
            $targets,
        );
    }
}
