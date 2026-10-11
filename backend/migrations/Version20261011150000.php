<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** A post that only might share a link is queued too: its completion finds no link and marks it done. */
final class Version20261011150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Queue the link cards of the posts stored before #1502, so opening an entry writes nothing (#1513).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            "INSERT INTO post_enrichment (entry_id, kind, queued_at) SELECT entry.id, 'link_cards', "
            . $this->utcNow() . ' FROM entry'
            . " WHERE entry.title_derived = 1 AND entry.guid NOT LIKE 'at://%' AND entry.content_html LIKE '%http%'"
            . ' AND NOT EXISTS (SELECT 1 FROM post_enrichment queued WHERE queued.entry_id = entry.id)'
            . ' AND NOT EXISTS (SELECT 1 FROM link_card WHERE link_card.entry_id = entry.id)',
        );
    }

    /** A backfilled row looks like any other queued row, and the older schema reads it as well. */
    public function down(Schema $schema): void
    {
    }

    /** Refuses any platform but the two supported ones: better a refusal than SQL nobody tested. */
    private function utcNow(): string
    {
        $platform = $this->connection->getDatabasePlatform();

        $this->abortIf(
            !$platform instanceof AbstractMySQLPlatform && !$platform instanceof SQLitePlatform,
            \sprintf('No SQL defined for platform %s; only MySQL and SQLite are supported.', $platform::class),
        );

        return $platform instanceof AbstractMySQLPlatform ? 'UTC_TIMESTAMP()' : 'CURRENT_TIMESTAMP';
    }
}
