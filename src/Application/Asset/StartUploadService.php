<?php

declare(strict_types=1);

namespace App\Application\Asset;

use App\Application\Asset\Command\StartUploadBatchCommand;
use App\Application\Asset\Command\StartUploadBatchFileCommand;
use App\Application\Asset\Command\StartUploadCommand;
use App\Application\Asset\Exception\UnexpectedSingleTargetException;
use App\Application\Asset\Exception\UnexpectedTargetCountException;
use App\Application\Asset\Result\StartUploadBatchFileResult;
use App\Application\Asset\Result\StartUploadBatchFileSuccess;
use App\Application\Asset\Result\StartUploadBatchResult;
use App\Application\Asset\Result\StartUploadResult;
use App\Application\Asset\Result\StartUploadSuccess;
use App\Application\Asset\Result\UserError;
use App\Domain\Asset\Asset;
use App\Domain\Asset\AssetRepositoryInterface;
use App\Domain\Asset\Exception\AssetDomainException;
use App\Domain\Asset\Exception\InvalidChunkCountException;
use App\Domain\Asset\Exception\InvalidFileNameException;
use App\Domain\Asset\Exception\InvalidMimeTypeException;
use App\Domain\Asset\Exception\RepositoryUnavailableException;
use App\Domain\Asset\Exception\StorageUnavailableException;
use App\Domain\Asset\StorageAdapterInterface;
use App\Domain\Asset\ValueObject\AccountId;
use App\Domain\Asset\ValueObject\UploadId;
use App\Domain\Asset\ValueObject\UploadTarget;

final class StartUploadService
{
    private const DUPLICATE_CLIENT_FILE_ID_CODE = 'DUPLICATE_CLIENT_FILE_ID';
    private const DUPLICATE_CLIENT_FILE_ID_MESSAGE = 'Each file in startUploadBatch must use a distinct clientFileId.';
    private const INVALID_CHUNK_COUNT_CODE = 'INVALID_CHUNK_COUNT';
    private const INVALID_CLIENT_FILE_ID_CODE = 'INVALID_CLIENT_FILE_ID';
    private const INVALID_CLIENT_FILE_ID_MESSAGE = 'clientFileId must be non-empty.';
    private const INVALID_FILE_NAME_CODE = 'INVALID_FILE_NAME';
    private const INVALID_MIME_TYPE_CODE = 'INVALID_MIME_TYPE';
    private const SINGLE_FILE_CLIENT_FILE_ID = '__single_file_upload__';
    private const UNEXPECTED_BATCH_RESULT_CODE = 'UPLOAD_INITIATION_FAILED';
    private const UNEXPECTED_BATCH_RESULT_MESSAGE = 'Upload initiation did not return a result.';
    private const UNEXPECTED_SINGLE_TARGET_MESSAGE = 'Storage adapter must return exactly one upload target for single-file uploads.';

    public function __construct(
        private readonly AssetRepositoryInterface $assets,
        private readonly StorageAdapterInterface $storage,
        private readonly UploadGrantIssuerInterface $uploadGrantIssuer,
    ) {
    }

    public function startUpload(StartUploadCommand $command): StartUploadResult
    {
        $batchResult = $this->startUploadBatch(
            new StartUploadBatchCommand(
                accountId: $command->accountId,
                files: [
                    new StartUploadBatchFileCommand(
                        clientFileId: self::SINGLE_FILE_CLIENT_FILE_ID,
                        fileName: $command->fileName,
                        mimeType: $command->mimeType,
                        chunkCount: 1,
                    ),
                ],
            ),
        );

        $fileResult = $batchResult->files[0] ?? null;

        if ($fileResult === null) {
            return new StartUploadResult(
                success: null,
                userErrors: [new UserError(self::UNEXPECTED_BATCH_RESULT_CODE, self::UNEXPECTED_BATCH_RESULT_MESSAGE)],
            );
        }

        if ($fileResult->success === null) {
            return new StartUploadResult(success: null, userErrors: $fileResult->userErrors);
        }

        if (count($fileResult->success->uploadTargets) !== 1) {
            throw new UnexpectedSingleTargetException(self::UNEXPECTED_SINGLE_TARGET_MESSAGE);
        }

        return new StartUploadResult(
            success: new StartUploadSuccess(
                asset: $fileResult->success->asset,
                uploadTarget: $fileResult->success->uploadTargets[0],
                uploadGrant: $fileResult->success->uploadGrant,
            ),
            userErrors: [],
        );
    }

    public function startUploadBatch(StartUploadBatchCommand $command): StartUploadBatchResult
    {
        $accountId = new AccountId($command->accountId);
        $duplicatedClientFileIds = $this->duplicatedClientFileIds($command->files);
        $files = [];

        foreach ($command->files as $file) {
            $files[] = $this->processBatchFile($accountId, $file, $duplicatedClientFileIds);
        }

        return new StartUploadBatchResult($files);
    }

    /**
     * Handle a single batch file entry and return a file result.
     * Extracted to lower cognitive complexity of the public batch method.
     *
     * @param array<string, true> $duplicatedClientFileIds
     */
    private function processBatchFile(AccountId $accountId, StartUploadBatchFileCommand $file, array $duplicatedClientFileIds): StartUploadBatchFileResult
    {
        $clientFileId = trim($file->clientFileId);
        $validationError = $this->validateClientFileId($clientFileId);
        $success = null;
        $userErrors = [];

        if ($validationError !== null) {
            $userErrors = [$validationError];
        } elseif (isset($duplicatedClientFileIds[$clientFileId])) {
            $userErrors = [
                new UserError(
                    self::DUPLICATE_CLIENT_FILE_ID_CODE,
                    self::DUPLICATE_CLIENT_FILE_ID_MESSAGE,
                    'clientFileId',
                ),
            ];
        } else {
            try {
                $success = $this->initiateUpload($accountId, $file);
            } catch (AssetDomainException $exception) {
                // Infrastructure failures (repository/storage unavailability)
                // should surface as server errors, not user-facing errors.
                if ($exception instanceof RepositoryUnavailableException || $exception instanceof StorageUnavailableException) {
                    throw $exception;
                }

                $userErrors = [$this->mapDomainException($exception)];
            }
        }

        return new StartUploadBatchFileResult(
            clientFileId: $clientFileId,
            success: $success,
            userErrors: $userErrors,
        );
    }

