# EPIC-02: Upload Flow

## Objective:

Provide a robust, chunked upload initiation and completion flow that supports large batches and clear validation, enabling reliable client uploads and efficient server-side processing.

## Included tasks:

- [US-04: Start multi-file upload](https://github.com/hsc00/dam-api/issues/18) — ask the system for one upload link per chunk for each file in a batch
- [US-05: Upload request validation](https://github.com/hsc00/dam-api/issues/19) — report friendly validation errors for invalid upload requests
- [US-06: Mark upload as complete](https://github.com/hsc00/dam-api/issues/20) — notify the system that all parts of a file have been uploaded
