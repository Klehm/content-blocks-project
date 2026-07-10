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
