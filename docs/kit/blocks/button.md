---
title: Button block
---

# `button` — Button

> A call-to-action button with variants, sizes and alignment.

A styled link-button for calls to action. Choose a visual `variant` (e.g. primary/secondary/outline), a `size`, and horizontal alignment. The label and target URL are free text.

## When to use

CTAs, "Learn more" links, form entry points.

## Configuration

Configure under `content_blocks_kit.blocks.button` (see [Configuring blocks](../configuration.md) for how the levers combine).

#### Choice fields

Selectable values. Restrict or reorder them per host with `choices:` (the default is shown in **bold**).

| Field | Values |
| --- | --- |
| `variant` | **`primary`**, `secondary`, `outline`, `link` |
| `size` | `sm`, **`md`**, `lg` |
| `align` | **`start`**, `center`, `end` |

#### Default data

Initial values for a new block. Override per host with `defaults:`.

| Field | Default |
| --- | --- |
| `text` | `Learn more` |
| `url` | `''` |
| `variant` | `primary` |
| `size` | `md` |
| `align` | `start` |
| `fullWidth` | `false` |
| `newTab` | `false` |

#### Example

```yaml
# config/packages/content_blocks_kit.yaml
content_blocks_kit:
    blocks:
        button:
            choices: { variant: [primary, secondary] }   # restrict / reorder the picker
            defaults: { text: Learn more }
```

## Front-end

Rendered markup: `.cb-kit-button` with a `--<variant>` modifier. Style it by overriding the `--cb-kit-*` custom properties (see [the kit stylesheet](../index.md#front-stylesheet-required)).

## Notes

- Restrict the `variant` / `size` choice lists per host to enforce a design system (see [Configuration](../configuration.md)).

---

_The configuration tables above are generated from `content-blocks-kit:blocks --format=json`, read straight from the block's code — they never go stale._
