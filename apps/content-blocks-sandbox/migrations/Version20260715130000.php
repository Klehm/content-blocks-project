<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * v1.0 styling viewport-key rename: d/t/m -> desktop/tablet/mobile.
 *
 * The responsive styling sub-tree stored padding/margin/gap under terse
 * viewport keys (`d`, `t`, `m`). The stable release spells them out
 * (`desktop`, `tablet`, `mobile`) — see FREEZE-AUDIT.md §B2. The emitted CSS
 * var names stay terse (the decorators map long->short), so only stored JSON
 * changes here.
 *
 * Rewrites `styling.{padding,margin,gap}` viewport keys in:
 *   - cb_section.draft_settings / published_settings
 *   - cb_block.draft_data / published_data   (blocks: padding/margin only)
 *
 * Host preset YAML (content_blocks.section_styles[].settings) using d/t/m must
 * be updated by hand — config is not migratable.
 */
final class Version20260715130000 extends AbstractMigration
{
    private const TABLES = [
        'cb_section' => ['draft_settings', 'published_settings'],
        'cb_block' => ['draft_data', 'published_data'],
    ];

    public function getDescription(): string
    {
        return 'Rename styling viewport keys d/t/m -> desktop/tablet/mobile in section settings and block data.';
    }

    public function up(Schema $schema): void
    {
        $this->rewrite(false);
    }

    public function down(Schema $schema): void
    {
        $this->rewrite(true);
    }

    private function rewrite(bool $reverse): void
    {
        foreach (self::TABLES as $table => $columns) {
            $cols = implode(', ', $columns);
            $rows = $this->connection->fetchAllAssociative("SELECT id, $cols FROM $table");

            foreach ($rows as $row) {
                $set = [];
                $params = ['id' => $row['id']];
                foreach ($columns as $col) {
                    if ($row[$col] === null) {
                        continue;
                    }
                    $decoded = json_decode((string) $row[$col], true);
                    if (!\is_array($decoded)) {
                        continue;
                    }
                    $set[] = "$col = :$col";
                    $params[$col] = json_encode($this->renameViewports($decoded, $reverse));
                }

                if ($set !== []) {
                    $this->connection->executeStatement(
                        "UPDATE $table SET " . implode(', ', $set) . ' WHERE id = :id',
                        $params
                    );
                }
            }
        }
    }

    /**
     * @param array<string, mixed> $root a settings / data array
     *
     * @return array<string, mixed>
     */
    private function renameViewports(array $root, bool $reverse): array
    {
        if (!isset($root['styling']) || !\is_array($root['styling'])) {
            return $root;
        }

        $map = $reverse
            ? ['desktop' => 'd', 'tablet' => 't', 'mobile' => 'm']
            : ['d' => 'desktop', 't' => 'tablet', 'm' => 'mobile'];

        foreach (['padding', 'margin', 'gap'] as $key) {
            $box = $root['styling'][$key] ?? null;
            if (!\is_array($box)) {
                continue;
            }
            $remapped = [];
            foreach ($box as $viewport => $value) {
                $remapped[$map[$viewport] ?? $viewport] = $value;
            }
            $root['styling'][$key] = $remapped;
        }

        return $root;
    }
}
