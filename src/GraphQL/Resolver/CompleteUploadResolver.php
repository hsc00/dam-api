<?php

declare(strict_types=1);

namespace App\GraphQL\Resolver;

use App\Application\Asset\Command\CompleteUploadCommand;
use App\Application\Asset\CompleteUploadService;
use App\Application\Asset\Result\CompleteUploadSuccess;
use App\Application\Asset\Result\UserError;
use App\GraphQL\Exception\MissingAccountContextException;
use GraphQL\Type\Definition\ResolveInfo;

final class CompleteUploadResolver
{
    public function __construct(
        private readonly CompleteUploadService $completeUploadService,
    ) {
    }

    /**
     * @param array<string,mixed> $args
     * @return array{
     *     success: array{asset: array{id: string, status: string}}|null,
     *     userErrors: list<array{code: string, message: string, field: string|null}>
     * }
     */
    public function resolve(mixed $_rootValue, array $args, mixed $context, ResolveInfo $_info): array
    {
        unset($_rootValue, $_info);

        $input = $args['input'] ?? null;
        $result = $this->completeUploadService->completeUpload(
            new CompleteUploadCommand(
                accountId: $this->accountId($context),
                assetId: is_array($input) ? (string) ($input['assetId'] ?? '') : '',
                uploadGrant: is_array($input) ? (string) ($input['uploadGrant'] ?? '') : '',
                completionProof: is_array($input) ? (string) ($input['completionProof'] ?? '') : '',
            ),
        );

        return [
            'success' => $result->success === null ? null : $this->mapSuccess($result->success),
            'userErrors' => array_map(
                fn (UserError $userError): array => $this->mapUserError($userError),
                $result->userErrors,
            ),
        ];
    }

    private function accountId(mixed $context): string
    {
        if (is_array($context) && isset($context['accountId']) && is_string($context['accountId'])) {
            return $context['accountId'];
        }

        throw MissingAccountContextException::missing();
    }

    /**
     * @return array{asset: array{id: string, status: string}}
     */
    private function mapSuccess(CompleteUploadSuccess $success): array
    {
        $asset = $success->asset;

        if ($asset['status'] instanceof \BackedEnum) {
            $asset['status'] = (string) $asset['status']->value;
        }

        return ['asset' => $asset];
    }

    /**
     * @return array{code: string, message: string, field: string|null}
     */
    private function mapUserError(UserError $userError): array
    {
        return [
            'code' => $userError->code,
            'message' => $userError->message,
            'field' => $userError->field,
        ];
    }
}
