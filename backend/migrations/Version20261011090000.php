<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261011090000 extends AbstractMigration
{
    private const string COPY_QUEUED_POSTS = 'INSERT INTO post_enrichment (id, entry_id, kind, queued_at)'
        . " SELECT id, entry_id, 'appview', queued_at FROM pending_post_enrichment";

    public function getDescription(): string
    {
        return 'Replace pending_post_enrichment with post_enrichment, one queue for AppView embeds and link cards'
            . ' (#1502); the queued rows stay, as appview.';
    }

    public function up(Schema $schema): void
    {
        $this->skipIf($schema->hasTable('post_enrichment'), 'post_enrichment already exists.');

        $this->abortIfUnsupportedPlatform();
        if ($this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform) {
            $this->addSql('CREATE TABLE post_enrichment (id INT AUTO_INCREMENT NOT NULL, kind VARCHAR(16) NOT NULL,'
                . ' queued_at DATETIME NOT NULL, claimed_at DATETIME DEFAULT NULL, done_at DATETIME DEFAULT NULL,'
                . ' entry_id INT NOT NULL, UNIQUE INDEX UNIQ_9FA2FF0FBA364942 (entry_id), PRIMARY KEY (id))'
                . ' DEFAULT CHARACTER SET utf8mb4');
            $this->addSql('ALTER TABLE post_enrichment ADD CONSTRAINT FK_9FA2FF0FBA364942'
                . ' FOREIGN KEY (entry_id) REFERENCES entry (id) ON DELETE CASCADE');
            $this->addSql(self::COPY_QUEUED_POSTS);
            $this->addSql('DROP TABLE pending_post_enrichment');

            return;
        }

        $this->addSql('CREATE TABLE post_enrichment (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,'
            . ' kind VARCHAR(16) NOT NULL, queued_at DATETIME NOT NULL, claimed_at DATETIME DEFAULT NULL,'
            . ' done_at DATETIME DEFAULT NULL, entry_id INTEGER NOT NULL, CONSTRAINT FK_9FA2FF0FBA364942'
            . ' FOREIGN KEY (entry_id) REFERENCES entry (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_9FA2FF0FBA364942 ON post_enrichment (entry_id)');
        $this->addSql(self::COPY_QUEUED_POSTS);
        $this->addSql('DROP TABLE pending_post_enrichment');
    }

    public function down(Schema $schema): void
    {
        $this->skipIf(!$schema->hasTable('post_enrichment'), 'post_enrichment does not exist.');

        $this->abortIfUnsupportedPlatform();
        if ($this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform) {
            $this->addSql('CREATE TABLE pending_post_enrichment (id INT AUTO_INCREMENT NOT NULL,'
                . ' queued_at DATETIME NOT NULL, entry_id INT NOT NULL, UNIQUE INDEX UNIQ_BA362B72BA364942 (entry_id),'
                . ' PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
            $this->addSql('ALTER TABLE pending_post_enrichment ADD CONSTRAINT FK_BA362B72BA364942'
                . ' FOREIGN KEY (entry_id) REFERENCES entry (id) ON DELETE CASCADE');
        } else {
            $this->addSql('CREATE TABLE pending_post_enrichment (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,'
                . ' queued_at DATETIME NOT NULL, entry_id INTEGER NOT NULL, CONSTRAINT FK_BA362B72BA364942'
                . ' FOREIGN KEY (entry_id) REFERENCES entry (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY'
                . ' IMMEDIATE)');
            $this->addSql('CREATE UNIQUE INDEX UNIQ_BA362B72BA364942 ON pending_post_enrichment (entry_id)');
        }
        $this->addSql("INSERT INTO pending_post_enrichment (id, entry_id, queued_at) SELECT id, entry_id, queued_at"
            . " FROM post_enrichment WHERE kind = 'appview'");
        $this->addSql('DROP TABLE post_enrichment');
    }

    public function isTransactional(): bool
    {
        return false;
    }

    private function abortIfUnsupportedPlatform(): void
    {
        $platform = $this->connection->getDatabasePlatform();

        $this->abortIf(
            !$platform instanceof AbstractMySQLPlatform && !$platform instanceof SQLitePlatform,
            \sprintf('No DDL defined for platform %s; only MySQL and SQLite are supported.', $platform::class),
        );
    }
}
