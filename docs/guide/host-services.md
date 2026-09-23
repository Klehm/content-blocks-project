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

`canEdit()` is called by every controller and Live Component before any mutation or any read of the unpublished draft (export, replace-with source, tree, copy), and is also what [preview-mode detection](./rendering.md#preview-vs-public-mode) hinges on. The package itself no longer calls `canView()`; it stays on the interface for hosts and satellites. If your admin and front-office live behind separate firewalls, read the [cross-firewall auth detection](./security.md#cross-firewall-auth-detection) note carefully.

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

The default whitelist has no video type. If you use the kit's `video` block
with uploads, list `video/mp4` and `video/webm` in `allowed_mime_types` (which
replaces the default list, so repeat the image types) and raise `max_size`.
Pasting a path to an existing video works without either.

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

`VideoUploadType` is the same widget with a `<video>` preview and `accept` set to MP4, WebM and Ogg.

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

  That last point holds everywhere, and it is deliberate: deleting a block does not delete its image, because `deleted` is a draft flag and the published page still renders that block. Reclaiming unreferenced files is a separate, explicit act — see [Asset lifecycle](./asset-lifecycle.md). If your own entities keep images in the same upload directory, that page also explains the one interface you need to register before sweeping.

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

::: tip Want compression and WebP, not just a CDN?
[Compress and convert images to WebP](./recipes/liip-imagine.md) is the full LiipImagine recipe — filter sets, the resolver, and the two operational traps. It is what the sandbox runs, so it is covered by an end-to-end test.
:::

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

### Initial settings of a new section

A default only reaches what the render falls back on — width mode and max width
above. Padding, margin, background or the **Customize styling** switch have no
render fallback, so a default for them only pre-fills the sidebar. To have every
section **added from the builder** start with real values, declare them:

```yaml
# config/packages/content_blocks.yaml
content_blocks:
    section:
        initial_settings:
            widthMode: centered
            stylingCustom: true
            styling:
                padding:
                    desktop: { top: 12, right: 12, bottom: 12, left: 12, linked: true }
```

They are written as the new section's own draft settings, exactly as if the
editor had saved them — so they render immediately, show in the sidebar, and
follow Publish / Discard. The tree is the same typed one as a style preset's
`settings`, so a misspelt key or an invalid value fails at `cache:clear`.
`styleName` is accepted too, which is how to give new sections a default preset.

Three things to know:

- **Only new, empty sections** get them. A pasted, duplicated, imported or
  template-built section keeps the settings it came with, and existing sections
  are untouched.
- **Don't declare the same value as a default too.** The render strips values
  equal to a `SectionSettingsDefaultsProviderInterface` default before the
  styling runs, and styling has no fallback — so 12 declared in both places
  renders no padding at all.
- Values equal to `default_width_mode` / `default_max_width` are harmless: those
  two do fall back at render.

### Section layouts

The add-section buttons offer three layouts out of the box: `full`, `two_cols`
and `three_cols`. Add your own, relabel or hide one, in config:

```yaml
# config/packages/content_blocks.yaml
content_blocks:
    section:
        layouts:
            four_cols: { label: '4 columns', columns: [3, 3, 3, 3] }
            sidebar_left: { label: 'Sidebar + content', columns: [4, 8] }
            two_cols: { label: 'Halves' }   # relabel a built-in
            three_cols: false               # hide a built-in
            tabs: { label: 'Tabs', columns: [4, 4, 4], display: tabs }
```

- **`columns`** is the span of each column on a 12-unit grid, and must add up
  to 12. A new section gets one column per entry, with the preset `col-N`.
  `label` is plain text or a translation key in the `content_blocks` domain.
  Both are required for a new layout. For a built-in, set only what changes.
- **The name** is stored in `cb_section.layout` and becomes the class
  `cb-section--<name>`, so it must be lowercase snake_case, 30 characters at
  most. Every mistake fails at `cache:clear`.
- **Hiding a layout never breaks content.** Sections are drawn from the presets
  of their own columns, not from this config, so a section built with a layout
  you later hide or remove still renders. The server refuses to *create* one,
  including from a forged request.
- Buttons follow the declaration order, built-ins first, and their glyph is
  drawn from `columns`.
- **`display`** (`grid` by default, `slider`, `tabs` or `accordion`) is how a new section of this
  layout starts showing its columns. The editor can switch it at any time in
  the section sidebar (see below).

The front stylesheet handles every span from `col-1` to `col-12`. On desktop,
columns share the row in proportion to their spans. At 768px and below, spans
of 4 or less sit two per row (so four columns become 2 + 2) and uneven wide
spans (`col-8`) take the whole row. At 540px and below, every column stacks.
Override `.cb-section--<name> .cb-col` in your own CSS for anything else.

A section with two columns or more also has **Reverse the column order on
mobile** in its sidebar (`reverseOnMobile` setting, class
`cb-section--reverse-mobile`). At 540px and below its stacked columns read last
to first, which keeps the image above the text across alternating rows. It
applies only where mobile shows a grid, and a style preset may set it.

### Columns and tabs

A layout only chooses a section's **starting** columns. The section sidebar has
a **Columns** group where the editor adds a column, removes one (not the last)
and names each. The **Display** setting shows the columns side by side or one at
a time as **tabs**, or as an **accordion** of collapsible panels, and the
column names become the tab or panel titles. A section holds
at most 20 columns.

Like everything else in the builder, all of it is draft: the published page
keeps its columns, spans and names until Publish, and undo walks every step
back. Adding or removing a column resets the spans to equal.

Tabs need no JavaScript on your pages. They are radios, labels and CSS served
by the core stylesheet, so they work wherever `cb_render_content_area()`
does. Three custom properties restyle them, and the classes are yours to
override:

```css
.cb-content-area {
    --cb-tabs-accent: #eb0540;               /* active tab underline */
    --cb-tabs-line: rgba(0, 0, 0, 0.1);      /* bar under the tabs */
    --cb-tabs-gap: 1.5rem;                   /* space above the panel */
}
.cb-tabs__tab { text-transform: uppercase; }
```

The accordion works the same way: a toggle and a header before each column. By
default the first panel is open and each one opens independently. Two section
settings, next to *Display* in the sidebar and allowed in a preset, change that:

- **`accordionSingle`** (*Only one panel open at a time*): opening a panel
  closes the other one, and clicking the open header closes it. Still CSS only.
- **`accordionCollapsed`** (*All panels closed at first*): nothing is open when
  the page loads, as on a FAQ.

Its custom properties and classes:

```css
.cb-content-area {
    --cb-accordion-accent: #eb0540;          /* keyboard focus ring */
    --cb-accordion-line: rgba(0, 0, 0, 0.1); /* rule under each header */
    --cb-accordion-gap: 1.5rem;              /* padding around a panel */
}
.cb-accordion__header { font-weight: 600; }
.cb-accordion__header::after { /* the chevron */ }
```

A style preset may carry `display: tabs` or `display: accordion` in its
`settings`, like any other section setting.

### Display per viewport

*Display* has the same desktop / tablet / mobile switch as the spacing fields.
Tablet and mobile default to *Same as above*, and **can only get more
compact** than the viewport above them:

| Above | Allowed below |
|---|---|
| grid or slider | grid, slider, accordion |
| tabs | tabs, accordion |
| accordion | accordion |

So a row of three cards can scroll on a phone, and tabs can become an
accordion, but tabs are never offered below a grid. The rule keeps the markup
light: a grid and a slider are the same HTML, and tabs becoming an accordion
reuse the tab radios (one panel is always open there). The server applies the
same rule, so a value it refuses, from a preset or a forged request, falls
back to the viewport above.

The keys are `display` (desktop), `displayTablet` and `displayMobile`, and a
preset may set them. A section that changes gets `cb-section--responsive` plus
`cb-section--t-<display>` and `cb-section--m-<display>`; one that does not keeps
`cb-section--display-<display>` alone, as before.

### Slider

A slider shows the columns in a row that scrolls and snaps, one column per
slide, so a slide holds any blocks. Swiping works without JavaScript. The
sidebar sets:

- **Slides visible at once** (`sliderPerView`), per viewport, 1 to 6; a blank
  viewport inherits the one above.
- **Slider controls** (`sliderControls`): `both` (default), `arrows`, `dots` or
  `none`.
- **Autoplay** (`sliderAutoplay`), in seconds, 0 to 60, off at 0. It pauses on
  hover and keyboard focus, stops in a hidden tab, and never runs for visitors
  who ask for reduced motion, nor in the builder.
- **Arrows loop back** (`sliderLoop`): the last slide's arrow goes to the first.

The arrows, dots and ARIA roles come from a small script the page loads only
when a section needs it (`content_blocks_asset_slider`, a public route like the
stylesheets). It reads from the CSS whether a section is a slider at the current
width, so it follows the same breakpoints. Restyle it through:

```css
.cb-content-area {
    --cb-slider-accent: #eb0540;             /* keyboard focus ring */
    --cb-slider-gap: 1.5rem;                 /* space above the controls */
}
.cb-slider__arrow { border-color: transparent; }
.cb-slider__dot[aria-current="true"] { background: #eb0540; }
```

A slide's width comes from two properties set on `.cb-row`: `--cb-slide-n`,
the slides visible at the current width (resolved from `--cb-slides-d/t/m`),
and `--cb-slide-gap`, which follows the section's column gap. Override the gap
to space slides apart from the grid's gutter; set the count in the sidebar.

### Order per viewport

With the builder preview on tablet or mobile, dragging a section, or a block
within its column, changes the order **for that viewport only**. The topbar
says so, next to a button that resets the order. Desktop is untouched, and a
block cannot change column there: CSS can reorder siblings, not move them.

Each element keeps its rank under `_order` in its settings or data. The render
turns the ranks of a group into `--cb-order-t` / `--cb-order-m` on every
sibling. A sibling without a rank (a duplicate, a paste, a new block) follows
the one before it on desktop. Screen readers and keyboard navigation keep the
desktop order.

### Your own keys in presets and initial settings

`section_styles[].settings` and `section.initial_settings` type the core keys,
and keep any other key as it is: a field you added to the section form with a
type extension (see [Add a section field](./recipes/add-section-field.md)) can
be preset too, at the top level or under `styling`. A key one or two letters
away from a core key is refused at `cache:clear` as a typo
(`widthMod` → *did you mean "widthMode"?*). Custom keys also travel with the
section through duplicate, copy/paste, section templates and export/import.

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

## Toggling topbar features (Insert content, Import / Export, View page)

Everything that acts on the area as a whole lives behind the topbar's single **Actions** menu. It ships two entries:

- **Insert content** (`⇆`) — overwrite the area's content with a clone of another area's content (the replace-content flow).
- **Import / Export** (`⇅`) — export a `ContentArea` to a `.zip` (its content and its media, or its content alone) and import it into another page or another site. See [Large imports](#large-imports) for the server limits.

Outside the menu, on the right of the topbar, **View page** (`↗`) opens the published page in a new tab — the URL your `ContentAreaUrlResolverInterface` returns, without the preview flag, so it shows what visitors see rather than the draft.

All three are **on by default** and are toggled **per field**, via `ContentAreaType` options — so the host picks its own strategy per form (an admin form can keep them, a lighter editor can drop them):

```php
$builder->add('contentArea', ContentAreaType::class, [
    'enable_replace' => false,        // hide the "Insert content" button + picker
    'enable_import_export' => false,  // hide the Import / Export button + overlay
    'enable_public_link' => false,    // hide the "View page" link
]);
```

A host that includes `launcher.html.twig` directly passes the same flags as `enableReplace`, `enableImportExport` and `enablePublicLink`. The link's URL is also available to your own templates as `cb_public_url(area)`.

::: warning UI-only toggles
The first two options are **UI-only**: they hide the menu entry and its overlay. The underlying endpoints (`…/replace-with`, `…/export`, `…/import`) stay reachable and remain protected by your `AccessCheckerInterface` (and CSRF for writes). If you need to close the endpoints server-side too, gate them with your firewall or `AccessChecker` — the form option does not, by design, since the route has no per-form context.
:::

Turn both off and register no action of your own, and the Actions button is not rendered at all.

### Large imports

An export is a `.zip`: `content.json` plus one file per medium, stored as it is. It is **streamed** as it is written, so the server holds one file at a time whatever the size of the page, and it announces its exact size, so the browser shows a real progress bar.

The import does not send that archive in one request. The browser opens it, asks which media this site already holds, sends the others **one per request**, then the content. So the size of the export never meets a request limit; the largest single file does, and it has to fit the same limits as an ordinary upload:

| Setting | Default | What to set |
|---|---|---|
| `content_blocks.upload.max_size` | 10 MB | The largest file an editor can upload — and an import can bring. |
| PHP `upload_max_filesize` | 2 MB | At least `upload.max_size`. Usually the first limit hit. |
| PHP `post_max_size` | 8 MB | A little above `upload_max_filesize`. Past it PHP drops the whole request. |
| nginx `client_max_body_size` | 1 MB | At least `upload.max_size`. Apache: `LimitRequestBody`. |
| PHP `max_execution_time` | 30 s | Enough to stream the largest export over a slow connection. |
| A proxy in front | varies | It must relay the streamed download as it comes: the response carries `X-Accel-Buffering: no` for nginx; a CDN may need the route excluded from buffering. |

`memory_limit` no longer grows with the page: a file at a time, on both sides.

Before sending anything, the import names a file larger than the smallest of the first three limits, and the editor can still drop a lighter copy of it. When a layer the builder cannot see refuses a request (the web server, a proxy), the editor reads "too large" rather than a generic failure.

The export's *Include media files* switch is the lighter option when the content stays on the same site, or goes to an environment that shares its uploads: the archive then holds `content.json` alone. A copy on the same site does not need it — the import already recognises the media this site holds and sends none of them — but it saves the download.

`POST …/import`, the single-request import kept for scripts, still takes the whole payload at once; `content_blocks.import.max_size` (50 MB) caps it, along with the PHP limits above.

## Adding your own actions to the menu

The package renders the entry and nothing more: clicking one dispatches a single `cb:builder:action` DOM event carrying its `key`. What the action *does* is yours — listen once on the shell and switch on the key.

There are two ways in, and which one is right depends on who owns the action.

**A single form** declares its own with the `topbar_actions` option:

```php
$builder->add('contentArea', ContentAreaType::class, [
    'topbar_actions' => [
        ['key' => 'save-as-model', 'label' => 'app.builder.save_as_model', 'icon' => '💾',
         'title' => 'app.builder.save_as_model_title'],
    ],
]);
```

Those labels are **translation keys** — see the note below. Hardcoding the text works and is the quickest way to end up with a half-English menu next to the package's own localized entries.

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

### Saying how it went

The builder is a modal: whatever your listener writes into your own page lands
*under* it, out of sight. Report back through the builder instead — dispatch
`cb:notify` at the action event's target (or anything inside the shell), and the
message appears in the builder's snackbar:

```js
document.addEventListener('cb:builder:action', async (event) => {
    if (event.detail.key !== 'save-as-model') return;
    const builder = event.target;
    const response = await fetch(`/admin/area/${event.detail.areaId}/save-as-model`, { method: 'POST' });
    const model = await response.json();

    builder.dispatchEvent(new CustomEvent('cb:notify', {
        bubbles: true,
        detail: {
            message: `Model created: ${model.title}`,
            link: { label: 'Open', href: model.url },   // optional
        },
    }));
});
```

| `detail` | |
|---|---|
| `message` | Required, plain text — it is never parsed as HTML. An empty one is ignored. |
| `link` | Optional `{ label, href }`, opened **in a new tab** so the builder stays open. An `href` that is not http(s) is dropped. |

The snackbar has one slot: a notification replaces whatever was showing,
including a pending *Undo* offer. It hides after 6 seconds, 10 with a link.
Translate the text yourself — the builder shows it as given.

::: tip Labels
A `label` (or `title`) is run through `trans` at render, the same way block-type labels are, so all three of these work:

- **a translation key** (`'app.builder.save_as_model'`) resolved against the default `messages` domain — the simplest option, and the one to reach for from a Twig `topbarActions` array where building an object is awkward;
- **a `TranslatableInterface`** (`new TranslatableMessage('action.translate', [], 'my_bundle')`) when the strings live in your bundle's own domain;
- **a plain, already-translated string**, which comes out unchanged when it has no catalogue entry.

The third is the trap: it renders fine in the language you typed it in, and stays in that language beside the builder's own localized entries. If your admin is ever anything but English, pass a key.

`icon` is rendered raw so it can be inline SVG. It must therefore come from trusted code; never interpolate user input into it.
:::

## Adding your own UI to the builder shell

An action gets a bundle a menu entry and an event. It does not get it anywhere to *put* anything — a dialog, a status line, the script that reacts to the event. A host has the page the builder is mounted in for that; a bundle owns no page. `BuilderShellExtensionInterface` is the other half: it lets a bundle render its own templates **inside the builder shell**, wherever the shell is rendered, with nothing for the host to wire.

It is autoconfigured — declare the service and you are done:

```php
use ContentBlocks\Builder\BuilderShellExtensionInterface;
use ContentBlocks\Builder\BuilderShellFragment;
use ContentBlocks\Entity\ContentArea;

final class HistoryShellExtension implements BuilderShellExtensionInterface
{
    public function getFragments(ContentArea $area): iterable
    {
        // Returning nothing is how a fragment hides itself.
        yield new BuilderShellFragment(
            template: '@MyBundle/builder/history.html.twig',
            context: ['revisionCount' => 3],
            priority: 0,   // higher renders first
        );
    }
}
```

The template is rendered as the last thing inside `.cb-shell`, after the builder's own chrome, so an overlay stacks above it. It is rendered in **isolation**: it sees its own `context` plus `area` (the `ContentArea` being edited) and nothing of the shell's variables, which are internal. `area` is reserved — a fragment declaring it is refused at construction.

Everything else a fragment needs is on the shell root, which it can reach with `closest()`: the CSRF token (`data-cb-csrf-token`, token id `content_blocks`) and the area id. So a fragment carries its own script, served from a route the bundle owns — no Stimulus controller, no `controllers.json` entry, no asset build, and the same under AssetMapper and Webpack Encore:

```twig
{# @MyBundle/builder/history.html.twig #}
<dialog class="my-history" data-my-history data-area-id="{{ area.id }}">…</dialog>
<script type="module" src="{{ path('my_bundle_history_js') }}"></script>
```

```js
// Served by the bundle. Pair it with a BuilderActionProviderInterface
// contributing the `history` key: the click arrives as cb:builder:action.
document.addEventListener('cb:builder:action', async (event) => {
    if (event.detail.key !== 'history') return;
    const shell = event.detail.button.closest('[data-cb-csrf-token]');
    const dialog = shell.querySelector('[data-my-history]');
    dialog.showModal();
    // … the user picks a revision; the bundle's endpoint writes it to the draft …
    // The area changed behind the builder's back: ask it to catch up.
    dialog.dispatchEvent(new CustomEvent('cb:area:changed', { bubbles: true }));
});
```

`cb:area:changed` is one of the two **inbound** public events: dispatched at the builder from the shell element or anything inside it, it makes the builder reload the preview and re-sync Publish / Discard — the same landing as an import or an "Insert content". `detail.hasUnpublishedChanges` is optional and defaults to `true`, which is what a draft write means.

::: tip Where fragments show up
The shell asks for its fragments itself (the `cb_shell_fragments(area)` Twig function), so a fragment renders whether the builder came from `ContentAreaType` or from a direct `{% include '@ContentBlocks/builder/launcher.html.twig' %}`. That differs from `BuilderActionProviderInterface`, whose actions are gathered by `ContentAreaType` and, on a direct include, have to be passed as `topbarActions` by the host.
:::


### From your own templates: the shell's empty blocks

A fragment is the way in for a **bundle**. A **host** that only wants a button or
a badge in the topbar can do it without a service: the shell carries empty Twig
blocks, and overriding the template to fill one copies nothing else.

```twig
{# templates/bundles/ContentBlocksBundle/builder/shell.html.twig #}
{% extends '@!ContentBlocks/builder/shell.html.twig' %}

{% block cb_shell_topbar_right_start %}
    <a class="cb-shell__public-link" href="{{ path('admin_page_list') }}">All pages</a>
{% endblock %}
```

| Block | Where |
|---|---|
| `cb_shell_topbar_left_end` | Left cluster, after undo / redo |
| `cb_shell_topbar_right_start` | Right cluster, first — before *View page* |
| `cb_shell_topbar_right_end` | Right cluster, last — after *Publish* |
| `cb_shell_end` | Last thing inside `.cb-shell`, after the fragments |

A block sees the shell's own variables — `area` always. Reusing the shell's
classes (`cb-shell__public-link` above) makes an addition look native. The block
**names** are covered by the [backward-compatibility promise](./backward-compatibility.md#twig);
what surrounds them is not. The translation workbench carries the same kind of
blocks, see [Translation](./translation.md#adding-to-the-workbench).
