<?php

declare(strict_types=1);

namespace ContentBlocks\Kit\Storage;

use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * No-op storage. Used as the default when no FileStorageInterface is configured.
 * Any upload attempt will throw, forcing the app to register a real implementation.
 */
final class NullFileStorage implements FileStorageInterface
{
    public function upload(UploadedFile $file, string $directory = ''): string
    {
        throw new \LogicException(
            'No FileStorageInterface configured. Register an implementation '
            . '(e.g. LocalFileStorage) in your services to enable file uploads.'
        );
    }

    public function remove(string $path): void
    {
        // No-op: nothing to remove
    }

    public function isStoredPath(string $value): bool
    {
        return false;
    }

    public function read(string $publicPath): ?string
    {
        return null;
    }

    public function uploadFromString(string $contents, string $extension, string $directory = ''): string
    {
        throw new \LogicException(
            'No FileStorageInterface configured. Register an implementation '
            . '(e.g. LocalFileStorage) in your services to enable file uploads.'
        );
    }
}
