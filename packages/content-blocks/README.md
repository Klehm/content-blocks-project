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
            "cb-section-settings-form": { "enabled": true, "fetch": "eager" },
            "cb-sidebar-tabs":          { "enabled": true, "fetch": "eager" },
            "cb-spacing-link":          { "enabled": true, "fetch": "eager" },
            "cb-viewport-tabs":         { "enabled": true, "fetch": "eager" }
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

#### Public assets loaded inside the preview iframe

The bundle exposes four routes under `/_content-blocks/public/*` that serve the styles and the overlay JS injected into the front-end iframe:

- `/_content-blocks/public/layout` → `text/css` (PUBLIC + PREVIEW)
- `/_content-blocks/public/styling` → `text/css` (PUBLIC + PREVIEW)
- `/_content-blocks/public/builder` → `text/css` (PREVIEW only)
- `/_content-blocks/public/preview-overlay` → `application/javascript` (PREVIEW only)

The render template injects these `<link>` and `<script>` tags itself, so the host has nothing to wire. They are deliberately split out from the admin endpoints (`/_content-blocks/sections/*`, `/_content-blocks/blocks/*`, `/_content-blocks/upload`) so a host can lock the admin endpoints down without 404-ing the iframe assets — see [Firewalls & access control](#firewalls--access-control) below.

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

### Render the ContentArea on the public page

**This step is required** — without it the builder iframe loads a page with no editable markers, so add-section trays, block toolbars and the preview overlay never appear.

The builder is a thin shell that opens the host's **public** URL inside an iframe. All the in-context editing UI (section/block guides, "+ section" tray, overlay script) is injected by the package's render template **inside that public page**, so the public template must call `cb_render_content_area()` to produce the markers Stimulus controllers attach to:

```twig
{# templates/page/show.html.twig — your public template #}
<article>
    <h1>{{ page.title }}</h1>
    {{ cb_render_content_area(page.contentArea) }}
</article>
```

`cb_render_content_area()` accepts `null` and renders an empty string in that case, so you don't need an `{% if page.contentArea %}` guard around it when the host entity may not yet have a linked area.

Render-mode is auto-detected from the request: a query string `?cb_preview=1` combined with `AccessCheckerInterface::canEdit()` granting access switches to **preview** mode (markers + overlay injected); anything else falls through to **public** mode (clean published HTML, no markers).

### Overriding render templates

The render pipeline is split into four templates so you can override the markup of an individual level (section, column, block) without forking the whole entry-point. Drop a file at the same relative path under `templates/bundles/ContentBlocksBundle/` in your host app to override one.

> Requires `klehm/content-blocks >= 0.1.0-alpha.4` for overrides to take priority. Earlier versions manually registered the vendor `templates/` path under `@ContentBlocks`, which (counter-intuitively) shadowed the host's `templates/bundles/ContentBlocksBundle/` directory.

| Template | Receives | Responsibility |
|---|---|---|
| `@ContentBlocks/render/content_area.html.twig` | `sections` (array), `mode` (`RenderMode`), `blockTypes` (array) | Top-level wrapper, layout/builder CSS `<link>`s, sections loop, preview-only section tray + overlay scripts. |
| `@ContentBlocks/render/section.html.twig` | `section` (`Section`), `isPreview` (bool) | `<section class="cb-section …">` element, inline styles + extra attributes from section decorators, columns loop. |
| `@ContentBlocks/render/column.html.twig` | `column` (`Column`), `isPreview` (bool) | `<div class="cb-col …">` element, blocks loop, preview-only "+ block" inline button. |
| `@ContentBlocks/render/block.html.twig` | `block` (`Block`), `isPreview` (bool) | `<div class="cb-block …">` element, include of `block.viewTemplate` with `data`. |

Sub-templates are included with `with_context = false` — the listed variables are the contract; anything else from the parent scope is not available.

If you override `section`/`column`/`block`, keep the existing `cb-*` classes and `data-cb-*` attributes intact. The builder's Stimulus controllers and the preview-overlay script attach to those selectors; renaming them breaks the in-context editing UI.

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

### `ContentAreaProviderInterface` — replace-content picker (optional)

The builder's **Insert content** button (topbar) lets editors overwrite the current area with the content of any other `ContentArea` in the system. The picker is populated by a host-provided query so users see meaningful labels (page title, slug, last edit…) instead of opaque ids.

A default implementation ships with the bundle: it searches by id and labels rows as `#<id> — <updatedAt>`. It works out of the box but is rarely the right UX — implement the interface and alias it in your `services.yaml` to surface what your editors actually search on:

```yaml
# config/services.yaml
ContentBlocks\Replace\ContentAreaProviderInterface:
    class: App\ContentBlocks\PageContentAreaProvider
```

```php
use App\Entity\Page;
use ContentBlocks\Entity\ContentArea;
use ContentBlocks\Replace\ContentAreaProviderInterface;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;

final class PageContentAreaProvider implements ContentAreaProviderInterface
{
    public function __construct(private readonly EntityManagerInterface $em) {}

    public function createQueryBuilder(?string $filter): QueryBuilder
    {
        // Join through the host's owning entity (Page) so the picker can
        // search on title + return only areas that have a real Page parent.
        $qb = $this->em->createQueryBuilder()
            ->select('a')
            ->from(ContentArea::class, 'a')
            ->innerJoin(Page::class, 'p', 'WITH', 'p.contentArea = a');

        if ($filter !== null && $filter !== '') {
            $qb->andWhere('p.title LIKE :q')->setParameter('q', '%' . $filter . '%');
        }

        return $qb;
    }

    public function getLabel(ContentArea $area): string
    {
        $page = $this->em->getRepository(Page::class)->findOneBy(['contentArea' => $area]);
        if (!$page) {
            return '#' . $area->getId();
        }
        $when = $area->getUpdatedAt()?->format('Y-m-d') ?? '—';

        return sprintf('%s — %s', $page->getTitle(), $when);
    }
}
```

The controller appends ordering (`updatedAt DESC` then `id DESC`) and pagination (10 items + 1 sentinel for `hasMore`); the target area is always excluded from results. `ContentArea::updatedAt` is touched by a Doctrine `onFlush` listener whenever any descendant Section / Column / Block changes — your provider does not need to maintain it.

The replace itself writes to the **draft** state on the target: existing sections are soft-deleted and clones of the source's sections are inserted. The user then publishes (commits the swap) or discards (restores the original content).

### File storage (optional, only if your blocks accept uploads)

```yaml
ContentBlocks\Storage\FileStorageInterface:
    class: ContentBlocks\Storage\LocalFileStorage
    arguments:
        $uploadDir: '%kernel.project_dir%/public/uploads/content-blocks'
        $publicPrefix: '/uploads/content-blocks'
```

## Styling sections and blocks

Each section's settings sidebar carries a **Styling** tab with padding, margin (per viewport), background color, min-height and alignment. Block edit forms carry the same tab with padding, margin, background color and max-width.

These fields land in JSON under `settings.styling` for sections and `data.styling` for blocks. They are stored as-is — no DB migration; existing content keeps working untouched.

At render time, two decorators (`StylingSectionDecorator`, `StylingBlockDecorator`) translate the values into **CSS custom properties** on the outer element, and a stylesheet shipped at `/_content-blocks/public/styling` maps those vars to real properties with `@media` rules for tablet (`max-width: 991px`) and mobile (`max-width: 575px`) — so per-viewport overrides actually work (inline `style` can't carry media queries).

The fallback chain inside each `@media` block is: mobile → tablet → desktop → 0. A viewport you leave blank inherits the next-wider one.

### Extending the Styling sub-form

The `StylingType` form holds the styling fields. Register a Symfony `FormTypeExtension` against it to inject or override fields without forking — they will render inside the sidebar's **Styling** tab:

```php
use ContentBlocks\Form\Type\Styling\StylingType;
use Symfony\Component\Form\AbstractTypeExtension;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\FormBuilderInterface;

final class BrandPaletteExtension extends AbstractTypeExtension
{
    public static function getExtendedTypes(): iterable
    {
        return [StylingType::class];
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        // Re-adding an existing field overrides it — here we replace the
        // raw HTML5 ColorType with a curated brand palette.
        $builder->add('backgroundColor', ChoiceType::class, [
            'required' => false,
            'choices' => [
                'Brand / Primary' => '#0a84ff',
                'Brand / Accent' => '#ff375f',
            ],
        ]);
    }
}
```

See the sandbox at [apps/content-blocks-sandbox/src/Form/Extension/StylingPaletteExtension.php](apps/content-blocks-sandbox/src/Form/Extension/StylingPaletteExtension.php) for a runnable example.

### Adding your own block decorator

Implement `ContentBlocks\Block\BlockDecoratorInterface` (mirror of `SectionDecoratorInterface`). It is auto-tagged with `content_blocks.block_decorator` when `autoconfigure: true` is on, and called for every block being rendered. Return a `BlockDecoration` (classes / inline styles / attributes) — the bundle merges all decorators' output into the block's outer `<div>`.

## Security notes

### CSRF

AJAX endpoints (`/_content-blocks/*`) require an `X-CSRF-Token` header bound to the token id `content_blocks`. Stimulus controllers read it from a `data-cb-csrf-token` attribute rendered by the bundle. Your app needs:

- `framework.session: true` (CSRF tokens are session-bound)
- `framework.csrf_protection.enabled: true`

### Firewalls & access control

The bundle exposes two URL families with different exposure:

| Path prefix | Audience | Mode |
|---|---|---|
| `/_content-blocks/public/*` | Anyone (loaded inside the public iframe) | Public |
| `/_content-blocks/*` (everything else) | Authenticated admin (block CRUD, section CRUD, sidebars, upload) | Admin-only |

The public sub-prefix is intentional: it lets you lock the admin endpoints down without breaking the iframe's CSS and overlay JS.

**With a single firewall**, an `access_control` split is enough:

```yaml
# config/packages/security.yaml
security:
    access_control:
        - { path: ^/_content-blocks/public, roles: PUBLIC_ACCESS }
        - { path: ^/_content-blocks,        roles: ROLE_ADMIN }
```

**With separate admin and front-office firewalls**, extend the admin firewall's pattern to cover the admin endpoints (and exclude the public sub-prefix), otherwise the builder's AJAX calls run unauthenticated:

```yaml
security:
    firewalls:
        admin:
            pattern: ^/(admin|_content-blocks(?!/public))
            # ...
        main:
            # public site — handles the iframe URL, no admin auth here
            pattern: ^/
```

#### Cross-firewall auth detection in `AccessCheckerInterface`

The render template auto-detects preview mode by calling `AccessCheckerInterface::canEdit()` while serving the public URL — i.e. the request passes through the **public/main** firewall, but the user authenticated against the **admin** firewall. With separate firewall contexts (`context: admin`), Symfony's standard `Security::isGranted()` will not see the admin token from the main firewall and the iframe falls back to public mode (no editing UI, even when an admin opens the builder).

If your firewalls use isolated contexts, the access checker has to read the admin token directly from the session:

```php
use ContentBlocks\Security\AccessCheckerInterface;
use ContentBlocks\Entity\ContentArea;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;

final class PageAccessChecker implements AccessCheckerInterface
{
    public function __construct(
        private readonly TokenStorageInterface $tokens,
        private readonly RequestStack $requests,
    ) {}

    public function canEdit(ContentArea $contentArea): bool
    {
        return $this->isAdmin() && $this->ownsArea($contentArea);
    }

    public function canView(ContentArea $contentArea): bool { return true; }

    private function isAdmin(): bool
    {
        // 1) Standard path: a token is in the current firewall's storage.
        $token = $this->tokens->getToken();
        if ($token && \in_array('ROLE_ADMIN', $token->getRoleNames(), true)) {
            return true;
        }

        // 2) Cross-firewall fallback: the iframe runs under the public
        // firewall, so the admin token isn't visible via $tokens. Read
        // the serialized admin token from the session directly. The key
        // is `_security_<context_or_firewall_name>` — `_security_admin`
        // when `context: admin` or the firewall name is `admin`.
        $request = $this->requests->getMainRequest();
        if (!$request || !$request->hasSession()) {
            return false;
        }

        $serialized = $request->getSession()->get('_security_admin');
        if (!\is_string($serialized)) {
            return false;
        }

        $adminToken = unserialize($serialized);
        return $adminToken instanceof TokenInterface
            && \in_array('ROLE_ADMIN', $adminToken->getRoleNames(), true);
    }

    private function ownsArea(ContentArea $area): bool
    {
        // your app's ownership check
    }
}
```

## Known install-time warnings

`composer audit` may flag `doctrine/annotations` as abandoned. This package does **not** require `doctrine/annotations` — the warning comes from your host project (typically pulled in by an older Symfony Framework Bundle setup or a legacy Doctrine config). Remove it with `composer remove doctrine/annotations` and set `framework.annotations: false` in your config if your app no longer uses annotation-based metadata.

## Documentation & contributing

Full development setup, sandbox apps, and JS test suite live in the monorepo:
[github.com/klehm/content-blocks-project](https://github.com/klehm/content-blocks-project)

## License

MIT
