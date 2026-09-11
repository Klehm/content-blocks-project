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

### Editor gestures: the navigator

The topbar's **Navigator** button opens a floating panel listing the area as sections → columns → blocks, with drag-to-reorder, duplicate and delete on every section and block. The panel itself is draggable by its header — it floats over the content it describes, so it has to be movable off whatever it is hiding — and remembers where it was parked. It exists for the two things the render-faithful preview is bad at: seeing a long page at a glance, and moving a block from its top to its bottom without dragging past everything in between.

It is a second **way in**, not a second state. Selecting a row opens the same sidebar a click in the preview opens and brings the preview to that element; the panel highlights whatever the sidebar has open, whichever side the click came from — the same "the sidebar *is* the selection" rule copy/paste follows below. Every action it offers is an endpoint the builder already had, so it writes to the **draft** and Publish / Discard behave exactly as before.

A block row is its **icon plus a line of text**: the text comes from `BlockPreviewHintInterface` (the seam the section-library thumbnails use) and falls back to the type's label, while the icon is the block type's own `getIcon()` — the same glyph the add-block picker shows. Naming the type beside that label read as noise, since a block with nothing to summarise already reads its type as its label. **Columns appear as read-only nodes**: they are derived from the section's layout and have no operations of their own, so showing them keeps the outline honest without inventing CRUD the model does not have.

### Editor gestures: copy / paste

Editors can copy a section or a block and paste it elsewhere — another section, another area, another page. It is deliberately **keyboard-only**: `Ctrl/Cmd-C` and `Ctrl/Cmd-V`, no toolbar button, no menu entry. The shortcut works from inside the preview iframe too (the overlay relays it), and it stands back whenever the keystroke belongs to the editor's own text — focus in a field, or a live text selection.

What gets copied is **whatever the sidebar has open**. Clicking an element is what opens it, so the sidebar *is* the selection, and there is no second "focused entity" state to keep in sync with it. A copy changes nothing on screen, so it is acknowledged in the snackbar — the same bar as the undo offer, minus the button.

Where a paste lands follows that same selection:

| Copied | Selection | Lands |
|---|---|---|
| a section | a section | right after it |
| a section | a block | right after that block's section |
| a section | nothing | at the end of the area |
| a block | a block | right after it, in its column |
| a block | a section | at the end of its **first** column |
| a block | nothing | refused, with a reason |

The last row is the rule that matters: pasting a block with nothing selected has no answer to *where*, so the builder says so instead of guessing. Paste writes to the **draft**, like every other structural operation — Publish commits it, Discard reverts it — and `canEdit()` is checked on the **target** area, which for a cross-area paste is not the one the copy came from.

::: info The clipboard is `localStorage`, so a paste is not a restore
Storing the entry in the browser is what lets a copy survive leaving the page — and what makes the payload user-writable. Every pasted block is therefore replayed through its own form before anything is written. See [Security → the clipboard goes through the same door](./security.md#the-clipboard-goes-through-the-same-door).
:::

### Editor gestures: undo / redo

`Ctrl/Cmd-Z` walks back through the actions of the current builder session, one at a time; `Ctrl/Cmd-Shift-Z` (or `Ctrl-Y`) walks forward again. It covers everything the builder does — create, move, duplicate, delete, paste, insert content, import, a section's settings, a block's fields — and it follows copy/paste's rules exactly: keyboard-only, relayed from inside the preview, and standing back whenever the keystroke belongs to the editor's own text.

Between the delete snackbar (one delete, six seconds) and Discard (the whole unpublished draft, irreversibly), there was nothing. This is that middle.

Three things are worth knowing before you rely on it:

- **It writes to the draft, like everything else.** Undoing never touches the published page; Publish is still the only gesture that does. A soft-deleted block that an undo brings back was on the live page the whole time.
- **The stack is per HTTP session and lives in a table**, so it survives a page reload — which is exactly when an editor wants it. Publishing or discarding empties it: the draft those entries describe is gone either way.
- **A step whose target moved under it is refused**, with a message, rather than guessed. Two people on one page will eventually undo into a world that has changed, and overwriting each other silently is the worse failure.

It needs one table, `cb_action_log`. See the migration in [apps/content-blocks-sandbox/migrations](https://github.com/klehm/content-blocks-project/tree/main/apps/content-blocks-sandbox/migrations); without it the endpoints simply have nowhere to write and undo is unavailable.

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
