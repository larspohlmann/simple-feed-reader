<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * user_recommendation_settings gains show_reasons (#541): the per-user flag
 * that shows each pick's one-line reason in the For You list, decoupled from
 * debug_enabled beside it, which now governs only the raw score and call logs.
 *
 * show_reasons defaults to 0 so every existing row -- including debug-on users
 * who saw reasons via the old coupling -- reads as off after the deploy; a user
 * opts back in from the settings page. This backfill was accepted deliberately.
 *
 * PLATFORM-AWARE DDL: tests build their schema from ORM metadata and never
 * execute a migration; CI's migrate-from-empty leg is the only runtime check.
 */
final class Version20260822120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add user_recommendation_settings.show_reasons (#541 reason visibility, default off).';
    }

    public function up(Schema $schema): void
    {
        $table = $schema->getTable('user_recommendation_settings');

        if (!$table->hasColumn('show_reasons')) {
            $this->addSql($this->mysql()
                ? 'ALTER TABLE user_recommendation_settings ADD show_reasons TINYINT(1) DEFAULT 0 NOT NULL'
                : 'ALTER TABLE user_recommendation_settings ADD COLUMN show_reasons BOOLEAN DEFAULT 0 NOT NULL');
        }
    }

    public function down(Schema $schema): void
    {
        $table = $schema->getTable('user_recommendation_settings');

        if ($table->hasColumn('show_reasons')) {
            $this->addSql($this->mysql()
                ? 'ALTER TABLE user_recommendation_settings DROP show_reasons'
                : 'ALTER TABLE user_recommendation_settings DROP COLUMN show_reasons');
        }
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
