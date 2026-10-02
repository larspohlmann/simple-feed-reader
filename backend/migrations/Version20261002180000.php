<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261002180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add user_ai_settings.profile_source: the connection that distils the profile for Jev (#1345).';
    }

    public function up(Schema $schema): void
    {
        if ($schema->getTable('user_ai_settings')->hasColumn('profile_source')) {
            return;
        }

        $this->addSql($this->mysql()
            ? 'ALTER TABLE user_ai_settings ADD profile_source TINYINT(1) DEFAULT 0 NOT NULL'
            : 'ALTER TABLE user_ai_settings ADD COLUMN profile_source BOOLEAN DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        if (!$schema->getTable('user_ai_settings')->hasColumn('profile_source')) {
            return;
        }

        $this->addSql($this->mysql()
            ? 'ALTER TABLE user_ai_settings DROP profile_source'
            : 'ALTER TABLE user_ai_settings DROP COLUMN profile_source');
    }

    /** Refuses any platform but the two supported ones: better a refusal than DDL nobody tested. */
    private function mysql(): bool
    {
        $platform = $this->connection->getDatabasePlatform();

        $this->abortIf(
            !$platform instanceof AbstractMySQLPlatform && !$platform instanceof SQLitePlatform,
            \sprintf('No DDL defined for platform %s; only MySQL and SQLite are supported.', $platform::class),
        );

        return $platform instanceof AbstractMySQLPlatform;
    }
}
