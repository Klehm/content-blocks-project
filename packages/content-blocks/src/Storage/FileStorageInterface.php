<?php

declare(strict_types=1);

namespace ContentBlocks\Storage;

use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Abstraction for file storage, used by the builder's upload endpoint
 * ({@see \ContentBlocks\Controller\UploadController}), upload-driven form
 * fields, and the ContentArea export/import flow (via
 * {@see \ContentBlocks\Asset\FileStorageAssetResolver}).
 *
 * Defaults to {@see NullFileStorage} (throws on upload). The quickest opt-in
 * is the bundle config — it registers a {@see LocalFileStorage}:
 *
 *     content_blocks:
 *         upload:
 *             dir: '%kernel.project_dir%/public/uploads/content-blocks'
 *             public_prefix: '/uploads/content-blocks'
 *
 * For S3/Flysystem/CDN storage, alias this interface to your own
 * implementation instead.
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
