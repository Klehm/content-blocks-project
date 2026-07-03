<?php

declare(strict_types=1);

namespace ContentBlocks\Asset;

use ContentBlocks\Storage\FileStorageInterface;

/**
 * Adapts FileStorageInterface to AssetResolverInterface so the export/
 * import flow can locate, read, and write asset binaries through whatever
 * storage backend the host configured. This is the default alias for
 * AssetResolverInterface; with the default NullFileStorage behind it,
 * exports simply see no assets and imports throw on asset payloads —
 * the same net behavior NullAssetResolver used to provide.
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
