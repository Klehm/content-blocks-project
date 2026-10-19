---
title: Custom blocks
---

# Building a custom block

A block type is a small service. Extend `AbstractBlockType` (or implement `BlockTypeInterface` directly), annotate it with `#[AsContentBlock]`, and Symfony autoconfiguration does the rest — the `BlockTypeCompilerPass` auto-tags it and the `BlockTypeRegistry` picks it up. No manual registration.

## Minimal example

Extending `AbstractBlockType` gives you sensible defaults for the optional methods (`getIcon()`, `getFormTheme()`, `getViewTemplate()`, `supportsPreviewHotReload()`), so a block only has to declare its type, label, form and default data:

```php
use ContentBlocks\BlockType\AbstractBlockType;
use ContentBlocks\BlockType\AsContentBlock;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;

#[AsContentBlock]
final class MyBlock extends AbstractBlockType
{
    public function getType(): string
    {
        return 'my_block';
    }

    public function getLabel(): string
    {
        return 'My Block';
    }

    public function buildForm(FormBuilderInterface $builder, array $data): void
    {
        $builder->add('content', TextType::class, ['data' => $data['content'] ?? '']);
    }

    public function getDefaultData(): array
    {
        return ['content' => ''];
    }

    public function getViewTemplate(): ?string
    {
        return 'block/my_block.html.twig';
    }
}
```

The block is detected automatically as long as its class lives in a namespace loaded by the service container. It then appears in the block picker, and its `data` shape is whatever `buildForm()` declares.

## Everything a block class can define

`BlockTypeInterface` has eight methods. Four are required; the other four have defaults from `AbstractBlockType`, so you only override the ones you need.

