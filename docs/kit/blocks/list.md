---
title: List block
---

# `list` — List

> A bulleted, checkmark or numbered list.

A simple list of items rendered as bullets, checkmarks, or numbers depending on the chosen style. Each item is plain text.

## When to use

Feature lists, requirement checklists, step summaries.

## Configuration

Configure under `content_blocks_kit.blocks.list` (see [Configuring blocks](../configuration.md) for how the levers combine).

#### Choice fields

Selectable values. Restrict or reorder them per host with `choices:` (the default is shown in **bold**).

| Field | Values |
| --- | --- |
| `style` | **`bullet`**, `check`, `numbered` |

#### Default data

Initial values for a new block. Override per host with `defaults:`.

| Field | Default |
| --- | --- |
| `style` | `bullet` |
| `items` | `[{"text":"List item"}]` |

#### Example

```yaml
# config/packages/content_blocks_kit.yaml
content_blocks_kit:
    blocks:
        list:
            choices: { style: [bullet, check] }   # restrict / reorder the picker
            defaults: { style: bullet }
```

## Front-end

Rendered markup: `.cb-kit-list` with a style modifier. Style it by overriding the `--cb-kit-*` custom properties (see [the kit stylesheet](../index.md#front-stylesheet-required)).

---

_The configuration tables above are generated from `content-blocks-kit:blocks --format=json`, read straight from the block's code — they never go stale._
