<?php

declare(strict_types=1);

namespace App\GraphQL\Resolver;

use App\Application\Asset\Command\StartUploadBatchCommand;
use App\Application\Asset\Command\StartUploadBatchFileCommand;
use App\Application\Asset\Result\StartUploadBatchFileResult;
use App\Application\Asset\Result\StartUploadBatchFileSuccess;
use App\Application\Asset\Result\UserError;
use App\Application\Asset\StartUploadService;
use App\GraphQL\Exception\MissingAccountContextException;

final class StartUploadBatchResolver
{
    public function __construct(
        private readonly StartUploadService $startUploadService,
    ) {
    }

    /**
     * @param array<string,mixed> $args
     * @return array<string,mixed>
     */
    public function resolve(array $args, mixed $context): array
    {
        $input = $args['input'] ?? null;
        if (! is_array($input)) {
            $input = [];
        }

        $files = $input['files'] ?? [];

        $result = $this->startUploadService->startUploadBatch(
            new StartUploadBatchCommand(
                accountId: $this->accountId($context),
                files: array_map(
                    static fn (array $file): StartUploadBatchFileCommand => new StartUploadBatchFileCommand(
                        clientFileId: (string) ($file['clientFileId'] ?? ''),
                        fileName: (string) ($file['fileName'] ?? ''),
                        mimeType: (string) ($file['mimeType'] ?? ''),
                        chunkCount: (int) ($file['chunkCount'] ?? 0),
                    ),
                    is_array($files) ? $files : [],
                ),
            ),
        );

        return [
            'files' => array_map(
                fn (StartUploadBatchFileResult $file): array => $this->mapFileResult($file),
                $result->files,
            ),
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
     * @return array{
     *     clientFileId: string,
     *     success: array{
     *         asset: array{id: string, status: string},
     *         uploadTargets: list<array{
     *             url: string,
     *             method: string,
     *             signedHeaders: list<array{name: string, value: string}>,
     *             completionProof: array{name: string, source: string},
     *             expiresAt: string
     *         }>,
     *         uploadGrant: string
     *     }|null,
     *     userErrors: list<array{code: string, message: string, field: string|null}>
     * }
     */
    private function mapFileResult(StartUploadBatchFileResult $file): array
    {
        return [
            'clientFileId' => $file->clientFileId,
            'success' => $file->success === null ? null : $this->mapFileSuccess($file->success),
            'userErrors' => array_map(
                fn (UserError $userError): array => $this->mapUserError($userError),
                $file->userErrors,
            ),
        ];
    }

    /**
     * @return array{
     *     asset: array{id: string, status: string},
     *     uploadTargets: list<array{
     *         url: string,
     *         method: string,
     *         signedHeaders: list<array{name: string, value: string}>,
     *         completionProof: array{name: string, source: string},
     *         expiresAt: string
     *     }>,
     *     uploadGrant: string
     * }
     */
    private function mapFileSuccess(StartUploadBatchFileSuccess $success): array
    {
        $asset = $success->asset;

        if ($asset['status'] instanceof \BackedEnum) {
            $asset['status'] = (string) $asset['status']->value;
        }

        return [
            'asset' => $asset,
            'uploadTargets' => $success->uploadTargets,
            'uploadGrant' => $success->uploadGrant,
        ];
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
