# ContentBlocks

Modular page builder for Symfony. Build content areas from sections, columns and blocks, with an extensible block-type system.

This package provides the core: entities, admin UI (Live Components + Stimulus), `ContentAreaType` form, and the block-type registry. Use it together with [`klehm/content-blocks-kit`](https://github.com/klehm/content-blocks-kit) for ready-to-use blocks (Text, Title, Image, Tabs).

## Requirements

- PHP >= 8.2 (>= 8.4 for Symfony 8.0)
- Symfony 6.4 LTS, 7.x or 8.x
- Doctrine ORM ^2.12 or ^3.0

## Installation

The packages are tagged as `0.1.0-alpha.*` pre-releases. With `minimum-stability: stable`, add an explicit constraint (`composer require klehm/content-blocks:^0.1@alpha`), or lower the stability to `alpha`/`dev` with `prefer-stable: true`.

### With the Flex recipe (recommended)

A self-hosted Flex recipe endpoint automates the bundle registration, route mounts and config templates. Add it **before** requiring the packages:

```bash
composer config extra.symfony.endpoint \
  '["https://raw.githubusercontent.com/klehm/content-blocks-project/flex/main/index.json", "flex://defaults"]'
composer require klehm/content-blocks klehm/content-blocks-kit
```

Each package has its own recipe: requiring only `klehm/content-blocks` applies only its recipe; the kit's recipe (its bundle, upload route and file-storage config template) is applied when `klehm/content-blocks-kit` is required.

The recipe registers the bundles, mounts the `/_content-blocks/*` routes, and copies a documented `config/packages/content_blocks.yaml` where the two **required** host services are wired (see below). Flex itself (independently of the recipe) syncs the Stimulus controllers and the `sortablejs` importmap entry into your `assets/controllers.json`.

### Without Flex

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
            "cb-autosave":              { "enabled": true, "fetch": "eager" },
            "cb-section-settings-form": { "enabled": true, "fetch": "eager" },
            "cb-spacing-link":          { "enabled": true, "fetch": "eager" },
            "cb-viewport-tabs":         { "enabled": true, "fetch": "eager" },
            "cb-collection-sort":       { "enabled": true, "fetch": "eager" },
            "cb-condition":             { "enabled": true, "fetch": "eager" },
            "cb-file-upload":           { "enabled": true, "fetch": "eager" }
        }
    },
    "entrypoints": []
}
```

The `cb-collection-sort` controller (drag-and-drop reordering of collection
fields) depends on [SortableJS](https://github.com/SortableJS/Sortable). Pin it
in your importmap once:

```bash
php bin/console importmap:require sortablejs
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

### Preview hot reload

After an inline block edit, the builder refreshes the preview iframe. By default a block type triggers a **full iframe reload** (`AbstractBlockType::supportsPreviewHotReload()` returns `false`). When a block's *view* is self-contained — static HTML or CSS-only behaviour, with no JavaScript init needed once the markup is in the DOM — override it to return `true`:

```php
public function supportsPreviewHotReload(): bool
{
    return true;
}
```

The builder then swaps just that block's markup in place (no flash, no re-running the host page's scripts) by fetching `GET /_content-blocks/block/{id}/render`. The server has the final say: an unknown type or one that returns `false` answers `{ "hotReload": false }` and the builder falls back to a full reload.

This is about the rendered **view**, not the edit form — the kit's `image` and `rich_text` blocks opt in even though their *forms* use JavaScript (upload widget, TinyMCE), because that JS lives in the sidebar, never in the preview.

If a view needs a little JavaScript but you still want hot reload, return `true` and (re)initialise idempotently from the `cb:block:rendered` DOM event the overlay dispatches on the freshly-swapped element:

```js
// runs inside the preview iframe
document.addEventListener('cb:block:rendered', (e) => {
    initMyWidget(e.target); // e.detail.blockId is also available
});
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

The quickest opt-in is the bundle config — it registers a `LocalFileStorage`
and enables the `/_content-blocks/upload` endpoint (CSRF-guarded, size-capped,
MIME-whitelisted):

```yaml
# config/packages/content_blocks.yaml
content_blocks:
    upload:
        dir: '%kernel.project_dir%/public/uploads/content-blocks'
        public_prefix: '/uploads/content-blocks'
        # max_size: 10485760                       # bytes, default 10 MB
        # allowed_mime_types: ['image/jpeg', ...]  # default: common images + PDF
