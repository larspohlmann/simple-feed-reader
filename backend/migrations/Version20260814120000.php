<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds idx_entry_feed_created (feed_id, created_at, id) to serve
 * EntryPruner's fetch-order ranking (#384): the age pass and the per-feed
 * cap now both rank a feed's entries via a correlated subquery on
 * `feed = ? AND (created_at, id) > (created_at, id)`, replacing the old
 * per-feed `OFFSET` that idx_entry_feed_effective (feed_id, effective_date)
 * used to serve. Without this index the new ranking query filters on
 * feed_id and filesorts the rest — on every refresh, for every feed with a
 * stale or over-cap entry.
 *
 * idx_entry_feed_effective stays: the reader's unread watermark and list
 * queries still walk article-recency (effective_date), not fetch-recency.
 *
 * PLATFORM-AWARE DDL for the same reason Version20260812120000 is: DDL
 * diffed on one platform does not parse on the other, and the suite cannot
 * catch it because tests build their schema from ORM metadata, not this
 * chain.
 */
final class Version20260814120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Index entry (feed_id, created_at, id) for EntryPruner\'s fetch-order ranking (#384)';
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

        if (!$entry->hasIndex('idx_entry_feed_created')) {
            $this->addSql('CREATE INDEX idx_entry_feed_created ON entry (feed_id, created_at, id)');
        }
    }

    public function down(Schema $schema): void
    {
        $entry = $schema->getTable('entry');

        if ($entry->hasIndex('idx_entry_feed_created')) {
            $platform = $this->connection->getDatabasePlatform();
            $this->addSql($platform instanceof AbstractMySQLPlatform
                ? 'DROP INDEX idx_entry_feed_created ON entry'
                : 'DROP INDEX idx_entry_feed_created');
        }
    }
}
