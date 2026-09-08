<?php

declare(strict_types=1);

namespace ContentBlocks\Storage;

/**
 * One file as the storage sees it. `lastModifiedAt` carries the retention
 * window — the only guard against sweeping a just-uploaded file.
 *
 * @see AssetInventoryInterface
 * @see docs/internals/assets.md#the-order-of-operations-is-the-safety-property
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
