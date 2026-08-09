<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * AI provider settings become many-per-account (#334).
 *
 * `user_ai_settings` loses its one-row-per-account unique index and gains an
 * optional `name`. `app_user` gains `active_ai_config_id`, the pointer to the
 * one configuration AI features use, with ON DELETE SET NULL.
 *
 * PLATFORM-AWARE DDL:
 *   MySQL:  ALTER … DROP INDEX/ADD INDEX in one statement; ADD CONSTRAINT for the FK.
 *   SQLite: cannot alter a constraint in place, but it can add a column that
 *           carries a REFERENCES clause when the default is NULL, and it can
 *           drop and create named indexes directly.
 *
 * The active-pointer backfill is identical on both engines: the correlated
 * subquery reads only `user_ai_settings`.
 */
final class Version20260809130249 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'AI settings become many-per-account with a name and one active pointer (#334)';
    }

    public function up(Schema $schema): void
    {
        $platform = $this->connection->getDatabasePlatform();

        if ($platform instanceof AbstractMySQLPlatform) {
            $this->addSql('ALTER TABLE user_ai_settings DROP INDEX uniq_ai_settings_user, ADD INDEX IDX_53B8EF30A76ED395 (user_id)');
            $this->addSql('ALTER TABLE user_ai_settings ADD name VARCHAR(120) DEFAULT NULL');
            $this->addSql('ALTER TABLE app_user ADD active_ai_config_id INT DEFAULT NULL');
            $this->addSql('ALTER TABLE app_user ADD CONSTRAINT FK_88BDF3E9882CE6B0 FOREIGN KEY (active_ai_config_id) REFERENCES user_ai_settings (id) ON DELETE SET NULL');
            $this->addSql('CREATE INDEX IDX_88BDF3E9882CE6B0 ON app_user (active_ai_config_id)');
            $this->backfillActivePointer();

            return;
        }

        if ($platform instanceof SQLitePlatform) {
            $this->addSql('DROP INDEX uniq_ai_settings_user');
            $this->addSql('CREATE INDEX IDX_53B8EF30A76ED395 ON user_ai_settings (user_id)');
            $this->addSql('ALTER TABLE user_ai_settings ADD COLUMN name VARCHAR(120) DEFAULT NULL');
            $this->addSql('ALTER TABLE app_user ADD COLUMN active_ai_config_id INTEGER DEFAULT NULL REFERENCES user_ai_settings (id) ON DELETE SET NULL');
            $this->addSql('CREATE INDEX IDX_88BDF3E9882CE6B0 ON app_user (active_ai_config_id)');
            $this->backfillActivePointer();

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
            $this->addSql('ALTER TABLE app_user DROP FOREIGN KEY FK_88BDF3E9882CE6B0');
            $this->addSql('DROP INDEX IDX_88BDF3E9882CE6B0 ON app_user');
            $this->addSql('ALTER TABLE app_user DROP active_ai_config_id');
            $this->addSql('ALTER TABLE user_ai_settings DROP INDEX IDX_53B8EF30A76ED395, ADD UNIQUE INDEX uniq_ai_settings_user (user_id)');
            $this->addSql('ALTER TABLE user_ai_settings DROP name');

            return;
        }

        if ($platform instanceof SQLitePlatform) {
            $this->addSql('DROP INDEX IDX_88BDF3E9882CE6B0');
            $this->addSql('ALTER TABLE app_user DROP COLUMN active_ai_config_id');
            $this->addSql('DROP INDEX IDX_53B8EF30A76ED395');
            $this->addSql('ALTER TABLE user_ai_settings DROP COLUMN name');
            $this->addSql('CREATE UNIQUE INDEX uniq_ai_settings_user ON user_ai_settings (user_id)');

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

    /**
     * Before this migration an account held at most one provider row. Point the
     * account at that row when it carries a model, so an account that was ready
     * before the change is ready after it. A row without a model was never
     * usable, so it leaves the pointer null.
     */
    private function backfillActivePointer(): void
    {
        $this->addSql(<<<'SQL'
            UPDATE app_user
            SET active_ai_config_id = (
                SELECT s.id FROM user_ai_settings s
                WHERE s.user_id = app_user.id AND s.model IS NOT NULL
                ORDER BY s.id ASC LIMIT 1
            )
            WHERE active_ai_config_id IS NULL
            SQL);
    }
}
