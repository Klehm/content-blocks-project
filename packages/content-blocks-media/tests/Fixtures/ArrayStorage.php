<?php

declare(strict_types=1);

namespace ContentBlocks\Media\Tests\Fixtures;

use ContentBlocks\Storage\AssetInventoryInterface;
use ContentBlocks\Storage\FileStorageInterface;
use ContentBlocks\Storage\StoredAsset;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/** A storage that lists the files it was given, and counts its reads. */
final class ArrayStorage implements FileStorageInterface, AssetInventoryInterface
{
    public int $reads = 0;

    /** @param array<string, string> $files public path => bytes */
    public function __construct(public array $files = [])
    {
    }

    public function upload(UploadedFile $file, string $directory = ''): string
    {
        throw new \LogicException('Not used.');
    }

    public function remove(string $path): void
    {
        unset($this->files[$path]);
    }

    public function isStoredPath(string $value): bool
    {
        return isset($this->files[$value]);
    }

    public function read(string $publicPath): ?string
    {
        ++$this->reads;

        return $this->files[$publicPath] ?? null;
    }

    public function uploadFromString(string $contents, string $extension, string $directory = ''): string
    {
        throw new \LogicException('Not used.');
    }

    public function listAssets(): iterable
    {
        foreach ($this->files as $path => $bytes) {
            yield new StoredAsset($path, \strlen($bytes), new \DateTimeImmutable('2025-03-04 05:06:07'));
        }
    }
}
