<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * entry gains url_hash (#484): sha256 of the normalized article URL, the stable
 * identity a feed keeps across a volatile GUID (BBC appends a `#N` revision
 * counter to its GUID, which the old (feed_id, guid_hash) unique key never
 * caught, so each revision stored a second row).
 *
 * Nullable, no default: null means "this row has no URL identity". The ingest
 * dedup (EntryDeduplicator) reads a null as "no URL match" and falls back to
 * the GUID hash, so url-less feeds are unaffected.
 *
 * Deliberately NOT in this migration, by decision on #484:
 * - No backfill of existing rows. This is a forward-only fix; rows already in
 *   the table keep url_hash NULL, so an article the table already holds can
 *   duplicate once more on its next revision (the new row then carries
 *   url_hash and stabilizes). Backfilling them was declined.
 * - No unique key and no index. The dedup query filters within one feed, which
 *   the existing feed_id-prefixed indexes already narrow cheaply; a dedicated
 *   index would cost writes for a saving of microseconds on a background job.
 *
 * PLATFORM-AWARE DDL: DDL diffed on one platform does not parse on the other,
 * and the suite cannot catch it because tests build their schema from ORM
 * metadata, not this chain (see the migrations CI leg).
 */
final class Version20260821120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add entry.url_hash for stable-URL dedup of volatile GUIDs (#484)';
    }

    public function up(Schema $schema): void
    {
        $entry = $schema->getTable('entry');

        if ($entry->hasColumn('url_hash')) {
            return;
        }

        $this->addSql($this->mysql()
            ? 'ALTER TABLE entry ADD url_hash VARCHAR(64) DEFAULT NULL'
            : 'ALTER TABLE entry ADD COLUMN url_hash VARCHAR(64) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $entry = $schema->getTable('entry');

        if (!$entry->hasColumn('url_hash')) {
            return;
        }

        $this->addSql($this->mysql()
            ? 'ALTER TABLE entry DROP url_hash'
            : 'ALTER TABLE entry DROP COLUMN url_hash');
    }

    /**
     * Answers which of the two supported platforms this is running on, and
     * refuses any third: better a refusal than DDL invented for a platform
     * nobody tested.
     */
    private function mysql(): bool
    {
        $platform = $this->connection->getDatabasePlatform();

        $this->abortIf(
            !$platform instanceof AbstractMySQLPlatform && !$platform instanceof SQLitePlatform,
            \sprintf('No DDL defined for platform %s; only MySQL and SQLite are supported.', $platform::class),
        );

        return $platform instanceof AbstractMySQLPlatform;
    }
}
