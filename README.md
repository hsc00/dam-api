# DAM PHP API — Presign + Status (GraphQL + MySQL)

A challenge showcasing a production-grade, framework-free PHP 8 service for a Digital Asset Management (DAM) presign-upload and asset-status-tracking API. Built with GraphQL, MySQL, and an optional Redis cache layer.

---

## PHP 8 Features Demonstrated

| Feature                                      |
| -------------------------------------------- |
| **Strict types** (`declare(strict_types=1)`) |
| **Enums** (backed & pure)                    |
| **Readonly properties / readonly classes**   |
| **Named arguments**                          |
| **Attributes** (`#[Attribute]`)              |
| **Match expressions**                        |
| **Fibers**                                   |
| **JIT (tracing)**                            |
| **OPcache**                                  |
| **First-class callables**                    |
| **Intersection types**                       |
| **`never` return type**                      |

---

## Architecture & Design Principles

### Clean Architecture (layered, dependency-rule enforced)

```
Domain          ← no external dependencies
Application     ← depends on Domain only
Infrastructure  ← implements Domain interfaces (DB, Cache, Storage)
GraphQL         ← thin adapter; calls Application services
Http            ← middleware chain; feeds into GraphQL handler
```

### Domain-Driven Design (DDD)

- **Entities** — `Asset` with identity and lifecycle
- **Value Objects** — `UploadId`, `AccountId` (immutable, self-validating)
- **Domain Events** — `AssetStatusChangedEvent` dispatched on every transition
- **Repository Interface** — `AssetRepositoryInterface` owned by Domain

### Design Patterns

| Pattern                     |
| --------------------------- |
| **Repository**              |
| **Factory**                 |
| **Command**                 |
| **Strategy**                |
| **Decorator**               |
| **Adapter**                 |
| **Observer**                |
| **Chain of Responsibility** |
| **Service Layer**           |

### SOLID & OOP Principles

- **Single Responsibility** — every class has one reason to change
- **Open/Closed** — new functionality can be added without modifying existing code
- **Liskov Substitution** — implementations can be substituted without breaking correctness
- **Interface Segregation** — interfaces are split to avoid forcing unused dependencies
- **Dependency Inversion** — depend on abstractions, not concretions
- **Composition over inheritance** — prefer composing behavior over subclassing
- **Law of Demeter** — minimize knowledge of internal details between classes

### Resilience & Performance

- **N+1 query prevention** — batch-load assets in GraphQL resolvers using a DataLoader-style map
- **Redis cache** with **jitter** on TTL to avoid thundering-herd cache expiry
- **Graceful degradation** — cache miss falls back to DB transparently; Redis failure is swallowed
- **Error boundaries** — infrastructure exceptions are caught at the Application layer and converted to domain errors
- **GC tuning** — `gc_collect_cycles()` called in long-running worker loops; GC pressure documented

---

## Prerequisites

- PHP 8.5
- Composer
- MySQL 8+
- Redis (optional — service degrades gracefully without it)

---

## Quick Start — TTFHW

**Two commands** from a fresh clone to a running, queryable GraphQL API:

```bash
cp .env.example .env
docker compose up
```

On Windows PowerShell, use:

```powershell
Copy-Item .env.example .env
docker compose up
```

API is available at **`http://localhost:8000/graphql`**.

This is the canonical local development path. `docker compose up` starts PHP-FPM + Nginx + MySQL 8 + Redis 7, runs DB migrations, and exposes the GraphQL endpoint.

Local host bindings used by Docker Compose:

- GraphQL HTTP: `localhost:8000`
- MySQL from host machine: `127.0.0.1:${DB_HOST_PORT}` (default `3307`)
- Redis from host machine: `127.0.0.1:${REDIS_HOST_PORT}` (default `6380`)

> **TTFHW** is a principle of this project. Every change to infrastructure, tooling, or configuration must preserve the two-command setup above.

### Alternative — bare metal (no Docker)

For developers who prefer running PHP directly:

1. Copy `.env.example` to `.env` and fill in credentials.

2. Install dependencies:

```bash
composer install
```

3. Verify PHP → MySQL connectivity:

```bash
php -r 'new PDO("mysql:host=127.0.0.1;port=3307;dbname=dam;charset=utf8mb4","root","root"); echo "PDO ok\n";'
```

Adjust the host, port, username and password to match your `.env` values if you changed them.

4. Start the built-in server:

```bash
php -S localhost:8000 -t public
```

---

## CI / CD

Four GitHub Actions workflows cover quality, security and code review:

| Workflow        | Trigger                | What it does                                                                        |
| --------------- | ---------------------- | ----------------------------------------------------------------------------------- |
| `ci.yml`        | push + PR              | PHP matrix 8.1/8.2/8.3 · PHPStan · PHP-CS-Fixer · PHPUnit · Composer Audit · CodeQL |
| `security.yml`  | PR                     | Semgrep (`p/ci` ruleset + custom rules) · Gitleaks secret scan                      |
| `reviewdog.yml` | PR                     | PHPStan + Semgrep output posted as inline PR comments via reviewdog                 |
| `trivy.yml`     | push to main + nightly | Filesystem CVE scan (HIGH/CRITICAL)                                                 |

---

## Pre-commit Hooks

Git hooks are managed by [CaptainHook](https://github.com/captainhookphp/captainhook), a Composer dev dependency. Hooks install automatically as part of `composer install` — no extra tooling needed.

On every commit, three checks run and block the commit if any fail:

1. **PHP CS Fixer** — coding style (dry-run, no auto-fix)
2. **PHPStan** — static analysis
3. **PHPUnit** — unit test suite

> Use `composer check` to run CS Fixer + PHPStan on demand without committing.

> Branch protection on `main` requires all CI checks to pass + 1 code owner approval.
