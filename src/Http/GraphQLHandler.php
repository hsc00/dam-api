<?php

declare(strict_types=1);

namespace App\Http;

use App\Application\Exception\SuppressedFailure;
use App\GraphQL\SchemaFactory;
use GraphQL\Error\Error as GraphQLError;
use GraphQL\Error\FormattedError;
use GraphQL\Executor\ExecutionResult;
use GraphQL\GraphQL;
use Psr\Log\LoggerInterface;

/**
 * @phpstan-type FormattedGraphQLError array{
 *     message: string,
 *     locations?: array<int, array{line: int, column: int}>,
 *     path?: array<int, int|string>,
 *     extensions?: array<string, mixed>
 * }
 */
final class GraphQLHandler
{
    private const INTERNAL_SERVER_ERROR_MESSAGE = 'Internal server error';
    private const JSON_CONTENT_TYPE = 'application/json; charset=utf-8';
    private const INTERNAL_SERVER_ERROR_CODE = 'INTERNAL_SERVER_ERROR';
    private const INTERNAL_SERVER_ERROR_CATEGORY = 'INTERNAL';
    private const BAD_USER_INPUT_CODE = 'BAD_USER_INPUT';
    private const DEFAULT_USER_ERROR_CATEGORY = 'USER';

    public function __construct(
        private readonly SchemaFactory $schemaFactory,
        private readonly string $accountId,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @return array{status: int, headers: array<string, string>, body: string}
     */
    public function handle(string $method, string $path, string $rawBody): array
    {
        if ($path !== '/graphql') {
            return [
                'status' => 404,
                'headers' => ['Content-Type' => 'text/plain; charset=utf-8'],
                'body' => "Not found\n",
            ];
        }

        if (strtoupper($method) !== 'POST') {
            return $this->graphQLErrorResponse(405, new GraphQLError('Only POST /graphql is supported.'));
        }

        return $this->handleGraphQLRequest($rawBody);
    }

    /**
     * @return array{status: int, headers: array<string, string>, body: string}
     */
    private function handleGraphQLRequest(string $rawBody): array
    {
        $operationName = null;

        try {
            $payload = $this->decodeJsonPayload($rawBody);
            $query = $this->extractQuery($payload);
            $operationName = $this->extractOperationName($payload);
            $variables = $this->extractVariables($payload);

            $result = $this->executeGraphQLQuery($query, $variables, $operationName);

            return $this->jsonResponse(200, $result);
        } catch (\Throwable $e) {
            return $this->graphQLRequestErrorResponse($e, $operationName);
        }
    }

    /**
     * @return array{status: int, headers: array<string, string>, body: string}
     */
    private function graphQLRequestErrorResponse(\Throwable $error, ?string $operationName): array
    {
        if ($error instanceof \InvalidArgumentException) {
            return $this->graphQLErrorResponse(400, new GraphQLError($error->getMessage()));
        }

        if ($error instanceof GraphQLError) {
            return $this->jsonResponse(200, $this->graphQLErrorResult($error));
        }

        try {
            $this->logger->error('GraphQL handler error', ['exception' => $error, 'operation' => $operationName]);
        } catch (\Throwable $suppressed) {
            SuppressedFailure::acknowledge($suppressed);
        }

        return $this->graphQLErrorResponse(500, $error);
    }

    /**
     * @return array{status: int, headers: array<string, string>, body: string}
     */
    private function graphQLErrorResponse(int $status, \Throwable $error): array
    {
        return $this->jsonResponse($status, [
            'errors' => [
                $this->formatGraphQLError($error),
            ],
        ]);
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array{status: int, headers: array<string, string>, body: string}
     */
    private function jsonResponse(int $status, array $payload): array
    {
        if (isset($payload['errors'])) {
            try {
                @file_put_contents(__DIR__ . '/../../build/graphql_debug.json', json_encode($payload, JSON_THROW_ON_ERROR));
            } catch (\Throwable $e) {
                // best-effort debugging; do not interfere with normal error handling
            }
        }
        return [
            'status' => $status,
            'headers' => ['Content-Type' => self::JSON_CONTENT_TYPE],
            'body' => json_encode($payload, JSON_THROW_ON_ERROR),
        ];
    }

    /**
     * Decode and validate the incoming JSON payload.
     *
     * @return array<string, mixed>
     *
     * @throws \InvalidArgumentException when payload is invalid
     */
    private function decodeJsonPayload(string $rawBody): array
    {
        $payload = json_decode($rawBody, true);

        if (! is_array($payload)) {
            throw new \InvalidArgumentException('Request body must be a JSON object.');
        }

        return $payload;
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @throws \InvalidArgumentException
     */
    private function extractQuery(array $payload): string
    {
        $query = $payload['query'] ?? null;

        if (! is_string($query) || trim($query) === '') {
            throw new \InvalidArgumentException('GraphQL requests must include a non-empty query string.');
        }

        return $query;
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return string|null
     *
     * @throws \InvalidArgumentException
     */
    private function extractOperationName(array $payload): ?string
    {
        $operationName = $payload['operationName'] ?? null;

        if ($operationName !== null && ! is_string($operationName)) {
            throw new \InvalidArgumentException('operationName must be a string when provided.');
        }

        return $operationName;
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>|null
     *
     * @throws \InvalidArgumentException
     */
    private function extractVariables(array $payload): ?array
    {
        $variables = $payload['variables'] ?? null;

        if ($variables !== null && ! is_array($variables)) {
            throw new \InvalidArgumentException('variables must be an object when provided.');
        }

        return $variables;
    }

    /**
     * @param array<string,mixed>|null $variables
     * @return array<string,mixed>
     */
    private function executeGraphQLQuery(string $query, ?array $variables, ?string $operationName): array
    {
        $result = GraphQL::executeQuery(
            $this->schemaFactory->create(),
            $query,
            null,
            ['accountId' => $this->accountId],
            $variables,
            $operationName,
        );
        $result->setErrorFormatter($this->formatGraphQLError(...));

        return $result->toArray();
    }

    /**
     * @return array<string, mixed>
     */
    private function graphQLErrorResult(GraphQLError $error): array
    {
        $result = new ExecutionResult(
            data: $this->dataWithNullAtPath($error->getPath()),
            errors: [$error],
        );
        $result->setErrorFormatter($this->formatGraphQLError(...));

        return $result->toArray();
    }

    /**
     * @param list<int|string>|null $path
     *
     * @return array<string, mixed>|null
     */
    private function dataWithNullAtPath(?array $path): ?array
    {
        if ($path === null || $path === []) {
            return null;
        }

        $rootSegment = $path[0];

        if (! is_string($rootSegment)) {
            return null;
        }

        /** @var mixed $data */
        $data = null;

        for ($index = count($path) - 1; $index >= 1; --$index) {
            $segment = $path[$index];

            if (is_int($segment)) {
                $list = array_fill(0, $segment + 1, null);
                $list[$segment] = $data;
                $data = $list;

                continue;
            }

            $data = [$segment => $data];
        }

        return [$rootSegment => $data];
    }

    /**
     * @return FormattedGraphQLError
     */
    private function formatGraphQLError(\Throwable $error): array
    {
        // If this is not a GraphQL\Error\Error, treat it as internal.
        if (! $error instanceof GraphQLError) {
            return [
                'message' => self::INTERNAL_SERVER_ERROR_MESSAGE,
                'extensions' => [
                    'code' => self::INTERNAL_SERVER_ERROR_CODE,
                    'category' => self::INTERNAL_SERVER_ERROR_CATEGORY,
                ],
            ];
        }

        // Non-client-safe GraphQL errors wrap internal failures and must stay sanitized.
        if (! $error->isClientSafe()) {
            return [
                'message' => self::INTERNAL_SERVER_ERROR_MESSAGE,
                'extensions' => [
                    'code' => self::INTERNAL_SERVER_ERROR_CODE,
                    'category' => self::INTERNAL_SERVER_ERROR_CATEGORY,
                ],
            ];
        }

        // Start from the library-formatted error to preserve locations/path and
        // any other metadata; then override message and extensions with
        // sanitized values.
        $formatted = FormattedError::createFromException($error);

        $formatted['extensions'] = $this->buildExtensions($formatted['extensions'] ?? $error->getExtensions());

        $formatted['message'] = $error->getMessage();

        return $formatted;
    }

    /**
     * Normalize and validate the extensions array we expose to clients.
     *
     * @param array<string, mixed>|null $originalExtensions
     * @return array<string, string>
     */
    private function buildExtensions(?array $originalExtensions): array
    {
        if (is_array($originalExtensions) && isset($originalExtensions['code']) && is_string($originalExtensions['code']) && $originalExtensions['code'] !== '') {
            $code = $originalExtensions['code'];
        } else {
            $code = self::BAD_USER_INPUT_CODE;
        }

        if (is_array($originalExtensions) && isset($originalExtensions['category']) && is_string($originalExtensions['category']) && $originalExtensions['category'] !== '') {
            $category = $originalExtensions['category'];
        } else {
            $category = self::DEFAULT_USER_ERROR_CATEGORY;
        }

        return ['code' => $code, 'category' => $category];
    }
}
