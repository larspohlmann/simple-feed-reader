<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds feed.image_url, the image a feed publishes for itself (#568).
 *
 * PLATFORM-AWARE DDL: a `doctrine:migrations:diff` on a SQLite dev box emits
 * SQLite-only DDL MySQL cannot parse, and the suite would not catch it because
 * tests build their schema from ORM metadata rather than by executing this
 * chain. Only CI's migrate-from-empty leg runs this.
 *
 * ADDITIVE ONLY. One nullable column: every feed that exists today predates
 * the field and correctly holds NULL until its next successful fetch.
 */
final class Version20260823120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add feed.image_url for the image a feed publishes for itself (#568)';
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
        if (!$schema->hasTable('feed') || $schema->getTable('feed')->hasColumn('image_url')) {
            return;
        }

        // `ADD image_url` (MySQL) vs `ADD COLUMN image_url` (SQLite).
        $verb = $mysql ? 'ADD' : 'ADD COLUMN';
        $this->addSql(\sprintf('ALTER TABLE feed %s image_url VARCHAR(2048) DEFAULT NULL', $verb));
    }

    public function down(Schema $schema): void
    {
        if ($schema->hasTable('feed') && $schema->getTable('feed')->hasColumn('image_url')) {
            $this->addSql('ALTER TABLE feed DROP COLUMN image_url');
        }
    }
}
