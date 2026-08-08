<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Recommendation debug view (#309): the per-call run log and the
 * debug-independent liveness counter on the run row.
 *
 * PLATFORM-AWARE DDL: tests build their schema from ORM metadata and never
 * execute a migration; CI's migrate-from-empty leg is the only runtime check.
 */
final class Version20260808120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Recommendation debug view (#309): recommendation_run_log table and recommendation_run.streamed_chars.';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->skipIf($schema->hasTable('recommendation_run_log'), 'recommendation_run_log already exists; nothing to do.');

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
        $this->addSql('DROP TABLE recommendation_run_log');
        $this->addSql('ALTER TABLE recommendation_run DROP COLUMN streamed_chars');
    }

    private function upMySql(): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE recommendation_run_log (
                id INT AUTO_INCREMENT NOT NULL,
                run_id INT NOT NULL,
                phase VARCHAR(16) NOT NULL,
                batch_number INT DEFAULT NULL,
                attempt INT NOT NULL,
                request_body LONGTEXT NOT NULL,
                response_text LONGTEXT NOT NULL,
                verdict VARCHAR(24) DEFAULT NULL,
                INDEX idx_recommendation_run_log_run (run_id),
                PRIMARY KEY (id),
                CONSTRAINT FK_recommendation_run_log_run FOREIGN KEY (run_id)
                    REFERENCES recommendation_run (id) ON DELETE CASCADE
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
            SQL);
        $this->addSql('ALTER TABLE recommendation_run ADD streamed_chars INT DEFAULT 0 NOT NULL');
    }

    private function upSqlite(): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE recommendation_run_log (
                id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
                run_id INTEGER NOT NULL,
                phase VARCHAR(16) NOT NULL,
                batch_number INTEGER DEFAULT NULL,
                attempt INTEGER NOT NULL,
                request_body CLOB NOT NULL,
                response_text CLOB NOT NULL,
                verdict VARCHAR(24) DEFAULT NULL,
                CONSTRAINT FK_recommendation_run_log_run FOREIGN KEY (run_id)
                    REFERENCES recommendation_run (id) ON DELETE CASCADE
            )
            SQL);
        $this->addSql('CREATE INDEX idx_recommendation_run_log_run ON recommendation_run_log (run_id)');
        $this->addSql('ALTER TABLE recommendation_run ADD COLUMN streamed_chars INTEGER DEFAULT 0 NOT NULL');
    }
}
