# ContentBlocks

Modular page builder for Symfony. Build content areas from sections, columns and blocks, with an extensible block-type system.

This package provides the core: entities, admin UI (Live Components + Stimulus), `ContentAreaType` form, and the block-type registry. Use it together with [`klehm/content-blocks-kit`](https://github.com/klehm/content-blocks-kit) for ready-to-use blocks (Text, Title, Image, Tabs).

## Requirements

- PHP >= 8.2 (>= 8.4 for Symfony 8.0)
- Symfony 6.4 LTS, 7.x or 8.x
- Doctrine ORM ^2.12 or ^3.0

## Installation

The package is not tagged yet. Until `0.1.0-alpha` ships, install the dev branch:

```bash
composer require klehm/content-blocks:dev-main klehm/content-blocks-kit:dev-main
```

If your project uses `minimum-stability: stable`, either lower it to `dev` (with `prefer-stable: true`) or add the `:dev-main` constraint as shown above.

### Register the bundle

The bundle is detected by Symfony Flex — but until a Flex recipe is published, add it manually:

```php
// config/bundles.php
return [
    // ...
    ContentBlocks\ContentBlocksBundle::class => ['all' => true],
    ContentBlocks\Kit\ContentBlocksKitBundle::class => ['all' => true],
];
```

### Mount the routes

The `/_content-blocks/*` AJAX endpoints (block CRUD, section reorder, file upload) are not mounted automatically:

```yaml
# config/routes/content_blocks.yaml
content_blocks:
    resource: '@ContentBlocksBundle/config/routes.php'
```

### Database schema

This package ships Doctrine entities (`cb_content_area`, `cb_section`, `cb_column`, `cb_block`) but no migrations — generate them in your own pipeline:

```bash
php bin/console doctrine:migrations:diff
php bin/console doctrine:migrations:migrate
```

Or, for a brand-new database:

```bash
php bin/console doctrine:schema:update --force
```

## Quick start

Attach a `ContentArea` to your own entity (e.g. `Page`). The `cascade: ['persist', 'remove']` is required — `ContentAreaType` returns a transient `ContentArea` on submit and relies on cascade to commit it together with the host entity:

```php
use ContentBlocks\Entity\ContentArea;

#[ORM\Entity]
class Page
{
    #[ORM\OneToOne(targetEntity: ContentArea::class, cascade: ['persist', 'remove'])]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?ContentArea $contentArea = null;
}
```

Render the builder in any Symfony form:

```php
$builder->add('contentArea', ContentAreaType::class);
```

### Lifecycle

`ContentAreaType` does **not** write to the database on a `GET` request. If the host entity has no `ContentArea` yet (new entity, or legacy data), the widget renders a "save first" placeholder instead of the builder. Once the form is submitted and the host entity is persisted, the next edit shows the builder normally.

## Required host services

Two interfaces have no useful default and **must** be configured by the host app:

### `AccessCheckerInterface` — authorization

ContentBlocks does not know your auth model. The default (`DenyAllAccessChecker`) blocks every mutation. Provide your own:

```yaml
# config/services.yaml
ContentBlocks\Security\AccessCheckerInterface:
    class: App\Security\PageAccessChecker
```

```php
use ContentBlocks\Security\AccessCheckerInterface;
use ContentBlocks\Entity\ContentArea;

final class PageAccessChecker implements AccessCheckerInterface
{
    public function canEdit(ContentArea $contentArea): bool
    {
        // Check that the current user owns the Page linked to this ContentArea
    }

    public function canView(ContentArea $contentArea): bool
    {
        return true;
    }
}
```

### `ContentAreaUrlResolverInterface` — preview URL

The builder shell loads the public page in an iframe to preview edits in context. The resolver maps a `ContentArea` back to the host's public URL. The default (`NullContentAreaUrlResolver`) throws — without a real implementation, rendering the widget fails:

```yaml
# config/services.yaml
ContentBlocks\Preview\ContentAreaUrlResolverInterface:
    class: App\Preview\PageContentAreaUrlResolver
```

```php
use ContentBlocks\Entity\ContentArea;
use ContentBlocks\Preview\ContentAreaUrlResolverInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class PageContentAreaUrlResolver implements ContentAreaUrlResolverInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UrlGeneratorInterface $urls,
    ) {}

    public function resolve(ContentArea $area): string
    {
        $page = $this->em->getRepository(Page::class)->findOneBy(['contentArea' => $area]);
        if (!$page) {
            // Fallback while the parent entity is being created and is not yet linked
            return $this->urls->generate('app_home');
        }

        return $this->urls->generate('app_page_show', ['id' => $page->getId()]);
    }
}
```

### File storage (optional, only if your blocks accept uploads)

```yaml
ContentBlocks\Storage\FileStorageInterface:
    class: ContentBlocks\Storage\LocalFileStorage
    arguments:
        $uploadDir: '%kernel.project_dir%/public/uploads/content-blocks'
        $publicPrefix: '/uploads/content-blocks'
```

## Security notes

### CSRF

AJAX endpoints (`/_content-blocks/*`) require an `X-CSRF-Token` header bound to the token id `content_blocks`. Stimulus controllers read it from a `data-cb-csrf-token` attribute rendered by the bundle. Your app needs:

- `framework.session: true` (CSRF tokens are session-bound)
- `framework.csrf_protection.enabled: true`

### Firewalls

If your admin area is behind a firewall **separate** from the front-office, extend that firewall's pattern to cover `/_content-blocks/*` — otherwise the builder's AJAX calls run unauthenticated and lose the user's session:

```yaml
# config/packages/security.yaml
security:
    firewalls:
        admin:
            pattern: ^/(admin|_content-blocks)
            # ...
```

## Documentation & contributing

Full development setup, sandbox apps, and JS test suite live in the monorepo:
[github.com/klehm/content-blocks-project](https://github.com/klehm/content-blocks-project)

## License

MIT
