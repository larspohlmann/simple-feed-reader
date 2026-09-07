<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Add entry.media and entry.attachments (#906): the feed-declared visual media
 * and playable enclosures, stored as JSON. PLATFORM-AWARE DDL because the JSON
 * type is a native column on MySQL and CLOB on SQLite — the same split the
 * fresh-table migrations carry.
 */
final class Version20260907112243 extends AbstractMigration
{
    private const TABLE = 'entry';

    public function getDescription(): string
    {
        return 'Add entry.media and entry.attachments JSON columns (#906)';
    }

    public function up(Schema $schema): void
    {
        $table = $schema->getTable(self::TABLE);
        $this->skipIf(
            $table->hasColumn('media') && $table->hasColumn('attachments'),
            'entry.media and entry.attachments already exist.',
        );

        $platform = $this->connection->getDatabasePlatform();

        if ($platform instanceof AbstractMySQLPlatform) {
            $this->addSql('ALTER TABLE entry ADD media JSON DEFAULT NULL, ADD attachments JSON DEFAULT NULL');

            return;
        }

        if ($platform instanceof SQLitePlatform) {
            $this->addSql('ALTER TABLE entry ADD COLUMN media CLOB DEFAULT NULL');
            $this->addSql('ALTER TABLE entry ADD COLUMN attachments CLOB DEFAULT NULL');

            return;
        }

        throw new \RuntimeException('Unsupported database platform for the entry media migration.');
    }

    public function down(Schema $schema): void
    {
        $this->skipIf(!$schema->getTable(self::TABLE)->hasColumn('media'), 'entry.media does not exist.');

        $platform = $this->connection->getDatabasePlatform();

        if ($platform instanceof AbstractMySQLPlatform) {
            $this->addSql('ALTER TABLE entry DROP media, DROP attachments');

            return;
        }

        if ($platform instanceof SQLitePlatform) {
            $this->addSql('ALTER TABLE entry DROP COLUMN media');
            $this->addSql('ALTER TABLE entry DROP COLUMN attachments');

            return;
        }

        throw new \RuntimeException('Unsupported database platform for the entry media migration.');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
