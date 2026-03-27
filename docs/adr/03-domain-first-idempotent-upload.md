# ADR-03: Domain-first modeling and Idempotent upload flow

## Context

This project implements a presigned-upload + asset status tracking flow. We must make small but durable architectural
choices for the PoC that: preserve the TTFHW (Time to First Hello World) principle, keep business logic independent of infrastructure, and make the
presign/upload flow safe to call repeatedly from unreliable clients or retrying networks.
Constraints and forces:

- No framework — small, testable PHP code (PHP 8.5).
- `docker compose up` must produce a runnable system (migrations automated).
- Clients may retry presign/create calls — the system must avoid creating duplicate assets.

## Decision

1. Domain-first modeling: the canonical model will be the Domain layer. We will define:
   - `Asset` (aggregate root)
   - `UploadId`, `AccountId` (value objects)
   - `AssetStatus` (enum)
   - `AssetRepositoryInterface` (Domain contract)

   The domain objects and invariants are the source of truth; persistence and GraphQL are adapters implemented against
   the Domain model.

2. Idempotency by `uploadId` + DB uniqueness: the `uploadId` is an internal idempotency key candidate for the
   presign/create operation.
   The persistence schema will enforce `UNIQUE(upload_id)` so the database is authoritative about duplicates.

3. UUIDs for identities: use UUID v4 for `uploadId`.

4. Application behavior (presign/create): the application service that implements the presign flow will follow this
   pattern:
   - Attempt to find an existing `Asset` by `uploadId` and return it if found.
   - Otherwise create `Asset` in-memory and persist via the repository.
   - If the repository/DB insert fails with a unique-key conflict, the service will re-query by `uploadId` and return
     the existing `Asset` (optimistic/concurrent-safe).

5. Keep presigned URL generation in Infrastructure (storage adapter). The Domain and Application layers do not know
   bucket names, credentials, or URL signing details.

## Consequences

Positive

- Business rules (status transitions, ownership) live in Domain and are well tested.
- Idempotent presign/create calls are safe for client retries and network retries.
- Simpler, stable GraphQL mapping: GraphQL types mirror Domain objects.

Negative / Tradeoffs

- Need to implement unique constraint and handle duplicate-key exceptions in infrastructure.
- Slightly more work initially to model Domain objects before wiring persistence and GraphQL.

Neutral

- Storage details (S3/GCS) remain pluggable — adapters can be swapped without changing Domain.
