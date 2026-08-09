<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The model's per-pick score, persisted alongside the reason (#321).
 *
 * PLATFORM-AWARE DDL: tests build their schema from ORM metadata and never
 * execute a migration; CI's migrate-from-empty leg is the only runtime check.
 * The two dialects differ in the integer type name, and SQLite's comparator
 * treats INT and INTEGER as a schema difference — writing one DDL for both
 * leaves `doctrine:schema:validate` out of sync.
 */
final class Version20260808171000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Recommendation score (#321): recommendation_item.score.';
    }

    public function up(Schema $schema): void
    {
        $this->skipIf(
            $schema->getTable('recommendation_item')->hasColumn('score'),
            'recommendation_item.score already exists; nothing to do.',
        );

        $platform = $this->connection->getDatabasePlatform();

        if ($platform instanceof AbstractMySQLPlatform) {
            $this->addSql('ALTER TABLE recommendation_item ADD score INT DEFAULT NULL');

            return;
        }

        if ($platform instanceof SQLitePlatform) {
            $this->addSql('ALTER TABLE recommendation_item ADD COLUMN score INTEGER DEFAULT NULL');

            return;
        }

        $this->abortIf(true, \sprintf(
            'No DDL defined for platform %s; only MySQL and SQLite are supported.',
            $platform::class,
        ));
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE recommendation_item DROP COLUMN score');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
