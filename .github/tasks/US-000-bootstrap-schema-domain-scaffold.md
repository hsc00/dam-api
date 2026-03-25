# US-000a: Agree API Schema

## User Story

As a **developer**,
I want to **agree a single, human-readable schema file that lists all API messages and types**,
so that **the team has a single source of truth for how clients and the service communicate**.

---

## Context

Before writing any API logic, the team needs one clear file that describes the shape of requests, responses, and the important status values used across the product. This prevents confusion later and makes it easy for designers, product people, and engineers to agree on names and behaviour.

---

## Acceptance Criteria

### Scenario 1: Schema file exists and is accepted by the team

```
Given the repository has been cloned
When  the schema file is opened by the team
Then  the team agrees it describes the API messages and types in plain language
 And  there is a short note explaining each important value
```

### Scenario 2: Status values and core types are documented

```
Given the schema file is accepted
When  a reviewer inspects it
Then  the file lists the status values used for uploads (e.g. UPLOADING, PROCESSING, READY, FAILED)
 And  the file defines the shapes for a file, a chunk link, and a user-facing error
```

### Scenario 3: Actions are named and agreed

```
Given the schema file is accepted
When  a developer reads the file
Then  it clearly names the two main actions: starting an upload and finishing an upload
 And  it does not leave ambiguity about expected inputs or returned messages
```

---

## Out of Scope

- Server code and internal object implementations (covered in other stories)

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
