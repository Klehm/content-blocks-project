---
title: Rich text block
---

# `rich_text` — Rich text

> WYSIWYG rich text powered by TinyMCE.

A full WYSIWYG editor (TinyMCE) for formatted copy: bold/italic, links, lists, headings. The color swatches offered in the editor are wired to the project palette (`cb_color_palette()`), so rich text stays on-brand.

## When to use

Long-form content, marketing copy, anything needing inline formatting or links.

## Configuration

Configure under `content_blocks_kit.blocks.rich_text` (see [Configuring blocks](../configuration.md) for how the levers combine).

#### Default data

Initial values for a new block. Override per host with `defaults:`.

| Field | Default |
| --- | --- |
| `content` | `''` |

#### Example

```yaml
# config/packages/content_blocks_kit.yaml
content_blocks_kit:
    blocks:
        rich_text:
            defaults: { content: '' }
```

## Front-end

Rendered markup: `.cb-kit-rich-text` (the stored HTML is rendered as-is). Style it by overriding the `--cb-kit-*` custom properties (see [the kit stylesheet](../index.md#front-stylesheet-required)).

::: tip Requires a Stimulus controller
Enable the `cb-tinymce` controller under `@klehm/content-blocks-kit` in your host `assets/controllers.json`.
:::

## Notes

- The editor JavaScript lives in the **sidebar**, never in the preview — so the block opts into preview hot-reload.
- TinyMCE color swatches read `content_blocks.palette` via `cb_color_palette()`.

---

_The configuration tables above are generated from `content-blocks-kit:blocks --format=json`, read straight from the block's code — they never go stale._
