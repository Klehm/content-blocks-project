<?php

declare(strict_types=1);

namespace App\Image;

use ContentBlocks\Image\ImageUrlResolverInterface;
use ContentBlocks\Image\ResolvedImage;
use Liip\ImagineBundle\Imagine\Cache\CacheManager;

/**
 * The sandbox's demo of the core image seam: every picture the kit renders is
 * served as a WebP variant sized for the box it lands in.
 *
 * ContentBlocks knows nothing about LiipImagine — this class is ~40 lines of
 * host code, and the bundle's own default (`PassthroughImageUrlResolver`) is
 * what runs when a host writes none. Documented in
 * docs/guide/recipes/liip-imagine.md.
 */
final class LiipImagineImageUrlResolver implements ImageUrlResolverInterface
{
    /**
     * Candidate widths, each mapped to the filter set that produces it (see
     * config/packages/liip_imagine.yaml). Every filter converts to WebP.
     *
     * @var array<int, string>
     */
    private const FILTERS = [
        400 => 'cb_w400',
        800 => 'cb_w800',
        1200 => 'cb_w1200',
        1600 => 'cb_w1600',
    ];

    /**
     * What `src` falls back to when the view pins no display width — a fluid
     * gallery cell, a card tile. Browsers that understand `srcset` never fetch
     * it; the ones that don't get a sane middle.
     */
    private const DEFAULT_WIDTH = 800;

    public function __construct(private readonly CacheManager $cache)
    {
    }

    public function resolve(string $src, ?int $width = null, ?int $height = null): ResolvedImage
    {
        // Anything that is not one of our own uploads — an absolute URL an
        // editor pasted, a path served by a controller — is passed through
        // untouched. The loader could not read it anyway, and guessing is how a
        // resolver breaks a page it does not own.
        if (!str_starts_with($src, '/uploads/')) {
            return new ResolvedImage($src);
        }

        $path = ltrim(parse_url($src, \PHP_URL_PATH) ?: $src, '/');
        $target = $width ?? self::DEFAULT_WIDTH;

        // Cap the candidates at twice the display width: a 400px box has no use
        // for a 1600px file, even on a retina screen.
        $widths = array_values(array_filter(array_keys(self::FILTERS), static fn (int $w): bool => $w <= $target * 2));
        if ($widths === []) {
            $widths = [array_key_first(self::FILTERS)];
        }

        $srcset = [];
        foreach ($widths as $w) {
            $srcset[] = $this->cache->getBrowserPath($path, self::FILTERS[$w]) . ' ' . $w . 'w';
        }

        // `src` is the smallest candidate that still covers the display box.
        $fallback = null;
        foreach ($widths as $w) {
            if ($fallback === null && $w >= $target) {
                $fallback = $w;
            }
        }
        $fallback ??= end($widths);

        return new ResolvedImage(
            $this->cache->getBrowserPath($path, self::FILTERS[$fallback]),
            implode(', ', $srcset),
            // No `sizes`: the image block knows its own display width and
            // derives a truthful one; the fluid views have none to give.
            null,
        );
    }
}
