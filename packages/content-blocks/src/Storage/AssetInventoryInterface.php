<?php

declare(strict_types=1);

namespace ContentBlocks\Storage;

/**
 * Enumerates what a storage backend actually holds.
 *
 * Deliberately **separate from {@see FileStorageInterface}** rather than five
 * more methods on it: hosts alias that interface to their own S3/Flysystem
 * implementations, and widening it would break every one of them at the next
 * upgrade. Listing is also a genuinely different capability — a signed-URL CDN
 * bucket may be perfectly good at storing and reading while having no cheap way
 * to enumerate.
 *
 * A storage that does not implement this simply has no garbage collection:
 * {@see \ContentBlocks\Command\CollectAssetsCommand} says so and stops, rather
 * than reporting a successful sweep of nothing.
 */
interface AssetInventoryInterface
{
    /**
     * Every file the backend holds. Implementations should stream (yield)
     * rather than build one array — an established install has more files
     * than it has rows in any of the content tables.
     *
     * @return iterable<StoredAsset>
     */
    public function listAssets(): iterable;
}
