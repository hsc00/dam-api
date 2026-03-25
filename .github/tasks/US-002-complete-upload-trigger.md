# US-002: Mark upload as complete

## User Story

As a **user uploading files**,
I want to **tell the system I have uploaded all parts of a file**,
so that **the system can begin working on the file and I can see progress**.

---

## Context

After the client finishes sending all pieces of a file, it must tell the service that the upload is complete. This lets the system start processing (for example: assembling pieces, creating thumbnails). This story covers the happy path and common rejections.

---

## Acceptance Criteria

### Scenario 1: Successful completion starts processing

```
Given a file is recorded as "uploading"
When  the user marks the file as complete
Then  the file is recorded as "processing"
 And  a background job is scheduled to handle the work
 And  the response shows the file is now processing with no errors
```

### Scenario 2: File not found

```
Given the provided file identifier does not exist
When  the user marks that file as complete
Then  the response contains a friendly error explaining the file was not found
 And  no background job is scheduled
```

### Scenario 3: Cannot complete from current state

```
Given a file is already being processed or is already ready or failed
When  the user marks it as complete
Then  the response contains a friendly error explaining the action is invalid
 And  no new background job is scheduled
```

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
