---
title: Card block
---

# `card` — Cards

> Image / title / text / button tiles laid out as a grid or list.

A repeatable set of cards, each combining an image, a title, some text and an optional button. Lay them out as a **grid** (capped by the `max_columns` option) or a vertical **list**. Great for feature rows and teaser sections.

## When to use

Feature grids, service tiles, article teasers, pricing tiers.

## Configuration

Configure under `content_blocks_kit.blocks.card` (see [Configuring blocks](../configuration.md) for how the levers combine).

#### Options

Block-level knobs, set under `options:`.

| Option | Default |
| --- | --- |
| `max_columns` | `4` |

#### Choice fields

Selectable values. Restrict or reorder them per host with `choices:` (the default is shown in **bold**).

| Field | Values |
| --- | --- |
| `layout` | **`grid`**, `list` |
| `columns` | `2`, **`3`**, `4` |

#### Default data

Initial values for a new block. Override per host with `defaults:`.

| Field | Default |
| --- | --- |
| `layout` | `grid` |
| `columns` | `3` |
| `items` | `[{"src":"","title":"Card title","content":"Card text","url":"","buttonText":""}]` |

#### Example

```yaml
# config/packages/content_blocks_kit.yaml
content_blocks_kit:
    blocks:
        card:
            options: { max_columns: 4 }
            choices: { layout: [grid, list] }   # restrict / reorder the picker
            defaults: { layout: grid }
```

## Front-end

Rendered markup: `.cb-kit-card` items inside `.cb-kit-cards`. Style it by overriding the `--cb-kit-*` custom properties (see [the kit stylesheet](../index.md#front-stylesheet-required)).

## Notes

- The `max_columns` option caps the grid column choice for a given host.
- Card media is resolved through [`ImageUrlResolverInterface`](../../guide/host-services.md#imageurlresolverinterface-responsive-images). A tile is fluid, so no display width is passed and a resolver returning candidates owns `sizes` itself.

---

_The configuration tables above are generated from `content-blocks-kit:blocks --format=json`, read straight from the block's code — they never go stale._
