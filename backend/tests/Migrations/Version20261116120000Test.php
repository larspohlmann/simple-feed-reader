<?php

declare(strict_types=1);

namespace App\Tests\Migrations;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use DoctrineMigrations\Version20261116120000;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/** Today's per-window Trending stories become the seed stories of #2128, built here since the test schema is mapped. */
final class Version20261116120000Test extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        require_once \dirname(__DIR__, 2) . '/migrations/Version20261116120000.php';
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->connection->executeStatement('PRAGMA foreign_keys = ON');
        foreach ([...$this->oldSchema(), ...$this->oldRows()] as $statement) {
            $this->connection->executeStatement($statement);
        }
    }

    public function testStoriesKeepTheirIdsAndTakeTheirSnapshotsBuildTimeAsJoinTime(): void
    {
        $this->migrate('up');

        self::assertSame([
            ['id' => 11, 'first_joined_at' => '2026-11-16 09:50:00', 'last_joined_at' => '2026-11-16 09:50:00'],
            ['id' => 12, 'first_joined_at' => '2026-11-16 09:00:00', 'last_joined_at' => '2026-11-16 09:00:00'],
        ], $this->connection->fetchAllAssociative(
            'SELECT id, first_joined_at, last_joined_at FROM trending_story ORDER BY id',
        ));
    }

    public function testMembersArePlacedInTheirStorysWindow(): void
    {
        $this->migrate('up');

        self::assertSame([
            ['story_id' => 11, 'entry_id' => 1, 'in_day' => 1, 'in_week' => 0, 'joined_at' => '2026-11-16 09:50:00'],
            ['story_id' => 11, 'entry_id' => 2, 'in_day' => 1, 'in_week' => 0, 'joined_at' => '2026-11-16 09:50:00'],
            ['story_id' => 12, 'entry_id' => 1, 'in_day' => 0, 'in_week' => 1, 'joined_at' => '2026-11-16 09:00:00'],
            ['story_id' => 12, 'entry_id' => 2, 'in_day' => 0, 'in_week' => 1, 'joined_at' => '2026-11-16 09:00:00'],
            ['story_id' => 12, 'entry_id' => 3, 'in_day' => 0, 'in_week' => 1, 'joined_at' => '2026-11-16 09:00:00'],
        ], $this->connection->fetchAllAssociative(
            'SELECT story_id, entry_id, in_day, in_week, joined_at FROM trending_story_entry'
            . ' ORDER BY story_id, entry_id',
        ));
    }

    public function testMembersKeepTheirFeedSiteAndLink(): void
    {
        $this->migrate('up');

        self::assertSame(
            ['feed_id' => 7, 'article_site' => 'n.pr', 'shared_link_id' => 1],
            $this->connection->fetchAssociative(
                'SELECT feed_id, article_site, shared_link_id FROM trending_story_entry'
                . ' WHERE story_id = 11 AND entry_id = 1',
            ),
        );
    }

    public function testAHiddenEntryHidesEveryStoryHoldingIt(): void
    {
        $this->migrate('up');

        self::assertSame([
            ['user_id' => 5, 'story_id' => 12, 'hidden_at' => '2026-11-15 08:00:00'],
            ['user_id' => 6, 'story_id' => 11, 'hidden_at' => '2026-11-15 07:00:00'],
            ['user_id' => 6, 'story_id' => 12, 'hidden_at' => '2026-11-15 07:00:00'],
        ], $this->connection->fetchAllAssociative(
            'SELECT user_id, story_id, hidden_at FROM trending_story_hide ORDER BY user_id, story_id',
        ));
    }

    public function testTheHiddenEntryTableAndTheWindowColumnAreGone(): void
    {
        $this->migrate('up');

        self::assertSame(0, $this->rowCount(
            "SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = 'trending_hidden_entry'",
        ));
        self::assertNotContains('time_window', $this->columnsOf('trending_story'));
    }

    public function testAWindowWithNoSnapshotRowFallsBackToTheStorysLastSeen(): void
    {
        $this->connection->executeStatement("DELETE FROM trending_snapshot WHERE time_window = 'week'");

        $this->migrate('up');

        self::assertSame(
            ['first_joined_at' => '2026-11-16 08:30:00', 'last_joined_at' => '2026-11-16 08:30:00'],
            $this->connection->fetchAssociative(
                'SELECT first_joined_at, last_joined_at FROM trending_story WHERE id = 12',
            ),
        );
        self::assertSame('2026-11-16 08:30:00', $this->connection->fetchOne(
            'SELECT joined_at FROM trending_story_entry WHERE story_id = 12 AND entry_id = 3',
        ));
    }

    public function testTheNewTablesCascadeFromTheirStory(): void
    {
        $this->migrate('up');
        $this->connection->executeStatement(
            "INSERT INTO trending_story_follow (followed_at, last_opened_at, user_id, story_id)"
            . " VALUES ('2026-11-16 10:00:00', '2026-11-16 10:00:00', 5, 12)",
        );

        $this->connection->executeStatement('DELETE FROM trending_story WHERE id = 12');

        self::assertSame(0, $this->rowCount('SELECT COUNT(*) FROM trending_story_entry WHERE story_id = 12'));
        self::assertSame(0, $this->rowCount('SELECT COUNT(*) FROM trending_story_hide WHERE story_id = 12'));
        self::assertSame(0, $this->rowCount('SELECT COUNT(*) FROM trending_story_follow'));
    }

    public function testTheEntryTriggerSurvives(): void
    {
        $this->migrate('up');

        self::assertSame(1, $this->rowCount(
            "SELECT COUNT(*) FROM sqlite_master WHERE type = 'trigger' AND name = 'entry_search_entry_insert'",
        ));
    }

    public function testDownRestoresTheWindowColumnAndTheHiddenEntries(): void
    {
        $this->migrate('up');
        $this->migrate('down');

        self::assertSame(
            [['id' => 11, 'time_window' => 'day'], ['id' => 12, 'time_window' => 'week']],
            $this->connection->fetchAllAssociative('SELECT id, time_window FROM trending_story ORDER BY id'),
        );
        self::assertSame('2026-11-15 08:00:00', $this->connection->fetchOne(
            'SELECT hidden_at FROM trending_hidden_entry WHERE user_id = 5 AND entry_id = 3',
        ));
        self::assertSame(5, $this->rowCount('SELECT COUNT(*) FROM trending_story_entry'));
        self::assertNotContains('in_day', $this->columnsOf('trending_story_entry'));
        self::assertSame(0, $this->rowCount(
            "SELECT COUNT(*) FROM sqlite_master WHERE type = 'table'"
            . " AND name IN ('trending_story_hide', 'trending_story_follow')",
        ));
    }

    /** @return list<string> */
    private function oldSchema(): array
    {
        return [
            'CREATE TABLE app_user (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, email VARCHAR(180) NOT NULL)',
            'CREATE TABLE feed (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, url VARCHAR(2048) NOT NULL)',
            'CREATE TABLE entry (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, title VARCHAR(1024) NOT NULL)',
            'CREATE TABLE entry_search_log (title VARCHAR(1024) NOT NULL)',
            'CREATE TRIGGER entry_search_entry_insert AFTER INSERT ON entry'
            . ' BEGIN INSERT INTO entry_search_log (title) VALUES (new.title); END',
            'CREATE TABLE shared_link (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, url VARCHAR(2048) NOT NULL)',
            'CREATE TABLE trending_snapshot (time_window VARCHAR(8) NOT NULL, built_at DATETIME NOT NULL,'
            . ' entry_count INTEGER NOT NULL, story_count INTEGER NOT NULL, duration_ms INTEGER NOT NULL,'
            . ' peak_memory_bytes BIGINT NOT NULL, PRIMARY KEY (time_window))',
            'CREATE TABLE trending_story (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,'
            . ' time_window VARCHAR(8) NOT NULL,'
            . ' first_seen DATETIME NOT NULL, last_seen DATETIME NOT NULL)',
            'CREATE INDEX idx_trending_story_window ON trending_story (time_window)',
            'CREATE TABLE trending_story_entry (feed_id INTEGER NOT NULL, story_id INTEGER NOT NULL,'
            . ' entry_id INTEGER NOT NULL, article_site VARCHAR(255) DEFAULT NULL,'
            . ' shared_link_id INTEGER DEFAULT NULL REFERENCES shared_link (id) ON DELETE SET NULL,'
            . ' PRIMARY KEY (story_id, entry_id),'
            . ' CONSTRAINT FK_8C835665AA5D4036 FOREIGN KEY (story_id) REFERENCES trending_story (id) ON DELETE CASCADE,'
            . ' CONSTRAINT FK_8C835665BA364942 FOREIGN KEY (entry_id) REFERENCES entry (id) ON DELETE CASCADE)',
            'CREATE TABLE trending_hidden_entry (hidden_at DATETIME NOT NULL, user_id INTEGER NOT NULL,'
            . ' entry_id INTEGER NOT NULL, PRIMARY KEY (user_id, entry_id),'
            . ' CONSTRAINT FK_D6B1BD7CA76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE,'
            . ' CONSTRAINT FK_D6B1BD7CBA364942 FOREIGN KEY (entry_id) REFERENCES entry (id) ON DELETE CASCADE)',
        ];
    }

    /** @return list<string> */
    private function oldRows(): array
    {
        return [
            "INSERT INTO app_user (id, email) VALUES (5, 'five@example.test'), (6, 'six@example.test')",
            "INSERT INTO feed (id, url) VALUES (7, 'https://n.pr/feed')",
            "INSERT INTO entry (id, title) VALUES (1, 'one'), (2, 'two'), (3, 'three')",
            "INSERT INTO shared_link (id, url) VALUES (1, 'https://n.pr/x')",
            'INSERT INTO trending_snapshot (time_window, built_at, entry_count, story_count, duration_ms,'
            . " peak_memory_bytes) VALUES ('day', '2026-11-16 09:50:00', 2, 1, 10, 1000),"
            . " ('week', '2026-11-16 09:00:00', 3, 1, 10, 1000)",
            'INSERT INTO trending_story (id, time_window, first_seen, last_seen) VALUES'
            . " (11, 'day', '2026-11-16 06:00:00', '2026-11-16 09:40:00'),"
            . " (12, 'week', '2026-11-12 06:00:00', '2026-11-16 08:30:00')",
            'INSERT INTO trending_story_entry (feed_id, story_id, entry_id, article_site, shared_link_id) VALUES'
            . " (7, 11, 1, 'n.pr', 1), (7, 11, 2, NULL, NULL),"
            . " (7, 12, 1, 'n.pr', 1), (7, 12, 2, NULL, NULL), (7, 12, 3, NULL, NULL)",
            'INSERT INTO trending_hidden_entry (hidden_at, user_id, entry_id) VALUES'
            . " ('2026-11-15 08:00:00', 5, 3), ('2026-11-15 07:00:00', 6, 1), ('2026-11-15 09:00:00', 6, 2)",
        ];
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

    private function migrate(string $direction): void
    {
        $migration = new Version20261116120000($this->connection, new NullLogger());
        $schema = $this->connection->createSchemaManager()->introspectSchema();
        'up' === $direction ? $migration->up($schema) : $migration->down($schema);
        foreach ($migration->getSql() as $query) {
            $this->connection->executeStatement($query->getStatement());
        }
    }
}
