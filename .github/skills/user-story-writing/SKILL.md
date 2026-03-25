---
name: user-story-writing
description: "Writes user stories in Given/When/Then format with acceptance criteria and DoD for any feature request. Use when a product owner needs to capture requirements, a feature needs acceptance criteria, stories need splitting, or a business requirement needs a structured spec. Output is a user story markdown document."
argument-hint: "Describe the feature or capability to write a story for (e.g. 'upload an asset via presigned URL')"
---

# User Story Writing Skill

## When to Use

- Translating a product requirement into a structured user story
- Defining acceptance criteria before development starts
- Splitting large epics into implementable stories
- Gate: the architect and backend-dev need a story before designing/implementing

## User Story Format

```
As a {role},
I want to {capability},
So that {business value}.
```

## Plain Language Rules

Stories must be readable by anyone — a product owner, a designer, a stakeholder, or someone with no technical background. Before writing or reviewing a story, check every sentence against these rules:

**Forbidden in the User Story, Context, and Acceptance Criteria sections:**
- Technology names: GraphQL, Redis, MySQL, PDO, PHP, JSON, HTTP, SQL, API, BRPOP, TTL
- Implementation details: class names, method names, field names, error codes, status codes
- System internals: database columns, cache keys, queue names, mutations, queries
- Jargon: idempotent, presigned URL, dead-letter, SCREAMING_SNAKE_CASE, PHPStan

**Use instead:**
- "the user submits a form" not "a mutation is called"
- "the file appears in their library" not "asset.status is READY"
- "they see an error message" not "userErrors contains code=ASSET_NOT_FOUND"
- "the system saves the file" not "a record is persisted to MySQL"
- "the upload begins" not "status transitions to PROCESSING"

**Scenarios describe what the USER experiences, not what the system does internally.**

## Procedure

### 1. Identify the Role

Use plain human roles, not technical titles:

- **user** — someone uploading or searching for files
- **content manager** — someone organising digital assets
- **platform operator** — someone keeping the service running
- Use "developer" only when the story is genuinely about a developer's workflow

### 2. Capture the Capability and Business Value

- Capability = what the user can DO (action verb, no tech jargon)
- Value = WHY it matters to the business or user (outcome, not feature, no tech jargon)

### 3. Read the Story Template

Read [story-template.md](./assets/story-template.md).

### 4. Write Acceptance Criteria (Given/When/Then)

Each scenario tests one specific behaviour from the user’s point of view:

```gherkin
Given {what the user has set up or what is already true}
When  {what the user does — in plain language}
Then  {what the user sees or experiences}
```

Write at least:
- 1 happy path scenario
- 1 error / something-goes-wrong scenario
- 1 boundary or edge case (if applicable)

After writing, re-read each scenario aloud. If it sounds like a code comment, rewrite it in plain English.

### 5. Define the Definition of Done

Always include:

- [ ] Implementation complete
- [ ] PHPUnit unit tests pass
- [ ] PHPUnit coverage ≥ 80% for new code
- [ ] PHPStan level 8 passes with no new errors
- [ ] CS Fixer reports no violations
- [ ] Architect has approved API/DB design
- [ ] QA engineer has approved test coverage
- [ ] Security reviewer has approved (no OWASP Top 10 findings)
- [ ] Tech writer has updated API docs / MkDocs

### 6. Save the Story

Save to `.github/tasks/{sprint-name}/{story-number}-{slug}.md`.

Example: `.github/tasks/sprint-1/US-001-presign-asset-upload.md`

If GitHub Issues is available via MCP, also create an issue using the story as the body.
