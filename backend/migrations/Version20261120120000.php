<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;
use Doctrine\Migrations\AbstractMigration;

/**
 * Keys every stored copy with its own copy of the canonical key rules (`*CanonicalKeyRule`), so a later refactor of
 * those rules cannot change what this migration did; Version20261120120000KeysTest holds the two to each other.
 * MySQL commits each DDL statement, so every MySQL step is guarded by the schema it finds: a run cut off midway
 * completes on the next. SQLite runs in one transaction and adds entry.canonical_item_id in place, so the
 * entry_search triggers on entry stay and no DROP TABLE entry cascades into its children.
 */
final class Version20261120120000 extends AbstractMigration
{
    private const int BATCH = 1000;
    private const int KEY_MAX_LENGTH = 2048;
    private const string BACKFILL = 'canonical_item_backfill';
    private const string AT_POST_URI = '#^at://([^/\s]+)/app\.bsky\.feed\.post/([^/\s]+)\z#';
    private const string LEMMY_POST_PAGE = '#^https://([a-z0-9.-]+)/post/(\d+)/?$#i';
    private const array MASTODON_STATUS_SHAPES = [
        '~^(?<prefix>https://(?<host>[a-z0-9.-]+)/users/(?<account>[^/?#]+))/statuses/(?<id>\d{1,32})$~iD',
        '~^(?<prefix>https://(?<host>[a-z0-9.-]+)/@(?<account>[^/?#]+))/(?<id>\d{1,32})$~iD',
    ];
    private const array TIMESTAMP_COLUMNS = ['discussion_vote' => 'voted_at', 'social_like' => 'liked_at'];

    public function getDescription(): string
    {
        return 'Copies of one social post share one canonical_item, with its counts, votes, likes and Lemmy community (#2404)';
    }

    public function up(Schema $schema): void
    {
        $this->abortUnlessSupported();
        $this->skipIf(self::isUpgraded($schema), 'Copies already share canonical items.');

        if ($this->isMySql()) {
            $this->upMySql($schema);

            return;
        }

        $this->abortUnlessPristine($schema);
        $this->upSqlite();
    }

    public function down(Schema $schema): void
    {
        $this->abortUnlessSupported();
        $this->skipIf(self::isPristine($schema), 'Copies do not share canonical items.');
        $this->abortUnlessUpgraded($schema);

        if ($this->isMySql()) {
            $this->downMySql();

            return;
        }

        $this->downSqlite();
    }

    /** SQLite DDL is transactional, so SQLite runs whole or not at all; MySQL commits each DDL statement anyway. */
    public function isTransactional(): bool
    {
        return $this->connection->getDatabasePlatform() instanceof SQLitePlatform;
    }

    /**
     * @param array{network: ?string, guid: string, url: ?string, discussion_url: ?string, comments_feed_url: ?string, comments_load: ?string} $entry
     *
     * @return array{0: string, 1: string}|null the item's kind and canonical key; null when no rule claims the entry
     */
    public static function parseCanonicalKey(array $entry): ?array
    {
        $guid = preg_replace('/#\d+\z/', '', $entry['guid']) ?? $entry['guid'];

        return self::parseBlueskyKey($entry['network'], $guid)
            ?? self::parseLemmyKey($entry, $guid)
            ?? self::parseMastodonKey($entry['network'], $guid, $entry['url']);
    }

    /** @return array{0: string, 1: string}|null */
    private static function parseBlueskyKey(?string $network, string $guid): ?array
    {
        return 'bluesky' === $network && 1 === preg_match(self::AT_POST_URI, $guid) ? ['bluesky', $guid] : null;
    }

    /**
     * @param array{network: ?string, guid: string, url: ?string, discussion_url: ?string, comments_feed_url: ?string, comments_load: ?string} $entry
     *
     * @return array{0: string, 1: string}|null
     */
    private static function parseLemmyKey(array $entry, string $guid): ?array
    {
        if ('lemmy' === $entry['network']) {
            $page = self::parseLemmyPage($guid) ?? self::parseLemmyPage($entry['discussion_url']);

            return null === $page ? null : ['lemmy', $page];
        }
        $isLemmyThread = null === $entry['url']
            && null !== $entry['discussion_url']
            && null === $entry['comments_feed_url']
            && 'auto' === $entry['comments_load'];
        $page = $isLemmyThread ? self::parseLemmyPage($entry['discussion_url']) : null;

        return null === $page ? null : ['lemmy', $page];
    }

    private static function parseLemmyPage(?string $url): ?string
    {
        if (null === $url || 1 !== preg_match(self::LEMMY_POST_PAGE, $url, $parts)) {
            return null;
        }

        return \sprintf('https://%s/post/%d', strtolower($parts[1]), (int) $parts[2]);
    }

