---
title: Title block
---

# `title` — Title

> Heading with a visual size decoupled from its semantic tag.

Renders a heading whose **visual size** (`size`) is independent of its **semantic tag** (`tag`). That split lets an editor place, say, an `<h2>`-looking title that is actually an `<h1>` for the document outline — or a large visual lead that is semantically a `<p>`. The text color is picked from the project color palette.

## When to use

Section headings, page titles, visual leads — anywhere the heading level for SEO/accessibility should not dictate how big the text looks.

## Configuration

Configure under `content_blocks_kit.blocks.title` (see [Configuring blocks](../configuration.md) for how the levers combine).

#### Choice fields

Selectable values. Restrict or reorder them per host with `choices:` (the default is shown in **bold**).

| Field | Values |
| --- | --- |
| `size` | `h1`, **`h2`**, `h3`, `h4`, `h5`, `h6` |
| `tag` | `h1`, **`h2`**, `h3`, `h4`, `h5`, `h6`, `span`, `p` |

#### Default data

Initial values for a new block. Override per host with `defaults:`.

| Field | Default |
| --- | --- |
| `text` | `''` |
| `size` | `h2` |
| `tag` | `h2` |
| `color` | `''` |

#### Example

```yaml
# config/packages/content_blocks_kit.yaml
content_blocks_kit:
    blocks:
        title:
            choices: { size: [h1, h2] }   # restrict / reorder the picker
            defaults: { text: '' }
```

## Front-end

Rendered markup: `.cb-kit-title` (the element tag is whatever `tag` resolves to). Style it by overriding the `--cb-kit-*` custom properties (see [the kit stylesheet](../index.md#front-stylesheet-required)).

## Notes

- Both `size` and `tag` accept `h1`–`h6`; `tag` additionally accepts `span` and `p` for non-heading semantics.
- The `color` field draws from `content_blocks.palette` (empty = inherit).

---

_The configuration tables above are generated from `content-blocks-kit:blocks --format=json`, read straight from the block's code — they never go stale._
