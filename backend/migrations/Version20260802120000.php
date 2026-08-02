<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds user_preferences, one row of per-account settings per user, starting
 * with scrape_fallback_enabled.
 *
 * PLATFORM-AWARE DDL for the same reason Version20260724120000 is: a
 * `doctrine:migrations:diff` on a SQLite dev box emits SQLite-only DDL MySQL
 * cannot parse, and the suite cannot catch it because tests build their schema
 * from ORM metadata rather than by executing this chain.
 *
 * The backfill is not optional. User::getPreferences() throws when the row is
 * missing, and hydration bypasses the constructor that would create it, so
 * every account that predates this migration needs its row written here.
 *
 * The backfill runs OUTSIDE the hasTable() guard and is itself idempotent
 * (INSERT ... WHERE id NOT IN (...)), not just for the schema-baseline case
 * (a DB where doctrine:schema:create already produced the table). isTransactional()
 * is false and MySQL DDL autocommits regardless, so a process killed after
 * CREATE TABLE/ADD CONSTRAINT but before the INSERT leaves the table present
 * without doctrine_migration_versions recording success. The standard recovery,
 * re-running doctrine:migrations:migrate, must still perform the backfill on
 * that retry — if it were behind the same guard as the CREATE TABLE, hasTable()
 * would already be true and every pre-existing user would be permanently left
 * without a preferences row.
 */
final class Version20260802120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add user_preferences (scrape_fallback_enabled) and backfill one row per user';
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

        // Idempotence for a database baselined from doctrine:schema:create,
        // where ORM metadata already produced the table. The backfill below
        // deliberately sits OUTSIDE this guard; see the class docblock.
        if (!$schema->hasTable('user_preferences')) {
            if ($mysql) {
                $this->addSql(
                    'CREATE TABLE user_preferences ('
                    . 'id INT AUTO_INCREMENT NOT NULL, '
                    . 'user_id INT NOT NULL, '
                    . 'scrape_fallback_enabled TINYINT(1) DEFAULT 0 NOT NULL, '
                    . 'UNIQUE INDEX uniq_preferences_user (user_id), '
                    . 'PRIMARY KEY(id)'
                    . ') DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB',
                );
                $this->addSql(
                    'ALTER TABLE user_preferences ADD CONSTRAINT fk_preferences_user '
                    . 'FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE',
                );
            } else {
                $this->addSql(
                    'CREATE TABLE user_preferences ('
                    . 'id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, '
                    . 'user_id INTEGER NOT NULL, '
                    . 'scrape_fallback_enabled BOOLEAN DEFAULT 0 NOT NULL, '
                    . 'CONSTRAINT fk_preferences_user FOREIGN KEY (user_id) REFERENCES app_user (id) '
                    . 'ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE'
                    . ')',
                );
                $this->addSql(
                    'CREATE UNIQUE INDEX uniq_preferences_user ON user_preferences (user_id)',
                );
            }
        }

        // Unconditional and self-healing (WHERE NOT IN), not merely a one-time
        // backfill: see the class docblock for the partial-failure retry this
        // covers. Safe to run on every migrate, including a second run after a
        // successful one, because the subquery excludes rows that already exist.
        $this->addSql(
            'INSERT INTO user_preferences (user_id, scrape_fallback_enabled) '
            . 'SELECT id, 0 FROM app_user '
            . 'WHERE id NOT IN (SELECT user_id FROM user_preferences)',
        );
    }

    public function down(Schema $schema): void
    {
        if ($schema->hasTable('user_preferences')) {
            $this->addSql('DROP TABLE user_preferences');
        }
    }
}