    /** @return array{0: string, 1: string}|null */
    private static function parseMastodonKey(?string $network, string $guid, ?string $url): ?array
    {
        if ('mastodon' !== $network) {
            return null;
        }
        $address = self::parseMastodonStatus($guid)
            ?? self::parseMastodonStatus((string) $url)
            ?? self::keepIfAbsolute($guid)
            ?? self::keepIfAbsolute($url);

        return null === $address ? null : ['mastodon', $address];
    }

    private static function parseMastodonStatus(string $address): ?string
    {
        foreach (self::MASTODON_STATUS_SHAPES as $shape) {
            if (1 === preg_match($shape, $address, $match)) {
                return str_replace('/@', '/users/', $match['prefix']) . '/statuses/' . $match['id'];
            }
        }

        return null;
    }

    private static function keepIfAbsolute(?string $url): ?string
    {
        return null !== $url && 1 === preg_match('#^https?://#i', $url) ? $url : null;
    }

    /** @return array<string, bool> each part this migration adds, true when the schema has it */
    private static function readUpgradedParts(Schema $schema): array
    {
        return [
            'canonical_item' => $schema->hasTable('canonical_item'),
            'lemmy_post' => $schema->hasTable('lemmy_post'),
            'entry.canonical_item_id' => $schema->getTable('entry')->hasColumn('canonical_item_id'),
            'entry_lemmy_post dropped' => !$schema->hasTable('entry_lemmy_post'),
            'discussion_vote.entry_id dropped' => !$schema->getTable('discussion_vote')->hasColumn('entry_id'),
            'social_like.entry_id dropped' => !$schema->getTable('social_like')->hasColumn('entry_id'),
        ];
    }

    private static function isUpgraded(Schema $schema): bool
    {
        return !\in_array(false, self::readUpgradedParts($schema), true) && !$schema->hasTable(self::BACKFILL);
    }

    private static function isPristine(Schema $schema): bool
    {
        return !\in_array(true, self::readUpgradedParts($schema), true) && !$schema->hasTable(self::BACKFILL);
    }

    private function abortUnlessPristine(Schema $schema): void
    {
        $this->abortIf(!self::isPristine($schema), \sprintf(
            'The schema is half migrated (present: %s); SQLite runs this migration in one transaction, so restore a backup.',
            self::formatPresentParts($schema),
        ));
    }

    private function abortUnlessUpgraded(Schema $schema): void
    {
        $this->abortIf(!self::isUpgraded($schema), \sprintf(
            'The schema is half migrated (present: %s); run up to complete it before reverting.',
            self::formatPresentParts($schema),
        ));
    }

    private static function formatPresentParts(Schema $schema): string
    {
        $present = array_keys(array_filter(self::readUpgradedParts($schema)));
        if ($schema->hasTable(self::BACKFILL)) {
            $present[] = self::BACKFILL;
        }

        return [] === $present ? 'none' : implode(', ', $present);
    }

    private function upMySql(Schema $schema): void
    {
        if (!$schema->hasTable('canonical_item')) {
            $this->addSql(self::buildCanonicalItemTableOnMySql());
        }
        if (!$schema->hasTable('lemmy_post')) {
            $this->addSql('CREATE TABLE lemmy_post (id INT AUTO_INCREMENT NOT NULL, community_identity VARCHAR(255) DEFAULT NULL,'
                . ' community_url VARCHAR(2048) DEFAULT NULL, canonical_item_id INT NOT NULL,'
                . ' UNIQUE INDEX UNIQ_728A67AE4128692 (canonical_item_id), PRIMARY KEY (id),'
                . ' CONSTRAINT FK_728A67AE4128692 FOREIGN KEY (canonical_item_id) REFERENCES canonical_item (id) ON DELETE CASCADE)'
                . ' DEFAULT CHARACTER SET utf8mb4');
        }
        $this->addEntryItemColumnOnMySql($schema->getTable('entry'));
        if ($schema->hasTable('entry_lemmy_post')) {
            $this->backfillItemsOnMySql();
        }
        if ($schema->getTable('discussion_vote')->hasColumn('entry_id')) {
            $this->rekeyVotesOnMySql($schema);
        }
        if ($schema->getTable('social_like')->hasColumn('entry_id')) {
            $this->rekeyLikesOnMySql($schema);
        }
        $this->addSql('UPDATE entry SET comment_count = NULL, self_reply_count = 0 WHERE canonical_item_id IS NOT NULL');
        $this->addSql('DROP TABLE IF EXISTS ' . self::BACKFILL);
    }

