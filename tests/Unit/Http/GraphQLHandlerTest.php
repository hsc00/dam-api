<?php

declare(strict_types=1);

namespace App\Tests\Unit\Http;

use App\Application\Asset\CompleteUploadService;
use App\Application\Asset\StartUploadService;
use App\Domain\Asset\Asset;
use App\Domain\Asset\AssetRepositoryInterface;
use App\Domain\Asset\ValueObject\AccountId;
use App\Domain\Asset\ValueObject\AssetId;
use App\Domain\Asset\ValueObject\UploadId;
use App\GraphQL\Resolver\CompleteUploadResolver;
use App\GraphQL\Resolver\StartUploadBatchResolver;
use App\GraphQL\Resolver\StartUploadResolver;
use App\GraphQL\SchemaFactory;
use App\Http\GraphQLHandler;
use App\Infrastructure\Storage\MockStorageAdapter;
use App\Infrastructure\Upload\LocalUploadGrantIssuer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class GraphQLHandlerTest extends TestCase
{
    #[Test]
    public function itExecutesStartUploadThroughTheLocalGraphQlHandler(): void
    {
        // Arrange
        [$handler, $repository] = $this->createHandler();
        $requestBody = json_encode([
            'query' => <<<'GRAPHQL'
mutation StartUpload($input: StartUploadInput!) {
  startUpload(input: $input) {
    success {
      asset {
        id
        status
      }
      uploadGrant
      uploadTarget {
        url
        method
        completionProof {
          name
          source
        }
        signedHeaders {
          name
          value
        }
        expiresAt
      }
    }
    userErrors {
      code
      message
      field
    }
  }
}
GRAPHQL,
            'variables' => [
                'input' => [
                    'fileName' => 'single.png',
                    'mimeType' => 'image/png',
                    'fileSizeBytes' => '42',
                    'checksumSha256' => 'checksum',
                ],
            ],
        ], JSON_THROW_ON_ERROR);

        // Act
        $response = $handler->handle('POST', '/graphql', $requestBody);
        $payload = json_decode($response['body'], true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);

        // Assert
        self::assertSame(200, $response['status']);
        self::assertSame('application/json; charset=utf-8', $response['headers']['Content-Type']);
        self::assertArrayNotHasKey('errors', $payload);
        self::assertSame('PENDING', $payload['data']['startUpload']['success']['asset']['status']);
        self::assertSame([], $payload['data']['startUpload']['userErrors']);
        self::assertStringStartsWith('mock://uploads/', $payload['data']['startUpload']['success']['uploadTarget']['url']);
        self::assertStringEndsWith('/chunk/0', $payload['data']['startUpload']['success']['uploadTarget']['url']);
        self::assertIsString($payload['data']['startUpload']['success']['uploadGrant']);
        self::assertStringNotContainsString('uploadId', json_encode($payload['data']['startUpload'], JSON_THROW_ON_ERROR));
        self::assertCount(1, $repository->savedAssets);
        self::assertSame(1, $repository->savedAssets[0]->getChunkCount());
    }

    #[Test]
    public function itExecutesStartUploadBatchWithPerFilePartialSuccess(): void
    {
        // Arrange
        [$handler, $repository] = $this->createHandler();
        $requestBody = json_encode([
            'query' => <<<'GRAPHQL'
                mutation StartUploadBatch($input: StartUploadBatchInput!) {
                startUploadBatch(input: $input) {
                    userErrors {
                    code
                    message
                    field
                    }
                    files {
                    clientFileId
                    success {
                        asset {
                        id
                        status
                        }
                        uploadGrant
                        uploadTargets {
                        url
                        method
                        }
                    }
                    userErrors {
                        code
                        message
                        field
                    }
                    }
                }
                }
                GRAPHQL,
            'variables' => [
                'input' => [
                    'files' => [
                        [
                            'clientFileId' => 'alpha',
                            'fileName' => 'first.png',
                            'mimeType' => 'image/png',
                            'chunkCount' => 2,
                        ],
                        [
                            'clientFileId' => 'beta',
                            'fileName' => 'second.png',
                            'mimeType' => 'image/png',
                            'chunkCount' => 1,
                        ],
                        [
                            'clientFileId' => 'beta',
                            'fileName' => 'third.png',
                            'mimeType' => 'image/png',
                            'chunkCount' => 3,
                        ],
                    ],
                ],
            ],
        ], JSON_THROW_ON_ERROR);

        // Act
        $response = $handler->handle('POST', '/graphql', $requestBody);
        $payload = json_decode($response['body'], true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);

        // Assert
        self::assertSame(200, $response['status']);
        self::assertArrayNotHasKey('errors', $payload);
        self::assertSame([], $payload['data']['startUploadBatch']['userErrors']);
        self::assertCount(3, $payload['data']['startUploadBatch']['files']);
        self::assertSame('alpha', $payload['data']['startUploadBatch']['files'][0]['clientFileId']);
        self::assertCount(2, $payload['data']['startUploadBatch']['files'][0]['success']['uploadTargets']);
        self::assertSame([], $payload['data']['startUploadBatch']['files'][0]['userErrors']);
        self::assertSame('beta', $payload['data']['startUploadBatch']['files'][1]['clientFileId']);
        self::assertNull($payload['data']['startUploadBatch']['files'][1]['success']);
        self::assertSame('DUPLICATE_CLIENT_FILE_ID', $payload['data']['startUploadBatch']['files'][1]['userErrors'][0]['code']);
        self::assertSame('beta', $payload['data']['startUploadBatch']['files'][2]['clientFileId']);
        self::assertNull($payload['data']['startUploadBatch']['files'][2]['success']);
        self::assertSame('DUPLICATE_CLIENT_FILE_ID', $payload['data']['startUploadBatch']['files'][2]['userErrors'][0]['code']);
        self::assertCount(1, $repository->savedAssets);
        self::assertSame(2, $repository->savedAssets[0]->getChunkCount());
    }

    #[Test]
    public function itReturnsTopLevelBatchValidationErrorsWhenStartUploadBatchReceivesNoFiles(): void
    {
        // Arrange
        [$handler, $repository] = $this->createHandler();
        $requestBody = json_encode([
            'query' => <<<'GRAPHQL'
                mutation StartUploadBatch($input: StartUploadBatchInput!) {
                startUploadBatch(input: $input) {
                    userErrors {
                    code
                    message
                    field
                    }
                    files {
                    clientFileId
                    }
                }
                }
                GRAPHQL,
            'variables' => [
                'input' => [
                    'files' => [],
                ],
            ],
        ], JSON_THROW_ON_ERROR);

        // Act
        $response = $handler->handle('POST', '/graphql', $requestBody);
        $payload = json_decode($response['body'], true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);

        // Assert
        self::assertSame(200, $response['status']);
        self::assertArrayNotHasKey('errors', $payload);
        self::assertSame([], $payload['data']['startUploadBatch']['files']);
        self::assertCount(1, $payload['data']['startUploadBatch']['userErrors']);
        self::assertSame('EMPTY_BATCH', $payload['data']['startUploadBatch']['userErrors'][0]['code']);
        self::assertSame('At least one file is required.', $payload['data']['startUploadBatch']['userErrors'][0]['message']);
        self::assertSame('files', $payload['data']['startUploadBatch']['userErrors'][0]['field']);
        self::assertCount(0, $repository->savedAssets);
    }

    #[Test]
    public function itReturnsTopLevelBatchValidationErrorsWhenStartUploadBatchReceivesTooManyFiles(): void
    {
        // Arrange
        [$handler, $repository] = $this->createHandler();
        $requestBody = json_encode([
        'query' => <<<'GRAPHQL'
                mutation StartUploadBatch($input: StartUploadBatchInput!) {
                    startUploadBatch(input: $input) {
                        userErrors {
                            code
                            message
                            field
                        }
                        files {
                            clientFileId
                        }
                    }
                }
                GRAPHQL,
            'variables' => [
                    'input' => [
                            'files' => array_map(
                                static fn (int $index): array => [
                                            'clientFileId' => sprintf('file-%d', $index),
                                            'fileName' => sprintf('file-%d.png', $index),
                                            'mimeType' => 'image/png',
                                            'chunkCount' => 1,
                                    ],
                                range(1, 21),
                            ),
                    ],
            ],
        ], JSON_THROW_ON_ERROR);

        // Act
        $response = $handler->handle('POST', '/graphql', $requestBody);
        $payload = json_decode($response['body'], true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);

        // Assert
        self::assertSame(200, $response['status']);
        self::assertArrayNotHasKey('errors', $payload);
        self::assertSame([], $payload['data']['startUploadBatch']['files']);
        self::assertCount(1, $payload['data']['startUploadBatch']['userErrors']);
        self::assertSame('BATCH_TOO_LARGE', $payload['data']['startUploadBatch']['userErrors'][0]['code']);
        self::assertSame('You can upload at most 20 files in one request.', $payload['data']['startUploadBatch']['userErrors'][0]['message']);
        self::assertSame('files', $payload['data']['startUploadBatch']['userErrors'][0]['field']);
        self::assertCount(0, $repository->savedAssets);
    }

    #[Test]
    public function itReturnsPerFileValidationErrorsWhenStartUploadBatchReceivesChunkCountsOutsideTheAllowedRange(): void
    {
        // Arrange
        [$handler, $repository] = $this->createHandler();
        $requestBody = json_encode([
            'query' => <<<'GRAPHQL'
                mutation StartUploadBatch($input: StartUploadBatchInput!) {
                startUploadBatch(input: $input) {
                    userErrors {
                    code
                    message
                    field
                    }
                    files {
                    clientFileId
                    success {
                        asset {
                        id
                        status
                        }
                        uploadTargets {
                        url
                        }
                    }
                    userErrors {
                        code
                        message
                        field
                    }
                    }
                }
                }
                GRAPHQL,
            'variables' => [
                'input' => [
                    'files' => [
                        [
                            'clientFileId' => 'alpha',
                            'fileName' => 'first.png',
                            'mimeType' => 'image/png',
                            'chunkCount' => 100,
                        ],
                        [
                            'clientFileId' => 'beta',
                            'fileName' => 'second.png',
                            'mimeType' => 'image/png',
                            'chunkCount' => 101,
                        ],
                    ],
                ],
            ],
        ], JSON_THROW_ON_ERROR);

        // Act
        $response = $handler->handle('POST', '/graphql', $requestBody);
        $payload = json_decode($response['body'], true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);

        // Assert
        self::assertSame(200, $response['status']);
        self::assertArrayNotHasKey('errors', $payload);
        self::assertSame([], $payload['data']['startUploadBatch']['userErrors']);
        self::assertCount(2, $payload['data']['startUploadBatch']['files']);
        self::assertSame('alpha', $payload['data']['startUploadBatch']['files'][0]['clientFileId']);
        self::assertCount(100, $payload['data']['startUploadBatch']['files'][0]['success']['uploadTargets']);
        self::assertSame([], $payload['data']['startUploadBatch']['files'][0]['userErrors']);
        self::assertSame('beta', $payload['data']['startUploadBatch']['files'][1]['clientFileId']);
        self::assertNull($payload['data']['startUploadBatch']['files'][1]['success']);
        self::assertSame('INVALID_CHUNK_COUNT', $payload['data']['startUploadBatch']['files'][1]['userErrors'][0]['code']);
        self::assertSame('Chunk count must be between 1 and 100.', $payload['data']['startUploadBatch']['files'][1]['userErrors'][0]['message']);
        self::assertSame('chunkCount', $payload['data']['startUploadBatch']['files'][1]['userErrors'][0]['field']);
        self::assertCount(1, $repository->savedAssets);
        self::assertSame(100, $repository->savedAssets[0]->getChunkCount());
    }

    #[Test]
    public function itExecutesCompleteUploadThroughTheLocalGraphQlHandler(): void
    {
        // Arrange
        [$handler, $repository, $outbox] = $this->createHandler();
        $asset = Asset::createPending(
            UploadId::generate(),
            new AccountId('local-test-account'),
            'complete.png',
            'image/png',
        );
        $repository->save($asset);
        $requestBody = json_encode([
            'query' => <<<'GRAPHQL'
                mutation CompleteUpload($input: CompleteUploadInput!) {
                completeUpload(input: $input) {
                    success {
                    asset {
                        id
                        status
                    }
                    }
                    userErrors {
                    code
                    message
                    field
                    }
                }
                }
                GRAPHQL,
            'variables' => [
                'input' => [
                    'assetId' => (string) $asset->getId(),
                    'uploadGrant' => (new LocalUploadGrantIssuer('test-secret'))->issueForAsset($asset),
                    'completionProof' => 'etag-complete',
                ],
            ],
        ], JSON_THROW_ON_ERROR);

        // Act
        $response = $handler->handle('POST', '/graphql', $requestBody);
        $payload = json_decode($response['body'], true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);

        // Assert
        self::assertSame(200, $response['status']);
        self::assertArrayNotHasKey('errors', $payload);
        self::assertSame([], $payload['data']['completeUpload']['userErrors']);
        self::assertSame('PROCESSING', $payload['data']['completeUpload']['success']['asset']['status']);
        self::assertSame('etag-complete', $repository->savedAssets[0]->getCompletionProof()?->value);

        self::assertCount(1, $outbox->messages);
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($outbox->messages[0]['payload'], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame((string) $asset->getId(), $decoded['assetId']);
    }

    /**
     * @return array{0: GraphQLHandler, 1: InMemoryAssetRepository, 2: InMemoryOutboxRepository}
     */
    private function createHandler(): array
    {
        $repository = new InMemoryAssetRepository();
        $uploadGrantIssuer = new LocalUploadGrantIssuer('test-secret');

        $outbox = new InMemoryOutboxRepository();
        $transactionManager = $this->createMock(\App\Application\Transaction\TransactionManagerInterface::class);

        $startUploadService = new StartUploadService(
            $repository,
            new MockStorageAdapter(),
            $uploadGrantIssuer,
        );
        $completeUploadService = new CompleteUploadService(
            $repository,
            $uploadGrantIssuer,
            $transactionManager,
            $outbox,
        );
        $schemaFactory = new SchemaFactory(
            new StartUploadResolver($startUploadService),
            new StartUploadBatchResolver($startUploadService),
            new CompleteUploadResolver($completeUploadService),
        );

        return [new GraphQLHandler($schemaFactory, 'local-test-account', new NullLogger()), $repository, $outbox];
    }
}

final class InMemoryOutboxRepository implements \App\Application\Outbox\OutboxRepositoryInterface
{
    /** @var list<array{queue: string, payload: string}> */
    public array $messages = [];

    public function enqueue(string $queueName, string $payload): void
    {
        $this->messages[] = ['queue' => $queueName, 'payload' => $payload];
    }
}

final class InMemoryAssetRepository implements AssetRepositoryInterface
{
    /**
     * @var list<Asset>
     */
    public array $savedAssets = [];

    public function save(Asset $asset): void
    {
        $existingIndex = $this->findSavedAssetIndex($asset->getId());

        if ($existingIndex === null) {
            $this->savedAssets[] = $asset;

            return;
        }

        $this->savedAssets[$existingIndex] = $asset;
    }

    public function findById(AssetId $assetId): ?Asset
    {
        return $this->findSavedAsset(
            static fn (Asset $asset): bool => (string) $asset->getId() === (string) $assetId,
        );
    }

    public function findByUploadId(UploadId $uploadId): ?Asset
    {
        return $this->findSavedAsset(
            static fn (Asset $asset): bool => (string) $asset->getUploadId() === (string) $uploadId,
        );
    }

    public function searchByFileName(AccountId $accountId, string $query): array
    {
        return [];
    }

    private function findSavedAssetIndex(AssetId $assetId): ?int
    {
        foreach ($this->savedAssets as $index => $savedAsset) {
            if ((string) $savedAsset->getId() === (string) $assetId) {
                return $index;
            }
        }

        return null;
    }

    private function findSavedAsset(callable $matches): ?Asset
    {
        foreach ($this->savedAssets as $asset) {
            if ($matches($asset)) {
                return $asset;
            }
        }

        return null;
    }
}
