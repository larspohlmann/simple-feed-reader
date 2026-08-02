<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Materializes entry.effective_date = COALESCE(published_at, created_at) so an
 * index can serve the reader's newest-first sort (#245), and swaps the entry
 * indexes: idx_entry_feed_published goes; idx_entry_effective (effective_date,
 * id) serves the cross-feed list walk, idx_entry_feed_effective (feed_id,
 * effective_date) serves single-feed scans, the pruner and the mark-read
 * watermark.
 *
 * PLATFORM-AWARE DDL for the same reason Version20260723200000 is: DDL diffed
 * on one platform does not parse on the other, and the suite cannot catch it
 * because tests build their schema from ORM metadata, not this chain.
 *
 * The epoch DEFAULT exists only because SQLite cannot ADD a NOT NULL column
 * without one (and has no MODIFY COLUMN to tighten one afterwards); MySQL takes
 * the identical DDL so both platforms match the ORM metadata, which declares
 * the same default. No row ever keeps it: the backfill below rewrites every
 * pre-existing row, and Entry assigns the real value on construction.
 *
 * The backfill runs OUTSIDE the column guard and is idempotent (rewriting a
 * correct row with the same COALESCE is a no-op): isTransactional() is false
 * and MySQL DDL autocommits, so a process killed between the ALTER and the
 * UPDATE must still backfill when doctrine:migrations:migrate is re-run.
 */
final class Version20260802130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Materialize entry.effective_date and index it for list sorting (#245)';
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

        $entry = $schema->getTable('entry');

        // Per-column idempotence for a database baselined from
        // doctrine:schema:create, where ORM metadata already produced the column.
        if (!$entry->hasColumn('effective_date')) {
            $verb = $mysql ? 'ADD' : 'ADD COLUMN';
            $this->addSql(\sprintf(
                "ALTER TABLE entry %s effective_date DATETIME DEFAULT '1970-01-01 00:00:00' NOT NULL",
                $verb,
            ));
        }

        // Unconditional and self-healing; see the class docblock.
        $this->addSql('UPDATE entry SET effective_date = COALESCE(published_at, created_at)');

        if ($entry->hasIndex('idx_entry_feed_published')) {
            $this->addSql($mysql
                ? 'DROP INDEX idx_entry_feed_published ON entry'
                : 'DROP INDEX idx_entry_feed_published');
        }
        if (!$entry->hasIndex('idx_entry_effective')) {
            $this->addSql('CREATE INDEX idx_entry_effective ON entry (effective_date, id)');
        }
        if (!$entry->hasIndex('idx_entry_feed_effective')) {
            $this->addSql('CREATE INDEX idx_entry_feed_effective ON entry (feed_id, effective_date)');
        }
    }

    public function down(Schema $schema): void
    {
        $platform = $this->connection->getDatabasePlatform();
        $mysql = $platform instanceof AbstractMySQLPlatform;

        $entry = $schema->getTable('entry');

        if ($entry->hasIndex('idx_entry_effective')) {
            $this->addSql($mysql ? 'DROP INDEX idx_entry_effective ON entry' : 'DROP INDEX idx_entry_effective');
        }
        if ($entry->hasIndex('idx_entry_feed_effective')) {
            $this->addSql($mysql
                ? 'DROP INDEX idx_entry_feed_effective ON entry'
                : 'DROP INDEX idx_entry_feed_effective');
        }
        if (!$entry->hasIndex('idx_entry_feed_published')) {
            $this->addSql('CREATE INDEX idx_entry_feed_published ON entry (feed_id, published_at)');
        }
        if ($entry->hasColumn('effective_date')) {
            $this->addSql('ALTER TABLE entry DROP COLUMN effective_date');
        }
    }
}
