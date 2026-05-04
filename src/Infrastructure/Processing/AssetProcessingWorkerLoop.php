<?php

declare(strict_types=1);

namespace App\Infrastructure\Processing;

use Psr\Log\LoggerInterface;

final class AssetProcessingWorkerLoop
{
    private const DEFAULT_BASE_BACKOFF_MICROSECONDS = 250_000;
    private const DEFAULT_MAX_BACKOFF_MICROSECONDS = 5_000_000;
    private const DEFAULT_MAX_CONSECUTIVE_INFRASTRUCTURE_FAILURES = 5;
    private const ITERATION_FAILED_MESSAGE = 'Asset processing worker iteration failed.';
    private const STOPPED_AFTER_REPEATED_FAILURES_MESSAGE = 'Asset processing worker stopped after repeated infrastructure failures.';

    private readonly \Closure $sleep;
    private int $consecutiveInfrastructureFailures = 0;

    public function __construct(
        private readonly AssetProcessingJobConsumerInterface $consumer,
        private readonly AssetProcessingJobHandlerInterface $handler,
        private readonly LoggerInterface $logger,
        ?\Closure $sleep = null,
        private readonly int $maxConsecutiveInfrastructureFailures = self::DEFAULT_MAX_CONSECUTIVE_INFRASTRUCTURE_FAILURES,
        private readonly int $baseBackoffMicroseconds = self::DEFAULT_BASE_BACKOFF_MICROSECONDS,
        private readonly int $maxBackoffMicroseconds = self::DEFAULT_MAX_BACKOFF_MICROSECONDS,
    ) {
        $this->sleep = $sleep ?? static function (int $microseconds): void {
            usleep($microseconds);
        };
    }

    public function runOnce(): void
    {
        $reservation = $this->reserveNext();

        if ($reservation !== null) {
            $this->processReservation($reservation);
        }
    }

    private function reserveNext(): ?ReservedAssetProcessingJob
    {
        try {
            $reservation = $this->consumer->reserveNext();
        } catch (\Throwable $exception) {
            $this->handleInfrastructureFailure($exception);

            return null;
        }

        if ($reservation === null) {
            $this->consecutiveInfrastructureFailures = 0;

            return null;
        }

        return $reservation;
    }

    private function processReservation(ReservedAssetProcessingJob $reservation): void
    {
        try {
            $result = $this->handler->consume($reservation->payload());
        } catch (\Throwable $exception) {
            $this->releaseReservationAfterHandlerFailure($reservation, $exception);

            return;
        }

        try {
            $this->finalizeReservation($reservation, $result);
        } catch (\Throwable $exception) {
            $this->handleInfrastructureFailure($exception);

            return;
        }

        $this->consecutiveInfrastructureFailures = 0;
    }

    private function finalizeReservation(ReservedAssetProcessingJob $reservation, AssetProcessingJobHandlingResult $result): void
    {
        match ($result->delivery) {
            AssetProcessingJobDelivery::DEAD_LETTER => $reservation->deadLetter($this->requiredQueuePayload($result)),
            AssetProcessingJobDelivery::HANDLED => $reservation->acknowledge(),
            AssetProcessingJobDelivery::DISCARD => $reservation->discard(),
            AssetProcessingJobDelivery::RETRY => $reservation->release($this->requiredQueuePayload($result)),
        };
    }

    private function requiredQueuePayload(AssetProcessingJobHandlingResult $result): string
    {
        if ($result->queuePayload === null) {
            throw new \LogicException('Queue payload is required for retry or dead-letter deliveries.');
        }

        return $result->queuePayload;
    }

    private function releaseReservationAfterHandlerFailure(ReservedAssetProcessingJob $reservation, \Throwable $exception): void
    {
        $releaseFailure = null;

        try {
            $reservation->release();
        } catch (\Throwable $candidate) {
            $releaseFailure = $candidate;
        }

        $this->handleInfrastructureFailure($exception, $releaseFailure);
    }

    private function handleInfrastructureFailure(\Throwable $exception, ?\Throwable $releaseFailure = null): void
    {
        $this->consecutiveInfrastructureFailures++;

        $context = [
            'consecutiveFailures' => $this->consecutiveInfrastructureFailures,
            'exception' => $exception,
        ];

        if ($releaseFailure !== null) {
            $context['releaseException'] = $releaseFailure;
        }

        if ($this->consecutiveInfrastructureFailures >= $this->maxConsecutiveInfrastructureFailures) {
            $this->logger->critical(self::STOPPED_AFTER_REPEATED_FAILURES_MESSAGE, $context);

            throw $exception;
        }

        $backoffMicroseconds = $this->backoffMicroseconds($this->consecutiveInfrastructureFailures);
        $this->logger->error(self::ITERATION_FAILED_MESSAGE, array_merge($context, [
            'backoffMicroseconds' => $backoffMicroseconds,
        ]));
        ($this->sleep)($backoffMicroseconds);
    }

    private function backoffMicroseconds(int $consecutiveFailures): int
    {
        return min(
            $this->maxBackoffMicroseconds,
            $this->baseBackoffMicroseconds * (2 ** max(0, $consecutiveFailures - 1)),
        );
    }
}
