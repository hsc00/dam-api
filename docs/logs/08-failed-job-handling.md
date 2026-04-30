# Implementation Log: US-08 Failed-job Handling

**Feature:** US-08 Failed-job handling

## Summary

US-08 extends the background processing pipeline with bounded automatic retries and failed-queue routing. Queue payloads now carry `retryCount`, retryable failures below the limit are requeued with an incremented payload, and failures after the limit move the updated payload to the failed Redis queue while marking the asset `FAILED` through a dedicated retry-exhaustion path. Exhausted stale or duplicate jobs for unknown assets or assets no longer in `PROCESSING` are discarded instead of being dead-lettered.

## Implementation Details

- Added `AssetProcessingJobPayload` as the canonical queue payload model for `assetId` and `retryCount`, including JSON parsing, serialization, initial-payload creation, and retry-count incrementing.
- Introduced `HandleAssetProcessingRetryExhaustionCommand`, `HandleAssetProcessingRetryExhaustionService`, and retry-exhaustion result types so exhausted retries can mark assets `FAILED` through an application service instead of embedding that policy in the worker.
- Extracted `TerminalAssetPersistenceService` so both the normal processing path and the retry-exhaustion path share the same terminal-state save, stale-write handling, and best-effort terminal-status cache write behavior.
- Updated `AssetProcessingJobWorker` and `AssetProcessingJobHandlingResult` to map retryable failures into either a rewritten retry payload, a dead-lettered failed-job payload, or a discard when the exhausted job is stale or no longer actionable.
- Extended `ReservedAssetProcessingJob`, `AssetProcessingWorkerLoop`, and `RedisJobQueueConsumer` so release and dead-letter callbacks can write replacement payloads and route exhausted jobs into the failed Redis queue without losing reserved-queue semantics.
- Updated `RedisJobQueuePublisher` and the worker bootstrap so newly dispatched jobs start with `retryCount` 0, and tightened logging around retry, discard, dead-letter, and terminal-cache outcomes.
- Raised the PHPStan memory limit in `composer.json` so the analyse/check gates continue to pass with the added retry-handling coverage and queue wiring.

## Files Changed

- `bin/asset-processing-worker.php` — wired the retry-exhaustion service into the worker bootstrap.
- `composer.json` — raised the PHPStan memory limit used by `composer analyse`.
- `src/Application/Asset/HandleAssetProcessingJobService.php` — reused the shared terminal-persistence path for the main processing flow.
- `src/Application/Asset/TerminalAssetPersistenceService.php` — centralized terminal asset save, stale-write resolution, and best-effort cache writes.
- `src/Application/Asset/Command/HandleAssetProcessingRetryExhaustionCommand.php` — added the application command for exhausted retry handling.
- `src/Application/Asset/HandleAssetProcessingRetryExhaustionService.php` — added the retry-exhaustion flow that marks processing assets `FAILED` and discards stale or duplicate exhausted jobs.
- `src/Application/Asset/Result/AssetProcessingRetryExhaustionOutcome.php` — defined the retry-exhaustion outcomes exposed back to infrastructure.
- `src/Application/Asset/Result/HandleAssetProcessingRetryExhaustionResult.php` — carried retry-exhaustion result details, including terminal cache state.
- `src/Infrastructure/Processing/AssetProcessingJobPayload.php` — modeled payload parsing, serialization, and retry-count incrementing.
- `src/Infrastructure/Processing/AssetProcessingJobHandlingResult.php` — mapped application outcomes into queue delivery actions and updated payloads.
- `src/Infrastructure/Processing/AssetProcessingJobWorker.php` — implemented retry-budget handling, dead-letter mapping, discard rules, and structured outcome logging.
- `src/Infrastructure/Processing/AssetProcessingWorkerLoop.php` — finalized reserved jobs as handled, discard, retry, or dead-letter actions.
- `src/Infrastructure/Processing/RedisJobQueueConsumer.php` — added failed-queue routing and replacement-payload release/dead-letter callbacks while preserving reservation recovery.
- `src/Infrastructure/Processing/RedisJobQueuePublisher.php` — dispatched the initial queue payload with `retryCount` set to 0.
- `src/Infrastructure/Processing/ReservedAssetProcessingJob.php` — allowed retry and dead-letter paths to supply updated payloads for an existing reservation.
- `src/Infrastructure/Processing/Exception/RedisJobQueueConsumerException.php` — added explicit failure cases for release and dead-letter queue operations.
- `tests/Unit/Application/Asset/HandleAssetProcessingRetryExhaustionServiceTest.php` — covered exhausted retry handling for failed, discarded, and skipped assets.
- `tests/Unit/Infrastructure/Processing/AssetProcessingJobPayloadTest.php` — covered payload parsing, serialization, and retry-count mutation.
- `tests/Unit/Infrastructure/Processing/AssetProcessingJobWorkerTest.php` — covered retry, dead-letter, discard, and logging outcomes.
- `tests/Unit/Infrastructure/Processing/AssetProcessingWorkerLoopTest.php` — covered worker-loop delivery finalization for handled, discard, retry, and dead-letter paths.
- `tests/Unit/Infrastructure/Processing/RedisJobQueueConsumerTest.php` — covered failed-queue routing, release behavior, and reserved-job recovery.
- `tests/Unit/Infrastructure/Processing/RedisJobQueuePublisherTest.php` — covered initial payload dispatch with `retryCount` 0.

## Validation

- `vendor/bin/phpunit --no-coverage tests/Unit/Infrastructure/Processing/RedisJobQueueConsumerTest.php` — passed.
- `vendor/bin/phpunit --no-coverage tests/Unit/Infrastructure/Processing/AssetProcessingJobWorkerTest.php` — passed.
- `composer test` — passed (185 tests, 807 assertions).
- `composer fix:check` — passed.
- `composer analyse` — passed after raising the PHPStan memory limit in `composer.json`.
- `composer check` — passed.

## Delivery Chunks

- Payload/retry model and retry-exhaustion application service — introduced the retry-aware payload contract, the dedicated retry-exhaustion command/service/result types, and the shared terminal persistence service used by both terminal paths.
- Worker/queue dead-letter wiring and runtime callback fix — updated worker outcome mapping, reserved-job callbacks, loop finalization, and Redis consumer routing so retries and dead-letters can write updated payloads and reach the correct queue.
- Review-driven edge-case handling, validation, and tooling gate cleanup — discarded exhausted stale or duplicate jobs instead of dead-lettering them, added focused unit coverage, and raised the PHPStan memory limit so the quality gates stay green.