| Method | Required? | Purpose |
|---|---|---|
| `getType(): string` | ✅ | Unique machine id (`'my_block'`). Stored in `cb_block.type`. |
| `getLabel(): string\|TranslatableInterface` | ✅ | Label in the picker & block toolbar. String, or a `TranslatableMessage` for a custom translation domain — [see below](#translatable-labels). |
| `buildForm(FormBuilderInterface, array $data): void` | ✅ | The edit form. **This is also the data whitelist & validator** — [see below](#the-form-is-your-data-whitelist). |
| `getDefaultData(): array` | ✅ | Initial `data` for a freshly-added block. |
| `getViewTemplate(): ?string` | default `null` | The Twig template that renders the block front-and-preview — [see below](#the-view-template). `null` ⇒ the block renders nothing. |
| `getIcon(): ?string` | default `null` | Inline SVG shown in the picker — [see below](#an-icon-in-the-picker). `null` ⇒ generic glyph. |
| `getFormTheme(): ?string` | default `null` | A custom Twig form theme for the edit form — [see below](#a-custom-form-theme). |
| `supportsPreviewHotReload(): bool` | default `false` | Swap this block in place instead of reloading the iframe — [see below](#opting-into-preview-hot-reload). |

Beyond the interface, the `#[AsContentBlock]` attribute takes a `priority` that [orders the block in the picker](#ordering-blocks-in-the-picker), and — because a block is a real service — you can [inject dependencies](#injecting-services) through its constructor.

One more capability lives outside `BlockTypeInterface`, on an interface of its own: implement `BlockPreviewHintInterface` to say what your block should look like in a [section-library thumbnail](#showing-up-in-section-library-thumbnails).

## The view template

`getViewTemplate()` returns the Twig template that renders the block — on the **public page and inside the preview alike**. It receives the block's stored `data` array (rendered with `with_context = false`, so `data` is the only variable in scope):

```twig
{# templates/block/my_block.html.twig #}
<div class="my-block">
    {{ data.content }}
</div>
```

Where the file lives depends on how you ship the block:

| You are… | Put the template at | Return from `getViewTemplate()` |
|---|---|---|
| A **host app** (block in `src/`) | `templates/block/my_block.html.twig` | `'block/my_block.html.twig'` |
| A **distributable bundle** | `<BundleRoot>/templates/block/my_block/view.html.twig` | `'@YourBundle/block/my_block/view.html.twig'` |

Symfony auto-registers a bundle's `templates/` directory under its `@YourBundle` Twig namespace — which is exactly how the kit points at `'@ContentBlocksKit/block/alert/view.html.twig'`.

The view receives these variables, and nothing else from the page:

| Variable | What it is |
|---|---|
| `data` | the block's data, resolved for this render (draft in the preview, published on the public page, translated under i18n) |
| `block_id` | its id |
| `block` | the block itself, read-only: `block.id`, `block.type` |
| `context` | how the page is rendered: `context.preview` / `context.public`, `context.locale`, `context.mode` |

Use `block_id` for anything that must be unique on the page, such as a radio group's `name` or an ARIA id; unlike `random()`, it gives the same markup on every render, which keeps the page cacheable:

```twig
{% set uid = 'my-block-' ~ (block_id ?? random()) %}
```

`block_id` can be `null` when a view is rendered on its own, in a test for instance, hence the fallback.

`context` is for what must differ between the builder and the visitor's page, or between languages:

```twig
{# No autoplay while an editor is working on the page. #}
<video{% if data.autoplay and not context.preview %} autoplay muted{% endif %} …>

<time datetime="{{ data.date }}">{{ data.date|format_date(locale: context.locale) }}</time>
```

`context.locale` is the locale the page is rendered in: the one the caller passed in the `RenderContext`, else the request's. It is `null` only when there is no request at all, in a console command for instance. `block` is not the Doctrine entity on purpose: a view must not be able to read a draft on a public page.

::: tip Overriding `render/block.html.twig`
`block` and `context` arrived in 1.5. If your app overrides `@ContentBlocks/render/block.html.twig`, add `block: block.view, context: block.context` to its `include()`, or write `context ?? null` in your views until you do.
:::

::: warning A view template is effectively required for a real block
`AbstractBlockType::getViewTemplate()` returns `null` by default, and a `null` template renders **nothing**: the block's wrapper `<div>` is on the page, empty, and there is no generic fallback. Any block you actually ship must return a template path.
:::

Hosts (or the kit's users) can override your template without forking: drop a file at the matching path under `templates/bundles/YourBundle/…`. See [Overriding render templates](./rendering.md#overriding-render-templates).

::: tip Blocks not detected?
Check that the block class sits in a namespace wired into the container. In the kit, `config/services.php` explicitly loads `ContentBlocks\Kit\Block\` — a custom block in your own app needs the equivalent for its own namespace.
:::

## An icon in the picker

`getIcon()` returns **self-contained inline SVG** shown next to the label in the "+ block" picker. Use `currentColor` for strokes/fills so the icon inherits the picker's theme color:

```php
public function getIcon(): ?string
{
    return <<<'SVG'
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <rect x="3" y="3" width="18" height="18" rx="2"/>
        </svg>
        SVG;
}
```

::: danger Trusted markup only
The SVG is injected into the picker DOM **as-is**. It must come from your block-author code — never interpolate user input into it.
:::

Return `null` (the `AbstractBlockType` default) to fall back to a generic glyph.

## Ordering blocks in the picker

The picker grid renders blocks in registration order, which is driven by the `priority` argument of `#[AsContentBlock]` — **higher priority first**. Blocks sharing a priority keep their service-discovery order. Default is `0`.

```php
#[AsContentBlock(priority: 100)]  // floats to the top of the picker
final class MyBlock extends AbstractBlockType { /* … */ }
```

## Translatable labels

`getLabel()` may return a plain string (already-translated) or a `TranslatableInterface`. Return a `TranslatableMessage` when the label lives in a custom translation domain — the renderer translates it at the boundary before exposing it to the front (picker, JSON endpoints):

```php
use Symfony\Component\Translation\TranslatableMessage;

public function getLabel(): string|TranslatableInterface
{
    return new TranslatableMessage('block.my_block.label', [], 'messages');
}
```

## A custom form theme

By default the edit form renders with the builder's form theme. To customize the markup of your block's fields, ship a Twig form theme and point at it from `getFormTheme()`:

```php
public function getFormTheme(): ?string
{
    return '@YourBundle/form/my_block_theme.html.twig';
}
```

The kit uses this mechanism for its richer fields (image upload, palette color). For simple field tweaks you usually don't need it — a `row_attr`/`attr` on the field or the shared `cb-condition` controller is enough.

## A field driven by JavaScript

A color wheel, a map picker, an editor: a field whose widget is built by a
script needs three things, because the sidebar form is a **Live Component**.
Each save re-renders it on the server and *morphs* the new HTML into the page,
and each field reaches the server through its input's `input` and `change`
events.

1. **A Stimulus controller on the widget**, not a script run once on load. The
   morph can remove and re-add the element; `connect()` and `disconnect()` are
   what follow it.
2. **`data-live-ignore` on the element your script owns**, so the morph does not
   replace the DOM the widget built with the server's plain `<input>`.
3. **Events after every change you make in JavaScript.** Setting `input.value`
   fires nothing, so Live would never see the value, and the next morph would
   put the old one back. Dispatch them yourself, bubbling:

```js
// assets/controllers/color_wheel_controller.js
import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['input'];

    connect() {
        this.wheel = new ColorWheel(this.element, {
            value: this.inputTarget.value,
            onChange: (color) => {
                this.inputTarget.value = color;
                // `input` is debounced by autosave, `change` saves now.
                this.inputTarget.dispatchEvent(new Event('input', { bubbles: true }));
                this.inputTarget.dispatchEvent(new Event('change', { bubbles: true }));
            },
        });
    }

    disconnect() {
        this.wheel.destroy();
    }
}
```

```twig
{# In the theme getFormTheme() returns, for a type whose prefix is color_wheel #}
{% block color_wheel_widget %}
    {%- set attr = attr|merge({'data-color-wheel-target': 'input'}) -%}
    <div data-controller="color-wheel" data-live-ignore>
        {{ block('form_widget_simple') }}
    </div>
{% endblock %}
```

The input stays a real form field inside the wrapper: it is what the form
submits and validates. The kit's rich-text editors follow the same pattern
(`rich_text_theme.html.twig`, `cb-tinymce_controller.js`).

## Injecting services

A block type is a fully-fledged autowired service, so you can inject dependencies through its constructor — a repository, the `UrlGeneratorInterface`, a settings service — and use them in `buildForm()` or a custom method:

```php
#[AsContentBlock]
final class LatestPostsBlock extends AbstractBlockType
{
    public function __construct(private readonly PostRepository $posts) {}

    public function buildForm(FormBuilderInterface $builder, array $data): void
    {
        $builder->add('category', ChoiceType::class, [
            'choices' => $this->posts->findCategories(), // resolved via DI
            'data' => $data['category'] ?? null,
        ]);
    }

    // getType(), getLabel(), getDefaultData(), getViewTemplate()…
}
```

::: warning Introspection instantiates with `new`
Tooling that introspects blocks outside the container (e.g. the kit's `content-blocks-kit:blocks` command) constructs them with `new $class()`. Give every constructor argument a default (or tolerate a bare `new`) if you want your block to stay introspectable — the kit's `AbstractKitBlock` does exactly this.
:::

## The form is your data whitelist

::: info Security by declaration
A block's `data` is never written raw — the block's Symfony form **is** its whitelist and validator. The compound form only maps its declared children (unexpected POST keys are dropped), and each field's `constraints` run on submit (an invalid value is never written: that field keeps its stored value, and the editor sees the error once they have touched it).
:::

There is no `sanitizeData()` / `getAllowedDataKeys()` hook to implement — you secure a block's data purely through the fields and constraints declared in `buildForm()`. For a field with a fixed set of allowed values, add an `Assert\Choice` constraint derived from the full coded choice set. See [Security → Block data sanitization](./security.md#block-data-sanitization) for the full rationale.

This pays off in a place you never wrote code for: **a pasted block is replayed through your form**. The [copy/paste clipboard](./concepts.md#editor-gestures-copy-paste) lives in the browser's `localStorage`, so its payload is user-writable — a key you did not declare never reaches `data`, and a value your `constraints` refuse is reset to your `getDefaultData()` value and reported to the editor rather than costing the whole block. Nothing to opt into; the form you already wrote is the filter.

### Names the package owns

- **Never declare a field whose name starts with `_`.** The prefix is reserved by ContentBlocks at every level of `Block.data`, including collection entries.
- **`_lang`** and **`_block_lang`** appear in the `data` a view receives, never in storage: the translation package records there which fields are shown in another language. Read them through `cb_lang()` (see above).
- **`_id`** is what it uses today: every entry of a `CollectionType` / `LiveCollectionType` field gets one automatically, so a reorder, duplicate or delete never shifts what per-entry information points at. You do not declare it, render it, or maintain it — but do not strip it either if you post-process `data` yourself. (Copies carry their entry ids across — cloning a section, importing an area, inserting a template — with one exception: a **pasted** block gets fresh ones, since it went back through the form.)

## Text shown in another language

With the translation package and its `fallback_lang` option on, a field still
in the source language on a translated page is marked. When the whole block
is untranslated, the wrapper gets `lang` and your view has nothing to do. For a
block translated in part, print `cb_lang()` on the element that holds each text
field, passing the object the field belongs to:

```twig
<h2{{ cb_lang(data, 'title') }}>{{ data.title }}</h2>
{% for item in data.items %}
    <p{{ cb_lang(item, 'text') }}>{{ item.text }}</p>
{% endfor %}
```

It prints ` lang="fr"` when the field is shown in another language, and nothing
otherwise, including when the translation package is not installed.

## Opting into preview hot reload

By default an inline edit, add or duplicate of your block triggers a full iframe reload (moves and deletes never do). If your block's rendered **view** is self-contained (static HTML or CSS-only, no JS init needed once the markup lands in the DOM), opt into in-place swapping:

```php
public function supportsPreviewHotReload(): bool
{
    return true;
}
```

This is about the rendered view, not the edit form — a block whose *form* uses JavaScript (upload widget, rich-text editor) can still opt in, because that JS lives in the sidebar, never in the preview. If the view needs a little JS of its own, re-initialise idempotently from the `cb:block:rendered` event. See [Rendering → Preview hot reload](./rendering.md#preview-hot-reload) for the full details.

## Showing up in section-library thumbnails

Every card in the section library carries a thumbnail: a small diagram of the saved section, drawn from its stored payload — real column proportions, one tile per block. It is **not** a screenshot, so there is nothing to store, no image processing, and no headless browser anywhere.

The package knows the *structure* of a saved section, but not what your block **holds** — that is your business. So a block that owns something worth seeing at thumbnail size says so through the optional `BlockPreviewHintInterface`:

```php
use ContentBlocks\BlockType\AbstractBlockType;
use ContentBlocks\BlockType\BlockPreviewHint;
use ContentBlocks\BlockType\BlockPreviewHintInterface;

#[AsContentBlock]
final class QuoteBlock extends AbstractBlockType implements BlockPreviewHintInterface
{
    public function previewHint(array $data): ?BlockPreviewHint
    {
        return BlockPreviewHint::text($data['quote'] ?? null);
    }

    // getType(), buildForm(), … as usual
}
```

Six shapes are available, each a named constructor on `BlockPreviewHint`:

| Shape | Renders as | Use for |
|---|---|---|
| `image(string $src, ?string $caption = null)` | A real `<img>` pointing at the stored file | Pictures. An empty `$src` degrades to `generic($caption)` on its own. |
| `heading(?string $text)` | One emphasised line | Titles, the first item of an accordion or tab set. |
| `text(?string $text)` | Small copy, clamped to two lines | Paragraphs, list items joined together. |
| `button(?string $label)` | A pill | Calls to action. |
| `rule()` | A hairline | Dividers. |
| `generic(?string $text = null)` | A dashed tile bearing the block's label | Anything with nothing to preview. |

Text is collapsed onto one line and truncated for you, and an empty value degrades to `generic()` — you do not need to guard for either.

::: tip Implementing it is optional
A block that does not implement the interface still appears in the thumbnail, as a tile bearing its label. Nothing breaks and no existing block needs touching — add a hint when it buys recognition, skip it when it wouldn't.
:::

::: warning The `$data` you receive is raw and old
It is the payload exactly as it was persisted — possibly by an earlier version of your block, or by a host that has since changed its schema. Read defensively (null-coalesce, check types) and return `null` rather than assuming a shape. No [`BlockDataResolverInterface`](./rendering.md) has run on it either: a hint sees **stored** values, not resolved ones.
:::

Colours need no hint. Background colours live in the `styling` sub-form the package adds to every block and every section, so the thumbnail reads them directly — including the one a section inherits from its style preset — and repaints its copy for dark grounds.

## CSS and JS a block needs

A block whose view needs a stylesheet or a script of its own declares them, and the page links them **only where the block is rendered**, each one once however many such blocks the page holds:

```php
use ContentBlocks\BlockType\BlockAssets;
use ContentBlocks\BlockType\BlockAssetsInterface;

#[AsContentBlock]
final class MapBlock extends AbstractBlockType implements BlockAssetsInterface
{
    // …

    public function assets(array $data): BlockAssets
    {
        return new BlockAssets(
            stylesheets: ['blocks/map.css'],
            // Asked for only when this block needs it.
            modules: ($data['interactive'] ?? false) ? ['blocks/map.js'] : [],
        );
    }
}
```

- **`$data` is what the view receives**, so what is linked can follow the content: a gallery shown as a grid needs no carousel script.
- **Four lists**: `stylesheets` (`<link rel="stylesheet">`), `modules` (`<script type="module">`), `scripts` (classic, `<script defer>`), for a script that is not a module, and `entries`, for Webpack Encore (below).
- **A path or a URL.** A path (`blocks/map.css`) goes through Symfony's `asset()` when the app has `framework.assets`, so AssetMapper, an Encore manifest or a CDN base resolve it as for any other asset. An absolute path (`/build/map.js`) or a URL (`https://…`, `//…`) is linked as written.
- **The tags are emitted with the content area**, after the core's stylesheets, not in your `<head>`. A stylesheet there is valid HTML and modules and `defer` scripts run once the page is parsed.
- **In the builder**, a block added or edited in place brings its assets with it: the preview links what is missing. A script must therefore set up the blocks already on the page when it loads, and the ones that arrive later on `cb:block:rendered`:

```js
const setUp = (root) => root.querySelectorAll('[data-map]').forEach(initMap);
setUp(document);
document.addEventListener('cb:block:rendered', (event) => setUp(event.target));
```

A block type that does not implement the interface changes nothing on the page. The sandbox's `CounterBlock` is a working example.

### With AssetMapper: make the module an entrypoint, passed to no `importmap()`

A module served by AssetMapper keeps its `import` lines as written; it is the page's `importmap()` tag that tells the browser where they point. A module that imports nothing works on any page. For one with imports, two things are needed:

1. **Declare the block's module as an entrypoint** in `importmap.php`, and do **not** pass it to any `importmap()` call:

   ```php
   // importmap.php
   'blocks/map' => ['path' => './assets/blocks/map.js', 'entrypoint' => true],
   ```

2. **Render an importmap on your public pages**, with any entrypoint: `importmap('app')`, or a lighter one of their own (`importmap('front')`).

`importmap()` maps the imports of *every* entrypoint declared in `importmap.php`, but only runs the ones it is given. So the block's module, linked by the content area where the block renders, finds its imports (relative ones included), and runs nowhere else. Write it as any other AssetMapper module:

```js
// assets/blocks/map.js, declared as modules: ['blocks/map.js']
import { easeOut } from './easing.js';
```

Without step 1, a relative import asks for the unversioned file, gets a 404, and the script silently does nothing. Do not pass the block's entrypoint to `importmap()` either: it would run on every page.

Keep the block's CSS in `stylesheets` rather than importing it from the module. AssetMapper can link an imported stylesheet, but only once the script has run, so the block shows unstyled first; a `<link>` in the page loads with it, and styles the block's no-JS variants too.

### With Webpack Encore: declare the entry, not its file

An Encore entry is not one file. With `enableSingleRuntimeChunk()` and `splitEntryChunks()` (Encore's defaults), its JS needs `runtime.js` and the chunks it shares with other entries; linking the entry's own file alone loads a script that never runs, without an error. Declare the entry by name:

```js
// webpack.config.js
.addEntry('blocks/map', './assets/blocks/map.js')   // which imports './map.css'
```

```php
return new BlockAssets(entries: ['blocks/map']);
```

The page links what `entrypoints.json` lists for it, CSS included, as `encore_entry_script_tags()` would, and leaves out what your layout's own `encore_entry_*_tags()` already emitted in the same request: the runtime is linked once. That needs the layout's tags rendered before the content area, which is the case when they are in the `<head>`. Encore's default build is used. A file copied with `copyFiles()` is a plain path: `asset('build/…')` resolves it through the manifest (`framework.assets.json_manifest_path`).

`entries` are resolved by an adapter, `EntrypointResolverInterface`. The package ships the Encore one, `EncoreEntrypointResolver`, registered **only when `symfony/webpack-encore-bundle` is installed**: the package does not require it. Without an adapter, a block declaring `entries` raises an exception that says so.

### Another bundler: your own adapter

`resolve()` returns the entry's files as final URLs, so any bundler with a manifest can answer. A sketch for Vite (`pentatrion/vite-bundle`), whose entries are modules; the lookup methods are the bundle's, check them against your version:

```php
use ContentBlocks\BlockType\BlockAssets;
use ContentBlocks\Rendering\EntrypointResolverInterface;
use Pentatrion\ViteBundle\Service\EntrypointsLookupCollection;

final class ViteEntrypointResolver implements EntrypointResolverInterface
{
    public function __construct(private readonly EntrypointsLookupCollection $lookups)
    {
    }

    public function resolve(string $entry): BlockAssets
    {
        $lookup = $this->lookups->getEntrypointsLookup();

        return new BlockAssets(
            stylesheets: $lookup->getCSSFiles($entry),
            modules: $lookup->getJSFiles($entry),
        );
    }
}
```

```yaml
# config/services.yaml
ContentBlocks\Rendering\EntrypointResolverInterface: '@App\Assets\ViteEntrypointResolver'
```

Your alias wins over the Encore one if both bundles are installed.

## Styling your block

Custom blocks automatically get the shared **Styling** group (padding, margin, background color, max-width) in their edit form, and can reuse the palette color type and the `cb-condition` conditional-field controller. See [Styling](./styling.md) for how to extend the styling sub-form and add block decorators.

## Recipes

Common tasks that build on this page:

- [Add a field to an existing / kit block](./recipes/add-block-field.md) — e.g. a subtitle on the `button` block, without forking.
- [Add a field to the section settings](./recipes/add-section-field.md) — extend the section sidebar and render the value.
