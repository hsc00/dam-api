# API Contract: US-01 Agree API Schema

## Purpose

US-01 defines the consumer-facing GraphQL contract for the DAM upload flow. It gives frontend and API consumers a stable agreement for starting an upload, completing an upload, and checking asset status while the implementation is completed in follow-up work.

This page documents the agreed contract only. It is the reference point for follow-up implementation.

Draft ADRs and initial PHP scaffolding may describe internal implementation concepts such as `uploadId`. Those are
implementation-level details and do not change the public contract documented here unless a later story explicitly
revises the schema.

## Query

### `asset(id: ID!): Asset`

Returns the current status for a single asset within the authenticated caller account scope. Possession of an `assetId` alone is not intended to authorize access.

**Arguments**

| Name | Type | Required | Description                                 |
| ---- | ---- | -------- | ------------------------------------------- |
| `id` | `ID` | Yes      | Asset identifier returned by `startUpload`. |

**Returns**

| Type    | Description                             |
| ------- | --------------------------------------- |
| `Asset` | The asset when found, otherwise `null`. |

**Authorization**

The query is intended to be resolved only for the authenticated caller's own account or ownership scope.

**Example**

```graphql
query GetAssetStatus($assetId: ID!) {
  asset(id: $assetId) {
    id
    status
  }
}
```

## Mutations

### `startUpload(input: StartUploadInput): StartUploadPayload!`

Starts one upload and returns the instructions the client needs to upload the file.

The `StartUploadInput` fields are business-required. The schema intentionally accepts omitted or `null` values so the server can report friendly validation failures in `userErrors` instead of surfacing GraphQL transport errors for routine request problems.

**Input Fields**

| Field            | Type        | Required | Description                                          |
| ---------------- | ----------- | -------- | ---------------------------------------------------- |
| `fileName`       | `String`    | Yes      | Original client file name.                           |
| `mimeType`       | `String`    | Yes      | Media type declared by the client.                   |
| `fileSizeBytes`  | `ByteCount` | Yes      | File size in bytes.                                  |
| `checksumSha256` | `String`    | Yes      | Base64-encoded SHA-256 checksum of the file content. |

**Returns**

| Field                  | Type            | Description                                                     |
| ---------------------- | --------------- | --------------------------------------------------------------- |
| `success.asset`        | `Asset`         | Created asset with its initial status.                          |
| `success.uploadTarget` | `UploadTarget`  | Time-limited upload instructions for the file transfer.         |
| `success.uploadGrant`  | `String`        | Server-issued grant required by `completeUpload`.               |
| `userErrors`           | `[UserError!]!` | Business validation errors when the request cannot be accepted. |

**Upload pattern**

Use `startUpload` for a single complete-file upload when the client already knows the final `fileSizeBytes` and `checksumSha256` for the whole file. The business-required fields for this path are `fileName`, `mimeType`, `fileSizeBytes`, and `checksumSha256`.

**Return semantics**

Clients should treat the payload as a success/userErrors pair: the `success` field is non-null only when the operation completed successfully; in that case `userErrors` MUST be an empty list. Conversely, when business validation prevents the request from succeeding, `success` will be `null` and `userErrors` will contain one or more `UserError` objects describing the problem(s). Clients MUST check `success` before reading `success.asset`, `success.uploadTarget`, or `success.uploadGrant`.

**Example**

```graphql
mutation StartUpload($input: StartUploadInput) {
  startUpload(input: $input) {
    success {
      asset {
        id
        status
      }
      uploadGrant
      uploadTarget {
        url
        method
        signedHeaders {
          name
          value
        }
        completionProof {
          name
          source
        }
        expiresAt
      }
    }
    userErrors {
      code
      message
      field
    }
  }
}
```

**Business Error Codes**

| Code                     | Meaning                                                          |
| ------------------------ | ---------------------------------------------------------------- |
| `INVALID_FILE_NAME`      | The supplied `fileName` is missing or failed domain validation.  |
| `INVALID_MIME_TYPE`      | The supplied `mimeType` is missing or failed validation.         |
| `INVALID_FILE_SIZE`      | `fileSizeBytes` is not a non-negative integer or exceeds limits. |
| `INVALID_CHECKSUM`       | `checksumSha256` is missing or invalid.                          |
| `MISSING_REQUIRED_FIELD` | A required input field was omitted or provided as blank.         |

