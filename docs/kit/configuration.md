---
title: Configuring blocks
---

# Configuring blocks

Every kit block exposes **four levers** under `content_blocks_kit.blocks.<type>`. They let a host tailor the block palette — drop blocks, restrict pickers, change starting values — without forking anything.

| Key | Purpose |
|---|---|
| `enabled` | `false` un-registers the block's service — it never reaches the picker. |
| `options` | Block-level knobs (e.g. `max_columns`), merged over the block's coded ones. |
| `choices` | Per-field allow-list restricting/reordering a `ChoiceType` field. |
| `defaults` | Per-field overrides of a block's initial data (what a new block starts with). |

```yaml
# config/packages/content_blocks_kit.yaml
content_blocks_kit:
    blocks:
        tabs: { enabled: false }                # drop a block entirely
        html_raw: { enabled: true }             # opt into a default-disabled block
        gallery:
            options: { max_columns: 4 }         # cap the column choices
        button:
            choices:
                variant: [primary, secondary]   # only these two, in this order, in the picker
                size: [md, lg]
            defaults:
                variant: secondary              # new buttons start as "secondary"
                align: center
        title:
            defaults: { size: h1 }              # new titles default to h1 size
```

## How the levers behave

- Blocks omitted from config are enabled with their coded defaults — **except** [`html_raw`](./blocks/html_raw.md), which is **disabled by default**: it renders unescaped markup (`{{ html|raw }}`), so it trusts its editors and must be opted in explicitly.
- `choices` values not offered by the block are ignored; an empty or all-invalid list falls back to the full set (the select is never empty).
- `defaults` only apply to fields the block declares; unknown keys are ignored.

::: tip Restricting a picker never invalidates stored data
Narrowing a `choices` list only affects what the editor can *pick*. Validation still accepts the block's **full coded set**, so content already saved with a now-hidden value stays valid. (The kit derives its `Assert\Choice` constraint from the complete choice set for exactly this reason.)
:::

## Where the values come from

All three of `options`, `choices` and `defaults` are declared **once in code**, in each block's `describe()` surface, and consumed both by the block's form and by the `content-blocks-kit:blocks` command. There is one source of truth — which is why the per-block reference pages in this documentation are generated from it and can't drift.

Discover any block's exact surface:

```bash
bin/console content-blocks-kit:blocks button          # human-readable
bin/console content-blocks-kit:blocks --format=json   # machine-readable
```

## Colors

All color fields draw from the single core palette (`content_blocks.palette`). See the [Block Kit overview](./index.md#colors) and the core [Styling guide](/guide/styling#color-palette).

## Overriding block templates

Drop a file at the matching relative path under `templates/bundles/ContentBlocksKitBundle/` to override any template shipped by the kit — e.g. `templates/bundles/ContentBlocksKitBundle/block/image/view.html.twig` overrides the image view.

::: warning Version requirement
Requires `klehm/content-blocks-kit >= 0.1.0-alpha.4` for overrides to take priority. Earlier versions registered the vendor `templates/` path under `@ContentBlocksKit`, which shadowed the host's `templates/bundles/ContentBlocksKitBundle/` directory.
:::

## File uploads

The [`image`](./blocks/image.md) block uses the core upload brick (`ImageUploadType`, the `/_content-blocks/upload` endpoint and `FileStorageInterface`). Enable local storage via the core config:

```yaml
# config/packages/content_blocks.yaml
content_blocks:
    upload:
        dir: '%kernel.project_dir%/public/uploads/content-blocks'
        public_prefix: '/uploads/content-blocks'
```

See the core [Host services → File storage](/guide/host-services#file-storage) for S3/Flysystem.
