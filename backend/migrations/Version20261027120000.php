<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261027120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add entry_transcript: an episode\'s declared transcript, queued at ingest and filled later (#1809).';
    }

    public function up(Schema $schema): void
    {
        $this->skipIf($schema->hasTable('entry_transcript'), 'entry_transcript already exists.');

        if ($this->mysql()) {
            $this->upMySql();

            return;
        }

        $this->upSqlite();
    }

    public function down(Schema $schema): void
    {
        $this->skipIf(!$schema->hasTable('entry_transcript'), 'entry_transcript does not exist.');

        if ($this->mysql()) {
            $this->downMySql();

            return;
        }

        $this->downSqlite();
    }

    public function isTransactional(): bool
    {
        return false;
    }

    private function upMySql(): void
    {
        $this->addSql('CREATE TABLE entry_transcript (id INT AUTO_INCREMENT NOT NULL, url LONGTEXT NOT NULL,'
            . ' format VARCHAR(8) NOT NULL, language VARCHAR(35) DEFAULT NULL, status VARCHAR(8) NOT NULL,'
            . ' attempts INT DEFAULT 0 NOT NULL, next_attempt_at DATETIME DEFAULT NULL, paragraphs JSON DEFAULT NULL,'
            . ' search_text LONGTEXT DEFAULT NULL, entry_id INT NOT NULL,'
            . ' UNIQUE INDEX UNIQ_305C5EF1BA364942 (entry_id),'
            . ' INDEX idx_entry_transcript_claimable (status, next_attempt_at), PRIMARY KEY (id))'
            . ' DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE entry_transcript ADD CONSTRAINT FK_305C5EF1BA364942 FOREIGN KEY (entry_id)'
            . ' REFERENCES entry (id) ON DELETE CASCADE');
    }

    private function upSqlite(): void
    {
        $this->addSql('CREATE TABLE entry_transcript (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,'
            . ' url CLOB NOT NULL, format VARCHAR(8) NOT NULL, language VARCHAR(35) DEFAULT NULL,'
            . ' status VARCHAR(8) NOT NULL, attempts INTEGER DEFAULT 0 NOT NULL, next_attempt_at DATETIME DEFAULT NULL,'
            . ' paragraphs CLOB DEFAULT NULL, search_text CLOB DEFAULT NULL, entry_id INTEGER NOT NULL,'
            . ' CONSTRAINT FK_305C5EF1BA364942 FOREIGN KEY (entry_id) REFERENCES entry (id) ON DELETE CASCADE'
            . ' NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_305C5EF1BA364942 ON entry_transcript (entry_id)');
        $this->addSql('CREATE INDEX idx_entry_transcript_claimable ON entry_transcript (status, next_attempt_at)');
    }

    private function downMySql(): void
    {
        $this->addSql('ALTER TABLE entry_transcript DROP FOREIGN KEY FK_305C5EF1BA364942');
        $this->addSql('DROP TABLE entry_transcript');
    }

    private function downSqlite(): void
    {
        $this->addSql('DROP TABLE entry_transcript');
    }

    /** Refuses any platform but the two supported ones: better a refusal than DDL nobody tested. */
    private function mysql(): bool
    {
        $platform = $this->connection->getDatabasePlatform();

        $this->abortIf(
            !$platform instanceof AbstractMySQLPlatform && !$platform instanceof SQLitePlatform,
            \sprintf('No DDL defined for platform %s; only MySQL and SQLite are supported.', $platform::class),
        );

        return $platform instanceof AbstractMySQLPlatform;
    }
}
