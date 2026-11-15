<?php

declare(strict_types=1);

namespace App\Tests\Migrations;

use App\Service\Foundation\Url\UrlNormalizer;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\Migrations\AbstractMigration;
use Doctrine\Migrations\Exception\AbortMigration;
use DoctrineMigrations\Version20261115140000;
use DoctrineMigrations\Version20261115140100;
use DoctrineMigrations\Version20261115140200;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/** The three #2239 migrations over the pre-#2239 tables, built here, since the test schema comes from the mapping. */
final class SharedLinkMigrationsTest extends TestCase
{
    private const string URL = 'https://news.example/story';

    private Connection $connection;

    protected function setUp(): void
    {
        foreach (['140000', '140100', '140200'] as $version) {
            require_once \dirname(__DIR__, 2) . '/migrations/Version20261115' . $version . '.php';
        }
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->connection->executeStatement('PRAGMA foreign_keys = ON');
        $this->createPreSharedLinkTables();
    }

    public function testTheOldestCardWinsTheRow(): void
    {
        $this->card($this->entry('2026-11-03 10:00:00'), self::URL, 'Newest');
        $this->quote($this->entry('2026-11-01 10:00:00'), self::URL, 'Quote at the same time');
        $this->card($this->entry('2026-11-01 10:00:00'), self::URL, 'Oldest card');
        $this->card($this->entry('2026-11-02 10:00:00'), self::URL, 'Middle');

        $this->migrateUp();

        self::assertSame(
            [['url' => self::URL, 'title' => 'Oldest card', 'created_at' => '2026-11-01 10:00:00']],
            $this->connection->fetchAllAssociative('SELECT url, title, created_at FROM shared_link'),
        );
    }

    public function testAPreviewIsCopiedVerbatim(): void
    {
        $this->card($this->entry('2026-11-01 10:00:00'), self::URL, "O'Brien says \"no\" \\ ; DROP", 'Über – “quoted”');

        $this->migrateUp();

        self::assertSame(
            ["O'Brien says \"no\" \\ ; DROP", 'Über – “quoted”'],
            array_values((array) $this->connection->fetchAssociative('SELECT title, description FROM shared_link')),
        );
    }

    public function testEveryCardAndQuoteIsLinked(): void
    {
        $this->card($this->entry('2026-11-01 10:00:00'), self::URL, 'One');
        $this->card($this->entry('2026-11-01 11:00:00'), 'https://other.example/a', 'Two');
        $quoting = $this->entry('2026-11-01 12:00:00');
        $this->quote($quoting, 'https://quoted.example/b', 'Quoted');

        $this->migrateUp();

        self::assertSame(0, $this->rowCount('SELECT COUNT(*) FROM link_card WHERE shared_link_id IS NULL'));
        self::assertSame(3, $this->rowCount('SELECT COUNT(*) FROM shared_link'));
        self::assertSame(
            'https://quoted.example/b',
            $this->connection->fetchOne(
                'SELECT link.url FROM entry JOIN shared_link link ON link.id = entry.quoted_shared_link_id'
                . ' WHERE entry.id = ?',
                [$quoting],
            ),
        );
    }

    public function testTrackingVariantsShareARow(): void
    {
        $this->card($this->entry('2026-11-01 10:00:00'), self::URL . '?utm_source=x', 'Tracked');
        $this->card($this->entry('2026-11-02 10:00:00'), self::URL, 'Bare');

        $this->migrateUp();

        self::assertSame(1, $this->rowCount('SELECT COUNT(*) FROM shared_link'));
        self::assertSame(
            (new UrlNormalizer())->hash(self::URL),
            $this->connection->fetchOne('SELECT url_hash FROM shared_link'),
        );
        self::assertSame(1, $this->rowCount('SELECT COUNT(DISTINCT shared_link_id) FROM link_card'));
    }

    public function testABareUrlDescriptionIsNotCarried(): void
    {
        $this->card($this->entry('2026-11-01 10:00:00'), self::URL, 'Bare', ' https://news.example/story ');
        $this->card($this->entry('2026-11-01 11:00:00'), 'https://other.example/a', 'Said', 'Words about it');

        $this->migrateUp();

        self::assertSame(
            [null, 'Words about it'],
            $this->connection->fetchFirstColumn('SELECT description FROM shared_link ORDER BY id'),
        );
    }

