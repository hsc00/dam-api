# Implementation Log: US-05 Upload Request Validation

**Feature:** US-05 Upload Request Validation

## Summary

US-05 added user-facing validation for invalid batch upload initiation requests without surfacing GraphQL transport errors for business-rule failures. The delivered slice rejects empty batches and batches larger than 20 files with top-level payload errors, and it rejects per-file chunk counts outside the allowed 1..100 range while still allowing valid files in the same batch to proceed. The implementation keeps the no-persistence guarantee for invalid batch requests by short-circuiting before any file processing or repository writes.

## Implementation Details

- Extended the batch result contract to carry top-level `userErrors` alongside per-file outcomes so whole-request validation can be returned as business errors inside `StartUploadBatchPayload`.
- Added batch-size validation to `StartUploadService` and short-circuited `startUploadBatch()` before duplicate detection, asset creation, storage target generation, or upload-grant issuance when the request has zero files or more than 20 files.
- Tightened the Asset aggregate chunk-count invariant from a lower-bound-only check to the full accepted range of 1 through 100 so the rule is enforced at the domain boundary for both new assets and reconstituted assets.
- Kept the GraphQL resolver thin by only mapping the new batch-level `userErrors` field from the application result into the GraphQL payload.
- Added focused unit coverage at the domain, application, and GraphQL handler layers to prove accepted and rejected boundaries and the no-persistence behavior for invalid requests.

## Files Changed

- `src/Application/Asset/Result/StartUploadBatchResult.php` — added top-level batch `userErrors` to the application result and validated the new collection shape.
- `src/Application/Asset/StartUploadService.php` — added empty-batch and batch-too-large validation plus the short-circuit that prevents persistence on invalid whole-batch requests.
- `src/Domain/Asset/Asset.php` — enforced the chunk-count range `1..100` at the aggregate boundary.
- `src/GraphQL/Resolver/StartUploadBatchResolver.php` — mapped batch-level `userErrors` into the GraphQL response.
- `src/GraphQL/Schema/schema.graphql` — extended `StartUploadBatchPayload` with top-level `userErrors`.
- `tests/Unit/Application/Asset/StartUploadServiceTest.php` — added service coverage for invalid batch sizes and per-file chunk-count boundaries.
- `tests/Unit/Domain/Asset/AssetTest.php` — extended domain coverage to reject chunk counts above 100.
- `tests/Unit/Http/GraphQLHandlerTest.php` — verified the new top-level batch errors and per-file chunk-count validation through the local GraphQL handler.

## Validation

- `vendor/bin/phpunit --configuration phpunit.xml tests/Unit/Domain/Asset/AssetTest.php` — passed; PHPUnit reported only the existing missing code coverage driver warning.
- `vendor/bin/phpunit --configuration phpunit.xml tests/Unit/Application/Asset/StartUploadServiceTest.php` — passed; PHPUnit reported only the existing missing code coverage driver warning.
- `vendor/bin/phpunit --configuration phpunit.xml tests/Unit/Http/GraphQLHandlerTest.php` — passed; PHPUnit reported only the existing missing code coverage driver warning.
- Editor diagnostics for the touched PHP files were clean.

## Delivery Chunks

- Batch-level validation contract — added top-level batch `userErrors` to the application and GraphQL payload shape.
- Batch request guardrails — short-circuited empty and oversized upload batches before any persistence or storage calls.
- Chunk-count boundary enforcement — moved the per-file `1..100` rule into the Asset aggregate and verified the mapped user-facing error path.
