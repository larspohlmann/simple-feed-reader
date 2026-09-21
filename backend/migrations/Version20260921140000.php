<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260921140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Index the pending image verification queue (#1109).';
    }

    public function up(Schema $schema): void
    {
        $this->assertSupportedPlatform();
        $this->addSql('CREATE INDEX idx_entry_image_verify ON entry (image_verify_attempts, id)');
    }

    public function down(Schema $schema): void
    {
        $platform = $this->assertSupportedPlatform();
        $this->addSql($platform instanceof AbstractMySQLPlatform
            ? 'DROP INDEX idx_entry_image_verify ON entry'
            : 'DROP INDEX idx_entry_image_verify');
    }

    public function isTransactional(): bool
    {
        return false;
    }

    private function assertSupportedPlatform(): AbstractMySQLPlatform|SQLitePlatform
    {
        $platform = $this->connection->getDatabasePlatform();
        $this->abortIf(
            !($platform instanceof AbstractMySQLPlatform) && !($platform instanceof SQLitePlatform),
            sprintf('No DDL defined for platform %s; only MySQL and SQLite are supported.', $platform::class),
        );

        /** @var AbstractMySQLPlatform|SQLitePlatform $platform */
        return $platform;
    }
}
