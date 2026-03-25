---
agent: agent
description: "Run a comprehensive cross-agent code review on a specified file or feature. Collects verdicts from architect, QA engineer, and security reviewer. Surfaces all APPROVE / REQUEST CHANGES / DECLINE decisions in a unified review report."
---

@scrum-master

Run a comprehensive code review on the following target:

> ${input:review_target}
> (e.g. a file path like `src/Application/Asset/PresignService.php`, a PR branch name, or a feature name)

Orchestrate the following specialist reviews in parallel and collect their verdicts:

1. **@architect** — Review for Clean Architecture compliance, layer boundaries, DDD correctness, and API/DB design quality
2. **@qa-engineer** — Review for test coverage, test quality, edge case handling, and PHPUnit conventions
3. **@security-reviewer** — Review for OWASP Top 10 vulnerabilities, input validation, injection risks, and authentication/authorization correctness

For each agent, surface their full verdict in the format:

```
## {Agent} Review

**Verdict**: APPROVE | REQUEST CHANGES | DECLINE

### Findings
{list of findings with severity}

### Required Changes (if any)
{specific changes needed before approval}
```

After all verdicts are collected, provide a **unified summary**:

- Overall status: APPROVED (all agents approved) | CHANGES REQUIRED | BLOCKED
- Prioritized list of all required changes
- Recommended next steps
