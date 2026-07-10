---
title: Breadcrumb block
---

# `breadcrumb` — Breadcrumb

> A breadcrumb trail.

Renders a breadcrumb navigation trail from a list of label/URL pairs.

## When to use

Page hierarchy context near the top of a content area.

## Configuration

Configure under `content_blocks_kit.blocks.breadcrumb` (see [Configuring blocks](../configuration.md) for how the levers combine).

#### Default data

Initial values for a new block. Override per host with `defaults:`.

| Field | Default |
| --- | --- |
| `items` | `[{"label":"Home","url":"/"},{"label":"Current page","url":""}]` |

#### Example

```yaml
# config/packages/content_blocks_kit.yaml
content_blocks_kit:
    blocks:
        breadcrumb:
```

## Front-end

Rendered markup: `.cb-kit-breadcrumb` (semantic `<nav>`). Style it by overriding the `--cb-kit-*` custom properties (see [the kit stylesheet](../index.md#front-stylesheet-required)).

---

_The configuration tables above are generated from `content-blocks-kit:blocks --format=json`, read straight from the block's code — they never go stale._
