<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260830090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Instance-wide passkey sign-in toggle, defaulting to enabled (#624 follow-up).';
    }

    public function up(Schema $schema): void
    {
        $this->assertSupportedPlatform();

        if ($this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform) {
            $this->addSql('ALTER TABLE instance_setting ADD passkey_sign_in_enabled TINYINT(1) NOT NULL DEFAULT 1');

            return;
        }

        $this->addSql('ALTER TABLE instance_setting ADD passkey_sign_in_enabled BOOLEAN NOT NULL DEFAULT true');
    }

    public function down(Schema $schema): void
    {
        $this->assertSupportedPlatform();
        $this->addSql('ALTER TABLE instance_setting DROP passkey_sign_in_enabled');
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
