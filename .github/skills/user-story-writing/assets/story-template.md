# US-{NN}: {Story Title}

---

## User Story

As a **{role}**,
I want to **{capability}**,
so that **{business value}**.

---

## Context

{One paragraph: why this story exists and what problem it solves for the user. Use plain language — no code, no system names, no technical jargon. Required.}

---

## Acceptance Criteria

### Scenario 1: {Happy path title}

```
Given  {what the user has set up or what is already true}
When   {what the user does}
Then   {what the user sees or experiences}
 And   {any other outcome the user notices}
```

### Scenario 2: {Something goes wrong}

```
Given  {a situation that leads to a problem}
When   {what the user does}
Then   {the user sees a helpful error or explanation}
 And   {nothing unexpected happens in the background}
```

### Scenario 3: {Edge or boundary case — optional}

```
Given  {an unusual but valid situation}
When   {what the user does}
Then   {the expected outcome for that edge case}
```

---

## Out of Scope (optional)

- {Things this story deliberately does NOT cover, to prevent scope creep}

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
