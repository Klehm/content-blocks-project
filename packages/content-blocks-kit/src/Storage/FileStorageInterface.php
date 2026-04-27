<?php

declare(strict_types=1);

namespace ContentBlocks\Kit\Storage;

use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Abstraction for file storage. The host application must provide an implementation
 * (local filesystem, S3, Flysystem, etc.).
 *
 * Used by UploadController and ImageBlock.
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
}
