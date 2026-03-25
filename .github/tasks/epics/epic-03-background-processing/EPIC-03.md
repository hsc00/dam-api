# EPIC-03: Background Processing

## Objective:

Provide reliable asynchronous processing for uploaded files, with robust retry/failure handling and operator tooling for inspecting and replaying failed jobs.

## Included tasks:

- [US-07: Background job processing](https://github.com/hsc00/dam-api/issues/21) — normal job consumption and processing
- [US-08: Failed-job handling](https://github.com/hsc00/dam-api/issues/22) — move repeatedly failing jobs to a failed-jobs list
- [US-09: Check file status](https://github.com/hsc00/dam-api/issues/23) — provide current processing status and cache/durable-store semantics
