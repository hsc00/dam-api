# US-003a: Background job processing

## User Story

As a **platform operator**,
I want **background jobs to process uploaded files automatically**,
so that **files are converted and made available without manual work**.

---

## Context

When a user marks an upload as complete, the system schedules a background job. A worker process reads those jobs and performs the processing steps (e.g. combine pieces, run transforms). This story covers job consumption and normal failure handling.

---

## Acceptance Criteria

### Scenario 1: Successful processing marks file ready

```
Given a background job exists for a file that is marked processing
When  the worker handles the job
Then  the file is marked ready in the system
 And  a short-lived cache entry shows the ready status for fast lookups
 And  the job is not retried
```

### Scenario 2: Processing fails and file is marked failed

```
Given a background job causes an exception during processing
When  the worker handles the job
Then  the file is marked failed
 And  a short-lived cache entry shows the failed status for fast lookups
```

### Scenario 3: Unknown file id is handled gracefully

```
Given a job references a file id that does not exist
When  the worker handles the job
Then  the job is discarded and an error is logged for operator review
 And  the worker continues processing other jobs
```

---

## Out of Scope

- Moving repeatedly failing jobs to a separate failed-jobs list (US-003b)
- Worker container orchestration details

---

## Definition of Done

- [ ] Implementation complete
- [ ] PHPUnit unit tests pass
- [ ] PHPUnit coverage ≥ 80% for new code
- [ ] PHPStan level 8 passes
- [ ] CS Fixer reports no violations
- [ ] Architect approved API/DB design
- [ ] QA engineer approved test coverage
- [ ] Security reviewer approved (no OWASP Top 10 findings)
- [ ] Tech writer updated API docs
