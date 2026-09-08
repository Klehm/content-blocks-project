<?php

declare(strict_types=1);

namespace ContentBlocks\Twig;

use ContentBlocks\Image\ImageUrlResolverInterface;
use ContentBlocks\Image\ResolvedImage;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Exposes the image seam to view templates as `cb_image()`. Kept apart from
 * {@see ContentBlocksExtension} so a block-view test can register it alone.
 *
 * @see docs/internals/builder-extensions.md#why-the-twig-extensions-are-split
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
     * Width and height are the *display* box the template intends to use. Pass
     * null where the view pins none and let the resolver decide.
     */
    public function image(?string $src, ?int $width = null, ?int $height = null): ResolvedImage
    {
        $src = trim($src ?? '');

        // Spares implementations from special-casing the empty source, which
        // templates guard against anyway.
        if ($src === '') {
            return new ResolvedImage('');
        }

        return $this->resolver->resolve($src, $width, $height);
    }
}
