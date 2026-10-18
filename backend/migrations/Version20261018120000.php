<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261018120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Widen idx_entry_feed_effective with url_hash so the duplicate collapse stays index-only (#1679)';
    }

    public function up(Schema $schema): void
    {
        $entry = $schema->getTable('entry');
        $this->skipIf($entry->hasIndex('idx_entry_feed_effective_hash'), 'Index already widened.');

        if ($entry->hasIndex('idx_entry_feed_effective')) {
            $this->addSql($this->dropIndex('idx_entry_feed_effective'));
        }
        $this->addSql('CREATE INDEX idx_entry_feed_effective_hash ON entry (feed_id, effective_date, url_hash)');
    }

    public function down(Schema $schema): void
    {
        $entry = $schema->getTable('entry');
        $this->skipIf(!$entry->hasIndex('idx_entry_feed_effective_hash'), 'Index is not widened.');

        $this->addSql($this->dropIndex('idx_entry_feed_effective_hash'));
        if (!$entry->hasIndex('idx_entry_feed_effective')) {
            $this->addSql('CREATE INDEX idx_entry_feed_effective ON entry (feed_id, effective_date)');
        }
    }

    private function dropIndex(string $name): string
    {
        return $this->mysql()
            ? sprintf('DROP INDEX %s ON entry', $name)
            : sprintf('DROP INDEX %s', $name);
    }

    /** Refuses any platform but the two supported ones: better a refusal than DDL nobody tested. */
    private function mysql(): bool
    {
        $platform = $this->connection->getDatabasePlatform();

        $this->abortIf(
            !$platform instanceof AbstractMySQLPlatform && !$platform instanceof SQLitePlatform,
            \sprintf('No DDL defined for platform %s; only MySQL and SQLite are supported.', $platform::class),
        );

        return $platform instanceof AbstractMySQLPlatform;
    }
}
