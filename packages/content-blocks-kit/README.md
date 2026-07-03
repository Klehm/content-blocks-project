# ContentBlocks Kit

Ready-to-use block types for [`klehm/content-blocks`](https://github.com/klehm/content-blocks).

## Included blocks

| Type | Class |
|---|---|
| `text` | `TextBlock` |
| `title` | `TitleBlock` |
| `rich_text` | `RichTextBlock` |
| `image` | `ImageBlock` |
| `tabs` | `TabsBlock` |

## Installation

```bash
composer require klehm/content-blocks klehm/content-blocks-kit
```

The blocks are auto-registered via Symfony autoconfiguration (`#[AsContentBlock]` attribute) — no extra configuration needed.

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
