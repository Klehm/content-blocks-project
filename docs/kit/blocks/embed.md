---
title: Embed block
---

# `embed` — Video embed

> A responsive YouTube / Vimeo video embed.

Embeds a YouTube or Vimeo video responsively (16:9 by default) from a paste-in URL. URL parsing uses the core `cb_embed_url` helper, so both watch-page and share URLs work.

## When to use

Product videos, tutorials, any hosted video.

## Configuration

Configure under `content_blocks_kit.blocks.embed` (see [Configuring blocks](../configuration.md) for how the levers combine).

#### Default data

Initial values for a new block. Override per host with `defaults:`.

| Field | Default |
| --- | --- |
| `url` | `''` |
| `title` | `''` |

#### Example

```yaml
# config/packages/content_blocks_kit.yaml
content_blocks_kit:
    blocks:
        embed:
            defaults: { url: '' }
```

## Front-end

Rendered markup: `.cb-kit-embed` with a responsive iframe wrapper. Style it by overriding the `--cb-kit-*` custom properties (see [the kit stylesheet](../index.md#front-stylesheet-required)).

## Notes

- Only YouTube and Vimeo are recognized; other providers are not embedded.

---

_The configuration tables above are generated from `content-blocks-kit:blocks --format=json`, read straight from the block's code — they never go stale._
