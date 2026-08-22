<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The instance-wide egress proxy settings row (#490). PLATFORM-AWARE DDL for the
 * same reason as the other fresh-table migrations: SQLite's
 * INTEGER PRIMARY KEY AUTOINCREMENT is not valid MySQL, and vice versa. Tests
 * build their schema from ORM metadata and never run a migration, so a dialect
 * error here is caught only by CI's migrate-from-empty leg.
 */
final class Version20260822130000 extends AbstractMigration
{
    private const TABLE = 'proxy_server_settings';

    public function getDescription(): string
    {
        return 'Create proxy_server_settings for the instance-wide egress proxy (#490)';
    }

    public function up(Schema $schema): void
    {
        $this->skipIf($schema->hasTable(self::TABLE), 'proxy_server_settings already exists.');

        $platform = $this->connection->getDatabasePlatform();

        if ($platform instanceof AbstractMySQLPlatform) {
            $this->addSql(<<<'SQL'
                CREATE TABLE proxy_server_settings (
                    id INT AUTO_INCREMENT NOT NULL,
                    enabled TINYINT(1) DEFAULT 0 NOT NULL,
                    direct_fallback TINYINT(1) DEFAULT 1 NOT NULL,
                    type VARCHAR(8) NOT NULL,
                    host VARCHAR(255) NOT NULL,
                    port INT NOT NULL,
                    username VARCHAR(255) DEFAULT NULL,
                    password_ciphertext VARCHAR(1024) NOT NULL,
                    password_nonce VARCHAR(64) NOT NULL,
                    password_salt VARCHAR(64) NOT NULL,
                    password_hint VARCHAR(8) NOT NULL,
                    key_version INT DEFAULT 1 NOT NULL,
                    PRIMARY KEY (id)
                ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
                SQL);

            return;
        }

        if ($platform instanceof SQLitePlatform) {
            $this->addSql(<<<'SQL'
                CREATE TABLE proxy_server_settings (
                    id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
                    enabled BOOLEAN DEFAULT 0 NOT NULL,
                    direct_fallback BOOLEAN DEFAULT 1 NOT NULL,
                    type VARCHAR(8) NOT NULL,
                    host VARCHAR(255) NOT NULL,
                    port INTEGER NOT NULL,
                    username VARCHAR(255) DEFAULT NULL,
                    password_ciphertext VARCHAR(1024) NOT NULL,
                    password_nonce VARCHAR(64) NOT NULL,
                    password_salt VARCHAR(64) NOT NULL,
                    password_hint VARCHAR(8) NOT NULL,
                    key_version INTEGER DEFAULT 1 NOT NULL
                )
                SQL);

            return;
        }

        throw new \RuntimeException('Unsupported database platform for proxy_server_settings migration.');
    }

    public function down(Schema $schema): void
    {
        $this->skipIf(!$schema->hasTable(self::TABLE), 'proxy_server_settings does not exist.');
        $this->addSql('DROP TABLE proxy_server_settings');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
