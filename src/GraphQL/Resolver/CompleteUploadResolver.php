<?php

declare(strict_types=1);

namespace App\GraphQL\Resolver;

use GraphQL\Type\Definition\ResolveInfo;

final class CompleteUploadResolver
{
    /**
     * @param array<string,mixed> $args
     * @return array<string,mixed>
     */
    public function resolve(mixed $rootValue, array $args, mixed $context, ResolveInfo $info): array
    {
        return [
            'success' => null,
            'userErrors' => [
                [
                    'code' => 'NOT_IMPLEMENTED',
                    'message' => 'completeUpload is not implemented in the local runtime.',
                    'field' => null,
                ],
            ],
        ];
    }
}
