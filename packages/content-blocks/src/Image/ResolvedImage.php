<?php

declare(strict_types=1);

namespace ContentBlocks\Image;

/**
 * The URL for `src` plus the optional responsive attributes. A template
 * renders those only when non-null — an empty `srcset=""` is not the same.
 *
 * @see docs/internals/assets.md#the-image-seam-ships-a-passthrough
 */
final class ResolvedImage
{
    public function __construct(
        /** URL for the `src` attribute. */
        public readonly string $src,
        /** Candidates for `srcset`, e.g. `/a-400.jpg 400w, /a-800.jpg 800w`. */
        public readonly ?string $srcset = null,
        /** Hints for `sizes`, e.g. `(max-width: 800px) 100vw, 800px`. */
        public readonly ?string $sizes = null,
    ) {
    }

    /**
     * True when the resolver produced responsive candidates — the signal a
     * template uses to decide whether there is anything extra to render.
     */
    public function isResponsive(): bool
    {
        return $this->srcset !== null && $this->srcset !== '';
    }
}
