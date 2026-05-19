<?php

declare(strict_types=1);

namespace ContentBlocks\Asset;

/**
 * Default no-op resolver. Detects no asset paths, reads nothing, and refuses
 * to store. Exports succeed (no assets are embedded), imports fail loudly if
 * the payload contains binaries — which is the expected behavior when the
 * host has not configured file storage.
 */
final class NullAssetResolver implements AssetResolverInterface
{
    public function isAssetPath(string $value): bool
    {
        return false;
    }

    public function read(string $publicPath): ?string
    {
        return null;
    }

    public function store(string $contents, string $extension): string
    {
        throw new \LogicException(
            'No AssetResolverInterface configured. The kit ships a default '
            . 'bridge (FileStorageAssetResolver) — register a FileStorageInterface '
            . 'implementation (e.g. LocalFileStorage) to enable asset import.'
        );
    }
}
