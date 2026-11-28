<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261128121000 extends AbstractMigration
{
    private const string TABLE = 'interest_term_run';

    public function getDescription(): string
    {
        return 'Drop the AI interest-term runs and their call logs (#2601).';
    }

    public function up(Schema $schema): void
    {
        // Not $schema: reading it makes the executor diff the SQLite inline FK and rebuild the log table.
        $this->skipIf(!$this->termRunTableExists(), 'interest_term_run does not exist.');

        $platform = $this->connection->getDatabasePlatform();
        $this->abortIfUnsupportedPlatform($platform);
        $this->addSql('DELETE FROM recommendation_run_log WHERE interest_term_run_id IS NOT NULL');
        if ($platform instanceof AbstractMySQLPlatform) {
            $this->upMySql();

            return;
        }
        $this->upSqlite();
    }

    public function down(Schema $schema): void
    {
        $this->skipIf($this->termRunTableExists(), 'interest_term_run already exists.');

        $platform = $this->connection->getDatabasePlatform();
        $this->abortIfUnsupportedPlatform($platform);
        if ($platform instanceof AbstractMySQLPlatform) {
            $this->downMySql();

            return;
        }
        $this->downSqlite();
    }

    /** MySQL commits each DDL statement, so a transaction would protect nothing there. */
    public function isTransactional(): bool
    {
        return !$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform;
    }

    private function upMySql(): void
    {
        $this->addSql('ALTER TABLE recommendation_run_log DROP FOREIGN KEY FK_2505ECADE5F6ACA4');
        $this->addSql('DROP INDEX idx_recommendation_run_log_interest_term_run ON recommendation_run_log');
        $this->addSql('ALTER TABLE recommendation_run_log DROP interest_term_run_id');
        $this->addSql('DROP TABLE interest_term_run');
    }

    private function upSqlite(): void
    {
        $this->addSql('CREATE TEMPORARY TABLE __temp__recommendation_run_log AS SELECT id, run_id, profile_run_id,'
            . ' phase, batch_number, attempt, request_body, response_text, verdict, wire_bytes, created_at,'
            . ' finished_at, error_detail, finish_reason, request_id, answering_model, cost_nano_credits,'
            . ' adjusted_setting, adjusted_value, story_ai_run_id FROM recommendation_run_log');
        $this->addSql('DROP TABLE recommendation_run_log');
        $this->addSql('CREATE TABLE recommendation_run_log (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,'
            . ' run_id INTEGER DEFAULT NULL, profile_run_id INTEGER DEFAULT NULL, phase VARCHAR(16) NOT NULL,'
            . ' batch_number INTEGER DEFAULT NULL, attempt INTEGER NOT NULL, request_body CLOB NOT NULL,'
            . ' response_text CLOB NOT NULL, verdict VARCHAR(24) DEFAULT NULL,'
            . ' wire_bytes INTEGER DEFAULT 0 NOT NULL, created_at DATETIME NOT NULL,'
            . ' finished_at DATETIME DEFAULT NULL, error_detail CLOB DEFAULT NULL,'
            . ' finish_reason VARCHAR(32) DEFAULT NULL, request_id VARCHAR(255) DEFAULT NULL,'
            . ' answering_model VARCHAR(255) DEFAULT NULL, cost_nano_credits BIGINT DEFAULT NULL,'
            . ' adjusted_setting VARCHAR(24) DEFAULT NULL, adjusted_value VARCHAR(64) DEFAULT NULL,'
            . ' story_ai_run_id INTEGER DEFAULT NULL,'
            . ' FOREIGN KEY (story_ai_run_id) REFERENCES story_ai_run (id)'
            . ' ON UPDATE NO ACTION ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE,'
            . ' CONSTRAINT FK_recommendation_run_log_run FOREIGN KEY (run_id) REFERENCES recommendation_run (id)'
            . ' ON UPDATE NO ACTION ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE,'
            . ' CONSTRAINT FK_recommendation_run_log_profile_run FOREIGN KEY (profile_run_id)'
            . ' REFERENCES profile_run (id) ON UPDATE NO ACTION ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('INSERT INTO recommendation_run_log (id, run_id, profile_run_id, phase, batch_number, attempt,'
            . ' request_body, response_text, verdict, wire_bytes, created_at, finished_at, error_detail,'
            . ' finish_reason, request_id, answering_model, cost_nano_credits, adjusted_setting, adjusted_value,'
            . ' story_ai_run_id) SELECT id, run_id, profile_run_id, phase, batch_number, attempt, request_body,'
            . ' response_text, verdict, wire_bytes, created_at, finished_at, error_detail, finish_reason,'
            . ' request_id, answering_model, cost_nano_credits, adjusted_setting, adjusted_value, story_ai_run_id'
            . ' FROM __temp__recommendation_run_log');
        $this->addSql('DROP TABLE __temp__recommendation_run_log');
        $this->addSql('CREATE INDEX idx_recommendation_run_log_story_ai_run ON recommendation_run_log (story_ai_run_id)');
        $this->addSql('CREATE INDEX idx_recommendation_run_log_profile_run ON recommendation_run_log (profile_run_id)');
        $this->addSql('CREATE INDEX idx_recommendation_run_log_run ON recommendation_run_log (run_id)');
        $this->addSql('DROP TABLE interest_term_run');
    }

    private function downMySql(): void
    {
        $this->addSql('CREATE TABLE interest_term_run (id INT AUTO_INCREMENT NOT NULL,'
            . ' profile_generated_at DATETIME NOT NULL, status VARCHAR(16) NOT NULL, created_at DATETIME NOT NULL,'
            . ' completed_at DATETIME DEFAULT NULL, error LONGTEXT DEFAULT NULL,'
            . ' streamed_chars INT DEFAULT 0 NOT NULL, attempts INT DEFAULT 0 NOT NULL,'
            . ' transport_failures INT DEFAULT 0 NOT NULL, last_invalid_reply LONGTEXT DEFAULT NULL,'
            . ' provider_host VARCHAR(255) DEFAULT NULL, model VARCHAR(255) DEFAULT NULL,'
            . ' prompt_tokens INT DEFAULT 0 NOT NULL, completion_tokens INT DEFAULT 0 NOT NULL,'
            . ' reasoning_tokens INT DEFAULT 0 NOT NULL, cached_tokens INT DEFAULT 0 NOT NULL,'
            . ' cost_nano_credits BIGINT DEFAULT NULL, user_id INT NOT NULL,'
            . ' INDEX idx_interest_term_run_user (user_id), PRIMARY KEY (id),'
            . ' CONSTRAINT FK_F374CB91A76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id)'
            . ' ON DELETE CASCADE) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE recommendation_run_log ADD interest_term_run_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE recommendation_run_log ADD CONSTRAINT FK_2505ECADE5F6ACA4'
            . ' FOREIGN KEY (interest_term_run_id) REFERENCES interest_term_run (id) ON DELETE CASCADE');
        $this->addSql('CREATE INDEX idx_recommendation_run_log_interest_term_run'
            . ' ON recommendation_run_log (interest_term_run_id)');
    }

    private function downSqlite(): void
    {
        $this->addSql('CREATE TABLE interest_term_run (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,'
            . ' profile_generated_at DATETIME NOT NULL, status VARCHAR(16) NOT NULL, created_at DATETIME NOT NULL,'
            . ' completed_at DATETIME DEFAULT NULL, error CLOB DEFAULT NULL,'
            . ' streamed_chars INTEGER DEFAULT 0 NOT NULL, attempts INTEGER DEFAULT 0 NOT NULL,'
            . ' transport_failures INTEGER DEFAULT 0 NOT NULL, last_invalid_reply CLOB DEFAULT NULL,'
            . ' provider_host VARCHAR(255) DEFAULT NULL, model VARCHAR(255) DEFAULT NULL,'
            . ' prompt_tokens INTEGER DEFAULT 0 NOT NULL, completion_tokens INTEGER DEFAULT 0 NOT NULL,'
            . ' reasoning_tokens INTEGER DEFAULT 0 NOT NULL, cached_tokens INTEGER DEFAULT 0 NOT NULL,'
            . ' cost_nano_credits BIGINT DEFAULT NULL, user_id INTEGER NOT NULL,'
            . ' CONSTRAINT FK_F374CB91A76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id)'
            . ' ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX idx_interest_term_run_user ON interest_term_run (user_id)');
        $this->addSql('ALTER TABLE recommendation_run_log ADD COLUMN interest_term_run_id INTEGER DEFAULT NULL'
            . ' REFERENCES interest_term_run (id) ON DELETE CASCADE');
        $this->addSql('CREATE INDEX idx_recommendation_run_log_interest_term_run'
            . ' ON recommendation_run_log (interest_term_run_id)');
    }

    private function termRunTableExists(): bool
    {
        return $this->connection->createSchemaManager()->tablesExist([self::TABLE]);
    }

    private function abortIfUnsupportedPlatform(object $platform): void
    {
        $this->abortIf(
            !$platform instanceof AbstractMySQLPlatform && !$platform instanceof SQLitePlatform,
            \sprintf('No DDL defined for platform %s; only MySQL and SQLite are supported.', $platform::class),
        );
    }
}
