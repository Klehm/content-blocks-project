<?php

declare(strict_types=1);

namespace ContentBlocks\Media\Storage;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;

/**
 * `cb_media` through DBAL, so recording an upload never flushes the caller's
 * unit of work (an import is mid-transaction when it stores files).
 *
 * @internal
 *
 * @see docs/internals/media.md#rows-are-written-beside-the-orm
 */
final class MediaRows
{
    public const TABLE = 'cb_media';

    public function __construct(private readonly Connection $connection)
    {
    }

    public function has(string $path): bool
    {
        return $this->connection->fetchOne(
            'SELECT 1 FROM ' . self::TABLE . ' WHERE path = ?',
            [$path],
        ) !== false;
    }

    /** False when the path already has a row: a file is in the library once. */
    public function add(MediaRecord $record): bool
    {
        if ($this->has($record->path)) {
            return false;
        }

        $now = new \DateTimeImmutable();
        $this->connection->insert(self::TABLE, [
            'path' => $record->path,
            'kind' => $record->kind,
            'title' => $record->title,
            'alt' => '',
            'mime_type' => $record->mimeType,
            'size' => $record->size,
            'width' => $record->width,
            'height' => $record->height,
            'created_at' => $record->createdAt ?? $now,
            'updated_at' => $now,
        ], [
            'created_at' => Types::DATETIME_IMMUTABLE,
            'updated_at' => Types::DATETIME_IMMUTABLE,
        ]);

        return true;
    }

    public function remove(string $path): void
    {
        $this->connection->delete(self::TABLE, ['path' => $path]);
    }

    /** @return iterable<string> */
    public function paths(): iterable
    {
        foreach ($this->connection->iterateColumn('SELECT path FROM ' . self::TABLE) as $path) {
            yield (string) $path;
        }
    }
}
