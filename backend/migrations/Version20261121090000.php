<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261121090000 extends AbstractMigration
{
    private const string INDEX = 'idx_entry_item_feed_date';

    public function getDescription(): string
    {
        return 'Cover the item copies a mark-all-read sweep spreads to by item, feed and date (#2404).';
    }

    public function up(Schema $schema): void
    {
        $this->skipIf($schema->getTable('entry')->hasIndex(self::INDEX), self::INDEX . ' already exists.');
        $this->requireSupportedPlatform();

        $this->addSql('CREATE INDEX ' . self::INDEX . ' ON entry (canonical_item_id, feed_id, effective_date)');
    }

    public function down(Schema $schema): void
    {
        $this->skipIf(!$schema->getTable('entry')->hasIndex(self::INDEX), self::INDEX . ' does not exist.');

        if ($this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform) {
            $this->addSql('DROP INDEX ' . self::INDEX . ' ON entry');

            return;
        }
        $this->addSql('DROP INDEX ' . self::INDEX);
    }

    /** MySQL commits each DDL statement, so a transaction would protect nothing. */
    public function isTransactional(): bool
    {
        return false;
    }

    private function requireSupportedPlatform(): void
    {
        $platform = $this->connection->getDatabasePlatform();
        $this->abortIf(
            !$platform instanceof AbstractMySQLPlatform && !$platform instanceof SQLitePlatform,
            \sprintf('No DDL defined for platform %s; only MySQL and SQLite are supported.', $platform::class),
        );
    }
}
