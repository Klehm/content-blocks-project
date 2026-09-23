---
title: Video block
---

# `video` — Video

> A self-hosted video file in the browser's native player.

![The video block, rendered](/screenshots/kit/video.webp){.cb-kit-shot}

Plays a video file uploaded to the site, in the browser's own `<video>` player: no player script and no third party. It has a poster image, a size and an alignment like [`image`](./image.md), and playback options: autoplay, muted, controls and loop. For YouTube or Vimeo, use [`embed`](./embed.md).

## When to use

Short product clips, background-style loops, any video you host yourself.

## Configuration

Configure under `content_blocks_kit.blocks.video` (see [Configuring blocks](../configuration.md) for how the levers combine).

#### Choice fields

Selectable values. Restrict or reorder them per host with `choices:` (the default is shown in **bold**).

| Field | Values |
| --- | --- |
| `size` | `sm`, `md`, `lg`, **`full`** |
| `align` | `start`, **`center`**, `end` |

#### Default data

Initial values for a new block. Override per host with `defaults:`.

| Field | Default |
| --- | --- |
| `src` | `''` |
| `poster` | `''` |
| `autoplay` | `false` |
| `muted` | `false` |
| `controls` | `true` |
| `loop` | `false` |
| `size` | `full` |
| `align` | `center` |
| `caption` | `''` |
| `captions` | `''` |
| `captionsLang` | `''` |

#### Example

```yaml
# config/packages/content_blocks_kit.yaml
content_blocks_kit:
    blocks:
        video:
            choices: { size: [sm, md] }   # restrict / reorder the picker
            defaults: { src: '' }
```

## Front-end

Rendered markup: `.cb-kit-video` (a `<figure>`), `.cb-kit-video__player` on the `<video>`, `.cb-kit-video__caption` on the caption. Style it by overriding the `--cb-kit-*` custom properties (see [the kit stylesheet](../index.md#front-stylesheet-required)).

## Notes

- Uploads need `video/mp4` and/or `video/webm` added to `content_blocks.upload.allowed_mime_types`: they are not in the default list.
- Autoplay is always muted, since browsers refuse unmuted autoplay, and a video without autoplay always shows its controls, since it could not be started otherwise.
- The poster goes through [`ImageUrlResolverInterface`](../../guide/host-services.md#imageurlresolverinterface-responsive-images).
- Captions are a WebVTT file given by path or URL, rendered as a default `<track kind="captions">`. The file must be served as `text/vtt`. Both the file and its language are translatable, so each language can point at its own file.

---

_The configuration tables above are generated from `content-blocks-kit:blocks --format=json`, read straight from the block's code — they never go stale._
