<?php

declare(strict_types=1);

namespace App\GraphQL\Resolver;

use App\Application\Asset\Command\GetAssetQuery;
use App\Application\Asset\GetAssetService;
use App\GraphQL\Exception\MissingAccountContextException;
use GraphQL\Error\Error;
use GraphQL\Type\Definition\ResolveInfo;

final class GetAssetResolver
{
    private const INVALID_ASSET_ID_CODE = 'INVALID_INPUT';
    private const INVALID_ASSET_ID_CATEGORY = 'validation';
    private const INVALID_ASSET_ID_MESSAGE = 'Asset id must be a UUIDv4 string.';
    private const UUID_V4_PATTERN = '/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-4[0-9a-fA-F]{3}-[89abAB][0-9a-fA-F]{3}-[0-9a-fA-F]{12}$/';

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

        $result = $this->getAssetService->getAsset(
            new GetAssetQuery(
                accountId: $this->accountId($context),
                assetId: $this->validatedAssetId($args['id'] ?? null),
            ),
        );

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

    private function validatedAssetId(mixed $assetId): string
    {
        if (is_string($assetId) && preg_match(self::UUID_V4_PATTERN, $assetId) === 1) {
            return $assetId;
        }

        throw new Error(
            message: self::INVALID_ASSET_ID_MESSAGE,
            extensions: [
                'code' => self::INVALID_ASSET_ID_CODE,
                'category' => self::INVALID_ASSET_ID_CATEGORY,
            ],
        );
    }
}
