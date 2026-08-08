<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Per-attempt wire byte count on the recommendation run log (#320).
 *
 * A reasoning model streams megabytes before it answers, so an attempt that
 * failed on a cap and one where the provider never spoke both end up with an
 * empty response_text. This column is what tells them apart after the fact.
 *
 * PLATFORM-AWARE DDL: tests build their schema from ORM metadata and never
 * execute a migration; CI's migrate-from-empty leg is the only runtime check.
 * The two dialects differ in the integer type name, and SQLite's comparator
 * treats INT and INTEGER as a schema difference — writing one DDL for both
 * leaves `doctrine:schema:validate` out of sync.
 */
final class Version20260808170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Recommendation debug view (#320): recommendation_run_log.wire_bytes.';
    }

    public function up(Schema $schema): void
    {
        $this->skipIf(
            $schema->getTable('recommendation_run_log')->hasColumn('wire_bytes'),
            'recommendation_run_log.wire_bytes already exists; nothing to do.',
        );

        $platform = $this->connection->getDatabasePlatform();

        if ($platform instanceof AbstractMySQLPlatform) {
            $this->addSql('ALTER TABLE recommendation_run_log ADD wire_bytes INT DEFAULT 0 NOT NULL');

            return;
        }

        if ($platform instanceof SQLitePlatform) {
            $this->addSql('ALTER TABLE recommendation_run_log ADD COLUMN wire_bytes INTEGER DEFAULT 0 NOT NULL');

            return;
        }

        $this->abortIf(true, \sprintf(
            'No DDL defined for platform %s; only MySQL and SQLite are supported.',
            $platform::class,
        ));
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE recommendation_run_log DROP COLUMN wire_bytes');
    }
}
