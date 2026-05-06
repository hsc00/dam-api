# Implementation Log: US-10 Search Files by Name

**Feature:** US-10 Search Files by Name

## Summary

US-10 added the `searchAssets(query, page, pageSize)` GraphQL query for account-scoped file-name search. The accepted implementation returns `files`, `totalCount`, `pageInfo`, and `userErrors`, searches only uploaded assets, trims the plain-text query, and rejects an empty post-trim query with a friendly user error instead of a GraphQL transport failure. Pagination stays repository-owned, and result ordering is deterministic.

## Implementation Details

- Added `SearchAssetsQuery`, `SearchAssetsService`, and dedicated result types so the application layer owns trimmed query validation, page-size capping, total-count calculation, and repository-failure translation.
- Extended `AssetRepositoryInterface` and `MySQLAssetRepository` with `countByFileName()` and `searchByFileName()` for account-scoped, uploaded-only, case-insensitive file-name search with literal `%` and `_` handling, `created_at DESC, id ASC` ordering, and offset/limit pagination.
- Added `SearchAssetsPayload`, `SearchAssetsFile`, and `SearchAssetsPageInfo` to the GraphQL schema plus a `SearchAssetsResolver` wired through `SchemaFactory` and `public/index.php`.
- Covered the GraphQL happy path, pagination, page-size capping, empty-query rejection, sanitized execution failures on both count and search paths, application-boundary translation, and MySQL literal search semantics in focused unit and integration tests.
- Kept the final diagnostic cleanup local to `SearchAssetsService` by rebinding the repository dependency to a local variable to silence an editor false positive without changing behavior.

## Files Changed

- `public/index.php` - wired `SearchAssetsService` and `SearchAssetsResolver` into the runtime entrypoint.
- `src/Application/Asset/Command/SearchAssetsQuery.php` - added the application query DTO for account-scoped search input.
- `src/Application/Asset/SearchAssetsService.php` - implemented trimmed query validation, pagination metadata, repository calls, and repository-failure translation.
- `src/Application/Asset/Result/SearchAssetsFile.php` - mapped asset search rows into the GraphQL-facing file result.
- `src/Application/Asset/Result/SearchAssetsPageInfo.php` - defined repository-owned pagination metadata and max page-size behavior.
- `src/Application/Asset/Result/SearchAssetsResult.php` - carried files, counts, page info, and user errors back to the resolver.
- `src/Domain/Asset/AssetRepositoryInterface.php` - added the file-name count and search repository contract.
- `src/GraphQL/Resolver/SearchAssetsResolver.php` - mapped GraphQL arguments into the application query and response payload.
- `src/GraphQL/Schema/schema.graphql` - added the `searchAssets` query and its payload, file, and pagination types.
- `src/GraphQL/SchemaFactory.php` - registered the `searchAssets` resolver on the query root.
- `src/Infrastructure/Persistence/MySQLAssetRepository.php` - implemented trimmed literal `LIKE` search, uploaded-only filtering, deterministic ordering, and pagination.
- `tests/Integration/Infrastructure/Persistence/MySQLAssetRepositoryTest.php` - covered account scoping, uploaded-only filtering, pagination, empty-query handling, and literal `%` / `_` search behavior.
- `tests/Unit/Application/Asset/SearchAssetsServiceTest.php` - covered empty-query rejection, page-size capping, repository call ordering, and repository-failure translation.
- `tests/Unit/Http/GraphQLHandlerTest.php` - covered the GraphQL contract, pagination behavior, friendly user errors, and sanitized execution failures.

## Validation

- Editor diagnostics on `SearchAssetsService` and the touched tests - no errors.
- `vendor/bin/phpstan analyse -c phpstan.neon --no-progress src/Application/Asset/SearchAssetsService.php` - passed.
- `vendor/bin/phpunit --configuration phpunit.xml tests/Unit/Application/Asset/SearchAssetsServiceTest.php tests/Unit/Http/GraphQLHandlerTest.php` - assertions passed; the environment warned about a missing coverage driver.
- `vendor/bin/phpunit --configuration phpunit.xml --display-warnings tests/Unit/Application/Asset/SearchAssetsServiceTest.php tests/Unit/Http/GraphQLHandlerTest.php` - passed with 29 tests and 217 assertions; the environment still warned about a missing coverage driver.
- `composer test` - passed locally per QA.
- `composer analyse` - passed locally per QA.
- `composer test:integration` - executed in the app Docker container against a MySQL service; MySQL integration tests passed (OK 14 tests, 217 assertions)
- `composer mutate` - could not complete locally without a coverage driver per QA.
- `composer fix:check` - still reports unrelated formatting diffs outside the US-10 slice per QA.

## Delivery Chunks

- Search query contract and application flow - added the application query/service/result path, empty-query user error, and repository-failure translation.
- Repository and runtime wiring - added repository count/search methods, GraphQL schema and resolver wiring, and the runtime bootstrap registration.
- Review and validation hardening - added focused GraphQL, application, and MySQL search coverage, then kept the final SearchAssetsService diagnostic fix behavior-neutral.
