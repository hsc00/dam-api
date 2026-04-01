# ADR-04: Asset Domain Contracts

## Purpose

This document records the domain contract between the `Asset` aggregate, persistence, and storage for US-02. It is an implementation-level contract (not a Draw.io diagram) and belongs with the ADRs as permanent architecture documentation.

## Asset Repository Contract

`AssetRepositoryInterface` must provide these behaviors.

| Method                                       | Required behavior                                                                                                                                                                                               | Why US-02 needs it                                                                                                   |
| -------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------- |
| `save(Asset $asset): void`                   | Persist enough state to reconstruct the same domain object later. This includes `id`, `uploadId`, `accountId`, `fileName`, `mimeType`, `status`, `createdAt`, and `completionProof` when the asset is uploaded. | The `Asset` aggregate is the source of truth for lifecycle state. Persistence cannot drop uploaded-state proof data. |
| `findById(AssetId $assetId): ?Asset`         | Return the matching asset or `null` when none exists.                                                                                                                                                           | Later read and completion flows need a stable lookup by asset identity.                                              |
| `findByUploadId(UploadId $uploadId): ?Asset` | Return the matching asset or `null` when none exists.                                                                                                                                                           | The upload identifier is part of the upload flow contract and supports retry-safe lookup.                            |

Repository implementations must also preserve the uploaded-state invariant during reads.

- `PENDING` and `FAILED` assets can be reconstituted with `Asset::reconstitute(...)`.
- `UPLOADED` assets must be reconstituted with `Asset::reconstituteUploaded(...)` and must include a completion proof value.
- An uploaded record without completion proof is invalid domain state and must not be returned as a valid `Asset`.

## Storage Adapter Contract

`StorageAdapterInterface` must return a fully typed `UploadTarget` for an accepted `Asset`.

| Upload target field | Required behavior                                                                      | Notes                                                                    |
| ------------------- | -------------------------------------------------------------------------------------- | ------------------------------------------------------------------------ |
| `url`               | Must be an absolute URL. HTTPS is required except for local-development loopback URLs. | Validated by `UploadTarget`.                                             |
| `method`            | Must be a supported domain upload method.                                              | The current domain contract supports `PUT`.                              |
| `signedHeaders`     | Must be a list of `UploadParameter` objects.                                           | The contract avoids provider-specific associative arrays.                |
| `completionProof`   | Must describe which proof the client captures and where it comes from.                 | Expressed as `UploadCompletionProof` plus `UploadCompletionProofSource`. |
| `expiresAt`         | Must state when the target stops being valid.                                          | Consumers should treat it as a hard expiry.                              |

Storage adapters own signing, credentials, bucket selection, and provider-specific response details. Those details stay in infrastructure and are translated into the typed domain contract before being returned.

## Added to complete US-02

The accepted delivery extended slightly beyond the original story so the domain contract would be complete.

- `findByUploadId(...)` was added to the repository contract so upload flows can resolve an asset by upload identifier.
- `StorageAdapterInterface` and the typed upload-target model were added so upload instructions are represented as domain types instead of untyped infrastructure payloads.
- A completion-proof requirement was added to both upload completion and uploaded-state reconstitution so `UPLOADED` cannot exist without proof.

## Contract Boundary

This page is the durable contract for US-02. Infrastructure implementations may vary, but repository and storage adapters must continue to satisfy these behaviors and return values.
