---
title: Tabs block
---

# `tabs` — Tabs

> Tabbed panels.

A set of labeled tabs, each revealing its own panel of content.

## When to use

Grouping alternative content (specs vs. reviews, plans, platforms) in one compact area.

## Configuration

Configure under `content_blocks_kit.blocks.tabs` (see [Configuring blocks](../configuration.md) for how the levers combine).

#### Default data

Initial values for a new block. Override per host with `defaults:`.

| Field | Default |
| --- | --- |
| `tabs` | `[{"title":"Tab 1","content":""}]` |

#### Example

```yaml
# config/packages/content_blocks_kit.yaml
content_blocks_kit:
    blocks:
        tabs:
```

## Front-end

Rendered markup: `.cb-kit-tabs` Style it by overriding the `--cb-kit-*` custom properties (see [the kit stylesheet](../index.md#front-stylesheet-required)).

---

_The configuration tables above are generated from `content-blocks-kit:blocks --format=json`, read straight from the block's code — they never go stale._
