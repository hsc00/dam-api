<?php

declare(strict_types=1);

namespace App\Infrastructure\Processing;

use App\Application\Asset\AssetProcessingMetricCounter;
use App\Application\Asset\AssetProcessingMetricsInterface;
use App\Application\Asset\Command\HandleAssetProcessingJobCommand;
use App\Application\Asset\Command\HandleAssetProcessingRetryExhaustionCommand;
use App\Application\Asset\Exception\RetryableAssetProcessingException;
use App\Application\Asset\HandleAssetProcessingJobService;
use App\Application\Asset\HandleAssetProcessingRetryExhaustionService;
use App\Application\Asset\Result\AssetProcessingRetryExhaustionOutcome;
use App\Application\Asset\Result\HandleAssetProcessingRetryExhaustionResult;
use App\Domain\Asset\AssetStatus;
use Psr\Log\LoggerInterface;

final class AssetProcessingJobWorker implements \App\Infrastructure\Processing\AssetProcessingJobHandlerInterface
{
    private const DEAD_LETTERED_MESSAGE = 'Moved asset processing job to failed queue after retry budget exhausted.';
    private const FAILED_ASSET_MESSAGE = 'Asset processing failed and the asset was marked as FAILED.';
    private const INVALID_ASSET_ID_MESSAGE = 'Discarded asset processing job with invalid asset id.';
    private const MALFORMED_PAYLOAD_MESSAGE = 'Discarded malformed asset processing job payload.';
    private const MAX_AUTOMATIC_RETRY_COUNT = 2;
    private const RETRYABLE_PROCESSING_FAILURE_MESSAGE = 'Released asset processing job after retryable processing failure.';
    private const SKIPPED_ASSET_MESSAGE = 'Skipped asset processing job because asset is not in PROCESSING state.';
    private const TERMINAL_STATUS_CACHE_FAILED_MESSAGE = 'Persisted asset terminal state but failed to cache terminal status.';
    private const UNKNOWN_ASSET_MESSAGE = 'Discarded asset processing job for unknown asset.';

    private readonly AssetProcessingMetricsInterface $metrics;

    public function __construct(
        private readonly HandleAssetProcessingJobService $service,
        private readonly HandleAssetProcessingRetryExhaustionService $retryExhaustionService,
        private readonly LoggerInterface $logger,
        ?AssetProcessingMetricsInterface $metrics = null,
    ) {
        $this->metrics = $metrics ?? new NullAssetProcessingMetrics();
    }

    public function consume(string $payload): AssetProcessingJobHandlingResult
    {
        $decodedPayload = AssetProcessingJobPayload::fromJson($payload);

        if ($decodedPayload === null) {
            $result = AssetProcessingJobHandlingResult::discardedMalformedPayload();

            return $this->reportOutcome($result, $payload);
        }

        $assetId = $decodedPayload->toAssetId();

        if ($assetId === null) {
            $result = AssetProcessingJobHandlingResult::discardedInvalidAssetId();

            return $this->reportOutcome($result, $payload);
        }

        $result = $this->handleCommand(new HandleAssetProcessingJobCommand($assetId), $decodedPayload);

        return $this->reportOutcome($result, $payload);
    }

    private function handleCommand(
        HandleAssetProcessingJobCommand $command,
        AssetProcessingJobPayload $payload,
    ): AssetProcessingJobHandlingResult {
        try {
            return AssetProcessingJobHandlingResult::fromApplicationResult($this->service->handle($command));
        } catch (RetryableAssetProcessingException $exception) {
            return $this->handleRetryableFailure($command, $payload, $exception);
        }
    }

    private function handleRetryableFailure(
        HandleAssetProcessingJobCommand $command,
        AssetProcessingJobPayload $payload,
        RetryableAssetProcessingException $exception,
    ): AssetProcessingJobHandlingResult {
        $nextPayload = $payload->incrementRetryCount();

        if ($nextPayload->retryCount() <= self::MAX_AUTOMATIC_RETRY_COUNT) {
            return AssetProcessingJobHandlingResult::retryableProcessingFailure(
                (string) $command->assetId,
                $exception->getMessage(),
                $nextPayload->toJson(),
            );
        }

        $retryExhaustionResult = $this->retryExhaustionService->handle(
            new HandleAssetProcessingRetryExhaustionCommand($command->assetId),
        );

        return $this->handlingResultFromRetryExhaustion(
            $command,
            $nextPayload,
            $exception,
            $retryExhaustionResult,
        );
    }

