<?php

declare(strict_types=1);

namespace ContentBlocks\Image;

/**
 * Seam between a stored image path and the URL(s) actually rendered.
 *
 * ContentBlocks deliberately ships no image processing: an uploaded file is
 * served as-is and only its *display box* is controlled by CSS. That covers the
 * free wins (no layout shift, lazy loading) but never reduces byte size, which
 * inherently needs either an image-processing library (LiipImagine, Glide, GD,
 * Imagick) or a transforming CDN — neither of which belongs in this package's
 * `require`.
 *
 * So this is an interface with a passthrough default, on the same pattern as
 * {@see \ContentBlocks\Storage\FileStorageInterface} and
 * {@see \ContentBlocks\Security\AccessCheckerInterface}: with nothing wired, the
 * output is byte-for-byte what it was before this seam existed; a host that
 * aliases its own implementation gets `srcset`/`sizes` everywhere the kit
 * renders an image, without touching a template.
 *
 * Implementations must be safe on any input: `$src` is whatever an editor stored
 * (a local path, an absolute URL, a leftover from a previous storage backend),
 * and returning `new ResolvedImage($src)` is always a valid answer — a resolver
 * that cannot transform a given source should say so by passing it through
 * rather than by throwing.
 */
interface ImageUrlResolverInterface
{
    /**
     * @param string   $src    the stored source, exactly as persisted in the block data
     * @param int|null $width  the display width in px the view intends to use, when it knows one
     * @param int|null $height the display height in px, when the view pins one
     */
    public function resolve(string $src, ?int $width = null, ?int $height = null): ResolvedImage;
}
