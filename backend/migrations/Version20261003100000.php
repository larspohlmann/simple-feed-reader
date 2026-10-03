<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** Accounts with a recommendation schedule start with a daily profile; everyone else generates it by hand. */
final class Version20261003100000 extends AbstractMigration
{
    private const string PROFILE_RUN_COLUMNS = <<<'SQL'
        status VARCHAR(16) NOT NULL,
        run_trigger VARCHAR(16) NOT NULL,
        created_at DATETIME NOT NULL,
        completed_at DATETIME DEFAULT NULL,
        fingerprint VARCHAR(64) DEFAULT NULL,
        outcome VARCHAR(16) DEFAULT NULL,
        provider_host VARCHAR(255) DEFAULT NULL,
        model VARCHAR(255) DEFAULT NULL,
        cost_nano_credits BIGINT DEFAULT NULL,
        SQL;

    private const string SQLITE_RUN_LOG_CALL_COLUMNS = ' phase VARCHAR(16) NOT NULL, batch_number INTEGER DEFAULT NULL,'
        . ' attempt INTEGER NOT NULL, request_body CLOB NOT NULL, response_text CLOB NOT NULL,'
        . ' verdict VARCHAR(24) DEFAULT NULL, wire_bytes INTEGER DEFAULT 0 NOT NULL, created_at DATETIME NOT NULL,'
        . ' finished_at DATETIME DEFAULT NULL, error_detail CLOB DEFAULT NULL, finish_reason VARCHAR(32) DEFAULT NULL,'
        . ' request_id VARCHAR(255) DEFAULT NULL, answering_model VARCHAR(255) DEFAULT NULL,'
        . ' cost_nano_credits BIGINT DEFAULT NULL,';

    private const string SQLITE_RUN_LOG_RUN_CONSTRAINT = ' CONSTRAINT FK_recommendation_run_log_run FOREIGN KEY (run_id)'
        . ' REFERENCES recommendation_run (id) ON DELETE CASCADE';

    private const string RUN_LOG_COPIED_COLUMNS = 'id, run_id, phase, batch_number, attempt, request_body, '
        . 'response_text, verdict, wire_bytes, created_at, finished_at, error_detail, finish_reason, request_id, '
        . 'answering_model, cost_nano_credits';

    public function getDescription(): string
    {
        return 'Add profile_run, the stored profile and the profile settings, and profile-run log rows (#1351).';
    }

