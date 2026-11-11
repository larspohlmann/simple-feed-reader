<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261111110000 extends AbstractMigration
{
    private const string TABLE = 'user_sharing_settings';

    public function getDescription(): string
    {
        return 'Sharing settings move from user_preferences to user_sharing_settings (#2177).';
    }

    public function up(Schema $schema): void
    {
        $this->skipIf($schema->hasTable(self::TABLE), 'Already applied.');

        $this->abortIfUnsupportedPlatform();

        if ($this->onMySql()) {
            $this->addSql('CREATE TABLE user_sharing_settings (id INT AUTO_INCREMENT NOT NULL,'
                . ' enabled TINYINT DEFAULT 1 NOT NULL, hidden_targets JSON NOT NULL,'
                . ' remember_shares TINYINT DEFAULT 1 NOT NULL, user_id INT NOT NULL,'
                . ' UNIQUE INDEX uniq_sharing_settings_user (user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
            $this->addSql('ALTER TABLE user_sharing_settings ADD CONSTRAINT FK_88232532A76ED395'
                . ' FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE');
        } else {
            $this->addSql('CREATE TABLE user_sharing_settings (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,'
                . ' enabled BOOLEAN DEFAULT 1 NOT NULL, hidden_targets CLOB NOT NULL,'
                . ' remember_shares BOOLEAN DEFAULT 1 NOT NULL, user_id INTEGER NOT NULL,'
                . ' CONSTRAINT FK_88232532A76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE'
                . ' NOT DEFERRABLE INITIALLY IMMEDIATE)');
            $this->addSql('CREATE UNIQUE INDEX uniq_sharing_settings_user ON user_sharing_settings (user_id)');
        }

        $emptyList = $this->emptyJsonList();
        $this->addSql(\sprintf(
            'INSERT INTO user_sharing_settings (user_id, enabled, hidden_targets, remember_shares)'
            . ' SELECT user_id, sharing_enabled, COALESCE(hidden_share_targets, %s), remember_shares FROM user_preferences',
            $emptyList,
        ));
        $this->addSql(\sprintf(
            'INSERT INTO user_sharing_settings (user_id, hidden_targets)'
            . ' SELECT id, %s FROM app_user WHERE id NOT IN (SELECT user_id FROM user_sharing_settings)',
            $emptyList,
        ));
        $this->dropPreferenceColumns();
    }

    public function down(Schema $schema): void
    {
        $this->skipIf(!$schema->hasTable(self::TABLE), 'Not applied.');

        $this->abortIfUnsupportedPlatform();

        if ($this->onMySql()) {
            $this->addSql('ALTER TABLE user_preferences ADD sharing_enabled TINYINT(1) DEFAULT 1 NOT NULL,'
                . ' ADD hidden_share_targets JSON DEFAULT NULL, ADD remember_shares TINYINT(1) DEFAULT 1 NOT NULL');
        } else {
            $this->addSql('ALTER TABLE user_preferences ADD COLUMN sharing_enabled BOOLEAN DEFAULT 1 NOT NULL');
            $this->addSql('ALTER TABLE user_preferences ADD COLUMN hidden_share_targets CLOB DEFAULT NULL');
            $this->addSql('ALTER TABLE user_preferences ADD COLUMN remember_shares BOOLEAN DEFAULT 1 NOT NULL');
        }

        $this->restoreColumn('sharing_enabled', 'enabled', '1');
        $this->restoreColumn('hidden_share_targets', 'hidden_targets', null);
        $this->restoreColumn('remember_shares', 'remember_shares', '1');
        $this->addSql('DROP TABLE user_sharing_settings');
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

    private function onMySql(): bool
    {
        return $this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform;
    }

    private function emptyJsonList(): string
    {
        return $this->onMySql() ? 'JSON_ARRAY()' : '\'[]\'';
    }

    private function dropPreferenceColumns(): void
    {
        if ($this->onMySql()) {
            $this->addSql('ALTER TABLE user_preferences DROP sharing_enabled, DROP hidden_share_targets, DROP remember_shares');

            return;
        }

        $this->addSql('ALTER TABLE user_preferences DROP COLUMN sharing_enabled');
        $this->addSql('ALTER TABLE user_preferences DROP COLUMN hidden_share_targets');
        $this->addSql('ALTER TABLE user_preferences DROP COLUMN remember_shares');
    }

    private function restoreColumn(string $column, string $sharingColumn, ?string $fallback): void
    {
        $copy = \sprintf(
            '(SELECT sharing.%s FROM user_sharing_settings sharing WHERE sharing.user_id = user_preferences.user_id)',
            $sharingColumn,
        );
        $this->addSql(\sprintf(
            'UPDATE user_preferences SET %s = %s',
            $column,
            null === $fallback ? $copy : \sprintf('COALESCE(%s, %s)', $copy, $fallback),
        ));
    }
}
