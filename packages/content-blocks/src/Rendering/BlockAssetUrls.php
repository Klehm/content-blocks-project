<?php

declare(strict_types=1);

namespace ContentBlocks\Rendering;

use ContentBlocks\BlockType\BlockAssets;
use Symfony\Component\Asset\Packages;

/**
 * Turns the assets blocks declare into the URLs a page links, deduplicated in
 * order. Paths go through `assets.packages` when the host has it.
 *
 * @see docs/internals/rendering.md#css-and-js-a-block-declares
 */
final class BlockAssetUrls
{
    public function __construct(
        private readonly ?Packages $packages = null,
        private readonly ?EntrypointResolverInterface $entrypoints = null,
    ) {
    }

    /**
     * @param iterable<BlockAssets> $assets
     *
     * @return array{
     *     stylesheets: list<string>,
     *     scripts: list<string>,
     *     modules: list<string>,
     * }
     */
    public function collect(iterable $assets): array
    {
        $out = ['stylesheets' => [], 'scripts' => [], 'modules' => []];
        foreach ($assets as $set) {
            foreach (['stylesheets', 'scripts', 'modules'] as $kind) {
                foreach ($set->{$kind} as $path) {
                    $out[$kind][$this->url($path)] = true;
                }
            }
            foreach ($set->entries as $entry) {
                // Final URLs from the adapter: not resolved a second time.
                $files = $this->entrypoints()->resolve($entry);
                foreach (['stylesheets', 'scripts', 'modules'] as $kind) {
                    foreach ($files->{$kind} as $url) {
                        $out[$kind][$url] = true;
                    }
                }
            }
        }

        return array_map(array_keys(...), $out);
    }

    private function entrypoints(): EntrypointResolverInterface
    {
        return $this->entrypoints ?? throw new \LogicException('A block declares entries, and no EntrypointResolverInterface is registered: install symfony/webpack-encore-bundle, or alias one.');
    }

    private function url(string $path): string
    {
        // Already a URL: an absolute path, `//cdn…` or a scheme.
        if ($this->packages === null || preg_match('#^(/|[a-z][a-z0-9+.-]*:)#i', $path) === 1) {
            return $path;
        }

        return $this->packages->getUrl($path);
    }
}
