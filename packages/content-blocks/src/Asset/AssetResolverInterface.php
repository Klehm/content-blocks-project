<?php

declare(strict_types=1);

namespace ContentBlocks\Asset;

/**
 * Bridge between the export/import flow and the host's file storage. The
 * main package does not depend on the kit (where FileStorageInterface lives)
 * so it talks to assets through this interface. The kit provides a default
 * bridge (FileStorageAssetResolver) that delegates to FileStorageInterface.
 */
interface AssetResolverInterface
{
    /**
     * True if the value looks like a public asset path managed by the host's
     * storage backend (e.g. "/uploads/content-blocks/blocks/abc.png").
     */
    public function isAssetPath(string $value): bool;

    /**
     * Returns the raw binary contents for a stored asset, or null if the
     * file cannot be located (missing on disk, unknown prefix, etc.).
     */
    public function read(string $publicPath): ?string;

    /**
     * Stores raw binary contents and returns the new public path. The
     * extension is provided by the caller (sourced from the exported
     * metadata, not guessed from contents).
     */
    public function store(string $contents, string $extension): string;
}
