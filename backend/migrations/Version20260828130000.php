<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260828130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Admin-configurable public base URL for outgoing email links (#636).';
    }

    public function up(Schema $schema): void
    {
        $this->assertSupportedPlatform();
        $this->addSql('ALTER TABLE instance_setting ADD public_base_url VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->assertSupportedPlatform();
        $this->addSql('ALTER TABLE instance_setting DROP public_base_url');
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
