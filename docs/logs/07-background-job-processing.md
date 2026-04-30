# Implementation Log: US-07 Background Job Processing

**Feature:** US-07 Async Worker Pipeline — Single-Attempt Background Processing

## Summary

US-07 delivers the background job consumer that takes an asset from `PROCESSING` to either `UPLOADED` (success) or `FAILED` (terminal failure). The implementation adds a real Redis queue consumer, a worker container, and all the application/domain/infrastructure pieces needed for reliable single-attempt processing. Retries and dead-letter handling remain deferred to US-08.

## Architecture Decisions

- **Queue contract:** Non-destructive dequeue via an atomic Lua `RPOP` + `LPUSH` into a `:reserved` list. Jobs are only removed from the reserved list after an explicit `acknowledge()` (HANDLED or DISCARD) or returned to the main queue via `release()` (RETRY or infrastructure failure). A visibility-timeout recovery pass runs before each `reserveNext()` call to handle crashed workers.
- **Delivery semantics in Infrastructure:** The application service (`HandleAssetProcessingJobService`) returns domain vocabulary only (`AssetProcessingJobOutcome`). The translation to queue delivery actions (`HANDLED`/`DISCARD`/`RETRY`) lives exclusively in `AssetProcessingJobHandlingResult` inside `Infrastructure\Processing\`.
- **Worker loop resilience:** `AssetProcessingWorkerLoop` applies exponential backoff (250 ms base, 5 s ceiling) and hard fail-fast after 5 consecutive infrastructure failures (logs `critical` then re-throws). The failure counter resets on any successful poll or processed job.
- **Terminal status cache:** Written best-effort after MySQL persistence succeeds. Uses `setEx` with TTL = 300 + jitter (0–30 s) per project thundering-herd convention.
- **Sanitized logging:** Rejected payloads (malformed or invalid asset ID) log only `payloadLength` and `payloadSha256` — never the raw payload.

## Files Added

- `src/Application/Asset/Command/HandleAssetProcessingJobCommand.php` — command DTO carrying the parsed `AssetId`
- `src/Application/Asset/HandleAssetProcessingJobService.php` — application use case: validate state, process, persist, cache
- `src/Application/Asset/AssetProcessorInterface.php` — application port for the actual processing operation
- `src/Application/Asset/AssetTerminalStatusCacheInterface.php` — application port for terminal status caching
- `src/Application/Asset/Result/AssetProcessingJobOutcome.php` — domain-vocabulary outcome enum
- `src/Application/Asset/Result/HandleAssetProcessingJobResult.php` — application result DTO (no delivery semantics)
- `src/Application/Asset/Result/TerminalStatusCacheStoreResult.php` — cache-write result DTO
- `src/Application/Asset/Exception/RetryableAssetProcessingException.php` — retryable processor failure
- `src/Application/Asset/Exception/TerminalAssetProcessingException.php` — terminal processor failure
- `src/Infrastructure/Processing/AssetProcessingJobHandlerInterface.php` — infrastructure handler contract
- `src/Infrastructure/Processing/AssetProcessingJobConsumerInterface.php` — infrastructure consumer contract
- `src/Infrastructure/Processing/AssetProcessingJobHandlingOutcome.php` — infrastructure-level outcome enum (mirrors application enum with RETRYABLE_PROCESSING_FAILURE added)
- `src/Infrastructure/Processing/AssetProcessingJobHandlingResult.php` — infrastructure result DTO including `AssetProcessingJobDelivery`
- `src/Infrastructure/Processing/AssetProcessingJobDelivery.php` — delivery enum (`HANDLED`/`DISCARD`/`RETRY`)
- `src/Infrastructure/Processing/ReservedAssetProcessingJob.php` — reserved job VO with `acknowledge()`/`discard()`/`release()` closures
- `src/Infrastructure/Processing/AssetProcessingJobWorker.php` — thin adapter: parse payload → call service → translate result; sanitized logging
- `src/Infrastructure/Processing/AssetProcessingWorkerLoop.php` — polling loop with backoff/fail-fast and ack/release routing
- `src/Infrastructure/Processing/RedisJobQueueConsumer.php` — reservation-based Redis consumer using atomic Lua scripts
- `src/Infrastructure/Processing/RedisAssetTerminalStatusCache.php` — Redis terminal status cache with TTL jitter
- `src/Infrastructure/Processing/PassThroughAssetProcessor.php` — minimal concrete processor (validates state and proof; no file transforms)
- `src/Infrastructure/Processing/Exception/RedisJobQueueConsumerException.php` — typed consumer failure
- `src/Infrastructure/Processing/Exception/RedisAssetTerminalStatusCacheException.php` — typed cache failure
- `bin/asset-processing-worker.php` — CLI entrypoint: bootstraps services, starts loop

## Files Modified

- `src/Application/Asset/CompleteUploadService.php` — dispatch-failure compensation: if job dispatch fails after saving `PROCESSING`, asset is restored to `PENDING` and re-saved
- `src/Domain/Asset/Asset.php` — added `restorePending()` (for dispatch compensation); `markFailed()` clears completion proof
- `src/Infrastructure/Persistence/MySQLAssetRepository.php` — extended allowed status transitions to include `PROCESSING → PENDING|UPLOADED|FAILED`
- `src/Infrastructure/Processing/RedisJobQueuePublisher.php` — lazy Redis connection via closure; replaces `MockAssetProcessingJobDispatcher` in the HTTP path
- `public/index.php` — wires `RedisJobQueuePublisher` instead of the mock dispatcher
- `docker-compose.yaml` — adds `worker` service (`bin/asset-processing-worker.php`) depending on `db` and `redis`

## Tests Added/Modified

- `tests/Unit/Application/Asset/HandleAssetProcessingJobServiceTest.php` — malformed payload, invalid ID, unknown asset, non-PROCESSING skip, success, terminal failure, stale-write recovery, cache failure paths
- `tests/Unit/Infrastructure/Processing/AssetProcessingJobWorkerTest.php` — payload parsing, logging levels, delivery values for all outcomes
- `tests/Unit/Infrastructure/Processing/AssetProcessingWorkerLoopTest.php` — ack on HANDLED, discard on DISCARD, release on RETRY, release on handler exception, backoff, fail-fast
- `tests/Unit/Infrastructure/Processing/RedisAssetTerminalStatusCacheTest.php` — store success, store failure
- `tests/Unit/Infrastructure/Processing/RedisJobQueueConsumerTest.php` — reserve, acknowledge, release, expired-job recovery
- `tests/Unit/Infrastructure/Processing/PassThroughAssetProcessorTest.php` — validates state guard and proof guard
- `tests/Unit/Application/Asset/CompleteUploadServiceTest.php` — dispatch-failure compensation sequence
- `tests/Unit/Domain/Asset/AssetTest.php` — `PROCESSING → UPLOADED`, `PROCESSING → PENDING` (restore), `markFailed` clears proof
- `tests/Integration/Infrastructure/Persistence/MySQLAssetRepositoryTest.php` — `PROCESSING → UPLOADED`, `PROCESSING → PENDING`, `PROCESSING → FAILED`
