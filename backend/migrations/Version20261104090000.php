<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261104090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Records when the reader shared an entry (#2007).';
    }

    public function up(Schema $schema): void
    {
        $this->skipIf(
            $schema->getTable('entry_state')->hasColumn('shared_at'),
            'entry_state.shared_at already exists.',
        );

        if ($this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform) {
            $this->addSql('ALTER TABLE entry_state ADD shared_at DATETIME DEFAULT NULL');

            return;
        }

        $this->assertSqlite();
        $this->addSql('ALTER TABLE entry_state ADD COLUMN shared_at DATETIME DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->skipIf(
            !$schema->getTable('entry_state')->hasColumn('shared_at'),
            'entry_state.shared_at does not exist.',
        );

        if ($this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform) {
            $this->addSql('ALTER TABLE entry_state DROP shared_at');

            return;
        }

        $this->assertSqlite();
        $this->addSql('ALTER TABLE entry_state DROP COLUMN shared_at');
    }

    private function assertSqlite(): void
    {
        $platform = $this->connection->getDatabasePlatform();
        $this->abortIf(
            !$platform instanceof SQLitePlatform,
            \sprintf('No DDL defined for platform %s; only MySQL and SQLite are supported.', $platform::class),
        );
    }
}
