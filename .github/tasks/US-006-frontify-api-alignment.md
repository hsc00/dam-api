# US-006: Consistent mutation responses

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

## Out of Scope

- Query return types (read-only queries may follow different patterns)
- Client SDK changes

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
