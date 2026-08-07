<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Recommendation feed (#308): settings, run and item tables; context window
 * on user_ai_settings.
 *
 * PLATFORM-AWARE DDL: tests build their schema from ORM metadata and never
 * execute a migration; CI's migrate-from-empty leg is the only runtime check.
 *
 * Generated with doctrine:migrations:diff (against the dev SQLite database)
 * and then rewritten by hand: the diff output is single-dialect, so the
 * MySQL branch below is a hand-written mirror of it, not a second diff.
 */
final class Version20260807130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Recommendation feed (#308): settings, run and item tables; '
            . 'context window on user_ai_settings.';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->skipIf($schema->hasTable('recommendation_run'), 'recommendation tables already exist; nothing to do.');

        $platform = $this->connection->getDatabasePlatform();

        if ($platform instanceof AbstractMySQLPlatform) {
            $this->upMySql();

            return;
        }

        if ($platform instanceof SQLitePlatform) {
            $this->upSqlite();

            return;
        }

        $this->abortIf(true, \sprintf(
            'No DDL defined for platform %s; only MySQL and SQLite are supported.',
            $platform::class,
        ));
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE recommendation_item');
        $this->addSql('DROP TABLE recommendation_run');
        $this->addSql('DROP TABLE user_recommendation_settings');
        $this->addSql('ALTER TABLE user_ai_settings DROP COLUMN model_context_window');
    }

    private function upMySql(): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE user_recommendation_settings (
                id INT AUTO_INCREMENT NOT NULL,
                guidance_prompt LONGTEXT DEFAULT NULL,
                favorites_cap INT DEFAULT 40 NOT NULL,
                kept_cap INT DEFAULT 40 NOT NULL,
                viewed_cap INT DEFAULT 80 NOT NULL,
                candidate_pool_size INT DEFAULT 1000 NOT NULL,
                picks_limit INT DEFAULT 100 NOT NULL,
                context_window INT DEFAULT NULL,
                debug_enabled TINYINT DEFAULT 0 NOT NULL,
                user_id INT NOT NULL,
                UNIQUE INDEX uniq_recommendation_settings_user (user_id),
                PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
            SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE recommendation_run (
                id INT AUTO_INCREMENT NOT NULL,
                status VARCHAR(16) NOT NULL,
                created_at DATETIME NOT NULL,
                completed_at DATETIME DEFAULT NULL,
                error LONGTEXT DEFAULT NULL,
                candidate_batches JSON DEFAULT NULL,
                batch_winners JSON NOT NULL,
                batches_done INT DEFAULT 0 NOT NULL,
                attempts INT DEFAULT 0 NOT NULL,
                last_invalid_reply LONGTEXT DEFAULT NULL,
                user_id INT NOT NULL,
                INDEX IDX_ACA1664FA76ED395 (user_id),
                INDEX idx_recommendation_run_user_status (user_id, status),
                PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
            SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE recommendation_item (
                id INT AUTO_INCREMENT NOT NULL,
                position INT NOT NULL,
                reason LONGTEXT NOT NULL,
                recommendation_run_id INT NOT NULL,
                entry_id INT NOT NULL,
                INDEX IDX_62E06C6D8FF1503E (recommendation_run_id),
                INDEX IDX_62E06C6DBA364942 (entry_id),
                UNIQUE INDEX uniq_recommendation_item_run_position (recommendation_run_id, position),
                PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
            SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE user_recommendation_settings
            ADD CONSTRAINT FK_83A9855EA76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE
            SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE recommendation_run
            ADD CONSTRAINT FK_ACA1664FA76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE
            SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE recommendation_item
            ADD CONSTRAINT FK_62E06C6D8FF1503E FOREIGN KEY (recommendation_run_id) REFERENCES recommendation_run (id) ON DELETE CASCADE
            SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE recommendation_item
            ADD CONSTRAINT FK_62E06C6DBA364942 FOREIGN KEY (entry_id) REFERENCES entry (id) ON DELETE CASCADE
            SQL);
        $this->addSql('ALTER TABLE user_ai_settings ADD model_context_window INT DEFAULT NULL');
    }

    private function upSqlite(): void
    {
        $this->addSql(<<<'SQL'
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
                CONSTRAINT FK_83A9855EA76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE
            )
            SQL);
        $this->addSql('CREATE UNIQUE INDEX uniq_recommendation_settings_user ON user_recommendation_settings (user_id)');

        $this->addSql(<<<'SQL'
            CREATE TABLE recommendation_run (
                id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
                status VARCHAR(16) NOT NULL,
                created_at DATETIME NOT NULL,
                completed_at DATETIME DEFAULT NULL,
                error CLOB DEFAULT NULL,
                candidate_batches CLOB DEFAULT NULL,
                batch_winners CLOB NOT NULL,
                batches_done INTEGER DEFAULT 0 NOT NULL,
                attempts INTEGER DEFAULT 0 NOT NULL,
                last_invalid_reply CLOB DEFAULT NULL,
                user_id INTEGER NOT NULL,
                CONSTRAINT FK_ACA1664FA76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE
            )
            SQL);
        $this->addSql('CREATE INDEX IDX_ACA1664FA76ED395 ON recommendation_run (user_id)');
        $this->addSql('CREATE INDEX idx_recommendation_run_user_status ON recommendation_run (user_id, status)');

        $this->addSql(<<<'SQL'
            CREATE TABLE recommendation_item (
                id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
                position INTEGER NOT NULL,
                reason CLOB NOT NULL,
                recommendation_run_id INTEGER NOT NULL,
                entry_id INTEGER NOT NULL,
                CONSTRAINT FK_62E06C6D8FF1503E FOREIGN KEY (recommendation_run_id) REFERENCES recommendation_run (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE,
                CONSTRAINT FK_62E06C6DBA364942 FOREIGN KEY (entry_id) REFERENCES entry (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE
            )
            SQL);
        $this->addSql('CREATE INDEX IDX_62E06C6D8FF1503E ON recommendation_item (recommendation_run_id)');
        $this->addSql('CREATE INDEX IDX_62E06C6DBA364942 ON recommendation_item (entry_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_recommendation_item_run_position ON recommendation_item (recommendation_run_id, position)');

        $this->addSql('ALTER TABLE user_ai_settings ADD COLUMN model_context_window INTEGER DEFAULT NULL');
    }
}
