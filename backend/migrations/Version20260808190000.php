<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Per-call timing and the transport error message on the recommendation
 * debug log (#321): when a call went out, when it settled, and -- for a
 * transport-failed call -- the exception message that ended it. A stalled or
 * failing run could not be diagnosed from the log alone before this; it took
 * a live tail of the server's own error output.
 *
 * `recommendation_run_log` is transient by design (every run start wipes
 * it), so this migration deletes its rows before adding the NOT NULL
 * `created_at` column instead of backfilling one. Unlike `updated_at`, the
 * column #309 removed here, `created_at` and `finished_at` are both written
 * (RecordedCall, on every settle) *and* read (the debug list response) --
 * they carry real information, not incidental bookkeeping.
 *
 * PLATFORM-AWARE DDL: tests build their schema from ORM metadata and never
 * execute a migration; CI's migrate-from-empty leg is the only runtime check.
 */
final class Version20260808190000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Recommendation debug log (#321): recommendation_run_log.created_at/finished_at/error_detail.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('DELETE FROM recommendation_run_log');

        $platform = $this->connection->getDatabasePlatform();

        if ($platform instanceof AbstractMySQLPlatform) {
            $this->addSql('ALTER TABLE recommendation_run_log ADD created_at DATETIME NOT NULL');
            $this->addSql('ALTER TABLE recommendation_run_log ADD finished_at DATETIME DEFAULT NULL');
            $this->addSql('ALTER TABLE recommendation_run_log ADD error_detail LONGTEXT DEFAULT NULL');

            return;
        }

        if ($platform instanceof SQLitePlatform) {
            $this->addSql('ALTER TABLE recommendation_run_log ADD COLUMN created_at DATETIME NOT NULL');
            $this->addSql('ALTER TABLE recommendation_run_log ADD COLUMN finished_at DATETIME DEFAULT NULL');
            $this->addSql('ALTER TABLE recommendation_run_log ADD COLUMN error_detail CLOB DEFAULT NULL');

            return;
        }

        $this->abortIf(true, \sprintf(
            'No DDL defined for platform %s; only MySQL and SQLite are supported.',
            $platform::class,
        ));
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE recommendation_run_log DROP COLUMN created_at');
        $this->addSql('ALTER TABLE recommendation_run_log DROP COLUMN finished_at');
        $this->addSql('ALTER TABLE recommendation_run_log DROP COLUMN error_detail');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
