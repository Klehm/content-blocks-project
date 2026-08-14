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

Both shapes are available on **every** choice field — the difference is what you want to do, not which field you are on.

```yaml
content_blocks_kit:
    blocks:
        button:
            choices:
                # A LIST restricts: keep these coded values, in this order.
                # Values the block does not code are ignored.
                align: [start, center]

                # A MAP replaces: this becomes the entire choice set, so it can
                # add values the kit never shipped.
                variant:
                    primary: 'cb_kit.block.button.variant.primary'   # a translation key
                    ghost: 'Ghost'                                   # or a literal label
                    brand-outline: 'Contour marque'

                # …and nothing stops you replacing `size` too — sm/md/lg are
                # just the coded defaults, not a fixed vocabulary.
                size:
                    md: 'cb_kit.block.size.normal'
                    jumbo: 'Jumbo'
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

Getting a value into the picker is half the job; the other half is the view. Here is every choice field in the kit and how far an added value travels — the table is [pinned by a test](https://github.com/klehm/content-blocks-project/blob/master/packages/content-blocks-kit/tests/Block/ChoiceFieldCoverageTest.php) that renders each one, so it cannot drift.

| Field | An added value… | You supply |
|---|---|---|
| `button.variant` · `button.size` · `button.align` | renders as `cb-kit-btn--<value>` / `cb-kit-btn-wrap--<value>` | CSS |
| `title.size` | renders as `cb-kit-title--<value>` | CSS |
| `divider.style` | renders as `cb-kit-divider--<value>` | CSS |
| `alert.type` | renders as `cb-kit-alert--<value>`, with the `info` glyph | CSS |
| `list.style` | renders as `cb-kit-list--<value>`, inside `<ul>` | CSS |
| `image.align` · `image.size` | render as `cb-kit-image--<value>` / `cb-kit-image--size-<value>`; an added size has no preset pixel width, so the image goes fluid | CSS |
| `image.fit` · `gallery.fit` | go straight into `object-fit` / `--cb-kit-fit` | **nothing** — `fill`, `none`, `scale-down` work as-is |
| `gallery.layout` · `card.layout` | render as `cb-kit-gallery--<value>` / `cb-kit-cards--<value>`, through the grid markup | CSS |
| `gallery.columns` · `card.columns` | feed `--cb-kit-cols`; must be numeric | **nothing** |
| `icon.align` | renders as `cb-kit-icon--<value>` | CSS |
| **`icon.name`** | **needs a glyph** — see below | an `IconProviderInterface` |
| **`title.tag`** | **does not render** — closed by design | a template override |

Two of them are worth reading in full.

**`title.tag` is closed on purpose.** It becomes the HTML element, so its list stays fixed whatever `choices` says: configuration widens what a host can *style*, never what markup the kit emits. Adding a tag means overriding [the template](https://github.com/klehm/content-blocks-project/blob/master/packages/content-blocks-kit/templates/block/title/view.html.twig), deliberately.

**`icon.name` is extensible, but not through `choices`.** A name without a glyph draws nothing, so listing one in config would give you an empty block. Contribute the icon instead — it shows up in the picker with no config at all:

```php
use ContentBlocks\Kit\Icon\IconProviderInterface;

final class BrandIcons implements IconProviderInterface
{
    public function icons(): array
    {
        // Inner SVG markup only, on a 24×24 viewBox. The wrapper — sizing,
        // currentColor, stroke width — is the kit's, so a contributed glyph
        // looks like it belongs.
        return ['brand-logo' => '<path d="M4 4h16v16H4z"/>'];
    }
}
```

The service is autoconfigured; declaring it is enough. Returning a name the kit already ships **replaces** that glyph, which is how you restyle one icon without touching a template. `choices` then works on top as usual, restricting what the registry produced.

**Behaviour does not follow a class.** The `gallery` slider carries a Stimulus controller, `list` renders `<ol>` only for `numbered`, `alert` glyphs come from the kit's icon set. An added value keeps its class — so it is still stylable — but takes the default branch; going further means overriding that view template.

Views pass choice values through the `cb_kit_token()` Twig function, which checks the value's *shape* — a single `[A-Za-z0-9_-]` token — rather than its membership in a list. That is what lets an unknown-but-valid value through while a malformed one (a space, which would inject a second class) still falls back.

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
