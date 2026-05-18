<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds cb_content_area.updated_at, touched by ContentAreaTouchListener on
 * any insert/update/delete of a descendant Section / Column / Block. The
 * "replace with…" picker uses it to surface recently modified areas first.
 */
final class Version20260518120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add cb_content_area.updated_at for the replace-with picker (latest-first order).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE cb_content_area ADD updated_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE cb_content_area DROP updated_at');
    }
}
