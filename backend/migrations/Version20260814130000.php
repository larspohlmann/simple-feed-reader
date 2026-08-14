<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Drops idx_entry_list (created_at, published_at, id). It served the #366
 * run-first list sort; that sort now walks effective_date and is served by
 * idx_entry_effective (effective_date, id), which already exists. Nothing
 * else orders or filters on the (created_at, published_at, id) tuple:
 * idx_entry_feed_created (feed_id, created_at, id) added in
 * Version20260814120000 serves EntryPruner's fetch-order ranking instead.
 *
 * PLATFORM-AWARE DDL for the same reason Version20260812120000 is: DDL
 * diffed on one platform does not parse on the other, and the suite cannot
 * catch it because tests build their schema from ORM metadata, not this
 * chain.
 */
final class Version20260814130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Drop idx_entry_list; the entry list sorts on effective_date again (#384)';
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

        if ($entry->hasIndex('idx_entry_list')) {
            $this->addSql($mysql
                ? 'DROP INDEX idx_entry_list ON entry'
                : 'DROP INDEX idx_entry_list');
        }
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

        $entry = $schema->getTable('entry');

        if (!$entry->hasIndex('idx_entry_list')) {
            $this->addSql('CREATE INDEX idx_entry_list ON entry (created_at, published_at, id)');
        }
    }
}
