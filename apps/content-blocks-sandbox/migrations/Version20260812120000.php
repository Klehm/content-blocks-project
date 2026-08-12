<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Content translation: the side table `klehm/content-blocks-i18n` stores
 * per-locale field values in.
 *
 * Copy this into your own project when you install the package — the bundle
 * maps the entity for you, but the table is yours to create.
 *
 * Two things worth keeping if you adapt it:
 *
 *  - **`ON DELETE CASCADE`** on `block_id`. A deleted block has no translations
 *    by definition, and the database is the only place that can guarantee it
 *    without every delete path in the application opting in.
 *  - **the unique index on `(block_id, locale)`**, which is what makes
 *    "one row per block per language" an invariant rather than a convention.
 */
final class Version20260812120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add cb_block_translation for klehm/content-blocks-i18n.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE cb_block_translation (
                id INT AUTO_INCREMENT NOT NULL,
                block_id INT NOT NULL,
                locale VARCHAR(16) NOT NULL,
                draft_values JSON DEFAULT NULL,
                draft_digests JSON DEFAULT NULL,
                published_values JSON DEFAULT NULL,
                published_digests JSON DEFAULT NULL,
                updated_at DATETIME NOT NULL,
                INDEX IDX_559AD38EE9ED820C (block_id),
                INDEX cb_block_translation_locale (locale),
                UNIQUE INDEX cb_block_translation_unique (block_id, locale),
                PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
            SQL);

        $this->addSql(<<<'SQL'
            ALTER TABLE cb_block_translation
                ADD CONSTRAINT FK_559AD38EE9ED820C
                FOREIGN KEY (block_id) REFERENCES cb_block (id)
                ON DELETE CASCADE
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE cb_block_translation');
    }
}