    private static function buildCanonicalItemTableOnMySql(): string
    {
        return 'CREATE TABLE canonical_item (id INT AUTO_INCREMENT NOT NULL, kind VARCHAR(16) NOT NULL,'
            . ' canonical_key VARCHAR(2048) NOT NULL, key_hash VARCHAR(64) NOT NULL, score INT DEFAULT NULL,'
            . ' comment_count INT DEFAULT NULL, self_reply_count SMALLINT DEFAULT 0 NOT NULL, created_at DATETIME NOT NULL,'
            . ' INDEX idx_canonical_item_created_at (created_at), UNIQUE INDEX uniq_canonical_item_kind_key (kind, key_hash),'
            . ' PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4';
    }

    /** One step per statement, so each stays cheap on a large entry table: the column alone is instant. */
    private function addEntryItemColumnOnMySql(Table $entry): void
    {
        if (!$entry->hasColumn('canonical_item_id')) {
            $this->addSql('ALTER TABLE entry ADD canonical_item_id INT DEFAULT NULL');
        }
        if (!$entry->hasIndex('IDX_2B219D704128692')) {
            $this->addSql('CREATE INDEX IDX_2B219D704128692 ON entry (canonical_item_id)');
        }
        if (!$entry->hasIndex('idx_entry_canonical_item')) {
            $this->addSql('CREATE INDEX idx_entry_canonical_item ON entry (canonical_item_id, id)');
        }
        if ($entry->hasForeignKey('FK_2B219D704128692')) {
            return;
        }
        // Without the checks MySQL adds the key in place instead of copying the table; the column holds only NULLs here.
        // Should the ALTER fail, the checks stay off for the rest of that connection, which the failed run then ends.
        $this->addSql('SET foreign_key_checks = 0');
        $this->addSql('ALTER TABLE entry ADD CONSTRAINT FK_2B219D704128692 FOREIGN KEY (canonical_item_id)'
            . ' REFERENCES canonical_item (id) ON DELETE SET NULL');
        $this->addSql('SET foreign_key_checks = 1');
    }

