<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds feed.source_format ('xml'|'scraped'), recording whether a feed's body
 * is turned into entries by parsing RSS/Atom or by scraping an HTML listing
 * (the planned HtmlItemExtractor). Open string, matching
 * App\Service\Discovery\FeedCandidate::$format.
 *
 * PLATFORM-AWARE DDL, for the same reason Version20260723120000 is: a
 * `doctrine:migrations:diff` run on a SQLite dev box emits SQLite-only DDL
 * MySQL cannot parse, and the suite would not catch it because tests build
 * their schema from ORM metadata rather than by executing this chain.
 *
 * ADDITIVE ONLY. One NOT NULL column with DEFAULT 'xml' — every feed that
 * exists today is RSS/Atom, so the default backfills existing rows correctly
 * with no separate per-row pass needed.
 */
final class Version20260723200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return "Add feed.source_format ('xml'|'scraped') for the HTML scraper";
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
        if (!$schema->hasTable('feed') || $schema->getTable('feed')->hasColumn('source_format')) {
            return;
        }

        // `ADD source_format` (MySQL) vs `ADD COLUMN source_format` (SQLite);
        // both take the same NOT NULL DEFAULT 'xml'.
        $verb = $mysql ? 'ADD' : 'ADD COLUMN';
        $this->addSql(\sprintf(
            "ALTER TABLE feed %s source_format VARCHAR(20) DEFAULT 'xml' NOT NULL",
            $verb,
        ));
    }

    public function down(Schema $schema): void
    {
        if ($schema->hasTable('feed') && $schema->getTable('feed')->hasColumn('source_format')) {
            $this->addSql('ALTER TABLE feed DROP COLUMN source_format');
        }
    }
}
