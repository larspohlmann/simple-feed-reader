<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds idx_entry_url_hash (url_hash, id) to back the cross-feed
 * duplicate-collapse semi-join and enrichment query (#496). url_hash itself
 * dates from #484; this only indexes it.
 *
 * PLATFORM-AWARE DDL for the same reason Version20260812120000 is: DDL diffed
 * on one platform does not parse on the other, and the suite cannot catch it
 * because tests build their schema from ORM metadata, not this chain.
 */
final class Version20260911062436 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Index entry (url_hash, id) for the cross-feed duplicate collapse (#496)';
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

        if (!$entry->hasIndex('idx_entry_url_hash')) {
            $this->addSql('CREATE INDEX idx_entry_url_hash ON entry (url_hash, id)');
        }
    }

    public function down(Schema $schema): void
    {
        $entry = $schema->getTable('entry');

        if ($entry->hasIndex('idx_entry_url_hash')) {
            $platform = $this->connection->getDatabasePlatform();
            $this->addSql($platform instanceof AbstractMySQLPlatform
                ? 'DROP INDEX idx_entry_url_hash ON entry'
                : 'DROP INDEX idx_entry_url_hash');
        }
    }
}
