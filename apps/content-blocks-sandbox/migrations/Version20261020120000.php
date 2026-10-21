<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The media library of `klehm/content-blocks-media`: one row per stored file,
 * and the folders they are sorted into.
 *
 * Copy this into your own project when you install the package, then run
 * `content-blocks-media:import` to take in the files stored before it.
 *
 * @see docs/guide/media-library.md#installing-the-library
 */
final class Version20261020120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add cb_media and cb_media_folder for klehm/content-blocks-media.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE cb_media_folder (
                id INT AUTO_INCREMENT NOT NULL,
                parent_id INT DEFAULT NULL,
                name VARCHAR(255) NOT NULL,
                position INT NOT NULL,
                created_at DATETIME NOT NULL,
                INDEX IDX_ECD7D0C1727ACA70 (parent_id),
                INDEX cb_media_folder_position (parent_id, position),
                PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE cb_media (
                id INT AUTO_INCREMENT NOT NULL,
                folder_id INT DEFAULT NULL,
                path VARCHAR(512) NOT NULL,
                kind VARCHAR(16) NOT NULL,
                title VARCHAR(255) NOT NULL,
                alt VARCHAR(255) NOT NULL,
                mime_type VARCHAR(127) DEFAULT NULL,
                size INT DEFAULT NULL,
                width INT DEFAULT NULL,
                height INT DEFAULT NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                INDEX IDX_76B9C9CE162CB942 (folder_id),
                INDEX cb_media_kind (kind),
                INDEX cb_media_created_at (created_at),
                UNIQUE INDEX cb_media_path (path),
                PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
            SQL);

        $this->addSql(<<<'SQL'
            ALTER TABLE cb_media_folder
                ADD CONSTRAINT FK_ECD7D0C1727ACA70
                FOREIGN KEY (parent_id) REFERENCES cb_media_folder (id)
                ON DELETE CASCADE
            SQL);

        $this->addSql(<<<'SQL'
            ALTER TABLE cb_media
                ADD CONSTRAINT FK_76B9C9CE162CB942
                FOREIGN KEY (folder_id) REFERENCES cb_media_folder (id)
                ON DELETE SET NULL
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE cb_media');
        $this->addSql('DROP TABLE cb_media_folder');
    }
}
