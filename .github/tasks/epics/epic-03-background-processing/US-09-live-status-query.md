# US-09: Check file status

## User Story

As a **user**,
I want to **check the current status of a file**,
so that **I can show progress to end users while the file is being processed**.

---

## Context

Processing runs asynchronously. We keep a small, fast cache for in-progress files and a durable store for final states. The status endpoint should return whether the information came from the fast cache or from the durable store so clients can understand recency.

---

## Acceptance Criteria

### Scenario 1: Fast-cache hit

```
Given a recent status is in the fast cache
When  the user requests the file status
Then  the response shows the expected status and indicates it came from the fast cache
```

### Scenario 2: Cache miss falls back to durable store and seeds cache

```
Given the fast cache does not contain the file status
When  the user requests the file status
Then  the response is read from the durable store
 And  the fast cache is populated for subsequent quick reads
```

### Scenario 3: File not found

```
Given no record exists for the requested file
When  the user requests the file status
Then  the response is null and no error is thrown
```

---

## Definition of Done

- [ ] Implementation complete
- [ ] PHPUnit unit tests pass

[EPIC-03: Background Processing](.github/tasks/epics/epic-03-background-processing/EPIC-03.md)
