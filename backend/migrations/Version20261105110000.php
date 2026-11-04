<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261105110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Records whether the account shows Trending (#2017).';
    }

    public function up(Schema $schema): void
    {
        $this->skipIf(
            $schema->getTable('user_preferences')->hasColumn('trending_enabled'),
            'user_preferences.trending_enabled already exists.',
        );

        if ($this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform) {
            $this->addSql('ALTER TABLE user_preferences ADD trending_enabled TINYINT(1) DEFAULT 1 NOT NULL');

            return;
        }

        $this->assertSqlite();
        $this->addSql('ALTER TABLE user_preferences ADD COLUMN trending_enabled BOOLEAN DEFAULT 1 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->skipIf(
            !$schema->getTable('user_preferences')->hasColumn('trending_enabled'),
            'user_preferences.trending_enabled does not exist.',
        );

        if ($this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform) {
            $this->addSql('ALTER TABLE user_preferences DROP trending_enabled');

            return;
        }

        $this->assertSqlite();
        $this->addSql('ALTER TABLE user_preferences DROP COLUMN trending_enabled');
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
