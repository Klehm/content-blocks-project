---
title: Button group block
---

# `button_group` — Button group

> One to three buttons in a row, sharing size and alignment.

A row of call-to-action buttons that sit side by side and wrap together. Each entry has its own label, URL and `variant`; the `size` and alignment are shared by the whole row, so the buttons always match. Links go through the same safe-URL guard as [`button`](./button.md).

## When to use

A primary and a secondary action next to each other ("Buy" / "Learn more"), store badges, a short list of equal choices.

## Configuration

Configure under `content_blocks_kit.blocks.button_group` (see [Configuring blocks](../configuration.md) for how the levers combine).

#### Options

Block-level knobs, set under `options:`.

| Option | Default |
| --- | --- |
| `max_items` | `3` |

#### Choice fields

Selectable values. Restrict or reorder them per host with `choices:` (the default is shown in **bold**).

| Field | Values |
| --- | --- |
| `variant` | `primary`, `secondary`, `outline`, `link` |
| `size` | `sm`, **`md`**, `lg` |
| `align` | **`start`**, `center`, `end` |

#### Default data

Initial values for a new block. Override per host with `defaults:`.

| Field | Default |
| --- | --- |
| `items` | `[{"text":"Learn more","url":"","variant":"primary","newTab":false},{"text":"Contact us","url":"","variant":"outline","newTab":false}]` |
| `size` | `md` |
| `align` | `start` |
| `stackOnMobile` | `false` |

#### Example

```yaml
# config/packages/content_blocks_kit.yaml
content_blocks_kit:
    blocks:
        button_group:
            options: { max_items: 3 }
            choices: { variant: [primary, secondary] }   # restrict / reorder the picker
            defaults: { size: md }
```

## Front-end

Rendered markup: `.cb-kit-btn-group` with an alignment modifier, and `--stack` when *Stack on mobile* is on; each entry is a `.cb-kit-btn`. Style it by overriding the `--cb-kit-*` custom properties (see [the kit stylesheet](../index.md#front-stylesheet-required)).

## Notes

- The `max_items` option caps the number of buttons (3 by default).
- *Stack on mobile* puts the buttons one under the other on small screens.

---

_The configuration tables above are generated from `content-blocks-kit:blocks --format=json`, read straight from the block's code — they never go stale._
