---
title: Alert block
---

# `alert` — Alert

> An info / success / warning / error callout.

A colored callout box for drawing attention: informational, success, warning or error variants, each with its own accent color and (optional) icon.

## When to use

Notices, tips, warnings, inline status messages.

## Configuration

Configure under `content_blocks_kit.blocks.alert` (see [Configuring blocks](../configuration.md) for how the levers combine).

#### Choice fields

Selectable values. Restrict or reorder them per host with `choices:` (the default is shown in **bold**).

| Field | Values |
| --- | --- |
| `type` | **`info`**, `success`, `warning`, `error` |

#### Default data

Initial values for a new block. Override per host with `defaults:`.

| Field | Default |
| --- | --- |
| `type` | `info` |
| `title` | `''` |
| `message` | `''` |

#### Example

```yaml
# config/packages/content_blocks_kit.yaml
content_blocks_kit:
    blocks:
        alert:
            choices: { type: [info, success] }   # restrict / reorder the picker
            defaults: { type: info }
```

## Front-end

Rendered markup: `.cb-kit-alert` with a variant modifier. Style it by overriding the `--cb-kit-*` custom properties (see [the kit stylesheet](../index.md#front-stylesheet-required)).

---

_The configuration tables above are generated from `content-blocks-kit:blocks --format=json`, read straight from the block's code — they never go stale._
