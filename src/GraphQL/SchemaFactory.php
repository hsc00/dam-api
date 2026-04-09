<?php

declare(strict_types=1);

namespace App\GraphQL;

use App\GraphQL\Exception\SchemaLoadException;
use App\GraphQL\Resolver\CompleteUploadResolver;
use App\GraphQL\Resolver\StartUploadBatchResolver;
use App\GraphQL\Resolver\StartUploadResolver;
use DateTimeImmutable;
use GraphQL\Error\Error;
use GraphQL\Language\AST\IntValueNode;
use GraphQL\Language\AST\Node;
use GraphQL\Language\AST\StringValueNode;
use GraphQL\Type\Definition\ResolveInfo;
use GraphQL\Type\Schema;
use GraphQL\Utils\BuildSchema;

final class SchemaFactory
{
    public function __construct(
        private readonly StartUploadResolver $startUploadResolver,
        private readonly StartUploadBatchResolver $startUploadBatchResolver,
        private readonly CompleteUploadResolver $completeUploadResolver,
    ) {
    }

    public function create(): Schema
    {
        $schemaSource = file_get_contents(__DIR__ . '/Schema/schema.graphql');

        if (! is_string($schemaSource)) {
            throw SchemaLoadException::unableToLoad();
        }

        return BuildSchema::build(
            $schemaSource,
            function (array $typeConfig): array {
                return match ($typeConfig['name'] ?? null) {
                    'Mutation' => $this->decorateMutation($typeConfig),
                    'ByteCount' => $this->decorateByteCountScalar($typeConfig),
                    'DateTime' => $this->decorateDateTimeScalar($typeConfig),
                    default => $typeConfig,
                };
            },
        );
    }

    /**
     * @param array<string, mixed> $typeConfig
     *
     * @return array<string, mixed>
     */
    private function decorateMutation(array $typeConfig): array
    {
        $typeConfig['resolveField'] = function (mixed $source, array $args, mixed $contextValue, ResolveInfo $resolveInfo): mixed {
            return match ($resolveInfo->fieldName) {
                'startUpload' => $this->startUploadResolver->resolve($args, $contextValue),
                'startUploadBatch' => $this->startUploadBatchResolver->resolve($args, $contextValue),
                'completeUpload' => $this->completeUploadResolver->resolve($source, $args, $contextValue, $resolveInfo),
                default => null,
            };
        };

        return $typeConfig;
    }

    /**
     * @param array<string, mixed> $typeConfig
     *
     * @return array<string, mixed>
     */
    private function decorateByteCountScalar(array $typeConfig): array
    {
        $typeConfig['serialize'] = static fn (mixed $value): string => self::normalizeByteCount($value);
        $typeConfig['parseValue'] = static fn (mixed $value): string => self::normalizeByteCount($value);
        $typeConfig['parseLiteral'] = static function (Node $valueNode): string {
            if ($valueNode instanceof IntValueNode || $valueNode instanceof StringValueNode) {
                return self::normalizeByteCount($valueNode->value);
            }

            throw new Error('ByteCount must be a non-negative integer string.');
        };

        return $typeConfig;
    }

    /**
     * @param array<string, mixed> $typeConfig
     *
     * @return array<string, mixed>
     */
    private function decorateDateTimeScalar(array $typeConfig): array
    {
        $typeConfig['serialize'] = static fn (mixed $value): string => self::normalizeDateTime($value);

        return $typeConfig;
    }

    private static function normalizeByteCount(mixed $value): string
    {
        if (is_int($value) && $value >= 0) {
            return (string) $value;
        }

        if (is_string($value) && preg_match('/^(0|[1-9]\d*)$/', $value) === 1) {
            return $value;
        }

        throw new Error('ByteCount must be a non-negative integer string.');
    }

    private static function normalizeDateTime(mixed $value): string
    {
        if (! is_string($value)) {
            throw new Error('DateTime values must serialize from ISO 8601 strings.');
        }

        try {
            $dt = new DateTimeImmutable($value);
        } catch (\Throwable $e) {
            throw new Error('DateTime values must serialize from ISO 8601 strings.');
        }

        return $dt->format(DATE_ATOM);
    }
}
