---
title: Host services
---

# Host services

ContentBlocks stays agnostic about your application's auth model, URL routing and storage. Two interfaces have no useful default and **must** be wired; the rest are optional refinements.

## Required host services

### `AccessCheckerInterface` — authorization

::: danger Secure by default
ContentBlocks does not know your auth model. The default (`DenyAllAccessChecker`) blocks **every** mutation. You must provide your own implementation, or the builder is inert.
:::

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

`canEdit()` is called by every controller and Live Component before any mutation, and is also what [preview-mode detection](./rendering.md#preview-vs-public-mode) hinges on. If your admin and front-office live behind separate firewalls, read the [cross-firewall auth detection](./security.md#cross-firewall-auth-detection) note carefully.

### `ContentAreaUrlResolverInterface` — preview URL

The builder shell loads the public page in an iframe to preview edits in context. The resolver maps a `ContentArea` back to the host's public URL.

::: warning
The default (`NullContentAreaUrlResolver`) **throws** — without a real implementation, rendering the widget fails.
:::

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

## Optional host services

### `ContentAreaProviderInterface` — replace-content picker

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

### File storage

_(Only needed if your blocks accept uploads.)_

The quickest opt-in is the bundle config — it registers a `LocalFileStorage` and enables the `/_content-blocks/upload` endpoint (CSRF-guarded, size-capped, MIME-whitelisted):

```yaml
# config/packages/content_blocks.yaml
content_blocks:
    upload:
        dir: '%kernel.project_dir%/public/uploads/content-blocks'
        public_prefix: '/uploads/content-blocks'
        # max_size: 10485760                       # bytes, default 10 MB
        # allowed_mime_types: ['image/jpeg', ...]  # default: common images + PDF
```

For S3/Flysystem/CDN storage, alias the interface to your own implementation instead:

```yaml
ContentBlocks\Storage\FileStorageInterface:
    class: App\Storage\S3FileStorage
```

Block forms get upload UI for free through `ImageUploadType` (file picker + preview around a hidden path input, wired to the `cb-file-upload` controller):

```php
use ContentBlocks\Form\Type\ImageUploadType;

$builder->add('src', ImageUploadType::class);
```

## Customizing default values

A few section and block fields ship with a baked-in default so the form always presents a usable value and the renderer can fall back when the user leaves a field empty. The two surfaces (form pre-fill + renderer fallback) read the **same source**, so changing the default in one place keeps them in sync.

### Section `maxWidth` (built-in)

When the user picks **Centered** width without typing a number, the section is capped at **1320px**. The same value pre-fills the input box and shows up as the placeholder. Typing `0` explicitly opts out of any cap.

The number is exposed as a service parameter — the simplest override is one line of YAML:

```yaml
# config/services.yaml
parameters:
    content_blocks.section.default_max_width: 1400
```

Both `BuiltInSectionDecorator` and `CoreSectionDefaults` are bound to this parameter, so the form pre-fill, placeholder, and rendered fallback all move together.

::: info Note on centered sections
The cap is applied to the inner `.cb-row`, not the `<section>` element — so a centered section's **background spans the full viewport width** while its content stays contained (the standard full-bleed pattern). The cap is emitted as a `--cb-row-max-w` custom property read by `layout.css`.
:::

### Default section width mode (built-in)

New sections start in **full** width by default. To make every new section start **centered** project-wide, set the width mode default — via a parameter or the `CONTENT_BLOCKS` env layer:

```yaml
# config/services.yaml
parameters:
    content_blocks.section.default_width_mode: centered   # 'full' (default) | 'centered'
    content_blocks.section.default_max_width: 1140         # the cap centered sections use
```

Like `maxWidth`, this drives the form radio pre-selection (`SectionSettingsType`), the defaults provider (`CoreSectionDefaults`), and the render fallback (`BuiltInSectionDecorator`) in lock-step.

### Adding (or overriding) defaults via a provider

For multi-key defaults, nested values, or anything computed at runtime, register a `SectionSettingsDefaultsProviderInterface`:

```php
use ContentBlocks\Section\SectionSettingsDefaultsProviderInterface;

final class AppSectionDefaults implements SectionSettingsDefaultsProviderInterface
{
    public function getDefaults(): array
    {
        return [
            // Top-level section setting.
            'maxWidth' => 1400,
            // Nested under the Styling sub-form (deep-merged).
            'styling' => [
                'backgroundColor' => '#f7f7f7',
            ],
        ];
    }
}
```

The interface is autoconfigured — no tag needed. All providers are aggregated via `array_replace_recursive`, **later providers win on key conflict**, so a host provider always overrides `CoreSectionDefaults` / `CoreStylingDefaults`.

At render time, values **equal to the default are stripped** from the saved settings before the decorator pipeline runs (`SectionSettingsDefaults::withoutDefaults()`) — so a section saved with the default cap produces no inline `max-width` style; only user-overridden values do. The decorator re-applies the default itself when the key is missing.

### Block-side equivalent

For block defaults, implement `ContentBlocks\Block\BlockDataDefaultsProviderInterface` (mirror of the section interface). It's the same pattern: form pre-fill + `BlockDataDefaults::withoutDefaults()` at render. The package's `CoreBlockStylingDefaults` sets `styling.backgroundColor = ''` (transparent) — override it the same way if your project wants a different starting background.

## Toggling topbar features (Insert content, Import / Export)

The builder topbar ships two optional features:

- **Insert content** (`⇆`) — overwrite the area's content with a clone of another area's content (the replace-content flow).
- **Import / Export** (`⇅`) — export a `ContentArea` to a self-contained JSON file (sections + blocks + base64-encoded assets) and re-import it elsewhere.

Both are **on by default** and are toggled **per field**, via `ContentAreaType` options — so the host picks its own strategy per form (an admin form can keep them, a lighter editor can drop them):

```php
$builder->add('contentArea', ContentAreaType::class, [
    'enable_replace' => false,        // hide the "Insert content" button + picker
    'enable_import_export' => false,  // hide the Import / Export button + overlay
]);
```

::: warning UI-only toggles
Both options are **UI-only**: they hide the topbar button and its overlay. The underlying endpoints (`…/replace-with`, `…/export`, `…/import`) stay reachable and remain protected by your `AccessCheckerInterface` (and CSRF for writes). If you need to close the endpoints server-side too, gate them with your firewall or `AccessChecker` — the form option does not, by design, since the route has no per-form context.
:::
