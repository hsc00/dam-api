---
name: feature-delivery-workflow
description: "Orchestrates feature delivery for this DAM PHP PoC using a compact or full multi-agent workflow. Use when planning, implementing, reviewing, or accepting a feature; when deciding whether to use the compact path; or when splitting delivery into small reviewable chunks."
argument-hint: "Describe the feature, review target, or delivery task to orchestrate (e.g. 'presigned upload mutation', 'review duplicate upload ID handling')"
---

# Feature Delivery Workflow

## When to Use

- New feature work that spans more than one specialist
- Sprint or task planning for a meaningful backend/API change
- Cross-agent review orchestration
- Feature acceptance and implementation logging
- Deciding whether a task should use the compact path or full lifecycle

## Compact Path

Use the compact path when the task is small, low-risk, and does not materially change product scope, architecture, schema, or infrastructure.

Eligible examples:

- single-file bug fixes
- targeted test additions
- copy or documentation corrections
- narrow refactors inside an existing design
- agent, prompt, skill, or instruction-file updates

Compact path:

1. Write a one-line task summary
2. Delegate only to the specialist needed for implementation or review
3. Run only the review roles justified by the risk profile
4. Return a delta summary in 5 bullets or fewer

## Full 7-Phase Workflow

### Phase 1 — Specify

- Delegate to Product Owner
- Produce user stories, acceptance criteria, priority, and out-of-scope boundaries
- Do not proceed until the spec is clear enough to design

### Phase 2 — Design

- Delegate to Architect
- Produce technical approach, schema, data model, risks, and implementation slices
- Ask Product Owner to validate the design against the spec

### Phase 3 — Task Breakdown

- Ask Architect for implementation tasks in dependency order and grouped into small reviewable chunks
- Ask QA Engineer for test tasks
- Ask DevOps Engineer for infrastructure/CI tasks when relevant
- Ask Tech Writer for documentation tasks when relevant
- Consolidate into a task list with assignees, dependencies, and chunk boundaries

### Phase 4 — Implement

- Assign one chunk at a time to the appropriate specialist
- Prefer commit-sized slices and avoid broad multi-concern deliveries
- Sequence dependent chunks instead of letting one specialist ship the whole feature in one pass

### Phase 5 — Review

- Route to Architect for architecture compliance
- Route to QA Engineer for correctness and test coverage
- Route to Security Reviewer for security review when the risk profile warrants it
- Consolidate findings and required changes before reassigning work

### Phase 6 — Accept

- Delegate to Product Owner
- Validate final deliverables against the original acceptance criteria
- Do not consider the feature complete until accepted

### Phase 7 — Record

- Delegate to Tech Writer
- Create an implementation log in `docs/logs/`
- Include what changed, how it was implemented, validation run, and follow-up work

## Chunking Rules

- Prefer the smallest vertical slice that produces meaningful progress
- Split work by concern or dependency boundary: schema, domain, application service, infrastructure, tests, docs
- Treat each chunk as commit-sized even when no git commit is being created
- If a chunk must touch many files, require justification for why it cannot be split further

## Review Escalation Rules

- `APPROVE`: proceed to the next step
- `REQUEST CHANGES`: collect all required changes, reassign the relevant chunk, and re-run review
- `DECLINE`: escalate to the user when the issue cannot be resolved without contradicting the design, scope, or constraints

## Feedback Persistence Rules

- When a reusable rule is learned, update the relevant skill or scoped instruction first
- Update an agent file only when the role workflow, delegation behavior, or review authority itself must change
- Reject re-submissions that repeat a previously rejected approach without addressing the root cause

## Sprint Summary Format

```text
## Sprint Complete
Feature: {feature name}
Deliverables:
- {file/component}: {what was built}

Tests: {checks or cases added}
Docs: {docs updated/created}
ADRs: {ADRs written if any}
Implementation Logs: {logs written if any}
```
