# ContentBlocks Kit

Ready-to-use block types for [`klehm/content-blocks`](https://github.com/klehm/content-blocks).

## Included blocks

| Type | Class |
|---|---|
| `text` | `TextBlock` |
| `title` | `TitleBlock` |
| `image` | `ImageBlock` |
| `tabs` | `TabsBlock` |

## Installation

```bash
composer require klehm/content-blocks klehm/content-blocks-kit
```

The blocks are auto-registered via Symfony autoconfiguration (`#[AsContentBlock]` attribute) — no extra configuration needed.

## Overriding block templates

Drop a file at the matching relative path under `templates/bundles/ContentBlocksKitBundle/` to override any template shipped by this kit — e.g. `templates/bundles/ContentBlocksKitBundle/block/image/view.html.twig` overrides the image view, `templates/bundles/ContentBlocksKitBundle/form/image_theme.html.twig` overrides the upload widget.

> Requires `klehm/content-blocks-kit >= 0.1.0-alpha.4` for overrides to take priority. Earlier versions manually registered the vendor `templates/` path under `@ContentBlocksKit`, which shadowed the host's `templates/bundles/ContentBlocksKitBundle/` directory.

## File uploads

`ImageBlock` requires a `FileStorageInterface` implementation. The kit ships with `LocalFileStorage`:

```yaml
# config/services.yaml
ContentBlocks\Storage\FileStorageInterface:
    class: ContentBlocks\Storage\LocalFileStorage
    arguments:
        $uploadDir: '%kernel.project_dir%/public/uploads/content-blocks'
        $publicPrefix: '/uploads/content-blocks'
```

## Documentation & contributing

Full documentation and development setup live in the monorepo:
[github.com/klehm/content-blocks-project](https://github.com/klehm/content-blocks-project)

## License

MIT
