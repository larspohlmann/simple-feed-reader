<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The mail send failure log (#882): PLATFORM-AWARE DDL for the same reason as
 * the other fresh-table migrations — SQLite's INTEGER PRIMARY KEY AUTOINCREMENT
 * is not valid MySQL, and vice versa.
 */
final class Version20260906101218 extends AbstractMigration
{
    private const TABLE = 'mail_send_failure';

    public function getDescription(): string
    {
        return 'Create mail_send_failure for the admin Mail failure pill (#882)';
    }

    public function up(Schema $schema): void
    {
        $this->skipIf($schema->hasTable(self::TABLE), 'mail_send_failure already exists.');

        $platform = $this->connection->getDatabasePlatform();

        if ($platform instanceof AbstractMySQLPlatform) {
            $this->addSql(<<<'SQL'
                CREATE TABLE mail_send_failure (
                    id INT AUTO_INCREMENT NOT NULL,
                    kind VARCHAR(16) NOT NULL,
                    recipient VARCHAR(255) NOT NULL,
                    error_detail LONGTEXT NOT NULL,
                    created_at DATETIME NOT NULL,
                    PRIMARY KEY (id)
                ) DEFAULT CHARACTER SET utf8mb4
                SQL);

            return;
        }

        if ($platform instanceof SQLitePlatform) {
            $this->addSql(<<<'SQL'
                CREATE TABLE mail_send_failure (
                    id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
                    kind VARCHAR(16) NOT NULL,
                    recipient VARCHAR(255) NOT NULL,
                    error_detail CLOB NOT NULL,
                    created_at DATETIME NOT NULL
                )
                SQL);

            return;
        }

        throw new \RuntimeException('Unsupported database platform for mail_send_failure migration.');
    }

    public function down(Schema $schema): void
    {
        $this->skipIf(!$schema->hasTable(self::TABLE), 'mail_send_failure does not exist.');
        $this->addSql('DROP TABLE mail_send_failure');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
