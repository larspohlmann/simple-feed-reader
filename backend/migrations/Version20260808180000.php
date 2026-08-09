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
 * from 1000/100 to 500/50 in #321. The entity uses these constants for its column
 * options via ORM attributes, so the metadata now declares 500/50, but the DDL still
 * had 1000/100. This mismatch makes doctrine:schema:validate fail.
 *
 * MySQL: direct ALTER COLUMN DEFAULT.
 * SQLite: cannot ALTER COLUMN DEFAULT in place; must rebuild the table with the
 *         new defaults while preserving all columns, indexes, and constraints.
 */
final class Version20260808180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Recommendation settings defaults (#321): candidate_pool_size 1000→500, picks_limit 100→50.';
    }

    public function up(Schema $schema): void
    {
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
    guidance_prompt CLOB DEFAULT NULL,
    favorites_cap INTEGER DEFAULT 40 NOT NULL,
    kept_cap INTEGER DEFAULT 40 NOT NULL,
    viewed_cap INTEGER DEFAULT 80 NOT NULL,
    candidate_pool_size INTEGER DEFAULT 500 NOT NULL,
    picks_limit INTEGER DEFAULT 50 NOT NULL,
    context_window INTEGER DEFAULT NULL,
    debug_enabled BOOLEAN DEFAULT 0 NOT NULL,
    user_id INTEGER NOT NULL,
    batch_count INTEGER DEFAULT NULL,
    CONSTRAINT FK_83A9855EA76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id) ON UPDATE NO ACTION ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE
)
SQL
            );
            $this->addSql(
                <<<'SQL'
INSERT INTO user_recommendation_settings (
    id, guidance_prompt, favorites_cap, kept_cap, viewed_cap,
    candidate_pool_size, picks_limit, context_window, debug_enabled,
    user_id, batch_count
)
SELECT
    id, guidance_prompt, favorites_cap, kept_cap, viewed_cap,
    candidate_pool_size, picks_limit, context_window, debug_enabled,
    user_id, batch_count
FROM user_recommendation_settings_old
SQL
            );
            $this->addSql('DROP TABLE user_recommendation_settings_old');
            $this->addSql('CREATE UNIQUE INDEX uniq_recommendation_settings_user ON user_recommendation_settings (user_id)');

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
    guidance_prompt CLOB DEFAULT NULL,
    favorites_cap INTEGER DEFAULT 40 NOT NULL,
    kept_cap INTEGER DEFAULT 40 NOT NULL,
    viewed_cap INTEGER DEFAULT 80 NOT NULL,
    candidate_pool_size INTEGER DEFAULT 1000 NOT NULL,
    picks_limit INTEGER DEFAULT 100 NOT NULL,
    context_window INTEGER DEFAULT NULL,
    debug_enabled BOOLEAN DEFAULT 0 NOT NULL,
    user_id INTEGER NOT NULL,
    batch_count INTEGER DEFAULT NULL,
    CONSTRAINT FK_83A9855EA76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id) ON UPDATE NO ACTION ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE
)
SQL
            );
            $this->addSql(
                <<<'SQL'
INSERT INTO user_recommendation_settings (
    id, guidance_prompt, favorites_cap, kept_cap, viewed_cap,
    candidate_pool_size, picks_limit, context_window, debug_enabled,
    user_id, batch_count
)
SELECT
    id, guidance_prompt, favorites_cap, kept_cap, viewed_cap,
    candidate_pool_size, picks_limit, context_window, debug_enabled,
    user_id, batch_count
FROM user_recommendation_settings_new
SQL
            );
            $this->addSql('DROP TABLE user_recommendation_settings_new');
            $this->addSql('CREATE UNIQUE INDEX uniq_recommendation_settings_user ON user_recommendation_settings (user_id)');

            return;
        }

        $this->abortIf(true, \sprintf(
            'No DDL defined for platform %s; only MySQL and SQLite are supported.',
            $platform::class,
        ));
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
