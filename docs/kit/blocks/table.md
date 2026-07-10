---
title: Table block
---

# `table` — Table

> A data table from configurable columns and rows.

A straightforward data table: define columns and rows and the block renders a semantic `<table>`.

## When to use

Specs, comparison tables, structured tabular data.

## Configuration

Configure under `content_blocks_kit.blocks.table` (see [Configuring blocks](../configuration.md) for how the levers combine).

#### Default data

Initial values for a new block. Override per host with `defaults:`.

| Field | Default |
| --- | --- |
| `striped` | `true` |
| `columns` | `[{"label":"Name","align":"left"},{"label":"Value","align":"right"}]` |
| `rows` | `[{"cells":[{"content":"Row 1"},{"content":"—"}]}]` |

#### Example

```yaml
# config/packages/content_blocks_kit.yaml
content_blocks_kit:
    blocks:
        table:
            defaults: { striped: true }
```

## Front-end

Rendered markup: `.cb-kit-table` wrapping a native `<table>`. Style it by overriding the `--cb-kit-*` custom properties (see [the kit stylesheet](../index.md#front-stylesheet-required)).

---

_The configuration tables above are generated from `content-blocks-kit:blocks --format=json`, read straight from the block's code — they never go stale._