    public function up(Schema $schema): void
    {
        $this->skipIf($schema->hasTable('profile_run'), 'profile_run already exists.');

        if ($this->mysql()) {
            $this->upMySql();
        } else {
            $this->upSqlite();
        }

        $this->addSql('UPDATE user_recommendation_settings SET profile_interval_hours = 24 WHERE auto_generate_interval_hours IS NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->skipIf(!$schema->hasTable('profile_run'), 'profile_run does not exist.');

        $this->addSql('DELETE FROM recommendation_run_log WHERE run_id IS NULL');

        if ($this->mysql()) {
            $this->downMySql();

            return;
        }

        $this->downSqlite();
    }

    public function isTransactional(): bool
    {
        return false;
    }

    private function upMySql(): void
    {
        $this->addSql('CREATE TABLE profile_run (id INT AUTO_INCREMENT NOT NULL, user_id INT NOT NULL, '
            . self::PROFILE_RUN_COLUMNS
            . ' error LONGTEXT DEFAULT NULL, streamed_chars INT DEFAULT 0 NOT NULL, attempts INT DEFAULT 0 NOT NULL,'
            . ' transport_failures INT DEFAULT 0 NOT NULL, last_invalid_reply LONGTEXT DEFAULT NULL,'
            . ' prompt_tokens INT DEFAULT 0 NOT NULL, completion_tokens INT DEFAULT 0 NOT NULL,'
            . ' reasoning_tokens INT DEFAULT 0 NOT NULL, cached_tokens INT DEFAULT 0 NOT NULL,'
            . ' INDEX IDX_FED40DFFA76ED395 (user_id), INDEX idx_profile_run_user_status (user_id, status),'
            . ' PRIMARY KEY (id), CONSTRAINT FK_FED40DFFA76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id)'
            . ' ON DELETE CASCADE) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE user_recommendation_settings ADD profile_interval_hours INT DEFAULT NULL, ADD profile_generated_at DATETIME DEFAULT NULL, ADD profile_provider_host VARCHAR(255) DEFAULT NULL, ADD profile_model VARCHAR(255) DEFAULT NULL, ADD profile_connection_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE user_recommendation_settings ADD CONSTRAINT FK_83A9855E23D39AC1 FOREIGN KEY (profile_connection_id) REFERENCES user_ai_settings (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_83A9855E23D39AC1 ON user_recommendation_settings (profile_connection_id)');
        $this->addSql('ALTER TABLE recommendation_run_log MODIFY run_id INT DEFAULT NULL, ADD profile_run_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE recommendation_run_log ADD CONSTRAINT FK_recommendation_run_log_profile_run FOREIGN KEY (profile_run_id) REFERENCES profile_run (id) ON DELETE CASCADE');
        $this->addSql('CREATE INDEX idx_recommendation_run_log_profile_run ON recommendation_run_log (profile_run_id)');
    }

    private function upSqlite(): void
    {
        $this->addSql('CREATE TABLE profile_run (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, user_id INTEGER NOT NULL, '
            . self::PROFILE_RUN_COLUMNS
            . ' error CLOB DEFAULT NULL, streamed_chars INTEGER DEFAULT 0 NOT NULL, attempts INTEGER DEFAULT 0 NOT NULL,'
            . ' transport_failures INTEGER DEFAULT 0 NOT NULL, last_invalid_reply CLOB DEFAULT NULL,'
            . ' prompt_tokens INTEGER DEFAULT 0 NOT NULL, completion_tokens INTEGER DEFAULT 0 NOT NULL,'
            . ' reasoning_tokens INTEGER DEFAULT 0 NOT NULL, cached_tokens INTEGER DEFAULT 0 NOT NULL,'
            . ' CONSTRAINT FK_FED40DFFA76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE'
            . ' NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX IDX_FED40DFFA76ED395 ON profile_run (user_id)');
        $this->addSql('CREATE INDEX idx_profile_run_user_status ON profile_run (user_id, status)');
        $this->addSql('ALTER TABLE user_recommendation_settings ADD COLUMN profile_interval_hours INTEGER DEFAULT NULL');
        $this->addSql('ALTER TABLE user_recommendation_settings ADD COLUMN profile_generated_at DATETIME DEFAULT NULL');
        $this->addSql('ALTER TABLE user_recommendation_settings ADD COLUMN profile_provider_host VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE user_recommendation_settings ADD COLUMN profile_model VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE user_recommendation_settings ADD COLUMN profile_connection_id INTEGER DEFAULT NULL REFERENCES user_ai_settings (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_83A9855E23D39AC1 ON user_recommendation_settings (profile_connection_id)');
        $this->rebuildSqliteRunLogWithProfileRuns();
    }

    private function downMySql(): void
    {
        $this->addSql('ALTER TABLE recommendation_run_log DROP FOREIGN KEY FK_recommendation_run_log_profile_run');
        $this->addSql('DROP INDEX idx_recommendation_run_log_profile_run ON recommendation_run_log');
        $this->addSql('ALTER TABLE recommendation_run_log DROP profile_run_id, MODIFY run_id INT NOT NULL');
        $this->addSql('ALTER TABLE user_recommendation_settings DROP FOREIGN KEY FK_83A9855E23D39AC1');
        $this->addSql('DROP INDEX IDX_83A9855E23D39AC1 ON user_recommendation_settings');
        $this->addSql('ALTER TABLE user_recommendation_settings DROP profile_interval_hours, DROP profile_generated_at, DROP profile_provider_host, DROP profile_model, DROP profile_connection_id');
        $this->addSql('DROP TABLE profile_run');
    }

    private function downSqlite(): void
    {
        $this->rebuildSqliteRunLogWithoutProfileRuns();
        $this->addSql('DROP INDEX IDX_83A9855E23D39AC1');
        foreach (['profile_connection_id', 'profile_model', 'profile_provider_host', 'profile_generated_at', 'profile_interval_hours'] as $column) {
            $this->addSql(\sprintf('ALTER TABLE user_recommendation_settings DROP COLUMN %s', $column));
        }
        $this->addSql('DROP TABLE profile_run');
    }

    /** SQLite cannot relax NOT NULL in place, so the table is rebuilt around the copied rows. */
    private function rebuildSqliteRunLogWithProfileRuns(): void
    {
        $this->renameSqliteRunLogAside();
        $this->addSql('CREATE TABLE recommendation_run_log (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,'
            . ' run_id INTEGER DEFAULT NULL, profile_run_id INTEGER DEFAULT NULL,'
            . self::SQLITE_RUN_LOG_CALL_COLUMNS
            . self::SQLITE_RUN_LOG_RUN_CONSTRAINT
            . ', CONSTRAINT FK_recommendation_run_log_profile_run FOREIGN KEY (profile_run_id)'
            . ' REFERENCES profile_run (id) ON DELETE CASCADE)');
        $this->copySqliteRunLogBack();
        $this->addSql('CREATE INDEX idx_recommendation_run_log_profile_run ON recommendation_run_log (profile_run_id)');
    }

    private function rebuildSqliteRunLogWithoutProfileRuns(): void
    {
        $this->renameSqliteRunLogAside();
        $this->addSql('CREATE TABLE recommendation_run_log (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,'
            . ' run_id INTEGER NOT NULL,'
            . self::SQLITE_RUN_LOG_CALL_COLUMNS
            . self::SQLITE_RUN_LOG_RUN_CONSTRAINT
            . ')');
        $this->copySqliteRunLogBack();
    }

    /** Index names are global in SQLite, so the old table's indexes go before the new table takes their names. */
    private function renameSqliteRunLogAside(): void
    {
        $this->addSql('DROP INDEX IF EXISTS idx_recommendation_run_log_profile_run');
        $this->addSql('DROP INDEX idx_recommendation_run_log_run');
        $this->addSql('ALTER TABLE recommendation_run_log RENAME TO recommendation_run_log_previous');
    }

    private function copySqliteRunLogBack(): void
    {
        $this->addSql(\sprintf(
            'INSERT INTO recommendation_run_log (%1$s) SELECT %1$s FROM recommendation_run_log_previous',
            self::RUN_LOG_COPIED_COLUMNS,
        ));
        $this->addSql('DROP TABLE recommendation_run_log_previous');
        $this->addSql('CREATE INDEX idx_recommendation_run_log_run ON recommendation_run_log (run_id)');
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
