---
title: Headless rendering
---

# Headless rendering — the area as JSON

`cb_render_content_area()` renders HTML. When the page is rendered by a JavaScript front (Next, Nuxt, a mobile app) or the content feeds a search index, the same area is available **as data**: sections, columns and blocks as a tree of plain arrays, ready for `json_encode`.

The data render reads exactly what the HTML render reads. A visitor gets the **published** state, an editor asking for the preview gets the draft, and no builder action changes the public JSON until Publish. The [immutability tests](./rendering.md#what-public-mode-reads) cover both renders.

## Serving it

The package ships **no public route** for this. Only your application knows whether a page may be read at all (a disabled product, a members-only page). A `ContentArea` id says nothing about that. So you write the route, the same way you wrote the public page:

```php
use ContentBlocks\Headless\ContentAreaNormalizerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;

#[Route('/api/pages/{slug}', name: 'api_page')]
public function page(Page $page, ContentAreaNormalizerInterface $normalizer): JsonResponse
{
    if (!$page->isEnabled() || $page->getContentArea() === null) {
        throw $this->createNotFoundException();
    }

    return JsonResponse::fromJsonString($normalizer->toJson($page->getContentArea()));
}
```

The main sandbox runs this as `App\Controller\HeadlessController` (`/api/page/{id}`, and `/{_locale}/api/page/{id}` for a translation).

`toJson()` is the method to send over HTTP: `settings` and `data` stay JSON objects when empty (plain `json_encode` would write `[]`), and `<`, `>`, `&` and quotes are escaped, so the string is also safe inside a `<script>` element. `normalize()` returns the same tree as a PHP array, for your own code.

From Twig, to hand the content to a front-end component:

```twig
<script type="application/json" id="page-content">{{ cb_content_area_json(page.contentArea) }}</script>
{% set tree = cb_content_area_data(page.contentArea) %}  {# the array #}
```

Both accept `null` (`null` / `'null'` back) and a locale as second argument, like `cb_render_content_area()`.

## Mode and locale

The arguments are those of the HTML renderer, a [`RenderContext`](./rendering.md#render-context-and-locale):

```php
$normalizer->toJson($area);                                 // mode from the request
$normalizer->toJson($area, RenderContext::forPublic());     // always published
$normalizer->toJson($area, RenderContext::forPreview());    // the draft
$normalizer->toJson($area, RenderContext::forLocale('en')); // a translation
```

With no mode, the request decides, by the same rule as the page: the draft only for `?cb_preview=1` **and** an `AccessCheckerInterface::canEdit()` that grants the area. Anyone else gets the published tree. Pinning `forPreview()` skips that check, so only do it behind your own authorization.

With `klehm/content-blocks-i18n` installed, the locale works exactly as on the HTML page: translated values, per-field fallback, and the area's translations loaded in one query.

## The tree

```json
{
  "format": 1,
  "id": 12,
  "mode": "public",
  "locale": "fr",
  "contentVersion": 1,
  "sections": [
    {
      "id": 40,
      "layout": "two_cols",
      "display": { "desktop": "grid", "tablet": "grid", "mobile": "accordion" },
      "accordion": { "single": false, "collapsed": false },
      "slider": null,
      "order": { "tablet": null, "mobile": null },
      "settings": { "styleName": "boxed", "styling": { "backgroundColor": "#f5f5f5" } },
      "columns": [
        {
          "id": 81,
          "preset": "col-6",
          "span": 6,
          "width": null,
          "label": "Overview",
          "blocks": [
            {
              "id": 133,
              "type": "title",
              "data": { "tag": "h2", "size": "h1", "text": "Hello", "color": "" },
              "order": { "tablet": null, "mobile": 0 }
            }
          ]
        }
      ]
    }
  ]
}
```

| Key | What it holds |
|---|---|
| `format` | The shape of this tree, `1`. It changes only in a major release. |
| `mode` | `public` or `preview`: which state was read. |
| `locale` | The locale the tree was rendered in: the one you passed, else the request's. |
| `contentVersion` | The area's [content version](./content-versioning.md) stamp, `null` before versioning. |
| `sections[].display` | The display per viewport, already resolved (`grid`, `tabs`, `accordion`, `slider`): tablet and mobile inherit from above and can only get more compact. |
| `sections[].accordion` / `slider` | The options of that display, `null` when no viewport uses it. `slider.perView` is the slides shown at once per viewport. |
| `sections[].settings` | The settings the HTML render reads: the style preset merged underneath, values equal to their default left out. |
| `columns[].span` | The width on the 12-unit grid, from `preset`. `width` is the custom weight from the *Column widths* field, `null` for equal widths. |
| `columns[].label` | The column's title, shown by the tabs and accordion displays. |
| `blocks[].data` | The block's data, after the [data resolvers](./rendering.md#changing-what-a-block-renders) and the [block normalizers](#what-a-block-sends). |
| `order` | The position on tablet / mobile among siblings when an editor reordered them for that viewport. `null` means the desktop order. |

The order is the order of the arrays. Soft-deleted sections, columns and blocks are left out of the preview, as with `cb_chrome=0`.

Collection entries keep their `_id`, stable across edits, so a client can use it as a key. With `fallback_lang` on, the i18n package's `_lang` / `_block_lang` markers are in `data` too ([Translation](./translation.md)).

There is no class name, inline style or markup anywhere: presentation is the client's. The [kit's tokens](../kit/#the-kits-own-tokens) and the values in `settings.styling` are what you style from.

## What a block sends

A block's data is stored for its Twig view, and the view also cleans it on the way out: it resolves image URLs, sanitizes HTML and drops unsafe links. A JSON client has no view, so a **block normalizer** does that work for it:

```php
use ContentBlocks\Headless\BlockJsonNormalizerInterface;
use ContentBlocks\Rendering\BlockView;
use ContentBlocks\Rendering\RenderContext;

final class ProductBlockNormalizer implements BlockJsonNormalizerInterface
{
    public function __construct(private readonly ProductRepository $products)
    {
    }

    public function normalize(BlockView $block, array $data, RenderContext $context): array
    {
        if ($block->type !== 'product') {
            return $data;
        }

        $product = $this->products->find($data['productId'] ?? 0);

        return $data + ['product' => $product ? ['name' => $product->getName($context->locale)] : null];
    }
}
```

With `autoconfigure: true` there is nothing else to wire. Normalizers form a **pipeline**, like data resolvers: each receives what the previous one returned and checks the type itself. Each one runs on every block of every JSON response, so keep it free of side effects.

The difference from a [data resolver](./rendering.md#changing-what-a-block-renders): a resolver changes what **both** renders show, a normalizer only what the JSON shows. Use a normalizer for what a view template would do: build a URL, sanitize, expand an id into the record it points to.

A block type with no normalizer sends its data as resolved. That is correct for plain values (text, choices, numbers). If its data holds HTML or links the view used to clean up, write a normalizer for it.

## The kit blocks

The kit registers one normalizer for its own blocks. It applies the same rules as the kit's views:

| Block | In the JSON |
|---|---|
| `rich_text` | `content` sanitized by the same sanitizer as the view (`content_blocks_kit.rich_text_sanitizer`). |
| `image` | `src` is the URL the [`ImageUrlResolverInterface`](./host-services.md) returns, at the width of the block's size, with `srcset` and `sizes` beside it (`null` when the resolver gives none). |
| `gallery`, `card` | The same for each entry's `src`. |
| `video` | `poster` resolved the same way, with `posterSrcset` / `posterSizes`. `src` is the video file, as stored. |
| `button`, `button_group`, `image`, `card`, `gallery`, `breadcrumb` | Each `url` goes through the same check as `cb_kit_safe_url`. A `javascript:` or `data:` link becomes `''`. |
| `video` | `captions`, the same check. |
| `embed` | `embedUrl`, the player URL (`null` for an unsupported link). |
| `html_raw` | Sent as stored. The block trusts its editors, which is why it is [off by default](./security.md). |

The kit normalizer only handles the kit's own block types. If you register your own `rich_text`, the kit leaves it alone.

## Replacing it

`ContentAreaNormalizerInterface` is an alias you can decorate. The i18n package decorates it to load translations in advance. A decorator that adds keys to the tree is fine. Before removing one, remember that `format: 1` is a promise to your clients.
