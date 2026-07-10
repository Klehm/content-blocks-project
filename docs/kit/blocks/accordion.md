---
title: Accordion block
---

# `accordion` — Accordion

> Collapsible panels built on native `<details>` — zero JavaScript.

A set of collapsible panels, each with a header and a body. Built on the **native `<details>`/`<summary>` elements**, so it needs no JavaScript at all and works even with JS disabled.

## When to use

FAQs, disclosure sections, long content you want to fold away.

## Configuration

Configure under `content_blocks_kit.blocks.accordion` (see [Configuring blocks](../configuration.md) for how the levers combine).

#### Default data

Initial values for a new block. Override per host with `defaults:`.

| Field | Default |
| --- | --- |
| `exclusive` | `false` |
| `items` | `[{"title":"Question","content":"Answer"}]` |

#### Example

```yaml
# config/packages/content_blocks_kit.yaml
content_blocks_kit:
    blocks:
        accordion:
            defaults: { exclusive: false }
```

## Front-end

Rendered markup: `.cb-kit-accordion` wrapping native `<details>` elements. Style it by overriding the `--cb-kit-*` custom properties (see [the kit stylesheet](../index.md#front-stylesheet-required)).

## Notes

- No Stimulus controller required — the open/close behavior is browser-native.

---

_The configuration tables above are generated from `content-blocks-kit:blocks --format=json`, read straight from the block's code — they never go stale._
