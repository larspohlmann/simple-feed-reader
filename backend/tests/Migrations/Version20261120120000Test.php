<?php

declare(strict_types=1);

namespace App\Tests\Migrations;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\Migrations\Exception\AbortMigration;
use Doctrine\Migrations\Exception\SkipMigration;
use DoctrineMigrations\Version20261120120000;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class Version20261120120000Test extends TestCase
{
    private const string LEMMY_POST = 'https://lemmy.world/post/54626839';
    private const string MASTODON_STATUS = 'https://mastodon.social/users/lars/statuses/113456789';

    private Connection $connection;

    protected function setUp(): void
    {
        // Migration classes are deliberately excluded from Composer's autoloader (doctrine_migrations.yaml).
        require_once \dirname(__DIR__, 2) . '/migrations/Version20261120120000.php';
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        // As the app's connection: a DROP TABLE entry would cascade into the votes and likes this test counts.
        $this->connection->executeStatement('PRAGMA foreign_keys = ON');
        foreach (self::buildPreMigrationSchema() as $statement) {
            $this->connection->executeStatement($statement);
        }
        $this->seedCopies();
    }

    public function testBackfillTiesCopies(): void
    {
        $this->migrateUp();

        $items = $this->connection->fetchAllAssociative(
            'SELECT id, kind, canonical_key, key_hash, score, comment_count, self_reply_count FROM canonical_item ORDER BY kind',
        );
        self::assertCount(2, $items);
        [$lemmy, $mastodon] = $items;
        self::assertSame(['lemmy', self::LEMMY_POST, hash('sha256', self::LEMMY_POST)], [
            $lemmy['kind'], $lemmy['canonical_key'], $lemmy['key_hash'],
        ]);
        self::assertSame([2, 40, 3], [$lemmy['score'], $lemmy['comment_count'], $lemmy['self_reply_count']]);
        self::assertSame(['mastodon', self::MASTODON_STATUS], [$mastodon['kind'], $mastodon['canonical_key']]);
        self::assertSame(
            [1617614 => $lemmy['id'], 1617690 => $lemmy['id'], 1617700 => $mastodon['id'], 1617701 => $mastodon['id'], 1617702 => null],
            $this->connection->fetchAllKeyValue('SELECT id, canonical_item_id FROM entry ORDER BY id'),
        );
    }

    public function testCopiesHandTheirCountsToTheItem(): void
    {
        $this->migrateUp();

        self::assertSame(
            [[1617614, null, 0], [1617690, null, 0], [1617700, null, 0], [1617701, null, 0], [1617702, 12, 2]],
            $this->connection->fetchAllNumeric('SELECT id, comment_count, self_reply_count FROM entry ORDER BY id'),
        );
    }

    public function testTheCommunityMovesOntoTheItemsLemmyPost(): void
    {
        $this->migrateUp();

        self::assertSame(
            [['c/technology@lemmy.world', 'https://lemmy.world/c/technology']],
            $this->connection->fetchAllNumeric(
                'SELECT community_identity, community_url FROM lemmy_post post'
                . " JOIN canonical_item item ON item.id = post.canonical_item_id AND item.kind = 'lemmy'",
            ),
        );
        self::assertFalse($this->connection->createSchemaManager()->tablesExist(['entry_lemmy_post']));
    }

    public function testOneUsersVotesOnTwoCopiesMergeIntoTheLatestWithItsAccount(): void
    {
        $this->migrateUp();

        self::assertSame(
            [[7, $this->findItemId('lemmy'), -1, '2026-11-19 11:00:00', 21], [8, $this->findItemId('lemmy'), 1, '2026-11-19 09:00:00', 22]],
            $this->connection->fetchAllNumeric(
                'SELECT user_id, canonical_item_id, score, voted_at, social_account_id FROM discussion_vote ORDER BY user_id',
            ),
        );
    }

    public function testOneUsersLikesOnTwoCopiesMergeIntoTheLatestWithItsAccount(): void
    {
        $this->migrateUp();

        self::assertSame(
            [[7, $this->findItemId('mastodon'), 'like-b', 32]],
            $this->connection->fetchAllNumeric('SELECT user_id, canonical_item_id, remote_id, social_account_id FROM social_like'),
        );
    }

    public function testAVoteOnAnEntryWithoutAnItemIsDropped(): void
    {
        $this->connection->executeStatement(
            "INSERT INTO discussion_vote (user_id, entry_id, score, voted_at, social_account_id) VALUES (9, 1617702, 1, '2026-11-19 12:00:00', 23)",
        );

        $this->migrateUp();

        self::assertSame(0, $this->connection->fetchOne('SELECT COUNT(*) FROM discussion_vote WHERE user_id = 9'));
    }

    public function testARunOverAnUpgradedSchemaIsSkipped(): void
    {
        $this->migrateUp();

        $this->expectException(SkipMigration::class);
        $this->migrateUp();
    }

    public function testAHalfMigratedSchemaIsRefusedNamingWhatItHolds(): void
    {
        $this->connection->executeStatement('CREATE TABLE canonical_item (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL)');
        $this->connection->executeStatement('ALTER TABLE entry ADD COLUMN canonical_item_id INTEGER DEFAULT NULL');

        $this->expectException(AbortMigration::class);
        $this->expectExceptionMessage('present: canonical_item, entry.canonical_item_id');
        $this->migrateUp();
    }

    public function testDownOverAPristineSchemaIsSkipped(): void
    {
        $this->expectException(SkipMigration::class);
        $this->migrateDown();
    }

    public function testDownRestoresTheCopiesState(): void
    {
        $this->migrateUp();

        $this->migrateDown();

        self::assertFalse($this->connection->createSchemaManager()->tablesExist(['canonical_item']));
        self::assertSame(
            [[1617614, 40, 3], [1617690, 40, 3], [1617700, 5, 0], [1617701, 5, 0], [1617702, 12, 2]],
            $this->connection->fetchAllNumeric('SELECT id, comment_count, self_reply_count FROM entry ORDER BY id'),
        );
        self::assertSame(
            [[1617614, 2, 'c/technology@lemmy.world'], [1617690, 2, 'c/technology@lemmy.world']],
            $this->connection->fetchAllNumeric('SELECT entry_id, score, community_identity FROM entry_lemmy_post ORDER BY entry_id'),
        );
        self::assertSame(
            [[7, 1617614, -1], [8, 1617614, 1]],
            $this->connection->fetchAllNumeric('SELECT user_id, entry_id, score FROM discussion_vote ORDER BY user_id'),
        );
        self::assertSame(
            [[7, 1617700]],
            $this->connection->fetchAllNumeric('SELECT user_id, entry_id FROM social_like'),
        );
    }

    private function seedCopies(): void
    {
        $this->connection->executeStatement('INSERT INTO app_user (id) VALUES (7), (8), (9)');
        $this->connection->executeStatement('INSERT INTO social_account (id) VALUES (20), (21), (22), (23), (31), (32)');
        $feeds = [[1, 'https://lemmy.world/feeds/c/technology.xml', 'lemmy'], [2, 'https://lemmy.world/api/v3/post/list', 'lemmy'],
            [3, 'https://mastodon.social/api/v1/timelines/home', 'mastodon'], [4, 'https://mastodon.social/@lars.rss', 'mastodon'],
            [5, 'https://blog.example.com/feed.xml', null]];
        foreach ($feeds as $feed) {
            $this->connection->executeStatement('INSERT INTO feed (id, url, network) VALUES (?, ?, ?)', $feed);
        }
        $entries = [
            [1617614, 1, self::LEMMY_POST, 'https://example.com/story', null, 38, 1],
            [1617690, 2, self::LEMMY_POST . '#3', 'https://example.com/story', self::LEMMY_POST, 40, 3],
            [1617700, 3, self::MASTODON_STATUS, 'https://mastodon.social/@lars/113456789', null, 4, 0],
            [1617701, 4, 'https://mastodon.social/@lars/113456789', 'https://mastodon.social/@lars/113456789', null, 5, 0],
            [1617702, 5, 'https://blog.example.com/a', 'https://blog.example.com/a', null, 12, 2],
        ];
        foreach ($entries as $entry) {
            $this->connection->executeStatement(
                'INSERT INTO entry (id, feed_id, guid, url, discussion_url, comment_count, self_reply_count) VALUES (?, ?, ?, ?, ?, ?, ?)',
                $entry,
            );
        }
        $this->connection->executeStatement(
            "INSERT INTO entry_lemmy_post (entry_id, score, community_identity, community_url) VALUES (1617614, 3, 'c/technology@lemmy.world',"
            . " 'https://lemmy.world/c/technology'), (1617690, 2, NULL, NULL)",
        );
        $this->connection->executeStatement(
            'INSERT INTO discussion_vote (user_id, entry_id, score, voted_at, social_account_id) VALUES'
            . " (7, 1617614, 1, '2026-11-19 10:00:00', 20), (7, 1617690, -1, '2026-11-19 11:00:00', 21),"
            . " (8, 1617690, 1, '2026-11-19 09:00:00', 22)",
        );
        $this->connection->executeStatement(
            'INSERT INTO social_like (user_id, entry_id, remote_id, liked_at, social_account_id) VALUES'
            . " (7, 1617700, 'like-a', '2026-11-19 10:00:00', 31), (7, 1617701, 'like-b', '2026-11-19 10:30:00', 32)",
        );
    }

    private function findItemId(string $kind): mixed
    {
        return $this->connection->fetchOne('SELECT id FROM canonical_item WHERE kind = ?', [$kind]);
    }

    private function migrateUp(): void
    {
        $migration = new Version20261120120000($this->connection, new NullLogger());
        $migration->up($this->connection->createSchemaManager()->introspectSchema());
        foreach ($migration->getSql() as $query) {
            $this->connection->executeStatement($query->getStatement());
        }
    }

    private function migrateDown(): void
    {
        $migration = new Version20261120120000($this->connection, new NullLogger());
        $migration->down($this->connection->createSchemaManager()->introspectSchema());
        foreach ($migration->getSql() as $query) {
            $this->connection->executeStatement($query->getStatement());
        }
    }

    /** @return list<string> the tables the migration reads and rewrites, as the migration before it leaves them */
    private static function buildPreMigrationSchema(): array
    {
        return [
            'CREATE TABLE app_user (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL)',
            'CREATE TABLE social_account (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL)',
            'CREATE TABLE feed (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, url VARCHAR(2048) NOT NULL, network VARCHAR(16) DEFAULT NULL)',
            'CREATE TABLE entry (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, feed_id INTEGER NOT NULL, guid CLOB NOT NULL,'
            . ' url VARCHAR(2048) DEFAULT NULL, discussion_url VARCHAR(2048) DEFAULT NULL, comments_feed_url VARCHAR(2048) DEFAULT NULL,'
            . ' comments_load VARCHAR(8) DEFAULT NULL, comment_count INTEGER DEFAULT NULL, self_reply_count SMALLINT DEFAULT 0 NOT NULL,'
            . ' CONSTRAINT FK_2B219D7051A5BC03 FOREIGN KEY (feed_id) REFERENCES feed (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)',
            'CREATE TABLE entry_lemmy_post (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, score INTEGER DEFAULT NULL,'
            . ' community_identity VARCHAR(255) DEFAULT NULL, community_url VARCHAR(2048) DEFAULT NULL, entry_id INTEGER NOT NULL,'
            . ' CONSTRAINT FK_EA202E9CBA364942 FOREIGN KEY (entry_id) REFERENCES entry (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)',
            'CREATE UNIQUE INDEX UNIQ_EA202E9CBA364942 ON entry_lemmy_post (entry_id)',
            'CREATE TABLE discussion_vote (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, score SMALLINT NOT NULL, voted_at DATETIME NOT NULL,'
            . ' user_id INTEGER NOT NULL, entry_id INTEGER NOT NULL, social_account_id INTEGER NOT NULL,'
            . ' CONSTRAINT FK_7F7E2952A76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE,'
            . ' CONSTRAINT FK_7F7E2952BA364942 FOREIGN KEY (entry_id) REFERENCES entry (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE,'
            . ' CONSTRAINT FK_7F7E29525538ED78 FOREIGN KEY (social_account_id) REFERENCES social_account (id) ON DELETE CASCADE'
            . ' NOT DEFERRABLE INITIALLY IMMEDIATE)',
            'CREATE INDEX IDX_7F7E2952A76ED395 ON discussion_vote (user_id)',
            'CREATE INDEX idx_discussion_vote_entry ON discussion_vote (entry_id)',
            'CREATE INDEX idx_discussion_vote_account ON discussion_vote (social_account_id)',
            'CREATE UNIQUE INDEX uniq_discussion_vote_user_entry ON discussion_vote (user_id, entry_id)',
            'CREATE TABLE social_like (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, remote_id VARCHAR(512) NOT NULL, liked_at DATETIME NOT NULL,'
            . ' user_id INTEGER NOT NULL, entry_id INTEGER NOT NULL, social_account_id INTEGER NOT NULL,'
            . ' CONSTRAINT fk_social_like_user FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE,'
            . ' CONSTRAINT fk_social_like_entry FOREIGN KEY (entry_id) REFERENCES entry (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE,'
            . ' CONSTRAINT fk_social_like_account FOREIGN KEY (social_account_id) REFERENCES social_account (id) ON DELETE CASCADE'
            . ' NOT DEFERRABLE INITIALLY IMMEDIATE)',
            'CREATE INDEX idx_social_like_user ON social_like (user_id)',
            'CREATE INDEX idx_social_like_entry ON social_like (entry_id)',
            'CREATE INDEX idx_social_like_account ON social_like (social_account_id)',
            'CREATE UNIQUE INDEX uniq_social_like_user_entry ON social_like (user_id, entry_id)',
        ];
    }
}
