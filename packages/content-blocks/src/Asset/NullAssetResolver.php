<?php

declare(strict_types=1);

namespace ContentBlocks\Asset;

/**
 * No-op resolver: detects nothing, stores nothing. Exports succeed with no
 * assets embedded; an import carrying binaries fails loudly.
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
        throw new \LogicException('No AssetResolverInterface configured. The kit ships a default bridge (FileStorageAssetResolver) — register a FileStorageInterface implementation (e.g. LocalFileStorage) to enable asset import.');
    }
}
