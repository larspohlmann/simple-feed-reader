<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261115220000 extends AbstractMigration
{
    private const string TABLE = 'shared_link_resolution';

    public function getDescription(): string
    {
        return 'Create shared_link_resolution (#2236)';
    }

    public function up(Schema $schema): void
    {
        $this->skipIf($schema->hasTable(self::TABLE), 'shared_link_resolution already exists.');

        $platform = $this->connection->getDatabasePlatform();
        if ($platform instanceof AbstractMySQLPlatform) {
            $this->upMySql();

            return;
        }
        if ($platform instanceof SQLitePlatform) {
            $this->upSqlite();

            return;
        }

        throw new \RuntimeException('Unsupported database platform for the shared_link_resolution migration.');
    }

    public function down(Schema $schema): void
    {
        $this->skipIf(!$schema->hasTable(self::TABLE), 'shared_link_resolution does not exist.');

        $this->addSql('DROP TABLE shared_link_resolution');
    }

    public function isTransactional(): bool
    {
        return false;
    }

    private function upMySql(): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE shared_link_resolution (
                shared_link_id INT NOT NULL,
                status VARCHAR(10) NOT NULL,
                resolved_url VARCHAR(2048) DEFAULT NULL,
                resolved_url_hash VARCHAR(64) DEFAULT NULL,
                attempts SMALLINT DEFAULT 0 NOT NULL,
                retry_at DATETIME DEFAULT NULL,
                INDEX idx_shared_link_resolution_due (status, retry_at, shared_link_id),
                PRIMARY KEY (shared_link_id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
            SQL);
        $this->addSql(
            'ALTER TABLE shared_link_resolution ADD CONSTRAINT FK_425050968C730EC6 FOREIGN KEY (shared_link_id)'
            . ' REFERENCES shared_link (id) ON DELETE CASCADE',
        );
    }

    /** INT, not INTEGER: an INTEGER primary key aliases the rowid, which DBAL reads as autoincrement and diffs. */
    private function upSqlite(): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE shared_link_resolution (
                shared_link_id INT NOT NULL,
                status VARCHAR(10) NOT NULL,
                resolved_url VARCHAR(2048) DEFAULT NULL,
                resolved_url_hash VARCHAR(64) DEFAULT NULL,
                attempts SMALLINT DEFAULT 0 NOT NULL,
                retry_at DATETIME DEFAULT NULL,
                PRIMARY KEY (shared_link_id),
                CONSTRAINT FK_425050968C730EC6 FOREIGN KEY (shared_link_id) REFERENCES shared_link (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE
            )
            SQL);
        $this->addSql(
            'CREATE INDEX idx_shared_link_resolution_due ON shared_link_resolution (status, retry_at, shared_link_id)',
        );
    }
}
