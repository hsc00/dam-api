# Implementation Log: US-11 Consistent Mutation Responses

**Feature:** US-11 Consistent Mutation Responses

## Summary

US-11 aligned the GraphQL mutation boundary with the existing application-level validation model. `startUpload`, `startUploadBatch`, and `completeUpload` now accept omitted or `null` input values at the schema boundary so routine validation failures can be returned in payload `userErrors` instead of surfacing as GraphQL transport errors. The change preserves the existing success payload shapes and keeps the byte-count contract explicit via the `ByteCount` scalar.

## Implementation Details

- Relaxed the mutation argument and input-field nullability in the GraphQL schema so the resolvers and application services can return friendly user errors for missing input instead of failing during GraphQL argument coercion.
  -- Kept the `ByteCount` schema type for `startUpload.fileSizeBytes` to preserve the public contract.
  -- Changed the scalar input coercion path so `ByteCount` now passes string and integer values through to resolver-level validation instead of rejecting malformed byte-count strings at the transport boundary.
- Reused the existing resolver and service validation paths instead of duplicating business rules in the HTTP or GraphQL handler layers.
- Added focused GraphQL handler coverage for invalid `startUpload` input, omitted `startUploadBatch` input, and omitted `completeUpload` input to prove the payload-level error contract across every mutation.
- Updated the public API reference to explain that these mutation inputs remain business-required even though the schema is intentionally permissive to support payload-level validation, and completed the missing `startUploadBatch` contract section with per-file semantics and error codes.

## Files Changed

- `src/GraphQL/Schema/schema.graphql` - made mutation arguments and their input fields nullable so invalid requests reach payload-level validation.
- `src/GraphQL/SchemaFactory.php` - relaxed `ByteCount` input coercion while preserving strict output serialization.
- `tests/Unit/Http/GraphQLHandlerTest.php` - added invalid-input coverage for all three mutations through the local GraphQL handler.
- `docs/api/01-agree-api-schema.md` - documented the intentionally permissive mutation input boundary, updated the published mutation signatures, and added the missing `startUploadBatch` reference section.
- `mkdocs.yml` - added the US-11 implementation log to the documentation navigation.

## Validation

- Editor diagnostics on `tests/Unit/Http/GraphQLHandlerTest.php` and `src/GraphQL/SchemaFactory.php` - no errors.
- `php -d opcache.jit=0 vendor/bin/phpunit --configuration phpunit.xml --filter 'itReturnsPayloadLevelUserErrorsWhenStartUploadInputFailsValidation|itReturnsPayloadLevelUserErrorsWhenStartUploadBatchOmitsInput|itReturnsPayloadLevelUserErrorsWhenCompleteUploadOmitsInput' tests/Unit/Http/GraphQLHandlerTest.php` - assertions passed; PHPUnit reported only the existing missing code coverage driver warning.
- `php -d opcache.jit=0 vendor/bin/phpunit --configuration phpunit.xml tests/Unit/Http/GraphQLHandlerTest.php` - passed with 25 tests and 184 assertions; PHPUnit reported only the existing missing code coverage driver warning.
- `php -d opcache.jit=0 vendor/bin/phpunit --configuration phpunit.xml --testsuite=unit` - passed with 227 tests and 1046 assertions; PHPUnit reported only the existing missing code coverage driver warning.
- `php -d opcache.jit=0 vendor/bin/phpstan analyse -c phpstan.neon --no-progress` - passed with no errors.

## Delivery Chunks

- Mutation schema boundary - relaxed mutation input coercion so routine validation no longer escapes as transport-level GraphQL errors.
- Scalar contract preservation - kept `ByteCount` as the public type while moving malformed-value handling into the payload validation path.
- Contract verification - added end-to-end GraphQL handler coverage for invalid input across every mutation.
