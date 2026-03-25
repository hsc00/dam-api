---
description: "GraphQL resolver and schema conventions for this project. Enforced on all files under src/GraphQL/."
applyTo: "src/GraphQL/**/*.php"
---

## Resolvers: Thin Adapter Rule

A GraphQL resolver has **exactly one job**: call an Application Service and return a plain array.

```php
// RIGHT — thin resolver
final class PresignAssetResolver
{
    public function __construct(
        private readonly PresignService $presignService,
    ) {}

    public function resolve(mixed $root, array $args): array
    {
        return $this->presignService->presign(
            new PresignAssetCommand(
                uploadId: $args['input']['uploadId'],
                accountId: $args['input']['accountId'],
                mimeType:  $args['input']['mimeType'],
            )
        );
    }
}

// WRONG — business logic in resolver
final class PresignAssetResolver
{
    public function resolve(mixed $root, array $args): array
    {
        $asset = $this->repository->findById($args['input']['uploadId']); // ← direct repo access
        if ($asset !== null) { // ← business logic
            throw new \RuntimeException('Asset already exists');
        }
        // ... more logic
    }
}
```

## Prohibited in Resolvers

- Direct access to repositories or database connections
- Domain entity construction (let Application Services handle this)
- Business rule evaluation (`if ($status === 'pending')` etc.)
- Direct access to `$_GET`, `$_POST`, `$_SERVER`
- Raw SQL of any kind

## Type Definitions

Use `ObjectType` and `InputObjectType` built via `SchemaFactory` — never define types inline in resolvers:

```php
// Types live in src/GraphQL/Type/ — one file per type
final class AssetType extends ObjectType { ... }
final class PresignAssetInput extends InputObjectType { ... }
```

## Error Handling

Map domain errors to GraphQL errors with structured error codes:

```php
// GraphQL errors carry an 'extensions' key with 'code' and 'category'
[
    'message' => 'Asset not found',
    'extensions' => [
        'code' => 'ASSET_NOT_FOUND',
        'category' => 'not_found',
    ],
]
```

Never expose stack traces, raw exception messages, or internal identifiers in GraphQL error responses.

## DataLoader Pattern (N+1 Prevention)

When a resolver may be called once per item in a list, use a DataLoader-style batch map:

```php
// Buffer IDs in the first pass, load all at once in the second pass
public function resolveBatch(array $ids): array
{
    return $this->assetService->findByIds($ids); // one query for N items
}
```

## Schema Structure

```
src/GraphQL/
├── SchemaFactory.php          ← builds and returns the full Schema object
├── Type/
│   ├── AssetType.php          ← ObjectType definitions
│   └── PresignAssetInput.php  ← InputObjectType definitions
└── Resolver/
    ├── PresignAssetResolver.php
    └── AssetStatusResolver.php
```