### `startUploadBatch(input: StartUploadBatchInput): StartUploadBatchPayload!`

Starts multiple uploads in one request and returns one per-file outcome in request order.

The `StartUploadBatchInput.files` list and each `StartUploadBatchFileInput` field are business-required. The schema intentionally accepts omitted or `null` values so the server can report friendly validation failures in `userErrors` instead of surfacing GraphQL transport errors for routine request problems.

**Input Fields**

| Field                  | Type     | Required | Description                                                |
| ---------------------- | -------- | -------- | ---------------------------------------------------------- |
| `files[].clientFileId` | `String` | Yes      | Client correlation identifier echoed back in the response. |
| `files[].fileName`     | `String` | Yes      | Original client file name.                                 |
| `files[].mimeType`     | `String` | Yes      | Media type declared by the client.                         |
| `files[].chunkCount`   | `Int`    | Yes      | Number of upload chunks the client will send for the file. |

**Returns**

| Field                           | Type               | Description                                                         |
| ------------------------------- | ------------------ | ------------------------------------------------------------------- |
| `files[].clientFileId`          | `String!`          | Client correlation identifier echoed from the request.              |
| `files[].success.asset`         | `Asset`            | Created asset for one accepted file.                                |
| `files[].success.uploadGrant`   | `String`           | Server-issued grant required to complete that file upload later.    |
| `files[].success.uploadTargets` | `[UploadTarget!]!` | One upload target per declared chunk for that accepted file.        |
| `files[].userErrors`            | `[UserError!]!`    | Business validation errors for that specific file.                  |
| `userErrors`                    | `[UserError!]!`    | Whole-batch business validation errors that apply to the full call. |

**Upload pattern**

Use `startUploadBatch` for chunked or multipart uploads, or when the client wants to initiate multiple files in one request. Each `files[]` item is business-required to include `clientFileId`, `fileName`, `mimeType`, and `chunkCount`, and each accepted file returns one `uploadTargets` entry per declared chunk.

**Return semantics**

Clients should treat `startUploadBatch` as a mixed-outcome payload. Top-level `userErrors` reports whole-request failures such as an empty or oversized batch; in that case `files` will be empty and no file work will be performed. When the batch is accepted, top-level `userErrors` will be empty and each entry in `files` must be inspected independently: a file-level `success` value is present only for accepted files, and rejected files will instead report one or more `files[].userErrors`.

**Business Error Codes**

| Code                       | Meaning                                                               |
| -------------------------- | --------------------------------------------------------------------- |
| `EMPTY_BATCH`              | The request did not include any files.                                |
| `BATCH_TOO_LARGE`          | The request exceeded the server limit of 20 files.                    |
| `INVALID_CLIENT_FILE_ID`   | A file omitted `clientFileId` or supplied it as blank/whitespace.     |
| `DUPLICATE_CLIENT_FILE_ID` | Multiple files in the same batch reused the same `clientFileId`.      |
| `INVALID_FILE_NAME`        | A file name was missing or failed domain validation.                  |
| `INVALID_MIME_TYPE`        | A MIME type was missing or failed domain validation.                  |
| `INVALID_CHUNK_COUNT`      | A file declared a chunk count outside the accepted range of `1..100`. |

**Example**

```graphql
mutation StartUploadBatch($input: StartUploadBatchInput) {
  startUploadBatch(input: $input) {
    userErrors {
      code
      message
      field
    }
    files {
      clientFileId
      success {
        asset {
          id
          status
        }
        uploadGrant
        uploadTargets {
          url
          method
          completionProof {
            name
            source
          }
          signedHeaders {
            name
            value
          }
          expiresAt
        }
      }
      userErrors {
        code
        message
        field
      }
    }
  }
}
```

### `completeUpload(input: CompleteUploadInput): CompleteUploadPayload!`

Completes one upload after the client has sent the file and captured the required completion proof. When accepted, the asset moves into background processing immediately.

