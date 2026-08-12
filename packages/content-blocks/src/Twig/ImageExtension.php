<?php

declare(strict_types=1);

namespace ContentBlocks\Twig;

use ContentBlocks\Image\ImageUrlResolverInterface;
use ContentBlocks\Image\ResolvedImage;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Exposes the image seam to view templates as `cb_image()`.
 *
 * Kept apart from {@see ContentBlocksExtension} on purpose: this one depends on
 * a single resolver, so a block-view test can register it standalone without
 * standing up the renderer, the URL resolver and the palette.
 *
 *     {% set img = cb_image(data.src, 800) %}
 *     <img src="{{ img.src }}"
 *          {%- if img.srcset %} srcset="{{ img.srcset }}"{% endif %}>
 */
final class ImageExtension extends AbstractExtension
{
    public function __construct(
        private readonly ImageUrlResolverInterface $resolver,
    ) {
    }

    /**
     * @return list<TwigFunction>
     */
    public function getFunctions(): array
    {
        return [
            new TwigFunction('cb_image', [$this, 'image']),
        ];
    }

    /**
     * `{{ cb_image(src, width, height) }}` → a {@see ResolvedImage}.
     *
     * Width/height are the *display* box the template intends to use, which is
     * exactly the input a resizing resolver needs; pass null when the view does
     * not pin one (a fluid grid cell, say) and let the resolver decide.
     */
    public function image(?string $src, ?int $width = null, ?int $height = null): ResolvedImage
    {
        $src = trim($src ?? '');

        // Nothing to resolve — spare implementations from having to special-case
        // the empty source, which templates guard against anyway.
        if ($src === '') {
            return new ResolvedImage('');
        }

        return $this->resolver->resolve($src, $width, $height);
    }
}
