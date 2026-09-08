<?php

declare(strict_types=1);

namespace ContentBlocks\Storage;

/**
 * Enumerates what a storage backend holds. Deliberately separate from
 * {@see FileStorageInterface}, which hosts alias and must stay narrow.
 *
 * @see docs/internals/assets.md#three-seams-and-why-each-is-its-own-interface
 */
interface AssetInventoryInterface
{
    /**
     * Every file the backend holds. Stream it: an established install has more
     * files than rows in any content table.
     *
     * @return iterable<StoredAsset>
     */
    public function listAssets(): iterable;
}
