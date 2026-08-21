<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * recommendation_run gains profile_text and distilled (#493): the frozen
 * per-run profile a distillation phase produces, isolated from the settings
 * display copy in user_recommendation_settings.profile_text (Version20260821130000)
 * so a degraded distillation this run never reads last run's profile.
 *
 * profile_text is nullable TEXT, mirroring error and last_invalid_reply
 * beside it -- no row means no profile yet. distilled defaults to 0 so every
 * run already in flight across the deploy reads as "not yet distilled".
 *
 * PLATFORM-AWARE DDL: tests build their schema from ORM metadata and never
 * execute a migration; CI's migrate-from-empty leg is the only runtime check.
 */
final class Version20260821140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add recommendation_run.profile_text and .distilled (#493 per-run profile).';
    }

    public function up(Schema $schema): void
    {
        $table = $schema->getTable('recommendation_run');

        if (!$table->hasColumn('profile_text')) {
            $this->addSql($this->mysql()
                ? 'ALTER TABLE recommendation_run ADD profile_text LONGTEXT DEFAULT NULL'
                : 'ALTER TABLE recommendation_run ADD COLUMN profile_text CLOB DEFAULT NULL');
        }

        if (!$table->hasColumn('distilled')) {
            $this->addSql($this->mysql()
                ? 'ALTER TABLE recommendation_run ADD distilled TINYINT(1) DEFAULT 0 NOT NULL'
                : 'ALTER TABLE recommendation_run ADD COLUMN distilled BOOLEAN DEFAULT 0 NOT NULL');
        }
    }

    public function down(Schema $schema): void
    {
        $table = $schema->getTable('recommendation_run');

        if ($table->hasColumn('distilled')) {
            $this->addSql($this->mysql()
                ? 'ALTER TABLE recommendation_run DROP distilled'
                : 'ALTER TABLE recommendation_run DROP COLUMN distilled');
        }

        if ($table->hasColumn('profile_text')) {
            $this->addSql($this->mysql()
                ? 'ALTER TABLE recommendation_run DROP profile_text'
                : 'ALTER TABLE recommendation_run DROP COLUMN profile_text');
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
