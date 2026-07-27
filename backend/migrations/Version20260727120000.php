<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds the feed-supplied image to entry. App\Service\Parser\ItemImageExtractor
 * has always extracted it and App\Service\EntryIngestor has always dropped it,
 * so the magazine layout (#148) had almost nothing to render.
 *
 * PLATFORM-AWARE DDL, for the same reason Version20260726120000 is: tests
 * build their schema from ORM metadata and never execute a migration, so a
 * dialect error here is caught only by CI's dedicated migrate-from-empty leg.
 *
 * ADDITIVE ONLY. Three nullable columns, no DROP, no narrowing, no constraint
 * on existing data — every entry ingested before this ships simply has no
 * recorded image, which is exactly what NULL means here.
 */
final class Version20260727120000 extends AbstractMigration
{
    private const TABLE = 'entry';

    public function getDescription(): string
    {
        return 'Add entry.image_url, entry.image_width and entry.image_height';
    }

    // Non-transactional, matching the global doctrine_migrations.transactional:
    // false policy the rest of this chain uses.
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

        // `ADD column` (MySQL) vs `ADD COLUMN column` (SQLite); both take the
        // same nullable type.
        $verb = $mysql ? 'ADD' : 'ADD COLUMN';

        // Per-column idempotence for a database baselined from
        // doctrine:schema:create, where ORM metadata already produced the columns.
        foreach ([
            ['image_url', 'VARCHAR(2048) DEFAULT NULL'],
            ['image_width', 'INTEGER DEFAULT NULL'],
            ['image_height', 'INTEGER DEFAULT NULL'],
        ] as [$column, $definition]) {
            if ($schema->hasTable(self::TABLE) && $schema->getTable(self::TABLE)->hasColumn($column)) {
                continue;
            }

            $this->addSql(\sprintf('ALTER TABLE %s %s %s %s', self::TABLE, $verb, $column, $definition));
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE entry DROP COLUMN image_url');
        $this->addSql('ALTER TABLE entry DROP COLUMN image_width');
        $this->addSql('ALTER TABLE entry DROP COLUMN image_height');
    }
}
