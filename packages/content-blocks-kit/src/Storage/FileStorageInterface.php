<?php

declare(strict_types=1);

namespace ContentBlocks\Kit\Storage;

use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Abstraction for file storage. The host application must provide an implementation
 * (local filesystem, S3, Flysystem, etc.).
 *
 * Used by UploadController, ImageBlock, and the ContentArea export/import flow
 * (via FileStorageAssetResolver).
 */
interface FileStorageInterface
{
    /**
     * Stores an uploaded file and returns its public URL or path.
     */
    public function upload(UploadedFile $file, string $directory = ''): string;

    /**
     * Removes a previously stored file by its path/URL.
     */
    public function remove(string $path): void;

    /**
     * True if the given value is a public path managed by this storage
     * backend. Used by the export flow to detect asset references inside
     * block data and embed them as base64.
     */
    public function isStoredPath(string $value): bool;

    /**
     * Returns the raw binary contents for a stored file by its public path,
     * or null if the file cannot be located.
     */
    public function read(string $publicPath): ?string;

    /**
     * Stores raw binary contents under the given directory and returns the
     * new public path. Used by the import flow to materialize base64-encoded
     * assets back onto the host's storage.
     */
    public function uploadFromString(string $contents, string $extension, string $directory = ''): string;
}
