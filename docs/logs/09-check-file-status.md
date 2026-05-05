# Implementation Log: US-09 Check File Status

**Feature:** US-09 Check File Status

## Summary

US-09 added an asset-status query that reports both the current status and whether that status was served from the fast cache or the durable store. The accepted implementation kept the shared mutation asset payloads unchanged and introduced a query-specific `AssetStatusSnapshot` contract with `id`, `status`, and `readSource`. Missing or unauthorized assets resolve to `null` without GraphQL transport errors.

## Implementation Details

- Generalized the cache port and Redis adapter from terminal-only naming to asset-status cache naming so the same abstraction can serve both write-side seeding and read-side status lookups.
- Added `GetAssetService` and `GetAssetQuery` so the application layer owns the file-status read path, validates durable ownership first, and returns `null` before any cache lookup when the asset is missing or belongs to another account.
- Compared the cached status with the durable asset status and returned `FAST_CACHE` only when both values matched; cache misses, cache lookup failures, or mismatches fall back to the durable store and return `DURABLE_STORE`.
- Seeded or repaired the cache as a best-effort side effect on durable fallback so later reads can return from cache without changing the authoritative result of the current query.
- Kept GraphQL contract truthfulness by adding `AssetReadSource` and `AssetStatusSnapshot` for the `asset(id: ID!)` query instead of adding `readSource` to the shared mutation `Asset` payload used by `startUpload`, `startUploadBatch`, and `completeUpload`.
- Mapped invalid asset ids, missing assets, and unauthorized assets to `null` in the resolver and handler tests so the query stays silent at the GraphQL transport layer for those cases.

## Files Changed

- `src/Application/Asset/AssetStatusCacheInterface.php` — introduced the generalized asset-status cache port used by both reads and writes.
- `src/Application/Asset/StartUploadService.php` — seeded the asset-status cache when new `PENDING` assets are created.
- `src/Application/Asset/CompleteUploadService.php` — seeded the asset-status cache when uploads transition to `PROCESSING`.
- `src/Application/Asset/Command/GetAssetQuery.php` — added the application query DTO for asset-status reads.
- `src/Application/Asset/GetAssetService.php` — implemented durable ownership checks, cache-hit provenance, durable fallback, and cache repair/seeding.
- `src/Application/Asset/Result/AssetReadSource.php` — defined the `FAST_CACHE` and `DURABLE_STORE` provenance enum.
- `src/Application/Asset/Result/GetAssetResult.php` — carried the query-specific asset status snapshot returned from the application layer.
- `src/GraphQL/Schema/schema.graphql` — added `AssetReadSource`, `AssetStatusSnapshot`, and the query-specific `asset` return type.
- `src/GraphQL/Resolver/GetAssetResolver.php` — mapped the GraphQL query into `GetAssetService` and returned `null` for invalid, missing, or unauthorized assets.
- `src/GraphQL/SchemaFactory.php` — wired the query resolver into the schema.
- `src/Infrastructure/Processing/RedisAssetStatusCache.php` — exposed the generalized Redis-backed asset-status cache implementation used by the query and the write paths.
- `public/index.php` — wired the cache implementation, `GetAssetService`, and `GetAssetResolver` into the runtime entrypoint.
- `tests/Unit/Application/Asset/GetAssetServiceTest.php` — covered ownership checks, provenance outcomes, durable fallback, and cache repair/seeding behavior.
- `tests/Unit/Http/GraphQLHandlerTest.php` — covered the query contract, `null` responses without GraphQL errors, and cache-hit versus durable-fallback behavior.
- `tests/Unit/Infrastructure/Processing/RedisAssetStatusCacheTest.php` — covered the generalized Redis cache lookup and store behavior.

## Validation

- `composer fix:check` — passed.
- `composer analyse` — passed.
- `composer test` — passed.
- `composer check` — passed.
- `vendor/bin/phpunit --configuration phpunit.xml --no-coverage tests/Unit/Application/Asset/GetAssetServiceTest.php` — passed after the final contract-truthfulness fix.
- `composer test:integration` — completed with skipped tests.

## Delivery Chunks

- Cache abstraction generalization — renamed the terminal-only cache surface to an asset-status cache and reused it from upload writes and status reads.
- Asset query delivery — added the application query/service path, GraphQL resolver wiring, and the query-specific status snapshot contract with provenance.
- Review-driven contract correction and validation — kept `readSource` off the shared mutation asset payloads, preserved null-on-missing behavior without GraphQL errors, and reran focused plus broad validation.
