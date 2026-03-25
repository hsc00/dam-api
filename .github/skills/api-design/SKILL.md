---
name: api-design
description: "Designs GraphQL schemas for new features following project schema conventions. Use when defining a new mutation, query, or type; designing API input/output types; reviewing GraphQL schema structure; or creating a schema for a new domain aggregate. Outputs SDL (.graphql) files."
argument-hint: "Describe the feature or operation to design a schema for (e.g. 'presign asset upload mutation with status query')"
---

# GraphQL API Design Skill

## When to Use

- Designing a new mutation or query before implementation
- Reviewing an existing schema for type consistency
- Adding a new domain entity's types to the schema
- Defining input validation rules in SDL

## Procedure

### 1. Understand the Use Case

Identify:
- **Actor**: who calls this operation (API client)
- **Intent**: what the operation accomplishes (command vs query)
- **Domain objects**: which entities/VOs are involved
- **Side effects**: what state changes occur

### 2. Classify as Mutation or Query

| Operation Type | Use Mutation | Use Query |
|---|---|---|
| Creates data | ✓ | |
| Modifies state | ✓ | |
| Reads/fetches data | | ✓ |
| Generates a URL | ✓ (side effect: DB record) | |

### 3. Read the Schema Template

Read [schema-template.graphql](./assets/schema-template.graphql) and adapt it to the feature.

### 4. Apply Schema Design Rules

**Naming:**
- Mutations: camelCase verb + noun (`presignAsset`, `updateAssetStatus`)
- Queries: camelCase noun (`asset`, `assets`)
- Input types: `{MutationName}Input` (e.g. `PresignAssetInput`)
- Payload types: `{MutationName}Payload` (e.g. `PresignAssetPayload`)
- Enums: SCREAMING_SNAKE_CASE values (`PENDING`, `UPLOADED`)

**Error handling:**
- All mutations return a payload type (never a raw scalar)
- Payload includes a `userErrors: [UserError!]!` field for business errors
- Use `errors` in GraphQL response for system/unexpected errors only

**Nullability rules:**
- Required fields: `Type!` (non-null)
- Optional fields: `Type` (nullable — explicit intent)
- Lists: `[Type!]!` unless empty list is a meaningful "no items" case
- IDs: always `ID!` (non-null)

**Scalars:**
- Dates/timestamps: `String` (ISO 8601) or define a custom `DateTime` scalar
- Binary/blob data: never expose directly — use presigned URLs
- Sensitive data: never in responses (tokens, credentials)

### 5. Validate Schema Design

Before signing off, verify:
- [ ] Every mutation has an Input type and a Payload type
- [ ] No raw domain objects exposed directly (map to API types)
- [ ] Every field with business meaning has a description comment
- [ ] No circular dependencies in type graph
- [ ] Consistent casing across all type names

### 6. Save Output

Save the schema SDL to `src/GraphQL/Schema/` using the domain name:
- `src/GraphQL/Schema/Asset.graphql`
- `src/GraphQL/Schema/Upload.graphql`

Update the main `schema.graphql` to include or import new files.
