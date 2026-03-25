---
name: php-ddd-scaffold
description: "Scaffolds PHP Domain-Driven Design artifacts: Entity, Value Object, Repository Interface, Domain Event, and Application Service stubs. Use when asked to create a new aggregate, add a domain entity, define a value object, scaffold a repository, or create an application service. All stubs follow project conventions (declare(strict_types=1), readonly VOs, DDD naming)."
argument-hint: "Name of the aggregate root (e.g. 'Asset') and which artifacts to scaffold (entity, VO, repository, event, service)"
---

# PHP DDD Scaffold Skill

## When to Use

- Creating a new aggregate root with its supporting types
- Adding a new Value Object to the Domain layer
- Defining a new Repository interface in Domain + implementation in Infrastructure
- Scaffolding an Application Service for a new use case
- Adding a Domain Event to model state transitions

## Procedure

### 1. Determine the Aggregate Name

Identify the Pascal-case aggregate root name (e.g. `Asset`, `Account`). All artifact names derive from it.

### 2. Determine Which Artifacts to Generate

| Artifact                | Template                                                                | Namespace Pattern                                       |
| ----------------------- | ----------------------------------------------------------------------- | ------------------------------------------------------- |
| Entity (aggregate root) | [entity.php.stub](./assets/entity.php.stub)                             | `Domain\{Aggregate}\{AggregateRoot}`                    |
| Value Object            | [value-object.php.stub](./assets/value-object.php.stub)                 | `Domain\{Aggregate}\{VOName}`                           |
| Repository Interface    | [repository-interface.php.stub](./assets/repository-interface.php.stub) | `Domain\{Aggregate}\{AggregateRoot}RepositoryInterface` |
| Domain Event            | [domain-event.php.stub](./assets/domain-event.php.stub)                 | `Domain\{Aggregate}\{AggregateRoot}StatusChangedEvent`  |
| Application Service     | [app-service.php.stub](./assets/app-service.php.stub)                   | `Application\{Aggregate}\{UseCase}Service`              |

### 3. Read the Relevant Stub(s)

Read the template file(s) for the artifacts needed.

### 4. Replace Placeholders

| Placeholder              | Replace With                                                   |
| ------------------------ | -------------------------------------------------------------- |
| `{{AggregateRoot}}`      | Pascal-case aggregate name (e.g. `Asset`)                      |
| `{{AggregateRootLower}}` | camelCase aggregate name (e.g. `asset`)                        |
| `{{Namespace}}`          | Aggregate sub-path appended to the layer base (e.g. `Asset`) — entity/VO/repo/event resolve to `Domain\Asset`, service to `Application\Asset` |
| `{{VOName}}`             | Pascal-case Value Object name (e.g. `UploadId`)                |
| `{{EventName}}`          | Pascal-case event name (e.g. `AssetStatusChangedEvent`)        |
| `{{ServiceName}}`        | Pascal-case service name (e.g. `PresignService`)               |
| `{{CommandName}}`        | Pascal-case command name (e.g. `PresignAssetCommand`)          |
| `{{StatusEnum}}`         | AssetStatus enum or string value (e.g. `AssetStatus::PENDING`) |
| `{{ReturnType}}`         | Return type for repository finder methods                      |

### 5. Save Files to Correct Layer Directories

| Layer          | Directory                         |
| -------------- | --------------------------------- |
| Domain         | `src/Domain/{Aggregate}/`         |
| Application    | `src/Application/{Aggregate}/`    |
| Infrastructure | `src/Infrastructure/Persistence/` |

### 6. Register in Composition Root

After scaffolding, update `src/Infrastructure/DependencyInjection/` (or equivalent bootstrap file) to bind the new repository interface to its implementation.

## PHP Conventions Reminder

- `declare(strict_types=1)` always on line 1
- `readonly class` for all Value Objects
- `readonly` properties on Entities where applicable
- Repository interfaces in Domain; implementations in Infrastructure
- No `new` in Application Services for Domain objects (use factories/constructors)
- Domain Events are immutable `readonly class` with public properties
