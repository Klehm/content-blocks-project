<?php

declare(strict_types=1);

namespace App\Image;

use ContentBlocks\Image\ImageUrlResolverInterface;
use ContentBlocks\Image\ResolvedImage;
use League\Glide\Signatures\SignatureInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * The sandbox's Glide demo of the core image seam: every upload the kit
 * renders is served as a signed, WebP, width-capped variant.
 *
 * @see docs/guide/recipes/glide.md
 */
final class GlideImageUrlResolver implements ImageUrlResolverInterface
{
    /** @var list<int> */
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
        $prefix = rtrim($this->uploadPrefix, '/') . '/';
        $path = parse_url($src, \PHP_URL_PATH);
        if (!\is_string($path) || !str_starts_with($path, $prefix)) {
            return new ResolvedImage($src);
        }

        $path = substr($path, \strlen($prefix));
        $target = $width ?? self::DEFAULT_WIDTH;

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
        // fit=max never upscales: a 500px original stays 500px.
        $params = ['w' => $width, 'fit' => 'max', 'fm' => 'webp', 'q' => 72];

        return $this->urls->generate('app_glide_image', ['path' => $path]
            + $this->signature->addSignature($path, $params));
    }
}
