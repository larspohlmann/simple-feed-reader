<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds app_user.password_changed_at, the column that binds an issued JWT to the
 * password it was issued under.
 *
 * Why it exists: tokens are stateless, live 7 days and have no refresh flow.
 * The per-request Doctrine reload revokes on a STATUS change, but nothing in a
 * JWT derives from the password hash, so a password reset revoked nothing — a
 * phished user's attacker kept full access for the rest of the week.
 * App\Security\PasswordChangeTokenInvalidator compares the token's `iat`
 * against this column; App\Entity\User documents the rest.
 *
 * PLATFORM-AWARE DDL, for the same reason Version20260721153011 is. A
 * `doctrine:migrations:diff` run on a SQLite dev box emits SQLite-only DDL that
 * MySQL cannot parse, and the test suite would never catch it because tests
 * build their schema from ORM metadata rather than by executing this chain.
 * That exact bug was caught once already in this branch, so both dialects are
 * written out explicitly and both were executed from empty before shipping.
 *
 * The two statements happen to be spelled almost identically here — one added
 * nullable column is about as portable as DDL gets. They are still kept apart:
 * the abortIf below is what guarantees an untested platform gets a refusal
 * rather than DDL that merely looks generic, and collapsing the branches would
 * quietly remove that guarantee the next time a column is less portable.
 *
 * ADDITIVE ONLY. One nullable column, no DROP, no narrowing, no constraint on
 * existing data. The deploy migrates BEFORE the `current` symlink flips, so the
 * previous release keeps serving against the migrated schema for a moment —
 * that is only safe while migrations stay additive. A release that predates
 * this column simply never writes it.
 *
 * NULLABLE IS LOAD-BEARING, not laziness. Existing rows get NULL, and the
 * invalidator treats NULL as "no recorded change, revoke nothing". Backfilling
 * it with `now()` instead would invalidate every token in circulation and sign
 * the entire user base out at deploy time; backfilling with created_at would
 * assert a password age the database does not actually know. NULL is the only
 * honest value for a row written before the column existed.
 */
final class Version20260721170000 extends AbstractMigration
{
    private const TABLE = 'app_user';
    private const COLUMN = 'password_changed_at';

    public function getDescription(): string
    {
        return 'Add app_user.password_changed_at so a password reset revokes JWTs issued before it.';
    }

    public function up(Schema $schema): void
    {
        // Idempotence for a database baselined from doctrine:schema:create,
        // where ORM metadata already produced the column. Skipping beats
        // failing on a duplicate-column error halfway through a deploy.
        $this->skipIf(
            $schema->hasTable(self::TABLE) && $schema->getTable(self::TABLE)->hasColumn(self::COLUMN),
            'app_user.password_changed_at already exists; nothing to do.',
        );

        $platform = $this->connection->getDatabasePlatform();

        if ($platform instanceof AbstractMySQLPlatform) {
            $this->addSql('ALTER TABLE app_user ADD password_changed_at DATETIME DEFAULT NULL');

            return;
        }

        if ($platform instanceof SQLitePlatform) {
            $this->addSql('ALTER TABLE app_user ADD COLUMN password_changed_at DATETIME DEFAULT NULL');

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
        $this->addSql('ALTER TABLE app_user DROP COLUMN password_changed_at');
    }
}
