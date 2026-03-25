# US-000c: Data & Local Storage Setup

## User Story

As a **developer**,
I want to **have a local data table and a local storage stub that needs no cloud account**,
so that **the project can be run and tested locally with zero external setup**.

---

## Context

Our project should create its data table automatically on startup and provide a storage stub that returns deterministic links for uploaded chunks.

---

## Acceptance Criteria

### Scenario 1: Data table exists with expected fields

```
Given the project is started from a fresh clone
When  the setup runs
Then  a data table for assets exists with fields for id, upload identifier, account identifier, file name, mime type, status, chunk count, created and updated timestamps
 And  basic text search over file names is available for local development
```

### Scenario 2: Running setup twice is safe

```
Given setup has run once
When  it runs again
Then  it does not error and the structure remains correct
```

### Scenario 3: Local storage stub returns deterministic links

```
Given the local storage stub is available
When  a developer asks for a link for upload id "uid-001" and chunk 0
Then  the stub returns a deterministic link such as "mock://uploads/uid-001/chunk/0"
 And  no network or cloud credentials are required
```

---

## Out of Scope

- Real cloud storage integration
- Complex migration tooling

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
