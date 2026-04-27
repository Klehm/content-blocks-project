<?php

declare(strict_types=1);

namespace ContentBlocks\Kit\Storage;

use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Simple local filesystem storage. Suitable for development and simple deployments.
 * For production, consider using Flysystem or S3-backed implementations.
 */
final class LocalFileStorage implements FileStorageInterface
{
    public function __construct(
        private readonly string $uploadDir,
        private readonly string $publicPrefix = '/uploads/content-blocks',
    ) {
    }

    public function upload(UploadedFile $file, string $directory = ''): string
    {
        $filename = bin2hex(random_bytes(16)) . '.' . ($file->guessExtension() ?? 'bin');
        $targetDir = rtrim($this->uploadDir, '/');

        if ($directory !== '') {
            $targetDir .= '/' . trim($directory, '/');
        }

        if (!is_dir($targetDir)) {
            mkdir($targetDir, 0o755, true);
        }

        $file->move($targetDir, $filename);

        $publicPath = rtrim($this->publicPrefix, '/');
        if ($directory !== '') {
            $publicPath .= '/' . trim($directory, '/');
        }

        return $publicPath . '/' . $filename;
    }

    public function remove(string $path): void
    {
        // Convert public URL back to filesystem path
        $relativePath = str_replace($this->publicPrefix, '', $path);
        $filePath = rtrim($this->uploadDir, '/') . '/' . ltrim($relativePath, '/');

        if (is_file($filePath)) {
            unlink($filePath);
        }
    }
}
