<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260826120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Rename entry_state read columns to hidden (#483).';
    }

    public function up(Schema $schema): void
    {
        $this->assertSupportedPlatform();

        $this->addSql('ALTER TABLE entry_state RENAME COLUMN is_read TO is_hidden');
        $this->addSql('ALTER TABLE entry_state RENAME COLUMN read_at TO hidden_at');
    }

    public function down(Schema $schema): void
    {
        $this->assertSupportedPlatform();

        $this->addSql('ALTER TABLE entry_state RENAME COLUMN hidden_at TO read_at');
        $this->addSql('ALTER TABLE entry_state RENAME COLUMN is_hidden TO is_read');
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
