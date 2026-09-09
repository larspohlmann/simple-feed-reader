<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * feed.last_new_entry_at (#957): the instant a fetch last brought back new
 * entries, as opposed to last_successful_fetch_at, which advances on every 200
 * even when the feed carried nothing new. The reader's "last updated" reads
 * this column so it reflects real content, not a bare poll.
 *
 * Backfilled from each feed's newest entry: entry.created_at is when an entry
 * entered this instance — exactly "when new content was retrieved" — so the
 * greatest one per feed is the truthful historical value, not the mere poll
 * time last_successful_fetch_at records. A feed with no entries stays null
 * ("never"), which is honest: it has delivered nothing here. The correlated
 * subquery reads the same on both dialects, so only the column add branches.
 *
 * PLATFORM-AWARE DDL for the same reason its siblings are: DDL diffed on one
 * platform does not parse on the other, and the suite cannot catch it because
 * tests build their schema from ORM metadata, not this chain.
 */
final class Version20260909120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add feed.last_new_entry_at, backfilled from last_successful_fetch_at (#957)';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $mysql = $this->assertSupportedPlatform();

        $feed = $schema->getTable('feed');
        if ($feed->hasColumn('last_new_entry_at')) {
            return;
        }

        $this->addSql($mysql
            ? 'ALTER TABLE feed ADD last_new_entry_at DATETIME DEFAULT NULL'
            : 'ALTER TABLE feed ADD COLUMN last_new_entry_at DATETIME DEFAULT NULL');

        $this->addSql('UPDATE feed SET last_new_entry_at = (SELECT MAX(e.created_at) FROM entry e WHERE e.feed_id = feed.id)');
    }

    public function down(Schema $schema): void
    {
        $mysql = $this->assertSupportedPlatform();

        $feed = $schema->getTable('feed');
        if (!$feed->hasColumn('last_new_entry_at')) {
            return;
        }

        $this->addSql($mysql
            ? 'ALTER TABLE feed DROP last_new_entry_at'
            : 'ALTER TABLE feed DROP COLUMN last_new_entry_at');
    }

    /** @return bool True on MySQL, false on SQLite; aborts on anything else. */
    private function assertSupportedPlatform(): bool
    {
        $platform = $this->connection->getDatabasePlatform();
        $mysql = $platform instanceof AbstractMySQLPlatform;

        // Better a refusal than DDL invented for a platform nobody tested.
        $this->abortIf(!$mysql && !($platform instanceof SQLitePlatform), \sprintf(
            'No DDL defined for platform %s; only MySQL and SQLite are supported.',
            $platform::class,
        ));

        return $mysql;
    }
}
