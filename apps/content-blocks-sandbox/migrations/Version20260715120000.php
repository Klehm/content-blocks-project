<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * v1.0 Block.data key unification for the kit blocks.
 *
 * The kit shipped the same concept under different keys across blocks; the
 * stable release reconciles them (see the 1.0.0 entry in the kit CHANGELOG for
 * the full rename table). Because Block.data
 * is stored JSON, existing rows must be rewritten. This runs the rename in PHP
 * (decode → transform → encode) rather than SQL JSON functions, so nested
 * collection items (gallery/card items, table columns) are handled the same way
 * as top-level keys.
 *
 * Renames:
 *   image   data.link           -> data.url
 *   button  data.href           -> data.url
 *   alert   data.message        -> data.content
 *   tabs    data.tabs           -> data.items
 *   gallery items[].link        -> items[].url
 *   card    items[].buttonUrl   -> items[].url
 *           items[].buttonLabel -> items[].buttonText
 *   table   columns[].align     value 'left'->'start', 'right'->'end'
 *
 * Both JSON columns are rewritten: a block carries its published payload in
 * `published_data` and any in-flight edit in `draft_data`, so migrating only one
 * would leave the other on the old keys — visible the moment an editor discards
 * or publishes.
 *
 * Hosts on the beta line should copy this migration (adjust the namespace) to
 * upgrade their own cb_block rows.
 */
final class Version20260715120000 extends AbstractMigration
{
    /** Block payloads live in two JSON columns; both carry the same shape. */
    private const COLUMNS = ['draft_data', 'published_data'];

    public function getDescription(): string
    {
        return 'Unify kit Block.data keys for the stable release (link/href->url, message->content, tabs->items, card CTA, table align vocab).';
    }

    public function up(Schema $schema): void
    {
        $this->rewrite(false);
    }

    public function down(Schema $schema): void
    {
        $this->rewrite(true);
    }

    /**
     * @param bool $reverse when true, apply the inverse mapping (down()).
     */
    private function rewrite(bool $reverse): void
    {
        $cols = implode(', ', self::COLUMNS);
        $rows = $this->connection->fetchAllAssociative(
            "SELECT id, type, $cols FROM cb_block WHERE type IN ('image', 'button', 'alert', 'tabs', 'gallery', 'card', 'table')"
        );

        foreach ($rows as $row) {
            $set = [];
            $params = ['id' => $row['id']];

            foreach (self::COLUMNS as $col) {
                if ($row[$col] === null) {
                    continue;
                }
                $data = json_decode((string) $row[$col], true);
                if (!\is_array($data)) {
                    continue;
                }

                $set[] = "$col = :$col";
                $params[$col] = json_encode($this->transform($data, (string) $row['type'], $reverse));
            }

            if ($set === []) {
                continue;
            }

            $this->connection->executeStatement(
                'UPDATE cb_block SET ' . implode(', ', $set) . ' WHERE id = :id',
                $params
            );
        }
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private function transform(array $data, string $type, bool $reverse): array
    {
        return match ($type) {
            'image' => $this->renameKey($data, $reverse ? 'url' : 'link', $reverse ? 'link' : 'url'),
            'button' => $this->renameKey($data, $reverse ? 'url' : 'href', $reverse ? 'href' : 'url'),
            'alert' => $this->renameKey($data, $reverse ? 'content' : 'message', $reverse ? 'message' : 'content'),
            'tabs' => $this->renameKey($data, $reverse ? 'items' : 'tabs', $reverse ? 'tabs' : 'items'),
            'gallery' => $this->mapItems($data, 'items', fn (array $i) => $this->renameKey($i, $reverse ? 'url' : 'link', $reverse ? 'link' : 'url')),
            'card' => $this->mapItems($data, 'items', function (array $i) use ($reverse) {
                $i = $this->renameKey($i, $reverse ? 'url' : 'buttonUrl', $reverse ? 'buttonUrl' : 'url');

                return $this->renameKey($i, $reverse ? 'buttonText' : 'buttonLabel', $reverse ? 'buttonLabel' : 'buttonText');
            }),
            'table' => $this->mapItems($data, 'columns', fn (array $c) => $this->remapAlign($c, $reverse)),
            default => $data,
        };
    }

    /**
     * Move $from to $to if present, preserving nothing else. No-op when $from is
     * absent so re-running (or a partially-migrated row) is safe.
     *
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private function renameKey(array $data, string $from, string $to): array
    {
        if (\array_key_exists($from, $data) && !\array_key_exists($to, $data)) {
            $data[$to] = $data[$from];
            unset($data[$from]);
        }

        return $data;
    }

    /**
     * Apply $fn to every item of the collection under $key.
     *
     * @param array<string, mixed>          $data
     * @param callable(array<string,mixed>): array<string,mixed> $fn
     *
     * @return array<string, mixed>
     */
    private function mapItems(array $data, string $key, callable $fn): array
    {
        if (!isset($data[$key]) || !\is_array($data[$key])) {
            return $data;
        }

        $data[$key] = array_map(
            static fn ($item) => \is_array($item) ? $fn($item) : $item,
            $data[$key]
        );

        return $data;
    }

    /**
     * Table column alignment vocabulary: left/right <-> start/end (center is stable).
     *
     * @param array<string, mixed> $col
     *
     * @return array<string, mixed>
     */
    private function remapAlign(array $col, bool $reverse): array
    {
        if (!isset($col['align']) || !\is_string($col['align'])) {
            return $col;
        }

        $forward = ['left' => 'start', 'right' => 'end'];
        $map = $reverse ? array_flip($forward) : $forward;
        $col['align'] = $map[$col['align']] ?? $col['align'];

        return $col;
    }
}
