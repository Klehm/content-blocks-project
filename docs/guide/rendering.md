---
title: Rendering
---

# Rendering the ContentArea

## Render on the public page

::: danger This step is required
Without it the builder iframe loads a page with no editable markers, so add-section trays, block toolbars and the preview overlay never appear.
:::

The builder is a thin shell that opens the host's **public** URL inside an iframe. All the in-context editing UI (section/block guides, "+ section" tray, overlay script) is injected by the package's render template **inside that public page**, so the public template must call `cb_render_content_area()` to produce the markers Stimulus controllers attach to:

```twig
{# templates/page/show.html.twig — your public template #}
<article>
    <h1>{{ page.title }}</h1>
    {{ cb_render_content_area(page.contentArea) }}
</article>
```

`cb_render_content_area()` accepts `null` and renders an empty string in that case, so you don't need an `{% if page.contentArea %}` guard around it when the host entity may not yet have a linked area.

## Preview vs public mode

Render-mode is auto-detected from the request:

- A query string `?cb_preview=1` **combined with** `AccessCheckerInterface::canEdit()` granting access switches to **preview** mode (markers + overlay injected).
- Anything else falls through to **public** mode (clean published HTML, no markers).

See [host services](./host-services.md#accesscheckerinterface-authorization) for `AccessCheckerInterface`, and [Security](./security.md#cross-firewall-auth-detection) for the cross-firewall gotcha that can silently keep the iframe in public mode.

### Draft content without the editing chrome

Preview mode decides two things at once: **which data** is rendered (draft) and
**what is drawn around it** (the toolbars, the add-section tray, the section
handles, `builder.css`, the overlay script). Add `?cb_chrome=0` to keep the first
and drop the second:

```
/my-page?cb_preview=1&cb_chrome=0
```

The result is the page as a reader will see it once published, drawn from
unpublished data — for a review link, an approval step, or a preview pane in a
tool that is not the builder. Soft-deleted sections, columns and blocks are left
out too: with no chrome to strike them through, showing them would read as live
content.

`data-cb-block-id` / `-section-id` markers stay, so a caller can still scroll to
a block or hot-swap one through `GET /_content-blocks/block/{id}/render`.

The parameter is **opt-out and preview-only**: absent (or any other value) keeps
today's chrome, and a public render never gains any. `klehm/content-blocks-i18n`'s
translation workbench is the first consumer.

## Changing what a block renders

Overriding a template changes *how* a block's data is presented. To change the **data itself** on the way to the template — without forking the renderer — register a `BlockDataResolverInterface`:

```php
use ContentBlocks\Entity\Block;
use ContentBlocks\Rendering\BlockDataResolverInterface;
use ContentBlocks\Rendering\RenderContext;

final class UppercaseTitles implements BlockDataResolverInterface
{
    public function resolve(Block $block, RenderContext $context, array $data): array
    {
        if (isset($data['title'])) {
            $data['title'] = strtoupper($data['title']);
        }

        return $data;
    }
}
```

No wiring beyond `autoconfigure: true`. Resolvers form a **pipeline**: each receives what the previous produced. The package's own `CoreBlockDataResolver` runs first (priority `256`) and seeds the payload from the block's draft-or-published slots, so yours transforms a populated array. To seed differently, register at a higher priority and ignore the incoming `$data`.

Keep resolvers side-effect free — one runs per block on every public page render.

::: info Contrast with block decorators
`BlockDecoratorInterface` contributes CSS classes, inline styles and attributes on the block's **wrapper**; it cannot touch `data`. Use a decorator for presentation, a resolver for content.
:::

### Render context and locale

Every entry point on `BlockRendererInterface` takes a `RenderContext` carrying the render mode and an optional locale. Both are optional and `null` means "decide for me":

```php
$renderer->render($area);                                  // mode from the request
$renderer->render($area, RenderContext::forPublic());      // force public
$renderer->render($area, RenderContext::forLocale('fr'));  // pin locale, keep mode detection
```

From Twig, pass the locale as a second argument:

```twig
{{ cb_render_content_area(page.contentArea, 'fr') }}
```

The core does nothing with the locale on its own — it is the input a locale-aware resolver reads. See below.

## Translatable fields

ContentBlocks is single-language by default: content translation is designed to live in a satellite package. What ships in the core is the **convention** that package reads, frozen with the 1.0 contract so blocks written today are already annotated.

A block declares which of its fields hold language-dependent values:

```php
$builder->add('heading', TextType::class, [
    'label' => 'Heading',
    'cb_translatable' => true,
]);
```

Tag prose (headings, body copy, labels, alt text, captions) and link targets — a localized site routinely points at `/fr/contact`. Leave out enums, colors, sizes and IDs: they are identical in every language. The kit tags 29 fields across its blocks on exactly this rule.

The option carries **no behaviour on its own**. With no translation package installed, tagging a field changes nothing at all — it is a declaration, read back through `TranslatableFieldsInterface`:

```php
$fields = $translatableFields->forBlockType('card');
// ['items[].title', 'items[].content', 'items[].url', 'items[].buttonText']
```

Nesting is dotted and collection entries are marked `[]`. Because it reads the **built form**, a field a host adds through a [block form extension](./custom-blocks.md) is picked up for free.

## Overriding render templates

The render pipeline is split into four templates so you can override the markup of an individual level (section, column, block) without forking the whole entry-point. Drop a file at the same relative path under `templates/bundles/ContentBlocksBundle/` in your host app to override one.

::: info Version note
Requires `klehm/content-blocks >= 0.1.0-alpha.4` for overrides to take priority. Earlier versions manually registered the vendor `templates/` path under `@ContentBlocks`, which (counter-intuitively) shadowed the host's `templates/bundles/ContentBlocksBundle/` directory.
:::

| Template | Receives | Responsibility |
|---|---|---|
| `@ContentBlocks/render/content_area.html.twig` | `sections` (array), `mode` (`RenderMode`), `blockTypes` (array) | Top-level wrapper, layout/builder CSS `<link>`s, sections loop, preview-only section tray + overlay scripts. |
| `@ContentBlocks/render/section.html.twig` | `section` (`Section`), `isPreview` (bool) | `<section class="cb-section …">` element, inline styles + extra attributes from section decorators, columns loop. |
| `@ContentBlocks/render/column.html.twig` | `column` (`Column`), `isPreview` (bool) | `<div class="cb-col …">` element, blocks loop, preview-only "+ block" inline button. |
| `@ContentBlocks/render/block.html.twig` | `block` (`Block`), `isPreview` (bool) | `<div class="cb-block …">` element, include of `block.viewTemplate` with `data`. |

Sub-templates are included with `with_context = false` — the listed variables are the contract; anything else from the parent scope is not available.

::: warning Keep the hooks intact
If you override `section`/`column`/`block`, keep the existing `cb-*` classes and `data-cb-*` attributes intact. The builder's Stimulus controllers and the preview-overlay script attach to those selectors; renaming them breaks the in-context editing UI.
:::

## Preview hot reload

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
