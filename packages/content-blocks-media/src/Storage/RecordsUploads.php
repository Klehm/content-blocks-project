<?php

declare(strict_types=1);

namespace ContentBlocks\Media\Storage;

use ContentBlocks\Storage\FileStorageInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * The FileStorageInterface half both recording decorators share.
 *
 * @internal
 */
trait RecordsUploads
{
    public function __construct(
        private readonly FileStorageInterface $inner,
        private readonly UploadRecorder $recorder,
    ) {
    }

    public function upload(UploadedFile $file, string $directory = ''): string
    {
        $record = $this->recorder->forUpload($file);
        $path = $this->inner->upload($file, $directory);
        $record($path);

        return $path;
    }

    public function remove(string $path): void
    {
        $this->inner->remove($path);
        $this->recorder->forget($path);
    }

    public function isStoredPath(string $value): bool
    {
        return $this->inner->isStoredPath($value);
    }

    public function read(string $publicPath): ?string
    {
        return $this->inner->read($publicPath);
    }

    public function uploadFromString(string $contents, string $extension, string $directory = ''): string
    {
        $path = $this->inner->uploadFromString($contents, $extension, $directory);
        $this->recorder->forContents($path, $contents);

        return $path;
    }
}
