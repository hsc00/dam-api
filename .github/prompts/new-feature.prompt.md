---
agent: agent
description: "Launch the full SCRUM lifecycle for a new DAM API feature: define user story → design architecture → break into tasks → implement → review → accept → record implementation log."
---

@scrum-master

Start a new feature in the DAM API project using the full SCRUM ceremony.

The `${input:feature_description}` placeholder is populated by the slash-command input. If you are editing this prompt manually, replace it with the actual feature request.

The feature request is:

> ${input:feature_description}

Load the `feature-delivery-workflow` skill before orchestrating the lifecycle.

Run the complete 7-phase lifecycle:

1. **Specify** — work with @product-owner to write a user story with acceptance criteria
2. **Design** — delegate to @architect for API schema, DB schema, and architecture diagrams
3. **Task Breakdown** — decompose into implementation tasks and track them
4. **Implement** — delegate to @backend-dev for PHP code following DDD + Clean Architecture
5. **Review** — run cross-agent review: @qa-engineer, @architect, @security-reviewer must each APPROVE
6. **Accept** — validate against acceptance criteria with @product-owner
7. **Record** — write an implementation log in `docs/logs/` through @tech-writer, then summarize the sprint

Do not proceed to the next phase until all required agents have given an APPROVE decision for the current phase.
Create GitHub Issues for task tracking if the GitHub MCP server is available; otherwise write tasks to `.github/tasks/`.
