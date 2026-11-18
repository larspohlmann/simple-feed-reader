<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261118150000 extends AbstractMigration
{
    private const string TABLE = 'story_ai_run';

    public function getDescription(): string
    {
        return 'Add story_ai_run and the story AI run each call-log row can belong to (#2329).';
    }

    public function up(Schema $schema): void
    {
        $this->skipIf($schema->hasTable(self::TABLE), 'story_ai_run already exists.');

        $platform = $this->connection->getDatabasePlatform();
        if ($platform instanceof AbstractMySQLPlatform) {
            $this->addSql('CREATE TABLE story_ai_run (id INT AUTO_INCREMENT NOT NULL, task VARCHAR(16) NOT NULL,'
                . ' created_at DATETIME NOT NULL, streamed_chars INT DEFAULT 0 NOT NULL,'
                . ' provider_host VARCHAR(255) DEFAULT NULL, model VARCHAR(255) DEFAULT NULL,'
                . ' prompt_tokens INT DEFAULT 0 NOT NULL, completion_tokens INT DEFAULT 0 NOT NULL,'
                . ' reasoning_tokens INT DEFAULT 0 NOT NULL, cached_tokens INT DEFAULT 0 NOT NULL,'
                . ' cost_nano_credits BIGINT DEFAULT NULL, user_id INT NOT NULL,'
                . ' INDEX idx_story_ai_run_user (user_id), PRIMARY KEY (id),'
                . ' CONSTRAINT FK_9512B8B4A76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id)'
                . ' ON DELETE CASCADE) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
            $this->addSql('ALTER TABLE recommendation_run_log ADD story_ai_run_id INT DEFAULT NULL');
            $this->addSql('ALTER TABLE recommendation_run_log ADD CONSTRAINT FK_2505ECAD7BB3C691'
                . ' FOREIGN KEY (story_ai_run_id) REFERENCES story_ai_run (id) ON DELETE CASCADE');
            $this->addSql('CREATE INDEX idx_recommendation_run_log_story_ai_run'
                . ' ON recommendation_run_log (story_ai_run_id)');

            return;
        }
        $this->requireSqlite($platform);
        $this->addSql('CREATE TABLE story_ai_run (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,'
            . ' task VARCHAR(16) NOT NULL, created_at DATETIME NOT NULL, streamed_chars INTEGER DEFAULT 0 NOT NULL,'
            . ' provider_host VARCHAR(255) DEFAULT NULL, model VARCHAR(255) DEFAULT NULL,'
            . ' prompt_tokens INTEGER DEFAULT 0 NOT NULL, completion_tokens INTEGER DEFAULT 0 NOT NULL,'
            . ' reasoning_tokens INTEGER DEFAULT 0 NOT NULL, cached_tokens INTEGER DEFAULT 0 NOT NULL,'
            . ' cost_nano_credits BIGINT DEFAULT NULL, user_id INTEGER NOT NULL,'
            . ' CONSTRAINT FK_9512B8B4A76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id)'
            . ' ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX idx_story_ai_run_user ON story_ai_run (user_id)');
        $this->addSql('ALTER TABLE recommendation_run_log ADD COLUMN story_ai_run_id INTEGER DEFAULT NULL'
            . ' REFERENCES story_ai_run (id) ON DELETE CASCADE');
        $this->addSql('CREATE INDEX idx_recommendation_run_log_story_ai_run'
            . ' ON recommendation_run_log (story_ai_run_id)');
    }

    public function down(Schema $schema): void
    {
        $this->skipIf(!$schema->hasTable(self::TABLE), 'story_ai_run does not exist.');

        if ($this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform) {
            $this->addSql('ALTER TABLE recommendation_run_log DROP FOREIGN KEY FK_2505ECAD7BB3C691');
            $this->addSql('DROP INDEX idx_recommendation_run_log_story_ai_run ON recommendation_run_log');
            $this->addSql('ALTER TABLE recommendation_run_log DROP story_ai_run_id');
        } else {
            $this->addSql('DROP INDEX idx_recommendation_run_log_story_ai_run');
            $this->addSql('ALTER TABLE recommendation_run_log DROP COLUMN story_ai_run_id');
        }
        $this->addSql('DROP TABLE story_ai_run');
    }

    /** MySQL commits each DDL statement, so a transaction would protect nothing. */
    public function isTransactional(): bool
    {
        return false;
    }

    private function requireSqlite(object $platform): void
    {
        $this->abortIf(
            !$platform instanceof SQLitePlatform,
            \sprintf('No DDL defined for platform %s; only MySQL and SQLite are supported.', $platform::class),
        );
    }
}
