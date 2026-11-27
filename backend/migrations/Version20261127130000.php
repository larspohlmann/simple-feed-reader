<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261127130000 extends AbstractMigration
{
    private const string TABLE = 'interest_term_run';

    public function getDescription(): string
    {
        return 'Add interest_term_run and the interest-term run each call-log row can belong to (#2582).';
    }

    public function up(Schema $schema): void
    {
        $this->skipIf($schema->hasTable(self::TABLE), 'interest_term_run already exists.');

        $platform = $this->connection->getDatabasePlatform();
        if ($platform instanceof AbstractMySQLPlatform) {
            $this->upMySql();

            return;
        }
        $this->requireSqlite($platform);
        $this->upSqlite();
    }

    public function down(Schema $schema): void
    {
        // Not $schema: reading it makes the executor diff the SQLite up's inline FK and rebuild the log table.
        $this->skipIf(
            !$this->connection->createSchemaManager()->tablesExist([self::TABLE]),
            'interest_term_run does not exist.',
        );

        if ($this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform) {
            $this->addSql('ALTER TABLE recommendation_run_log DROP FOREIGN KEY FK_2505ECADE5F6ACA4');
            $this->addSql('DROP INDEX idx_recommendation_run_log_interest_term_run ON recommendation_run_log');
            $this->addSql('ALTER TABLE recommendation_run_log DROP interest_term_run_id');
        } else {
            $this->addSql('DROP INDEX idx_recommendation_run_log_interest_term_run');
            $this->addSql('ALTER TABLE recommendation_run_log DROP COLUMN interest_term_run_id');
        }
        $this->addSql('DROP TABLE interest_term_run');
    }

    /** MySQL commits each DDL statement, so a transaction would protect nothing. */
    public function isTransactional(): bool
    {
        return false;
    }

    private function upMySql(): void
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

    private function upSqlite(): void
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

    private function requireSqlite(object $platform): void
    {
        $this->abortIf(
            !$platform instanceof SQLitePlatform,
            \sprintf('No DDL defined for platform %s; only MySQL and SQLite are supported.', $platform::class),
        );
    }
}
