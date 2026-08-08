<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Update recommendation settings column defaults to match #321 constant changes.
 *
 * PLATFORM-AWARE DDL: DEFAULT_CANDIDATE_POOL_SIZE and DEFAULT_PICKS_LIMIT changed
 * from 1000/100 to 500/50 in #321, but the database schema defaults were not updated.
 * The entity uses these constants for its column options, so the ORM metadata now
 * declares 500/50, but the DDL still has 1000/100. Altering the defaults keeps
 * schema:validate happy and enables from-empty migrations to produce correct schemas.
 *
 * SQLite cannot ALTER COLUMN ... DEFAULT in place; we rebuild the table.
 */
final class Version20260808180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Recommendation settings defaults (#321): candidate_pool_size 1000→500, picks_limit 100→50.';
    }

    public function up(Schema $schema): void
    {
        $this->skipIf(
            false === $this->isColumnDefaultValue('user_recommendation_settings', 'candidate_pool_size', '1000'),
            'candidate_pool_size default is not 1000; already updated or column missing.',
        );

        $platform = $this->connection->getDatabasePlatform();

        if ($platform instanceof AbstractMySQLPlatform) {
            $this->addSql('ALTER TABLE user_recommendation_settings MODIFY candidate_pool_size INT NOT NULL DEFAULT 500');
            $this->addSql('ALTER TABLE user_recommendation_settings MODIFY picks_limit INT NOT NULL DEFAULT 50');

            return;
        }

        if ($platform instanceof SQLitePlatform) {
            // SQLite cannot ALTER COLUMN DEFAULT in place. Rebuild the table.
            $this->addSql('ALTER TABLE user_recommendation_settings RENAME TO user_recommendation_settings_old');
            $this->addSql(
                <<<'SQL'
CREATE TABLE user_recommendation_settings (
    id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
    user_id INTEGER NOT NULL,
    guidance_prompt CLOB DEFAULT NULL,
    favorites_cap INTEGER NOT NULL DEFAULT 40,
    kept_cap INTEGER NOT NULL DEFAULT 40,
    viewed_cap INTEGER NOT NULL DEFAULT 80,
    candidate_pool_size INTEGER NOT NULL DEFAULT 500,
    picks_limit INTEGER NOT NULL DEFAULT 50,
    context_window INTEGER DEFAULT NULL,
    batch_count INTEGER DEFAULT NULL,
    debug_enabled BOOLEAN NOT NULL DEFAULT 0,
    UNIQUE (user_id),
    CONSTRAINT fk_user_recommendation_settings_user FOREIGN KEY (user_id) REFERENCES user (id) ON DELETE CASCADE
)
SQL
            );
            $this->addSql(
                <<<'SQL'
INSERT INTO user_recommendation_settings (
    id, user_id, guidance_prompt, favorites_cap, kept_cap, viewed_cap,
    candidate_pool_size, picks_limit, context_window, batch_count, debug_enabled
)
SELECT
    id, user_id, guidance_prompt, favorites_cap, kept_cap, viewed_cap,
    candidate_pool_size, picks_limit, context_window, batch_count, debug_enabled
FROM user_recommendation_settings_old
SQL
            );
            $this->addSql('DROP TABLE user_recommendation_settings_old');

            return;
        }

        $this->abortIf(true, \sprintf(
            'No DDL defined for platform %s; only MySQL and SQLite are supported.',
            $platform::class,
        ));
    }

    public function down(Schema $schema): void
    {
        $platform = $this->connection->getDatabasePlatform();

        if ($platform instanceof AbstractMySQLPlatform) {
            $this->addSql('ALTER TABLE user_recommendation_settings MODIFY candidate_pool_size INT NOT NULL DEFAULT 1000');
            $this->addSql('ALTER TABLE user_recommendation_settings MODIFY picks_limit INT NOT NULL DEFAULT 100');

            return;
        }

        if ($platform instanceof SQLitePlatform) {
            $this->addSql('ALTER TABLE user_recommendation_settings RENAME TO user_recommendation_settings_new');
            $this->addSql(
                <<<'SQL'
CREATE TABLE user_recommendation_settings (
    id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
    user_id INTEGER NOT NULL,
    guidance_prompt CLOB DEFAULT NULL,
    favorites_cap INTEGER NOT NULL DEFAULT 40,
    kept_cap INTEGER NOT NULL DEFAULT 40,
    viewed_cap INTEGER NOT NULL DEFAULT 80,
    candidate_pool_size INTEGER NOT NULL DEFAULT 1000,
    picks_limit INTEGER NOT NULL DEFAULT 100,
    context_window INTEGER DEFAULT NULL,
    batch_count INTEGER DEFAULT NULL,
    debug_enabled BOOLEAN NOT NULL DEFAULT 0,
    UNIQUE (user_id),
    CONSTRAINT fk_user_recommendation_settings_user FOREIGN KEY (user_id) REFERENCES user (id) ON DELETE CASCADE
)
SQL
            );
            $this->addSql(
                <<<'SQL'
INSERT INTO user_recommendation_settings (
    id, user_id, guidance_prompt, favorites_cap, kept_cap, viewed_cap,
    candidate_pool_size, picks_limit, context_window, batch_count, debug_enabled
)
SELECT
    id, user_id, guidance_prompt, favorites_cap, kept_cap, viewed_cap,
    candidate_pool_size, picks_limit, context_window, batch_count, debug_enabled
FROM user_recommendation_settings_new
SQL
            );
            $this->addSql('DROP TABLE user_recommendation_settings_new');

            return;
        }
    }

    public function isTransactional(): bool
    {
        return false;
    }

    private function isColumnDefaultValue(string $table, string $column, string $expectedDefault): bool
    {
        $platform = $this->connection->getDatabasePlatform();

        if ($platform instanceof AbstractMySQLPlatform) {
            $result = $this->connection->executeQuery(
                'SELECT COLUMN_DEFAULT FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = ? AND COLUMN_NAME = ?',
                [$table, $column],
            )->fetchAssociative();

            return $result && (string) $result['COLUMN_DEFAULT'] === $expectedDefault;
        }

        if ($platform instanceof SQLitePlatform) {
            $result = $this->connection->executeQuery(
                "PRAGMA table_info($table)",
            )->fetchAllAssociative();

            foreach ($result as $row) {
                if ($row['name'] === $column) {
                    return null !== $row['dflt_value'] && (string) $row['dflt_value'] === $expectedDefault;
                }
            }

            return false;
        }

        return false;
    }
}
