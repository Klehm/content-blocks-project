<?php

declare(strict_types=1);

namespace ContentBlocks\Media\Library;

use ContentBlocks\Asset\AssetReferenceCollector;
use ContentBlocks\Asset\JsonPayload;
use Doctrine\DBAL\Connection;

/**
 * The areas whose blocks reference a file, in their draft or published data.
 * Prefiltered on the file name, confirmed by the GC's own collector.
 *
 * @see docs/internals/media.md#where-a-file-is-used
 */
final class MediaUsage
{
    public function __construct(
        private readonly Connection $connection,
        private readonly AssetReferenceCollector $collector,
    ) {
    }

    /**
     * @return array<int, array{draft: bool, published: bool}> area id => where
     */
    public function areasUsing(string $path): array
    {
        $name = basename((string) (parse_url($path, \PHP_URL_PATH) ?: $path));
        if ($name === '') {
            return [];
        }

        $like = '%' . addcslashes($name, '%_\\') . '%';
        $rows = $this->connection->fetchAllAssociative(
            'SELECT b.draft_data, b.published_data, b.deleted, s.content_area_id'
            . ' FROM cb_block b'
            . ' JOIN cb_column c ON c.id = b.column_id'
            . ' JOIN cb_section s ON s.id = c.section_id'
            . ' WHERE ' . $this->text('b.draft_data') . ' LIKE :name'
            . ' OR ' . $this->text('b.published_data') . ' LIKE :name',
            ['name' => $like],
        );

        $areas = [];
        foreach ($rows as $row) {
            $area = (int) $row['content_area_id'];
            $draft = !$row['deleted'] && $this->references($row['draft_data'], $path);
            $published = $this->references($row['published_data'], $path);
            if (!$draft && !$published) {
                continue;
            }
            $areas[$area] = [
                'draft' => ($areas[$area]['draft'] ?? false) || $draft,
                'published' => ($areas[$area]['published'] ?? false) || $published,
            ];
        }
        ksort($areas);

        return $areas;
    }

    private function references(mixed $json, string $path): bool
    {
        $data = JsonPayload::decode($json);

        return $data !== [] && \in_array($path, $this->collector->collect($data), true);
    }

    /** PostgreSQL has no LIKE on `json`; MySQL compares its text as is. */
    private function text(string $column): string
    {
        $platform = $this->connection->getDatabasePlatform();

        return $platform instanceof \Doctrine\DBAL\Platforms\PostgreSQLPlatform
            ? 'CAST(' . $column . ' AS TEXT)'
            : $column;
    }
}
