<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260904140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add mail_server_settings.use_proxy (#845)';
    }

    public function up(Schema $schema): void
    {
        $platform = $this->connection->getDatabasePlatform();
        if ($platform instanceof AbstractMySQLPlatform) {
            $this->addSql('ALTER TABLE mail_server_settings ADD use_proxy TINYINT(1) DEFAULT 0 NOT NULL');

            return;
        }
        if ($platform instanceof SQLitePlatform) {
            $this->addSql('ALTER TABLE mail_server_settings ADD use_proxy BOOLEAN DEFAULT 0 NOT NULL');

            return;
        }
        throw new \RuntimeException('Unsupported database platform for mail_server_settings migration.');
    }

    public function down(Schema $schema): void
    {
        $this->assertSupportedPlatform();
        $this->addSql('ALTER TABLE mail_server_settings DROP use_proxy');
    }

    private function assertSupportedPlatform(): void
    {
        $platform = $this->connection->getDatabasePlatform();
        $this->abortIf(
            !($platform instanceof AbstractMySQLPlatform) && !($platform instanceof SQLitePlatform),
            \sprintf('No DDL defined for platform %s; only MySQL and SQLite are supported.', $platform::class),
        );
    }
}
