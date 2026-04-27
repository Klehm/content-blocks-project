<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260427142147 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add draft/published data + preview_position + deleted soft-delete to Section/Column/Block.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE cb_block ADD published_data JSON DEFAULT NULL, ADD draft_data JSON DEFAULT NULL, ADD preview_position SMALLINT NOT NULL, ADD deleted TINYINT(1) DEFAULT 0 NOT NULL, DROP data');
        $this->addSql('ALTER TABLE cb_column ADD preview_position SMALLINT NOT NULL, ADD deleted TINYINT(1) DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE cb_section ADD preview_position SMALLINT NOT NULL, ADD deleted TINYINT(1) DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE cb_block ADD data JSON NOT NULL, DROP published_data, DROP draft_data, DROP preview_position, DROP deleted');
        $this->addSql('ALTER TABLE cb_column DROP preview_position, DROP deleted');
        $this->addSql('ALTER TABLE cb_section DROP preview_position, DROP deleted');
    }
}
