# Security Choices

## Context

Security considerations require their own ADR to clearly separate operational and enforcement decisions from general tooling choices.

## Decisions

### Dependabot

Enable automated dependency updates via `.github/dependabot.yml` so the repository receives timely upgrade PRs for Composer packages.

### Semgrep

Run a lightweight Semgrep job on PRs with high-confidence rules that fail the PR. Use deeper rule sets on merge/nightly.

### Gitleaks

Run Gitleaks on commits/PRs to detect accidentally committed secrets early and block merges that expose credentials.

### Trivy

Scan built container images for CVEs on merges or scheduled runs using Trivy and fail builds on critical/high severity findings.

### CodeQL

Keep CodeQL enabled for repository-level SAST via GitHub Actions; it complements Semgrep with broader query patterns for OWASP-style issues.

### Composer audit

Keep `composer audit` in CI to surface package-level advisories during dependency install steps.

### Secrets & SBOM

- Add `gitleaks` for secret scanning in PRs.

## Future Considerations

- SBOM generation with `syft` for releases (not yet decided — requires evaluating licensing and distribution requirements).

### Reviewdog

Post `phpstan`, `php-cs-fixer`, and `semgrep` output as PR comments using Reviewdog to improve reviewer UX and tie findings to code locations.

### CodeRabbit (AI reviews)

Integrate CodeRabbit as a reviewer that posts maintainability and clean-code suggestions. Treat it as advisory and tune for noise and privacy concerns.

## Enforcement strategy

- PR-level (fast, mandatory): `phpstan` (light), `php-cs-fixer` (check), `composer audit`, Semgrep quick rules, Gitleaks.
- PR-level: CodeRabbit (AI), Reviewdog-posted analyzer output until tuned.
- Merge/nightly (heavy): PSalm full rules, Infection mutation testing, Trivy image scans, deep Semgrep rule sets, and full CodeQL runs.
- Maintain `roave/security-advisories` to block known-vulnerable package installs at `composer install` time.

## Consequences

- Faster PR feedback for common security issues while reserving deeper costly scans for merge/nightly runs.
- AI reviews improve maintainability suggestions but must be non-blocking to avoid false-positive blocking and data leakage.
- Additional CI jobs increase runtime and maintenance; use caching and scheduling to reduce friction.
