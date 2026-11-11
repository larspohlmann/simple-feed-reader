<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261111100000 extends AbstractMigration
{
    private const string TABLE = 'user_reading_settings';

    public function getDescription(): string
    {
        return 'Reading settings move from user_preferences to user_reading_settings (#2177).';
    }

    public function up(Schema $schema): void
    {
        $this->skipIf($schema->hasTable(self::TABLE), 'Already applied.');

        $this->abortIfUnsupportedPlatform();

        if ($this->onMySql()) {
            $this->addSql('CREATE TABLE user_reading_settings (id INT AUTO_INCREMENT NOT NULL,'
                . ' scrape_fallback_enabled TINYINT DEFAULT 0 NOT NULL, magazine_style VARCHAR(10) DEFAULT \'boxed\' NOT NULL,'
                . ' trending_enabled TINYINT DEFAULT 1 NOT NULL, user_id INT NOT NULL,'
                . ' UNIQUE INDEX uniq_reading_settings_user (user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
            $this->addSql('ALTER TABLE user_reading_settings ADD CONSTRAINT FK_CEA7BFA8A76ED395'
                . ' FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE');
        } else {
            $this->addSql('CREATE TABLE user_reading_settings (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,'
                . ' scrape_fallback_enabled BOOLEAN DEFAULT 0 NOT NULL, magazine_style VARCHAR(10) DEFAULT \'boxed\' NOT NULL,'
                . ' trending_enabled BOOLEAN DEFAULT 1 NOT NULL, user_id INTEGER NOT NULL,'
                . ' CONSTRAINT FK_CEA7BFA8A76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE'
                . ' NOT DEFERRABLE INITIALLY IMMEDIATE)');
            $this->addSql('CREATE UNIQUE INDEX uniq_reading_settings_user ON user_reading_settings (user_id)');
        }

        $this->addSql('INSERT INTO user_reading_settings (user_id, scrape_fallback_enabled, magazine_style, trending_enabled)'
            . ' SELECT user_id, scrape_fallback_enabled, magazine_style, trending_enabled FROM user_preferences');
        $this->addSql('INSERT INTO user_reading_settings (user_id)'
            . ' SELECT id FROM app_user WHERE id NOT IN (SELECT user_id FROM user_reading_settings)');
        $this->dropPreferenceColumns();
    }

    public function down(Schema $schema): void
    {
        $this->skipIf(!$schema->hasTable(self::TABLE), 'Not applied.');

        $this->abortIfUnsupportedPlatform();

        if ($this->onMySql()) {
            $this->addSql('ALTER TABLE user_preferences ADD scrape_fallback_enabled TINYINT(1) DEFAULT 0 NOT NULL,'
                . ' ADD magazine_style VARCHAR(10) DEFAULT \'boxed\' NOT NULL, ADD trending_enabled TINYINT(1) DEFAULT 1 NOT NULL');
        } else {
            $this->addSql('ALTER TABLE user_preferences ADD COLUMN scrape_fallback_enabled BOOLEAN DEFAULT 0 NOT NULL');
            $this->addSql('ALTER TABLE user_preferences ADD COLUMN magazine_style VARCHAR(10) DEFAULT \'boxed\' NOT NULL');
            $this->addSql('ALTER TABLE user_preferences ADD COLUMN trending_enabled BOOLEAN DEFAULT 1 NOT NULL');
        }

        $this->restoreColumn('scrape_fallback_enabled', '0');
        $this->restoreColumn('magazine_style', '\'boxed\'');
        $this->restoreColumn('trending_enabled', '1');
        $this->addSql('DROP TABLE user_reading_settings');
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
            $this->addSql('ALTER TABLE user_preferences DROP scrape_fallback_enabled, DROP magazine_style, DROP trending_enabled');

            return;
        }

        $this->addSql('ALTER TABLE user_preferences DROP COLUMN scrape_fallback_enabled');
        $this->addSql('ALTER TABLE user_preferences DROP COLUMN magazine_style');
        $this->addSql('ALTER TABLE user_preferences DROP COLUMN trending_enabled');
    }

    private function restoreColumn(string $column, string $fallback): void
    {
        $this->addSql(\sprintf(
            'UPDATE user_preferences SET %1$s = COALESCE((SELECT reading.%1$s FROM user_reading_settings reading'
            . ' WHERE reading.user_id = user_preferences.user_id), %2$s)',
            $column,
            $fallback,
        ));
    }
}
