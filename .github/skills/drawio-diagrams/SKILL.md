---
name: drawio-diagrams
description: "Generates draw.io XML diagram files for C4 Context, C4 Container, Clean Architecture layers, and sequence diagrams. Use when asked to draw architecture, create a diagram, visualize layers, produce a C4 diagram, draw a sequence flow, or document system structure with draw.io. Output files are saved to docs/architecture/."
argument-hint: "Describe what diagram to generate (e.g. 'C4 context for presign flow' or 'clean architecture layers')"
---

# draw.io Diagram Generation

## When to Use

- "draw architecture", "C4 diagram", "sequence diagram", "visualize layers", "draw.io"
- After a new feature is designed (generate diagrams for the technical design)
- When onboarding documentation needs a visual system overview

## Procedure

### 1. Determine Which Diagram(s) to Generate

| Diagram Type              | When to Use                                                                | Template                                                      |
| ------------------------- | -------------------------------------------------------------------------- | ------------------------------------------------------------- |
| C4 Context                | System overview — shows users, your system, external dependencies          | [c4-context.drawio](./assets/c4-context.drawio)               |
| C4 Container              | Layer decomposition — shows Http/GraphQL/Application/Domain/Infrastructure | [c4-container.drawio](./assets/c4-container.drawio)           |
| Clean Architecture Layers | Dependency direction visualization                                         | [clean-arch-layers.drawio](./assets/clean-arch-layers.drawio) |
| Sequence Diagram          | Request/response flow for a specific use case                              | [sequence.drawio](./assets/sequence.drawio)                   |

### 2. Read the Relevant Template

Read the appropriate template file from `./assets/`. All templates contain valid draw.io XML with placeholder elements.

### 3. Customize the XML

Replace placeholder values in the XML with project-specific names and relationships:

- Replace `{{SYSTEM_NAME}}` with the actual system/component name
- Replace `{{ACTOR_NAME}}` with actual user/system names
- Add or remove `<mxCell>` elements to match actual architecture
- Adjust `x`/`y` geometry values to avoid overlapping elements (increment by 200 for horizontal spacing, 150 for vertical)

Refer to the [draw.io XML Reference](./references/drawio-xml-reference.md) for element syntax.

### 4. Save the Output

Save generated diagrams to `docs/architecture/{diagram-type}-{feature-name}.drawio`.

Examples:

- `docs/architecture/c4-context.drawio`
- `docs/architecture/c4-container.drawio`
- `docs/architecture/sequence-presign-upload.drawio`
- `docs/architecture/clean-arch-layers.drawio`

### 5. Update MkDocs Navigation

After saving, add the diagram to `mkdocs.yml` nav if it should appear in the docs site. draw.io files are not directly rendered by MkDocs — link to them or embed screenshots. For the docs site, prefer noting the file location.

## draw.io XML Structure

```xml
<mxfile host="app.diagrams.net">
  <diagram id="{unique-id}" name="{Diagram Title}">
    <mxGraphModel>
      <root>
        <mxCell id="0" />
        <mxCell id="1" parent="0" />
        <!-- All diagram elements are mxCell children of cell id="1" -->
      </root>
    </mxGraphModel>
  </diagram>
</mxfile>
```

## Key Style References

| Element                  | Style                                                               |
| ------------------------ | ------------------------------------------------------------------- |
| Person (C4)              | `ellipse;fillColor=#08427b;fontColor=#ffffff;strokeColor=#073b6f`   |
| Internal System          | `rounded=1;fillColor=#1168bd;fontColor=#ffffff;strokeColor=#0b4884` |
| External System          | `rounded=1;fillColor=#999999;fontColor=#ffffff;strokeColor=#8a8a8a` |
| Database                 | `shape=cylinder3;fillColor=#dae8fc;strokeColor=#6c8ebf`             |
| Domain layer box         | `rounded=1;fillColor=#d5e8d4;strokeColor=#82b366`                   |
| Application layer box    | `rounded=1;fillColor=#dae8fc;strokeColor=#6c8ebf`                   |
| Infrastructure layer box | `rounded=1;fillColor=#ffe6cc;strokeColor=#d6b656`                   |
| Arrow / relationship     | `edgeStyle=orthogonalEdgeStyle;rounded=0`                           |
