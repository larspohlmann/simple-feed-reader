<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261012130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create social_feed: the private timeline, list and custom feeds of social accounts (#1599).';
    }

    public function up(Schema $schema): void
    {
        $this->skipIf($schema->hasTable('social_feed'), 'social_feed already exists.');

        $this->abortIfUnsupportedPlatform();

        if ($this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform) {
            $this->addSql('CREATE TABLE social_feed (id INT AUTO_INCREMENT NOT NULL, kind VARCHAR(16) NOT NULL,'
                . ' remote_id VARCHAR(512) NOT NULL, newest_seen_id VARCHAR(64) DEFAULT NULL,'
                . ' social_account_id INT NOT NULL, feed_id INT NOT NULL,'
                . ' INDEX idx_social_feed_account (social_account_id),'
                . ' UNIQUE INDEX uniq_social_feed_feed (feed_id),'
                . ' UNIQUE INDEX uniq_social_feed_remote (social_account_id, kind, remote_id), PRIMARY KEY (id))'
                . ' DEFAULT CHARACTER SET utf8mb4');
            $this->addSql('ALTER TABLE social_feed ADD CONSTRAINT fk_social_feed_account'
                . ' FOREIGN KEY (social_account_id) REFERENCES social_account (id) ON DELETE CASCADE');
            $this->addSql('ALTER TABLE social_feed ADD CONSTRAINT fk_social_feed_feed'
                . ' FOREIGN KEY (feed_id) REFERENCES feed (id) ON DELETE CASCADE');

            return;
        }

        $this->addSql('CREATE TABLE social_feed (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,'
            . ' kind VARCHAR(16) NOT NULL, remote_id VARCHAR(512) NOT NULL, newest_seen_id VARCHAR(64) DEFAULT NULL,'
            . ' social_account_id INTEGER NOT NULL, feed_id INTEGER NOT NULL,'
            . ' CONSTRAINT fk_social_feed_account FOREIGN KEY (social_account_id) REFERENCES social_account (id)'
            . ' ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE,'
            . ' CONSTRAINT fk_social_feed_feed FOREIGN KEY (feed_id) REFERENCES feed (id)'
            . ' ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX idx_social_feed_account ON social_feed (social_account_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_social_feed_feed ON social_feed (feed_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_social_feed_remote ON social_feed (social_account_id, kind, remote_id)');
    }

    public function down(Schema $schema): void
    {
        $this->skipIf(!$schema->hasTable('social_feed'), 'social_feed does not exist.');

        $this->abortIfUnsupportedPlatform();
        $this->addSql('DROP TABLE social_feed');
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
