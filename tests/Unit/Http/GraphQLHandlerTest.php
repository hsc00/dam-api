<?php

declare(strict_types=1);

namespace App\Tests\Unit\Http;

use App\Application\Asset\AssetStatusCacheInterface;
use App\Application\Asset\CompleteUploadService;
use App\Application\Asset\GetAssetService;
use App\Application\Asset\Result\SearchAssetsPageInfo;
use App\Application\Asset\SearchAssetsService;
use App\Application\Asset\StartUploadService;
use App\Domain\Asset\Asset;
use App\Domain\Asset\AssetRepositoryInterface;
use App\Domain\Asset\AssetStatus;
use App\Domain\Asset\ValueObject\AccountId;
use App\Domain\Asset\ValueObject\AssetId;
use App\Domain\Asset\ValueObject\UploadCompletionProofValue;
use App\Domain\Asset\ValueObject\UploadId;
use App\GraphQL\Resolver\CompleteUploadResolver;
use App\GraphQL\Resolver\GetAssetResolver;
use App\GraphQL\Resolver\SearchAssetsResolver;
use App\GraphQL\Resolver\StartUploadBatchResolver;
use App\GraphQL\Resolver\StartUploadResolver;
use App\GraphQL\SchemaFactory;
use App\Http\GraphQLHandler;
use App\Infrastructure\Processing\NullAssetStatusCache;
use App\Infrastructure\Storage\MockStorageAdapter;
use App\Infrastructure\Upload\LocalUploadGrantIssuer;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class GraphQLHandlerTest extends TestCase
{
    private const UNKNOWN_ASSET_ID = '123e4567-e89b-42d3-a456-426614174000';

    #[Test]
    public function itReturnsGraphQlErrorExtensionsWhenTheRequestUsesANonPostMethod(): void
    {
        // Arrange
        [$handler] = $this->createHandler();

        // Act
        $response = $handler->handle('GET', '/graphql', '{}');
        $payload = json_decode($response['body'], true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);

        // Assert
        self::assertSame(405, $response['status']);
        self::assertSame('application/json; charset=utf-8', $response['headers']['Content-Type']);
        self::assertSame(
            [[
                'message' => 'Only POST /graphql is supported.',
                'extensions' => [
                    'code' => 'BAD_USER_INPUT',
                    'category' => 'USER',
                ],
            ]],
            $payload['errors'],
        );
    }

    #[Test]
    public function itReturnsGraphQlErrorExtensionsWhenTheRequestPayloadFailsValidation(): void
    {
        // Arrange
        [$handler] = $this->createHandler();

        // Act
        $response = $handler->handle('POST', '/graphql', '{}');
        $payload = json_decode($response['body'], true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);

        // Assert
        self::assertSame(400, $response['status']);
        self::assertSame(
            [[
                'message' => 'GraphQL requests must include a non-empty query string.',
                'extensions' => [
                    'code' => 'BAD_USER_INPUT',
                    'category' => 'USER',
                ],
            ]],
            $payload['errors'],
        );
    }

    #[Test]
    public function itReturnsGraphQlErrorExtensionsWhenAnInternalFailureOccursBeforeExecution(): void
    {
        // Arrange — inject a SchemaFactory stub that throws when create() is called,
        // avoiding mutating checked-in files.
        $schemaFactory = $this->createMock(SchemaFactory::class);
        $schemaFactory->method('create')->willThrowException(
            SchemaFileAccessWarning::fromPhpWarning('schema access warning', 'schema.graphql', 1, E_WARNING)
        );

        $handler = new GraphQLHandler($schemaFactory, 'local-test-account', new NullLogger());

        // Act
        $response = $handler->handle('POST', '/graphql', $this->assetQueryRequestBody(self::UNKNOWN_ASSET_ID));

        $payload = json_decode($response['body'], true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);

        // Assert
        self::assertSame(500, $response['status']);
        self::assertSame(
            [[
                'message' => 'Internal server error',
                'extensions' => [
                    'code' => 'INTERNAL_SERVER_ERROR',
                    'category' => 'INTERNAL',
                ],
            ]],
            $payload['errors'],
        );
    }

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

    #[Test]
    public function itReturnsCachedAssetStatusOnAssetQueryCacheHit(): void
    {
        // Arrange
        $cache = new ConfigurableAssetStatusCache(lookupResult: AssetStatus::PROCESSING);
        [$handler, $repository] = $this->createHandler(cache: $cache);
        $asset = $this->createProcessingAsset();
        $repository->save($asset);

        // Act
        $response = $handler->handle('POST', '/graphql', $this->assetQueryRequestBody((string) $asset->getId()));
        $payload = json_decode($response['body'], true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);

        // Assert
        self::assertSame(200, $response['status']);
        self::assertArrayNotHasKey('errors', $payload);
        self::assertSame(
            [
                'id' => (string) $asset->getId(),
                'status' => 'PROCESSING',
                'readSource' => 'FAST_CACHE',
            ],
            $payload['data']['asset'],
        );
        self::assertSame([(string) $asset->getId()], $cache->lookupCalls);
        self::assertSame([], $cache->storeCalls);
    }

    #[Test]
    public function itReturnsDurableAssetStatusAndRepairsTheCacheOnAssetQueryStatusMismatch(): void
    {
        // Arrange
        $cache = new ConfigurableAssetStatusCache(lookupResult: AssetStatus::FAILED);
        [$handler, $repository] = $this->createHandler(cache: $cache);
        $asset = $this->createProcessingAsset();
        $repository->save($asset);

        // Act
        $response = $handler->handle('POST', '/graphql', $this->assetQueryRequestBody((string) $asset->getId()));
        $payload = json_decode($response['body'], true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);

        // Assert
        self::assertSame(200, $response['status']);
        self::assertArrayNotHasKey('errors', $payload);
        self::assertSame(
            [
                'id' => (string) $asset->getId(),
                'status' => 'PROCESSING',
                'readSource' => 'DURABLE_STORE',
            ],
            $payload['data']['asset'],
        );
        self::assertSame([(string) $asset->getId()], $cache->lookupCalls);
        self::assertCount(1, $cache->storeCalls);
        self::assertSame((string) $asset->getId(), $cache->storeCalls[0]['assetId']);
        self::assertSame(AssetStatus::PROCESSING, $cache->storeCalls[0]['status']);
    }

    #[Test]
    public function itReturnsDurableAssetStatusAndSeedsTheCacheOnAssetQueryMiss(): void
    {
        // Arrange
        $cache = new ConfigurableAssetStatusCache();
        [$handler, $repository] = $this->createHandler(cache: $cache);
        $asset = $this->createProcessingAsset();
        $repository->save($asset);

        // Act
        $response = $handler->handle('POST', '/graphql', $this->assetQueryRequestBody((string) $asset->getId()));
        $payload = json_decode($response['body'], true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);

        // Assert
        self::assertSame(200, $response['status']);
        self::assertArrayNotHasKey('errors', $payload);
        self::assertSame(
            [
                'id' => (string) $asset->getId(),
                'status' => 'PROCESSING',
                'readSource' => 'DURABLE_STORE',
            ],
            $payload['data']['asset'],
        );
        self::assertSame([(string) $asset->getId()], $cache->lookupCalls);
        self::assertCount(1, $cache->storeCalls);
        self::assertSame((string) $asset->getId(), $cache->storeCalls[0]['assetId']);
        self::assertSame(AssetStatus::PROCESSING, $cache->storeCalls[0]['status']);
    }

    #[Test]
    public function itReturnsNullWithoutErrorsWhenTheAssetDoesNotExist(): void
    {
        // Arrange
        $cache = new ConfigurableAssetStatusCache(lookupResult: AssetStatus::FAILED);
        [$handler] = $this->createHandler(cache: $cache);

        // Act
        $response = $handler->handle('POST', '/graphql', $this->assetQueryRequestBody(self::UNKNOWN_ASSET_ID));
        $payload = json_decode($response['body'], true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);

        // Assert
        self::assertSame(200, $response['status']);
        self::assertArrayNotHasKey('errors', $payload);
        self::assertNull($payload['data']['asset']);
        self::assertSame([], $cache->lookupCalls);
        self::assertSame([], $cache->storeCalls);
    }

    #[Test]
    public function itReturnsNullWithoutErrorsWhenTheAssetBelongsToAnotherAccount(): void
    {
        // Arrange
        $cache = new ConfigurableAssetStatusCache(lookupResult: AssetStatus::FAILED);
        [$handler, $repository] = $this->createHandler(cache: $cache);
        $asset = $this->createProcessingAsset('another-account');
        $repository->save($asset);

        // Act
        $response = $handler->handle('POST', '/graphql', $this->assetQueryRequestBody((string) $asset->getId()));
        $payload = json_decode($response['body'], true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);

        // Assert
        self::assertSame(200, $response['status']);
        self::assertArrayNotHasKey('errors', $payload);
        self::assertNull($payload['data']['asset']);
        self::assertSame([], $cache->lookupCalls);
        self::assertSame([], $cache->storeCalls);
    }

    #[Test]
    public function itSearchesUploadedAssetsForTheAuthenticatedAccountWithExplicitGraphQlDefaults(): void
    {
        // Arrange
        [$handler, $repository] = $this->createHandler();
        $repository->save($this->reconstituteUploadedAsset(
            '123e4567-e89b-42d3-a456-426614174031',
            'Report Alpha.pdf',
            'local-test-account',
            '2026-05-01T10:00:00+00:00',
        ));
        $repository->save($this->reconstituteProcessingAsset(
            '123e4567-e89b-42d3-a456-426614174030',
            'REPORT in progress.pdf',
            'local-test-account',
            '2026-05-05T10:00:00+00:00',
        ));
        $repository->save($this->reconstituteUploadedAsset(
            '123e4567-e89b-42d3-a456-426614174010',
            'Quarterly Report.pdf',
            'local-test-account',
            '2026-05-04T09:00:00+00:00',
        ));
        $repository->save($this->reconstituteUploadedAsset(
            '123e4567-e89b-42d3-a456-426614174009',
            'report appendix.pdf',
            'local-test-account',
            '2026-05-04T09:00:00+00:00',
        ));
        $repository->save($this->reconstituteFailedAsset(
            '123e4567-e89b-42d3-a456-426614174050',
            'report failed.pdf',
            'local-test-account',
            '2026-05-03T10:00:00+00:00',
        ));
        $repository->save($this->reconstituteUploadedAsset(
            '123e4567-e89b-42d3-a456-426614174060',
            'report foreign-account.pdf',
            'another-account',
            '2026-05-06T10:00:00+00:00',
        ));

        // Act
        $response = $handler->handle('POST', '/graphql', $this->searchAssetsRequestBody('report'));
        $payload = json_decode($response['body'], true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);

        // Assert
        self::assertSame(200, $response['status']);
        self::assertArrayNotHasKey('errors', $payload);
        self::assertSame([], $payload['data']['searchAssets']['userErrors']);
        self::assertSame(3, $payload['data']['searchAssets']['totalCount']);
        self::assertSame(
            ['page' => 1, 'pageSize' => 10, 'totalPages' => 1],
            $payload['data']['searchAssets']['pageInfo'],
        );
        self::assertSame(
            [
                [
                    'id' => '123e4567-e89b-42d3-a456-426614174009',
                    'fileName' => 'report appendix.pdf',
                    'mimeType' => 'application/pdf',
                    'status' => 'UPLOADED',
                ],
                [
                    'id' => '123e4567-e89b-42d3-a456-426614174010',
                    'fileName' => 'Quarterly Report.pdf',
                    'mimeType' => 'application/pdf',
                    'status' => 'UPLOADED',
                ],
                [
                    'id' => '123e4567-e89b-42d3-a456-426614174031',
                    'fileName' => 'Report Alpha.pdf',
                    'mimeType' => 'application/pdf',
                    'status' => 'UPLOADED',
                ],
            ],
            $payload['data']['searchAssets']['files'],
        );
    }

    #[Test]
    public function itUsesExplicitSearchPaginationAtTheGraphQlBoundary(): void
    {
        // Arrange
        [$handler, $repository] = $this->createHandler();
        $repository->save($this->reconstituteUploadedAsset(
            '123e4567-e89b-42d3-a456-426614174031',
            'Report Alpha.pdf',
            'local-test-account',
            '2026-05-01T10:00:00+00:00',
        ));
        $repository->save($this->reconstituteUploadedAsset(
            '123e4567-e89b-42d3-a456-426614174010',
            'Quarterly Report.pdf',
            'local-test-account',
            '2026-05-04T09:00:00+00:00',
        ));
        $repository->save($this->reconstituteUploadedAsset(
            '123e4567-e89b-42d3-a456-426614174009',
            'report appendix.pdf',
            'local-test-account',
            '2026-05-04T09:00:00+00:00',
        ));

        // Act
        $response = $handler->handle('POST', '/graphql', $this->searchAssetsRequestBody('report', 2, 1));
        $payload = json_decode($response['body'], true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);

        // Assert
        self::assertSame(200, $response['status']);
        self::assertArrayNotHasKey('errors', $payload);
        self::assertSame([], $payload['data']['searchAssets']['userErrors']);
        self::assertSame(3, $payload['data']['searchAssets']['totalCount']);
        self::assertSame(
            ['page' => 2, 'pageSize' => 1, 'totalPages' => 3],
            $payload['data']['searchAssets']['pageInfo'],
        );
        self::assertSame(
            [[
                'id' => '123e4567-e89b-42d3-a456-426614174010',
                'fileName' => 'Quarterly Report.pdf',
                'mimeType' => 'application/pdf',
                'status' => 'UPLOADED',
            ]],
            $payload['data']['searchAssets']['files'],
        );
    }

    #[Test]
    public function itCapsExplicitSearchPageSizeAtTheConfiguredMaximum(): void
    {
        // Arrange
        [$handler, $repository] = $this->createHandler();

        foreach (range(1, SearchAssetsPageInfo::MAX_PAGE_SIZE + 1) as $index) {
            $repository->save($this->reconstituteUploadedAsset(
                sprintf('123e4567-e89b-42d3-a456-%012d', $index),
                sprintf('report-%02d.pdf', $index),
                'local-test-account',
                '2026-05-04T09:00:00+00:00',
            ));
        }

        // Act
        $response = $handler->handle(
            'POST',
            '/graphql',
            $this->searchAssetsRequestBody('report', 1, SearchAssetsPageInfo::MAX_PAGE_SIZE + 1),
        );
        $payload = json_decode($response['body'], true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);

        // Assert
        self::assertSame(200, $response['status']);
        self::assertArrayNotHasKey('errors', $payload);
        self::assertSame([], $payload['data']['searchAssets']['userErrors']);
        self::assertSame(SearchAssetsPageInfo::MAX_PAGE_SIZE + 1, $payload['data']['searchAssets']['totalCount']);
        self::assertSame(
            [
                'page' => 1,
                'pageSize' => SearchAssetsPageInfo::MAX_PAGE_SIZE,
                'totalPages' => 2,
            ],
            $payload['data']['searchAssets']['pageInfo'],
        );
        self::assertCount(SearchAssetsPageInfo::MAX_PAGE_SIZE, $payload['data']['searchAssets']['files']);
    }

    #[Test]
    public function itReturnsPayloadLevelUserErrorsForWhitespaceOnlySearchQueries(): void
    {
        // Arrange
        [$handler] = $this->createHandler();

        // Act
        $response = $handler->handle('POST', '/graphql', $this->searchAssetsRequestBody(" \n\t "));
        $payload = json_decode($response['body'], true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);

        // Assert
        self::assertSame(200, $response['status']);
        self::assertArrayNotHasKey('errors', $payload);
        self::assertSame([], $payload['data']['searchAssets']['files']);
        self::assertSame(0, $payload['data']['searchAssets']['totalCount']);
        self::assertSame(
            ['page' => 1, 'pageSize' => 10, 'totalPages' => 0],
            $payload['data']['searchAssets']['pageInfo'],
        );
        self::assertSame(
            [[
                'code' => 'EMPTY_QUERY',
                'message' => 'Enter a file name to search.',
                'field' => 'query',
            ]],
            $payload['data']['searchAssets']['userErrors'],
        );
    }

    #[Test]
    public function itReturnsASanitizedGraphQlErrorWhenSearchAssetsCountFailsDuringExecution(): void
    {
        // Arrange
        $searchAssetsRepository = $this->createMock(AssetRepositoryInterface::class);
        $searchAssetsRepository
            ->expects($this->once())
            ->method('countByFileName')
            ->willThrowException(new \RuntimeException('database unavailable during count'));
        $searchAssetsRepository
            ->expects($this->never())
            ->method('searchByFileName');

        $searchAssetsService = new SearchAssetsService($searchAssetsRepository);
        [$handler] = $this->createHandler(searchAssetsService: $searchAssetsService);

        // Act
        $response = $handler->handle('POST', '/graphql', $this->searchAssetsRequestBody('report'));
        $payload = json_decode($response['body'], true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);

        // Assert
        self::assertSame(200, $response['status']);
        self::assertArrayNotHasKey('data', $payload);
        self::assertSame(
            [[
                'message' => 'Internal server error',
                'extensions' => [
                    'code' => 'INTERNAL_SERVER_ERROR',
                    'category' => 'INTERNAL',
                ],
            ]],
            $payload['errors'],
        );
        self::assertStringNotContainsString('database unavailable during count', $response['body']);
    }

    #[Test]
    public function itReturnsASanitizedGraphQlErrorWhenSearchAssetsFailsDuringExecution(): void
    {
        // Arrange
        $searchAssetsRepository = $this->createMock(AssetRepositoryInterface::class);
        $searchAssetsRepository
            ->expects($this->once())
            ->method('countByFileName')
            ->willReturn(1);
        $searchAssetsRepository
            ->expects($this->once())
            ->method('searchByFileName')
            ->willThrowException(new \RuntimeException('database unavailable'));

        $searchAssetsService = new SearchAssetsService($searchAssetsRepository);
        [$handler] = $this->createHandler(searchAssetsService: $searchAssetsService);

        // Act
        $response = $handler->handle('POST', '/graphql', $this->searchAssetsRequestBody('report'));
        $payload = json_decode($response['body'], true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);

        // Assert
        self::assertSame(200, $response['status']);
        self::assertArrayNotHasKey('data', $payload);
        self::assertSame(
            [[
                'message' => 'Internal server error',
                'extensions' => [
                    'code' => 'INTERNAL_SERVER_ERROR',
                    'category' => 'INTERNAL',
                ],
            ]],
            $payload['errors'],
        );
        self::assertStringNotContainsString('database unavailable', $response['body']);
    }

    #[Test]
    public function itReturnsValidationErrorWhenTheAssetQueryReceivesAMalformedId(): void
    {
        // Arrange
        [$handler] = $this->createHandler();

        // Act
        $response = $handler->handle('POST', '/graphql', $this->assetQueryRequestBody('not-a-uuid'));
        $payload = json_decode($response['body'], true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);

        // Assert
        self::assertSame(200, $response['status']);
        self::assertNull($payload['data']['asset']);
        self::assertCount(1, $payload['errors']);
        self::assertSame('Asset id must be a UUIDv4 string.', $payload['errors'][0]['message']);
        self::assertSame(
            ['code' => 'INVALID_INPUT', 'category' => 'validation'],
            $payload['errors'][0]['extensions'],
        );
    }

    #[Test]
    public function itSuppressesCacheSeedFailuresOnDurableAssetReads(): void
    {
        // Arrange
        $cache = new ConfigurableAssetStatusCache(storeFailure: new \RuntimeException('cache unavailable'));
        [$handler, $repository] = $this->createHandler(cache: $cache);
        $asset = $this->createProcessingAsset();
        $repository->save($asset);

        // Act
        $response = $handler->handle('POST', '/graphql', $this->assetQueryRequestBody((string) $asset->getId()));
        $payload = json_decode($response['body'], true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);

        // Assert
        self::assertSame(200, $response['status']);
        self::assertArrayNotHasKey('errors', $payload);
        self::assertSame(
            [
                'id' => (string) $asset->getId(),
                'status' => 'PROCESSING',
                'readSource' => 'DURABLE_STORE',
            ],
            $payload['data']['asset'],
        );
        self::assertSame([(string) $asset->getId()], $cache->lookupCalls);
        self::assertCount(1, $cache->storeCalls);
    }

    /**
     * @return array{0: GraphQLHandler, 1: InMemoryAssetRepository, 2: InMemoryOutboxRepository}
     */
    private function createHandler(
        ?AssetStatusCacheInterface $cache = null,
        string $accountId = 'local-test-account',
        ?SearchAssetsService $searchAssetsService = null,
    ): array
    {
        $repository = new InMemoryAssetRepository();
        $uploadGrantIssuer = new LocalUploadGrantIssuer('test-secret');

        $outbox = new InMemoryOutboxRepository();
        $transactionManager = $this->createMock(\App\Application\Transaction\TransactionManagerInterface::class);
        $assetTerminalStatusCache = $cache ?? new NullAssetStatusCache();

        $startUploadService = new StartUploadService(
            $repository,
            new MockStorageAdapter(),
            $uploadGrantIssuer,
            $assetTerminalStatusCache,
        );
        $getAssetService = new GetAssetService(
            $repository,
            $assetTerminalStatusCache,
        );
        $searchAssetsService ??= new SearchAssetsService($repository);
        $completeUploadService = new CompleteUploadService(
            $repository,
            $uploadGrantIssuer,
            $transactionManager,
            $outbox,
            $assetTerminalStatusCache,
        );
        $schemaFactory = new SchemaFactory(
            new GetAssetResolver($getAssetService),
            new SearchAssetsResolver($searchAssetsService),
            new StartUploadResolver($startUploadService),
            new StartUploadBatchResolver($startUploadService),
            new CompleteUploadResolver($completeUploadService),
        );

        return [new GraphQLHandler($schemaFactory, $accountId, new NullLogger()), $repository, $outbox];
    }

    private function assetQueryRequestBody(string $assetId): string
    {
        return json_encode([
            'query' => <<<'GRAPHQL'
query Asset($id: ID!) {
  asset(id: $id) {
    id
    status
    readSource
  }
}
GRAPHQL,
            'variables' => ['id' => $assetId],
        ], JSON_THROW_ON_ERROR);
    }

    private function searchAssetsRequestBody(string $query, ?int $page = null, ?int $pageSize = null): string
    {
        $variables = ['query' => $query];

        if ($page !== null) {
            $variables['page'] = $page;
        }

        if ($pageSize !== null) {
            $variables['pageSize'] = $pageSize;
        }

        return json_encode([
            'query' => <<<'GRAPHQL'
query SearchAssets($query: String!, $page: Int, $pageSize: Int) {
    searchAssets(query: $query, page: $page, pageSize: $pageSize) {
        files {
            id
            fileName
            mimeType
            status
        }
        totalCount
        pageInfo {
            page
            pageSize
            totalPages
        }
        userErrors {
            code
            message
            field
        }
    }
}
GRAPHQL,
            'variables' => $variables,
        ], JSON_THROW_ON_ERROR);
    }

    private function createPendingAsset(string $accountId = 'local-test-account'): Asset
    {
        return Asset::createPending(
            UploadId::generate(),
            new AccountId($accountId),
            'report.pdf',
            'application/pdf',
        );
    }

    private function createProcessingAsset(string $accountId = 'local-test-account'): Asset
    {
        $asset = $this->createPendingAsset($accountId);
        $asset->markProcessing(new UploadCompletionProofValue('etag-processing'));

        return $asset;
    }

    private function reconstituteUploadedAsset(string $assetId, string $fileName, string $accountId, string $createdAt): Asset
    {
        return Asset::reconstituteUploaded(
            new AssetId($assetId),
            UploadId::generate(),
            new AccountId($accountId),
            $fileName,
            'application/pdf',
            new UploadCompletionProofValue('etag-uploaded'),
            ['createdAt' => new DateTimeImmutable($createdAt)],
        );
    }

    private function reconstituteProcessingAsset(string $assetId, string $fileName, string $accountId, string $createdAt): Asset
    {
        return Asset::reconstituteProcessing(
            new AssetId($assetId),
            UploadId::generate(),
            new AccountId($accountId),
            $fileName,
            'application/pdf',
            new UploadCompletionProofValue('etag-processing'),
            ['createdAt' => new DateTimeImmutable($createdAt)],
        );
    }

    private function reconstituteFailedAsset(string $assetId, string $fileName, string $accountId, string $createdAt): Asset
    {
        return Asset::reconstitute(
            new AssetId($assetId),
            UploadId::generate(),
            new AccountId($accountId),
            $fileName,
            'application/pdf',
            AssetStatus::FAILED,
            ['createdAt' => new DateTimeImmutable($createdAt)],
        );
    }
}

