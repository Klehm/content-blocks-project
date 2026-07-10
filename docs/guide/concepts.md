---
title: Core concepts
---

# Core concepts

This page is the mental model: how ContentBlocks is structured, how blocks are discovered, how the admin UI is wired, and how the `ContentAreaType` form behaves across a request lifecycle.

## Data model

A page builder tree is four nested entities:

```
ContentArea → Section → Column → Block
```

`ContentArea` is a generic, title-less, slug-less **container** of sections. It is attachable to any application entity (page, product, category…) — the host app owns its own entity (e.g. `Page`) and links to a `ContentArea` via a `OneToOne` relation.

| Entity        | Table              | Key fields                               |
|---------------|--------------------|------------------------------------------|
| `ContentArea` | `cb_content_area`  | id                                       |
| `Section`     | `cb_section`       | id, content_area_id, layout, position    |
| `Column`      | `cb_column`        | id, section_id, preset, position         |
| `Block`       | `cb_block`         | id, column_id, type, data, position      |

- **`Section.layout`**: `full`, `two_cols`, `three_cols`
- **`Column.preset`**: `col-12`, `col-6`, `col-4`, etc.
- **`Block.type`**: the BlockType identifier (e.g. `text`, `title`, `image`)
- **`Block.data`**: free-form JSON; its structure depends on the block type

## Block-type system

Each kind of block is a service implementing `BlockTypeInterface`, discovered automatically:

1. A block type implements `BlockTypeInterface`.
2. It is annotated with `#[AsContentBlock]`.
3. The `BlockTypeCompilerPass` auto-tags services carrying that attribute.
4. The `BlockTypeRegistry` centralizes every available type.

Because discovery is driven by Symfony autoconfiguration, dropping a new class into a loaded namespace is enough to register a block — no manual wiring. See [Custom blocks](./custom-blocks.md) for the full recipe.

## Admin UI: Live Components + Stimulus

The admin UI deliberately mixes two technologies, each for what it does best:

- **Live Components** handle **server-side CRUD** — adding/removing sections and blocks, inline block editing.
- **Stimulus controllers** handle **fine-grained DOM control** — drag-and-drop reordering, and integrating third-party JS editors (TinyMCE, upload widgets).

::: warning Architecture rule
Do **not** use a LiveAction for operations that reorder child Live Components. Morphdom/Idiomorph cannot reconcile the reorder of child Live Components that use `data-live-preserve` — reordering must go through Stimulus/DOM, not a server round-trip morph.
:::

### Key admin components

- **ContentAreaBuilder** — the main component; manages adding/removing sections (1, 2 or 3 columns).
- **Column** — manages adding/removing blocks within a column.
- **Block** — inline edit mode (simplified modal).
- **Section** — a plain Twig Component (static render of its columns).

## `ContentAreaType` lifecycle

`ContentAreaType` is a ready-to-use Symfony FormType that embeds a full builder in any form:

```php
$builder->add('contentArea', ContentAreaType::class);
```

Its request lifecycle has one rule worth internalizing:

::: info No DB write on GET
`ContentAreaType::buildView()` writes **nothing** to the database on a `GET`. If the host entity has no `ContentArea` yet (new entity, or legacy data), the widget renders a "save first" placeholder instead of the builder. This avoids orphan `cb_content_area` rows being created on every GET of a creation form.
:::

On submit, `reverseTransform()` creates a **transient** `ContentArea` (persisted without flush). The host commits it via either:

- `cascade: ['persist']` on the host entity's relation (recommended — see [Installation](./installation.md#quick-start)), or
- an explicit `$em->flush()` in the host controller.

Once the form is submitted and the host entity is persisted, the next edit shows the builder normally.

The widget itself is rendered through a form theme (`@ContentBlocks/form/content_area_widget.html.twig`) that the bundle auto-prepends, so no host template setup is needed.
