# Issue creation rules

These rules are used by automation and contributors when turning `.github/tasks/*.md` story files into GitHub Issues.

1. Epic grouping
   - Epics are first-class and have their own numeric identifier independent from `US-` story numbers.
   - Epic titles use the format `EPIC-<NN>: <Short Title>` (for example `EPIC-01: Bootstrap — Schema, Domain & Infrastructure`).
   - Epic labels use the pattern `epic/<NN>-<slug>` (for example `epic/01-bootstrap`).
   - Epic body format and requirements:
     - Every epic MUST include an `Objective:` paragraph near the top describing the measurable goal of the epic in one or two sentences.
     - Every epic MUST include an `Included tasks:` section that lists the child story references as Markdown links. The conversion tool should:
       - Prefer linking to the created issue URL (for example: `[US-01: Agree API Schema](https://github.com/<owner>/<repo>/issues/12)`).
       - If the issue does not yet exist, link to the source story file under `.github/tasks/` (for example: `[US-01: Agree API Schema](.github/tasks/US-000-bootstrap-schema-domain-scaffold.md)`).
     - The `Included tasks:` list should contain each story on its own line, prefixed with the story number and title (for example: `- [US-01: Agree API Schema](...) — define a single human-readable schema for messages and types`).
     - Epic status propagation rule:
       - An epic MUST NOT remain labelled `backlog` if any of its child stories is labelled `in-progress`.
       - The conversion tool or maintainer MUST update the epic's workflow label to reflect the most advanced state among its children (for example, if any child is `in-progress`, set the epic to `in-progress`).
       - When the last child moves to `done`, the epic may be set to `done` or closed according to team workflow.

   - Epic creation criteria:
     - Use these characteristics to decide when to create a new epic (and an accompanying epic folder):
       - Size: The work is too large for a single sprint and is expected to take multiple sprints (typically 2–6 months).
       - Hierarchy: The initiative naturally decomposes into multiple related user stories or features.
       - Strategic value: The work aligns with a strategic goal or large product initiative rather than a small tactical change.
       - Flexibility: The scope may change as feedback arrives; the container should allow adding/removing stories.
       - Measurable objective: There is a clear `Objective:` or goal that defines success for the collection of stories.
     - Usage examples that should create an epic: `Implement Checkout System`, `Improve Application Load Time`, `Revamp User Onboarding`.
     - Components to include when creating an epic:
       - `Title/Goal` (Objective): a concise measurable statement.
       - `User Stories`: the smaller tasks (placed under the epic folder or linked in `Included tasks`).
       - `MVP Definition`: what minimum set of stories constitutes a releasable outcome for the epic.
       - `Progress Metrics`: optional progress indicators (percentage, story points completed, kanban swimlane).
     - When to create an epic folder:
       - If the story set meets the Size + Hierarchy + Strategic criteria above, create `.github/tasks/epics/epic-<NN>-<slug>/` and move related story files there.
       - If unsure, propose an epic in a comment and wait for Product Owner confirmation before creating the folder.
     - If a candidate epic doesn't contain enough tickets to form a meaningful epic, do NOT create a standalone epic. Instead, merge that story into a related existing epic or propose grouping it with similar stories. Single-ticket epics are discouraged to avoid unnecessary fragmentation.

2. Child issues
   - Create one GitHub Issue per task file. The issue title is the sequential story number plus the file's H1 (e.g. `US-01: Agree API Schema`).
   - Child issues must include the label `epic/<NN>-<slug>` that identifies their epic (e.g. `epic/01-bootstrap`) and `backlog` by default.
   - The body of the child issue MUST follow this exact format:
     - It must be the exact contents of the corresponding story file under `.github/tasks/` (preserve headings, sections, acceptance criteria, DoD, etc.).
     - Immediately after the story content append a single line containing the hyperlinked epic text, for example:
       - `[EPIC-01: Bootstrap — Schema, Domain & Infrastructure](https://github.com/<owner>/<repo>/issues/11)`
     - Do NOT add any other text, annotations, or metadata to the issue body. The issue body must match the story file content plus the single hyperlinked epic line.

3. Labels and status
   - All new issues are labeled `backlog` by default.
   - Only the currently active issue is labeled `in-progress`.
     Stories MUST use exactly one primary classification label chosen from this set:
     - `bug` — Indicates an unexpected problem or unintended behavior
     - `enhancement` — Indicates new feature or improvement

4. Naming and numbering
   - Story issue titles MUST use sequential `US-XX` numbering (US-01, US-02, ...).
   - Epics MUST use `EPIC-<NN>` numbering (EPIC-01, EPIC-02, ...).
   - The conversion tool computes the next available `US-XX` by scanning existing `US-` issue titles and incrementing the highest number found.
   - The epic numbering (EPIC-<NN>) is computed independently and must not collide with story numbering.

5. Automation note
   - The MCP-backed issue creation tool must:
     - Create an `EPIC-<NN>` issue and its `epic/<NN>-<slug>` label before creating child stories.
   - Add the epic label to every child and set the issue body to the exact story file content with a single hyperlinked epic line as described above. Do not add any additional text to the issue body.

6. Where to store stories
   - Story files live in `.github/tasks/` and are the canonical source of acceptance criteria.

7. Updating rules
   - Keep this file up to date when the process or label patterns change.

   - Additional non-classification labels that are allowed on stories:
     - `epic/<NN>-<slug>` — epic grouping labels (required for grouping)
     - `backlog`, `in-progress`, `done` — workflow/status labels
     - `spike` — investigative or research flag (may be applied in addition to the primary label)

   - Enforcement and automation guidance:
     - The conversion tool MUST assign exactly one primary classification label (`bug` or `enhancement`) to every story issue.
     - The tool may also add `spike` and/or a status label (`backlog`, `in-progress`, `done`) where appropriate, and must add the relevant `epic/*` grouping label.
     - Do NOT create or use other custom classification labels (for example: `documentation`, `maintenance`, `question`, `feature`, `Chore`, etc.). All classification must be `bug` or `enhancement` only.

   - Mapping instruction for existing/ambiguous stories:
     - If a story describes a defect, label it `bug`.
     - Otherwise label it `enhancement`.
     - If the work is explicitly investigative, also add `spike` (but still keep exactly one primary label).

   - Best practice:
     - Keep `bug`/`enhancement` orthogonal to `epic/*` and workflow labels. Use `epic/*` for grouping and `backlog`/`in-progress`/`done` for status.
