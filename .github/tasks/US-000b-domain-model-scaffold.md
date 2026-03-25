# US-000b: Domain Model — core objects

## User Story

As a **developer**,
I want to **have the core server-side objects that represent an asset and its identifiers**,
so that **business logic can use clear, stable names when working with files and uploads**.

---

## Context

Before implementing application behaviour, we need plain server-side objects that represent an uploaded file, the identifier given by a client, and the account that owns it. These objects make the domain language consistent across the codebase and documentation.

---

## Acceptance Criteria

### Scenario 1: Asset object expresses the right properties

```
Given the codebase is loaded
When  a developer constructs an asset object with valid values
Then  the object exposes an id, the upload identifier, the account identifier, the file name, the mime type, the status value, and a creation timestamp
 And  the object is clearly immutable once created (no surprising side effects)
```

### Scenario 2: Identifiers validate input

```
Given an empty identifier value
When  a developer attempts to create an identifier object
Then  construction fails with a clear validation error
 And  valid non-empty values construct successfully
```

### Scenario 3: Storage and repository contracts are described

```
Given the repository is inspected
When  a developer reads the documentation
Then  there is a clear description of what the storage adapter and the repository must provide (e.g. save, find, generate a link)
```

---

## Out of Scope

- Concrete storage or database implementations (covered elsewhere)

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
