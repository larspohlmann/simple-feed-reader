<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** Drop the clear-text password_hint from mail_server_settings (#845): the admin
 *  page now shows only that a password is saved, so storing the last four
 *  characters in clear text buys nothing. */
final class Version20260904130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Drop mail_server_settings.password_hint (#845)';
    }

    public function up(Schema $schema): void
    {
        $this->assertSupportedPlatform();
        $this->addSql('ALTER TABLE mail_server_settings DROP password_hint');
    }

    public function down(Schema $schema): void
    {
        $this->assertSupportedPlatform();
        $this->addSql("ALTER TABLE mail_server_settings ADD password_hint VARCHAR(8) DEFAULT '' NOT NULL");
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