    private function handlingResultFromRetryExhaustion(
        HandleAssetProcessingJobCommand $command,
        AssetProcessingJobPayload $nextPayload,
        RetryableAssetProcessingException $exception,
        HandleAssetProcessingRetryExhaustionResult $retryExhaustionResult,
    ): AssetProcessingJobHandlingResult {
        $assetId = $retryExhaustionResult->assetId ?? (string) $command->assetId;

        return match ($retryExhaustionResult->outcome) {
            AssetProcessingRetryExhaustionOutcome::DISCARDED_UNKNOWN_ASSET => AssetProcessingJobHandlingResult::discardedUnknownAsset($assetId),
            AssetProcessingRetryExhaustionOutcome::MARKED_ASSET_FAILED => AssetProcessingJobHandlingResult::deadLettered(
                $assetId,
                $exception->getMessage(),
                $nextPayload->toJson(),
                $retryExhaustionResult->assetStatus,
                $retryExhaustionResult->terminalStatusCached,
                $retryExhaustionResult->terminalStatusCacheError,
            ),
            AssetProcessingRetryExhaustionOutcome::SKIPPED_ASSET_NOT_PROCESSING => AssetProcessingJobHandlingResult::skippedAssetNotProcessing(
                $assetId,
                $retryExhaustionResult->assetStatus ?? AssetStatus::PENDING,
            ),
        };
    }

    private function reportOutcome(AssetProcessingJobHandlingResult $result, string $payload): AssetProcessingJobHandlingResult
    {
        foreach ($this->countersForOutcome($result->outcome) as $counter) {
            $this->metrics->incrementCounter($counter);
        }

        $this->logOutcome($result, $payload);

        return $result;
    }

    /**
     * @return list<AssetProcessingMetricCounter>
     */
    private function countersForOutcome(AssetProcessingJobHandlingOutcome $outcome): array
    {
        return match ($outcome) {
            AssetProcessingJobHandlingOutcome::DISCARDED_INVALID_ASSET_ID,
            AssetProcessingJobHandlingOutcome::DISCARDED_MALFORMED_PAYLOAD,
            AssetProcessingJobHandlingOutcome::DISCARDED_UNKNOWN_ASSET,
            AssetProcessingJobHandlingOutcome::SKIPPED_ASSET_NOT_PROCESSING => [AssetProcessingMetricCounter::DISCARDED_TOTAL],
            AssetProcessingJobHandlingOutcome::PROCESSED_ASSET_UPLOADED => [
                AssetProcessingMetricCounter::PROCESSED_TOTAL,
                AssetProcessingMetricCounter::PROCESSED_SUCCESS_TOTAL,
            ],
            AssetProcessingJobHandlingOutcome::DEAD_LETTERED,
            AssetProcessingJobHandlingOutcome::PROCESSED_ASSET_FAILED => [
                AssetProcessingMetricCounter::PROCESSED_TOTAL,
                AssetProcessingMetricCounter::PROCESSED_FAILURE_TOTAL,
            ],
            AssetProcessingJobHandlingOutcome::RETRYABLE_PROCESSING_FAILURE => [AssetProcessingMetricCounter::RETRY_TOTAL],
        };
    }

    private function logOutcome(AssetProcessingJobHandlingResult $result, string $payload): void
    {
        switch ($result->outcome) {
            case AssetProcessingJobHandlingOutcome::DEAD_LETTERED:
                $this->logger->warning(self::DEAD_LETTERED_MESSAGE, array_merge([
                    'assetId' => (string) $result->assetId,
                    'reason' => (string) $result->processingErrorMessage,
                ], $this->deadLetterContext($result), $this->terminalStatusCacheContext($result)));

                break;

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
     * @return array<string, bool|string>
     */
    private function deadLetterContext(AssetProcessingJobHandlingResult $result): array
    {
        $context = [];

        if ($result->assetStatus !== null) {
            $context['status'] = $result->assetStatus->value;
        }

        if ($result->terminalStatusCached !== null) {
            $context['terminalStatusCached'] = $result->terminalStatusCached;
        }

        return $context;
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
