# draw.io XML Reference

## Root Structure

Every draw.io file follows this structure:

```xml
<mxfile host="app.diagrams.net" version="21.0.0">
  <diagram id="UNIQUE_ID" name="Page Title">
    <mxGraphModel dx="1422" dy="762" grid="1" gridSize="10" pageWidth="1169" pageHeight="827">
      <root>
        <mxCell id="0" />
        <mxCell id="1" parent="0" />
        <!-- YOUR CELLS HERE, all with parent="1" -->
      </root>
    </mxGraphModel>
  </diagram>
</mxfile>
```

Rules:

- `id="0"` and `id="1"` are always the first two cells — never omit them
- All your cells use `parent="1"` (or another cell id for grouping)
- IDs must be unique within the file — use sequential integers or UUIDs

## Vertex (Shape) Syntax

```xml
<mxCell id="2" value="Label Text" style="{style-string}" vertex="1" parent="1">
  <mxGeometry x="100" y="100" width="200" height="80" as="geometry" />
</mxCell>
```

- `value`: the text label (supports HTML with `html=1` in style)
- `style`: semicolon-separated key=value pairs
- `x`,`y`: top-left corner position in pixels
- `width`,`height`: dimensions in pixels

## Edge (Arrow) Syntax

```xml
<mxCell id="10" value="label on arrow" style="edgeStyle=orthogonalEdgeStyle;" edge="1" source="2" target="5" parent="1">
  <mxGeometry relative="1" as="geometry" />
</mxCell>
```

- `source`/`target`: IDs of the cells being connected
- `value`: optional label shown on the edge midpoint
- Remove `value=""` for unlabelled arrows

## Common Styles

### Shapes

| Use               | Style                                                      |
| ----------------- | ---------------------------------------------------------- |
| Plain rectangle   | `rounded=0;whiteSpace=wrap;html=1;`                        |
| Rounded rectangle | `rounded=1;whiteSpace=wrap;html=1;`                        |
| Ellipse / circle  | `ellipse;whiteSpace=wrap;html=1;`                          |
| Database cylinder | `shape=mxgraph.flowchart.database;whiteSpace=wrap;html=1;` |
| Actor (person)    | `shape=mxgraph.archimate3.actor;`                          |
| Note / comment    | `shape=note;whiteSpace=wrap;html=1;`                       |

### Fill Colors (with matching stroke)

| Semantic             | fillColor | strokeColor | fontColor |
| -------------------- | --------- | ----------- | --------- |
| Person (C4 user)     | `#08427b` | `#073b6f`   | `#ffffff` |
| Internal system      | `#1168bd` | `#0b4884`   | `#ffffff` |
| External system      | `#999999` | `#8a8a8a`   | `#ffffff` |
| Domain layer         | `#d5e8d4` | `#82b366`   | `#000000` |
| Application layer    | `#dae8fc` | `#6c8ebf`   | `#000000` |
| Infrastructure layer | `#ffe6cc` | `#d6b656`   | `#000000` |
| GraphQL layer        | `#e1d5e7` | `#9673a6`   | `#000000` |
| Http layer           | `#fff2cc` | `#d6b656`   | `#000000` |
| Warning / risk       | `#f8cecc` | `#b85450`   | `#000000` |

### Edge Styles

| Use                | Style                                                    |
| ------------------ | -------------------------------------------------------- |
| Straight arrow     | `endArrow=block;endFill=1;`                              |
| Orthogonal routing | `edgeStyle=orthogonalEdgeStyle;`                         |
| Dashed line        | `dashed=1;`                                              |
| No arrowhead       | `endArrow=none;`                                         |
| Bidirectional      | `startArrow=block;startFill=1;endArrow=block;endFill=1;` |

## Multi-line Labels

Use `&#xa;` for newlines in cell values:

```xml
value="Line 1&#xa;Line 2&#xa;[Component Type]"
```

## Swimlane Container

Group elements inside a swimlane:

```xml
<!-- Container -->
<mxCell id="20" value="Container Title" style="swimlane;startSize=30;" vertex="1" parent="1">
  <mxGeometry x="50" y="50" width="400" height="300" as="geometry" />
</mxCell>
<!-- Child element inside container -->
<mxCell id="21" value="Child" style="rounded=1;" vertex="1" parent="20">
  <mxGeometry x="50" y="80" width="120" height="60" as="geometry" />
</mxCell>
```

Children use `parent="{container-id}"`. Their `x`,`y` are relative to the container.

## Positioning Tips

- Start first element at `x="100" y="100"` to avoid clipping
- Horizontal spacing: 200px between elements
- Vertical spacing: 150px between rows
- Swimlane header height: `startSize=30`
- Group related elements in swimlanes
