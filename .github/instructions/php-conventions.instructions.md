---
description: "PHP coding conventions and Clean Architecture rules for this project. Enforced on every PHP source file."
applyTo: "**/*.php"
---

## Mandatory Header

Every PHP file must begin with:
```php
<?php

declare(strict_types=1);
```

No exceptions. PHPStan will flag missing `strict_types`.

## PSR-12 Style (enforced by php-cs-fixer)

- 4-space indentation, no tabs
- Opening braces on same line for classes and methods
- One blank line between class members
- `use` statements alphabetically sorted
- No trailing whitespace

## DDD Naming Conventions

| Type | Naming Pattern | Example |
|------|---------------|---------|
| Entity | `{Name}` | `Asset` |
| Value Object | `{Name}` | `UploadId`, `AccountId` |
| Repository Interface | `{Name}RepositoryInterface` | `AssetRepositoryInterface` |
| Application Service | `{Name}Service` | `PresignService`, `AssetService` |
| Command | `{Action}{Name}Command` | `PresignAssetCommand` |
| Domain Event | `{Name}{Verb}Event` | `AssetStatusChangedEvent` |
| Storage Adapter Interface | `{Name}AdapterInterface` | `StorageAdapterInterface` |

## Clean Architecture Layer Rules

No class may import from a layer further out than itself:
- `Domain/` → no imports from Application, Infrastructure, GraphQL, Http
- `Application/` → may import from Domain only
- `Infrastructure/` → may import from Domain and Application
- `GraphQL/` → may import from Application only (never Domain directly)
- `Http/` → may import from GraphQL and Application

## Type Safety

- All method signatures must declare parameter types and return types (including `void` and `never`)
- Use `readonly class` or `readonly` properties for all DTOs and Value Objects
- Use PHP Enums (backed) for status fields — never bare string constants
- Use union types (`int|string`) sparingly — prefer dedicated Value Objects

## Readonly Value Objects

```php
final readonly class UploadId
{
    public function __construct(
        public readonly string $value,
    ) {
        if (empty(trim($this->value))) {
            throw new \InvalidArgumentException('UploadId cannot be empty');
        }
    }
}
```

## Prohibited Patterns

- `eval()` — forbidden entirely
- Raw SQL string concatenation involving variables — use `PDO::prepare()` + named parameters
- `$_GET`, `$_POST`, `$_SERVER` — forbidden outside `src/Http/`
- `error_log()` — use Monolog structured logging
- `var_dump()`, `print_r()` — forbidden in production code paths
- Static state (`static $x = ...`) — avoid; use constructor injection
- `new` inside class bodies outside constructors/factories — prefer injected dependencies

## Banned in GraphQL Resolvers

Resolvers must delegate to Application Services only:
```php
// WRONG — business logic in resolver
public function resolve(mixed $root, array $args): array {
    $asset = $this->repository->findById($args['id']); // ← WRONG
    return $asset->toArray();
}

// RIGHT — delegate to Application Service
public function resolve(mixed $root, array $args): array {
    return $this->assetService->getAsset(new GetAssetQuery($args['id']));
}
```
