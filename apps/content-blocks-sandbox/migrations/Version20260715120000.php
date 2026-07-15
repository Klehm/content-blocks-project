<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * v1.0 Block.data key unification for the kit blocks.
 *
 * The kit shipped the same concept under different keys across blocks; the
 * stable release reconciles them (see FREEZE-AUDIT.md §B1). Because Block.data
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
 * Hosts on the beta line should copy this migration (adjust the namespace) to
 * upgrade their own cb_block rows.
 */
final class Version20260715120000 extends AbstractMigration
{
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
        $rows = $this->connection->fetchAllAssociative(
            "SELECT id, type, data FROM cb_block WHERE type IN ('image', 'button', 'alert', 'tabs', 'gallery', 'card', 'table')"
        );

        foreach ($rows as $row) {
            $data = json_decode((string) $row['data'], true);
            if (!\is_array($data)) {
                continue;
            }

            $data = match ($row['type']) {
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

            $this->connection->executeStatement(
                'UPDATE cb_block SET data = :data WHERE id = :id',
                ['data' => json_encode($data), 'id' => $row['id']]
            );
        }
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
