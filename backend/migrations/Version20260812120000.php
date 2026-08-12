<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds idx_entry_list (created_at, published_at, id) to serve the reader's
 * new run-first sort (#366): entries are ordered by their refresh run
 * (created_at = run-start), then by article publication time within the run,
 * then by id. The existing idx_entry_effective (effective_date, id) stays —
 * the pruner, the recommendation candidate loader, the recommendation history
 * loader, and the unread watermark still walk article-recency (effective_date
 * = published_at ?? created_at), not fetch-recency.
 *
 * PLATFORM-AWARE DDL for the same reason Version20260802130000 is: DDL diffed
 * on one platform does not parse on the other, and the suite cannot catch it
 * because tests build their schema from ORM metadata, not this chain.
 *
 * No data backfill: created_at and published_at already exist and are
 * populated, and historical rows keep their ingest-instant created_at (the
 * best available proxy for a run-start that was never recorded). The ORM
 * metadata is updated in lockstep to declare the new index.
 */
final class Version20260812120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Index entry (created_at, published_at, id) for the run-first list sort (#366)';
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

        if (!$entry->hasIndex('idx_entry_list')) {
            $this->addSql('CREATE INDEX idx_entry_list ON entry (created_at, published_at, id)');
        }
    }

    public function down(Schema $schema): void
    {
        $entry = $schema->getTable('entry');

        if ($entry->hasIndex('idx_entry_list')) {
            $platform = $this->connection->getDatabasePlatform();
            $this->addSql($platform instanceof AbstractMySQLPlatform
                ? 'DROP INDEX idx_entry_list ON entry'
                : 'DROP INDEX idx_entry_list');
        }
    }
}