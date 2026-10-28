<?php

declare(strict_types=1);

namespace ContentBlocks\Sylius\Image;

use ContentBlocks\Image\ImageUrlResolverInterface;
use ContentBlocks\Image\ResolvedImage;
use Liip\ImagineBundle\Imagine\Cache\CacheManager;

/**
 * The builder's images through the shop's LiipImagine: WebP, sized for the
 * box they land in, with a `srcset`. Other URLs are passed through.
 *
 * @see docs/internals/sylius.md#images-through-liipimagine
 */
final class LiipImageUrlResolver implements ImageUrlResolverInterface
{
    /** Candidate width => filter set, prepended by the plugin. */
    public const FILTERS = [
        400 => 'content_blocks_sylius_w400',
        800 => 'content_blocks_sylius_w800',
        1200 => 'content_blocks_sylius_w1200',
        1600 => 'content_blocks_sylius_w1600',
    ];

    /** For a view that pins no width: a fluid tile, a card. */
    private const DEFAULT_WIDTH = 800;

    public function __construct(
        private readonly CacheManager $cache,
        private readonly string $publicDir,
    ) {
    }

    public function resolve(string $src, ?int $width = null, ?int $height = null): ResolvedImage
    {
        $path = $this->localPath($src);
        if ($path === null) {
            return new ResolvedImage($src);
        }

        // Up to twice the box, for dense screens; a smaller box wants no more.
        $target = $width ?? self::DEFAULT_WIDTH;
        $widths = array_values(array_filter(
            array_keys(self::FILTERS),
            static fn (int $w): bool => $w <= $target * 2,
        ));
        $widths = $widths === [] ? [array_key_first(self::FILTERS)] : $widths;

        $srcset = [];
        $fallback = null;
        foreach ($widths as $w) {
            $srcset[] = $this->cache->getBrowserPath($path, self::FILTERS[$w]) . ' ' . $w . 'w';
            if ($fallback === null && $w >= $target) {
                $fallback = $w;
            }
        }
        $fallback ??= $widths[\count($widths) - 1];

        return new ResolvedImage(
            $this->cache->getBrowserPath($path, self::FILTERS[$fallback]),
            implode(', ', $srcset),
        );
    }

    /** A file under the public directory, where the filter sets read it. */
    private function localPath(string $src): ?string
    {
        if (!str_starts_with($src, '/') || str_starts_with($src, '//')) {
            return null;
        }
        $path = ltrim((string) parse_url($src, \PHP_URL_PATH), '/');
        // A vector stays sharp as is; WebP would freeze an animated GIF.
        $extension = strtolower(pathinfo($path, \PATHINFO_EXTENSION));
        if ($path === '' || str_contains($path, '..') || \in_array($extension, ['svg', 'gif'], true)) {
            return null;
        }

        return is_file($this->publicDir . '/' . $path) ? $path : null;
    }
}
