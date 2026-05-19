<?php

declare(strict_types=1);

namespace ContentBlocks\Kit\Asset;

use ContentBlocks\Asset\AssetResolverInterface;
use ContentBlocks\Kit\Storage\FileStorageInterface;

/**
 * Adapts the kit's FileStorageInterface to the main package's
 * AssetResolverInterface so the export/import flow can locate, read, and
 * write asset binaries without depending on the kit directly.
 */
final class FileStorageAssetResolver implements AssetResolverInterface
{
    public function __construct(
        private readonly FileStorageInterface $fileStorage,
    ) {
    }

    public function isAssetPath(string $value): bool
    {
        return $this->fileStorage->isStoredPath($value);
    }

    public function read(string $publicPath): ?string
    {
        return $this->fileStorage->read($publicPath);
    }

    public function store(string $contents, string $extension): string
    {
        return $this->fileStorage->uploadFromString($contents, $extension, 'blocks');
    }
}
