---
title: Block Kit
---

# Block Kit

`klehm/content-blocks-kit` is an optional package of **17 ready-to-use block types** for [ContentBlocks](/guide/). Install it and you get a full block palette out of the box — no configuration required.

The kit is **self-contained**: no Tailwind, no Bootstrap, no LiipImagine, no icon library. Every block renders neutral `cb-kit-*` markup styled by a single shipped stylesheet, so it drops into any host regardless of its CSS setup.

## The 17 blocks

| Block | What it is |
|---|---|
| [`title`](./blocks/title.md) | Heading with a visual size decoupled from its semantic tag |
| [`text`](./blocks/text.md) | Plain paragraph text with a palette color |
| [`rich_text`](./blocks/rich_text.md) | WYSIWYG rich text, on TinyMCE or CKEditor |
| [`image`](./blocks/image.md) | Image with size, fit, align, link, caption, rounded corners |
| [`gallery`](./blocks/gallery.md) | Image grid or arrow slider |
| [`button`](./blocks/button.md) | Call-to-action button (variants, sizes, alignment) |
| [`card`](./blocks/card.md) | Image/title/text/button tiles as a grid or list |
| [`list`](./blocks/list.md) | Bulleted / checkmark / numbered list |
| [`icon`](./blocks/icon.md) | A single icon from the shipped icon set |
| [`alert`](./blocks/alert.md) | Info / success / warning / error callout |
| [`divider`](./blocks/divider.md) | Horizontal rule (style + color) |
| [`accordion`](./blocks/accordion.md) | Collapsible panels (native `<details>`, zero JS) |
| [`table`](./blocks/table.md) | Columns + rows data table |
| [`embed`](./blocks/embed.md) | Responsive YouTube / Vimeo embed |
| [`breadcrumb`](./blocks/breadcrumb.md) | Breadcrumb trail |
| [`tabs`](./blocks/tabs.md) | Tabbed panels |
| [`html_raw`](./blocks/html_raw.md) | Raw-HTML escape hatch (**disabled by default**) |

Each block has its own reference page with its full configurable surface — see the sidebar.

## Installation

```bash
composer require klehm/content-blocks klehm/content-blocks-kit
```

The blocks are auto-registered via Symfony autoconfiguration — no config needed to get all of them (except `html_raw`, which is [disabled by default](./blocks/html_raw.md)).

### Front stylesheet (required)

Kit blocks render with neutral `cb-kit-*` classes styled by a stylesheet the kit serves at a public route. Include it once in your front layout (it also flows into the builder preview):

```twig
<link rel="stylesheet" href="{{ path('content_blocks_kit_asset_css') }}">
```

Retheme by overriding the `--cb-kit-*` custom properties (or the classes) in your own stylesheet loaded after it.

### Stimulus controllers

Two blocks need a Stimulus controller. Enable them in your host `assets/controllers.json` under the `@klehm/content-blocks-kit` package — `rich_text` has one per selectable editor, so enable the one you configured (or both, if you are unsure):

| Controller | Needed by |
|---|---|
| `cb-tinymce` | [`rich_text`](./blocks/rich_text.md) on its default editor |
| `cb-ckeditor` | [`rich_text`](./blocks/rich_text.md) when `options.editor: ckeditor` |
| `cb-gallery` | [`gallery`](./blocks/gallery.md) (slider layout only) |

## Colors

Every color field in the kit — icon and divider colors, the `title` and `text` blocks' text color, and the rich-text editor's swatches — draws from the **one** core palette declared in `content_blocks.palette`. Add a named color there once and it appears everywhere:

```yaml
# config/packages/content_blocks.yaml
content_blocks:
    palette:
        - { label: 'Brand', color: '#eb0540' }
```

## Discovering the surface from the CLI

Every block's options, choice fields (default marked `*`) and data defaults are introspectable — read straight from the code, so the output never goes stale:

```bash
bin/console content-blocks-kit:blocks              # all blocks, human-readable
bin/console content-blocks-kit:blocks button       # one block
bin/console content-blocks-kit:blocks --format=json   # machine-readable (drives these docs)
```

The per-block reference pages in this section are **generated** from that JSON, so they always match the installed version.

## Next

- **[Configuring blocks](./configuration.md)** — the four per-block levers (`enabled`, `options`, `choices`, `defaults`) and how they combine.
- **Individual block pages** — see the sidebar for all 17.
- **[Overriding block templates](./configuration.md#overriding-block-templates)** — swap any block's markup.
