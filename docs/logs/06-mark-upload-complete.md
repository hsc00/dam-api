# Implementation Log: US-06 Mark Upload as Complete

**Feature:** US-06 Mark Upload as Complete

## Summary

US-06 completed the upload-finalization path. A valid `completeUpload` call now accepts the client completion proof, moves the asset from `PENDING` to `PROCESSING`, persists that state, and dispatches a background processing job. Missing assets and invalid lifecycle states stay in the mutation payload as user-facing business errors rather than surfacing as GraphQL transport failures.

## Implementation Details

- Extended the asset lifecycle with `PROCESSING` so upload completion can represent the accepted post-upload state without pretending that downstream work has already finished.
- Added `Asset::markProcessing(...)` plus processing-state reconstitution to keep the completion-proof invariant in the domain layer and preserve the proof after upload acceptance.
- Added `AssetProcessingJobDispatcherInterface` at the application boundary and invoked it from `CompleteUploadService` only after a successful state transition and repository save.
- Kept the GraphQL resolver thin by continuing to map only application results into the mutation payload while updating the SDL and handler expectations to expose the new `PROCESSING` status.
- Tightened persistence coverage so the assets table accepts `PROCESSING` rows, still requires completion proof for proof-bearing states, and maps processing rows back into domain objects.
- Extracted integration-test lifecycle row fixtures into a dedicated trait so the shared base test class stayed within the repository method-count rule.

## Files Changed

- `src/Application/Asset/AssetProcessingJobDispatcherInterface.php` — added the application port for scheduling background processing work.
- `src/Application/Asset/CompleteUploadService.php` — changed upload completion to move assets into processing, persist them, and dispatch processing jobs.
- `src/Domain/Asset/Asset.php` — added processing-state transition and reconstitution rules while preserving completion-proof invariants.
- `src/Domain/Asset/AssetAccessors.php` — extracted accessor-heavy read methods from the aggregate to stay within the class-size limit.
- `src/Domain/Asset/AssetStatus.php` — added the `PROCESSING` lifecycle enum value.
- `src/GraphQL/Schema/schema.graphql` — documented `PROCESSING` in the GraphQL status enum and clarified the complete-upload mutation description.
- `src/Infrastructure/Persistence/MySQLAssetRepository.php` — mapped persisted processing rows and reused proof validation for proof-bearing states.
- `src/Infrastructure/Processing/MockAssetProcessingJobDispatcher.php` — provided the local/dev processing dispatcher stub.
- `migrations/20260401120000_create_assets_table.sql` — allowed `PROCESSING` in the assets status constraint and required completion proof for processing rows.
- `public/index.php` — wired the complete-upload service with the mock processing dispatcher.
- `tests/Unit/Application/Asset/CompleteUploadServiceTest.php` — added service coverage for processing transition, job dispatch, and invalid-state rejections.
- `tests/Unit/Http/GraphQLHandlerTest.php` — verified the local GraphQL handler returns `PROCESSING` and dispatches exactly one job on success.
- `tests/Integration/Infrastructure/Persistence/AssetsTableLifecycleTest.php` — added processing-row acceptance and processing-proof constraint coverage.
- `tests/Integration/Infrastructure/Persistence/AssetLifecycleFixtureRows.php` — extracted shared lifecycle row fixtures from the oversized base test class.
- `tests/Integration/Infrastructure/Persistence/BaseAssetsTableTestCase.php` — consumed the extracted fixture trait.
- `tests/Integration/Infrastructure/Persistence/BaseMySQLAssetRepositoryTestCase.php` — added a processing-asset helper for repository tests.
- `tests/Integration/Infrastructure/Persistence/MySQLAssetRepositoryTest.php` — updated repository lifecycle tests to persist the new processing transition.
- `docs/api/01-agree-api-schema.md` — aligned the public API contract page with the shipped `completeUpload` behavior.

## Validation

- `vendor/bin/phpunit --configuration phpunit.xml tests/Unit/Domain/Asset/AssetTest.php tests/Unit/Application/Asset/CompleteUploadServiceTest.php tests/Unit/Http/GraphQLHandlerTest.php` — passed; PHPUnit reported only the existing missing code coverage driver warning.
- `vendor/bin/phpunit --configuration phpunit.xml tests/Integration/Infrastructure/Persistence/AssetsTableLifecycleTest.php tests/Integration/Infrastructure/Persistence/MySQLAssetRepositoryTest.php` — passed inside the containerized PHP runtime with MySQL available.
- `composer fix:check` — passed in the running app container after applying formatter-only normalization to the touched files.
- `composer analyse` — passed.
- `composer test` — passed.
- `composer test:integration` — passed.
- `composer check` — passed.
- Editor diagnostics for the touched PHP files were clean after extracting the integration fixture trait and fixing trailing-newline issues.

## Delivery Chunks

- Lifecycle contract revision — introduced `PROCESSING` and updated the domain, schema, and persistence layers to treat it as the accepted post-upload state.
- Background work dispatch — added the application port and local dispatcher stub so successful completions schedule processing work.
- Focused verification and docs — tightened unit and integration coverage for accepted and rejected completion paths, then aligned the published API docs and implementation log.
