<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds user-defined ordering: tag.position (tag order in the sidebar),
 * subscription.position (order in the untagged "Feeds" list) and
 * subscription_tag.position (a feed's order WITHIN a tag — the join was promoted
 * from a plain many-to-many to the App\Entity\SubscriptionTag entity so it can
 * carry this). Feature: drag-and-drop reordering of tags and feeds.
 *
 * PLATFORM-AWARE DDL, for the same reason Version20260721170000 is: a
 * `doctrine:migrations:diff` on a SQLite dev box emits SQLite-only DDL MySQL
 * cannot parse, and the suite would not catch it because tests build their
 * schema from ORM metadata rather than by executing this chain. Both dialects
 * are written out; an untested platform gets a refusal, not guessed DDL.
 *
 * ADDITIVE ONLY. Three NOT NULL columns with DEFAULT 0, no DROP, no narrowing.
 * DEFAULT 0 is load-bearing: SQLite cannot add a NOT NULL column to a non-empty
 * table without one, and it lets the previous release keep serving during the
 * pre-symlink-flip window (its writes simply never set a position).
 *
 * BACKFILL in postUp(), after the columns exist, so the current display order
 * becomes the initial custom order — no visible jump on first load:
 *   - tag.position: per user, by name.
 *   - subscription.position: per user, by created_at, id.
 *   - subscription_tag.position: per tag, by the subscription's created_at, id.
 * Portable per-row loops via $this->connection — no window-function dependency.
 */
final class Version20260723120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add tag/subscription/subscription_tag position columns for drag-and-drop ordering.';
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
        foreach ([['tag', 'position'], ['subscription', 'position'], ['subscription_tag', 'position']] as [$table, $column]) {
            if ($schema->hasTable($table) && $schema->getTable($table)->hasColumn($column)) {
                continue;
            }

            // `ADD position` (MySQL) vs `ADD COLUMN position` (SQLite); both take
            // the same NOT NULL DEFAULT 0.
            $verb = $mysql ? 'ADD' : 'ADD COLUMN';
            $this->addSql(\sprintf(
                'ALTER TABLE %s %s position INTEGER NOT NULL DEFAULT 0',
                $table,
                $verb,
            ));
        }
    }

    public function postUp(Schema $schema): void
    {
        // Backfill is ONE-SHOT: it rewrites every row's position. `up()` is
        // idempotent but `postUp` is not, and `migrations:execute` re-runs it
        // without consulting migration_versions — so guard EACH table and skip
        // any that already carries a custom order (a non-zero position), which a
        // replay would otherwise clobber back to the initial display order.

        // Tags: per user, alphabetical.
        if (!$this->alreadyOrdered('tag')) {
            $this->assignPositions(
                'SELECT id, user_id FROM tag ORDER BY user_id ASC, name ASC',
                'user_id',
                'UPDATE tag SET position = :position WHERE id = :id',
                static fn (array $row): array => ['id' => (int) $row['id']],
            );
        }

        // Untagged "Feeds" order: per user, oldest first.
        if (!$this->alreadyOrdered('subscription')) {
            $this->assignPositions(
                'SELECT id, user_id FROM subscription ORDER BY user_id ASC, created_at ASC, id ASC',
                'user_id',
                'UPDATE subscription SET position = :position WHERE id = :id',
                static fn (array $row): array => ['id' => (int) $row['id']],
            );
        }

        // Feeds within each tag: by the subscription's age.
        if (!$this->alreadyOrdered('subscription_tag')) {
            $this->assignPositions(
                'SELECT st.subscription_id, st.tag_id
                 FROM subscription_tag st
                 JOIN subscription s ON s.id = st.subscription_id
                 ORDER BY st.tag_id ASC, s.created_at ASC, s.id ASC',
                'tag_id',
                'UPDATE subscription_tag SET position = :position
                 WHERE subscription_id = :subscription_id AND tag_id = :tag_id',
                static fn (array $row): array => [
                    'subscription_id' => (int) $row['subscription_id'],
                    'tag_id' => (int) $row['tag_id'],
                ],
            );
        }
    }

    /** True if the table already carries a custom order (any non-zero position). */
    private function alreadyOrdered(string $table): bool
    {
        return (int) $this->connection->fetchOne(
            \sprintf('SELECT COUNT(*) FROM %s WHERE position <> 0', $table),
        ) > 0;
    }

    /**
     * Walk $orderedSql once, assigning position 0..n and resetting to 0 whenever
     * $partitionColumn changes. $keyOf maps a row to the UPDATE's identifying
     * bind params.
     *
     * @param callable(array<string, mixed>): array<string, int> $keyOf
     */
    private function assignPositions(
        string $orderedSql,
        string $partitionColumn,
        string $updateSql,
        callable $keyOf,
    ): void {
        $position = 0;
        $partition = null;
        foreach ($this->connection->fetchAllAssociative($orderedSql) as $row) {
            if ($row[$partitionColumn] !== $partition) {
                $partition = $row[$partitionColumn];
                $position = 0;
            }
            $this->connection->executeStatement($updateSql, ['position' => $position] + $keyOf($row));
            ++$position;
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE subscription_tag DROP COLUMN position');
        $this->addSql('ALTER TABLE subscription DROP COLUMN position');
        $this->addSql('ALTER TABLE tag DROP COLUMN position');
    }
}
