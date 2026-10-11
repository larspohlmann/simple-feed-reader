<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261011150000 extends AbstractMigration
{
    private const string COPY_TO_SHARE = 'INSERT INTO share_enrichment'
        . ' (id, entry_id, kind, queued_at, claimed_at, done_at)'
        . ' SELECT id, entry_id, kind, queued_at, claimed_at, done_at FROM post_enrichment';
    private const string COPY_TO_POST = 'INSERT INTO post_enrichment'
        . ' (id, entry_id, kind, queued_at, claimed_at, done_at)'
        . ' SELECT id, entry_id, kind, queued_at, claimed_at, done_at FROM share_enrichment';

    public function getDescription(): string
    {
        return 'Rename post_enrichment to share_enrichment, the queue of every shared link (#1524).';
    }

    public function up(Schema $schema): void
    {
        $this->skipIf($schema->hasTable('share_enrichment'), 'share_enrichment already exists.');

        $this->abortIfUnsupportedPlatform();
        $this->createQueueTable('share_enrichment', 'UNIQ_583FCE08BA364942', 'FK_583FCE08BA364942');
        $this->addSql(self::COPY_TO_SHARE);
        $this->addSql('DROP TABLE post_enrichment');
    }

    public function down(Schema $schema): void
    {
        $this->skipIf(!$schema->hasTable('share_enrichment'), 'share_enrichment does not exist.');

        $this->abortIfUnsupportedPlatform();
        $this->createQueueTable('post_enrichment', 'UNIQ_9FA2FF0FBA364942', 'FK_9FA2FF0FBA364942');
        $this->addSql(self::COPY_TO_POST);
        $this->addSql('DROP TABLE share_enrichment');
    }

    public function isTransactional(): bool
    {
        return false;
    }

    private function createQueueTable(string $table, string $uniqueIndex, string $foreignKey): void
    {
        if ($this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform) {
            $this->addSql(sprintf('CREATE TABLE %s (id INT AUTO_INCREMENT NOT NULL, kind VARCHAR(16) NOT NULL,'
                . ' queued_at DATETIME NOT NULL, claimed_at DATETIME DEFAULT NULL, done_at DATETIME DEFAULT NULL,'
                . ' entry_id INT NOT NULL, UNIQUE INDEX %s (entry_id), PRIMARY KEY (id))'
                . ' DEFAULT CHARACTER SET utf8mb4', $table, $uniqueIndex));
            $this->addSql(sprintf('ALTER TABLE %s ADD CONSTRAINT %s'
                . ' FOREIGN KEY (entry_id) REFERENCES entry (id) ON DELETE CASCADE', $table, $foreignKey));

            return;
        }

        $this->addSql(sprintf('CREATE TABLE %s (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,'
            . ' kind VARCHAR(16) NOT NULL, queued_at DATETIME NOT NULL, claimed_at DATETIME DEFAULT NULL,'
            . ' done_at DATETIME DEFAULT NULL, entry_id INTEGER NOT NULL, CONSTRAINT %s'
            . ' FOREIGN KEY (entry_id) REFERENCES entry (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)',
            $table, $foreignKey));
        $this->addSql(sprintf('CREATE UNIQUE INDEX %s ON %s (entry_id)', $uniqueIndex, $table));
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
