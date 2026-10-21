<?php

declare(strict_types=1);

namespace ContentBlocks\Media\Storage;

use ContentBlocks\Storage\FileStorageInterface;

/**
 * Decorates the host's storage so every stored file enters the library. Built
 * by {@see self::decorate()}, which keeps the inner storage's inventory.
 *
 * @internal
 *
 * @see docs/internals/media.md#the-decorator-keeps-the-inventory
 */
final class RecordingFileStorage implements FileStorageInterface
{
    use RecordsUploads;

    public static function decorate(FileStorageInterface $inner, UploadRecorder $recorder): FileStorageInterface
    {
        return $inner instanceof \ContentBlocks\Storage\AssetInventoryInterface
            ? new RecordingInventoriedFileStorage($inner, $recorder)
            : new self($inner, $recorder);
    }
}
