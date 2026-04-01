# DAM PHP API — Workspace Context

Framework-free PHP 8.5 GraphQL API for Digital Asset Management: presigned upload flow and asset status tracking. No framework — only Composer-managed packages: webonyx/graphql-php, vlucas/phpdotenv, monolog/monolog.

## Layer Map

```
Domain          ← no external dependencies; owns Entity, ValueObject, RepositoryInterface, DomainEvent
Application     ← depends on Domain only; orchestrates use cases via Command + ApplicationService
Infrastructure  ← implements Domain interfaces (MySQLAssetRepository, CachedAssetRepository, MockStorageAdapter)
GraphQL         ← thin adapter layer; resolves queries/mutations by calling Application services only
Http            ← AuthMiddleware → CorsMiddleware → RateLimitMiddleware → GraphQLHandler
```

## TTFHW — Time To First Hello World

**TTFHW** is a first-class project principle: a developer who has just cloned the repository must be able to reach a running, queryable API with **two commands** and zero manual infrastructure setup:

```bash
cp .env.example .env
docker compose up
```

API endpoint: `http://localhost:8000/graphql`

Every architectural, tooling, and infrastructure decision must preserve this. If a change requires a developer to manually create a database, install a system package, or configure a service outside of `docker-compose.yaml`, it violates TTFHW and must be reconsidered.

TTFHW checklist for every feature:

- All new services added to `docker-compose.yaml`
- All new env vars added to `.env.example` with safe defaults
- No migration step that cannot be automated at container startup
- `MockStorageAdapter` available so storage never requires a real S3/GCS account locally

## Key Conventions

- Every PHP file: `declare(strict_types=1)` on line 1 — no exceptions
- DDD naming: `Asset` (Entity), `UploadId` / `AccountId` (ValueObject), `AssetRepositoryInterface`, `AssetStatusChangedEvent` (DomainEvent)
- No framework classes anywhere in `src/` — no Symfony, Laravel, or similar
- Repository interfaces live in `Domain/`; implementations in `Infrastructure/`
- GraphQL resolvers call Application services only — never access repositories or DB directly
- All DTOs and Value Objects use `readonly` properties or `readonly class`
- Infrastructure exceptions caught at Application boundary, converted to domain errors
- Redis cache uses TTL jitter to avoid thundering-herd expiry

## Do Not

- Do NOT use `$_GET`, `$_POST`, `$_SERVER` outside `src/Http/`
- Do NOT write raw SQL strings — always use `PDO::prepare()` with named parameters
- Do NOT add framework dependencies to `composer.json`
- Do NOT skip `declare(strict_types=1)`
- Do NOT put business logic in GraphQL resolvers
- Do NOT use `eval()`
- Do NOT trust user input without validation at system entry boundaries

## SCRUM Agent Team

This project uses a SCRUM-like agent team. For all feature work, use **@scrum-master** as the sole entry point.

| Agent             | Role                                                                                   |
| ----------------- | -------------------------------------------------------------------------------------- |
| **@scrum-master** | Orchestrator — sole user entry point for all feature work                              |
| product-owner     | User stories, acceptance criteria, business validation                                 |
| architect         | Architecture decisions, API design, DB schema, draw.io diagrams, ADRs, tech evaluation |
| backend-dev       | PHP implementation following DDD + Clean Architecture                                  |
| qa-engineer       | PHPUnit tests, quality review, mutation testing                                        |
| devops            | CI/CD pipelines, Docker, GitHub Actions workflows                                      |
| tech-writer       | API docs, MkDocs content, developer guides                                             |
| security-reviewer | OWASP Top 10 and SAST review (read-only subagent)                                      |

Quick-launch prompts (type `/` in chat): `/new-feature`, `/review-code`, `/draw-architecture`

## Token Efficiency

- Prefer the smallest workflow that fits the task. Do not run the full SCRUM ceremony for narrow edits that do not change product scope, architecture, schema, or infrastructure.
- After the first response, communicate deltas only. Do not restate unchanged requirements, prior findings, or full plans.
- Reviews should focus on `ISSUE` and `BLOCKER` items. Include `PASS` items only when needed to justify an approval or clarify a tradeoff.
- Keep specialist outputs compact by default: concise summary, concrete file list, and only the checks that were actually run.
- Load only the skills and files required for the current task. Avoid broad context gathering when a targeted read is sufficient.
- When a reusable implementation rule is discovered, prefer updating a scoped instruction or skill first. Update an agent file only when the role, delegation flow, or review behavior itself needs to change.

## Feedback Carry-Forward

When the user or another subagent returns `REQUEST CHANGES` or `DECLINE` on a prior attempt:

- Treat every `Required Changes` item, rejection reason, and explicit new constraint as mandatory input to the next attempt until it is satisfied or the user explicitly removes it
- Do not resubmit a previously rejected approach unless the root cause has been addressed and the revision makes that correction clear
- When the feedback reveals a reusable rule, persist it in the narrowest correct customization surface so it carries into future runs: scoped instruction or skill first, workspace instructions only when the rule is truly repo-wide, and agent files only when the role workflow, delegation behavior, or review authority itself must change

## Build & Test

```bash
composer install
vendor/bin/phpstan analyse -c phpstan.neon
vendor/bin/php-cs-fixer fix --dry-run --diff
vendor/bin/phpunit --configuration phpunit.xml --testsuite=unit
composer audit
```

## Architecture Decisions

Recorded as ADRs in `docs/adr/`
