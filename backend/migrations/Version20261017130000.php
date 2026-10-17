<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261017130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add the sync stamp to social_account and create known_social_collection and'
            . ' private_feed_sync_change for the hourly list and feed sync (#1666).';
    }

    public function up(Schema $schema): void
    {
        $this->skipIf($schema->hasTable('known_social_collection'), 'known_social_collection already exists.');

        $this->abortIfUnsupportedPlatform();

        if ($this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform) {
            $this->upOnMySql();

            return;
        }

        $this->upOnSqlite();
    }

    public function down(Schema $schema): void
    {
        $this->skipIf(!$schema->hasTable('known_social_collection'), 'known_social_collection does not exist.');

        $this->abortIfUnsupportedPlatform();
        $this->addSql('DROP TABLE private_feed_sync_change');
        $this->addSql('DROP TABLE known_social_collection');
        $this->addSql('ALTER TABLE social_account DROP COLUMN last_sync_skip');
        $this->addSql('ALTER TABLE social_account DROP COLUMN last_synced_at');
        $this->addSql('ALTER TABLE social_account DROP COLUMN last_checked_at');
    }

    public function isTransactional(): bool
    {
        return false;
    }

    private function upOnMySql(): void
    {
        $this->addSql('ALTER TABLE social_account ADD last_checked_at DATETIME DEFAULT NULL,'
            . ' ADD last_synced_at DATETIME DEFAULT NULL, ADD last_sync_skip VARCHAR(24) DEFAULT NULL');
        $this->addSql('CREATE TABLE known_social_collection (id INT AUTO_INCREMENT NOT NULL,'
            . ' kind VARCHAR(16) NOT NULL, remote_id VARCHAR(512) NOT NULL, social_account_id INT NOT NULL,'
            . ' INDEX idx_known_social_collection_account (social_account_id),'
            . ' UNIQUE INDEX uniq_known_social_collection (social_account_id, kind, remote_id), PRIMARY KEY (id))'
            . ' DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE private_feed_sync_change (id INT AUTO_INCREMENT NOT NULL,'
            . ' kind VARCHAR(16) NOT NULL, remote_id VARCHAR(512) NOT NULL, name VARCHAR(512) NOT NULL,'
            . ' reason VARCHAR(16) NOT NULL, changed_at DATETIME NOT NULL, social_account_id INT NOT NULL,'
            . ' INDEX IDX_99A356955538ED78 (social_account_id),'
            . ' INDEX idx_private_feed_sync_change_account (social_account_id, changed_at), PRIMARY KEY (id))'
            . ' DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE known_social_collection ADD CONSTRAINT fk_known_social_collection_account'
            . ' FOREIGN KEY (social_account_id) REFERENCES social_account (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE private_feed_sync_change ADD CONSTRAINT fk_private_feed_sync_change_account'
            . ' FOREIGN KEY (social_account_id) REFERENCES social_account (id) ON DELETE CASCADE');
    }

    private function upOnSqlite(): void
    {
        $this->addSql('ALTER TABLE social_account ADD COLUMN last_checked_at DATETIME DEFAULT NULL');
        $this->addSql('ALTER TABLE social_account ADD COLUMN last_synced_at DATETIME DEFAULT NULL');
        $this->addSql('ALTER TABLE social_account ADD COLUMN last_sync_skip VARCHAR(24) DEFAULT NULL');
        $this->addSql('CREATE TABLE known_social_collection (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,'
            . ' kind VARCHAR(16) NOT NULL, remote_id VARCHAR(512) NOT NULL, social_account_id INTEGER NOT NULL,'
            . ' CONSTRAINT fk_known_social_collection_account FOREIGN KEY (social_account_id)'
            . ' REFERENCES social_account (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX idx_known_social_collection_account'
            . ' ON known_social_collection (social_account_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_known_social_collection'
            . ' ON known_social_collection (social_account_id, kind, remote_id)');
        $this->addSql('CREATE TABLE private_feed_sync_change (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,'
            . ' kind VARCHAR(16) NOT NULL, remote_id VARCHAR(512) NOT NULL, name VARCHAR(512) NOT NULL,'
            . ' reason VARCHAR(16) NOT NULL, changed_at DATETIME NOT NULL, social_account_id INTEGER NOT NULL,'
            . ' CONSTRAINT fk_private_feed_sync_change_account FOREIGN KEY (social_account_id)'
            . ' REFERENCES social_account (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX IDX_99A356955538ED78 ON private_feed_sync_change (social_account_id)');
        $this->addSql('CREATE INDEX idx_private_feed_sync_change_account'
            . ' ON private_feed_sync_change (social_account_id, changed_at)');
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
