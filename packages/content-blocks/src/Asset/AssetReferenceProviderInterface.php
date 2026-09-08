<?php

declare(strict_types=1);

namespace ContentBlocks\Asset;

/**
 * "Here are the asset paths I still point at."
 *
 * The mark half of {@see AssetGarbageCollector}, and the seam that makes the
 * sweep safe to run at all. Same reasoning as
 * {@see \ContentBlocks\Security\AccessCheckerInterface}: the package cannot
 * know the host's model. A host that stores a Page's cover image in the same
 * upload directory registers one of these, or the sweep will correctly
 * conclude that no *block* references it and delete it.
 *
 * The package ships two implementations — one for content areas, one for the
 * section-template library — so core content is covered without any host
 * wiring, and the i18n package ships a third for translated values. All three
 * arrive through this same interface: there is no privileged internal path,
 * which is what keeps a host's provider on equal footing with the core's.
 *
 * Autoconfigured: implementing the interface is enough, no tag needed.
 * Implementations should stream (yield) rather than build one array.
 */
interface AssetReferenceProviderInterface
{
    /**
     * @return iterable<string> asset paths, in the exact spelling the storage
     *                          backend produced them; duplicates are fine
     */
    public function referencedAssetPaths(): iterable;
}
