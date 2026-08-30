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
        return 'Instance-wide passkey sign-in toggle, defaulting to DISABLED (#624 follow-up, addendum).';
    }

    /**
     * Edited in place rather than reversed by a second migration (fix round
     * 1 addendum): this branch is not pushed, so nobody outside it has run
     * the original DEFAULT 1 version, and a follow-up migration that merely
     * flips a default nobody has observed yet would be pure noise. Off by
     * default: "activated" should mean activated, not "on unless an admin
     * remembers to turn it off" — a fresh install ships with passkey sign-in
     * invisible until an admin opts in from the instance settings page.
     */
    public function up(Schema $schema): void
    {
        $this->assertSupportedPlatform();
        $this->addSql('ALTER TABLE instance_setting ADD passkey_sign_in_enabled TINYINT(1) DEFAULT 0 NOT NULL');
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
