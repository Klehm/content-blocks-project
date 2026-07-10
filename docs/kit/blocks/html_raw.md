---
title: Html raw block
---

# `html_raw` — Raw HTML

> A raw-HTML escape hatch — renders unescaped markup.

::: warning Disabled by default
The `html_raw` block is **not registered** unless you opt in with `content_blocks_kit.blocks.html_raw.enabled: true`.
:::

Outputs its content **verbatim and unescaped** (`{{ html|raw }}`). It is the escape hatch for embedding third-party snippets (widgets, forms, custom markup) that no other block covers.

::: danger Security
This block renders unescaped HTML and therefore **trusts its editors** (stored-XSS surface). It is **disabled by default** and must be explicitly opted in with `content_blocks_kit.blocks.html_raw.enabled: true`. Only enable it for roles you trust.
:::

## When to use

Trusted embeds and one-off markup that no structured block can express — only when you trust the editors.

## Configuration

Configure under `content_blocks_kit.blocks.html_raw` (see [Configuring blocks](../configuration.md) for how the levers combine).

#### Default data

Initial values for a new block. Override per host with `defaults:`.

| Field | Default |
| --- | --- |
| `html` | `''` |

#### Example

```yaml
# config/packages/content_blocks_kit.yaml
content_blocks_kit:
    blocks:
        html_raw:
            enabled: true                 # html_raw is disabled by default — opt in
            defaults: { html: '' }
```

## Front-end

Rendered markup: `.cb-kit-html-raw` (content rendered as-is). Style it by overriding the `--cb-kit-*` custom properties (see [the kit stylesheet](../index.md#front-stylesheet-required)).

---

_The configuration tables above are generated from `content-blocks-kit:blocks --format=json`, read straight from the block's code — they never go stale._
