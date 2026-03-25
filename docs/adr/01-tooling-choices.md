# Tooling Choices

## Context

This project is a challenge that mirrors a DAM (Digital Asset Management) stack using PHP, GraphQL and MySQL with no framework. We need a consistent set of tools for quality, safety and CI.

## Decisions

### webonyx/graphql-php

The de facto PHP GraphQL server library. Lets us build a schema without pulling in a framework. Matches the kind of stack we want to match.

### monolog/monolog

Structured logging library for PHP. Used to emit JSON log lines to stdout/stderr, making logs consumable by any log aggregator (Loki, CloudWatch, etc.) without coupling to a specific platform.

### vlucas/phpdotenv

Standard way to load `.env` files into PHP's `$_ENV`/`getenv()`. Keeps credentials out of source code and makes the app configurable across environments with no extra infrastructure.

### phpstan/phpstan

Static analyser for PHP. Fast and integrates well with CI.

### phpunit/phpunit

The PHP testing standard. Integrates with coverage reporting, mutation testing and CI natively. No learning overhead for reviewers.

### friendsofphp/php-cs-fixer

Automatically enforces a consistent code style (PSR-12 by default). Running in `--dry-run` mode in CI blocks style regressions without requiring opinion from reviewers.

### vimeo/psalm

Adds taint analysis on top of static analysis, catching SQL injection and XSS paths that phpstan alone does not cover. Used as a secondary security-focused analysis pass.

### squizlabs/php_codesniffer

Complements php-cs-fixer by enforcing project-specific standards (doc blocks, naming conventions) that a formatter alone cannot check.

### infection/infection

Mutation testing tool that measures test quality, not just coverage. Introduces small code mutations and checks that at least one test fails, ensuring tests actually guard against regressions.

### GitHub CodeQL

Free SAST built into GitHub Actions. Scans for OWASP Top 10 patterns (SQL injection, XSS, SSRF, etc.) in PHP without requiring a separate external service.

### composer audit

Built-in Composer command that checks installed packages against the PHP Security Advisories database.

## Docker / docker-compose

Provide a reproducible local development stack using `docker-compose` that includes MySQL and Redis. Local developers and reviewers can boot the same services the CI uses, lowering configuration friction and avoiding environment-specific bugs.

This decision directly supports the project's **TTFHW** _(Time To First Hello World)_ principle: a developer must be able to go from a fresh clone to a running API with exactly two commands:

```bash
cp .env.example .env
docker compose up
```

## Consequences

### Local development and TTFHW

Local development is supported by `docker-compose.yaml`, which brings up `app`, `nginx`, `db` (MySQL),
and `redis`. This enables the repository's CI and local checks to run without external services.

- Mutation testing (`infection`) may be slow on large suites — run on schedule, not every push.
