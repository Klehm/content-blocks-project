---
title: Gallery block
---

# `gallery` — Gallery

> A set of images as a responsive grid or an arrow slider.

Renders a collection of images either as a **grid** (column count is configurable) or as a **slider** with prev/next arrows. Shared controls: object-fit and rounded corners. The `max_columns` **option** caps how many columns the editor can choose.

## When to use

Portfolios, product shots, logo walls, before/after strips.

## Configuration

Configure under `content_blocks_kit.blocks.gallery` (see [Configuring blocks](../configuration.md) for how the levers combine).

#### Options

Block-level knobs, set under `options:`.

| Option | Default |
| --- | --- |
| `max_columns` | `6` |

#### Choice fields

Selectable values. Restrict or reorder them per host with `choices:` (the default is shown in **bold**).

| Field | Values |
| --- | --- |
| `layout` | **`grid`**, `slider` |
| `columns` | `2`, **`3`**, `4`, `5`, `6` |
| `fit` | **`cover`**, `contain` |

#### Default data

Initial values for a new block. Override per host with `defaults:`.

| Field | Default |
| --- | --- |
| `layout` | `grid` |
| `columns` | `3` |
| `fit` | `cover` |
| `borderRadius` | `{"linked":true}` |
| `items` | `[{"src":"","alt":"","caption":"","link":""}]` |

#### Example

```yaml
# config/packages/content_blocks_kit.yaml
content_blocks_kit:
    blocks:
        gallery:
            options: { max_columns: 6 }
            choices: { layout: [grid, slider] }   # restrict / reorder the picker
            defaults: { layout: grid }
```

## Front-end

Rendered markup: `.cb-kit-gallery` (grid) / `.cb-kit-gallery--slider` (slider). Style it by overriding the `--cb-kit-*` custom properties (see [the kit stylesheet](../index.md#front-stylesheet-required)).

::: tip Requires a Stimulus controller
Enable the `cb-gallery` controller under `@klehm/content-blocks-kit` in your host `assets/controllers.json`.
:::

## Notes

- The `cb-gallery` controller is only needed for the **slider** layout; a pure grid is CSS-only.
- Use the `max_columns` option to restrict the `columns` choice list for a given host.

---

_The configuration tables above are generated from `content-blocks-kit:blocks --format=json`, read straight from the block's code — they never go stale._
