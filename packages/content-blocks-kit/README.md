# ContentBlocks Kit

Ready-to-use block types for [`klehm/content-blocks`](https://github.com/klehm/content-blocks).

The kit is **self-contained**: no Tailwind/Bootstrap, no LiipImagine, no icon
library. Every block renders neutral `cb-kit-*` markup styled by a single
shipped stylesheet, so it drops into any host regardless of its CSS setup.

## Included blocks

| Type | What it is |
|---|---|
| `title` | Heading with tag choice |
| `text` | Plain paragraph text |
| `rich_text` | WYSIWYG (TinyMCE) rich text |
| `image` | Image with size preset / custom size, fit, align, link, caption, rounded corners |
| `gallery` | Image grid or slider (arrows) with columns, fit, rounded corners |
| `button` | Call-to-action button (variants, sizes, alignment) |
| `card` | Image/title/text/button tiles as a grid or list |
| `list` | Bulleted / checkmark / numbered list |
| `icon` | A single icon from the shipped icon set |
| `alert` | Info / success / warning / error callout |
| `divider` | Horizontal rule (style + color) |
| `accordion` | Collapsible panels (native `<details>`, zero JS) |
| `table` | Columns + rows data table |
| `embed` | Responsive YouTube / Vimeo embed |
| `breadcrumb` | Breadcrumb trail |
| `html_raw` | Raw HTML escape hatch |
| `tabs` | Tabbed panels |

## Installation

```bash
composer require klehm/content-blocks klehm/content-blocks-kit
```

The blocks are auto-registered via Symfony autoconfiguration — no config needed
to get all of them.

### Front stylesheet (required)

Kit blocks render with neutral `cb-kit-*` classes styled by a stylesheet the kit
serves at a public route. Include it once in your front layout (it also flows
into the builder preview):

```twig
<link rel="stylesheet" href="{{ path('content_blocks_kit_asset_css') }}">
```

Retheme by overriding the `--cb-kit-*` custom properties (or the classes) in
your own stylesheet loaded after it.

### Stimulus controllers

Enable the kit's controllers in your host `assets/controllers.json` under the
`@klehm/content-blocks-kit` package: `cb-tinymce` (rich text) and `cb-gallery`
(gallery slider).

## Configuring blocks

Each block exposes four levers under `content_blocks_kit.blocks.<type>`:

| Key        | Purpose                                                                    |
|------------|----------------------------------------------------------------------------|
| `enabled`  | `false` un-registers the block's service — it never reaches the picker.     |
| `options`  | Block-level knobs (e.g. `max_columns`), merged over the block's coded ones. |
| `choices`  | Per-field allow-list restricting/reordering a `ChoiceType` field.           |
| `defaults` | Per-field overrides of a block's initial data (what a new block starts with).|

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

Notes:

- Blocks omitted from config are enabled with their coded defaults — **except**
  `html_raw`, which is **disabled by default**: it renders unescaped markup
  (`{{ html|raw }}`), so it trusts its editors and must be opted in explicitly.
- `choices` values not offered by the block are ignored; an empty or all-invalid
  list falls back to the full set (the select is never empty). Restricting the
  picker does **not** invalidate content already stored with a now-hidden value —
  validation still accepts the block's full coded set.
- `defaults` only apply to fields the block declares; unknown keys are ignored.

### Colors

All color fields — icon and divider colors, the **title** and **text** blocks'
text color, and the rich-text (TinyMCE) swatches — draw from the **one** core
palette declared in `content_blocks.palette` (see the main package README). Add a
named color there once and it appears everywhere:

```yaml
# config/packages/content_blocks.yaml
content_blocks:
    palette:
        - { label: 'Brand', color: '#eb0540' }
```

### Discovering the surface

List every block with its options, choice fields (default marked `*`) and data
defaults — read straight from the code, so it never goes stale:

```bash
bin/console content-blocks-kit:blocks          # all blocks
bin/console content-blocks-kit:blocks button   # one block
```

## Overriding block templates

Drop a file at the matching relative path under `templates/bundles/ContentBlocksKitBundle/` to override any template shipped by this kit — e.g. `templates/bundles/ContentBlocksKitBundle/block/image/view.html.twig` overrides the image view.

> Requires `klehm/content-blocks-kit >= 0.1.0-alpha.4` for overrides to take priority. Earlier versions manually registered the vendor `templates/` path under `@ContentBlocksKit`, which shadowed the host's `templates/bundles/ContentBlocksKitBundle/` directory.

## File uploads

`ImageBlock` uses the main package's upload brick (`ImageUploadType`, the
`/_content-blocks/upload` endpoint and `FileStorageInterface` — all in
`klehm/content-blocks` now). Enable it via the bundle config:

```yaml
# config/packages/content_blocks.yaml
content_blocks:
    upload:
        dir: '%kernel.project_dir%/public/uploads/content-blocks'
        public_prefix: '/uploads/content-blocks'
```

## Documentation & contributing

Full documentation and development setup live in the monorepo:
[github.com/klehm/content-blocks-project](https://github.com/klehm/content-blocks-project)

## License

MIT
