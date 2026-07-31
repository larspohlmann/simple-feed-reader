<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds app_user.trial_ends_at and app_user.max_subscriptions for #66: a
 * per-account trial period and a per-account subscription cap.
 *
 * PLATFORM-AWARE DDL: tests build their schema from ORM metadata and never
 * execute a migration, so a dialect error here is caught only by CI's
 * migrate-from-empty leg.
 *
 * ADDITIVE ONLY. Two nullable columns, no DROP, no constraint on existing
 * data — every account that existed before this reads as "no trial, no
 * per-user cap".
 */
final class Version20260731130000 extends AbstractMigration
{
    private const TABLE = 'app_user';

    public function getDescription(): string
    {
        return 'Add app_user.trial_ends_at and app_user.max_subscriptions (#66).';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->skipIf(
            $schema->hasTable(self::TABLE)
                && $schema->getTable(self::TABLE)->hasColumn('trial_ends_at')
                && $schema->getTable(self::TABLE)->hasColumn('max_subscriptions'),
            'app_user trial columns already exist; nothing to do.',
        );

        $platform = $this->connection->getDatabasePlatform();

        if ($platform instanceof AbstractMySQLPlatform) {
            $this->addSql('ALTER TABLE app_user ADD trial_ends_at DATETIME DEFAULT NULL, ADD max_subscriptions INT DEFAULT NULL');

            return;
        }

        if ($platform instanceof SQLitePlatform) {
            $this->addSql('ALTER TABLE app_user ADD COLUMN trial_ends_at DATETIME DEFAULT NULL');
            $this->addSql('ALTER TABLE app_user ADD COLUMN max_subscriptions INT DEFAULT NULL');

            return;
        }

        $this->abortIf(true, \sprintf(
            'No DDL defined for platform %s; only MySQL and SQLite are supported.',
            $platform::class,
        ));
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE app_user DROP COLUMN trial_ends_at');
        $this->addSql('ALTER TABLE app_user DROP COLUMN max_subscriptions');
    }
}
