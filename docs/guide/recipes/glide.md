---
title: Compress and convert images to WebP (Glide)
---

# Compress images with Glide

[Glide](https://glide.thephpleague.com/) (`league/glide`) is a framework-agnostic PHP library that resizes and converts images on demand, from parameters in the URL: `/img/blocks/a1b2.png?w=800&fm=webp`. It is the alternative to the [LiipImagine recipe](./liip-imagine.md) for a host that does not want a bundle, or already runs Glide.

The integration point is the same: [`ImageUrlResolverInterface`](../host-services.md#imageurlresolverinterface-responsive-images). One service alias, no template overridden, and every picture the kit renders (`image`, `gallery` items, `card` media) is covered.

This is what [`apps/content-blocks-encore-sandbox`](https://github.com/klehm/content-blocks-project/tree/master/apps/content-blocks-encore-sandbox) runs, under an end-to-end test that checks the public page really serves decodable WebP variants and refuses forged URLs.

## LiipImagine or Glide

| | LiipImagine | Glide |
|---|---|---|
| Shape | Symfony bundle | Library — you write one controller |
| Variants | Filter sets declared in YAML | Parameters in the URL |
| What stops abuse | Only declared filter sets exist | A signature on every URL |

**The signature is not optional.** An open `?w=` lets anyone request thousands of variants and fill the cache directory. Glide signs each URL with a key (here `kernel.secret`), so the server only answers URLs the resolver produced.

## 1. Install

```bash
composer require league/glide
```

Glide 3 runs on [Intervention Image](https://image.intervention.io/) with GD or Imagick. GD is enough for JPEG, PNG and WebP.

## 2. The Glide server and the signature

```yaml
# config/services.yaml
parameters:
    app.upload_directory: '%kernel.project_dir%/public/uploads/content-blocks'
    app.upload_prefix: '/uploads/content-blocks'

services:
    League\Glide\Server:
        factory: ['League\Glide\ServerFactory', 'create']
        arguments:
            - source: '%app.upload_directory%'
              cache: '%kernel.project_dir%/var/glide'
              driver: gd                # or imagick
              max_image_size: 4000000   # pixels, 2000×2000

    League\Glide\Signatures\SignatureInterface:
        factory: ['League\Glide\Signatures\SignatureFactory', 'create']
        arguments: ['%kernel.secret%']
```

Point the bundle's upload config at the same two parameters, so they cannot drift apart:

```yaml
# config/packages/content_blocks.yaml
content_blocks:
    upload:
        directory: '%app.upload_directory%'
        public_prefix: '%app.upload_prefix%'
```

Rooting `source` at the **upload directory**, not at `public/`, confines Glide to editor uploads: it cannot be asked to read anything else.

## 3. The controller

Glide makes the variant; serving it is yours to write.

```php
<?php

namespace App\Controller;

use Intervention\Image\Exceptions\DecoderException;
use League\Glide\Filesystem\FileNotFoundException;
use League\Glide\Server;
use League\Glide\Signatures\SignatureException;
use League\Glide\Signatures\SignatureInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;

final class GlideImageController
{
    public function __construct(
        private readonly Server $server,
        private readonly SignatureInterface $signature,
    ) {
    }

    #[Route('/img/{path}', name: 'app_glide_image', requirements: ['path' => '.+'], methods: ['GET'])]
    public function __invoke(string $path, Request $request): Response
    {
        $params = $request->query->all();

        try {
            $this->signature->validateRequest($path, $params);
        } catch (SignatureException) {
            return new Response('', Response::HTTP_FORBIDDEN);
        }

        try {
            $cached = $this->server->makeImage($path, $params);
        } catch (FileNotFoundException) {
            return new Response('', Response::HTTP_NOT_FOUND);
        } catch (DecoderException) {
            return new Response('', Response::HTTP_UNSUPPORTED_MEDIA_TYPE);
        }

        $cache = $this->server->getCache();

        return new StreamedResponse(static function () use ($cache, $cached): void {
            fpassthru($cache->readStream($cached));
        }, Response::HTTP_OK, [
            'Content-Type' => $cache->mimeType($cached),
            'Content-Length' => (string) $cache->fileSize($cached),
            'Cache-Control' => 'public, max-age=31536000, immutable',
        ]);
    }
}
```

A year of `immutable` cache is safe: the signature pins the parameters, and uploads are never overwritten (each file gets a random name), so the bytes behind a URL never change. Flysystem's local adapter refuses `..` in the path, so a crafted `{path}` cannot leave the upload directory either.

The route must be reachable by visitors: keep it outside any admin firewall.

## 4. The resolver

```php
<?php

namespace App\Image;

use ContentBlocks\Image\ImageUrlResolverInterface;
use ContentBlocks\Image\ResolvedImage;
use League\Glide\Signatures\SignatureInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class GlideImageUrlResolver implements ImageUrlResolverInterface
{
    private const WIDTHS = [400, 800, 1200, 1600];
    private const DEFAULT_WIDTH = 800;

    public function __construct(
        private readonly UrlGeneratorInterface $urls,
        private readonly SignatureInterface $signature,
        private readonly string $uploadPrefix,
    ) {
    }

    public function resolve(string $src, ?int $width = null, ?int $height = null): ResolvedImage
    {
        // Not one of our uploads (an absolute URL an editor pasted, say):
        // pass it through untouched.
        $prefix = rtrim($this->uploadPrefix, '/') . '/';
        $path = parse_url($src, \PHP_URL_PATH);
        if (!\is_string($path) || !str_starts_with($path, $prefix)) {
            return new ResolvedImage($src);
        }

        $path = substr($path, \strlen($prefix));
        $target = $width ?? self::DEFAULT_WIDTH;

        // A 400px box has no use for a 1600px file, even on a retina screen.
        $widths = array_values(array_filter(
            self::WIDTHS,
            static fn (int $w): bool => $w <= $target * 2,
        )) ?: [self::WIDTHS[0]];

        $srcset = [];
        $fallback = null;
        foreach ($widths as $w) {
            $srcset[] = $this->url($path, $w) . ' ' . $w . 'w';
            $fallback ??= $w >= $target ? $w : null;
        }

        return new ResolvedImage(
            $this->url($path, $fallback ?? end($widths)),
            implode(', ', $srcset),
        );
    }

    private function url(string $path, int $width): string
    {
        $params = ['w' => $width, 'fit' => 'max', 'fm' => 'webp', 'q' => 72];

        return $this->urls->generate('app_glide_image', ['path' => $path]
            + $this->signature->addSignature($path, $params));
    }
}
```

```yaml
# config/services.yaml
App\Image\GlideImageUrlResolver:
    arguments:
        $uploadPrefix: '%app.upload_prefix%'

ContentBlocks\Image\ImageUrlResolverInterface: '@App\Image\GlideImageUrlResolver'
```

That is the whole integration. An `image` block set to **Medium (800px)** now renders:

```html
<img class="cb-kit-image__img"
     src="/img/blocks/a1b2.png?w=800&fit=max&fm=webp&q=72&s=91e5…"
     srcset="/img/blocks/a1b2.png?w=400&…&s=ecc0…  400w,
             /img/blocks/a1b2.png?w=800&…&s=91e5…  800w,
             /img/blocks/a1b2.png?w=1200&…&s=412e… 1200w,
             /img/blocks/a1b2.png?w=1600&…&s=53fc… 1600w"
     sizes="(max-width: 800px) 100vw, 800px"
     width="800" loading="lazy" decoding="async">
```

Three choices worth understanding:

- **A fixed list of widths**, although Glide accepts any. Each distinct width is a file in the cache, so a short list keeps the cache bounded.
- **`fit=max` never upscales.** A 500px original stays 500px at `w=1600`: more bytes for no more detail is what the parameter prevents.
- **The signature is computed on the path relative to the upload directory**, not on the URL, so it survives the route being mounted elsewhere or the app living in a subdirectory.

The resolver's contract (`$width` is the display box or `null`, leave `sizes` to the view, never throw) is the same as in the [LiipImagine recipe](./liip-imagine.md#what-the-resolver-is-and-is-not-responsible-for).

## Two operational caveats

**PHP's built-in server needs a router script.** It treats any URL that ends in an extension as a static file, so `/img/blocks/a1b2.png?w=400` 404s instead of reaching the front controller. nginx and apache route everything to `index.php` and need nothing. For `php -S`, use the same [`public/router.php`](./liip-imagine.md#two-operational-caveats) as the LiipImagine recipe.

**Variants are generated on the request that first asks for them.** The first visitor after a publish pays for the resize. Glide then serves the cached file. Clearing `var/glide` is always safe: the variants come back on demand.
