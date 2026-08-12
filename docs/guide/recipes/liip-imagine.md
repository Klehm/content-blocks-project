---
title: Compress and convert images to WebP (LiipImagine)
---

# Compress images and serve WebP

ContentBlocks serves an uploaded file exactly as it was stored. That is deliberate — shrinking bytes needs either an image-processing library or a transforming CDN, and neither belongs in a page builder's dependencies. What the package ships instead is the seam: [`ImageUrlResolverInterface`](../host-services.md#imageurlresolverinterface-responsive-images), with a passthrough default.

This recipe wires [LiipImagine](https://github.com/liip/LiipImagineBundle) behind it, so that a 4000px JPEG dropped into a 400px card is served as a 400px WebP. **One service alias is the entire opt-in** — no template is overridden, and every picture the kit renders (`image`, `gallery` items, `card` media) is covered at once.

It is not a sketch: this is what [`apps/content-blocks-sandbox`](https://github.com/klehm/content-blocks-project/tree/master/apps/content-blocks-sandbox) runs, and an end-to-end test asserts the rendered page really serves decodable WebP variants.

## 1. Install

```bash
composer require liip/imagine-bundle
```

Needs an imagine driver: GD (bundled with most PHP builds, and enough for JPEG/PNG/WebP) or Imagick (better color-profile handling, and AVIF).

## 2. One filter set per candidate width

```yaml
# config/packages/liip_imagine.yaml
liip_imagine:
    driver: gd

    # Uploads live under public/, and the stored src is their public path
    # (/uploads/content-blocks/blocks/<hash>.png), so rooting the loader at
    # public/ is all it takes to find them.
    loaders:
        default:
            filesystem:
                data_root: '%kernel.project_dir%/public'

    filter_sets:
        cache: ~
        cb_w400:  { quality: 72, format: webp, filters: { thumbnail: { size: [400, 4000],   mode: inset, allow_upscale: false } } }
        cb_w800:  { quality: 72, format: webp, filters: { thumbnail: { size: [800, 8000],   mode: inset, allow_upscale: false } } }
        cb_w1200: { quality: 72, format: webp, filters: { thumbnail: { size: [1200, 12000], mode: inset, allow_upscale: false } } }
        cb_w1600: { quality: 72, format: webp, filters: { thumbnail: { size: [1600, 16000], mode: inset, allow_upscale: false } } }
```

Three choices worth understanding:

- **`format: webp` is the conversion**, `quality: 72` the compression. Both apply to every candidate.
- **`mode: inset`** keeps the aspect ratio and never crops; the tall bound is a ceiling a normal photo never reaches, so the width is what actually constrains.
- **`allow_upscale: false`** matters more than it looks. Without it, a 500px original is blown up to 1600px — more bytes for no more detail.

Add the bundle's routes so variants can be generated on demand:

```yaml
# config/routes/liip_imagine.yaml
_liip_imagine:
    resource: '@LiipImagineBundle/Resources/config/routing.yaml'
```

## 3. The resolver

Forty lines of your own code, and the only place the two libraries meet:

```php
<?php

namespace App\Image;

use ContentBlocks\Image\ImageUrlResolverInterface;
use ContentBlocks\Image\ResolvedImage;
use Liip\ImagineBundle\Imagine\Cache\CacheManager;

final class LiipImagineImageUrlResolver implements ImageUrlResolverInterface
{
    private const FILTERS = [400 => 'cb_w400', 800 => 'cb_w800', 1200 => 'cb_w1200', 1600 => 'cb_w1600'];
    private const DEFAULT_WIDTH = 800;

    public function __construct(private readonly CacheManager $cache)
    {
    }

    public function resolve(string $src, ?int $width = null, ?int $height = null): ResolvedImage
    {
        // Not one of our uploads — an absolute URL an editor pasted, a path
        // served by a controller: pass it through untouched.
        if (!str_starts_with($src, '/uploads/')) {
            return new ResolvedImage($src);
        }

        $path = ltrim(parse_url($src, \PHP_URL_PATH) ?: $src, '/');
        $target = $width ?? self::DEFAULT_WIDTH;

        // A 400px box has no use for a 1600px file, even on a retina screen.
        $widths = array_values(array_filter(
            array_keys(self::FILTERS),
            static fn (int $w): bool => $w <= $target * 2,
        )) ?: [array_key_first(self::FILTERS)];

        $srcset = [];
        foreach ($widths as $w) {
            $srcset[] = $this->cache->getBrowserPath($path, self::FILTERS[$w]) . ' ' . $w . 'w';
        }

        $fallback = null;
        foreach ($widths as $w) {
            $fallback ??= $w >= $target ? $w : null;
        }
        $fallback ??= end($widths);

        return new ResolvedImage(
            $this->cache->getBrowserPath($path, self::FILTERS[$fallback]),
            implode(', ', $srcset),
        );
    }
}
```

```yaml
# config/services.yaml
ContentBlocks\Image\ImageUrlResolverInterface:
    class: App\Image\LiipImagineImageUrlResolver
```

That is the whole integration. An `image` block set to **Medium (800px)** now renders:

```html
<img class="cb-kit-image__img"
     src="/media/cache/cb_w800/uploads/content-blocks/blocks/a1b2.png"
     srcset="/media/cache/cb_w400/uploads/…  400w,
             /media/cache/cb_w800/uploads/…  800w,
             /media/cache/cb_w1200/uploads/… 1200w,
             /media/cache/cb_w1600/uploads/… 1600w"
     sizes="(max-width: 800px) 100vw, 800px"
     width="800" loading="lazy" decoding="async">
```

## What the resolver is and is not responsible for

- **`$width` / `$height` are the display box the view intends.** The `image` block passes its preset (sm=400, md=800, lg=1200) or its custom width; a fluid view — a `full` image, a gallery cell, card media — passes `null`, because there is no honest number to pass. That is why the example needs a `DEFAULT_WIDTH`.
- **Leave `sizes` alone unless you know better.** The `image` block derives `(max-width: Wpx) 100vw, Wpx` from the width it pinned when the resolver returns none. Returning your own overrides that and is the right move when your layout knows something the block does not.
- **Never throw on a source you cannot handle.** `$src` is whatever an editor stored: a local path, an absolute URL, a leftover from a previous storage backend, a value pasted through the field's link toggle. `return new ResolvedImage($src)` is always a valid answer, and the guard above is what keeps a foreign URL from being mangled into a 404.

## Two operational caveats

**The cached file keeps the source extension.** LiipImagine names the variant after the original, so a WebP lands at `…/photo.png` and your web server labels it `image/png`. Browsers detect image formats by content, so `<img>` renders it correctly — but if the header matters to you (CDN behavior, `Accept` negotiation), reach for LiipImagine's own [WebP support](https://symfony.com/bundles/LiipImagineBundle/current/basic-usage.html) (`liip_imagine.webp.generate`), which stores a `.webp` twin and negotiates it server-side.

**PHP's built-in server cannot generate variants without a router script.** It treats any URL that looks like a file as a static asset, so LiipImagine's lazy `/media/cache/resolve/…/photo.png` 404s instead of reaching the front controller. nginx and apache route everything to `index.php` and need nothing. For `php -S`, the sandbox ships [`public/router.php`](https://github.com/klehm/content-blocks-project/blob/master/apps/content-blocks-sandbox/public/router.php):

```php
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', \PHP_URL_PATH) ?: '/';

if ($path !== '/' && is_file(__DIR__ . urldecode($path))) {
    return false;   // let the built-in server serve the real file
}

return require __DIR__ . '/index.php';
```

```bash
php -S 127.0.0.1:8000 -t public public/router.php
```

## Other backends

The seam does not care which one you use. A transforming CDN (Cloudflare Images, imgix, Cloudinary) is often the better production answer — the resolver becomes pure URL building, with no PHP image processing and no cache directory to manage. See the [CDN example](../host-services.md#imageurlresolverinterface-responsive-images) in the host-services guide.