```

For S3/Flysystem/CDN storage, alias the interface to your own implementation
instead:

```yaml
ContentBlocks\Storage\FileStorageInterface:
    class: App\Storage\S3FileStorage
```

Block forms get upload UI for free through `ImageUploadType` (file picker +
preview around a hidden path input, wired to the `cb-file-upload` controller):

```php
use ContentBlocks\Form\Type\ImageUploadType;

$builder->add('src', ImageUploadType::class);
```

## Content versioning (`content_blocks.content_version`)

The shape of what a block stores is decided by its **block type** — yours, or the kit's — not by this package. So the schema generation of your content is yours to declare:

```yaml
# config/packages/content_blocks.yaml
content_blocks:
    content_version: 1   # bump when anything that shapes your block data changes
```

Bump it whenever your own blocks change their stored keys, when a kit upgrade renames some, or when a core upgrade note says so. As content is written, the current value is stamped onto `cb_content_area.content_version` (and onto `cb_section_template.content_version` when a snapshot is saved), so a later migration can find what predates the change:

```sql
SELECT id FROM cb_content_area WHERE content_version < 2 OR content_version IS NULL;
```

::: warning What the number means — and does not
It records the version the area was last **written** under, not that every block in it conforms. Editing a single block re-stamps the whole area while its other blocks keep whatever shape they had — so **run your migration before letting editors work on the new version**, otherwise they quietly remove areas from your own `WHERE content_version < N`.

A section template has no such caveat: a snapshot is frozen, so its stamp keeps describing its payload.

`NULL` means "predates versioning" — decide explicitly what to do with those rows; it is not the same as `0`.
:::

An export payload carries the emitting app's `contentVersion` too, but purely for information: a version number means something only inside the installation that issued it, so importing stamps the target with your **local** version instead.

### Deciding what happens to older content

Section templates are the one place where a stored version is comparable — the number came from this same installation. What to do about a mismatch is yours to decide, through `ContentVersionUpgraderInterface`:

```php
use ContentBlocks\Versioning\ContentVersionUpgraderInterface;

final class MyUpgrader implements ContentVersionUpgraderInterface
{
    public function supports(?int $stored, int $current): bool
    {
        return $stored === null || $stored >= 2;   // cheap: drives the picker
    }

    public function upgrade(array $payload, ?int $stored, int $current): array
    {
        if ($stored === 2) {
            $payload = $this->renameSubtitleToKicker($payload);
        }

        return $payload;   // transient — the stored row is never rewritten
    }
}
```

```yaml
# config/services.yaml
ContentBlocks\Versioning\ContentVersionUpgraderInterface: '@App\ContentBlocks\MyUpgrader'
```

`supports()` is called once per row when listing the library, so the picker greys out what you refuse instead of letting an editor click into an error; `upgrade()` runs only on the way in. Upgrading is **transient**: what you return is instantiated, the template row is untouched. Rewriting it for good is a migration, and stays your call.

The shipped default, `DenyOnMismatchUpgrader`, refuses a **known** mismatch and accepts `null`. That asymmetry is deliberate: every row written before versioning existed carries `null`, and refusing those would make your whole library unusable the day you upgrade.

Import does not consult this seam — a payload's version belongs to the app that exported it. If you control both ends of a transfer and want to gate it, decorate `ContentAreaImporterInterface`.

## Styling sections and blocks

Each section's settings sidebar carries a **Styling** group with padding, margin (per viewport), background color, min-height and alignment. Block edit forms carry the same group with padding, margin, background color and max-width.

On sections, the styling fields sit behind a **"Customize styling" switch** (progressive disclosure): everyday editors only see the style-preset dropdown; flipping the switch reveals the full fields, prefilled from the selected preset. While the switch is off, the styling subtree is dropped on save so a later preset change never fights stale values.

These fields land in JSON under `settings.styling` for sections and `data.styling` for blocks. They are stored as-is — no DB migration; existing content keeps working untouched (sections saved before the switch existed are treated as customized).

### Color palette

Background colors use `PaletteColorType`: a dropdown of named project colors plus a **Custom…** option revealing a free color picker. It stores a plain `#hex` (`''` for none) so decorators and templates are unaffected. Declare the palette in config:

