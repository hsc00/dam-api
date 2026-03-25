# EPIC-03: Background Processing

## Objective:

Provide reliable asynchronous processing for uploaded files, with robust retry/failure handling and operator tooling for inspecting and replaying failed jobs.

## Included tasks:

- [US-07: Background job processing](.github/tasks/epics/epic-03-background-processing/US-07-async-worker-pipeline.md) — normal job consumption and processing
- [US-08: Failed-job handling](.github/tasks/epics/epic-03-background-processing/US-08-worker-dead-letter-queue.md) — move repeatedly failing jobs to a failed-jobs list
- [US-09: Check file status](.github/tasks/epics/epic-03-background-processing/US-09-live-status-query.md) — provide current processing status and cache/durable-store semantics
