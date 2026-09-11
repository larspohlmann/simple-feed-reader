<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The instance-wide Grafana/Loki wiring row (#983). PLATFORM-AWARE DDL for the
 * same reason as the other fresh-table migrations: SQLite's
 * INTEGER PRIMARY KEY AUTOINCREMENT is not valid MySQL, and vice versa. Tests
 * build their schema from ORM metadata and never run a migration, so a dialect
 * error here is caught only by CI's migrate-from-empty leg.
 */
final class Version20260911150236 extends AbstractMigration
{
    private const TABLE = 'grafana_settings';

    public function getDescription(): string
    {
        return 'Create grafana_settings for the admin-configured Loki/Grafana wiring (#983)';
    }

    public function up(Schema $schema): void
    {
        $this->skipIf($schema->hasTable(self::TABLE), 'grafana_settings already exists.');

        $platform = $this->connection->getDatabasePlatform();

        if ($platform instanceof AbstractMySQLPlatform) {
            $this->addSql(<<<'SQL'
                CREATE TABLE grafana_settings (
                    id INT AUTO_INCREMENT NOT NULL,
                    loki_push_url VARCHAR(255) DEFAULT NULL,
                    loki_username VARCHAR(255) DEFAULT NULL,
                    grafana_url VARCHAR(255) DEFAULT NULL,
                    token_ciphertext VARCHAR(1024) NOT NULL,
                    token_nonce VARCHAR(64) NOT NULL,
                    token_salt VARCHAR(64) NOT NULL,
                    token_hint VARCHAR(8) NOT NULL,
                    key_version INT DEFAULT 1 NOT NULL,
                    PRIMARY KEY (id)
                ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
                SQL);

            return;
        }

        if ($platform instanceof SQLitePlatform) {
            $this->addSql(<<<'SQL'
                CREATE TABLE grafana_settings (
                    id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
                    loki_push_url VARCHAR(255) DEFAULT NULL,
                    loki_username VARCHAR(255) DEFAULT NULL,
                    grafana_url VARCHAR(255) DEFAULT NULL,
                    token_ciphertext VARCHAR(1024) NOT NULL,
                    token_nonce VARCHAR(64) NOT NULL,
                    token_salt VARCHAR(64) NOT NULL,
                    token_hint VARCHAR(8) NOT NULL,
                    key_version INTEGER DEFAULT 1 NOT NULL
                )
                SQL);

            return;
        }

        throw new \RuntimeException('Unsupported database platform for grafana_settings migration.');
    }

    public function down(Schema $schema): void
    {
        $this->skipIf(!$schema->hasTable(self::TABLE), 'grafana_settings does not exist.');
        $this->addSql('DROP TABLE grafana_settings');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