```yaml
# config/packages/content_blocks.yaml
content_blocks:
    palette:
        - { label: 'Primary', color: '#eb0540' }
        - { label: 'Dark',    color: '#252525' }
```

…or implement `ContentBlocks\Palette\ColorPaletteProviderInterface` (autoconfigured) for runtime palettes; both sources merge. With no palette declared, the dropdown still offers **None / Custom…** — which is what gives the field a real empty state (backgrounds now default to *transparent*; the old `#ffffff` pre-fill hack is gone).

`PaletteColorType` is reusable in your own block forms (option `allow_custom: false` locks editors to the palette).

### Section style presets

Presets are named styles offered in the section sidebar. Each carries a CSS class and/or **settings values** applied underneath the section's own settings at render time (the user's explicit values win key-by-key):

```yaml
content_blocks:
    section_styles:
        - name: boxed
          label: 'Boxed'
          css_class: 'my-section--boxed'
          settings:
              styling:
                  backgroundColor: '#f1f5f9'
                  padding: { desktop: { top: 40, right: 40, bottom: 40, left: 40 } }
        - name: airy            # settings-only preset (no class)
          label: 'Airy'
          settings:
              styling: { padding: { desktop: { top: 96, bottom: 96 } } }
```

…or implement `ContentBlocks\Section\SectionStyleProviderInterface` and return `SectionStyle` instances (the fourth constructor arg is the settings array).

### Conditional form fields (`cb-condition`)

The sidebar's show/hide logic is a generic Stimulus controller you can reuse in your own block forms: attach `data-controller="cb-condition"` on a container (form type `attr`) and tag rows with `row_attr` → `data-cb-condition="field:value1|value2"` (checkboxes match `true`/`false`; `field` alone means "non-empty"). Combine conditions with **AND** by separating clauses with `;` (e.g. `size:custom;customHeightAuto:false`); each clause still **OR**s its values with `|`. The field name matches the last bracket segment of the input's `name`.

