<?php

declare(strict_types=1);

namespace App\Tests\Migrations;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use DoctrineMigrations\Version20261116220000;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class Version20261116220000Test extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        require_once \dirname(__DIR__, 2) . '/migrations/Version20261116220000.php';
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->connection->executeStatement('PRAGMA foreign_keys = ON');
        foreach ([...$this->oldSchema(), ...$this->oldRows()] as $statement) {
            $this->connection->executeStatement($statement);
        }
    }

    public function testFlagsBecomePlacementRows(): void
    {
        $this->migrate('up');

        self::assertSame(
            [
                ['time_window' => 'day', 'entry_id' => 1, 'story_id' => 11],
                ['time_window' => 'day', 'entry_id' => 2, 'story_id' => 11],
                ['time_window' => 'week', 'entry_id' => 1, 'story_id' => 12],
                ['time_window' => 'week', 'entry_id' => 3, 'story_id' => 12],
            ],
            $this->placements(),
        );
        self::assertSame(5, $this->rowCount('SELECT COUNT(*) FROM trending_story_entry'));
    }

    public function testAnEntryFlaggedInTwoStoriesOfOneWindowGoesToTheLowerStoryId(): void
    {
        $this->connection->executeStatement('UPDATE trending_story_entry SET in_day = 1 WHERE entry_id = 2');

        $this->migrate('up');

        self::assertSame(
            [['time_window' => 'day', 'entry_id' => 2, 'story_id' => 11]],
            array_values(array_filter(
                $this->placements(),
                static fn (array $placement): bool => $placement['time_window'] === 'day'
                    && $placement['entry_id'] === 2,
            )),
        );
    }

    public function testTheFlagColumnsAreGone(): void
    {
        $this->migrate('up');

        self::assertSame(
            ['feed_id', 'story_id', 'entry_id', 'article_site', 'shared_link_id', 'joined_at'],
            $this->columnsOf('trending_story_entry'),
        );
        self::assertSame(1, $this->rowCount(
            "SELECT COUNT(*) FROM sqlite_master WHERE type = 'trigger' AND name = 'entry_search_entry_insert'",
        ));
    }

    public function testARunThatStoppedAfterDroppingTheDayFlagFinishesTheWeek(): void
    {
        $this->migrateUpStoppingBeforeTheLastStatement();

        $this->migrate('up');

        self::assertSame(
            [
                ['time_window' => 'day', 'entry_id' => 1, 'story_id' => 11],
                ['time_window' => 'day', 'entry_id' => 2, 'story_id' => 11],
                ['time_window' => 'week', 'entry_id' => 1, 'story_id' => 12],
                ['time_window' => 'week', 'entry_id' => 3, 'story_id' => 12],
            ],
            $this->placements(),
        );
        self::assertSame(
            ['feed_id', 'story_id', 'entry_id', 'article_site', 'shared_link_id', 'joined_at'],
            $this->columnsOf('trending_story_entry'),
        );
    }

    public function testDownPutsTheFlagsBack(): void
    {
        $flags = $this->flags();
        $this->migrate('up');

        $this->migrate('down');

        self::assertSame($flags, $this->flags());
        self::assertSame(0, $this->rowCount(
            "SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = 'trending_story_placement'",
        ));
    }

    /** @return list<string> */
    private function oldSchema(): array
    {
        return [
            'CREATE TABLE feed (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, url VARCHAR(2048) NOT NULL)',
            'CREATE TABLE entry (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, title VARCHAR(1024) NOT NULL)',
            'CREATE TABLE entry_search_log (title VARCHAR(1024) NOT NULL)',
            'CREATE TRIGGER entry_search_entry_insert AFTER INSERT ON entry'
            . ' BEGIN INSERT INTO entry_search_log (title) VALUES (new.title); END',
            'CREATE TABLE shared_link (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, url VARCHAR(2048) NOT NULL)',
            'CREATE TABLE trending_story (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,'
            . ' first_seen DATETIME NOT NULL, last_seen DATETIME NOT NULL,'
            . ' first_joined_at DATETIME NOT NULL, last_joined_at DATETIME NOT NULL)',
            'CREATE TABLE trending_story_entry (feed_id INTEGER NOT NULL, story_id INTEGER NOT NULL,'
            . ' entry_id INTEGER NOT NULL, article_site VARCHAR(255) DEFAULT NULL,'
            . ' shared_link_id INTEGER DEFAULT NULL, joined_at DATETIME NOT NULL,'
            . ' in_day BOOLEAN DEFAULT 0 NOT NULL, in_week BOOLEAN DEFAULT 0 NOT NULL,'
            . ' PRIMARY KEY (story_id, entry_id),'
            . ' CONSTRAINT FK_8C835665AA5D4036 FOREIGN KEY (story_id) REFERENCES trending_story (id) ON DELETE CASCADE,'
            . ' CONSTRAINT FK_8C835665BA364942 FOREIGN KEY (entry_id) REFERENCES entry (id) ON DELETE CASCADE,'
            . ' CONSTRAINT FK_8C8356658C730EC6 FOREIGN KEY (shared_link_id) REFERENCES shared_link (id)'
            . ' ON DELETE SET NULL)',
            'CREATE INDEX IDX_8C835665AA5D4036 ON trending_story_entry (story_id)',
            'CREATE INDEX IDX_8C835665BA364942 ON trending_story_entry (entry_id)',
            'CREATE INDEX idx_trending_story_entry_feed ON trending_story_entry (feed_id)',
            'CREATE INDEX IDX_8C8356658C730EC6 ON trending_story_entry (shared_link_id)',
        ];
    }

    /** @return list<string> */
    private function oldRows(): array
    {
        return [
            "INSERT INTO feed (id, url) VALUES (7, 'https://n.pr/feed')",
            "INSERT INTO entry (id, title) VALUES (1, 'one'), (2, 'two'), (3, 'three')",
            'INSERT INTO trending_story (id, first_seen, last_seen, first_joined_at, last_joined_at) VALUES'
            . " (11, '2026-11-16 06:00:00', '2026-11-16 09:40:00', '2026-11-16 09:50:00', '2026-11-16 09:50:00'),"
            . " (12, '2026-11-12 06:00:00', '2026-11-16 08:30:00', '2026-11-16 09:00:00', '2026-11-16 09:00:00')",
            'INSERT INTO trending_story_entry (feed_id, story_id, entry_id, joined_at, in_day, in_week) VALUES'
            . " (7, 11, 1, '2026-11-16 09:50:00', 1, 0), (7, 11, 2, '2026-11-16 09:50:00', 1, 0),"
            . " (7, 12, 1, '2026-11-16 09:00:00', 0, 1), (7, 12, 2, '2026-11-16 09:00:00', 0, 0),"
            . " (7, 12, 3, '2026-11-16 09:00:00', 0, 1)",
        ];
    }

    /** @return list<array{time_window: string, entry_id: int, story_id: int}> */
    private function placements(): array
    {
        /** @var list<array{time_window: string, entry_id: int|string, story_id: int|string}> $rows */
        $rows = $this->connection->fetchAllAssociative(
            'SELECT time_window, entry_id, story_id FROM trending_story_placement ORDER BY time_window, entry_id',
        );

        return array_map(
            static fn (array $row): array => [
                'time_window' => $row['time_window'],
                'entry_id' => (int) $row['entry_id'],
                'story_id' => (int) $row['story_id'],
            ],
            $rows,
        );
    }

    /** @return list<array<string, mixed>> */
    private function flags(): array
    {
        return $this->connection->fetchAllAssociative(
            'SELECT story_id, entry_id, in_day, in_week FROM trending_story_entry ORDER BY story_id, entry_id',
        );
    }

    /** @return list<mixed> */
    private function columnsOf(string $table): array
    {
        return array_column($this->connection->fetchAllAssociative("PRAGMA table_info('$table')"), 'name');
    }

    private function rowCount(string $sql): int
    {
        $count = $this->connection->fetchOne($sql);
        self::assertIsNumeric($count);

        return (int) $count;
    }

    private function migrateUpStoppingBeforeTheLastStatement(): void
    {
        $migration = new Version20261116220000($this->connection, new NullLogger());
        $migration->up($this->connection->createSchemaManager()->introspectSchema());
        foreach (\array_slice($migration->getSql(), 0, -1) as $query) {
            $this->connection->executeStatement($query->getStatement());
        }
        self::assertSame(
            ['feed_id', 'story_id', 'entry_id', 'article_site', 'shared_link_id', 'joined_at', 'in_week'],
            $this->columnsOf('trending_story_entry'),
        );
    }

    private function migrate(string $direction): void
    {
        $migration = new Version20261116220000($this->connection, new NullLogger());
        $schema = $this->connection->createSchemaManager()->introspectSchema();
        'up' === $direction ? $migration->up($schema) : $migration->down($schema);
        foreach ($migration->getSql() as $query) {
            $this->connection->executeStatement($query->getStatement());
        }
    }
}
