<?php

declare(strict_types=1);

namespace ContentBlocks\Media\Storage;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Turns a stored file into a library row. A failure is logged, never thrown:
 * the file is already stored, and losing the upload over its row is worse.
 *
 * @internal
 *
 * @see docs/internals/media.md#an-upload-never-fails-over-its-row
 */
final class UploadRecorder
{
    public function __construct(
        private readonly MediaRows $rows,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * Read before the inner storage moves the file away.
     *
     * @return \Closure(string): void records the path the storage returned
     */
    public function forUpload(UploadedFile $file): \Closure
    {
        $name = $file->getClientOriginalName();
        $mimeType = $file->getMimeType();
        $size = $file->getSize();
        $imageSize = @getimagesize($file->getPathname());

        return function (string $path) use ($name, $mimeType, $size, $imageSize): void {
            $this->record(MediaRecord::describe($path, $name, $mimeType, $size === false ? null : $size, $imageSize));
        };
    }

    public function forContents(string $path, string $contents): void
    {
        $mimeType = class_exists(\finfo::class)
            ? ((new \finfo(\FILEINFO_MIME_TYPE))->buffer($contents) ?: null)
            : null;

        $this->record(MediaRecord::describe(
            $path,
            '',
            $mimeType,
            \strlen($contents),
            @getimagesizefromstring($contents),
        ));
    }

    public function record(MediaRecord $record): bool
    {
        try {
            return $this->rows->add($record);
        } catch (\Doctrine\DBAL\Exception $e) {
            $this->logger->error('content-blocks-media: "{path}" was stored but not added to the library: {message}', [
                'path' => $record->path,
                'message' => $e->getMessage(),
                'exception' => $e,
            ]);

            return false;
        }
    }

    public function forget(string $path): void
    {
        try {
            $this->rows->remove($path);
        } catch (\Doctrine\DBAL\Exception $e) {
            $this->logger->error('content-blocks-media: "{path}" was removed but its library row was not: {message}', [
                'path' => $path,
                'message' => $e->getMessage(),
                'exception' => $e,
            ]);
        }
    }
}
