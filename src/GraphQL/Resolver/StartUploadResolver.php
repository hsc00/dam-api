<?php

declare(strict_types=1);

namespace App\GraphQL\Resolver;

use App\Application\Asset\Command\StartUploadCommand;
use App\Application\Asset\Result\StartUploadSuccess;
use App\Application\Asset\Result\UserError;
use App\Application\Asset\StartUploadService;
use App\GraphQL\Exception\MissingAccountContextException;

final class StartUploadResolver
{
    private const INPUT_MUST_BE_OBJECT_MESSAGE = 'input must be an object';
    private const INVALID_FILE_SIZE_MESSAGE = 'fileSizeBytes must be a non-negative integer';

    public function __construct(
        private readonly StartUploadService $startUploadService,
    ) {
    }

    /**
     * @param array<string, mixed> $args
     *
    * @return array{
    *     success: array{
    *         asset: array{id: string, status: string},
     *         uploadTarget: array{
     *             url: string,
     *             method: string,
     *             signedHeaders: list<array{name: string, value: string}>,
     *             completionProof: array{name: string, source: string},
     *             expiresAt: string
     *         },
     *         uploadGrant: string
     *     }|null,
     *     userErrors: list<array{code: string, message: string, field: string|null}>
     * }
     */
    public function resolve(array $args, mixed $context): array
    {
        $input = $args['input'] ?? null;

        if (! is_array($input)) {
            return [
                'success' => null,
                'userErrors' => [
                    $this->userError('BAD_USER_INPUT', self::INPUT_MUST_BE_OBJECT_MESSAGE),
                ],
            ];
        }

        $userErrors = [];

        ['value' => $fileName, 'error' => $fileNameError] = $this->validateRequiredString(
            $input['fileName'] ?? null,
            'INVALID_FILE_NAME',
            'fileName',
            'fileName is required',
        );
        ['value' => $mimeType, 'error' => $mimeTypeError] = $this->validateRequiredString(
            $input['mimeType'] ?? null,
            'INVALID_MIME_TYPE',
            'mimeType',
            'mimeType is required',
        );
        ['value' => $validatedFileSize, 'error' => $fileSizeError] = $this->validateFileSize($input['fileSizeBytes'] ?? null);
        ['value' => $checksum, 'error' => $checksumError] = $this->validateRequiredString(
            $input['checksumSha256'] ?? null,
            'INVALID_CHECKSUM',
            'checksumSha256',
            'checksumSha256 is required',
        );

        foreach ([$fileNameError, $mimeTypeError, $fileSizeError, $checksumError] as $error) {
            if ($error !== null) {
                $userErrors[] = $error;
            }
        }

        if ($userErrors !== []) {
            return ['success' => null, 'userErrors' => $userErrors];
        }

        $result = $this->startUploadService->startUpload(
            new StartUploadCommand(
                accountId: $this->accountId($context),
                fileName: $fileName,
                mimeType: $mimeType,
                fileSizeBytes: $validatedFileSize,
                checksumSha256: $checksum,
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
     * @return array{value: string, error: array{code: string, message: string, field: string|null}|null}
     */
    private function validateRequiredString(mixed $value, string $code, string $field, string $message): array
    {
        if (! is_string($value) || trim($value) === '') {
            return [
                'value' => '',
                'error' => $this->userError($code, $message, $field),
            ];
        }

        return [
            'value' => $value,
            'error' => null,
        ];
    }

    /**
     * @return array{value: int, error: array{code: string, message: string, field: string|null}|null}
     */
    private function validateFileSize(mixed $value): array
    {
        if (is_int($value)) {
            return $value >= 0
                ? ['value' => $value, 'error' => null]
                : ['value' => 0, 'error' => $this->userError('INVALID_FILE_SIZE', self::INVALID_FILE_SIZE_MESSAGE, 'fileSizeBytes')];
        }

        if (is_string($value)) {
            $filtered = filter_var(
                $value,
                FILTER_VALIDATE_INT,
                ['options' => ['min_range' => 0, 'max_range' => PHP_INT_MAX]],
            );

            if ($filtered !== false) {
                return ['value' => $filtered, 'error' => null];
            }
        }

        return [
            'value' => 0,
            'error' => $this->userError('INVALID_FILE_SIZE', self::INVALID_FILE_SIZE_MESSAGE, 'fileSizeBytes'),
        ];
    }

    /**
     * @return array{code: string, message: string, field: string|null}
     */
    private function userError(string $code, string $message, ?string $field = null): array
    {
        return [
            'code' => $code,
            'message' => $message,
            'field' => $field,
        ];
    }

    /**
     * @return array{
     *     asset: array{id: string, status: string},
     *     uploadTarget: array{
     *         url: string,
     *         method: string,
     *         signedHeaders: list<array{name: string, value: string}>,
     *         completionProof: array{name: string, source: string},
     *         expiresAt: string
     *     },
     *     uploadGrant: string
     * }
     */
    private function mapSuccess(StartUploadSuccess $success): array
    {
        $asset = $success->asset;

        if ($asset['status'] instanceof \BackedEnum) {
            $asset['status'] = (string) $asset['status']->value;
        }

        return [
            'asset' => $asset,
            'uploadTarget' => $success->uploadTarget,
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
