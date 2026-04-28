# Implementation Log: Start multi-file upload (US-04)

**Feature:** Start multi-file upload

## Summary

Implemented a batch-aware `startUpload` flow that initiates multi-file uploads. The server creates pending `Asset` records per file, requests storage upload targets (one target per chunk), and returns per-file upload grants and targets. Whole-request validation is returned as top-level `userErrors`; per-file problems are reported per-file.

## Implementation Details

- `StartUploadService::startUploadBatch` validates the batch, creates pending assets, asks the `StorageAdapter` for upload targets, and issues per-file upload grants.
- GraphQL schema extended with `StartUploadBatchInput`/`StartUploadBatchPayload` and a `StartUploadBatchResolver` maps the application result into the GraphQL response.
- `MockStorageAdapter` provides deterministic `mock://` upload targets for local development so no external storage is required.

## Files Changed

- [src/Application/Asset/StartUploadService.php](src/Application/Asset/StartUploadService.php) — batch start implementation
- [src/Application/Asset/Command/StartUploadBatchCommand.php](src/Application/Asset/Command/StartUploadBatchCommand.php) — command for batch start
- [src/Application/Asset/Result/StartUploadBatchResult.php](src/Application/Asset/Result/StartUploadBatchResult.php) — batch result shape
- [src/GraphQL/Resolver/StartUploadBatchResolver.php](src/GraphQL/Resolver/StartUploadBatchResolver.php) — GraphQL mapping
- [src/GraphQL/Schema/schema.graphql](src/GraphQL/Schema/schema.graphql) — schema additions for batch upload
- [src/Infrastructure/Storage/MockStorageAdapter.php](src/Infrastructure/Storage/MockStorageAdapter.php) — local target generation
- [tests/Unit/Application/Asset/StartUploadServiceTest.php](tests/Unit/Application/Asset/StartUploadServiceTest.php) — unit tests
- [tests/Unit/Http/GraphQLHandlerTest.php](tests/Unit/Http/GraphQLHandlerTest.php) — GraphQL mapping tests

## Validation

- `vendor/bin/phpunit --configuration phpunit.xml tests/Unit/Application/Asset/StartUploadServiceTest.php` — passed locally (unit tests).
- `vendor/bin/phpunit --configuration phpunit.xml tests/Unit/Http/GraphQLHandlerTest.php` — passed locally.
- Integration tests that require MySQL were skipped locally due to the test database not being available.

## Follow-up

- Run the full CI pipeline and `mkdocs build` in an environment with MkDocs installed to confirm navigation and link rendering.
- Optionally add an integration smoke test for storage target generation.
