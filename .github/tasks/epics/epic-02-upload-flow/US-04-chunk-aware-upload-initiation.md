# US-04: Start multi-file upload

## User Story

As a **user uploading files**,
I want to **ask the system for one upload link per chunk for each file in my batch**,
so that **I can upload many files and many pieces of each file in parallel**.

---

## Context

Clients sometimes upload many large files at once. To make that fast, the client asks the service for the links needed to upload each piece of each file. This story covers the happy path and partial-failure behaviour for that request. Validation rules (limits and sizes) are handled in a separate story.

---

## Acceptance Criteria

### Scenario 1: One file split into many pieces

```
Given I have one file split into 5 pieces
When  I request upload links for that file
Then  I receive 5 distinct links, one for each piece
 And  the file is recorded as "upload started"
 And  I see no errors in the response
```

### Scenario 2: Multiple files in one request

```
Given I include three different files in one request
When  I request upload links for the batch
Then  I receive a list with one entry per file and the expected number of piece links for each
 And  all returned links are distinct
 And  each file is recorded as "upload started"
```

### Scenario 3: One file in the batch has a duplicate identifier

```
Given one file in my batch uses an identifier already in the system
When I request upload links for the batch
Then the response includes a helpful error for that file only
 And  other files in the same request succeed and return links
```

---

## Out of Scope

- Input validation for chunk counts and batch size (see US-05)
- The actual transfer of bytes using the returned links

---

## Definition of Done

- [ ] Implementation complete
- [ ] PHPUnit unit tests pass

[EPIC-02: Upload Flow](https://github.com/hsc00/dam-api/issues/15)
