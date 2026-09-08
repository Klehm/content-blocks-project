<?php

declare(strict_types=1);

namespace ContentBlocks\Asset;

/**
 * How the export/import flow recognizes, reads and writes an asset, without
 * naming a storage. Default: {@see FileStorageAssetResolver}.
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
     * Stores raw contents and returns the new public path. The extension comes
     * from the exported metadata, never guessed from the bytes.
     */
    public function store(string $contents, string $extension): string;
}
