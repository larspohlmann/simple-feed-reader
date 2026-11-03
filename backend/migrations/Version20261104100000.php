<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261104100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Records whether the account remembers what it shares (#2007).';
    }

    public function up(Schema $schema): void
    {
        $this->skipIf(
            $schema->getTable('user_preferences')->hasColumn('remember_shares'),
            'user_preferences.remember_shares already exists.',
        );

        if ($this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform) {
            $this->addSql('ALTER TABLE user_preferences ADD remember_shares TINYINT(1) DEFAULT 1 NOT NULL');

            return;
        }

        $this->assertSqlite();
        $this->addSql('ALTER TABLE user_preferences ADD COLUMN remember_shares BOOLEAN DEFAULT 1 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->skipIf(
            !$schema->getTable('user_preferences')->hasColumn('remember_shares'),
            'user_preferences.remember_shares does not exist.',
        );

        if ($this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform) {
            $this->addSql('ALTER TABLE user_preferences DROP remember_shares');

            return;
        }

        $this->assertSqlite();
        $this->addSql('ALTER TABLE user_preferences DROP COLUMN remember_shares');
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
