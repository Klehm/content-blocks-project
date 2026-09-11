<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The builder's action history — `Ctrl/Cmd-Z`.
 *
 * Copy this into your own project when you upgrade. It adds one table and
 * touches nothing that exists, so it is safe on a live site: without it the
 * undo endpoints simply have nowhere to write.
 *
 * The table holds one row per undoable action, scoped to a content area and a
 * hashed HTTP session. Rows are short-lived by construction — publishing or
 * discarding an area empties its history, since the draft the entries describe
 * is gone either way — and what a never-published session leaves behind is
 * swept by the journal's own retention window.
 *
 * @see docs/internals/history.md
 */
final class Version20260910120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add cb_action_log, the builder\'s per-session undo stack.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE cb_action_log (
            id INT AUTO_INCREMENT NOT NULL,
            content_area_id INT NOT NULL,
            session_id VARCHAR(64) NOT NULL,
            seq INT NOT NULL,
            label VARCHAR(40) NOT NULL,
            coalesce_key VARCHAR(100) DEFAULT NULL,
            undo_ops JSON NOT NULL,
            redo_ops JSON NOT NULL,
            undone TINYINT DEFAULT 0 NOT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            INDEX IDX_C22DB5946207992F (content_area_id),
            INDEX cb_action_log_stack (content_area_id, session_id, seq),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4');

        // The area's rows go with it: an orphaned stack could never be
        // replayed, and nothing else points at them.
        $this->addSql('ALTER TABLE cb_action_log
            ADD CONSTRAINT FK_C22DB5946207992F FOREIGN KEY (content_area_id)
            REFERENCES cb_content_area (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE cb_action_log');
    }
}
