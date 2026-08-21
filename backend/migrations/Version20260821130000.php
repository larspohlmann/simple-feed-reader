<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * user_recommendation_settings gains profile_text (#493): the reader's
 * inferred preference profile, distilled by a later pipeline phase from
 * favorites/kept/viewed history. Nullable TEXT, mirroring guidance_prompt
 * beside it -- no row means no profile yet, exactly like no guidance yet.
 *
 * Read-only through the settings save path in this task: only
 * RecommendationSettingsWriter::storeProfile() writes it. The settings form
 * and its PUT request DTO do not carry it at all.
 *
 * PLATFORM-AWARE DDL: tests build their schema from ORM metadata and never
 * execute a migration; CI's migrate-from-empty leg is the only runtime check.
 */
final class Version20260821130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add profile_text to user_recommendation_settings (#493 preference profile).';
    }

    public function up(Schema $schema): void
    {
        $table = $schema->getTable('user_recommendation_settings');

        if ($table->hasColumn('profile_text')) {
            return;
        }

        $this->addSql($this->mysql()
            ? 'ALTER TABLE user_recommendation_settings ADD profile_text LONGTEXT DEFAULT NULL'
            : 'ALTER TABLE user_recommendation_settings ADD COLUMN profile_text CLOB DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $table = $schema->getTable('user_recommendation_settings');

        if (!$table->hasColumn('profile_text')) {
            return;
        }

        $this->addSql($this->mysql()
            ? 'ALTER TABLE user_recommendation_settings DROP profile_text'
            : 'ALTER TABLE user_recommendation_settings DROP COLUMN profile_text');
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
