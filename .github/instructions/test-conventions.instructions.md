---
description: "PHPUnit testing conventions for this project. Enforced on all test files. Covers structure, naming, mocking, and data providers."
applyTo: "tests/**/*.php"
---

## File and Class Structure

- Mirror the `src/` path: `src/Domain/Asset/Asset.php` → `tests/Unit/Domain/Asset/AssetTest.php`
- One test class per source class
- Test class name: `{SourceClass}Test`
- Extend `PHPUnit\Framework\TestCase`

## Test Method Conventions

Use the `#[Test]` attribute — NOT the `test` prefix:

```php
#[Test]
public function itReturnsAssetWhenFound(): void
{
    // Arrange
    $repository = $this->createMock(AssetRepositoryInterface::class);
    $repository->method('findById')->willReturn($asset);

    // Act
    $result = $this->service->getAsset(new GetAssetQuery('upload-123'));

    // Assert
    self::assertSame('upload-123', $result->uploadId->value);
}
```

**Naming patterns:**

- `itReturns{X}When{Condition}()`
- `itThrows{Exception}When{Condition}()`
- `itEmits{Event}When{Condition}()`
- `itDoesNot{X}When{Condition}()`

## Arrange / Act / Assert

Always separate sections with a blank line and an inline comment:

```php
#[Test]
public function itThrowsWhenUploadIdIsEmpty(): void
{
    // Arrange — nothing needed for Value Object construction test

    // Act & Assert
    $this->expectException(\InvalidArgumentException::class);
    new UploadId('');
}
```

## Data Providers

Use `#[DataProvider]` for boundary and equivalence class testing:

```php
#[Test]
#[DataProvider('invalidUploadIdProvider')]
public function itRejectsInvalidUploadId(string $value, string $expectedMessage): void
{
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage($expectedMessage);
    new UploadId($value);
}

public static function invalidUploadIdProvider(): array
{
    return [
        'empty string'      => ['', 'UploadId cannot be empty'],
        'whitespace only'   => ['   ', 'UploadId cannot be empty'],
        'too long'          => [str_repeat('a', 256), 'UploadId too long'],
    ];
}
```

## Mocking Rules

- **DO mock**: Repository interfaces, Storage adapter interfaces, external service interfaces
- **DO NOT mock**: Value Objects, Entities, Commands — instantiate them directly
- **DO NOT mock**: the class under test

```php
// RIGHT — mock the dependency interface
$repo = $this->createMock(AssetRepositoryInterface::class);

// WRONG — instantiate the real VO, not a mock
$uploadId = $this->createMock(UploadId::class); // ← never do this
```

## Test Coverage Expectations

Every public method needs MINIMUM:

1. One happy-path test
2. One error/edge-case test (exception thrown, null returned, empty input)

For status transitions, test every valid and every invalid transition.

## Mutation Testing Mindset

Write tests that would **fail** if a developer:

- Negated a boolean condition
- Changed `>` to `>=`
- Removed an assignment
- Changed a return value to `null`

Each assertion must be specific enough to catch these mutations.

## Prohibited in Tests

- `@test` annotation — use `#[Test]` attribute only
- Assertions that always pass: `self::assertTrue(true)`, empty test bodies
- Sleeping: `sleep()` in tests — use time injection or freeze time
- Accessing private properties via reflection unless testing serialization
