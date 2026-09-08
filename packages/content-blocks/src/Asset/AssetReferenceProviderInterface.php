<?php

declare(strict_types=1);

namespace ContentBlocks\Asset;

/**
 * "Here are the asset paths I still point at" — the mark half of the sweep,
 * autoconfigured, streamed. A host with its own files there registers one.
 *
 * @see docs/internals/assets.md#three-seams-and-why-each-is-its-own-interface
 */
interface AssetReferenceProviderInterface
{
    /**
     * @return iterable<string> asset paths, in the exact spelling the storage
     *                          backend produced them; duplicates are fine
     */
    public function referencedAssetPaths(): iterable;
}
