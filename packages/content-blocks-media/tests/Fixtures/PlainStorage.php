<?php

declare(strict_types=1);

namespace ContentBlocks\Media\Tests\Fixtures;

use ContentBlocks\Storage\FileStorageInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/** A storage that cannot list its files, like an S3 adapter may not. */
final class PlainStorage implements FileStorageInterface
{
    public function upload(UploadedFile $file, string $directory = ''): string
    {
        return '/u/x';
    }

    public function remove(string $path): void
    {
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
        return '/u/x';
    }
}
