<?php

declare(strict_types=1);

namespace ContentBlocks\Image;

/**
 * What an {@see ImageUrlResolverInterface} hands back to a view template: the
 * URL to put in `src`, plus the optional responsive attributes.
 *
 * `srcset` and `sizes` are null when the resolver has nothing to offer — the
 * default {@see PassthroughImageUrlResolver} always returns them null, which is
 * what keeps the rendered markup identical to a plain `<img src="…">`.
 *
 * A template renders the attributes only when they are non-null; emitting an
 * empty `srcset=""` is not the same thing to a browser.
 */
final class ResolvedImage
{
    public function __construct(
        /** URL for the `src` attribute. */
        public readonly string $src,
        /** Candidate set for `srcset`, e.g. `/a-400.jpg 400w, /a-800.jpg 800w`. */
        public readonly ?string $srcset = null,
        /** Media-condition hints for `sizes`, e.g. `(max-width: 800px) 100vw, 800px`. */
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
