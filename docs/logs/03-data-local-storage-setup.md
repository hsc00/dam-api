# Implementation Log: US-03 Data & Local Storage Setup

**Feature:** US-03 Data & Local Storage Setup

## Summary

US-03 delivered the first durable local data path for assets and a local-development storage adapter that does not require cloud infrastructure. The accepted work covered the asset lifecycle persistence model, the assets-table bootstrap migration, a MySQL repository with stale-write protection, and a deterministic mock upload target for local development. API and ADR documentation were updated so the local mock behavior and repository and storage contract stay explicit.

## Implementation Details

- Extended the Asset aggregate with persisted lifecycle fields chunkCount and updatedAt. New pending assets start with chunkCount 1 and updatedAt equal to createdAt, and status transitions only advance updatedAt when time moves forward.
- Extended AssetRepositoryInterface with account-scoped file-name search using the trimmed query as a plain-text, case-insensitive substring match with deterministic ordering.
- Added an idempotent assets-table bootstrap migration that persists lifecycle fields and enforces uploadId uniqueness, allowed status values, positive chunk_count, completion-proof consistency, and updated_at not earlier than created_at.
- Added MySQLAssetRepository using prepared statements with named parameters for insert, lookup, and search. The repository supports uploadId lookup, deterministic account-scoped search, no-op identical save, domain reconstitution of persisted lifecycle state, and stale-write protection.
- Added StaleAssetWriteException in the domain so stale persistence conflicts are surfaced as a domain-owned failure.
- Tightened UploadTarget so local mock URLs are accepted only in the exact shape mock://uploads/{uploadId}/chunk/0 while preserving existing HTTPS and loopback local-development allowances.
- Added MockStorageAdapter to return deterministic local upload targets and typed completion-proof metadata without requiring cloud storage in local development.
- Updated API and ADR documentation to describe deterministic local mock behavior and the lifecycle persistence and search contract.

## Added to complete US-03

- The lifecycle persistence fields and DB constraints were added so the first MySQL-backed asset state could be reconstructed without weakening domain invariants.
- Account-scoped file-name search was added to the repository contract and MySQL implementation so higher layers have deterministic search semantics before runtime wiring lands.
- Stale-write handling was added at the domain and repository boundary so repeated or competing saves fail safely instead of silently overwriting newer state.
- The local mock upload target was narrowed to a single deterministic shape so local development stays storage-free without allowing arbitrary mock URLs.

## Files Changed

- src/Domain/Asset/Asset.php — persists chunkCount and updatedAt in the aggregate and enforces monotonic lifecycle timestamps.
- src/Domain/Asset/AssetRepositoryInterface.php — adds account-scoped file-name search to the domain repository contract.
- src/Domain/Asset/Exception/StaleAssetWriteException.php — defines the domain-owned exception for stale writes.
- src/Domain/Asset/ValueObject/UploadTarget.php — narrows accepted local mock URLs to the agreed deterministic shape while keeping existing HTTPS and loopback rules.
- migrations/20260401120000_create_assets_table.sql — bootstraps the assets table idempotently and enforces lifecycle constraints in MySQL.
- src/Infrastructure/Persistence/MySQLAssetRepository.php — implements MySQL persistence, uploadId lookup, deterministic search, no-op identical save, and compare-and-swap stale-write protection.
- src/Infrastructure/Storage/MockStorageAdapter.php — returns deterministic local upload targets without cloud storage.
- docs/api/01-agree-api-schema.md — documents deterministic local mock upload targets for local development.
- docs/adr/04-asset-domain-contracts.md — records lifecycle persistence rules, search semantics, and the local mock URL contract.
- tests/Unit/Domain/Asset/AssetTest.php — covers lifecycle persistence fields and timestamp invariants in the aggregate.
- tests/Integration/Infrastructure/Persistence/AssetsTableBootstrapTest.php — verifies the assets-table bootstrap migration and DB-level lifecycle constraints.
- tests/Integration/Infrastructure/Persistence/MySQLAssetRepositoryTest.php — verifies MySQL persistence behavior, search ordering, idempotent save, and stale-write protection.
- tests/Unit/Domain/Asset/ValueObject/UploadTargetTest.php — verifies the exact local mock target shape and existing transport security rules.
- tests/Unit/Infrastructure/Storage/MockStorageAdapterTest.php — verifies deterministic local mock upload targets.

## Validation

- Targeted Asset domain unit tests passed.
- Docker-backed assets-table bootstrap integration test passed.
- Docker-backed MySQL repository integration test passed.
- Targeted UploadTarget and MockStorageAdapter unit tests passed with 38 tests and 72 assertions.
- Editor diagnostics were clean for the repaired PHP files.

## Delivery Chunks

- Lifecycle persistence model — extended the Asset aggregate and repository contract with persisted lifecycle fields and account-scoped search semantics.
- Database bootstrap — added the idempotent assets-table migration and DB-level lifecycle constraints.
- MySQL repository — implemented prepared-statement persistence, uploadId lookup, deterministic search, no-op identical save, and stale-write protection.
- Local mock storage — tightened UploadTarget validation, added MockStorageAdapter, and documented deterministic local mock behavior.

## Follow-up

- Wire storage adapter selection through the application and GraphQL runtime path.
- Run the full pre-merge quality gate end to end before merge if it has not already been covered elsewhere.
