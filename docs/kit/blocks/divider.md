---
title: Divider block
---

# `divider` — Divider

> A horizontal rule with a configurable style and color.

A horizontal separator between content. The line style (solid/dashed/dotted) and its color (from the palette) are configurable.

## When to use

Visual breaks between content groups within a column.

## Configuration

Configure under `content_blocks_kit.blocks.divider` (see [Configuring blocks](../configuration.md) for how the levers combine).

#### Choice fields

Selectable values. Restrict or reorder them per host with `choices:` (the default is shown in **bold**).

| Field | Values |
| --- | --- |
| `style` | **`solid`**, `dashed`, `dotted` |

#### Default data

Initial values for a new block. Override per host with `defaults:`.

| Field | Default |
| --- | --- |
| `style` | `solid` |
| `color` | `''` |

#### Example

```yaml
# config/packages/content_blocks_kit.yaml
content_blocks_kit:
    blocks:
        divider:
            choices: { style: [solid, dashed] }   # restrict / reorder the picker
            defaults: { style: solid }
```

## Front-end

Rendered markup: `.cb-kit-divider` Style it by overriding the `--cb-kit-*` custom properties (see [the kit stylesheet](../index.md#front-stylesheet-required)).

## Notes

- `color` draws from `content_blocks.palette`.

---

_The configuration tables above are generated from `content-blocks-kit:blocks --format=json`, read straight from the block's code — they never go stale._
