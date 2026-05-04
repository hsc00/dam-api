<?php

declare(strict_types=1);

namespace App\GraphQL\Resolver;

use App\Application\Asset\Command\GetAssetQuery;
use App\Application\Asset\GetAssetService;
use App\GraphQL\Exception\MissingAccountContextException;
use GraphQL\Type\Definition\ResolveInfo;
use InvalidArgumentException;

final class GetAssetResolver
{
    public function __construct(
        private readonly GetAssetService $getAssetService,
    ) {
    }

    /**
     * @param array<string, mixed> $args
     *
     * @return array{id: string, status: string, readSource: string}|null
     */
    public function resolve(mixed $_rootValue, array $args, mixed $context, ResolveInfo $_info): ?array
    {
        unset($_rootValue, $_info);

        $assetId = $args['id'] ?? null;

        try {
            $result = $this->getAssetService->getAsset(
                new GetAssetQuery(
                    accountId: $this->accountId($context),
                    assetId: is_string($assetId) ? $assetId : '',
                ),
            );
        } catch (InvalidArgumentException) {
            return null;
        }

        if ($result === null) {
            return null;
        }

        return [
            'id' => $result->id,
            'status' => $result->status->value,
            'readSource' => $result->readSource->value,
        ];
    }

    private function accountId(mixed $context): string
    {
        if (is_array($context) && isset($context['accountId']) && is_string($context['accountId'])) {
            return $context['accountId'];
        }

        throw MissingAccountContextException::missing();
    }
}