    private function backfillItemsOnMySql(): void
    {
        $this->addSql('DROP TABLE IF EXISTS ' . self::BACKFILL);
        $this->addSql('CREATE TABLE ' . self::BACKFILL . ' (entry_id INT NOT NULL, kind VARCHAR(16) NOT NULL,'
            . ' canonical_key LONGTEXT NOT NULL, key_hash VARCHAR(64) NOT NULL, INDEX idx_backfill_item (kind, key_hash),'
            . ' PRIMARY KEY (entry_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addBackfillRows();
        $this->addItems();
        $this->addSql('UPDATE entry e INNER JOIN ' . self::BACKFILL . ' backfill ON backfill.entry_id = e.id'
            . ' INNER JOIN canonical_item item ON item.kind = backfill.kind AND item.key_hash = backfill.key_hash'
            . ' SET e.canonical_item_id = item.id');
        $this->addItemCounts();
        $this->addLemmyCommunities();
        $this->addSql('DROP TABLE entry_lemmy_post');
    }

    private function rekeyVotesOnMySql(Schema $schema): void
    {
        $this->moveReactionsOntoItemsOnMySql($schema, 'discussion_vote');
        $this->addSql('ALTER TABLE discussion_vote DROP FOREIGN KEY FK_7F7E2952BA364942,'
            . ' DROP INDEX idx_discussion_vote_entry, DROP INDEX uniq_discussion_vote_user_entry, DROP entry_id,'
            . ' MODIFY canonical_item_id INT NOT NULL, ADD INDEX idx_discussion_vote_canonical_item (canonical_item_id),'
            . ' ADD UNIQUE INDEX uniq_discussion_vote_user_canonical_item (user_id, canonical_item_id),'
            . ' ADD CONSTRAINT FK_7F7E29524128692 FOREIGN KEY (canonical_item_id) REFERENCES canonical_item (id) ON DELETE CASCADE');
    }

    private function rekeyLikesOnMySql(Schema $schema): void
    {
        $this->moveReactionsOntoItemsOnMySql($schema, 'social_like');
        $this->addSql('ALTER TABLE social_like DROP FOREIGN KEY fk_social_like_entry,'
            . ' DROP INDEX idx_social_like_entry, DROP INDEX uniq_social_like_user_entry, DROP entry_id,'
            . ' MODIFY canonical_item_id INT NOT NULL, ADD INDEX idx_social_like_canonical_item (canonical_item_id),'
            . ' ADD UNIQUE INDEX uniq_social_like_user_canonical_item (user_id, canonical_item_id),'
            . ' ADD CONSTRAINT FK_E37293D74128692 FOREIGN KEY (canonical_item_id) REFERENCES canonical_item (id) ON DELETE CASCADE');
    }

    /** Runs right before the ALTER that makes the key NOT NULL and unique, so rows the old release wrote meanwhile cannot fail it. */
    private function moveReactionsOntoItemsOnMySql(Schema $schema, string $table): void
    {
        if (!$schema->getTable($table)->hasColumn('canonical_item_id')) {
            $this->addSql("ALTER TABLE $table ADD canonical_item_id INT DEFAULT NULL");
        }
        $this->addSql("UPDATE $table reaction INNER JOIN entry e ON e.id = reaction.entry_id"
            . ' SET reaction.canonical_item_id = e.canonical_item_id');
        $this->addSql("DELETE FROM $table WHERE canonical_item_id IS NULL");
        $this->addSql(\sprintf(
            'DELETE reaction FROM %1$s reaction INNER JOIN %1$s later ON later.user_id = reaction.user_id'
            . ' AND later.canonical_item_id = reaction.canonical_item_id'
            . ' AND (later.%2$s > reaction.%2$s OR (later.%2$s = reaction.%2$s AND later.id > reaction.id))',
            $table,
            self::TIMESTAMP_COLUMNS[$table],
        ));
    }

    private function upSqlite(): void
    {
        $this->addSql('CREATE TABLE canonical_item (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, kind VARCHAR(16) NOT NULL,'
            . ' canonical_key VARCHAR(2048) NOT NULL, key_hash VARCHAR(64) NOT NULL, score INTEGER DEFAULT NULL,'
            . ' comment_count INTEGER DEFAULT NULL, self_reply_count SMALLINT DEFAULT 0 NOT NULL, created_at DATETIME NOT NULL)');
        $this->addSql('CREATE INDEX idx_canonical_item_created_at ON canonical_item (created_at)');
        $this->addSql('CREATE UNIQUE INDEX uniq_canonical_item_kind_key ON canonical_item (kind, key_hash)');
        $this->addSql('CREATE TABLE lemmy_post (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,'
            . ' community_identity VARCHAR(255) DEFAULT NULL, community_url VARCHAR(2048) DEFAULT NULL,'
            . ' canonical_item_id INTEGER NOT NULL, CONSTRAINT FK_728A67AE4128692 FOREIGN KEY (canonical_item_id)'
            . ' REFERENCES canonical_item (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_728A67AE4128692 ON lemmy_post (canonical_item_id)');
        $this->addSql('ALTER TABLE entry ADD COLUMN canonical_item_id INTEGER DEFAULT NULL CONSTRAINT FK_2B219D704128692'
            . ' REFERENCES canonical_item (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('CREATE INDEX IDX_2B219D704128692 ON entry (canonical_item_id)');
        $this->addSql('CREATE INDEX idx_entry_canonical_item ON entry (canonical_item_id, id)');
        $this->backfillItemsOnSqlite();
        $this->rekeyVotesOnSqlite();
        $this->rekeyLikesOnSqlite();
        $this->addSql('UPDATE entry SET comment_count = NULL, self_reply_count = 0 WHERE canonical_item_id IS NOT NULL');
        $this->addSql('DROP TABLE ' . self::BACKFILL);
    }

    private function backfillItemsOnSqlite(): void
    {
        $this->addSql('CREATE TABLE ' . self::BACKFILL . ' (entry_id INTEGER PRIMARY KEY NOT NULL,'
            . ' kind VARCHAR(16) NOT NULL, canonical_key CLOB NOT NULL, key_hash VARCHAR(64) NOT NULL)');
        $this->addSql('CREATE INDEX idx_backfill_item ON ' . self::BACKFILL . ' (kind, key_hash)');
        $this->addBackfillRows();
        $this->addItems();
        $this->addSql('UPDATE entry SET canonical_item_id = (SELECT item.id FROM ' . self::BACKFILL . ' backfill'
            . ' INNER JOIN canonical_item item ON item.kind = backfill.kind AND item.key_hash = backfill.key_hash'
            . ' WHERE backfill.entry_id = entry.id) WHERE id IN (SELECT entry_id FROM ' . self::BACKFILL . ')');
        $this->addItemCounts();
        $this->addLemmyCommunities();
        $this->addSql('DROP TABLE entry_lemmy_post');
    }

    private function rekeyVotesOnSqlite(): void
    {
        $this->addSql('CREATE TEMPORARY TABLE __temp__discussion_vote AS SELECT reaction.id, reaction.score, reaction.voted_at,'
            . ' reaction.user_id, e.canonical_item_id, reaction.social_account_id FROM discussion_vote reaction'
            . ' INNER JOIN entry e ON e.id = reaction.entry_id WHERE ' . self::buildIsLatestOnSqlite('discussion_vote'));
        $this->addSql('DROP TABLE discussion_vote');
        $this->addSql('CREATE TABLE discussion_vote (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, score SMALLINT NOT NULL,'
            . ' voted_at DATETIME NOT NULL, user_id INTEGER NOT NULL, canonical_item_id INTEGER NOT NULL,'
            . ' social_account_id INTEGER NOT NULL,'
            . ' CONSTRAINT FK_7F7E2952A76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE,'
            . ' CONSTRAINT FK_7F7E29525538ED78 FOREIGN KEY (social_account_id) REFERENCES social_account (id) ON DELETE CASCADE'
            . ' NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_7F7E29524128692 FOREIGN KEY (canonical_item_id)'
            . ' REFERENCES canonical_item (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('INSERT INTO discussion_vote (id, score, voted_at, user_id, canonical_item_id, social_account_id)'
            . ' SELECT id, score, voted_at, user_id, canonical_item_id, social_account_id FROM __temp__discussion_vote');
        $this->addSql('DROP TABLE __temp__discussion_vote');
        $this->addSql('CREATE INDEX idx_discussion_vote_account ON discussion_vote (social_account_id)');
        $this->addSql('CREATE INDEX IDX_7F7E2952A76ED395 ON discussion_vote (user_id)');
        $this->addSql('CREATE INDEX idx_discussion_vote_canonical_item ON discussion_vote (canonical_item_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_discussion_vote_user_canonical_item ON discussion_vote (user_id, canonical_item_id)');
    }

    private function rekeyLikesOnSqlite(): void
    {
        $this->addSql('CREATE TEMPORARY TABLE __temp__social_like AS SELECT reaction.id, reaction.remote_id, reaction.liked_at,'
            . ' reaction.user_id, e.canonical_item_id, reaction.social_account_id FROM social_like reaction'
            . ' INNER JOIN entry e ON e.id = reaction.entry_id WHERE ' . self::buildIsLatestOnSqlite('social_like'));
        $this->addSql('DROP TABLE social_like');
        $this->addSql('CREATE TABLE social_like (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, remote_id VARCHAR(512) NOT NULL,'
            . ' liked_at DATETIME NOT NULL, user_id INTEGER NOT NULL, canonical_item_id INTEGER NOT NULL,'
            . ' social_account_id INTEGER NOT NULL,'
            . ' CONSTRAINT fk_social_like_user FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE,'
            . ' CONSTRAINT fk_social_like_account FOREIGN KEY (social_account_id) REFERENCES social_account (id) ON DELETE CASCADE'
            . ' NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_E37293D74128692 FOREIGN KEY (canonical_item_id)'
            . ' REFERENCES canonical_item (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('INSERT INTO social_like (id, remote_id, liked_at, user_id, canonical_item_id, social_account_id)'
            . ' SELECT id, remote_id, liked_at, user_id, canonical_item_id, social_account_id FROM __temp__social_like');
        $this->addSql('DROP TABLE __temp__social_like');
        $this->addSql('CREATE INDEX idx_social_like_account ON social_like (social_account_id)');
        $this->addSql('CREATE INDEX idx_social_like_user ON social_like (user_id)');
        $this->addSql('CREATE INDEX idx_social_like_canonical_item ON social_like (canonical_item_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_social_like_user_canonical_item ON social_like (user_id, canonical_item_id)');
    }

    /** True for the row of `reaction` (joined to its entry `e`) that one user keeps per item: the latest. */
    private static function buildIsLatestOnSqlite(string $table): string
    {
        return \sprintf(
            'e.canonical_item_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM %1$s later INNER JOIN entry copy'
            . ' ON copy.id = later.entry_id WHERE later.user_id = reaction.user_id'
            . ' AND copy.canonical_item_id = e.canonical_item_id'
            . ' AND (later.%2$s > reaction.%2$s OR (later.%2$s = reaction.%2$s AND later.id > reaction.id)))',
            $table,
            self::TIMESTAMP_COLUMNS[$table],
        );
    }

    /** Reads the entries a rule may claim in batches by id and queues one INSERT of their keys per batch. */
    private function addBackfillRows(): void
    {
        $reactedEntryIds = array_flip($this->readReactedEntryIds());
        $claimedReactions = [];
        $afterId = 0;
        while ([] !== ($entries = $this->readCandidateEntries($afterId))) {
            $values = [];
            foreach ($entries as $entry) {
                $afterId = (int) $entry['id'];
                $key = self::parseCanonicalKey($entry);
                if (null === $key) {
                    continue;
                }
                $values[] = $this->buildBackfillValues($afterId, $key);
                if (isset($reactedEntryIds[$afterId])) {
                    $claimedReactions[$afterId] = true;
                }
            }
            if ([] !== $values) {
                $this->addSql('INSERT INTO ' . self::BACKFILL . ' (entry_id, kind, canonical_key, key_hash) VALUES ' . implode(', ', $values));
            }
        }
        $unclaimed = \count(array_diff_key($reactedEntryIds, $claimedReactions));
        $this->warnIf($unclaimed > 0, \sprintf('Votes and likes on %d entries without an item are dropped.', $unclaimed));
    }

    /** @return list<int> */
    private function readReactedEntryIds(): array
    {
        return array_map(
            static fn (mixed $entryId): int => (int) $entryId,
            $this->connection->fetchFirstColumn('SELECT entry_id FROM discussion_vote UNION SELECT entry_id FROM social_like'),
        );
    }

    /** @return list<array{id: int|string, network: ?string, guid: string, url: ?string, discussion_url: ?string, comments_feed_url: ?string, comments_load: ?string}> */
    private function readCandidateEntries(int $afterId): array
    {
        /** @var list<array{id: int|string, network: ?string, guid: string, url: ?string, discussion_url: ?string, comments_feed_url: ?string, comments_load: ?string}> */
        return $this->connection->fetchAllAssociative(
            'SELECT e.id, f.network, e.guid, e.url, e.discussion_url, e.comments_feed_url, e.comments_load'
            . ' FROM entry e INNER JOIN feed f ON f.id = e.feed_id WHERE e.id > ? AND (f.network IS NOT NULL'
            . " OR (e.url IS NULL AND e.discussion_url IS NOT NULL AND e.comments_load = 'auto' AND e.comments_feed_url IS NULL))"
            . ' ORDER BY e.id LIMIT ' . self::BATCH,
            [$afterId],
        );
    }

    /** @param array{0: string, 1: string} $key */
    private function buildBackfillValues(int $entryId, array $key): string
    {
        return \sprintf(
            '(%d, %s, %s, %s)',
            $entryId,
            $this->connection->quote($key[0]),
            $this->connection->quote($key[1]),
            $this->connection->quote(hash('sha256', $key[1])),
        );
    }

    /** An item a cut-off run already created stays; the self-reply count is the largest any copy saw. */
    private function addItems(): void
    {
        $this->addSql(\sprintf(
            'INSERT INTO canonical_item (kind, canonical_key, key_hash, self_reply_count, created_at)'
            . ' SELECT backfill.kind, SUBSTR(MIN(backfill.canonical_key), 1, %1$d), backfill.key_hash, MAX(e.self_reply_count), %2$s'
            . ' FROM %3$s backfill INNER JOIN entry e ON e.id = backfill.entry_id WHERE NOT EXISTS (SELECT 1 FROM canonical_item'
            . ' item WHERE item.kind = backfill.kind AND item.key_hash = backfill.key_hash)'
            . ' GROUP BY backfill.kind, backfill.key_hash ORDER BY MIN(backfill.entry_id)',
            self::KEY_MAX_LENGTH,
            $this->connection->quote(gmdate('Y-m-d H:i:s')),
            self::BACKFILL,
        ));
    }

    /** The newest copy that carries a count gives it, as the copy fetched last does at ingestion. */
    private function addItemCounts(): void
    {
        $this->addSql('UPDATE canonical_item SET'
            . ' comment_count = (SELECT e.comment_count FROM entry e WHERE e.canonical_item_id = canonical_item.id'
            . ' AND e.comment_count IS NOT NULL ORDER BY e.id DESC LIMIT 1),'
            . ' score = (SELECT post.score FROM entry e INNER JOIN entry_lemmy_post post ON post.entry_id = e.id'
            . ' WHERE e.canonical_item_id = canonical_item.id AND post.score IS NOT NULL ORDER BY e.id DESC LIMIT 1)');
    }

    private function addLemmyCommunities(): void
    {
        $this->addSql('INSERT INTO lemmy_post (canonical_item_id, community_identity, community_url)'
            . ' SELECT e.canonical_item_id, post.community_identity, post.community_url'
            . ' FROM entry_lemmy_post post INNER JOIN entry e ON e.id = post.entry_id'
            . ' WHERE e.canonical_item_id IS NOT NULL AND post.community_identity IS NOT NULL'
            . ' AND NOT EXISTS (SELECT 1 FROM lemmy_post moved WHERE moved.canonical_item_id = e.canonical_item_id)'
            . ' AND NOT EXISTS (SELECT 1 FROM entry_lemmy_post newer INNER JOIN entry copy ON copy.id = newer.entry_id'
            . ' WHERE copy.canonical_item_id = e.canonical_item_id AND newer.community_identity IS NOT NULL'
            . ' AND newer.entry_id > post.entry_id)');
    }

    private function downMySql(): void
    {
        $this->addSql('CREATE TABLE entry_lemmy_post (id INT AUTO_INCREMENT NOT NULL, score INT DEFAULT NULL,'
            . ' community_identity VARCHAR(255) DEFAULT NULL, community_url VARCHAR(2048) DEFAULT NULL,'
            . ' entry_id INT NOT NULL, UNIQUE INDEX UNIQ_EA202E9CBA364942 (entry_id), PRIMARY KEY (id),'
            . ' CONSTRAINT FK_EA202E9CBA364942 FOREIGN KEY (entry_id) REFERENCES entry (id) ON DELETE CASCADE)'
            . ' DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('UPDATE entry e INNER JOIN canonical_item item ON item.id = e.canonical_item_id'
            . ' SET e.comment_count = item.comment_count, e.self_reply_count = item.self_reply_count');
        $this->addLemmyCopies();
        $this->restoreVotesOnMySql();
        $this->restoreLikesOnMySql();
        $this->addSql('DROP TABLE lemmy_post');
        $this->addSql('ALTER TABLE entry DROP FOREIGN KEY FK_2B219D704128692, DROP INDEX IDX_2B219D704128692,'
            . ' DROP INDEX idx_entry_canonical_item, DROP canonical_item_id');
        $this->addSql('DROP TABLE canonical_item');
    }

    private function restoreVotesOnMySql(): void
    {
        $this->moveReactionsOntoEntriesOnMySql('discussion_vote');
        $this->addSql('ALTER TABLE discussion_vote DROP FOREIGN KEY FK_7F7E29524128692,'
            . ' DROP INDEX idx_discussion_vote_canonical_item, DROP INDEX uniq_discussion_vote_user_canonical_item,'
            . ' DROP canonical_item_id, MODIFY entry_id INT NOT NULL, ADD INDEX idx_discussion_vote_entry (entry_id),'
            . ' ADD UNIQUE INDEX uniq_discussion_vote_user_entry (user_id, entry_id),'
            . ' ADD CONSTRAINT FK_7F7E2952BA364942 FOREIGN KEY (entry_id) REFERENCES entry (id) ON DELETE CASCADE');
    }

    private function restoreLikesOnMySql(): void
    {
        $this->moveReactionsOntoEntriesOnMySql('social_like');
        $this->addSql('ALTER TABLE social_like DROP FOREIGN KEY FK_E37293D74128692,'
            . ' DROP INDEX idx_social_like_canonical_item, DROP INDEX uniq_social_like_user_canonical_item,'
            . ' DROP canonical_item_id, MODIFY entry_id INT NOT NULL, ADD INDEX idx_social_like_entry (entry_id),'
            . ' ADD UNIQUE INDEX uniq_social_like_user_entry (user_id, entry_id),'
            . ' ADD CONSTRAINT fk_social_like_entry FOREIGN KEY (entry_id) REFERENCES entry (id) ON DELETE CASCADE');
    }

    private function moveReactionsOntoEntriesOnMySql(string $table): void
    {
        $this->addSql("ALTER TABLE $table ADD entry_id INT DEFAULT NULL");
        $this->addSql("UPDATE $table reaction SET reaction.entry_id ="
            . ' (SELECT MIN(e.id) FROM entry e WHERE e.canonical_item_id = reaction.canonical_item_id)');
        $this->addSql("DELETE FROM $table WHERE entry_id IS NULL");
    }

    private function downSqlite(): void
    {
        $this->addSql('CREATE TABLE entry_lemmy_post (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,'
            . ' score INTEGER DEFAULT NULL, community_identity VARCHAR(255) DEFAULT NULL,'
            . ' community_url VARCHAR(2048) DEFAULT NULL, entry_id INTEGER NOT NULL,'
            . ' CONSTRAINT FK_EA202E9CBA364942 FOREIGN KEY (entry_id) REFERENCES entry (id) ON DELETE CASCADE'
            . ' NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_EA202E9CBA364942 ON entry_lemmy_post (entry_id)');
        $this->addSql('UPDATE entry SET comment_count = (SELECT item.comment_count FROM canonical_item item WHERE item.id = entry.canonical_item_id),'
            . ' self_reply_count = (SELECT item.self_reply_count FROM canonical_item item WHERE item.id = entry.canonical_item_id)'
            . ' WHERE canonical_item_id IS NOT NULL');
        $this->addLemmyCopies();
        $this->restoreVotesOnSqlite();
        $this->restoreLikesOnSqlite();
        $this->addSql('DROP TABLE lemmy_post');
        $this->addSql('DROP INDEX IDX_2B219D704128692');
        $this->addSql('DROP INDEX idx_entry_canonical_item');
        $this->addSql('ALTER TABLE entry DROP COLUMN canonical_item_id');
        $this->addSql('DROP TABLE canonical_item');
    }

    /** Every Lemmy copy takes its item's score and community back. */
    private function addLemmyCopies(): void
    {
        $this->addSql('INSERT INTO entry_lemmy_post (entry_id, score, community_identity, community_url)'
            . ' SELECT e.id, item.score, post.community_identity, post.community_url FROM entry e'
            . ' INNER JOIN canonical_item item ON item.id = e.canonical_item_id'
            . ' LEFT JOIN lemmy_post post ON post.canonical_item_id = item.id'
            . " WHERE item.kind = 'lemmy' AND (item.score IS NOT NULL OR post.id IS NOT NULL)"
            . ' AND NOT EXISTS (SELECT 1 FROM entry_lemmy_post restored WHERE restored.entry_id = e.id)');
    }

    private function restoreVotesOnSqlite(): void
    {
        $this->addSql('CREATE TEMPORARY TABLE __temp__discussion_vote AS SELECT reaction.id, reaction.score, reaction.voted_at,'
            . ' reaction.user_id, ' . self::buildFirstCopy() . ' AS entry_id, reaction.social_account_id FROM discussion_vote reaction');
        $this->addSql('DROP TABLE discussion_vote');
        $this->addSql('CREATE TABLE discussion_vote (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, score SMALLINT NOT NULL,'
            . ' voted_at DATETIME NOT NULL, user_id INTEGER NOT NULL, entry_id INTEGER NOT NULL, social_account_id INTEGER NOT NULL,'
            . ' CONSTRAINT FK_7F7E2952A76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE,'
            . ' CONSTRAINT FK_7F7E2952BA364942 FOREIGN KEY (entry_id) REFERENCES entry (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE,'
            . ' CONSTRAINT FK_7F7E29525538ED78 FOREIGN KEY (social_account_id) REFERENCES social_account (id) ON DELETE CASCADE'
            . ' NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('INSERT INTO discussion_vote (id, score, voted_at, user_id, entry_id, social_account_id)'
            . ' SELECT id, score, voted_at, user_id, entry_id, social_account_id FROM __temp__discussion_vote WHERE entry_id IS NOT NULL');
        $this->addSql('DROP TABLE __temp__discussion_vote');
        $this->addSql('CREATE INDEX IDX_7F7E2952A76ED395 ON discussion_vote (user_id)');
        $this->addSql('CREATE INDEX idx_discussion_vote_entry ON discussion_vote (entry_id)');
        $this->addSql('CREATE INDEX idx_discussion_vote_account ON discussion_vote (social_account_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_discussion_vote_user_entry ON discussion_vote (user_id, entry_id)');
    }

    private function restoreLikesOnSqlite(): void
    {
        $this->addSql('CREATE TEMPORARY TABLE __temp__social_like AS SELECT reaction.id, reaction.remote_id, reaction.liked_at,'
            . ' reaction.user_id, ' . self::buildFirstCopy() . ' AS entry_id, reaction.social_account_id FROM social_like reaction');
        $this->addSql('DROP TABLE social_like');
        $this->addSql('CREATE TABLE social_like (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, remote_id VARCHAR(512) NOT NULL,'
            . ' liked_at DATETIME NOT NULL, user_id INTEGER NOT NULL, entry_id INTEGER NOT NULL, social_account_id INTEGER NOT NULL,'
            . ' CONSTRAINT fk_social_like_user FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE,'
            . ' CONSTRAINT fk_social_like_entry FOREIGN KEY (entry_id) REFERENCES entry (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE,'
            . ' CONSTRAINT fk_social_like_account FOREIGN KEY (social_account_id) REFERENCES social_account (id) ON DELETE CASCADE'
            . ' NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('INSERT INTO social_like (id, remote_id, liked_at, user_id, entry_id, social_account_id)'
            . ' SELECT id, remote_id, liked_at, user_id, entry_id, social_account_id FROM __temp__social_like WHERE entry_id IS NOT NULL');
        $this->addSql('DROP TABLE __temp__social_like');
        $this->addSql('CREATE INDEX idx_social_like_user ON social_like (user_id)');
        $this->addSql('CREATE INDEX idx_social_like_entry ON social_like (entry_id)');
        $this->addSql('CREATE INDEX idx_social_like_account ON social_like (social_account_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_social_like_user_entry ON social_like (user_id, entry_id)');
    }

    private static function buildFirstCopy(): string
    {
        return '(SELECT MIN(e.id) FROM entry e WHERE e.canonical_item_id = reaction.canonical_item_id)';
    }

    /** Better a refusal than DDL nobody tested. */
    private function abortUnlessSupported(): void
    {
        $platform = $this->connection->getDatabasePlatform();

        $this->abortIf(
            !$platform instanceof AbstractMySQLPlatform && !$platform instanceof SQLitePlatform,
            \sprintf('No DDL defined for platform %s; only MySQL and SQLite are supported.', $platform::class),
        );
    }

    private function isMySql(): bool
    {
        return $this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform;
    }
}
