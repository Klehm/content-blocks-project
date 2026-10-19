<?php

declare(strict_types=1);

namespace ContentBlocks\Rendering;

use ContentBlocks\BlockType\BlockAssets;
use Symfony\WebpackEncoreBundle\Asset\EntrypointLookupCollectionInterface;

/**
 * The Webpack Encore adapter: an entry's CSS, runtime and chunks from
 * `entrypoints.json`, registered only when WebpackEncoreBundle is installed.
 *
 * @see docs/internals/rendering.md#css-and-js-a-block-declares
 */
final class EncoreEntrypointResolver implements EntrypointResolverInterface
{
    public function __construct(
        private readonly ?EntrypointLookupCollectionInterface $lookups = null,
    ) {
    }

    public function resolve(string $entry): BlockAssets
    {
        // Encore dedupes per request: what the layout's encore_entry_*_tags()
        // already emitted is not returned again.
        $lookup = $this->lookups()->getEntrypointLookup();

        return new BlockAssets(
            stylesheets: array_values($lookup->getCssFiles($entry)),
            scripts: array_values($lookup->getJavaScriptFiles($entry)),
        );
    }

    private function lookups(): EntrypointLookupCollectionInterface
    {
        return $this->lookups ?? throw new \LogicException('WebpackEncoreBundle is installed but not enabled in the kernel.');
    }
}
