<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261103090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Records whether the account shares and which share targets it hides (#2006).';
    }

    public function up(Schema $schema): void
    {
        $this->skipIf(
            $schema->getTable('user_preferences')->hasColumn('sharing_enabled'),
            'user_preferences.sharing_enabled already exists.',
        );

        if ($this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform) {
            $this->addSql('ALTER TABLE user_preferences ADD sharing_enabled TINYINT(1) DEFAULT 1 NOT NULL,'
                . ' ADD hidden_share_targets JSON DEFAULT NULL');

            return;
        }

        $this->assertSqlite();
        $this->addSql('ALTER TABLE user_preferences ADD COLUMN sharing_enabled BOOLEAN DEFAULT 1 NOT NULL');
        $this->addSql('ALTER TABLE user_preferences ADD COLUMN hidden_share_targets CLOB DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->skipIf(
            !$schema->getTable('user_preferences')->hasColumn('sharing_enabled'),
            'user_preferences.sharing_enabled does not exist.',
        );

        if ($this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform) {
            $this->addSql('ALTER TABLE user_preferences DROP sharing_enabled, DROP hidden_share_targets');

            return;
        }

        $this->assertSqlite();
        $this->addSql('ALTER TABLE user_preferences DROP COLUMN sharing_enabled');
        $this->addSql('ALTER TABLE user_preferences DROP COLUMN hidden_share_targets');
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
