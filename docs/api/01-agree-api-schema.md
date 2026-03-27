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

### `startUpload(input: StartUploadInput!): StartUploadPayload!`

Starts one upload and returns the instructions the client needs to upload the file.

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

**Return semantics**

Clients should treat the payload as a success/userErrors pair: the `success` field is non-null only when the operation completed successfully; in that case `userErrors` MUST be an empty list. Conversely, when business validation prevents the request from succeeding, `success` will be `null` and `userErrors` will contain one or more `UserError` objects describing the problem(s). Clients MUST check `success` before reading `success.asset`, `success.uploadTarget`, or `success.uploadGrant`.

**Example**

```graphql
mutation StartUpload($input: StartUploadInput!) {
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

### `completeUpload(input: CompleteUploadInput!): CompleteUploadPayload!`

Completes one upload after the client has sent the file and captured the required completion proof.

**Input Fields**

| Field             | Type     | Required | Description                                                                |
| ----------------- | -------- | -------- | -------------------------------------------------------------------------- |
| `assetId`         | `ID`     | Yes      | Asset identifier returned by `startUpload`.                                |
| `uploadGrant`     | `String` | Yes      | Server-issued grant returned by `startUpload`.                             |
| `completionProof` | `String` | Yes      | Upload proof value captured as directed by `uploadTarget.completionProof`. |

**Returns**

| Field           | Type            | Description                                                    |
| --------------- | --------------- | -------------------------------------------------------------- |
| `success.asset` | `Asset`         | Updated asset after a successful completion.                   |
| `userErrors`    | `[UserError!]!` | Business validation errors when completion cannot be accepted. |

**Example**

```graphql
mutation CompleteUpload($input: CompleteUploadInput!) {
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

| Value      | Meaning                                 |
| ---------- | --------------------------------------- |
| `PENDING`  | Upload has started but is not complete. |
| `UPLOADED` | Upload completed successfully.          |
| `FAILED`   | Upload failed.                          |

### `UploadTarget`

| Field             | Type                               | Description                                           |
| ----------------- | ---------------------------------- | ----------------------------------------------------- |
| `url`             | `String!`                          | Time-limited upload URL.                              |
| `method`          | `UploadHttpMethod!`                | HTTP method the client must use.                      |
| `signedHeaders`   | `[UploadParameter!]!`              | Headers that must be sent exactly as issued.          |
| `completionProof` | `UploadCompletionProofDescriptor!` | Tells the client which proof to capture after upload. |
| `expiresAt`       | `DateTime!`                        | Expiration timestamp for the upload target.           |

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

### `CompleteUploadSuccess`

| Field   | Type     | Description                                  |
| ------- | -------- | -------------------------------------------- |
| `asset` | `Asset!` | Updated asset after a successful completion. |

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
