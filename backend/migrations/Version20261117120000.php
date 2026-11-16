<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261117120000 extends AbstractMigration
{
    private const string NOTIFICATION = 'social_notification';
    private const string POLL = 'social_notification_poll';

    public function getDescription(): string
    {
        return 'Notifications of a connected social account and when they were last checked (#2240).';
    }

    public function up(Schema $schema): void
    {
        $this->skipIf($schema->hasTable(self::NOTIFICATION) || $schema->hasTable(self::POLL), 'Already applied.');

        $this->abortIfUnsupportedPlatform();

        if ($this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform) {
            $this->upOnMySql();

            return;
        }

        $this->upOnSqlite();
    }

    public function down(Schema $schema): void
    {
        $this->skipIf(!$schema->hasTable(self::NOTIFICATION) && !$schema->hasTable(self::POLL), 'Not applied.');

        $this->abortIfUnsupportedPlatform();
        $this->addSql('DROP TABLE IF EXISTS ' . self::POLL);
        $this->addSql('DROP TABLE IF EXISTS ' . self::NOTIFICATION);
    }

    public function isTransactional(): bool
    {
        return false;
    }

    private function upOnMySql(): void
    {
        $this->addSql('CREATE TABLE social_notification (id INT AUTO_INCREMENT NOT NULL,'
            . ' remote_id VARCHAR(512) NOT NULL, kind VARCHAR(16) NOT NULL, created_at DATETIME NOT NULL,'
            . ' seen TINYINT NOT NULL, actor_handle VARCHAR(255) NOT NULL,'
            . ' actor_display_name VARCHAR(255) DEFAULT NULL, actor_avatar_url VARCHAR(2048) DEFAULT NULL,'
            . ' actor_profile_url VARCHAR(2048) NOT NULL, subject_group_key VARCHAR(512) NOT NULL,'
            . ' subject_excerpt VARCHAR(500) DEFAULT NULL, subject_link_url VARCHAR(2048) NOT NULL,'
            . ' social_account_id INT NOT NULL, INDEX IDX_63F7DFF55538ED78 (social_account_id),'
            . ' INDEX idx_social_notification_group (social_account_id, seen, kind, subject_group_key),'
            . ' INDEX idx_social_notification_created (created_at),'
            . ' UNIQUE INDEX uniq_social_notification_account_remote (social_account_id, remote_id),'
            . ' PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE social_notification_poll (id INT AUTO_INCREMENT NOT NULL,'
            . ' checked_at DATETIME DEFAULT NULL, scope_missing TINYINT DEFAULT 0 NOT NULL,'
            . ' social_account_id INT NOT NULL, UNIQUE INDEX UNIQ_D921A18D5538ED78 (social_account_id),'
            . ' PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE social_notification ADD CONSTRAINT FK_63F7DFF55538ED78'
            . ' FOREIGN KEY (social_account_id) REFERENCES social_account (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE social_notification_poll ADD CONSTRAINT FK_D921A18D5538ED78'
            . ' FOREIGN KEY (social_account_id) REFERENCES social_account (id) ON DELETE CASCADE');
    }

    private function upOnSqlite(): void
    {
        $this->addSql('CREATE TABLE social_notification (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,'
            . ' remote_id VARCHAR(512) NOT NULL, kind VARCHAR(16) NOT NULL, created_at DATETIME NOT NULL,'
            . ' seen BOOLEAN NOT NULL, actor_handle VARCHAR(255) NOT NULL,'
            . ' actor_display_name VARCHAR(255) DEFAULT NULL, actor_avatar_url VARCHAR(2048) DEFAULT NULL,'
            . ' actor_profile_url VARCHAR(2048) NOT NULL, subject_group_key VARCHAR(512) NOT NULL,'
            . ' subject_excerpt VARCHAR(500) DEFAULT NULL, subject_link_url VARCHAR(2048) NOT NULL,'
            . ' social_account_id INTEGER NOT NULL,'
            . ' CONSTRAINT FK_63F7DFF55538ED78 FOREIGN KEY (social_account_id)'
            . ' REFERENCES social_account (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX IDX_63F7DFF55538ED78 ON social_notification (social_account_id)');
        $this->addSql('CREATE INDEX idx_social_notification_group'
            . ' ON social_notification (social_account_id, seen, kind, subject_group_key)');
        $this->addSql('CREATE INDEX idx_social_notification_created ON social_notification (created_at)');
        $this->addSql('CREATE UNIQUE INDEX uniq_social_notification_account_remote'
            . ' ON social_notification (social_account_id, remote_id)');
        $this->addSql('CREATE TABLE social_notification_poll (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,'
            . ' checked_at DATETIME DEFAULT NULL, scope_missing BOOLEAN DEFAULT 0 NOT NULL,'
            . ' social_account_id INTEGER NOT NULL,'
            . ' CONSTRAINT FK_D921A18D5538ED78 FOREIGN KEY (social_account_id)'
            . ' REFERENCES social_account (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_D921A18D5538ED78 ON social_notification_poll (social_account_id)');
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
