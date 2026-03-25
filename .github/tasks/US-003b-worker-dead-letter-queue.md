# US-003b: Failed-job handling

## User Story

As a **platform operator**,
I want **jobs that fail repeatedly to be moved to a separate failed-jobs list after a small number of retries**,
so that **I can inspect and fix problematic jobs without blocking normal processing**.

---

## Context

Some jobs fail repeatedly due to persistent problems. Instead of retrying forever, the worker should move such jobs to a failed-jobs list so operators can inspect and replay them if needed. The worker should keep a retry count inside the job payload.

---

## Acceptance Criteria

### Scenario 1: Move job to failed-jobs list after final retry

```
Given a job has already been retried twice
When  the job fails again
Then  the job is placed in the failed-jobs list for operator inspection
 And  the file is marked as failed
 And  the job is not retried automatically
```

### Scenario 2: Increment retry count before final retry

```
Given a job has been retried once
When  the job fails again
Then  the worker increments the retry count and requeues the job
 And  the job is not moved to the failed-jobs list yet
```

### Scenario 3: Failed-jobs list preserves original payload

```
Given a job has been moved to the failed-jobs list
When  an operator inspects the list
Then  the entry contains the original job payload and the final retry count
```

---

## Out of Scope

- Replay tools for failed jobs
- Monitoring or alerting for failed-jobs depth
- Complex back-off strategies

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
