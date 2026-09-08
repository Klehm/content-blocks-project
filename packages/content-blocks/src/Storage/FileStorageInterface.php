<?php

declare(strict_types=1);

namespace ContentBlocks\Storage;

use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Where uploaded files live. Hosts alias it, so it stays narrow — enumeration
 * is separate ({@see AssetInventoryInterface}).
 *
 * @see NullFileStorage the throwing default
 * @see LocalFileStorage registered by `content_blocks.upload.directory`
 * @see docs/guide/security.md#file-upload
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
     * True if the value is a public path this backend manages — how the
     * exporter finds the references it embeds as base64.
     */
    public function isStoredPath(string $value): bool;

    /**
     * Returns the raw binary contents for a stored file by its public path,
     * or null if the file cannot be located.
     */
    public function read(string $publicPath): ?string;

    /**
     * Stores raw contents and returns the new public path — how an import
     * materializes base64 assets back onto the host's storage.
     */
    public function uploadFromString(string $contents, string $extension, string $directory = ''): string;
}
