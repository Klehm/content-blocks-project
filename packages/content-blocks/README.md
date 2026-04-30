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

### Bundle registration & routes

If you use Symfony Flex, the auto-generated recipe registers both bundles in `config/bundles.php` and creates a `config/routes/content_blocks.yaml` that mounts the `/_content-blocks/*` AJAX endpoints (block CRUD, section reorder, file upload). Nothing to do.

If you don't use Flex, add them manually:

```php
// config/bundles.php
return [
    // ...
    ContentBlocks\ContentBlocksBundle::class => ['all' => true],
    ContentBlocks\Kit\ContentBlocksKitBundle::class => ['all' => true],
];
```

```yaml
# config/routes/content_blocks.yaml
content_blocks:
    resource: '@ContentBlocksBundle/config/routes.php'
```

### Stimulus controllers & admin CSS (required, manual until a Flex recipe ships)

The host's Symfony Stimulus Bundle reads `assets/controllers.json` from your project — it does **not** auto-discover controllers shipped by third-party packages. Without an entry for each controller, the builder UI loads no JS and the "Edit content" button does nothing.

Add the following to `assets/controllers.json`:

```json
{
    "controllers": {
        "@klehm/content-blocks": {
            "cb-builder-launcher": {
                "enabled": true,
                "fetch": "eager",
                "autoimport": {
                    "@klehm/content-blocks/styles/admin.css": true
                }
            },
            "cb-builder":               { "enabled": true, "fetch": "eager" },
            "cb-block-edit-keys":       { "enabled": true, "fetch": "eager" },
            "cb-section-settings-form": { "enabled": true, "fetch": "eager" }
        },
        "@klehm/content-blocks-kit": {
            "cb-file-upload": { "enabled": true, "fetch": "eager" }
        }
    },
    "entrypoints": []
}
```

Then re-run `php bin/console asset-map:compile` (or your normal asset build).

The `autoimport` block on `cb-builder-launcher` pulls in `admin.css` (styles for the launcher button, builder dialog and sidebars). You do **not** need to add `import '@klehm/content-blocks/styles/admin.css'` in `app.js` — Stimulus Bundle handles it once the entry above is in place.

> A Symfony Flex recipe that injects this whole block automatically is on the roadmap — once published, this manual step goes away.

#### CSS loaded inside the preview iframe

Two other stylesheets — `builder.css` (preview-only overlays) and `layout.css` (front-end section/column grid) — are **not** loaded via AssetMapper. They are served by the bundle's controllers at stable URLs:

- `/_content-blocks/assets/layout` → `text/css` (PUBLIC + PREVIEW)
- `/_content-blocks/assets/builder` → `text/css` (PREVIEW only)

The render template injects these `<link>` tags itself when rendering a `ContentArea`, so **the host has nothing to wire** for those — they live inside the preview iframe, not in the admin page.

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

## Known install-time warnings

`composer audit` may flag `doctrine/annotations` as abandoned. This package does **not** require `doctrine/annotations` — the warning comes from your host project (typically pulled in by an older Symfony Framework Bundle setup or a legacy Doctrine config). Remove it with `composer remove doctrine/annotations` and set `framework.annotations: false` in your config if your app no longer uses annotation-based metadata.

## Documentation & contributing

Full development setup, sandbox apps, and JS test suite live in the monorepo:
[github.com/klehm/content-blocks-project](https://github.com/klehm/content-blocks-project)

## License

MIT