    private function validateClientFileId(string $clientFileId): ?UserError
    {
        if ($clientFileId === '') {
            return new UserError(
                self::INVALID_CLIENT_FILE_ID_CODE,
                self::INVALID_CLIENT_FILE_ID_MESSAGE,
                'clientFileId',
            );
        }

        return null;
    }

    private function initiateUpload(AccountId $accountId, StartUploadBatchFileCommand $file): StartUploadBatchFileSuccess
    {
        $asset = Asset::createPending(
            UploadId::generate(),
            $accountId,
            $file->fileName,
            $file->mimeType,
            $file->chunkCount,
        );

        try {
            $this->assets->save($asset);
        } catch (\Throwable $e) {
            // Do not attempt to mark or persist a failed asset if the initial
            // repository save failed — creating a "born-failed" record is
            // inconsistent. Surface a RepositoryUnavailableException instead,
            // preserving the original exception as the cause.
            throw RepositoryUnavailableException::forReason('Repository failure', $e);
        }

        try {
            $uploadTargets = $this->storage->createUploadTargets($asset);
        } catch (\Throwable $e) {
            // Ensure we do not leave a pending orphaned asset when storage fails.
            try {
                $asset->markFailed();
                $this->assets->save($asset);
            } catch (\Throwable $suppressed) {
                // If saving the failed state also fails, suppress secondary errors
                // to preserve the original failure as the root cause.
                unset($suppressed);
            }

            throw StorageUnavailableException::forReason('Storage adapter failure', $e);
        }
        $this->assertUploadTargetsMatchChunkCount($asset, $uploadTargets);

        return new StartUploadBatchFileSuccess(
            asset: [
                'id' => (string) $asset->getId(),
                'status' => $asset->getStatus(),
            ],
            uploadTargets: array_map(
                fn (UploadTarget $uploadTarget): array => $this->mapUploadTarget($uploadTarget),
                $uploadTargets,
            ),
            uploadGrant: $this->uploadGrantIssuer->issueForAsset($asset),
        );
    }

    /**
     * @param list<StartUploadBatchFileCommand> $files
     *
     * @return array<string, true>
     */
    private function duplicatedClientFileIds(array $files): array
    {
        $counts = [];

        foreach ($files as $file) {
            $clientFileId = trim($file->clientFileId);

            if ($clientFileId === '') {
                continue;
            }

            $counts[$clientFileId] = ($counts[$clientFileId] ?? 0) + 1;
        }

        $duplicates = [];

        foreach ($counts as $clientFileId => $count) {
            if ($count > 1) {
                $duplicates[$clientFileId] = true;
            }
        }

        return $duplicates;
    }

    private function mapDomainException(AssetDomainException $exception): UserError
    {
        $code = self::UNEXPECTED_BATCH_RESULT_CODE;
        $message = $exception->getMessage();
        $field = null;

        if ($exception instanceof InvalidChunkCountException) {
            $code = self::INVALID_CHUNK_COUNT_CODE;
            $field = 'chunkCount';
        } elseif ($exception instanceof InvalidFileNameException) {
            $code = self::INVALID_FILE_NAME_CODE;
            $field = 'fileName';
        } elseif ($exception instanceof InvalidMimeTypeException) {
            $code = self::INVALID_MIME_TYPE_CODE;
            $field = 'mimeType';
        }

        if ($field !== null) {
            return new UserError($code, $message, $field);
        }

        return new UserError($code, $message);
    }

    /**
     * @param list<UploadTarget> $uploadTargets
     */
    private function assertUploadTargetsMatchChunkCount(Asset $asset, array $uploadTargets): void
    {
        $actual = count($uploadTargets);
        $expected = $asset->getChunkCount();

        if ($actual !== $expected) {
            throw UnexpectedTargetCountException::fromCounts($expected, $actual);
        }

        $distinctUrls = [];

        foreach ($uploadTargets as $uploadTarget) {
            $distinctUrls[$uploadTarget->url] = true;
        }

        if (count($distinctUrls) !== $expected) {
            throw UnexpectedTargetCountException::fromCounts($expected, count($distinctUrls));
        }
    }

    /**
     * @return array{
     *     url: string,
     *     method: string,
     *     signedHeaders: list<array{name: string, value: string}>,
     *     completionProof: array{name: string, source: string},
     *     expiresAt: string
     * }
     */
    private function mapUploadTarget(UploadTarget $uploadTarget): array
    {
        return [
            'url' => $uploadTarget->url,
            'method' => $uploadTarget->method->value,
            'signedHeaders' => array_map(
                static fn ($signedHeader): array => [
                    'name' => $signedHeader->name,
                    'value' => $signedHeader->value,
                ],
                $uploadTarget->signedHeaders,
            ),
            'completionProof' => [
                'name' => $uploadTarget->completionProof->name,
                'source' => $uploadTarget->completionProof->source->value,
            ],
            'expiresAt' => $uploadTarget->expiresAt->format(DATE_ATOM),
        ];
    }
}
