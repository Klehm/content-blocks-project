<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Creates cb_section_template: the global library of reusable section
 * snapshots (layout + settings + columns + blocks + data), inserted from the
 * builder. `payload` holds the JSON snapshot; `block_types` caches the block
 * types it uses so incompatible templates can be flagged without decoding it.
 */
final class Version20260703120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create cb_section_template for the reusable section-template library.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE cb_section_template (
                id INT AUTO_INCREMENT NOT NULL,
                name VARCHAR(255) NOT NULL,
                payload JSON NOT NULL,
                block_types JSON NOT NULL,
                created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE cb_section_template');
    }
}
