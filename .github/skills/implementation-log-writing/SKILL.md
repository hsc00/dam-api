---
name: implementation-log-writing
description: "Writes implementation logs for accepted work. Use when documenting how a feature, bug fix, refactor, or workflow change was implemented, what files changed, what checks were run, and what follow-up work remains. Implementation logs are saved in docs/logs/ and linked from mkdocs.yml."
argument-hint: "Describe the accepted work to log (e.g. 'presigned upload mutation implementation', 'duplicate upload ID handling fix')"
---

# Implementation Log Writing Skill

## When to Use

- A feature or fix has been accepted and needs a persistent engineering record
- The team needs a summary of how the change was implemented, not just what was decided
- Future contributors would benefit from a concise change narrative, touched files, checks run, and follow-up notes

## Log Format

Every implementation log follows this structure:

```markdown
# Implementation Log: {Title}

**Feature:** {feature or task name}

## Summary

...

## Implementation Details

- ...

## Files Changed

- `path/to/file` — reason for change

## Validation

- `command run` — result

## Delivery Chunks

- {chunk name} — {what was delivered in this slice}

## Follow-up

- ...
```

## Procedure

### 1. Determine the Log Filename

Save to `docs/logs/{XX}-{kebab-case-title}.md`

Examples:

- `docs/logs/01-presigned-upload-mutation.md`
- `docs/logs/02-duplicate-upload-id-handling.md`

### 2. Read the Log Template

Read [log-template.md](./assets/log-template.md).

### 3. Gather Inputs

Use the accepted implementation and review outputs to capture:

- what was built or changed
- why the approach was chosen
- which files or components were touched
- what validation was actually run
- what remains open, deferred, or risky

### 4. Write the Log

Keep it factual and implementation-focused:

- `Summary` explains the delivered outcome in 2-4 sentences
- `Implementation Details` explains the important mechanics and tradeoffs
- `Files Changed` lists only the meaningful touched files with a one-line reason each
- `Validation` includes only commands that were actually run and their outcome
- `Delivery Chunks` lists the implementation slices or suggested commit boundaries when the work was delivered incrementally
- `Follow-up` captures known gaps, deferred work, or future cleanup

### 5. Update mkdocs.yml Navigation

After saving, add the log to `mkdocs.yml` under an `Implementation Logs` nav section. If the section does not exist yet, create it.

```yaml
nav:
  - Implementation Logs:
      - logs/{XX}-{kebab-title}.md
```

### 6. Keep It Lean

- Do not duplicate the full ADR format
- Do not restate unchanged requirements or the full sprint ceremony
- Prefer concrete file names, commands, and outcomes over narrative prose
