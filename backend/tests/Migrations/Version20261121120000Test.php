<?php

declare(strict_types=1);

namespace App\Tests\Migrations;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\ParameterType;
use Doctrine\Migrations\Exception\SkipMigration;
use DoctrineMigrations\Version20261121120000;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class Version20261121120000Test extends TestCase
{
    private const string AUTHOR = 'https://lemmy.world/u/PerfectDark';
    private const string LINK = 'https://example.com/story';

    private Connection $connection;

    protected function setUp(): void
    {
        require_once \dirname(__DIR__, 2) . '/migrations/Version20261121120000.php';
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        foreach ($this->buildOldSchema() as $statement) {
            $this->connection->executeStatement($statement);
        }
    }

    public function testCopiesOfOneLinkCrossPostShareAHash(): void
    {
        $link = '<a href="' . self::LINK . '">' . self::LINK . '</a>';
        $this->insertItem(1, 'lemmy', self::AUTHOR, 'First title', '<p>' . $link . '</p>');
        $this->insertItem(2, 'lemmy', self::AUTHOR, 'Second title', $link);
        $this->insertItem(3, 'lemmy', self::AUTHOR . '/', 'Third title', '<p>' . $link . '</p>');

        $this->migrate('up');

        $hashes = array_values($this->readHashes());
        self::assertNotNull($hashes[0]);
        self::assertSame([$hashes[0], $hashes[0]], [$hashes[1], $hashes[2]]);
    }

    public function testATextCrossPostSharesAHashAndAnotherTitleDoesNot(): void
    {
        $this->insertItem(1, 'lemmy', self::AUTHOR, 'Same words', '<p>Body one</p><p>Body two</p>');
        $this->insertItem(2, 'lemmy', self::AUTHOR, 'Same  Words', '<div>Body one</div><div>Body two</div>');
        $this->insertItem(3, 'lemmy', self::AUTHOR, 'Other words', '<p>Body one</p><p>Body two</p>');

        $this->migrate('up');

        $hashes = array_values($this->readHashes());
        self::assertNotNull($hashes[0]);
        self::assertSame($hashes[0], $hashes[1]);
        self::assertNotNull($hashes[2]);
        self::assertNotSame($hashes[0], $hashes[2]);
    }

    public function testAMastodonItemStaysUnkeyed(): void
    {
        $this->insertItem(1, 'mastodon', self::AUTHOR, 'A toot', '<p>text</p>');

        $this->migrate('up');

        self::assertSame([1 => null], $this->readHashes());
    }

    public function testAnItemKeysFromItsLowestIdCopy(): void
    {
        $this->insertItem(1, 'lemmy', self::AUTHOR, 'Original', '<p>Body</p>');
        $this->connection->insert('entry', [
            'id' => 9, 'canonical_item_id' => 1, 'author_url' => self::AUTHOR, 'title' => 'Edited', 'content_html' => '<p>Other</p>',
        ]);
        $this->insertItem(2, 'lemmy', self::AUTHOR, 'Original', '<p>Body</p>');

        $this->migrate('up');

        $hashes = $this->readHashes();
        self::assertNotNull($hashes[1]);
        self::assertSame($hashes[2], $hashes[1]);
    }

    public function testMoreThanOneBatchIsKeyed(): void
    {
        for ($id = 1; $id <= 1001; ++$id) {
            $this->insertItem($id, 'lemmy', 'https://lemmy.world/u/user' . $id, 'Title', '<p>Body</p>');
        }

        $this->migrate('up');

        self::assertCount(1001, array_unique(array_filter($this->readHashes())));
    }

    public function testDownDropsTheColumn(): void
    {
        $this->migrate('up');

        $this->migrate('down');

        self::assertNotContains('cross_post_hash', $this->readColumns());
    }

    public function testRunningUpTwiceSkips(): void
    {
        $this->migrate('up');

        $this->expectException(SkipMigration::class);

        $this->migrate('up');
    }

    /** @return array<int, ?string> */
    private function readHashes(): array
    {
        /** @var array<int, ?string> $hashes */
        $hashes = $this->connection->fetchAllKeyValue('SELECT id, cross_post_hash FROM canonical_item ORDER BY id');

        return $hashes;
    }

    /** @return list<string> */
    private function readColumns(): array
    {
        return array_map(
            static fn ($column): string => $column->getName(),
            $this->connection->createSchemaManager()->introspectTable('canonical_item')->getColumns(),
        );
    }

    private function insertItem(int $id, string $kind, string $authorUrl, string $title, string $contentHtml): void
    {
        $this->connection->insert('canonical_item', [
            'id' => $id, 'kind' => $kind, 'canonical_key' => 'key' . $id, 'key_hash' => 'hash' . $id,
            'score' => 0, 'comment_count' => 0, 'self_reply_count' => 0, 'created_at' => '2026-11-21 00:00:00',
        ]);
        $this->connection->insert('entry', [
            'id' => $id, 'canonical_item_id' => $id, 'author_url' => $authorUrl, 'title' => $title, 'content_html' => $contentHtml,
        ]);
    }

    private function migrate(string $direction): void
    {
        $migration = new Version20261121120000($this->connection, new NullLogger());
        $schema = $this->connection->createSchemaManager()->introspectSchema();
        'up' === $direction ? $migration->up($schema) : $migration->down($schema);
        foreach ($migration->getSql() as $query) {
            $statement = $this->connection->prepare($query->getStatement());
            foreach (array_values($query->getParameters()) as $index => $value) {
                $statement->bindValue($index + 1, $value, \is_int($value) ? ParameterType::INTEGER : ParameterType::STRING);
            }
            $statement->executeStatement();
        }
    }

    /** @return list<string> */
    private function buildOldSchema(): array
    {
        return [
            'CREATE TABLE canonical_item (id INTEGER PRIMARY KEY, kind VARCHAR(16) NOT NULL, canonical_key VARCHAR(2048) NOT NULL,'
            . ' key_hash VARCHAR(64) NOT NULL, score INTEGER NOT NULL, comment_count INTEGER NOT NULL,'
            . ' self_reply_count INTEGER NOT NULL, created_at DATETIME NOT NULL)',
            'CREATE TABLE entry (id INTEGER PRIMARY KEY, canonical_item_id INTEGER DEFAULT NULL, author_url VARCHAR(2048) DEFAULT NULL,'
            . ' title VARCHAR(1024) NOT NULL, content_html CLOB DEFAULT NULL)',
            'CREATE INDEX idx_entry_item_feed_date ON entry (canonical_item_id, id)',
        ];
    }
}
