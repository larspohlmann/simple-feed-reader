<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261111120000 extends AbstractMigration
{
    private const string TABLE = 'user_digest_settings';

    public function getDescription(): string
    {
        return 'Digest settings move from user_preferences to user_digest_settings (#2177).';
    }

    public function up(Schema $schema): void
    {
        $this->skipIf($schema->hasTable(self::TABLE), 'Already applied.');

        $this->abortIfUnsupportedPlatform();

        if ($this->onMySql()) {
            $this->addSql('CREATE TABLE user_digest_settings (id INT AUTO_INCREMENT NOT NULL,'
                . ' enabled TINYINT DEFAULT 0 NOT NULL, cadence VARCHAR(10) DEFAULT \'daily\' NOT NULL,'
                . ' format VARCHAR(10) DEFAULT \'html\' NOT NULL, send_hour SMALLINT DEFAULT 8 NOT NULL,'
                . ' weekday SMALLINT DEFAULT 1 NOT NULL, last_sent_at DATETIME DEFAULT NULL, user_id INT NOT NULL,'
                . ' UNIQUE INDEX uniq_digest_settings_user (user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
            $this->addSql('ALTER TABLE user_digest_settings ADD CONSTRAINT FK_59591884A76ED395'
                . ' FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE');
        } else {
            $this->addSql('CREATE TABLE user_digest_settings (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,'
                . ' enabled BOOLEAN DEFAULT 0 NOT NULL, cadence VARCHAR(10) DEFAULT \'daily\' NOT NULL,'
                . ' format VARCHAR(10) DEFAULT \'html\' NOT NULL, send_hour SMALLINT DEFAULT 8 NOT NULL,'
                . ' weekday SMALLINT DEFAULT 1 NOT NULL, last_sent_at DATETIME DEFAULT NULL, user_id INTEGER NOT NULL,'
                . ' CONSTRAINT FK_59591884A76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE'
                . ' NOT DEFERRABLE INITIALLY IMMEDIATE)');
            $this->addSql('CREATE UNIQUE INDEX uniq_digest_settings_user ON user_digest_settings (user_id)');
        }

        $this->addSql('INSERT INTO user_digest_settings (user_id, enabled, cadence, format, send_hour, weekday, last_sent_at)'
            . ' SELECT user_id, digest_enabled, digest_cadence, digest_format, digest_send_hour, digest_weekday,'
            . ' digest_last_sent_at FROM user_preferences');
        $this->addSql('INSERT INTO user_digest_settings (user_id)'
            . ' SELECT id FROM app_user WHERE id NOT IN (SELECT user_id FROM user_digest_settings)');
        $this->dropPreferenceColumns();
    }

    public function down(Schema $schema): void
    {
        $this->skipIf(!$schema->hasTable(self::TABLE), 'Not applied.');

        $this->abortIfUnsupportedPlatform();

        if ($this->onMySql()) {
            $this->addSql('ALTER TABLE user_preferences ADD digest_enabled TINYINT(1) DEFAULT 0 NOT NULL,'
                . ' ADD digest_cadence VARCHAR(10) DEFAULT \'daily\' NOT NULL,'
                . ' ADD digest_send_hour SMALLINT DEFAULT 8 NOT NULL, ADD digest_weekday SMALLINT DEFAULT 1 NOT NULL,'
                . ' ADD digest_last_sent_at DATETIME DEFAULT NULL,'
                . ' ADD digest_format VARCHAR(10) DEFAULT \'html\' NOT NULL');
        } else {
            $this->addSql('ALTER TABLE user_preferences ADD COLUMN digest_enabled BOOLEAN DEFAULT 0 NOT NULL');
            $this->addSql('ALTER TABLE user_preferences ADD COLUMN digest_cadence VARCHAR(10) DEFAULT \'daily\' NOT NULL');
            $this->addSql('ALTER TABLE user_preferences ADD COLUMN digest_send_hour SMALLINT DEFAULT 8 NOT NULL');
            $this->addSql('ALTER TABLE user_preferences ADD COLUMN digest_weekday SMALLINT DEFAULT 1 NOT NULL');
            $this->addSql('ALTER TABLE user_preferences ADD COLUMN digest_last_sent_at DATETIME DEFAULT NULL');
            $this->addSql('ALTER TABLE user_preferences ADD COLUMN digest_format VARCHAR(10) DEFAULT \'html\' NOT NULL');
        }

        $this->restoreColumn('digest_enabled', 'enabled', '0');
        $this->restoreColumn('digest_cadence', 'cadence', '\'daily\'');
        $this->restoreColumn('digest_send_hour', 'send_hour', '8');
        $this->restoreColumn('digest_weekday', 'weekday', '1');
        $this->restoreColumn('digest_last_sent_at', 'last_sent_at', null);
        $this->restoreColumn('digest_format', 'format', '\'html\'');
        $this->addSql('DROP TABLE user_digest_settings');
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

    private function dropPreferenceColumns(): void
    {
        if ($this->onMySql()) {
            $this->addSql('ALTER TABLE user_preferences DROP digest_enabled, DROP digest_cadence, DROP digest_format,'
                . ' DROP digest_send_hour, DROP digest_weekday, DROP digest_last_sent_at');

            return;
        }

        foreach (['digest_enabled', 'digest_cadence', 'digest_format', 'digest_send_hour', 'digest_weekday', 'digest_last_sent_at'] as $column) {
            $this->addSql(\sprintf('ALTER TABLE user_preferences DROP COLUMN %s', $column));
        }
    }

    private function restoreColumn(string $column, string $digestColumn, ?string $fallback): void
    {
        $copy = \sprintf(
            '(SELECT digest.%s FROM user_digest_settings digest WHERE digest.user_id = user_preferences.user_id)',
            $digestColumn,
        );
        $this->addSql(\sprintf(
            'UPDATE user_preferences SET %s = %s',
            $column,
            null === $fallback ? $copy : \sprintf('COALESCE(%s, %s)', $copy, $fallback),
        ));
    }
}
