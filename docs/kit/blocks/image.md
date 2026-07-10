---
title: Image block
---

# `image` — Image

> A single image with size, fit, alignment, link, caption and rounded corners.

Displays one image with a rich set of presentation controls: a size preset (`sm`/`md`/`lg`/`full`) or a fully custom width/height, object-fit (`cover`/`contain`), horizontal alignment, an optional link wrapper, a caption, and per-corner border radius. Uploads go through the core upload brick (`ImageUploadType` + `/_content-blocks/upload`).

## When to use

Any standalone image: hero, inline figure, logo, illustration.

## Configuration

Configure under `content_blocks_kit.blocks.image` (see [Configuring blocks](../configuration.md) for how the levers combine).

#### Choice fields

Selectable values. Restrict or reorder them per host with `choices:` (the default is shown in **bold**).

| Field | Values |
| --- | --- |
| `size` | `sm`, **`md`**, `lg`, `full`, `custom` |
| `fit` | **`cover`**, `contain` |
| `align` | `start`, **`center`**, `end` |

#### Default data

Initial values for a new block. Override per host with `defaults:`.

| Field | Default |
| --- | --- |
| `src` | `''` |
| `alt` | `''` |
| `size` | `md` |
| `customWidth` | `600` |
| `customHeightAuto` | `true` |
| `customHeight` | `400` |
| `fit` | `cover` |
| `align` | `center` |
| `link` | `''` |
| `caption` | `''` |
| `borderRadius` | `{"linked":true}` |

#### Example

```yaml
# config/packages/content_blocks_kit.yaml
content_blocks_kit:
    blocks:
        image:
            choices: { size: [sm, md] }   # restrict / reorder the picker
            defaults: { src: '' }
```

## Front-end

Rendered markup: `.cb-kit-image` — wraps a `<figure>` when a caption is present. Style it by overriding the `--cb-kit-*` custom properties (see [the kit stylesheet](../index.md#front-stylesheet-required)).

## Notes

- Picking `size: custom` reveals `customWidth` / `customHeight` (with a `customHeightAuto` toggle) via conditional fields.
- Requires [file storage](../../guide/host-services.md#file-storage) to be configured for uploads to work.
- Opts into preview hot-reload — the upload JS is in the sidebar, not the view.

---

_The configuration tables above are generated from `content-blocks-kit:blocks --format=json`, read straight from the block's code — they never go stale._
