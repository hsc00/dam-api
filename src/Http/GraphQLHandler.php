<?php

declare(strict_types=1);

namespace App\Http;

use App\GraphQL\SchemaFactory;
use GraphQL\Error\Error as GraphQLError;
use GraphQL\GraphQL;
use Psr\Log\LoggerInterface;

final class GraphQLHandler
{
    private const JSON_CONTENT_TYPE = 'application/json; charset=utf-8';

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
        // Default response: not found. We'll override for valid /graphql POST requests.
        $response = [
            'status' => 404,
            'headers' => ['Content-Type' => 'text/plain; charset=utf-8'],
            'body' => "Not found\n",
        ];

        if ($path === '/graphql') {
            if (strtoupper($method) === 'POST') {
                $operationName = null;

                try {
                    $payload = $this->decodeJsonPayload($rawBody);
                    $query = $this->extractQuery($payload);
                    $operationName = $this->extractOperationName($payload);
                    $variables = $this->extractVariables($payload);

                    $result = $this->executeGraphQLQuery($query, $variables, $operationName);

                    $response = $this->jsonResponse(200, $result);
                } catch (\InvalidArgumentException $e) {
                    $response = $this->jsonResponse(400, ['errors' => [['message' => $e->getMessage()]]]);
                } catch (\Throwable $e) {
                    try {
                        $this->logger->error('GraphQL handler error', ['exception' => $e, 'operation' => $operationName]);
                    } catch (\Throwable $suppressed) {
                        unset($suppressed);
                    }

                    $response = $this->jsonResponse(500, ['errors' => [['message' => 'Internal server error']]]);
                }
            } else {
                $response = $this->jsonResponse(405, [
                    'errors' => [
                        ['message' => 'Only POST /graphql is supported.'],
                    ],
                ]);
            }
        }

        return $response;
    }
    /**
     * @param array<string, mixed> $payload
     *
     * @return array{status: int, headers: array<string, string>, body: string}
     */
    private function jsonResponse(int $status, array $payload): array
    {
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
        $result->setErrorFormatter(function (\Throwable $error): array {
            // If this is not a GraphQL\Error\Error, treat it as internal.
            if (! $error instanceof GraphQLError) {
                return [
                    'message' => 'Internal server error',
                    'extensions' => [
                        'code' => 'INTERNAL_SERVER_ERROR',
                        'category' => 'INTERNAL',
                    ],
                ];
            }

            // If the GraphQL Error wraps an internal exception, return a sanitized payload.
            if ($error->getPrevious() !== null) {
                return [
                    'message' => 'Internal server error',
                    'extensions' => [
                        'code' => 'INTERNAL_SERVER_ERROR',
                        'category' => 'INTERNAL',
                    ],
                ];
            }

            // Prefer a semantic code from the error extensions when available.
            $extensions = $error->getExtensions();

            if (is_array($extensions) && isset($extensions['code']) && is_string($extensions['code']) && $extensions['code'] !== '') {
                $code = $extensions['code'];
            } else {
                $code = 'BAD_USER_INPUT';
            }

            return [
                'message' => $error->getMessage(),
                'extensions' => [
                    'code' => $code,
                    'category' => 'USER',
                ],
            ];
        });

        return $result->toArray();
    }
}
