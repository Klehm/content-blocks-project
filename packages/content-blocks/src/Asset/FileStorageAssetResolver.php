<?php

declare(strict_types=1);

namespace ContentBlocks\Asset;

use ContentBlocks\Storage\FileStorageInterface;

/**
 * Adapts {@see FileStorageInterface} to {@see AssetResolverInterface}, and is
 * the default alias. Over NullFileStorage: exports see no assets.
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
