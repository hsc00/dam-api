# US-11: Consistent mutation responses

## User Story

As a **developer integrating the API**,
I want **mutation responses to always include a clear list of user-facing errors alongside the result**,
so that **clients can handle validation and business failures in a consistent, user-friendly way**.

---

## Context

We follow a convention where operations return any user-facing validation or business errors as part of the normal response. This keeps client integration simple and avoids relying on transport-level error codes for routine business validation.

---

## Acceptance Criteria

### Scenario 1: Every operation includes a user-facing errors list

```
Given any mutation the client can call
When the client inspects the response
Then the response always contains a list of user-facing errors (possibly empty) alongside the normal result
```

### Scenario 2: Validation failures are expressed in the response body

```
Given a mutation is called with invalid input
When the system validates the input
Then the response body contains a friendly error explaining the problem
 And the error is delivered in the normal response payload rather than as an unexpected transport fault
```

### Scenario 3: Business rule violations use the errors list

```
Given a business rule is violated during an operation
When the client receives the response
Then the violation is represented in the user-facing errors list and not as an unexpected transport error
```

---

## Definition of Done

- [ ] Implementation complete
- [ ] PHPUnit unit tests pass

[EPIC-04: API Consistency](.github/tasks/epics/epic-04-api-consistency/EPIC-04.md)
