# ContentBlocks

Modular page builder for Symfony. Build content areas from sections, columns and blocks, with an extensible block-type system.

This package provides the core: entities, admin UI (Live Components + Stimulus), `ContentAreaType` form, and the block-type registry. Use it together with [`klehm/content-blocks-kit`](https://github.com/klehm/content-blocks-kit) for ready-to-use blocks (Text, Title, Image, Tabs).

## Requirements

- PHP >= 8.2 (>= 8.4 for Symfony 8.0)
- Symfony 6.4 LTS, 7.x or 8.x
- Doctrine ORM ^2.12 or ^3.0

## Installation

```bash
composer require klehm/content-blocks klehm/content-blocks-kit
```

## Quick start

Attach a `ContentArea` to your own entity (e.g. `Page`):

```php
use ContentBlocks\Entity\ContentArea;

#[ORM\Entity]
class Page
{
    #[ORM\OneToOne(targetEntity: ContentArea::class, cascade: ['persist', 'remove'])]
    private ?ContentArea $contentArea = null;
}
```

Render the builder in any Symfony form:

```php
$builder->add('contentArea', ContentAreaType::class);
```

## Security setup

ContentBlocks does not know your auth model — implement `AccessCheckerInterface` to control who can edit which `ContentArea`:

```yaml
# config/services.yaml
ContentBlocks\Security\AccessCheckerInterface:
    class: App\Security\PageAccessChecker
```

The default implementation denies all access (secure by default).

## Documentation & contributing

Full documentation and development setup live in the monorepo:
[github.com/klehm/content-blocks-project](https://github.com/klehm/content-blocks-project)

## License

MIT
