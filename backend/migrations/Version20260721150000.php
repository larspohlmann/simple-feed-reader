<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Doctrine\Migrations\Exception\IrreversibleMigration;

/**
 * DATA ONLY — this migration issues no DDL.
 *
 * App\Entity\User now lowercases addresses on construction, and every lookup
 * path (UserRepository::findOneByEmail, and through it the security provider)
 * normalises before comparing. Any row already stored with mixed case would
 * therefore become unreachable: the owner could not log in, could not request a
 * password reset, and re-registering would look like a duplicate. That is the
 * same lockout the normalisation was introduced to prevent, so the existing
 * rows have to be brought forward too.
 *
 * Portability: LOWER() is standard SQL and behaves identically on both targets
 * for the ASCII-only addresses Assert\Email(html5) permits. SQLite's LOWER() is
 * ASCII-only by design; MySQL's is collation-aware but reaches the same result
 * on ASCII. Verified by execution against SQLite; the MySQL leg is exercised by
 * CI, which runs the suite against mysql:8.4.
 */
final class Version20260721150000 extends AbstractMigration
{
    private const TABLE = 'app_user';

    public function getDescription(): string
    {
        return 'Data only: lowercase existing app_user.email to match the normalisation all lookups now apply.';
    }

    public function up(Schema $schema): void
    {
        // Guard rather than assume: this migration is timestamped BEFORE the
        // schema migration that creates app_user, because it was written for
        // databases whose schema predates the chain. On a genuinely empty
        // database it therefore runs first, with no table to backfill.
        //
        // `return`, NOT $this->skipIf(). Doctrine does not record a skipped
        // migration as executed, so skipIf() left this version pending after a
        // from-empty run: the chain reported success, and the NEXT invocation
        // — the second production deploy — ran the UPDATE for real against a
        // live table. Harmless in itself (it lowercases addresses that are
        // already lowercase) but it meant the chain never converged in one
        // pass, so "no migrations pending" was false on every fresh install
        // until something ran it a second time. Returning early records the
        // version with zero queries, which is what "there was nothing to do
        // here" should mean.
        //
        // Verified: migrate from empty, then migrate again => "Already at the
        // latest version". Previously the second run executed one query.
        if (!$schema->hasTable(self::TABLE)) {
            return;
        }

        $this->abortOnCollisions();

        $this->addSql('UPDATE app_user SET email = LOWER(email)');
    }

    public function down(Schema $schema): void
    {
        // The original casing is not recorded anywhere, so this cannot be
        // undone. Saying so is better than a no-op down() that silently
        // pretends the rollback succeeded.
        throw new IrreversibleMigration(
            'Lowercasing app_user.email cannot be reversed: the original casing is not retained.',
        );
    }

    /**
     * Refuses to run if lowercasing would merge two rows into one.
     *
     * Reachable on any case-sensitive collation — SQLite, or MySQL with a _bin
     * or _cs collation — where `bob@example.com` and `Bob@example.com` can both
     * exist today. Effectively unreachable on the MySQL 8.4 default
     * (utf8mb4_0900_ai_ci), because that unique index would already have
     * rejected the second insert.
     *
     * Failing loudly is the only acceptable behaviour here. The alternatives
     * are a unique-constraint violation with an opaque driver message, or —
     * far worse — quietly deleting one of two real accounts. An operator who
     * hits this needs to decide which account survives; the migration must not
     * decide for them.
     */
    private function abortOnCollisions(): void
    {
        $collisions = $this->connection->fetchFirstColumn(
            'SELECT LOWER(email) FROM app_user GROUP BY LOWER(email) HAVING COUNT(*) > 1',
        );

        if ([] === $collisions) {
            return;
        }

        $this->abortIf(true, \sprintf(
            'Cannot lowercase app_user.email: %d address(es) would collide (%s). '
            . 'Two accounts differing only by case already exist. Merge or remove '
            . 'the duplicates by hand, then re-run this migration.',
            \count($collisions),
            implode(', ', array_map(strval(...), $collisions)),
        ));
    }
}
