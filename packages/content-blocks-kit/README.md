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
