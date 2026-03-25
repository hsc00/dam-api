---
name: test-generation
description: "Generates PHPUnit 10 test classes following project test conventions. Use when asked to write tests, generate unit tests, add PHPUnit coverage, test a service/repository/value object, or create test cases for a new class. All tests follow AAA pattern, use #[Test] attribute, and are named after the class under test."
argument-hint: "Name of the class to test and which behaviors to cover (e.g. 'PresignService happy path and invalid input')"
---

# Test Generation Skill

## When to Use

- After implementing a new class (Entity, VO, Application Service, Repository, Resolver)
- When coverage gaps are found in a QA review
- When a bug is fixed and a regression test is needed
- When asked to test a specific scenario or edge case

## Procedure

### 1. Read the Class Under Test

Read the source file for the class being tested. Understand:
- Constructor dependencies (for mocking)
- Public methods and their signatures
- Invariants enforced in the constructor or methods
- Error conditions and exceptions thrown

### 2. Decide Which Test Layer

| Class Type | Test Directory | Test Strategy |
|---|---|---|
| Value Object | `tests/Unit/Domain/` | No mocks — pure construction tests |
| Domain Entity | `tests/Unit/Domain/` | No mocks — test state transitions |
| Application Service | `tests/Unit/Application/` | Mock repository + infrastructure interfaces |
| Repository (MySQL) | `tests/Integration/Infrastructure/` | Requires real database (use test DB) |
| GraphQL Resolver | `tests/Unit/GraphQL/` | Mock Application service |
| Middleware | `tests/Unit/Http/` | Mock PSR request/response |

### 3. Read the Test Template

Read [test-template.stub.php](./assets/test-template.stub.php) and adapt it for the class.

**Template note:** The test template uses `{{NamespaceSuffix}}` to safely render the `namespace` line. When generating a test file set:
- `{{NamespaceSuffix}}` = `\\{SubNamespace}` (for example `\\Domain\\Asset`) when you need a sub-namespace
- or set `{{NamespaceSuffix}}` = `` (empty string) to place the test directly under `Tests\Unit`

### 4. Identify Test Cases

Plan test cases following this structure:

**For each public method / constructor:**
- Happy path (valid input → expected output)
- All invalid inputs that should throw exceptions
- Boundary conditions
- State transitions (for entities)

**Naming convention:** `it_<verb>_<condition>` in snake_case

Examples:
- `it_creates_an_asset_with_pending_status`
- `it_throws_when_upload_id_is_empty`
- `it_returns_null_when_asset_not_found`

### 5. Apply Test Conventions

- Use `#[Test]` attribute on every test method (NOT `test` prefix)
- Follow AAA: Arrange → Act → Assert with one blank line between each section
- Use `#[DataProvider]` for parametric tests — define a static method returning arrays
- Mock only interfaces and abstract types — never mock concrete classes
- `createMock()` for simple stubs; `getMockBuilder()` for method chaining
- One assertion concept per test method (may require multiple `assert*` calls)

### 6. Verify the Test Runs

After generating, run:
```bash
vendor/bin/phpunit --filter {TestClassName} --configuration phpunit.xml
```

Check for:
- No fatal errors or undefined class references
- At least 80% coverage of the class under test
- No logic leaking into test setup methods (keep `setUp()` minimal)
