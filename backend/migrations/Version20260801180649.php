<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds the instance_setting table for the admin registration-gate toggles
 * (#224). A single row holds require_email_confirmation and require_approval.
 *
 * PLATFORM-AWARE DDL: tests build their schema from ORM metadata and never
 * execute a migration, so a dialect error here is caught only by CI's
 * migrate-from-empty leg. ADDITIVE ONLY: no existing table is touched, and an
 * absent row reads as both gates on.
 */
final class Version20260801180649 extends AbstractMigration
{
    private const TABLE = 'instance_setting';

    public function getDescription(): string
    {
        return 'Add instance_setting table for registration-gate toggles (#224).';
    }

    public function up(Schema $schema): void
    {
        $this->skipIf($schema->hasTable(self::TABLE), 'instance_setting already exists.');

        $platform = $this->connection->getDatabasePlatform();

        if ($platform instanceof AbstractMySQLPlatform) {
            $this->addSql(
                'CREATE TABLE instance_setting (id INT AUTO_INCREMENT NOT NULL, '
                . 'require_email_confirmation TINYINT(1) NOT NULL, '
                . 'require_approval TINYINT(1) NOT NULL, '
                . 'PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 ENGINE = InnoDB',
            );

            return;
        }

        if ($platform instanceof SQLitePlatform) {
            $this->addSql(
                'CREATE TABLE instance_setting (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, '
                . 'require_email_confirmation BOOLEAN NOT NULL, '
                . 'require_approval BOOLEAN NOT NULL)',
            );

            return;
        }

        throw new \RuntimeException('Unsupported database platform for instance_setting migration.');
    }

    public function down(Schema $schema): void
    {
        $this->skipIf(!$schema->hasTable(self::TABLE), 'instance_setting does not exist.');
        $this->addSql('DROP TABLE instance_setting');
    }
}
