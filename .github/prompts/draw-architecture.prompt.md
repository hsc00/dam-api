---
agent: agent
description: "Generate one or more draw.io architecture diagrams for the DAM API project. Delegates to the architect agent, which loads the drawio-diagrams skill and produces .drawio XML files saved to docs/architecture/."
---

@scrum-master

Generate architecture diagrams for the DAM API project.

Diagram request:

> ${input:diagram_description}
> (e.g. "C4 context and container diagrams for the full system", "sequence diagram for the presign upload flow", "clean architecture layers diagram")

Delegate to **@architect** to:

1. Determine which diagram type(s) to generate (C4 Context, C4 Container, Clean Architecture layers, Sequence)
2. Load the `drawio-diagrams` skill
3. Read the appropriate template(s) from `.github/skills/drawio-diagrams/assets/`
4. Customize the XML for the requested scope — use actual layer names, component names, and relationships from the codebase
5. Save each generated diagram to `docs/architecture/{diagram-type}-{scope}.drawio`
6. Confirm the output file path(s) so they can be opened in VS Code with the draw.io extension

The generated files should open correctly in VS Code when the `hediet.vscode-drawio` extension is installed.
