# Block types — the interface and its optional companions

Integrator-facing version: [../guide/custom-blocks.md](../guide/custom-blocks.md).

## The view template contract

`getViewTemplate()` names the block's *view*, used for the public page and the
builder preview alike — the wrapper around it differs, the view does not.

Returning null renders **nothing**: the wrapper `<div>` comes out empty. There is
no generic fallback, so a block meant to be seen has to name a template.

The template is included with `with_context = false` and receives exactly one
variable, `data` — the payload after every registered `BlockDataResolverInterface`
has had its say. The block entity and the block type are deliberately out of
reach: a view renders stored values, it does not query the model.

Return a plain-namespace path (`@ContentBlocksKit/block/alert/view.html.twig`) so
a host can override it from `templates/bundles/`.

## Labels and icons cross a trust boundary

`getLabel()` returns a plain string for an already-translated label, or a
`TranslatableInterface` when the key lives in a custom domain — the renderer
translates it at the boundary, before it reaches a popover or a JSON endpoint.

`getIcon()` returns self-contained inline SVG, using `currentColor` so the icon
inherits the picker's theme. **The markup is injected as-is into the picker
DOM**, so it must come from trusted block-author code; never interpolate user
input into it. Null falls back to a generic icon.

## Preview hot reload is opt-in

`supportsPreviewHotReload()` says whether the builder may refresh this block's
preview in place instead of reloading the whole iframe.

It is about the **rendered view**, not the edit form. Return true only when the
view produces self-contained markup that works the moment it enters the DOM —
static HTML, CSS-only behaviour. Return false when the view needs a JavaScript
init pass (a carousel, a map, a third-party widget bootstrapped on load); the
builder then falls back to a full reload so that init runs again.

`AbstractBlockType` defaults to false, and the asymmetry is the reason: a
needless full reload costs a little performance, while a wrong hot reload leaves
a JS-dependent view broken. So blocks opt in explicitly.

A block that ships a little view JS *and* wants hot reload can return true and
re-initialise idempotently from the `cb:block:rendered` DOM event the overlay
dispatches on the freshly-swapped element.

A **section** is hot-insertable only when every one of its non-deleted blocks
opts in — one JS-dependent block forces the full reload. An empty section
trivially qualifies.

## Preview hints, and why they stay tiny

The core owns no opinion about the shape of a block's `data`; only the block type
does. `BlockPreviewHintInterface` is how a block says what is worth seeing at
thumbnail size, in terms the [poster renderer](section-templates.md#why-the-poster-is-a-spec)
understands without knowing anything about the block.

It is **optional on purpose**. A block that does not implement it still appears
in the poster, as a tile bearing its label, so nothing breaks and no existing
block needs touching.

`BlockPreviewHint` is deliberately small: six kinds, an optional line of text, an
optional image path. It describes *a tile in a thumbnail*, not the block — resist
growing it into a second rendering pipeline. Anything a hint cannot express is a
sign the poster should stay generic and let the real preview do its job.

Text is capped rather than rejected. A tile shows a line or two and a block has
no way of knowing that, so cutting at the hint keeps every caller honest and the
list payload small (ten templates times their blocks).

Two things to remember about the `data` a hint receives:

- it is **untrusted and possibly stale** — written by an older version of the
  block, or by a host that has since changed its schema. Read defensively and
  return `BlockPreviewHint::generic()` rather than assuming a shape;
- **no resolver has run on it.** A hint sees stored values, not resolved ones.

Returning null is equivalent to `generic()`: use it when this particular data has
nothing worth showing.

## Registration order is the picker order

`#[AsContentBlock]` auto-registers a block through `BlockTypeCompilerPass`, which
uses `findAndSortTaggedServices()` and therefore honours the attribute's
`priority`. The registry's insertion order is what the block-picker grid renders,
so priority is how a block chooses where it sits in that grid.
