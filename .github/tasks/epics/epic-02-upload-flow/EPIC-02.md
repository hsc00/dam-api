# EPIC-02: Upload Flow

## Objective:

Provide a robust, chunked upload initiation and completion flow that supports large batches and clear validation, enabling reliable client uploads and efficient server-side processing.

## Included tasks:

- [US-04: Start multi-file upload](.github/tasks/epics/epic-02-upload-flow/US-04-chunk-aware-upload-initiation.md) — ask the system for one upload link per chunk for each file in a batch
- [US-05: Upload request validation](.github/tasks/epics/epic-02-upload-flow/US-05-upload-initiation-validation.md) — report friendly validation errors for invalid upload requests
- [US-06: Mark upload as complete](.github/tasks/epics/epic-02-upload-flow/US-06-complete-upload-trigger.md) — notify the system that all parts of a file have been uploaded