At render time, two decorators (`StylingSectionDecorator`, `StylingBlockDecorator`) translate the values into **CSS custom properties** on the outer element, and a stylesheet shipped at `/_content-blocks/public/styling` maps those vars to real properties with `@media` rules for tablet (`max-width: 991px`) and mobile (`max-width: 575px`) — so per-viewport overrides actually work (inline `style` can't carry media queries).

The fallback chain inside each `@media` block is: mobile → tablet → desktop → 0. A viewport you leave blank inherits the next-wider one.

### Extending the Styling sub-form

The `StylingType` form holds the styling fields. Register a Symfony `FormTypeExtension` against it to inject (or override, by re-`add()`ing an existing name) fields without forking — they will render inside the sidebar's **Styling** group, for sections and blocks alike:

```php
use ContentBlocks\Form\Type\Styling\StylingType;
use Symfony\Component\Form\AbstractTypeExtension;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\FormBuilderInterface;

final class ZIndexExtension extends AbstractTypeExtension
{
    public static function getExtendedTypes(): iterable
    {
        return [StylingType::class];
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        // `include_gap` is only true for sections — use it to gate
        // section-only fields.
        if ($options['include_gap']) {
            $builder->add('zIndex', IntegerType::class, ['required' => false]);
        }
    }
}
```

Pair it with a `SectionDecoratorInterface` reading `$settings['styling']['zIndex']` to emit the style. (For curated background colors, prefer the built-in `palette` config above.)

### Adding a field to a block's edit form

Every block is edited through one shared form type (`BlockFormType`), so a stock Symfony `FormTypeExtension` can't be scoped to a single block — it fires for all of them. Use the **block form extension** seam instead: declare which block type ids it targets (or `'*'` global) and add fields to their edit form.

```php
use ContentBlocks\Form\Extension\AsBlockFormExtension;
use ContentBlocks\Form\Extension\BlockFormExtensionInterface;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;

#[AsBlockFormExtension('button')]          // one type
// #[AsBlockFormExtension(['button', 'card'])]  // several
// #[AsBlockFormExtension]                       // every block (global)
final class ButtonRelExtension implements BlockFormExtensionInterface
{
    public function buildForm(FormBuilderInterface $builder, array $data, string $blockType): void
    {
        $builder->add('rel', TextType::class, ['required' => false, 'data' => $data['rel'] ?? '']);
    }
}
```

Auto-tagged with `content_blocks.block_form_extension` (autoconfigured); `BlockFormType` calls every matching extension after the block's own `buildForm()`, in `priority` order (`#[AsBlockFormExtension('button', priority: 10)]` — higher first). Keyed by block type **id**, so it survives block subclassing. The added field round-trips into `Block.data` like any other (block data is not pruned); render it via a host block-template override. Add `'attr' => ['data-cb-group' => 'SEO']` to the field to give it its own tab in the block sidebar.

For a **global** extension, pair it with a block decorator (next section) rather than overriding every template: the decorator turns the stored key into a class / attribute / inline style for every block at once.

The builder is the block's own, so the seam is not add-only: `$builder->remove('fullWidth')` drops a field (its stored value is frozen rather than deleted, and a POST still carrying it is ignored), and re-adding child builders (`$b->add($b->get('url'))`) reorders the form — children render in insertion order, and the "Style" tab is always appended last. All four patterns are wired in the sandboxes — see `apps/content-blocks-sandbox/src/ContentBlocks/` — and detailed in the *Add a field to a block* recipe in the docs.

### Adding your own block decorator

Implement `ContentBlocks\Block\BlockDecoratorInterface` (mirror of `SectionDecoratorInterface`). It is auto-tagged with `content_blocks.block_decorator` when `autoconfigure: true` is on, and called for every block being rendered. Return a `BlockDecoration` (classes / inline styles / attributes) — the bundle merges all decorators' output into the block's outer `<div>`.

### Replacing or decorating a core service

The services that carry the builder's core behaviour are each registered as a concrete class **aliased to an interface**, and every consumer type-hints the interface. To change one, alias it to your own implementation — or, more often, decorate the shipped one:

| Interface | Default | Responsibility |
|---|---|---|
| `Rendering\BlockRendererInterface` | `BlockRenderer` | renders an area / section / block to HTML |
| `Publishing\ContentAreaPublisherInterface` | `ContentAreaPublisher` | publishes or discards the draft state |
| `Section\SectionClonerInterface` | `SectionCloner` | deep-clones a section (duplicate + replace flows) |
| `Transfer\ContentAreaExporterInterface` | `ContentAreaExporter` | area → self-contained JSON payload |
| `Transfer\ContentAreaImporterInterface` | `ContentAreaImporter` | JSON payload → draft sections |
| `SectionTemplate\SectionTemplateSerializerInterface` | `SectionTemplateSerializer` | section → reusable library snapshot |
| `SectionTemplate\SectionTemplateInstantiatorInterface` | `SectionTemplateInstantiator` | snapshot → detached draft section |

```php
use ContentBlocks\Publishing\ContentAreaPublisherInterface;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;

#[AsDecorator(ContentAreaPublisherInterface::class)]
final class AuditedPublisher implements ContentAreaPublisherInterface
{
    public function __construct(private readonly ContentAreaPublisherInterface $inner) {}

    public function publish(ContentArea $area): void
    {
        $this->inner->publish($area);
        // … your audit trail, cache invalidation, webhook …
    }

    public function discardDraft(ContentArea $area): void { $this->inner->discardDraft($area); }
}
```

One contract to keep in mind when replacing rather than decorating: `publish()` / `discardDraft()` **flush** the EntityManager — they are the terminal operations of the draft lifecycle. The services that *build* rather than commit (cloner, importer, instantiator) leave the flush to their caller.

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

> **Note on centered sections.** The cap is applied to the inner `.cb-row`, not the `<section>` element — so a centered section's **background spans the full viewport width** while its content stays contained (the standard full-bleed pattern). The cap is emitted as a `--cb-row-max-w` custom property read by `layout.css`.

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

Both options are **UI-only**: they hide the topbar button and its overlay. The underlying endpoints (`…/replace-with`, `…/export`, `…/import`) stay reachable and remain protected by your `AccessCheckerInterface` (and CSRF for writes). If you need to close the endpoints server-side too, gate them with your firewall or `AccessChecker` — the form option does not, by design, since the route has no per-form context.

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
