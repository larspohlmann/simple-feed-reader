<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * feed.last_successful_fetch_at (#384): the instant a fetch last actually
 * delivered, as opposed to last_fetched_at, which also advances on a failed
 * or gone attempt (FeedScheduler::recordFailure(), recordGone()). The entry
 * effective-date grace window now reads this column instead, so a feed that
 * failed for days and then recovers gives its whole backlog the grace of
 * "new to us" rather than sinking it to its own publication dates.
 *
 * Backfilled from last_fetched_at: the best available approximation for
 * existing rows, since nothing recorded whether any given past fetch
 * succeeded. A row backfilled this way may read a few minutes later than its
 * true last success (if the most recent attempt before this migration ran
 * failed), which only widens that one feed's grace window once — not a
 * correctness problem worth a richer backfill for.
 *
 * PLATFORM-AWARE DDL for the same reason Version20260814130000 is: DDL
 * diffed on one platform does not parse on the other, and the suite cannot
 * catch it because tests build their schema from ORM metadata, not this
 * chain.
 */
final class Version20260814140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add feed.last_successful_fetch_at, backfilled from last_fetched_at (#384)';
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

        $feed = $schema->getTable('feed');

        if ($feed->hasColumn('last_successful_fetch_at')) {
            return;
        }

        $this->addSql($mysql
            ? 'ALTER TABLE feed ADD last_successful_fetch_at DATETIME DEFAULT NULL'
            : 'ALTER TABLE feed ADD COLUMN last_successful_fetch_at DATETIME DEFAULT NULL');

        $this->addSql('UPDATE feed SET last_successful_fetch_at = last_fetched_at WHERE last_successful_fetch_at IS NULL');
    }

    public function down(Schema $schema): void
    {
        $platform = $this->connection->getDatabasePlatform();

        $mysql = $platform instanceof AbstractMySQLPlatform;
        $sqlite = $platform instanceof SQLitePlatform;

        // Better a refusal than DDL invented for a platform nobody tested.
        $this->abortIf(!$mysql && !$sqlite, \sprintf(
            'No DDL defined for platform %s; only MySQL and SQLite are supported.',
            $platform::class,
        ));

        $feed = $schema->getTable('feed');

        if (!$feed->hasColumn('last_successful_fetch_at')) {
            return;
        }

        $this->addSql($mysql
            ? 'ALTER TABLE feed DROP last_successful_fetch_at'
            : 'ALTER TABLE feed DROP COLUMN last_successful_fetch_at');
    }
}
