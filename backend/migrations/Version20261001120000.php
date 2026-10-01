<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** PLATFORM-AWARE DDL: SQLite drops one column per ALTER and rejects the multi-DROP MySQL accepts. */
final class Version20261001120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Drop profiling_enabled and pyroscope_push_url from grafana_settings (#1328)';
    }

    public function up(Schema $schema): void
    {
        $platform = $this->connection->getDatabasePlatform();

        if ($platform instanceof AbstractMySQLPlatform) {
            $this->addSql('ALTER TABLE grafana_settings DROP profiling_enabled, DROP pyroscope_push_url');

            return;
        }

        if ($platform instanceof SQLitePlatform) {
            $this->addSql('ALTER TABLE grafana_settings DROP COLUMN profiling_enabled');
            $this->addSql('ALTER TABLE grafana_settings DROP COLUMN pyroscope_push_url');

            return;
        }

        throw new \RuntimeException('Unsupported database platform for the grafana_settings profiling migration.');
    }

    public function down(Schema $schema): void
    {
        $platform = $this->connection->getDatabasePlatform();

        if ($platform instanceof AbstractMySQLPlatform) {
            $this->addSql('ALTER TABLE grafana_settings ADD profiling_enabled TINYINT DEFAULT 0 NOT NULL, ADD pyroscope_push_url VARCHAR(255) DEFAULT NULL');

            return;
        }

        if ($platform instanceof SQLitePlatform) {
            $this->addSql('ALTER TABLE grafana_settings ADD COLUMN profiling_enabled BOOLEAN DEFAULT 0 NOT NULL');
            $this->addSql('ALTER TABLE grafana_settings ADD COLUMN pyroscope_push_url VARCHAR(255) DEFAULT NULL');

            return;
        }

        throw new \RuntimeException('Unsupported database platform for the grafana_settings profiling migration.');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
