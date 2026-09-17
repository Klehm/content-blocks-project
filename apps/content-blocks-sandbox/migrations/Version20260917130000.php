<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Tab titles in other languages: the side table `klehm/content-blocks-i18n`
 * keeps a column's translated settings in, beside `cb_block_translation`.
 *
 * Copy this into your own project when you upgrade the i18n package. Same two
 * invariants as the block table: `ON DELETE CASCADE` on `column_id`, and one
 * row per column per language through the unique index.
 *
 * @see docs/internals/i18n.md#tab-titles-are-translated-beside-the-column
 */
final class Version20260917130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add cb_column_translation for klehm/content-blocks-i18n.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE cb_column_translation (
                id INT AUTO_INCREMENT NOT NULL,
                column_id INT NOT NULL,
                locale VARCHAR(16) NOT NULL,
                draft_values JSON DEFAULT NULL,
                draft_digests JSON DEFAULT NULL,
                published_values JSON DEFAULT NULL,
                published_digests JSON DEFAULT NULL,
                updated_at DATETIME NOT NULL,
                INDEX IDX_EC8DBBDFBE8E8ED5 (column_id),
                INDEX cb_column_translation_locale (locale),
                UNIQUE INDEX cb_column_translation_unique (column_id, locale),
                PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
            SQL);

        $this->addSql(<<<'SQL'
            ALTER TABLE cb_column_translation
                ADD CONSTRAINT FK_EC8DBBDFBE8E8ED5
                FOREIGN KEY (column_id) REFERENCES cb_column (id)
                ON DELETE CASCADE
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE cb_column_translation');
    }
}
