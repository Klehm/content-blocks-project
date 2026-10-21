<?php

declare(strict_types=1);

namespace ContentBlocks\Media\Asset;

use ContentBlocks\Asset\AssetReferenceProviderInterface;
use ContentBlocks\Media\Storage\MediaRows;

/**
 * Every file the library holds counts as referenced, so the asset GC sweeps
 * one only after it has been removed from the library.
 *
 * @see docs/internals/media.md#the-library-is-a-reference
 */
final class LibraryAssetReferenceProvider implements AssetReferenceProviderInterface
{
    public function __construct(private readonly MediaRows $rows)
    {
    }

    public function referencedAssetPaths(): iterable
    {
        return $this->rows->paths();
    }
}
