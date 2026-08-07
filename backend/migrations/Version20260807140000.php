<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Worker heartbeat (#311): one named liveness signal the poll driver reads
 * to decide whether a background worker already owns execution.
 *
 * PLATFORM-AWARE DDL: tests build their schema from ORM metadata and never
 * execute a migration; CI's migrate-from-empty leg is the only runtime check.
 */
final class Version20260807140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Worker heartbeat (#311): one named liveness signal table.';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->skipIf($schema->hasTable('worker_heartbeat'), 'worker_heartbeat already exists; nothing to do.');

        $platform = $this->connection->getDatabasePlatform();

        if ($platform instanceof AbstractMySQLPlatform) {
            $this->upMySql();

            return;
        }

        if ($platform instanceof SQLitePlatform) {
            $this->upSqlite();

            return;
        }

        $this->abortIf(true, \sprintf(
            'No DDL defined for platform %s; only MySQL and SQLite are supported.',
            $platform::class,
        ));
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE worker_heartbeat');
    }

    private function upMySql(): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE worker_heartbeat (
                name VARCHAR(64) NOT NULL,
                touched_at DATETIME NOT NULL,
                PRIMARY KEY (name)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
            SQL);
    }

    private function upSqlite(): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE worker_heartbeat (
                name VARCHAR(64) NOT NULL,
                touched_at DATETIME NOT NULL,
                PRIMARY KEY (name)
            )
            SQL);
    }
}
