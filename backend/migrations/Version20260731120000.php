<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds app_user.last_login_at, so the admin screens can show when an account
 * was last used and flag accounts that were created and then abandoned.
 *
 * PLATFORM-AWARE DDL, for the same reason Version20260721170000 is: tests
 * build their schema from ORM metadata and never execute a migration, so a
 * dialect error here is caught only by CI's dedicated migrate-from-empty leg.
 *
 * ADDITIVE ONLY. One nullable column, no DROP, no narrowing, no constraint on
 * existing data — every account that existed before this ships simply reads
 * as "never signed in" until its owner next signs in.
 */
final class Version20260731120000 extends AbstractMigration
{
    private const TABLE = 'app_user';
    private const COLUMN = 'last_login_at';

    public function getDescription(): string
    {
        return 'Add app_user.last_login_at for the admin activity screens.';
    }

    // Non-transactional, matching the global doctrine_migrations.transactional:
    // false policy the rest of this chain uses.
    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        // Idempotence for a database baselined from doctrine:schema:create,
        // where ORM metadata already produced the column. Skipping beats
        // failing on a duplicate-column error halfway through a deploy.
        $this->skipIf(
            $schema->hasTable(self::TABLE) && $schema->getTable(self::TABLE)->hasColumn(self::COLUMN),
            'app_user.last_login_at already exists; nothing to do.',
        );

        $platform = $this->connection->getDatabasePlatform();

        if ($platform instanceof AbstractMySQLPlatform) {
            $this->addSql('ALTER TABLE app_user ADD last_login_at DATETIME DEFAULT NULL');

            return;
        }

        if ($platform instanceof SQLitePlatform) {
            $this->addSql('ALTER TABLE app_user ADD COLUMN last_login_at DATETIME DEFAULT NULL');

            return;
        }

        // Better a refusal than DDL invented for a platform nobody tested.
        $this->abortIf(true, \sprintf(
            'No DDL defined for platform %s; only MySQL and SQLite are supported.',
            $platform::class,
        ));
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE app_user DROP COLUMN last_login_at');
    }
}
