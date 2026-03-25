---
description: "GitHub Actions workflow conventions for this project. Enforced on all files under .github/workflows/. Covers action pinning, caching, secrets, permissions, and job structure."
applyTo: ".github/workflows/**/*.yml"
---

## Action Version Pinning

Always pin actions to a full semver tag — never use `@main` or `@latest`:

```yaml
# RIGHT
- uses: actions/checkout@v4
- uses: shivammathur/setup-php@v2
- uses: actions/cache@v4

# WRONG
- uses: actions/checkout@main
- uses: actions/checkout@latest
```

## Explicit Permissions

Set `permissions:` on every job. Default to least privilege:

```yaml
jobs:
  tests:
    runs-on: ubuntu-latest
    permissions:
      contents: read # minimum for checkout
      pull-requests: write # only when posting PR comments
      security-events: write # only for CodeQL SARIF upload
```

## Composer Dependency Caching

Always cache Composer dependencies. Use `composer.lock` hash as cache key:

```yaml
- name: Cache Composer dependencies
  uses: actions/cache@v4
  with:
    path: vendor
    key: ${{ runner.os }}-composer-${{ hashFiles('**/composer.lock') }}
    restore-keys: |
      ${{ runner.os }}-composer-
```

## Secrets

- Use `${{ secrets.SECRET_NAME }}` for sensitive values — never `${{ env.VAR }}` for secrets
- Never echo secrets in `run:` steps
- Use `GITHUB_TOKEN` (auto-provided) for GitHub API operations; request minimum required scopes in `permissions:`

```yaml
# RIGHT
env:
  GITHUB_TOKEN: ${{ secrets.GITHUB_TOKEN }}

# WRONG — exposes token in env var
env:
  TOKEN: ${{ secrets.MY_TOKEN }}
run: echo $TOKEN  # ← never log tokens
```

## Job Structure and Dependencies

Use `needs:` to express dependency chains. Fast jobs first:

```yaml
jobs:
  lint: # fast — run first
    ...
  test: # depends on lint
    needs: lint
  security: # can run in parallel with test
    ...
  deploy: # only after all checks pass
    needs: [test, security]
```

## Matrix Strategy

Use matrix for PHP version testing. Always test the minimum supported version and latest:

```yaml
strategy:
  matrix:
    php: [8.1, 8.2, 8.3]
  fail-fast: false # run all versions even if one fails
```

## Trigger Hygiene

- PR workflows: use `pull_request` trigger (not `push` to feature branches)
- Deploy workflows: use `push` to `main` only
- Scheduled scans: use `schedule` with `cron:` — run off-peak hours (02:00 UTC)
- Never trigger deploy on `pull_request` — only on merged `push` to `main`

## Security Scanning Jobs

Semgrep and Gitleaks run on PRs only. Trivy runs on push to main and scheduled:

```yaml
# Security jobs that block PRs — keep fast (< 2 min)
on:
  pull_request:

# Heavy scans — run on schedule
on:
  schedule:
    - cron: "0 2 * * *"
```
