---
name: adr-writing
description: "Writes Architecture Decision Records (ADRs) following the Nygard format. Use when a significant technical decision needs to be documented, when the architect selects a library, database technology, security approach, or architectural pattern. ADRs are saved in docs/adr/ and linked from mkdocs.yml."
argument-hint: "Describe the architectural decision to document (e.g. 'choice of webonyx/graphql-php', 'Redis cache TTL jitter strategy')"
---

# ADR Writing Skill

## When to Use

- A significant non-obvious technology choice has been made
- An architectural pattern has been adopted project-wide
- A security control or constraint has been established
- A decision was _rejected_ and future teams need to know why

## Nygard ADR Format

Every ADR follows this structure:

```
# ADR-{NN}: {Title}

**Status**: Proposed | Accepted | Deprecated | Superseded by ADR-{NN}

## Context
...

## Decision
...

## Consequences
...
```

## Procedure

### 1. Determine the ADR Number

Check `docs/adr/` for existing ADR files. The next number = highest existing `NN` + 1. Pad to two digits (e.g. `03`, `10`).

### 2. Read the ADR Template

Read [adr-template.md](./assets/adr-template.md).

### 3. Fill Each Section

**Context:**

- Describe the technical problem or constraint
- Explain what forces are at play (performance, security, maintainability, team skill)
- Include constraints (e.g. "no framework dependencies", "PHP 8.5 only")
- Be factual — no advocacy yet

**Decision:**

- State the chosen approach clearly: "We will use X because..."
- List what was considered but not chosen (with one-line rejection rationale each)
- Quantify where possible (TTL values, character limits, error codes)

**Consequences:**

- Positive: what becomes easier or better
- Negative: what is now harder or the known tradeoffs
- Neutral: what changes without being better or worse

### 4. Save the ADR

Save to `docs/adr/{NN}-{kebab-case-title}.md`

Examples:

- `docs/adr/03-presigned-url-ttl-strategy.md`
- `docs/adr/04-redis-cache-decorator-pattern.md`

### 5. Update mkdocs.yml Navigation

After saving, add the ADR to `mkdocs.yml` under the `Architecture Decisions` nav section:

```yaml
nav:
  - Architecture Decisions:
      - docs/adr/01-tooling-choices.md
      - docs/adr/02-security-choices.md
      - docs/adr/{NN}-{kebab-title}.md # ← add here
```

### 6. Cross-reference from Source Code

If the ADR documents a decision visible in code, add a brief inline comment referencing the ADR:

```php
// See ADR-03 for TTL jitter rationale
$ttl = $baseTtl + random_int(0, 30);
```
