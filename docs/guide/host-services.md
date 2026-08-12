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
        directory: '%kernel.project_dir%/public/uploads/content-blocks'
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

The widget renders a dashed frame around the preview, a **Choose an image** button, a **Remove** button (only once there is a value) and a link toggle that reveals a raw path field.

- **Drop** — the whole widget is a drop zone: a file dropped anywhere on it goes through the same endpoint, the same CSRF token and the same limits as one picked from the dialog. A drop is filtered client-side against the field's own `accept` (`'accept' => 'image/png,image/webp'` on the type narrows both the dialog and the drop), but that is a courtesy — the server remains the gate.
- **Paste a path** — the link toggle is the escape hatch for an image that already exists: a media library the host fills by other means, an asset migrated from a previous CMS, a URL on a CDN. Nothing is uploaded; the typed value is stored as-is, with one normalization: an absolute URL **on the builder's own origin** is stored as its path (`https://your-site/uploads/a.jpg` → `/uploads/a.jpg`), because the path is what survives a domain change. Foreign URLs and relative paths are left exactly as typed.

  Two consequences worth knowing. A value pointing outside your `FileStorageInterface` is invisible to [export/import](./recipes/index.md): `FileStorageAssetResolver` only bundles assets it owns, so an external URL travels as a bare string and resolves only where that URL resolves. And since the value is a free string, it is worth constraining if your blocks should only ever reference your own storage — the block's form is the whitelist, as always:

  ```php
  $builder->add('src', ImageUploadType::class, [
      'constraints' => [new Assert\Regex('#^/uploads/#')],
  ]);
  ```
- **Remove** — clears the reference. The stored file is left alone; ContentBlocks never deletes from storage on its own.

### `ImageUrlResolverInterface` — responsive images

_(Only needed if you want smaller image bytes.)_

ContentBlocks ships no image processing: an uploaded file is served as stored, and only its *display box* is controlled by CSS. That already avoids layout shift and lazy-loads below the fold, but it never shrinks a 4000px photo dropped into a 400px card — and doing so requires either an image library (LiipImagine, Glide, GD, Imagick) or a transforming CDN, neither of which belongs in this package's dependencies.

So it is a seam with a passthrough default. Wire your own implementation and every image the kit renders gains `srcset`/`sizes`, with no template override:

```php
use ContentBlocks\Image\ImageUrlResolverInterface;
use ContentBlocks\Image\ResolvedImage;

final class CloudflareImageResolver implements ImageUrlResolverInterface
{
    public function resolve(string $src, ?int $width = null, ?int $height = null): ResolvedImage
    {
        // Not one of ours (an absolute URL an editor pasted, say) — pass it through.
        if (!str_starts_with($src, '/uploads/')) {
            return new ResolvedImage($src);
        }

        $variant = static fn (int $w): string => sprintf('/cdn-cgi/image/width=%d,format=auto%s', $w, $src);
        $candidates = array_filter([400, 800, 1200, 1600], fn (int $w) => $width === null || $w <= $width * 2);

        return new ResolvedImage(
            $variant($width ?? 1200),
            implode(', ', array_map(fn (int $w) => $variant($w) . ' ' . $w . 'w', $candidates)),
        );
    }
}
```

```yaml
# config/services.yaml
ContentBlocks\Image\ImageUrlResolverInterface:
    class: App\Image\CloudflareImageResolver
```

Things worth knowing:

- **`$width` / `$height` are the display box the view intends**, in px — the image block's preset (sm=400, md=800, lg=1200) or its custom width, and the pinned height when the editor set one. They are `null` where the view is genuinely fluid (a `full` image, a gallery cell, card media), which is a fact about the layout, not a gap: return a candidate set and own `sizes` yourself.
- **`sizes` is derived only when you leave it null and the view knows its width** — a `srcset` with no `sizes` is read as `100vw`, which would make a browser fetch the widest candidate for a 400px box. Where no width is pinned, nothing is emitted rather than something invented.
- **Never throw on a source you cannot handle.** `$src` is whatever an editor stored — a local path, an absolute URL, a leftover from a previous storage backend. Returning `new ResolvedImage($src)` is always a valid answer.
- **Your own blocks get it too**, via the `cb_image()` Twig function:
  ```twig
  {% set img = cb_image(data.src, 800) %}
  <img src="{{ img.src }}"{% if img.srcset %} srcset="{{ img.srcset }}"{% endif %}>
  ```

