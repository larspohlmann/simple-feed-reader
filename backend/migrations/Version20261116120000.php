<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** SQLite adds the column in place, so entry keeps its FTS5 triggers. */
final class Version20261116120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Count the self-replies continuing an entry, its folded thread posts to begin with (#2269)';
    }

    public function up(Schema $schema): void
    {
        $this->skipIf($schema->getTable('entry')->hasColumn('self_reply_count'), 'entry.self_reply_count exists.');

        $this->addSql('ALTER TABLE entry ADD COLUMN self_reply_count SMALLINT DEFAULT 0 NOT NULL');
        $this->addSql(
            'UPDATE entry SET self_reply_count = (SELECT COUNT(*) FROM thread_post WHERE thread_post.entry_id = entry.id)'
            . ' WHERE id IN (SELECT entry_id FROM thread_post)',
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE entry DROP COLUMN self_reply_count');
    }
}