    public function testACardWhoseUrlDoesNotHashIsDeleted(): void
    {
        $this->card($this->entry('2026-11-01 10:00:00'), 'not a web address', 'Broken');
        $this->card($this->entry('2026-11-01 11:00:00'), self::URL, 'Fine');

        $this->migrateUp();

        self::assertSame(['Fine'], $this->connection->fetchFirstColumn(
            'SELECT link.title FROM link_card card JOIN shared_link link ON link.id = card.shared_link_id',
        ));
    }

    public function testTheEntryTriggerSurvives(): void
    {
        $this->migrateUp();

        self::assertSame(1, $this->rowCount(
            "SELECT COUNT(*) FROM sqlite_master WHERE type = 'trigger' AND name = 'entry_search_entry_insert'",
        ));
        $this->entry('2026-11-05 10:00:00');
        self::assertSame(1, $this->rowCount('SELECT COUNT(*) FROM entry_search_log'));
    }

    public function testTheSlimTablesHoldOnlyTheReference(): void
    {
        $this->migrateUp();

        self::assertSame(
            ['id', 'position', 'entry_id', 'thread_post_id', 'shared_link_id'],
            array_column($this->connection->fetchAllAssociative('PRAGMA table_info(link_card)'), 'name'),
        );
        self::assertNotContains(
            'quoted_link_card',
            array_column($this->connection->fetchAllAssociative('PRAGMA table_info(entry)'), 'name'),
        );
    }

    public function testDownRestoresThePreviews(): void
    {
        $older = $this->entry('2026-11-01 10:00:00');
        $newer = $this->entry('2026-11-02 10:00:00');
        $this->card($older, self::URL, 'Oldest');
        $this->card($newer, self::URL, 'Newer');
        $this->quote($newer, 'https://quoted.example/b', 'Quoted');
        $this->migrateUp();

        $this->execute(new Version20261115140200($this->connection, new NullLogger()), 'down');
        $this->execute(new Version20261115140100($this->connection, new NullLogger()), 'down');

        self::assertSame(
            ['Oldest', 'Oldest'],
            $this->connection->fetchFirstColumn('SELECT title FROM link_card ORDER BY id'),
        );
        self::assertEquals(
            ['url' => 'https://quoted.example/b', 'title' => 'Quoted', 'description' => null, 'imageUrl' => null,
                'siteName' => 'quoted.example'],
            json_decode($this->quotedCardOf($newer), true, flags: \JSON_THROW_ON_ERROR),
        );
        self::assertSame(0, $this->rowCount('SELECT COUNT(*) FROM shared_link'));
        self::assertSame(0, $this->rowCount('SELECT COUNT(*) FROM link_card WHERE shared_link_id IS NOT NULL'));
    }

    public function testTheSlimMigrationLinksCardsWrittenAfterTheMerge(): void
    {
        $this->card($this->entry('2026-11-01 10:00:00'), self::URL, 'Merged first');
        $this->execute(new Version20261115140000($this->connection, new NullLogger()), 'up');
        $this->execute(new Version20261115140100($this->connection, new NullLogger()), 'up');
        $this->card($this->entry('2026-11-03 10:00:00'), self::URL . '?utm_source=late', 'Written late');
        $this->card($this->entry('2026-11-03 11:00:00'), 'https://late.example/a', 'Late newest');
        $this->card($this->entry('2026-11-03 10:30:00'), 'https://late.example/a', 'Late oldest');
        $quoting = $this->entry('2026-11-03 12:00:00');
        $this->quote($quoting, 'https://late.example/a', 'Late quote');

        $this->execute(new Version20261115140200($this->connection, new NullLogger()), 'up');

        self::assertSame(0, $this->rowCount('SELECT COUNT(*) FROM link_card WHERE shared_link_id IS NULL'));
        self::assertEquals(
            [
                ['id' => 1, 'url' => self::URL, 'title' => 'Merged first'],
                ['id' => 2, 'url' => 'https://late.example/a', 'title' => 'Late oldest'],
            ],
            $this->connection->fetchAllAssociative('SELECT id, url, title FROM shared_link ORDER BY id'),
        );
        self::assertSame(2, $this->rowCount(
            'SELECT quoted_shared_link_id FROM entry WHERE id = ' . $quoting,
        ));
    }

    public function testAFilledTableAbortsTheMerge(): void
    {
        $this->execute(new Version20261115140000($this->connection, new NullLogger()), 'up');
        $this->connection->executeStatement(
            "INSERT INTO shared_link (url, url_hash, title, site_name, created_at) VALUES ('u', 'h', 't', 's', 'x')",
        );

        $this->expectException(AbortMigration::class);

        $this->execute(new Version20261115140100($this->connection, new NullLogger()), 'up');
    }

