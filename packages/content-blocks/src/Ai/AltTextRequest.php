<?php

declare(strict_types=1);

namespace ContentBlocks\Ai;

/**
 * An image to describe. `src` is what the field stores; `url` is absolute, so
 * a remote model can fetch it.
 */
final class AltTextRequest
{
    /**
     * @param string|null $blockType the block holding the image, if known
     * @param string|null $context   text near the image (the block's title)
     * @param string|null $brief     what the host says about the page
     */
    public function __construct(
        public readonly string $src,
        public readonly string $url,
        public readonly string $locale,
        public readonly ?string $blockType = null,
        public readonly ?string $context = null,
        public readonly ?string $brief = null,
    ) {
    }
}