final class SchemaFileAccessWarning extends \RuntimeException
{
    public static function fromPhpWarning(string $message, string $file, int $line, int $severity): self
    {
        return new self(sprintf('%s in %s on line %d', $message, $file, $line), $severity);
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

    public function countByFileName(AccountId $accountId, string $query, AssetStatus $status): int
    {
        return count($this->matchingAssetsByFileName($accountId, $query, $status));
    }

    /**
     * @return list<Asset>
     */
    public function searchByFileName(AccountId $accountId, string $query, AssetStatus $status, int $offset, int $limit): array
    {
        if ($limit < 1) {
            return [];
        }

        return array_slice(
            $this->matchingAssetsByFileName($accountId, $query, $status),
            max(0, $offset),
            $limit,
        );
    }

    /**
     * @return list<Asset>
     */
    private function matchingAssetsByFileName(AccountId $accountId, string $query, AssetStatus $status): array
    {
        $trimmedQuery = trim($query);

        if ($trimmedQuery === '') {
            return [];
        }

        $matchingAssets = array_values(array_filter(
            $this->savedAssets,
            static fn (Asset $asset): bool => (string) $asset->getAccountId() === (string) $accountId
                && $asset->getStatus() === $status
                && stripos($asset->getFileName(), $trimmedQuery) !== false,
        ));

        usort(
            $matchingAssets,
            static function (Asset $left, Asset $right): int {
                $createdAtComparison = $right->getCreatedAt() <=> $left->getCreatedAt();

                if ($createdAtComparison !== 0) {
                    return $createdAtComparison;
                }

                return (string) $left->getId() <=> (string) $right->getId();
            },
        );

        return $matchingAssets;
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

final class ConfigurableAssetStatusCache implements AssetStatusCacheInterface
{
    /**
     * @var list<string>
     */
    public array $lookupCalls = [];

    /**
     * @var list<array{assetId: string, status: AssetStatus}>
     */
    public array $storeCalls = [];

    public function __construct(
        private readonly ?AssetStatus $lookupResult = null,
        private readonly ?\Throwable $lookupFailure = null,
        private readonly ?\Throwable $storeFailure = null,
    ) {
    }

    public function lookup(AssetId $assetId): ?AssetStatus
    {
        $this->lookupCalls[] = (string) $assetId;

        if ($this->lookupFailure !== null) {
            throw $this->lookupFailure;
        }

        return $this->lookupResult;
    }

    public function store(AssetId $assetId, AssetStatus $status): void
    {
        $this->storeCalls[] = [
            'assetId' => (string) $assetId,
            'status' => $status,
        ];

        if ($this->storeFailure !== null) {
            throw $this->storeFailure;
        }
    }
}
