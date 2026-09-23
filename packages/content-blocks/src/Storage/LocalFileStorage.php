<?php

declare(strict_types=1);

namespace ContentBlocks\Storage;

use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Local filesystem storage, and the reference {@see AssetInventoryInterface}:
 * a directory walk, so the GC works out of the box for hosts using it.
 */
final class LocalFileStorage implements FileStorageInterface, AssetInventoryInterface
{
    public function __construct(
        private readonly string $uploadDir,
        private readonly string $publicPrefix = '/uploads/content-blocks',
    ) {
    }

    public function upload(UploadedFile $file, string $directory = ''): string
    {
        $filename = bin2hex(random_bytes(16)) . '.' . ($file->guessExtension() ?? 'bin');
        $targetDir = $this->ensureTargetDir($directory);

        $file->move($targetDir, $filename);

        return $this->buildPublicPath($directory, $filename);
    }

    public function remove(string $path): void
    {
        $filePath = $this->resolveExistingFile($path);
        if ($filePath !== null) {
            unlink($filePath);
        }
    }

    public function isStoredPath(string $value): bool
    {
        return $this->relativePath($value) !== null;
    }

    public function read(string $publicPath): ?string
    {
        $filePath = $this->resolveExistingFile($publicPath);
        if ($filePath === null) {
            return null;
        }
        $contents = file_get_contents($filePath);

        return $contents === false ? null : $contents;
    }

    public function uploadFromString(string $contents, string $extension, string $directory = ''): string
    {
        $extension = ltrim($extension, '.');
        if ($extension === '') {
            $extension = 'bin';
        }
        $filename = bin2hex(random_bytes(16)) . '.' . $extension;
        $targetDir = $this->ensureTargetDir($directory);

        if (file_put_contents($targetDir . '/' . $filename, $contents) === false) {
            throw new \RuntimeException(sprintf('Failed to write asset to %s', $targetDir . '/' . $filename));
        }

        return $this->buildPublicPath($directory, $filename);
    }

    /**
     * One {@see StoredAsset} per file, spelled as
     * {@see self::buildPublicPath()} spells it — marking compares strings.
     *
     * @return iterable<StoredAsset>
     */
    public function listAssets(): iterable
    {
        $root = rtrim($this->uploadDir, '/');
        if (!is_dir($root)) {
            return;
        }

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
        );

        $prefix = rtrim($this->publicPrefix, '/');

        /** @var \SplFileInfo $file */
        foreach ($files as $file) {
            if (!$file->isFile()) {
                continue;
            }

            $absolute = $file->getPathname();
            // Not str_replace: a path component could repeat the root string.
            $relative = ltrim(substr($absolute, \strlen($root)), '/');

            yield new StoredAsset(
                $prefix . '/' . $relative,
                (int) $file->getSize(),
                (new \DateTimeImmutable())->setTimestamp((int) $file->getMTime()),
            );
        }
    }

    private function ensureTargetDir(string $directory): string
    {
        $targetDir = rtrim($this->uploadDir, '/');
        if ($directory !== '') {
            $targetDir .= '/' . trim($directory, '/');
        }
        if (!is_dir($targetDir) && !mkdir($targetDir, 0o755, true) && !is_dir($targetDir)) {
            throw new \RuntimeException(sprintf('Failed to create directory %s', $targetDir));
        }

        return $targetDir;
    }

    private function buildPublicPath(string $directory, string $filename): string
    {
        $publicPath = rtrim($this->publicPrefix, '/');
        if ($directory !== '') {
            $publicPath .= '/' . trim($directory, '/');
        }

        return $publicPath . '/' . $filename;
    }

    /**
     * A stored path is editor input: it must name a file inside the upload dir.
     *
     * @see docs/guide/security.md#stored-paths-are-confined
     */
    private function resolveExistingFile(string $publicPath): ?string
    {
        $relative = $this->relativePath($publicPath);
        $root = realpath($this->uploadDir);
        if ($relative === null || $root === false) {
            return null;
        }

        $real = realpath($root . '/' . $relative);
        if ($real === false || !str_starts_with($real, $root . '/') || !is_file($real)) {
            return null;
        }

        return $real;
    }

    private function relativePath(string $publicPath): ?string
    {
        $prefix = rtrim($this->publicPrefix, '/') . '/';
        if (!str_starts_with($publicPath, $prefix)) {
            return null;
        }
        $relative = substr($publicPath, \strlen($prefix));
        if ($relative === '' || strpbrk($relative, "\\\0") !== false) {
            return null;
        }
        foreach (explode('/', $relative) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return null;
            }
        }

        return $relative;
    }
}
