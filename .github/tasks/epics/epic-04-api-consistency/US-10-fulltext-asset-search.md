# US-10: Search files by name

## User Story

As a **content manager**,
I want to **search for files by their name within my account**,
so that **I can quickly find the files I need without browsing manually**.

---

## Context

For large libraries, scanning by eye is slow. The service should let users search by file name and return only files that are ready to use. Results should be paginated with a sensible default page size.

---

## Acceptance Criteria

### Scenario 1: Search returns matching ready files with pagination info

```
Given several ready files contain the word "promo" in their name for my account
When  I search for "promo" with page size 10
Then  I see up to 10 matching files and a total count of matches
 And  the response contains no errors
```

### Scenario 2: Empty search is rejected

```
Given I send an empty search string
When  the request is processed
Then  the response contains a friendly error explaining the query must not be empty
 And  no files are returned
```

### Scenario 3: Only ready files for this account are returned

```
Given files in other accounts or files not yet ready match the search term
When  I search within my account
Then  only ready files that belong to my account are returned
 And  the total reflects only those matches
```

---

## Definition of Done

- [ ] Implementation complete
- [ ] PHPUnit unit tests pass

[EPIC-04: API Consistency](.github/tasks/epics/epic-04-api-consistency/EPIC-04.md)
