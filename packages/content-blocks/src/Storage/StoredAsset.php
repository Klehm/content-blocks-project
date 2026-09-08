<?php

declare(strict_types=1);

namespace ContentBlocks\Storage;

/**
 * One file as the storage backend sees it. The `lastModifiedAt` is not
 * decoration: it is what the garbage collector's retention window is measured
 * against, and the only thing standing between a sweep and a file uploaded
 * seconds ago whose block has not been saved yet.
 *
 * @see AssetInventoryInterface
 */
final class StoredAsset
{
    public function __construct(
        /** Public path/URL, in the exact spelling stored inside block data. */
        public readonly string $publicPath,
        public readonly int $size,
        public readonly \DateTimeImmutable $lastModifiedAt,
    ) {
    }
}
