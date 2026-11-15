<?php

declare(strict_types=1);

namespace App\Tests\Migrations;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use DoctrineMigrations\Version20261115220000;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/** The resolution table beside the #2239 shared_link table, built here, since the test schema comes from the mapping. */
final class Version20261115220000Test extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        require_once \dirname(__DIR__, 2) . '/migrations/Version20261115220000.php';
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->connection->executeStatement('PRAGMA foreign_keys = ON');
        $statements = [
            'CREATE TABLE entry (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, title VARCHAR(1024) NOT NULL)',
            'CREATE TABLE entry_search_log (title VARCHAR(1024) NOT NULL)',
            'CREATE TRIGGER entry_search_entry_insert AFTER INSERT ON entry'
            . ' BEGIN INSERT INTO entry_search_log (title) VALUES (new.title); END',
            'CREATE TABLE shared_link (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, url VARCHAR(2048) NOT NULL,'
            . ' url_hash VARCHAR(64) NOT NULL, title VARCHAR(500) NOT NULL, site_name VARCHAR(255) NOT NULL,'
            . ' created_at DATETIME NOT NULL)',
            "INSERT INTO shared_link (url, url_hash, title, site_name, created_at)"
            . " VALUES ('https://n.pr/x', 'h', 't', 's', '2026-11-15 10:00:00')",
        ];
        foreach ($statements as $statement) {
            $this->connection->executeStatement($statement);
        }
    }

    public function testAnExistingLinkIsNotQueued(): void
    {
        $this->migrate('up');

        self::assertSame(0, $this->rowCount('SELECT COUNT(*) FROM shared_link_resolution'));
    }

    public function testAQueuedLinkStartsWithNoAttempt(): void
    {
        $this->migrate('up');
        $this->connection->executeStatement(
            "INSERT INTO shared_link_resolution (shared_link_id, status) VALUES (1, 'pending')",
        );

        self::assertSame(
            ['resolved_url' => null, 'resolved_url_hash' => null, 'attempts' => 0, 'retry_at' => null],
            $this->connection->fetchAssociative(
                'SELECT resolved_url, resolved_url_hash, attempts, retry_at FROM shared_link_resolution',
            ),
        );
    }

    public function testADeletedLinkTakesItsResolution(): void
    {
        $this->migrate('up');
        $this->connection->executeStatement(
            "INSERT INTO shared_link_resolution (shared_link_id, status) VALUES (1, 'pending')",
        );

        $this->connection->executeStatement('DELETE FROM shared_link');

        self::assertSame(0, $this->rowCount('SELECT COUNT(*) FROM shared_link_resolution'));
    }

    public function testTheStatusIsIndexed(): void
    {
        $this->migrate('up');

        self::assertSame(['status'], array_column(
            $this->connection->fetchAllAssociative("PRAGMA index_info('idx_shared_link_resolution_status')"),
            'name',
        ));
    }

    public function testTheEntryTriggerSurvives(): void
    {
        $this->migrate('up');

        self::assertSame(1, $this->rowCount(
            "SELECT COUNT(*) FROM sqlite_master WHERE type = 'trigger' AND name = 'entry_search_entry_insert'",
        ));
    }

    public function testDownDropsTheTable(): void
    {
        $this->migrate('up');
        $this->migrate('down');

        self::assertSame(0, $this->rowCount(
            "SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = 'shared_link_resolution'",
        ));
    }

    private function rowCount(string $sql): int
    {
        $count = $this->connection->fetchOne($sql);
        self::assertIsNumeric($count);

        return (int) $count;
    }

    private function migrate(string $direction): void
    {
        $migration = new Version20261115220000($this->connection, new NullLogger());
        $schema = $this->connection->createSchemaManager()->introspectSchema();
        'up' === $direction ? $migration->up($schema) : $migration->down($schema);
        foreach ($migration->getSql() as $query) {
            $this->connection->executeStatement($query->getStatement());
        }
    }
}
