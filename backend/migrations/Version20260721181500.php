<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Pins `user_identity.provider_user_id` to `utf8mb4_bin`, so a provider subject
 * is compared case-sensitively on MySQL as it always was on SQLite.
 *
 * THE BUG, observed rather than reasoned about. The column declared no
 * collation, so MySQL gave it the table default (utf8mb4_0900_ai_ci) and
 * compared it case-insensitively. Against MySQL 8.4, a lookup for `sub-abc`
 * returned a row stored as `Sub-ABC`; against SQLite the same lookup returned
 * nothing. Same code, same query, two answers — the divergence class this
 * project has now been bitten by twice.
 *
 * WHY IT MATTERS, given no provider shipping today can trigger it. Google's
 * `sub` is decimal digits and Apple's is digits and dots, so neither can
 * produce two subjects differing only in case. The hazard is that the design
 * spec advertises a new provider as "one class and one env block, no
 * migration". A provider issuing base64url or hex subjects makes `a` and `A`
 * two different people, and under the old collation the second of them to sign
 * in would have been handed the FIRST one's account. That is an account
 * takeover reachable by performing exactly the operation the design calls
 * safe, which is why it is fixed before a third provider can exist rather than
 * after.
 *
 * ONLY `provider_user_id`, deliberately. The sibling column `provider` is left
 * on the table default: its values are a closed vocabulary written by our own
 * code from a hard-coded getName(), never provider- or user-supplied, so its
 * collation cannot be attacked — only look untidy. App\Entity\UserIdentity
 * carries the same note at the two columns themselves, which is where a reader
 * puzzled by the asymmetry will actually be standing.
 *
 * PLATFORM-AWARE DDL, hand-written, for the reason Version20260721153011 and
 * Version20260721170000 both record: `doctrine:migrations:diff` emits DDL for
 * whichever platform it happened to run against, and the test suite cannot
 * catch a wrong-dialect migration because tests/bootstrap.php builds its schema
 * from ORM metadata with doctrine:schema:create and never executes this chain.
 * The CI job "Verify the migration chain builds the schema from empty" is the
 * only thing in the project that would catch it, and it is the only reason a
 * collation-only change like this one is safe to ship: doctrine:schema:validate
 * there compares the mapping's `utf8mb4_bin` against the real column, so a
 * migration that forgot the COLLATE clause would fail the build rather than
 * drift silently.
 *
 * NOT ADDITIVE, unlike every migration before it — this ALTERs a column in
 * place. That is safe here specifically because it changes only how the column
 * COMPARES, never what it can hold: utf8mb4_bin and utf8mb4_0900_ai_ci accept
 * identical byte sequences at identical lengths, so a release running against
 * the migrated schema during the window before the `current` symlink flips
 * cannot write a value the new collation rejects. A width or type change in
 * this position would not have that property.
 *
 * EXISTING DATA. If two rows ever differed only by case in provider_user_id,
 * the old case-insensitive unique index would have refused the second one at
 * insert time — so such a pair cannot exist, and this ALTER cannot fail on it.
 * Were the index somehow absent, the ALTER would fail loudly on rebuilding it
 * rather than silently merging rows, which is the behaviour we want and which
 * MySQL provides on its own. No extra guard is warranted.
 *
 * THE UNIQUE INDEX. uniq_identity_provider_uid spans
 * (provider, provider_user_id), and changing a column's collation rebuilds
 * every index over it. The index survives with the new collation on the
 * rebuilt column — verified with SHOW CREATE TABLE after migrating, not
 * assumed, because losing it would silently remove the only thing stopping one
 * provider subject from being registered to two local accounts.
 */
final class Version20260721181500 extends AbstractMigration
{
    private const TABLE = 'user_identity';
    private const COLUMN = 'provider_user_id';
    private const COLLATION = 'utf8mb4_bin';

    public function getDescription(): string
    {
        return 'Compare user_identity.provider_user_id case-sensitively on MySQL by pinning utf8mb4_bin.';
    }

    // Non-transactional, matching the global doctrine_migrations.transactional:
    // false policy — see that config for why (MySQL auto-commits DDL, so the
    // per-migration transaction was a no-op the ORM now deprecates). The
    // generator emits this for new migrations; existing ones are retrofitted to
    // keep the whole set uniform.
    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $platform = $this->connection->getDatabasePlatform();

        if ($platform instanceof AbstractMySQLPlatform) {
            // Idempotence at the DDL level, not merely at the version-table
            // level. `doctrine:migrations:migrate` already refuses to re-run an
            // executed version, so a second deploy is a no-op for free — but a
            // database baselined from doctrine:schema:create (the path
            // Version20260721153011's abortIf tells operators to take) got the
            // collation from ORM metadata and has never seen this migration.
            // Re-applying the ALTER there would be a pointless full table
            // rebuild plus an index rebuild, on the one code path where the
            // table might already be large.
            $this->skipIf(
                self::COLLATION === $this->currentCollation(),
                \sprintf('%s.%s is already %s; nothing to do.', self::TABLE, self::COLUMN, self::COLLATION),
            );

            // Spelled exactly as doctrine:schema:create --dump-sql emits it for
            // this mapping, so what the chain builds and what schema:validate
            // expects are the same string rather than two things that merely
            // ought to agree.
            $this->addSql(\sprintf(
                'ALTER TABLE %s MODIFY %s VARCHAR(191) NOT NULL COLLATE `%s`',
                self::TABLE,
                self::COLUMN,
                self::COLLATION,
            ));

            return;
        }

        if ($platform instanceof SQLitePlatform) {
            // Deliberately a no-op, and deliberately not collapsed into a
            // shared branch with MySQL. SQLite's default BINARY collation is
            // already case-sensitive, so this column has always behaved the way
            // the fix makes MySQL behave — there is nothing to change, and the
            // two platforms converge on SQLite's semantics rather than MySQL's.
            //
            // Worth stating which direction the convergence runs: the fix
            // corrects PRODUCTION to match dev, so the behaviour every test in
            // this suite has been asserting all along is the one that now
            // ships. Had it gone the other way, every existing assertion about
            // subject matching would have needed rereading.
            //
            // The empty branch also keeps the abortIf below meaningful: an
            // untested platform gets a refusal rather than falling through a
            // condition that looked generic.
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
        $platform = $this->connection->getDatabasePlatform();

        if (!$platform instanceof AbstractMySQLPlatform) {
            // Nothing was done on SQLite, so there is nothing to undo. Reversing
            // "no-op" as "no-op" rather than leaving down() to throw keeps the
            // chain rewindable on the engine dev and CI actually use.
            return;
        }

        // Omitting COLLATE returns the column to the table's default, which is
        // where it was before this migration — rather than naming
        // utf8mb4_0900_ai_ci explicitly, which would hard-code the default of
        // the MySQL version that happened to create the table and quietly
        // change the column's meaning on a server whose default differs.
        $this->addSql(\sprintf(
            'ALTER TABLE %s MODIFY %s VARCHAR(191) NOT NULL',
            self::TABLE,
            self::COLUMN,
        ));
    }

    /**
     * Read from information_schema rather than from the injected Schema: DBAL's
     * introspected Column exposes a collation platform option, but whether it
     * is populated depends on the platform and DBAL version, and a silently
     * absent value here would turn the skipIf above into a permanent "not yet
     * applied" and re-run the ALTER on every baselined deploy.
     */
    private function currentCollation(): ?string
    {
        $collation = $this->connection->fetchOne(
            'SELECT collation_name FROM information_schema.columns
             WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?',
            [self::TABLE, self::COLUMN],
        );

        return \is_string($collation) ? $collation : null;
    }
}
