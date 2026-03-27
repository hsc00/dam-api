---
name: security-review
description: "Performs OWASP-focused security reviews for this DAM PHP PoC. Use when reviewing PHP code, GraphQL flows, HTTP boundaries, CI configuration, dependency risk, secret handling, or preparing a security verdict with concrete remediation."
argument-hint: "Describe the code, feature, or configuration to review (e.g. 'presign upload flow', 'MySQLAssetRepository', 'ci.yml security review')"
---

# Security Review Skill

## When to Use

- Reviewing PHP, GraphQL, HTTP, Docker, or CI changes for security issues
- Preparing a security verdict during feature review
- Validating secret handling, dependency risk, or authentication boundaries
- Converting a vague security concern into a concrete remediation list

## Review Inputs

Review the changed code and configuration with emphasis on:

- entry points for user input
- persistence and query layers
- authentication and authorization boundaries
- logging and secret exposure
- dependency and CI configuration changes

## Core Checklist

### A01 — Broken Access Control

- Auth middleware is present and enforced on protected routes
- No object access without ownership or account validation
- State-changing operations are not reachable without proper authorization

### A02 — Cryptographic Failures

- No plaintext sensitive data storage
- Randomness uses `random_bytes()` or `random_int()`
- Secrets are not hardcoded in code, config, or examples

### A03 — Injection

- SQL uses `PDO::prepare()` with named parameters
- No shell execution from user-controlled input
- File paths and URLs are validated before use
- No raw exception leakage that exposes infrastructure details

### A04 — Insecure Design

- Business rules are not bypassable through GraphQL field selection or thin boundary code
- Upload size, type, and state transitions are validated at the right boundary
- Rate limiting and auth are enforced in the HTTP layer where required

### A05 — Security Misconfiguration

- No debug helpers in production paths
- CI and Docker configs do not expose secrets or unsafe defaults
- `.env.example` uses safe placeholder values only

### A07 — Identification and Authentication Failures

- Token or key validation occurs in middleware or the correct system boundary
- Tokens and credentials are never logged

### A09 — Logging and Monitoring Failures

- Sensitive data is not written to logs
- Structured logging is preferred over ad hoc output

### A10 — SSRF

- No user-controlled URLs are fetched directly
- Presigned URLs are generated server-side, not trusted from client input

## Dependency and CI Checks

- Run or verify `composer audit` where possible
- Review CI/workflow changes for leaked secrets, unsafe permissions, or overbroad tokens
- Ensure security tooling remains enabled when workflows are changed

## Review Output Format

```text
## Security Review: {subject}
Verdict: APPROVE | REQUEST CHANGES | DECLINE
Confidence: HIGH | MEDIUM | LOW

OWASP Findings:
- [ISSUE] {problem} -> {remediation}
- [BLOCKER] {critical problem} -> {required fix}

Taint Paths:
- Source: {entry point}
  Sink: {dangerous operation}
  Remediation: {validation or redesign}

Required Changes:
1. {file path + required change}
```

## Verdict Rules

- `APPROVE` when no injection path, auth bypass, secret exposure, or comparable risk remains
- `REQUEST CHANGES` when medium-severity issues can be fixed within the current design
- `DECLINE` when there is a critical vulnerability, unsafe architecture, or unresolved secret/auth problem

## Keep It Focused

- Prefer concrete remediations over general warnings
- Report only material findings; do not pad the review with low-value `PASS` items
- Use the `php-pro` skill as a secure PHP baseline, not as a replacement for this checklist
