---
name: ci-pipeline
description: "Designs and reviews GitHub Actions CI/CD pipeline workflows following project CI conventions. Use when adding a new workflow, modifying an existing one, adding a new CI job, setting up a matrix strategy, or ensuring pipeline security (pinned action versions, minimal permissions, secret handling)."
argument-hint: "Describe the pipeline change needed (e.g. 'add mutation testing job', 'add Docker build and push step')"
---

# CI Pipeline Skill

## When to Use

- Adding a new GitHub Actions workflow
- Modifying an existing workflow (ci.yml, security.yml, reviewdog.yml, trivy.yml, mkdocs-deploy.yml)
- Adding a new job to an existing workflow
- Reviewing a pipeline for security or correctness issues
- Setting up caching, matrix builds, or deployment steps

## Existing Workflows

| File                                  | Trigger                                | Purpose                                    |
| ------------------------------------- | -------------------------------------- | ------------------------------------------ |
| `.github/workflows/ci.yml`            | push/PR to main                        | PHPStan, CS Fixer, PHPUnit, composer audit |
| `.github/workflows/security.yml`      | push/PR to main                        | Semgrep SAST, Gitleaks secrets scan        |
| `.github/workflows/reviewdog.yml`     | PR only                                | Inline PR annotations via reviewdog        |
| `.github/workflows/trivy.yml`         | push/PR to main, weekly schedule       | Container/filesystem vulnerability scan    |
| `.github/workflows/mkdocs-deploy.yml` | push to main (docs/\*\* or mkdocs.yml) | Build and deploy MkDocs to GitHub Pages    |

## Workflow Design Procedure

### 1. Determine the Trigger

```yaml
on:
  push:
    branches: [main]
  pull_request:
    branches: [main]
  # OR for scheduled:
  schedule:
    - cron: "0 3 * * 1" # Mondays at 03:00 UTC
  # OR for manual:
  workflow_dispatch:
```

### 2. Declare Minimal Permissions

Always declare explicit permissions at the top of the file — never rely on defaults:

```yaml
permissions:
  contents: read
  # Add only what is strictly needed:
  # pull-requests: write   → for PR comments/annotations
  # pages: write           → for GitHub Pages deploy
  # id-token: write        → for OIDC auth
  # security-events: write → for CodeQL SARIF upload
```

### 3. Pin Action Versions to SHA

**Always** pin third-party actions to a full commit SHA — never use `@main`, `@master`, or floating minor tags:

```yaml
- uses: actions/checkout@11bd71901bbe5b1630ceea73d27597364c9af683 # v4.2.2
- uses: shivammathur/setup-php@c541c155eee45413f5b09a52248675b1a2575231 # v2.31.1
```

To find the correct SHA: run `gh api repos/{owner}/{repo}/git/ref/tags/{tag}` or check Releases.

### 4. Cache Composer Dependencies

Include caching in every PHP job:

```yaml
- name: Cache Composer packages
  uses: actions/cache@d4323d4dc3a9502f714873b1c12a85b11c3c37b9 # v4.2.2
  with:
    path: vendor
    key: ${{ runner.os }}-composer-${{ hashFiles('**/composer.lock') }}
    restore-keys: |
      ${{ runner.os }}-composer-

- name: Install dependencies
  run: composer install --prefer-dist --no-progress --no-interaction
```

### 5. Standard PHP Job Structure

```yaml
jobs:
  { job-name }:
    runs-on: ubuntu-24.04
    steps:
      - uses: actions/checkout@{SHA} # pin to SHA
      - uses: shivammathur/setup-php@{SHA}
        with:
          php-version: "8.5"
          extensions: pdo, pdo_mysql, redis
          coverage: xdebug # only if coverage needed
      - name: Cache Composer packages
        uses: actions/cache@{SHA}
        with:
          path: vendor
          key: ${{ runner.os }}-composer-${{ hashFiles('**/composer.lock') }}
      - name: Install dependencies
        run: composer install --prefer-dist --no-progress --no-interaction
      - name: Run {tool}
        run: vendor/bin/{tool} {args}
```

### 6. Secret Handling

- Use `${{ secrets.SECRET_NAME }}` — never hardcode credentials
- Use `environment:` for deployment secrets to apply protection rules
- Never echo secrets to logs — use `::add-mask::` for dynamic secrets
- Never pass secrets via `env:` to untrusted third-party actions

### 7. Job Dependencies

Use `needs:` to enforce ordering:

```yaml
jobs:
  lint:
    ...
  test:
    needs: lint
    ...
  deploy:
    needs: [lint, test]
    if: github.ref == 'refs/heads/main'
```

## CI Conventions Reference

See `.github/instructions/ci-conventions.instructions.md` for the full set of enforceable rules.
