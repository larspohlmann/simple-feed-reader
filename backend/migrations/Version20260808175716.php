<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The expert `batchCount` override (#321): user_recommendation_settings.batch_count.
 *
 * PLATFORM-AWARE DDL: tests build their schema from ORM metadata and never
 * execute a migration; CI's migrate-from-empty leg is the only runtime check.
 * The two dialects differ in the integer type name, and SQLite's comparator
 * treats INT and INTEGER as a schema difference — writing one DDL for both
 * leaves `doctrine:schema:validate` out of sync.
 *
 * `doctrine:migrations:diff` also picked up the default-value change on
 * candidate_pool_size/picks_limit from #321's Task 4 (500/50); that stray was
 * pre-existing and out of this task's scope, so it was left out here rather
 * than folded in. It now has its own migration, Version20260808180000 --
 * look there, not here, for that column-default drift.
 */
final class Version20260808175716 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Expert batchCount override (#321): user_recommendation_settings.batch_count.';
    }

    public function up(Schema $schema): void
    {
        $this->skipIf(
            $schema->getTable('user_recommendation_settings')->hasColumn('batch_count'),
            'user_recommendation_settings.batch_count already exists; nothing to do.',
        );

        $platform = $this->connection->getDatabasePlatform();

        if ($platform instanceof AbstractMySQLPlatform) {
            $this->addSql('ALTER TABLE user_recommendation_settings ADD batch_count INT DEFAULT NULL');

            return;
        }

        if ($platform instanceof SQLitePlatform) {
            $this->addSql('ALTER TABLE user_recommendation_settings ADD COLUMN batch_count INTEGER DEFAULT NULL');

            return;
        }

        $this->abortIf(true, \sprintf(
            'No DDL defined for platform %s; only MySQL and SQLite are supported.',
            $platform::class,
        ));
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE user_recommendation_settings DROP COLUMN batch_count');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
