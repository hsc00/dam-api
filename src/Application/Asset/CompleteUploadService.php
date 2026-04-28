<?php

declare(strict_types=1);

namespace App\Application\Asset;

use App\Application\Asset\Command\CompleteUploadCommand;
use App\Application\Asset\Result\CompleteUploadResult;
use App\Application\Asset\Result\CompleteUploadSuccess;
use App\Application\Asset\Result\UserError;
use App\Domain\Asset\Asset;
use App\Domain\Asset\AssetRepositoryInterface;
use App\Domain\Asset\Exception\AssetDomainException;
use App\Domain\Asset\Exception\RepositoryUnavailableException;
use App\Domain\Asset\ValueObject\AccountId;
use App\Domain\Asset\ValueObject\AssetId;
use App\Domain\Asset\ValueObject\UploadCompletionProofValue;

final class CompleteUploadService
{
    private const ASSET_ALREADY_UPLOADED_CODE = 'ASSET_ALREADY_UPLOADED';
    private const ASSET_NOT_FOUND_CODE = 'ASSET_NOT_FOUND';
    private const ASSET_NOT_FOUND_MESSAGE = 'Asset not found.';
    private const COMPLETE_UPLOAD_FAILED_CODE = 'COMPLETE_UPLOAD_FAILED';
    private const INVALID_ASSET_ID_CODE = 'INVALID_ASSET_ID';
    private const INVALID_ASSET_ID_MESSAGE = 'assetId must be a valid asset id.';
    private const INVALID_ASSET_STATE_CODE = 'INVALID_ASSET_STATE';
    private const INVALID_COMPLETION_PROOF_CODE = 'INVALID_COMPLETION_PROOF';
    private const INVALID_COMPLETION_PROOF_MESSAGE = 'completionProof is required.';
    private const INVALID_UPLOAD_GRANT_CODE = 'INVALID_UPLOAD_GRANT';
    private const INVALID_UPLOAD_GRANT_MESSAGE = 'uploadGrant is invalid.';
    private const UPLOAD_GRANT_REQUIRED_MESSAGE = 'uploadGrant is required.';

    public function __construct(
        private readonly AssetRepositoryInterface $assets,
        private readonly UploadGrantIssuerInterface $uploadGrantIssuer,
    ) {
    }

    public function completeUpload(CompleteUploadCommand $command): CompleteUploadResult
    {
        $accountId = new AccountId($command->accountId);
        $assetId = $this->assetId($command->assetId);
        $uploadGrant = trim($command->uploadGrant);
        $completionProof = $this->completionProof($command->completionProof);
        $userErrors = [];
        $success = null;

        if ($assetId === null) {
            $userErrors[] = new UserError(self::INVALID_ASSET_ID_CODE, self::INVALID_ASSET_ID_MESSAGE, 'assetId');
        }

        if ($uploadGrant === '') {
            $userErrors[] = new UserError(self::INVALID_UPLOAD_GRANT_CODE, self::UPLOAD_GRANT_REQUIRED_MESSAGE, 'uploadGrant');
        }

        if ($completionProof === null) {
            $userErrors[] = new UserError(self::INVALID_COMPLETION_PROOF_CODE, self::INVALID_COMPLETION_PROOF_MESSAGE, 'completionProof');
        }

        if ($assetId === null || $uploadGrant === '' || $completionProof === null) {
            return new CompleteUploadResult($success, $userErrors);
        }

        $asset = $this->assets->findById($assetId);

        if ($asset === null || (string) $asset->getAccountId() !== (string) $accountId) {
            $userErrors[] = new UserError(self::ASSET_NOT_FOUND_CODE, self::ASSET_NOT_FOUND_MESSAGE, 'assetId');
        } elseif (! hash_equals($this->uploadGrantIssuer->issueForAsset($asset), $uploadGrant)) {
            $userErrors[] = new UserError(self::INVALID_UPLOAD_GRANT_CODE, self::INVALID_UPLOAD_GRANT_MESSAGE, 'uploadGrant');
        } else {
            try {
                $asset->markUploaded($completionProof);
                $this->assets->save($asset);
                $success = new CompleteUploadSuccess($this->mapAsset($asset));
            } catch (AssetDomainException $exception) {
                $userErrors[] = $this->mapDomainException($exception);
            } catch (\Throwable $exception) {
                throw RepositoryUnavailableException::forReason('Repository failure', $exception);
            }
        }

        return new CompleteUploadResult($success, $userErrors);
    }

    private function assetId(string $value): ?AssetId
    {
        try {
            return new AssetId(trim($value));
        } catch (\InvalidArgumentException $exception) {
            return null;
        }
    }

    private function completionProof(string $value): ?UploadCompletionProofValue
    {
        try {
            return new UploadCompletionProofValue($value);
        } catch (\InvalidArgumentException $exception) {
            return null;
        }
    }

    private function mapDomainException(AssetDomainException $exception): UserError
    {
        return match ($exception->getMessage()) {
            'Asset already uploaded' => new UserError(self::ASSET_ALREADY_UPLOADED_CODE, $exception->getMessage(), 'assetId'),
            'Cannot upload asset from current state' => new UserError(self::INVALID_ASSET_STATE_CODE, $exception->getMessage(), 'assetId'),
            default => new UserError(self::COMPLETE_UPLOAD_FAILED_CODE, $exception->getMessage()),
        };
    }

    /**
     * @return array{id: string, status: \App\Domain\Asset\AssetStatus}
     */
    private function mapAsset(Asset $asset): array
    {
        return [
            'id' => (string) $asset->getId(),
            'status' => $asset->getStatus(),
        ];
    }
}