The `CompleteUploadInput` fields are business-required. The schema intentionally accepts omitted or `null` values so the server can report friendly validation failures in `userErrors` instead of surfacing GraphQL transport errors for routine request problems.

**Input Fields**

| Field             | Type     | Required | Description                                                                |
| ----------------- | -------- | -------- | -------------------------------------------------------------------------- |
| `assetId`         | `ID`     | Yes      | Asset identifier returned by `startUpload`.                                |
| `uploadGrant`     | `String` | Yes      | Server-issued grant returned by `startUpload`.                             |
| `completionProof` | `String` | Yes      | Upload proof value captured as directed by `uploadTarget.completionProof`. |

**Returns**

| Field           | Type            | Description                                                    |
| --------------- | --------------- | -------------------------------------------------------------- |
| `success.asset` | `Asset`         | Updated asset after a successful completion starts processing. |
| `userErrors`    | `[UserError!]!` | Business validation errors when completion cannot be accepted. |

**Business Error Codes**

| Code                       | Meaning                                                                |
| -------------------------- | ---------------------------------------------------------------------- |
| `ASSET_NOT_FOUND`          | The asset id does not exist in the authenticated caller account scope. |
| `INVALID_ASSET_ID`         | The supplied asset id is not a valid asset identifier.                 |
| `INVALID_UPLOAD_GRANT`     | The server-issued upload grant is missing or does not match the asset. |
| `INVALID_COMPLETION_PROOF` | The completion proof is missing or blank.                              |
| `INVALID_ASSET_STATE`      | The asset is already processing or is otherwise not completable now.   |

**Example**

```graphql
mutation CompleteUpload($input: CompleteUploadInput) {
  completeUpload(input: $input) {
    success {
      asset {
        id
        status
      }
    }
    userErrors {
      code
      message
      field
    }
  }
}
```

## Supporting Types

### Scalars

#### `DateTime`

ISO 8601 timestamp value.

#### `ByteCount`

Non-negative file size in bytes. This allows values beyond the GraphQL `Int` range.

### `Asset`

| Field    | Type           | Description                      |
| -------- | -------------- | -------------------------------- |
| `id`     | `ID!`          | Stable asset identifier.         |
| `status` | `AssetStatus!` | Current upload lifecycle status. |

### `AssetStatus`

| Value        | Meaning                                                 |
| ------------ | ------------------------------------------------------- |
| `PENDING`    | Upload has started but is not complete.                 |
| `PROCESSING` | Upload completed and background processing has started. |
| `UPLOADED`   | Upload completed successfully.                          |
| `FAILED`     | Upload failed.                                          |

### `UploadTarget`

| Field             | Type                               | Description                                                                                                                    |
| ----------------- | ---------------------------------- | ------------------------------------------------------------------------------------------------------------------------------ |
| `url`             | `String!`                          | Time-limited upload target. Production returns HTTPS; local mock may return deterministic `mock://uploads/{uploadId}/chunk/0`. |
| `method`          | `UploadHttpMethod!`                | HTTP method the client must use.                                                                                               |
| `signedHeaders`   | `[UploadParameter!]!`              | Headers that must be sent exactly as issued.                                                                                   |
| `completionProof` | `UploadCompletionProofDescriptor!` | Tells the client which proof to capture after upload.                                                                          |
| `expiresAt`       | `DateTime!`                        | Expiration timestamp for the upload target.                                                                                    |

Clients must perform a network upload only when `UploadTarget.url` uses `https://`. In local development, `mock://uploads/{uploadId}/chunk/0` is a deterministic stub for test and dev flows, so clients must skip the network upload and continue with the agreed mock behavior. Clients must reject `http://` targets and any other non-HTTPS, non-`mock://` scheme as a misconfiguration and security risk.

### `UploadHttpMethod`

| Value | Meaning                                             |
| ----- | --------------------------------------------------- |
| `PUT` | The client must upload using an HTTP `PUT` request. |

### `UploadParameter`

| Field   | Type      | Description                                  |
| ------- | --------- | -------------------------------------------- |
| `name`  | `String!` | Header name to send with the upload request. |
| `value` | `String!` | Exact header value to send.                  |

