<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260829165039 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Records whether the one-time passkey enrolment offer was answered (#624).';
    }

    public function up(Schema $schema): void
    {
        $this->assertSupportedPlatform();
        $this->addSql('ALTER TABLE user_preferences ADD passkey_offer_answered_at DATETIME DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->assertSupportedPlatform();
        $this->addSql('ALTER TABLE user_preferences DROP passkey_offer_answered_at');
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
