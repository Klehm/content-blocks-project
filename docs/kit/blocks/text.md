---
title: Text block
---

# `text` — Text

> A plain paragraph of text with a palette-driven color.

A single block of plain paragraph text — no rich formatting, no HTML. For formatted copy (bold, links, lists) reach for [`rich_text`](./rich_text.md) instead. The text color is picked from the project palette.

## When to use

Body copy, intros, captions — any place where you want plain, safe text without a WYSIWYG editor.

## Configuration

Configure under `content_blocks_kit.blocks.text` (see [Configuring blocks](../configuration.md) for how the levers combine).

#### Default data

Initial values for a new block. Override per host with `defaults:`.

| Field | Default |
| --- | --- |
| `content` | `''` |
| `color` | `''` |

#### Example

```yaml
# config/packages/content_blocks_kit.yaml
content_blocks_kit:
    blocks:
        text:
            defaults: { content: '' }
```

## Front-end

Rendered markup: `.cb-kit-text` Style it by overriding the `--cb-kit-*` custom properties (see [the kit stylesheet](../index.md#front-stylesheet-required)).

## Notes

- `color` draws from `content_blocks.palette` (empty = inherit).

---

_The configuration tables above are generated from `content-blocks-kit:blocks --format=json`, read straight from the block's code — they never go stale._
