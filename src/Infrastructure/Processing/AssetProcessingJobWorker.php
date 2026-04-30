<?php

declare(strict_types=1);

namespace App\Infrastructure\Processing;

use App\Application\Asset\Command\HandleAssetProcessingJobCommand;
use App\Application\Asset\Exception\RetryableAssetProcessingException;
use App\Application\Asset\HandleAssetProcessingJobService;
use App\Domain\Asset\ValueObject\AssetId;
use Psr\Log\LoggerInterface;

final class AssetProcessingJobWorker implements \App\Infrastructure\Processing\AssetProcessingJobHandlerInterface
{
    private const ASSET_ID_FIELD = 'assetId';
    private const FAILED_ASSET_MESSAGE = 'Asset processing failed and the asset was marked as FAILED.';
    private const INVALID_ASSET_ID_MESSAGE = 'Discarded asset processing job with invalid asset id.';
    private const MALFORMED_PAYLOAD_MESSAGE = 'Discarded malformed asset processing job payload.';
    private const RETRYABLE_PROCESSING_FAILURE_MESSAGE = 'Released asset processing job after retryable processing failure.';
    private const SKIPPED_ASSET_MESSAGE = 'Skipped asset processing job because asset is not in PROCESSING state.';
    private const TERMINAL_STATUS_CACHE_FAILED_MESSAGE = 'Persisted asset terminal state but failed to cache terminal status.';
    private const UNKNOWN_ASSET_MESSAGE = 'Discarded asset processing job for unknown asset.';

    public function __construct(
        private readonly HandleAssetProcessingJobService $service,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function consume(string $payload): AssetProcessingJobHandlingResult
    {
        $command = $this->commandFromPayload($payload);
        $result = $command instanceof HandleAssetProcessingJobCommand
            ? $this->handleCommand($command)
            : $command;

        $this->logOutcome($result, $payload);

        return $result;
    }

    private function handleCommand(HandleAssetProcessingJobCommand $command): AssetProcessingJobHandlingResult
    {
        try {
            return AssetProcessingJobHandlingResult::fromApplicationResult($this->service->handle($command));
        } catch (RetryableAssetProcessingException $exception) {
            return AssetProcessingJobHandlingResult::retryableProcessingFailure(
                (string) $command->assetId,
                $exception->getMessage(),
            );
        }
    }

    /**
     * @return AssetProcessingJobHandlingResult|HandleAssetProcessingJobCommand
     */
    private function commandFromPayload(string $payload): AssetProcessingJobHandlingResult|HandleAssetProcessingJobCommand
    {
        $decodedPayload = $this->decodePayload($payload);

        if ($decodedPayload === null) {
            return AssetProcessingJobHandlingResult::discardedMalformedPayload();
        }

        $assetId = $this->assetId($decodedPayload);

        if ($assetId === null) {
            return AssetProcessingJobHandlingResult::discardedInvalidAssetId();
        }

        return new HandleAssetProcessingJobCommand($assetId);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decodePayload(string $payload): ?array
    {
        try {
            $decodedPayload = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            return null;
        }

        return is_array($decodedPayload) ? $decodedPayload : null;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function assetId(array $payload): ?AssetId
    {
        $assetId = $payload[self::ASSET_ID_FIELD] ?? null;

        if (! is_string($assetId)) {
            return null;
        }

        try {
            return new AssetId(trim($assetId));
        } catch (\InvalidArgumentException $exception) {
            return null;
        }
    }

    private function logOutcome(AssetProcessingJobHandlingResult $result, string $payload): void
    {
        switch ($result->outcome) {
            case AssetProcessingJobHandlingOutcome::DISCARDED_MALFORMED_PAYLOAD:
                $this->logger->warning(self::MALFORMED_PAYLOAD_MESSAGE, $this->rejectedPayloadContext($payload));

                break;

            case AssetProcessingJobHandlingOutcome::DISCARDED_INVALID_ASSET_ID:
                $this->logger->warning(self::INVALID_ASSET_ID_MESSAGE, $this->rejectedPayloadContext($payload));

                break;

            case AssetProcessingJobHandlingOutcome::DISCARDED_UNKNOWN_ASSET:
                $this->logger->error(self::UNKNOWN_ASSET_MESSAGE, ['assetId' => $result->assetId]);

                break;

            case AssetProcessingJobHandlingOutcome::SKIPPED_ASSET_NOT_PROCESSING:
                $this->logger->info(self::SKIPPED_ASSET_MESSAGE, [
                    'assetId' => $result->assetId,
                    'status' => $result->assetStatus?->value,
                ]);

                break;

            case AssetProcessingJobHandlingOutcome::PROCESSED_ASSET_FAILED:
                $this->logger->warning(
                    self::FAILED_ASSET_MESSAGE,
                    array_merge([
                        'assetId' => $result->assetId,
                        'status' => $result->assetStatus?->value,
                        'reason' => $result->processingErrorMessage,
                        'terminalStatusCached' => $result->terminalStatusCached,
                    ], $this->terminalStatusCacheContext($result)),
                );

                break;

            case AssetProcessingJobHandlingOutcome::PROCESSED_ASSET_UPLOADED:
                if ($result->terminalStatusCached === false) {
                    $this->logger->warning(
                        self::TERMINAL_STATUS_CACHE_FAILED_MESSAGE,
                        array_merge([
                            'assetId' => $result->assetId,
                            'status' => $result->assetStatus?->value,
                        ], $this->terminalStatusCacheContext($result)),
                    );
                }

                break;

            case AssetProcessingJobHandlingOutcome::RETRYABLE_PROCESSING_FAILURE:
                $this->logger->warning(self::RETRYABLE_PROCESSING_FAILURE_MESSAGE, [
                    'assetId' => $result->assetId,
                    'reason' => $result->processingErrorMessage,
                ]);

                break;

            default:
                break;
        }
    }

    /**
     * @return array{payloadLength: int, payloadSha256: string}
     */
    private function rejectedPayloadContext(string $payload): array
    {
        return [
            'payloadLength' => strlen($payload),
            'payloadSha256' => hash('sha256', $payload),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function terminalStatusCacheContext(AssetProcessingJobHandlingResult $result): array
    {
        if ($result->terminalStatusCacheError === null) {
            return [];
        }

        return ['terminalStatusCacheError' => $result->terminalStatusCacheError];
    }
}
