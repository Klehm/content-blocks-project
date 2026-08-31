<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The published render becomes immutable until Publish.
 *
 * Copy this into your own project when you upgrade. It does two things, and
 * the second one is the one that matters on a live site.
 *
 * **1. `cb_block.published_column_id`.** Dragging a block into another column
 * writes `column_id` immediately — that FK is what the builder, the preview and
 * Doctrine's cascades navigate, so it is the *draft* location. This nullable
 * column is the note the block leaves behind saying which column the public
 * page should keep showing it in until Publish. NULL everywhere means "not
 * moved", which is the state every existing row is already in.
 *
 * **2. Backfilling `published_at`.** The public render now shows published
 * entities only — a Section/Column without a `published_at` is a draft addition
 * and stays off the live page until Publish stamps it. `published_at` was
 * introduced without a backfill, so rows predating it carry NULL while being
 * very much live. Left alone, they would vanish from the public site the moment
 * this version deploys.
 *
 * The backfill stamps exactly the rows the *old* renderer put on the public
 * page: every non-deleted section and column. So the live page after the
 * upgrade is the live page before it, unchanged.
 *
 * ::: warning Drafts open at upgrade time
 * A section added in the builder and not yet published is, under the old
 * behaviour, already on the live page — so the backfill stamps it as published
 * too. That is what "freeze the current public page" means. Ask editors to
 * Publish or Discard their open drafts before you deploy if you would rather
 * those additions stay pending.
 * :::
 */
final class Version20260831120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Remember a block\'s published column, and stamp already-live sections as published.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE cb_block ADD published_column_id INT DEFAULT NULL');

        // Freeze what the public page is serving right now as the published
        // state. NOW() is a stand-in: nothing reads the value, only whether it
        // is NULL.
        $this->addSql('UPDATE cb_section SET published_at = NOW() WHERE published_at IS NULL AND deleted = 0');
        $this->addSql('UPDATE cb_column SET published_at = NOW() WHERE published_at IS NULL AND deleted = 0');
    }

    public function down(Schema $schema): void
    {
        // The backfill is not undone: which rows carried NULL before is not
        // recorded anywhere, and re-NULLing them all would unpublish the site.
        $this->addSql('ALTER TABLE cb_block DROP published_column_id');
    }
}