    private function migrateUp(): void
    {
        $this->execute(new Version20261115140000($this->connection, new NullLogger()), 'up');
        $this->execute(new Version20261115140100($this->connection, new NullLogger()), 'up');
        $this->execute(new Version20261115140200($this->connection, new NullLogger()), 'up');
    }

    private function execute(AbstractMigration $migration, string $direction): void
    {
        $schema = $this->connection->createSchemaManager()->introspectSchema();
        'up' === $direction ? $migration->up($schema) : $migration->down($schema);
        foreach ($migration->getSql() as $query) {
            $this->connection->executeStatement($query->getStatement());
        }
    }

    private function entry(string $createdAt): int
    {
        $this->connection->insert('entry', ['title' => 'Post', 'created_at' => $createdAt]);

        return (int) $this->connection->lastInsertId();
    }

    private function card(int $entryId, string $url, string $title, ?string $description = null): void
    {
        $this->connection->insert('link_card', [
            'entry_id' => $entryId,
            'position' => $this->rowCount('SELECT COUNT(*) FROM link_card WHERE entry_id = ' . $entryId),
            'url' => $url,
            'title' => $title,
            'description' => $description,
            'site_name' => (string) parse_url($url, \PHP_URL_HOST),
        ]);
    }

    private function quote(int $entryId, string $url, string $title): void
    {
        $this->connection->update('entry', ['quoted_link_card' => json_encode([
            'url' => $url,
            'title' => $title,
            'description' => null,
            'imageUrl' => null,
            'siteName' => (string) parse_url($url, \PHP_URL_HOST),
        ], \JSON_THROW_ON_ERROR)], ['id' => $entryId]);
    }

    private function quotedCardOf(int $entryId): string
    {
        $stored = $this->connection->fetchOne('SELECT quoted_link_card FROM entry WHERE id = ?', [$entryId]);
        self::assertIsString($stored);

        return $stored;
    }

    private function rowCount(string $sql): int
    {
        $count = $this->connection->fetchOne($sql);
        self::assertIsNumeric($count);

        return (int) $count;
    }

    private function createPreSharedLinkTables(): void
    {
        $statements = [
            'CREATE TABLE entry (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, title VARCHAR(1024) NOT NULL,'
            . ' created_at DATETIME NOT NULL, quoted_link_card CLOB DEFAULT NULL)',
            'CREATE TABLE entry_search_log (title VARCHAR(1024) NOT NULL)',
            'CREATE TRIGGER entry_search_entry_insert AFTER INSERT ON entry'
            . ' BEGIN INSERT INTO entry_search_log (title) VALUES (new.title); END',
            'CREATE TABLE thread_post (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, entry_id INTEGER NOT NULL,'
            . ' FOREIGN KEY (entry_id) REFERENCES entry (id) ON DELETE CASCADE)',
            'CREATE TABLE trending_story_entry (feed_id INTEGER NOT NULL, story_id INTEGER NOT NULL,'
            . ' entry_id INTEGER NOT NULL, article_site VARCHAR(255) DEFAULT NULL, PRIMARY KEY (story_id, entry_id))',
            'CREATE TABLE link_card (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, position SMALLINT NOT NULL,'
            . ' entry_id INTEGER NOT NULL, url VARCHAR(2048) NOT NULL, title VARCHAR(500) NOT NULL,'
            . ' description CLOB DEFAULT NULL, image_url VARCHAR(2048) DEFAULT NULL, site_name VARCHAR(255) NOT NULL,'
            . ' thread_post_id INTEGER DEFAULT NULL REFERENCES thread_post (id) ON DELETE CASCADE,'
            . ' CONSTRAINT FK_A7530A7ABA364942 FOREIGN KEY (entry_id) REFERENCES entry (id) ON DELETE CASCADE)',
            'CREATE UNIQUE INDEX uniq_link_card_entry_position ON link_card (entry_id, position)',
            'CREATE INDEX IDX_A7530A7ABA364942 ON link_card (entry_id)',
            'CREATE INDEX IDX_A7530A7AFA33EB65 ON link_card (thread_post_id)',
        ];
        foreach ($statements as $statement) {
            $this->connection->executeStatement($statement);
        }
    }
}
