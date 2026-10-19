<?php

declare(strict_types=1);

namespace ContentBlocks\BlockType;

/**
 * The stylesheets and scripts a block needs on the page that shows it. Each is
 * an asset path or a URL; the page links each one once.
 *
 * @see docs/guide/custom-blocks.md#css-and-js-a-block-needs
 */
final class BlockAssets
{
    /**
     * @param list<string> $stylesheets `<link rel="stylesheet">`
     * @param list<string> $scripts     classic `<script defer>`
     * @param list<string> $modules     `<script type="module">`
     * @param list<string> $entries     Webpack Encore entry names: their
     *                                  runtime, chunks and CSS, deduplicated
     *                                  with the layout's own entries
     */
    public function __construct(
        public readonly array $stylesheets = [],
        public readonly array $scripts = [],
        public readonly array $modules = [],
        public readonly array $entries = [],
    ) {
    }

    public static function none(): self
    {
        return new self();
    }

    public function isEmpty(): bool
    {
        return $this->stylesheets === [] && $this->scripts === []
            && $this->modules === [] && $this->entries === [];
    }
}