### `UploadCompletionProofDescriptor`

| Field    | Type                           | Description                                                           |
| -------- | ------------------------------ | --------------------------------------------------------------------- |
| `name`   | `String!`                      | Name of the proof value the client must capture.                      |
| `source` | `UploadCompletionProofSource!` | Where the client reads that proof after the upload request completes. |

### `UploadCompletionProofSource`

| Value             | Meaning                                              |
| ----------------- | ---------------------------------------------------- |
| `RESPONSE_HEADER` | The proof must be read from an HTTP response header. |

### `StartUploadSuccess`

| Field          | Type            | Description                                       |
| -------------- | --------------- | ------------------------------------------------- |
| `asset`        | `Asset!`        | Created asset with its initial status.            |
| `uploadTarget` | `UploadTarget!` | Upload instructions for the file transfer.        |
| `uploadGrant`  | `String!`       | Server-issued grant required by `completeUpload`. |

### `StartUploadPayload`

| Field        | Type                 | Description                                                             |
| ------------ | -------------------- | ----------------------------------------------------------------------- |
| `success`    | `StartUploadSuccess` | Successful result when the operation completes without business errors. |
| `userErrors` | `[UserError!]!`      | Business validation errors.                                             |

### `StartUploadBatchInput`

| Field   | Type                            | Required | Description                                         |
| ------- | ------------------------------- | -------- | --------------------------------------------------- |
| `files` | `[StartUploadBatchFileInput!]!` | Yes      | Files to initiate in this batch (in request order). |

### `StartUploadBatchFileInput`

| Field          | Type     | Required | Description                                                              |
| -------------- | -------- | -------- | ------------------------------------------------------------------------ |
| `clientFileId` | `String` | Yes      | Client correlation identifier echoed back in the response.               |
| `fileName`     | `String` | Yes      | Original client file name.                                               |
| `mimeType`     | `String` | Yes      | Media type declared by the client.                                       |
| `chunkCount`   | `Int`    | Yes      | Number of chunks the client will upload for this file (range: `1..100`). |

### `StartUploadBatchSuccess`

| Field           | Type               | Description                                                     |
| --------------- | ------------------ | --------------------------------------------------------------- |
| `asset`         | `Asset!`           | Created asset for the accepted file.                            |
| `uploadTargets` | `[UploadTarget!]!` | One `UploadTarget` per declared chunk for that file.            |
| `uploadGrant`   | `String!`          | Server-issued grant required to complete the file upload later. |

### `StartUploadBatchPayload`

| Field        | Type                              | Description                                                                                                  |
| ------------ | --------------------------------- | ------------------------------------------------------------------------------------------------------------ |
| `files`      | `[StartUploadBatchFilePayload!]!` | Per-file outcomes in request order (each item exposes `clientFileId`, optional `success`, and `userErrors`). |
| `userErrors` | `[UserError!]!`                   | Whole-batch business validation errors that apply to the full call (e.g., empty batch or too many files).    |

### `CompleteUploadSuccess`

| Field   | Type     | Description                                                    |
| ------- | -------- | -------------------------------------------------------------- |
| `asset` | `Asset!` | Updated asset after a successful completion starts processing. |

### `CompleteUploadPayload`

| Field        | Type                    | Description                                                             |
| ------------ | ----------------------- | ----------------------------------------------------------------------- |
| `success`    | `CompleteUploadSuccess` | Successful result when the operation completes without business errors. |
| `userErrors` | `[UserError!]!`         | Business validation errors.                                             |

### `UserError`

| Field     | Type      | Description                           |
| --------- | --------- | ------------------------------------- |
| `code`    | `String!` | Machine-readable error code.          |
| `message` | `String!` | Human-readable error message.         |
| `field`   | `String`  | Related input field, when applicable. |

## Contract Status

This is the agreed GraphQL contract for US-01. Follow-up implementation work should conform to this contract unless a later story explicitly revises it.

Internal draft architecture and scaffolding may evolve behind this contract. When those drafts differ from the public
API, the schema and this page remain the source of truth for US-01.