With nothing wired, `PassthroughImageUrlResolver` returns the stored source untouched and no `srcset`/`sizes` attribute is rendered — byte-for-byte the markup that predates the seam.

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

### `ContentVersionUpgraderInterface` — older stored content

Decides what happens to a section template saved under an earlier generation of
*your* content schema. The shipped default refuses a known mismatch and accepts
content that predates versioning. See [Content versioning](./content-versioning)
for the whole picture — when to bump, what the stamp does and does not
guarantee, and a complete migration.

```yaml
# config/services.yaml
ContentBlocks\Versioning\ContentVersionUpgraderInterface: '@App\ContentBlocks\MyUpgrader'
```

## Toggling topbar features (Insert content, Import / Export)

Everything that acts on the area as a whole lives behind the topbar's single **Actions** menu. It ships two entries:

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
Both options are **UI-only**: they hide the menu entry and its overlay. The underlying endpoints (`…/replace-with`, `…/export`, `…/import`) stay reachable and remain protected by your `AccessCheckerInterface` (and CSRF for writes). If you need to close the endpoints server-side too, gate them with your firewall or `AccessChecker` — the form option does not, by design, since the route has no per-form context.
:::

Turn both off and register no action of your own, and the Actions button is not rendered at all.

## Adding your own actions to the menu

The package renders the entry and nothing more: clicking one dispatches a single `cb:builder:action` DOM event carrying its `key`. What the action *does* is yours — listen once on the shell and switch on the key.

There are two ways in, and which one is right depends on who owns the action.

**A single form** declares its own with the `topbar_actions` option:

```php
$builder->add('contentArea', ContentAreaType::class, [
    'topbar_actions' => [
        ['key' => 'save-as-model', 'label' => 'Save page as model', 'icon' => '💾',
         'title' => 'Save this content as a reusable model'],
    ],
]);
```

**A bundle** implements `BuilderActionProviderInterface` instead, and its action appears in every builder in the application without the host editing each form. It is autoconfigured — declare the service and you are done:

```php
use ContentBlocks\Builder\BuilderAction;
use ContentBlocks\Builder\BuilderActionProviderInterface;
use ContentBlocks\Entity\ContentArea;

final class TranslationActions implements BuilderActionProviderInterface
{
    public function __construct(private readonly Security $security) {}

    public function getActions(ContentArea $area): iterable
    {
        // Returning nothing is how an action hides itself.
        if (!$this->security->isGranted('ROLE_TRANSLATOR')) {
            return;
        }

        yield new BuilderAction(
            key: 'translate',
            label: new TranslatableMessage('action.translate', [], 'my_bundle'),
            icon: '🌍',
            priority: 100,   // higher sorts first
        );
    }
}
```

Both sources are merged into one ordered list: descending `priority`, then providers before the form's own entries. A duplicate `key` collapses to the first occurrence — the key is what your listener switches on, so two rows sharing one would fire the same handler from two places.

Then, on the host side:

```js
document.addEventListener('cb:builder:action', (event) => {
    if (event.detail.key !== 'translate') return;
    // event.detail.areaId is the ContentArea being edited.
});
```

::: tip Labels
A `label` (or `title`) may be a plain, already-translated string or a `TranslatableInterface`. Both are run through `trans` at render, the same way block-type labels are — so a plain string with no catalogue entry comes out unchanged.

`icon` is rendered raw so it can be inline SVG. It must therefore come from trusted code; never interpolate user input into it.
:::
