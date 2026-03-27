# US-01: Agree API schema

## User Story

As a **developer**,
I want to **agree the initial contract for upload and asset tracking**,
so that **later work can be built against a shared, approved reference**.

---

## Context

This story defines the first agreed contract for the DAM API. The deliverable is the schema itself, used as the shared reference for later implementation work.

---

## Acceptance Criteria

### Scenario 1: Initial contract is agreed

```text
Given the team is preparing the first DAM release
When the contract is reviewed
Then it describes the upload and asset-tracking behavior needed for that release
 And it can be used as the agreed reference for later work
```

### Scenario 2: Scope is limited to the contract

```text
Given this story is complete
When the deliverable is reviewed
Then it contains the agreed contract only
 And it does not include server behavior or internal object design
```

### Scenario 3: The contract covers the first upload flow

```text
Given the schema is used as the reference for follow-up work
When it is reviewed
Then it includes the core shapes for starting an upload, finishing an upload, and checking file status
```

---

## Out of Scope

- Server-side behavior
- Internal object or domain design
- Persistence, infrastructure, or runtime wiring

---

## Definition of Done

- [ ] Contract document is complete
- [ ] Contract has been reviewed and agreed
- [ ] Acceptance criteria are met

[EPIC-01: Bootstrap — Schema, Domain & Infrastructure](https://github.com/hsc00/dam-api/issues/11)
