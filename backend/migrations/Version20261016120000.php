<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** Platform-aware DDL, as Version20260904120000: the two dialects' auto-increment keys differ. */
final class Version20261016120000 extends AbstractMigration
{
    private const string TABLE = 'retention_setting';

    public function getDescription(): string
    {
        return 'Create retention_setting for the admin-configured retention windows (#1645)';
    }

    public function up(Schema $schema): void
    {
        $this->skipIf($schema->hasTable(self::TABLE), 'retention_setting already exists.');

        $platform = $this->connection->getDatabasePlatform();

        if ($platform instanceof AbstractMySQLPlatform) {
            $this->addSql(<<<'SQL'
                CREATE TABLE retention_setting (
                    id INT AUTO_INCREMENT NOT NULL,
                    entry_retention_days INT DEFAULT 90 NOT NULL,
                    social_retention_days INT DEFAULT 7 NOT NULL,
                    PRIMARY KEY (id)
                ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
                SQL);

            return;
        }

        if ($platform instanceof SQLitePlatform) {
            $this->addSql(<<<'SQL'
                CREATE TABLE retention_setting (
                    id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
                    entry_retention_days INTEGER DEFAULT 90 NOT NULL,
                    social_retention_days INTEGER DEFAULT 7 NOT NULL
                )
                SQL);

            return;
        }

        throw new \RuntimeException('Unsupported database platform for retention_setting migration.');
    }

    public function down(Schema $schema): void
    {
        $this->skipIf(!$schema->hasTable(self::TABLE), 'retention_setting does not exist.');
        $this->addSql('DROP TABLE retention_setting');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
