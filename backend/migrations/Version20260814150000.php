<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * user_recommendation_settings.lookback_days (#386): how many days back a
 * run's candidate pool reaches. Existing rows take the same default as a
 * missing row, so the setting means one thing everywhere.
 *
 * PLATFORM-AWARE DDL for the reason Version20260814140000 records: DDL
 * diffed on one platform does not parse on the other, and the suite cannot
 * catch it because tests build their schema from ORM metadata, not this
 * chain.
 *
 * The default is the literal 2, not
 * EffectiveRecommendationSettings::DEFAULT_LOOKBACK_DAYS: a migration
 * records what was applied at a point in time, and a constant a migration
 * imports can move after the migration has already run, silently changing
 * what an already-applied migration claims and diverging fresh installs
 * from migrated ones.
 */
final class Version20260814150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add user_recommendation_settings.lookback_days, default 2 (#386)';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $platform = $this->connection->getDatabasePlatform();

        $mysql = $platform instanceof AbstractMySQLPlatform;
        $sqlite = $platform instanceof SQLitePlatform;

        // Better a refusal than DDL invented for a platform nobody tested.
        $this->abortIf(!$mysql && !$sqlite, \sprintf(
            'No DDL defined for platform %s; only MySQL and SQLite are supported.',
            $platform::class,
        ));

        $settings = $schema->getTable('user_recommendation_settings');

        if ($settings->hasColumn('lookback_days')) {
            return;
        }

        $this->addSql($mysql
            ? 'ALTER TABLE user_recommendation_settings ADD lookback_days INT DEFAULT 2 NOT NULL'
            : 'ALTER TABLE user_recommendation_settings ADD COLUMN lookback_days INTEGER DEFAULT 2 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $platform = $this->connection->getDatabasePlatform();

        $mysql = $platform instanceof AbstractMySQLPlatform;
        $sqlite = $platform instanceof SQLitePlatform;

        // Better a refusal than DDL invented for a platform nobody tested.
        $this->abortIf(!$mysql && !$sqlite, \sprintf(
            'No DDL defined for platform %s; only MySQL and SQLite are supported.',
            $platform::class,
        ));

        $settings = $schema->getTable('user_recommendation_settings');

        if (!$settings->hasColumn('lookback_days')) {
            return;
        }

        $this->addSql($mysql
            ? 'ALTER TABLE user_recommendation_settings DROP lookback_days'
            : 'ALTER TABLE user_recommendation_settings DROP COLUMN lookback_days');
    }
}
