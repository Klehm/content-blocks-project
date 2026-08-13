---
title: Configuring blocks
---

# Configuring blocks

Every kit block exposes **four levers** under `content_blocks_kit.blocks.<type>`. They let a host tailor the block palette — drop blocks, restrict pickers, change starting values — without forking anything.

| Key | Purpose |
|---|---|
| `enabled` | `false` un-registers the block's service — it never reaches the picker. |
| `options` | Block-level knobs (e.g. `max_columns`), merged over the block's coded ones. |
| `choices` | Per-field choice override: **restrict** a `ChoiceType` field, or **replace** its set outright — including with values the kit never coded. |
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
- `defaults` only apply to fields the block declares; unknown keys are ignored.
- `options` are per-block knobs, so what they do is documented on each block's page. The widest set belongs to [`rich_text`](./blocks/rich_text.md), which picks the WYSIWYG editor (TinyMCE or CKEditor), decides where its JavaScript is loaded from, and passes an init config through.

## `choices`: restricting versus replacing

`choices` reads its value in one of two shapes, told apart by whether you wrote a **list** or a **map**. There is no flag to set — the shape is the instruction.

```yaml
content_blocks_kit:
    blocks:
        button:
            choices:
                # A LIST restricts: keep these coded values, in this order.
                # Values the block does not code are ignored.
                size: [md, lg]

                # A MAP replaces: this becomes the entire choice set, so it can
                # add values the kit never shipped.
                variant:
                    primary: 'cb_kit.block.button.variant.primary'   # a translation key
                    ghost: 'Ghost'                                   # or a literal label
                    brand-outline: 'Contour marque'
```

Choosing between them:

| | list | map |
|---|---|---|
| Restrict / reorder | ✅ | ✅ |
| Rename a label | ❌ | ✅ |
| **Add a value** | ❌ | ✅ |
| Keeps the kit's labels | ✅ automatically | only for values you point back at their key |

**Labels are translated.** Every choice label goes through the field's translation domain (`content_blocks_kit`), so a translation key is resolved — your own catalogue's keys included — and a literal string comes out as written, because Symfony returns an unknown key unchanged.

::: warning A list cannot add — and fails silently if that is all you tried
A list is filtered against the coded set. If *every* value you list is unknown, the filter empties it and the block falls back to the **full** coded set rather than render an empty `<select>` — which looks exactly like the config being ignored. If you meant to add a value, you want the map form.
:::

::: tip Neither form invalidates stored data
Validation accepts the **union** of the block's coded values and your configured ones. Content already saved with a value you have since hidden stays valid, and a value you added passes its own form. Narrowing a picker is a UI decision, never a data migration.
:::

If your override drops the value a block starts with, the block's initial data moves to the first value you *do* offer — so a new block never opens on something absent from its own dropdown. Set `defaults.<field>` to choose that starting value yourself.

### Whether an added value *renders*

Getting a value into the picker is half the job; the other half is the view, and how far it follows depends on what the value does in the markup:

- **Values that only suffix a CSS class follow all the way.** `button.variant` / `size`, `title.size`, `divider.style`, `alert.type`, `list.style`, every `align`, and the `gallery` / `card` layouts all render as `cb-kit-*--<your value>`. Write the matching CSS and you are done — no template override.
- **`image.fit` needs nothing at all**: the value goes straight into `object-fit`, so `fill`, `none` and `scale-down` work as-is.
- **Values that drive behaviour land on a fallback.** The `gallery` slider carries a Stimulus controller, `list` renders `<ol>` only for `numbered`, `alert` glyphs come from the kit's own icon set, and `image` sizes map to fixed pixel widths. An added value keeps its class but takes the default branch; going further means overriding that view template.
- **`title.tag` is closed on purpose.** It becomes the HTML element, so its list stays fixed whatever `choices` says — configuration widens what a host can *style*, never what markup the kit emits. Adding a tag means overriding [the template](https://github.com/klehm/content-blocks-project/blob/master/packages/content-blocks-kit/templates/block/title/view.html.twig), deliberately.

Views pass choice values through the `cb_kit_token()` Twig function, which checks the value's *shape* — a single `[A-Za-z0-9_-]` token — rather than its membership in a list. That is what lets an unknown-but-valid value through while a malformed one (a space that would inject a second class) still falls back.

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
        directory: '%kernel.project_dir%/public/uploads/content-blocks'
        public_prefix: '/uploads/content-blocks'
```

See the core [Host services → File storage](/guide/host-services#file-storage) for S3/Flysystem.
