<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * user_ai_settings gains slow_model (#433): whether this endpoint answers
 * slowly enough to need the long timeout profile.
 *
 * Defaults to 0, so every existing connection keeps the bounds it has been
 * running under. A hosted provider that goes quiet is dead and should fail
 * fast; only a connection the account marks earns the hour-long wall clock.
 *
 * PLATFORM-AWARE DDL for the reason Version20260814140000 records: DDL diffed
 * on one platform does not parse on the other, and the suite cannot catch it
 * because tests build their schema from ORM metadata, not this chain.
 */
final class Version20260816160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add user_ai_settings.slow_model (#433)';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $settings = $schema->getTable('user_ai_settings');

        if ($settings->hasColumn('slow_model')) {
            return;
        }

        $this->addSql($this->mysql()
            ? 'ALTER TABLE user_ai_settings ADD slow_model TINYINT(1) DEFAULT 0 NOT NULL'
            : 'ALTER TABLE user_ai_settings ADD COLUMN slow_model BOOLEAN DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $settings = $schema->getTable('user_ai_settings');

        if (!$settings->hasColumn('slow_model')) {
            return;
        }

        $this->addSql($this->mysql()
            ? 'ALTER TABLE user_ai_settings DROP slow_model'
            : 'ALTER TABLE user_ai_settings DROP COLUMN slow_model');
    }

    /**
     * Answers which of the two supported platforms this is running on, and
     * refuses any third: better a refusal than DDL invented for a platform
     * nobody tested.
     */
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
