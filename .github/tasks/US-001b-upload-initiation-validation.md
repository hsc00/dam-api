# US-001b: Upload request validation

## User Story

As a **user uploading files**,
I want to **get clear, friendly errors when my upload request is invalid**,
so that **I can fix the request and try again without confusion**.

---

## Context

The system should protect itself from invalid or abusive requests (for example: asking for zero pieces, or an extremely large batch). This story defines how validation errors are reported to the user in plain language.

---

## Acceptance Criteria

### Scenario 1: Piece count outside allowed range

```
Given I request 0 pieces or more than 100 pieces for a file
When  I send the upload initiation request
Then  the response contains a friendly error for that file explaining the allowed range
 And  that file is not recorded in the system
```

### Scenario 2: Empty batch is rejected

```
Given I send a request with no files
When  the request is processed
Then  the response contains a friendly top-level error explaining that at least one file is required
 And  no files are recorded
```

### Scenario 3: Batch that is too large is rejected

```
Given I send a request with more than 20 files
When  the request is processed
Then  the response contains a friendly top-level error explaining the batch size limit
 And  no files are recorded
```

---

## Out of Scope

- Core multi-file upload flow (US-001a)
- Detailed file metadata validation such as mime type or filename length

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
