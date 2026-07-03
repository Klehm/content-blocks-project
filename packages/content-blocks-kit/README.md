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

## Enabling / disabling blocks & options

Every block can be turned off, and some accept options, via bundle config:

```yaml
# config/packages/content_blocks_kit.yaml
content_blocks_kit:
    blocks:
        html_raw: { enabled: false }        # drop a block entirely
        gallery:
            enabled: true
            options: { max_columns: 4 }     # cap the column choices
        card:
            options: { max_columns: 3 }
```

Blocks omitted from config are enabled with their default options. Disabling a
block un-registers its service, so it never appears in the block picker.

Custom colors (icon, divider, and rich-text swatches) come from the core
`content_blocks.palette` config — see the main package README.

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
