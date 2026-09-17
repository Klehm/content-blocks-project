<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Columns get the draft twins every other mutable field already had: a
 * published preset, and draft/published settings (the tab title).
 *
 * Copy this into your own project when you upgrade. The backfill pins what
 * each published column shows today, so adding or removing a column in the
 * builder can never move the live page before Publish.
 *
 * @see docs/internals/rendering.md#columns-and-tabs
 */
final class Version20260917120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add published_preset, published_settings and draft_settings to cb_column.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE cb_column
            ADD published_preset VARCHAR(30) DEFAULT NULL,
            ADD published_settings JSON DEFAULT NULL,
            ADD draft_settings JSON DEFAULT NULL');
        $this->addSql('UPDATE cb_column SET published_preset = preset WHERE published_at IS NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE cb_column
            DROP published_preset,
            DROP published_settings,
            DROP draft_settings');
    }
}
