<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The per-account AI provider row (#305).
 *
 * PLATFORM-AWARE DDL for the same reason the other fresh-table migrations in
 * this directory are: SQLite's auto-increment primary key
 * (`INTEGER PRIMARY KEY AUTOINCREMENT`) is not valid MySQL syntax, and MySQL's
 * inline `ADD CONSTRAINT` foreign key is not valid SQLite syntax. Tests build
 * their schema from ORM metadata and never execute a migration, so a dialect
 * error here would only be caught by CI's migrate-from-empty leg.
 */
final class Version20260806120000 extends AbstractMigration
{
    private const TABLE = 'user_ai_settings';

    public function getDescription(): string
    {
        return 'Create user_ai_settings for the per-account AI provider (#305)';
    }

    public function up(Schema $schema): void
    {
        $this->skipIf($schema->hasTable(self::TABLE), 'user_ai_settings already exists.');

        $platform = $this->connection->getDatabasePlatform();

        if ($platform instanceof AbstractMySQLPlatform) {
            $this->addSql(<<<'SQL'
                CREATE TABLE user_ai_settings (
                    id INT AUTO_INCREMENT NOT NULL,
                    base_url VARCHAR(512) NOT NULL,
                    api_key_ciphertext VARCHAR(1024) NOT NULL,
                    api_key_nonce VARCHAR(64) NOT NULL,
                    api_key_salt VARCHAR(64) NOT NULL,
                    api_key_hint VARCHAR(8) NOT NULL,
                    key_version INT DEFAULT 1 NOT NULL,
                    model VARCHAR(255) DEFAULT NULL,
                    verified_at DATETIME DEFAULT NULL,
                    user_id INT NOT NULL,
                    UNIQUE INDEX uniq_ai_settings_user (user_id),
                    PRIMARY KEY (id)
                ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
                SQL);
            $this->addSql(<<<'SQL'
                ALTER TABLE user_ai_settings
                ADD CONSTRAINT FK_53B8EF30A76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE
                SQL);

            return;
        }

        if ($platform instanceof SQLitePlatform) {
            $this->addSql(<<<'SQL'
                CREATE TABLE user_ai_settings (
                    id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
                    base_url VARCHAR(512) NOT NULL,
                    api_key_ciphertext VARCHAR(1024) NOT NULL,
                    api_key_nonce VARCHAR(64) NOT NULL,
                    api_key_salt VARCHAR(64) NOT NULL,
                    api_key_hint VARCHAR(8) NOT NULL,
                    key_version INTEGER DEFAULT 1 NOT NULL,
                    model VARCHAR(255) DEFAULT NULL,
                    verified_at DATETIME DEFAULT NULL,
                    user_id INTEGER NOT NULL,
                    CONSTRAINT FK_53B8EF30A76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE
                )
                SQL);
            $this->addSql('CREATE UNIQUE INDEX uniq_ai_settings_user ON user_ai_settings (user_id)');

            return;
        }

        throw new \RuntimeException('Unsupported database platform for user_ai_settings migration.');
    }

    public function down(Schema $schema): void
    {
        $this->skipIf(!$schema->hasTable(self::TABLE), 'user_ai_settings does not exist.');
        $this->addSql('DROP TABLE user_ai_settings');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
