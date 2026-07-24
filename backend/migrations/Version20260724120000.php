<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds app_user.locale ('en'|'de'), the recipient language for this account's
 * transactional emails, captured from the UI at registration.
 *
 * PLATFORM-AWARE DDL, for the same reason Version20260723200000 is: a
 * `doctrine:migrations:diff` on a SQLite dev box emits SQLite-only DDL MySQL
 * cannot parse, and the suite would not catch it because tests build their
 * schema from ORM metadata rather than by executing this chain.
 *
 * ADDITIVE ONLY. One NOT NULL column with DEFAULT 'en' — every account that
 * exists today predates per-language mail, so English backfills them correctly
 * with no separate per-row pass.
 */
final class Version20260724120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return "Add app_user.locale ('en'|'de') for localised account emails";
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

        // Per-column idempotence for a database baselined from
        // doctrine:schema:create, where ORM metadata already produced the column.
        if (!$schema->hasTable('app_user') || $schema->getTable('app_user')->hasColumn('locale')) {
            return;
        }

        // `ADD locale` (MySQL) vs `ADD COLUMN locale` (SQLite); both take the
        // same NOT NULL DEFAULT 'en'.
        $verb = $mysql ? 'ADD' : 'ADD COLUMN';
        $this->addSql(\sprintf(
            "ALTER TABLE app_user %s locale VARCHAR(5) DEFAULT 'en' NOT NULL",
            $verb,
        ));
    }

    public function down(Schema $schema): void
    {
        if ($schema->hasTable('app_user') && $schema->getTable('app_user')->hasColumn('locale')) {
            $this->addSql('ALTER TABLE app_user DROP COLUMN locale');
        }
    }
}
