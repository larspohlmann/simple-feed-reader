<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The provider's finish reason on the recommendation debug log (#327):
 * `length` when `max_tokens` truncated the answer, `stop` on a natural end.
 * Without it a reasoning model that spent its whole budget thinking looked
 * identical to a mute provider -- an empty "answered without a completion"
 * row -- and the log could not tell the two apart.
 *
 * The column is nullable, so unlike #321's created_at this needs no DELETE:
 * SQLite accepts a nullable ADD COLUMN against a populated table.
 *
 * PLATFORM-AWARE DDL: tests build their schema from ORM metadata and never
 * execute a migration; CI's migrate-from-empty leg is the only runtime check.
 */
final class Version20260809072351 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Recommendation debug log (#327): recommendation_run_log.finish_reason.';
    }

    public function up(Schema $schema): void
    {
        $platform = $this->connection->getDatabasePlatform();

        if ($platform instanceof AbstractMySQLPlatform) {
            $this->addSql('ALTER TABLE recommendation_run_log ADD finish_reason VARCHAR(32) DEFAULT NULL');

            return;
        }

        if ($platform instanceof SQLitePlatform) {
            $this->addSql('ALTER TABLE recommendation_run_log ADD COLUMN finish_reason VARCHAR(32) DEFAULT NULL');

            return;
        }

        $this->abortIf(true, \sprintf(
            'No DDL defined for platform %s; only MySQL and SQLite are supported.',
            $platform::class,
        ));
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE recommendation_run_log DROP COLUMN finish_reason');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
