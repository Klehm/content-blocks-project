---
title: Icon block
---

# `icon` — Icon

> A single icon from the shipped icon set, in a palette color.

Renders one icon from the kit's **self-contained icon set** (no external icon library needed). Size and color (from the palette) are configurable.

## When to use

Accents next to headings, feature bullets, inline glyphs.

## Configuration

Configure under `content_blocks_kit.blocks.icon` (see [Configuring blocks](../configuration.md) for how the levers combine).

#### Choice fields

Selectable values. Restrict or reorder them per host with `choices:` (the default is shown in **bold**).

| Field | Values |
| --- | --- |
| `name` | **`star`**, `heart`, `check`, `check-circle`, `info`, `help`, `warning`, `lightbulb`, `phone`, `mail`, `map-pin`, `calendar`, `clock`, `user`, `settings`, `thumbs-up`, `gift`, `shield`, `zap`, `globe`, `camera`, `arrow-right`, `quote` |
| `align` | `start`, **`center`**, `end` |

#### Default data

Initial values for a new block. Override per host with `defaults:`.

| Field | Default |
| --- | --- |
| `name` | `star` |
| `color` | `''` |
| `size` | `48` |
| `align` | `center` |

#### Example

```yaml
# config/packages/content_blocks_kit.yaml
content_blocks_kit:
    blocks:
        icon:
            choices: { name: [star, heart] }   # restrict / reorder the picker
            defaults: { name: star }
```

## Front-end

Rendered markup: `.cb-kit-icon` (inline SVG from the shipped `IconSet`). Style it by overriding the `--cb-kit-*` custom properties (see [the kit stylesheet](../index.md#front-stylesheet-required)).

## Notes

- `color` draws from `content_blocks.palette`. The icon list is large — run `content-blocks-kit:blocks icon` to see the full set.

---

_The configuration tables above are generated from `content-blocks-kit:blocks --format=json`, read straight from the block's code — they never go stale._
