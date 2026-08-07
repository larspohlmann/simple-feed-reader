<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds entry_state.is_viewed and entry_state.viewed_at for #307: "viewed"
 * records an active open, which read_at cannot express (the bulk mark-read
 * sweep stamps it too).
 *
 * PLATFORM-AWARE DDL: tests build their schema from ORM metadata and never
 * execute a migration, so a dialect error here is caught only by CI's
 * migrate-from-empty leg.
 *
 * ADDITIVE ONLY. No backfill: existing read_at data cannot distinguish a
 * click from a sweep, so every existing row reads as "never viewed".
 */
final class Version20260807120000 extends AbstractMigration
{
    private const TABLE = 'entry_state';

    public function getDescription(): string
    {
        return 'Add entry_state.is_viewed and entry_state.viewed_at (#307).';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->skipIf(
            $schema->hasTable(self::TABLE)
                && $schema->getTable(self::TABLE)->hasColumn('is_viewed')
                && $schema->getTable(self::TABLE)->hasColumn('viewed_at'),
            'entry_state viewed columns already exist; nothing to do.',
        );

        $platform = $this->connection->getDatabasePlatform();

        if ($platform instanceof AbstractMySQLPlatform) {
            $this->addSql('ALTER TABLE entry_state ADD is_viewed TINYINT NOT NULL, ADD viewed_at DATETIME DEFAULT NULL');

            return;
        }

        if ($platform instanceof SQLitePlatform) {
            // No DEFAULT: the sibling booleans on this table (is_read, is_favorite,
            // is_kept) were created the same way and the ORM metadata declares none,
            // so schema:validate demands none here either. SQLite's own rule is that
            // ADD COLUMN ... NOT NULL needs a DEFAULT unless the table is empty; CI's
            // migrate-from-empty leg satisfies that, and production runs MySQL, not
            // SQLite, so this only bites a developer applying it to a populated local
            // dev.db (recreate that database rather than adding a default here).
            $this->addSql('ALTER TABLE entry_state ADD COLUMN is_viewed BOOLEAN NOT NULL');
            $this->addSql('ALTER TABLE entry_state ADD COLUMN viewed_at DATETIME DEFAULT NULL');

            return;
        }

        $this->abortIf(true, \sprintf(
            'No DDL defined for platform %s; only MySQL and SQLite are supported.',
            $platform::class,
        ));
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE entry_state DROP COLUMN is_viewed');
        $this->addSql('ALTER TABLE entry_state DROP COLUMN viewed_at');
    }
}
