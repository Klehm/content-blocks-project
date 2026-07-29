<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds the content-version columns.
 *
 * `content_blocks.content_version` is a host-owned integer: bump it whenever
 * anything that shapes your stored block data changes (your own blocks, a kit
 * upgrade, a core upgrade note saying so). It is stamped onto content as it is
 * written, so a later migration can target what predates the change with
 * `WHERE content_version < N`.
 *
 * Both columns are nullable and left NULL here on purpose. NULL means "predates
 * versioning" — it is *not* the same as 0, and a host migration must decide what
 * to do with those rows explicitly rather than assume they are on version 1.
 *
 * Note what the area column does and does not mean: it records the version the
 * content was last **written** under, not that every block in it conforms.
 * Editing one block re-stamps the whole area. Migrate before letting editors
 * work on a new version. (The section-template column has no such caveat — a
 * snapshot is frozen, so its stamp keeps describing its payload.)
 */
final class Version20260729120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add cb_content_area.content_version and cb_section_template.content_version.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE cb_content_area ADD content_version INT DEFAULT NULL');
        $this->addSql('ALTER TABLE cb_section_template ADD content_version INT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE cb_content_area DROP content_version');
        $this->addSql('ALTER TABLE cb_section_template DROP content_version');
    }
}
