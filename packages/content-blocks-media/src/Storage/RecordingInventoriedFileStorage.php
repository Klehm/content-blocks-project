<?php

declare(strict_types=1);

namespace ContentBlocks\Media\Storage;

use ContentBlocks\Storage\AssetInventoryInterface;
use ContentBlocks\Storage\FileStorageInterface;

/**
 * {@see RecordingFileStorage} over a storage that lists its files: the asset
 * GC tests `instanceof AssetInventoryInterface`, so the decorator must too.
 *
 * @internal
 */
final class RecordingInventoriedFileStorage implements FileStorageInterface, AssetInventoryInterface
{
    use RecordsUploads;

    public function listAssets(): iterable
    {
        \assert($this->inner instanceof AssetInventoryInterface);

        return $this->inner->listAssets();
    }
}
