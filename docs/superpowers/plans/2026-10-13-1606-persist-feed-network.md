# Persist a feed's social network (#1606) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Store which social network a feed belongs to in a nullable, indexed `feed.network` column, and make the network view, network mark-all-read, network refresh and the avatar sweep query that column instead of classifying every subscription in PHP.

**Architecture:** `Service/Social/Support/SocialFeedClassifier` stays the one rule that *computes* the network. A Doctrine `preFlush` entity listener (`Service/Social/FeedNetworkListener`) writes its verdict to `Feed::$network` on every flush. That covers every writer of `url` and `sourceFormat` without touching any call site. A migration adds the column and its index, then backfills existing rows through the same classifier. The repositories then scope by `f.network = :network`:
- `EntryScopePredicates` gets a network join;
- `SubscriptionRepository` gets `findForUserOnNetwork`;
- `FeedRepository` gets a network restriction on `DueFeedCriteria` and an equality test in the avatar sweep.

After that, `NetworkSubscriptions` and the network-only id-list plumbing have no callers left and are deleted.

**Tech Stack:** Symfony 7.4 / PHP 8.4, Doctrine ORM 3.6 / DBAL 4.4 / Migrations 3.9, PHPUnit 12, SQLite (native suite) and MySQL 8.4 (Docker leg).

**Spec:** GitHub issue #1606, plus the agreed design below (Lars, 2026-10-13). The design decisions are binding.

## Design (agreed, binding)

1. **Column** `Feed.network`: nullable `SocialNetwork` enum (`App\Enum\SocialNetwork`), indexed. `Service/Social/Support/SocialFeedClassifier::networkOf()` stays the single rule that *computes* it; the column stores the result.
2. **Sync = option A (chosen by Lars):** a Doctrine `preFlush` entity listener on `Feed` (`#[AsEntityListener(event: Events::preFlush, entity: Feed::class)]`) in `Service/Social`, named `…Listener`, sets the entity's network from `SocialFeedClassifier::networkOf($feed)`. Why a listener: it covers every writer of `url` or `sourceFormat` with no call-site change:
   - `FeedFactory::create`;
   - the `FeedOutcomePersister` permanent redirect `setUrl`;
   - `CatalogFeedEditor` `setUrl`;
   - `E2eSeedAdminSubscriptionCommand`;
   - every `setSourceFormat` caller.

   The entity cannot call the classifier itself (`PersistenceKnowsNoServiceRule`). A functional test (a real flush, then `clear()` and a re-read) proves the column is persisted for new and for updated feeds. It covers:
   - a new private feed;
   - a new Bluesky profile feed and a new Mastodon profile feed;
   - a plain RSS feed, which stores null;
   - a profile feed redirected to a non-profile URL, which goes back to null.
3. **Migration** adds the column and its index. It backfills in PHP: it reads `id, url, source_format`, runs the same classifier on each row, and issues an UPDATE only for feeds that classify. It is dialect-correct on SQLite and MySQL. It is verified by migrating from empty on both legs with `doctrine:schema:validate`, by a backfill check on a scratch database, and by running it against the live Docker dev DB with a SELECT of the backfilled counts.
4. **Consumers switch to the column:**
   - `EntryController` `?network=` uses a predicate on the subscribed feed's network. Subscriptions hidden from All items are included, as today.
   - `MarkReadService`'s network scope queries by feed network.
   - Refresh (`UserRefreshScope`, `RefreshFeedSetModel`, `FeedRepository::restrictToFeedSet`): the feed set carries a `SocialNetwork` instead of a feed-id list. The restriction is the user's subscribed feeds with `f.network = :network`. The run key (`'network-' . value`) stays as it is.
   - `FeedRepository::findBlueskyProfileFeedsWithImageDue` selects `f.network = bluesky` and a format that is not a private (social) one. This replaces the URL LIKE prefix scan.
   - `EntryJson`, `EntryListRowEnricher`, `SocialPostAddress` and `SubscriptionJson` read `$feed->getNetwork()` where they only need the network. `SubscriptionJson` still parses the private address where it needs more (account, kind).
   - `NetworkSubscriptions` and its test are deleted, and so is the network-only id-list plumbing.
5. `SocialFeedClassifier` and `ProfileFeedUrl` methods are removed only if they lose all callers. The listener and the migration still use the classifier.

## Rulings where the design left a choice or the code disagreed

- **Entity API:** the entity method is `Feed::classifyAs(?SocialNetwork $network): void`, with `Feed::getNetwork(): ?SocialNetwork` to read it. The name says the value is a verdict handed in, not a field anyone edits.
- **The classifier gains `networkFor(string $url, string $sourceFormat): ?SocialNetwork`.** `networkOf(Feed)` delegates to it. The migration's backfill calls it on the raw row, so an old migration never depends on the `Feed` constructor. The rule is still one rule.
- **The column lands with its migration (Task 1), the listener in Task 2.** Each commit keeps `doctrine:schema:validate` green and the live Docker app working: an entity mapping without its migration makes the running app 500.
- **`RefreshFeedSetModel` keeps its name and becomes `{ SocialNetwork $network }` with `key(): string`** returning `'network-' . $network->value`. The run-store key stays `set-network-bluesky` byte for byte.
- **`DueFeedCriteria::$feedIds` becomes `?SocialNetwork $network`.** `restrictToFeedSet` becomes `restrictToNetwork`. User ownership is still enforced by `restrictToOwnership`, since a feed-set request always carries the user id.
- **`EntryQuery::$subscriptionIds` becomes `?SocialNetwork $network`.** Its only caller was the network scope; every other `subscriptionIds` in `src` is a request field of the bulk, reorder and tag-order endpoints, which this plan leaves alone. `EntryAliases` gains a `networkFeed` alias (`nf` / `nf2`). `EntryScopePredicates` inner-joins `<entry>.feed` under it with `network = :network`, so the duplicate-collapse semi-join gets the same scope. It works like the tag join.
- **`ActingAccountChooser` is unchanged.** It asks "is this a private feed, and which account owns it", which needs the private address and not the network.
- **`SocialPostAddress::onFeedNetwork()` becomes private.** It existed so the enricher could classify each feed once per page. With the column, the enricher calls `SocialPostAddress::of()`.
- **The backup leaves `network` out.** It is derived: the listener recomputes it on the restore's flush. `BackupSchemaCoverageTest` and `docs/backup.md` record the reason.
- **Frontend untouched.** The JSON shapes of entries, subscriptions and refresh do not change.

## Global Constraints

- CLAUDE.md applies in full: Clean Code, no abbreviations, guard clauses, `final readonly` services, no needless comments, queries only in `src/Repository`, `Response::HTTP_*` for statuses, PSR-12, `declare(strict_types=1)`.
- Every `src` file you touch must be PHPMD-clean before commit (`composer md`). phptramp, `ServiceRoleRule`, `ServiceModuleCycleRule` and `ServiceModuleBoundaryRule` stay green (`composer check`). This plan removes the `Reading → Social` and `Refresh → Social` edges and adds none; `FeedNetworkListener` imports only `App\Entity` and `Service\Social\Support`.
- Datetimes stay naive UTC. This plan writes none.
- Commit messages are `type(#1606): …`, with no attribution or co-author lines. Do not push.
- **Between tasks:** run `git status`, then `git fetch origin develop`. If `git log --oneline HEAD..origin/develop` is non-empty, run `git merge origin/develop`, resolve any conflict, and rerun the previous task's tests before starting the next task. If develop brought a migration newer than `Version20261013150000`, rename this branch's migration to a later timestamp so it still runs last.
- Concurrent sessions share this checkout: never `checkout`, `reset` or `stash` without checking `git status` first. Never `git checkout --` a file to undo a break-test; re-apply the edit by hand.
- Commands run from `backend/` unless stated otherwise. `composer stan` needs a warm dev cache (`bin/console cache:warmup`).
- Never clear or hand-edit the dev database. Migrations against the live Docker DB are additive here (`ADD COLUMN … DEFAULT NULL` plus a backfill), which is allowed without asking.

## File structure

| File | Responsibility |
|---|---|
| `src/Entity/Feed.php` (modify) | `network` column + index, `getNetwork()`, `classifyAs()` |
| `src/Service/Social/Support/SocialFeedClassifier.php` (modify) | `networkFor(url, sourceFormat)`; `networkOf` delegates |
| `migrations/Version20261013150000.php` (create) | column, index, backfill |
| `src/Service/Social/FeedNetworkListener.php` (create) | preFlush: store the classifier's verdict |
| `src/Repository/{EntryQuery,EntryAliases,EntryScopePredicates,DateOrderedPage}.php`, `src/Controller/Api/EntryController.php` (modify) | entry list network scope |
| `src/Repository/SubscriptionRepository.php`, `src/Service/Reading/MarkReadService.php` (modify) | mark-read network scope |
| `src/Service/Refresh/{UserRefreshScope,RefreshRunStore}.php`, `src/Service/Refresh/Model/RefreshFeedSetModel.php`, `src/Service/Refresh/RefreshRunner/RefreshRunner.php`, `src/Repository/{DueFeedCriteria,FeedRepository}.php` (modify) | refresh network scope |
| `src/Service/Social/NetworkSubscriptions.php`, `tests/Service/Social/NetworkSubscriptionsTest.php` (delete) | gone |
| `src/Repository/FeedRepository.php` (modify) | avatar sweep selection |
| `src/Http/{EntryJson,SubscriptionJson}.php`, `src/Repository/EntryListRowEnricher.php`, `src/Service/Social/Support/SocialPostAddress.php` (modify) | readers use `getNetwork()` |
| `tests/Service/Backup/Support/BackupSchemaCoverageTest.php`, `docs/backup.md` (modify) | the backup leaves `network` out |

---

### Task 1: The `network` column, its classifier entry point and the migration

**Files:**
- Modify: `backend/src/Entity/Feed.php`
- Modify: `backend/src/Service/Social/Support/SocialFeedClassifier.php`
- Create: `backend/migrations/Version20261013150000.php`
- Modify: `backend/tests/Service/Social/Support/SocialFeedClassifierTest.php`
- Modify: `backend/tests/Entity/FeedTest.php`
- Modify: `backend/tests/Service/Backup/Support/BackupSchemaCoverageTest.php`
- Modify: `docs/backup.md`

**Interfaces:**
- Produces:
  - `Feed::getNetwork(): ?SocialNetwork`;
  - `Feed::classifyAs(?SocialNetwork $network): void`;
  - `SocialFeedClassifier::networkFor(string $url, string $sourceFormat): ?SocialNetwork`;
  - the column `feed.network VARCHAR(16) NULL` with index `idx_feed_network`.

- [ ] **Step 1: Write the failing tests**

Append to `tests/Service/Social/Support/SocialFeedClassifierTest.php` (inside the class, reusing its `feeds()` provider):

```php
    #[DataProvider('feeds')]
    public function testAStoredRowsUrlAndFormatNameTheSameNetwork(
        string $url,
        string $sourceFormat,
        ?SocialNetwork $network,
    ): void {
        self::assertSame($network, SocialFeedClassifier::networkFor($url, $sourceFormat));
    }
```

Append to `tests/Entity/FeedTest.php` (add `use App\Enum\SocialNetwork;`):

```php
    public function testAFeedStartsWithoutANetworkAndKeepsTheOneItIsClassifiedAs(): void
    {
        $feed = new Feed('https://bsky.app/profile/alice.bsky.social/rss');
        self::assertNull($feed->getNetwork());

        $feed->classifyAs(SocialNetwork::Bluesky);
        self::assertSame(SocialNetwork::Bluesky, $feed->getNetwork());

        $feed->classifyAs(null);
        self::assertNull($feed->getNetwork());
    }
```

- [ ] **Step 2: Run them to verify they fail**

Run: `php bin/phpunit --filter 'SocialFeedClassifierTest|FeedTest::testAFeedStartsWithoutANetwork'`
Expected: FAIL with `Call to undefined method …networkFor()` and `…getNetwork()`.

- [ ] **Step 3: Add the classifier entry point**

In `src/Service/Social/Support/SocialFeedClassifier.php`, replace `networkOf()` and update the class docblock:

```php
/**
 * Which network a feed belongs to: a private feed by its format and synthetic URL, any other feed when its URL is a
 * network's profile feed. FeedNetworkListener stores the verdict in Feed::$network, which every network scope reads.
 */
final class SocialFeedClassifier
{
    public static function networkOf(Feed $feed): ?SocialNetwork
    {
        return self::networkFor($feed->getUrl(), $feed->getSourceFormat());
    }

    public static function networkFor(string $url, string $sourceFormat): ?SocialNetwork
    {
        $privateAddress = SourceFormat::isSocial($sourceFormat) ? SocialFeedUrl::addressOf($url) : null;

        return $privateAddress->network ?? ProfileFeedUrl::networkOf($url);
    }
```

Leave `privateAddressOf()` and the private constructor as they are.

- [ ] **Step 4: Add the column to `Feed`**

In `src/Entity/Feed.php`:
- add `use App\Enum\SocialNetwork;`;
- add the index attribute below the `UniqueConstraint`;
- add the property after `$sourceFormat`;
- add the two methods after `setSourceFormat()`.

```php
#[ORM\UniqueConstraint(name: 'uniq_feed_url', columns: ['url'])]
#[ORM\Index(name: 'idx_feed_network', columns: ['network'])]
final class Feed
```

```php
    /** Derived, never edited: FeedNetworkListener stores SocialFeedClassifier's verdict on every flush (#1606). */
    #[ORM\Column(length: 16, nullable: true, enumType: SocialNetwork::class)]
    private ?SocialNetwork $network = null;
```

```php
    public function getNetwork(): ?SocialNetwork
    {
        return $this->network;
    }

    public function classifyAs(?SocialNetwork $network): void
    {
        $this->network = $network;
    }
```

- [ ] **Step 5: Run the tests to verify they pass**

Run: `php bin/phpunit --filter 'SocialFeedClassifierTest|FeedTest'`
Expected: PASS.

- [ ] **Step 6: Record why the backup leaves the column out**

Run `php bin/phpunit tests/Service/Backup/Support/BackupSchemaCoverageTest.php`. Expected: FAIL, naming `Feed::network` as neither carried nor excused.

In `tests/Service/Backup/Support/BackupSchemaCoverageTest.php`, add to the `Feed::class` map after `'imageCheckedAt'`:

```php
            'network' => 'Derived from url and sourceFormat by SocialFeedClassifier; the restore\'s flush '
                . 'recomputes it through FeedNetworkListener.',
```

In `docs/backup.md`, in the "On a feed." table, add after the `imageCheckedAt` row:

```markdown
| `network` | Which social network the feed belongs to. It follows from the feed's address and format, and the new instance works it out again when it saves the restored feed. |
```

Rerun: `php bin/phpunit tests/Service/Backup/Support/BackupSchemaCoverageTest.php`. Expected: PASS.

- [ ] **Step 7: Write the migration**

Create `migrations/Version20261013150000.php`:

```php
<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Service\Social\Support\SocialFeedClassifier;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261013150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add feed.network and backfill it with SocialFeedClassifier\'s verdict on every feed (#1606).';
    }

    public function up(Schema $schema): void
    {
        $this->skipIf($schema->getTable('feed')->hasColumn('network'), 'feed.network already exists.');

        $this->abortIfUnsupportedPlatform();
        $this->addSql('ALTER TABLE feed ADD network VARCHAR(16) DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_feed_network ON feed (network)');
    }

    public function postUp(Schema $schema): void
    {
        /** @var list<array{id: int|string, url: string, source_format: string}> $feeds */
        $feeds = $this->connection->fetchAllAssociative('SELECT id, url, source_format FROM feed');
        foreach ($feeds as $feed) {
            $network = SocialFeedClassifier::networkFor($feed['url'], $feed['source_format']);
            if (null === $network) {
                continue;
            }
            $this->connection->executeStatement(
                'UPDATE feed SET network = :network WHERE id = :id',
                ['network' => $network->value, 'id' => (int) $feed['id']],
            );
        }
    }

    public function down(Schema $schema): void
    {
        $this->skipIf(!$schema->getTable('feed')->hasColumn('network'), 'feed.network is already gone.');

        $this->abortIfUnsupportedPlatform();
        if ($this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform) {
            $this->addSql('DROP INDEX idx_feed_network ON feed');
        } else {
            $this->addSql('DROP INDEX idx_feed_network');
        }
        $this->addSql('ALTER TABLE feed DROP COLUMN network');
    }

    public function isTransactional(): bool
    {
        return false;
    }

    private function abortIfUnsupportedPlatform(): void
    {
        $platform = $this->connection->getDatabasePlatform();

        $this->abortIf(
            !$platform instanceof AbstractMySQLPlatform && !$platform instanceof SQLitePlatform,
            \sprintf('No DDL defined for platform %s; only MySQL and SQLite are supported.', $platform::class),
        );
    }
}
```

`ALTER TABLE … ADD <column>` and `CREATE INDEX … ON …` are the same on both engines (as in `Version20261012150000`). Only `DROP INDEX` differs. SQLite refuses to drop an indexed column, so `down()` drops the index first. The backfill sits in `postUp()`, which Doctrine runs after `up()`'s SQL has executed (so the column exists) and skips when `up()` was skipped.

- [ ] **Step 8: Verify on SQLite — from empty, and the backfill on rows that exist**

From `backend/`, use a scratch file, never `var/data_dev.db`:

```bash
export DATABASE_URL="sqlite:///%kernel.project_dir%/var/migration-1606.db"
rm -f var/migration-1606.db
bin/console doctrine:migrations:migrate --no-interaction --env=dev
bin/console doctrine:schema:validate --env=dev
```

Expected: `[OK] The database schema is in sync with the mapping files.`

Now check the backfill: step one migration down, seed rows, migrate up again.

```bash
bin/console doctrine:migrations:migrate prev --no-interaction --env=dev
bin/console dbal:run-sql --env=dev "INSERT INTO feed (url, status, source_format, fetch_interval_minutes, consecutive_failures) VALUES
  ('https://bsky.app/profile/alice.bsky.social/rss', 'active', 'xml', 60, 0),
  ('https://mastodon.social/@Gargron.rss', 'active', 'xml', 60, 0),
  ('social:bluesky:4:home', 'active', 'bluesky-api', 60, 0),
  ('social:mastodon:7:list:12', 'active', 'mastodon-api', 60, 0),
  ('social:bluesky:5:home', 'active', 'xml', 60, 0),
  ('https://example.com/feed.xml', 'active', 'xml', 60, 0)"
bin/console doctrine:migrations:migrate --no-interaction --env=dev
bin/console dbal:run-sql --env=dev "SELECT url, source_format, network FROM feed ORDER BY id"
```

Expected `network`, in row order: `bluesky`, `mastodon`, `bluesky`, `mastodon`, `NULL`, `NULL`.

Prove `down()` and re-`up()`:

```bash
bin/console doctrine:migrations:migrate prev --no-interaction --env=dev
bin/console doctrine:migrations:migrate --no-interaction --env=dev
bin/console doctrine:schema:validate --env=dev
rm -f var/migration-1606.db
unset DATABASE_URL
```

Expected: both migrations run without error; validate is `[OK]`.

- [ ] **Step 9: Verify on MySQL — from empty, the backfill, then the live dev DB**

From the repo root, on a scratch MySQL database:

```bash
SCRATCH='mysql://root:root@mysql:3306/feedreader_migration_1606?serverVersion=8.4&charset=utf8mb4'
docker compose exec -T -e DATABASE_URL="$SCRATCH" php bin/console doctrine:database:drop --force --if-exists
docker compose exec -T -e DATABASE_URL="$SCRATCH" php bin/console doctrine:database:create
docker compose exec -T -e DATABASE_URL="$SCRATCH" php bin/console doctrine:migrations:migrate --no-interaction
docker compose exec -T -e DATABASE_URL="$SCRATCH" php bin/console doctrine:schema:validate
docker compose exec -T -e DATABASE_URL="$SCRATCH" php bin/console doctrine:migrations:migrate prev --no-interaction
docker compose exec -T -e DATABASE_URL="$SCRATCH" php bin/console dbal:run-sql "INSERT INTO feed (url, status, source_format, fetch_interval_minutes, consecutive_failures) VALUES ('https://bsky.app/profile/alice.bsky.social/rss','active','xml',60,0), ('https://mastodon.social/@Gargron.rss','active','xml',60,0), ('social:bluesky:4:home','active','bluesky-api',60,0), ('social:bluesky:5:home','active','xml',60,0), ('https://example.com/feed.xml','active','xml',60,0)"
docker compose exec -T -e DATABASE_URL="$SCRATCH" php bin/console doctrine:migrations:migrate --no-interaction
docker compose exec -T -e DATABASE_URL="$SCRATCH" php bin/console dbal:run-sql "SELECT url, source_format, network FROM feed ORDER BY id"
docker compose exec -T -e DATABASE_URL="$SCRATCH" php bin/console doctrine:migrations:migrate prev --no-interaction
docker compose exec -T -e DATABASE_URL="$SCRATCH" php bin/console doctrine:database:drop --force
```

Expected: validate is `[OK]`; `network` reads `bluesky`, `mastodon`, `bluesky`, `NULL`, `NULL`; the `prev` (down) runs clean.

Then apply the migration to the live Docker dev DB (standing rule). Confirm first that it is the only pending migration:

```bash
docker compose exec php bin/console doctrine:migrations:list | grep -i 'not migrated'
docker compose exec php bin/console doctrine:migrations:migrate --no-interaction
docker compose exec php bin/console doctrine:schema:validate
docker compose exec php bin/console dbal:run-sql "SELECT network, source_format, COUNT(*) AS feeds FROM feed GROUP BY network, source_format ORDER BY network, source_format"
```

Expected:
- `Version20261013150000` is the only "not migrated" line;
- validate is `[OK]`;
- the counts show every `bluesky-api` / `mastodon-api` row under its network;
- every `bsky.app/profile/…/rss` and `…/@user.rss` subscription is under `bluesky` / `mastodon`.

Cross-check against the URL shapes:

```bash
docker compose exec php bin/console dbal:run-sql "SELECT COUNT(*) FROM feed WHERE network IS NULL AND (url LIKE '%bsky.app/profile/%/rss' OR source_format IN ('bluesky-api','mastodon-api'))"
```

Expected: `0`.

- [ ] **Step 10: Gates and commit**

Run: `bin/console cache:warmup && composer check && composer md`
Expected: clean. If PHPStan rejects the `@var` shape in the migration, keep the annotation and cast at the use site (`(string) $feed['url']`), as other migrations do. Do not add an ignore.

```bash
git add backend/src/Entity/Feed.php backend/src/Service/Social/Support/SocialFeedClassifier.php \
  backend/migrations/Version20261013150000.php backend/tests/Service/Social/Support/SocialFeedClassifierTest.php \
  backend/tests/Entity/FeedTest.php backend/tests/Service/Backup/Support/BackupSchemaCoverageTest.php docs/backup.md
git commit -m "feat(#1606): store a feed's social network, backfilled by the classifier"
```

---

### Task 2: `FeedNetworkListener` keeps the column in sync on every flush

**Files:**
- Create: `backend/src/Service/Social/FeedNetworkListener.php`
- Create: `backend/tests/Service/Social/FeedNetworkListenerTest.php`

**Interfaces:**
- Consumes: `Feed::classifyAs()` and `SocialFeedClassifier::networkOf()` (Task 1).
- Produces: every flushed `Feed` (inserted, or managed and changed) carries `getNetwork() === SocialFeedClassifier::networkOf($feed)`. Later tasks rely on DB fixtures that flush through the `EntityManager` getting their network for free.

Doctrine behaviour this relies on (read in `vendor/doctrine/orm/src/UnitOfWork.php`, `computeChangeSet()`): the entity-listener `preFlush` is invoked for each entity at the start of its changeset computation, before the property values are read. `computeChangeSets()` runs it for scheduled insertions and for every managed, initialised entity under deferred-implicit tracking. So a value set in the listener lands in the INSERT or UPDATE of the same flush. An unchanged verdict yields no changeset, so the listener writes no extra UPDATE.

- [ ] **Step 1: Write the failing functional test**

Create `tests/Service/Social/FeedNetworkListenerTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Social;

use App\Entity\Feed;
use App\Enum\SocialNetwork;
use App\Enum\SourceFormat;
use App\Tests\DbTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class FeedNetworkListenerTest extends DbTestCase
{
    /** @return iterable<string, array{string, string, SocialNetwork|null}> */
    public static function newFeeds(): iterable
    {
        yield 'a Bluesky private feed' => ['social:bluesky:4:home', SourceFormat::BLUESKY_API, SocialNetwork::Bluesky];
        yield 'a Mastodon private feed' => ['social:mastodon:7:list:12', SourceFormat::MASTODON_API, SocialNetwork::Mastodon];
        yield 'a Bluesky profile feed' => [
            'https://bsky.app/profile/alice.bsky.social/rss',
            SourceFormat::XML,
            SocialNetwork::Bluesky,
        ];
        yield 'a Mastodon profile feed' => ['https://mastodon.social/@Gargron.rss', SourceFormat::XML, SocialNetwork::Mastodon];
        yield 'an ordinary feed' => ['https://example.com/feed.xml', SourceFormat::XML, null];
    }

    #[DataProvider('newFeeds')]
    public function testANewFeedIsStoredWithItsNetwork(string $url, string $sourceFormat, ?SocialNetwork $network): void
    {
        $feed = new Feed($url);
        $feed->setSourceFormat($sourceFormat);
        $this->entityManager->persist($feed);
        $this->entityManager->flush();

        self::assertSame($network, $this->storedNetworkOf($feed->requireId()));
    }

    public function testAProfileFeedRedirectedToAnOrdinaryUrlLosesItsNetwork(): void
    {
        $feedId = $this->storedFeed('https://bsky.app/profile/alice.bsky.social/rss', SourceFormat::XML);
        $reloaded = $this->entityManager->find(Feed::class, $feedId);
        self::assertNotNull($reloaded);

        $reloaded->setUrl('https://alice.example.com/feed.xml');
        $this->entityManager->flush();

        self::assertNull($this->storedNetworkOf($feedId));
    }

    public function testAnOrdinaryFeedRedirectedToAProfileFeedGainsItsNetwork(): void
    {
        $feedId = $this->storedFeed('https://example.com/gargron.xml', SourceFormat::XML);
        $reloaded = $this->entityManager->find(Feed::class, $feedId);
        self::assertNotNull($reloaded);

        $reloaded->setUrl('https://mastodon.social/@Gargron.rss');
        $this->entityManager->flush();

        self::assertSame(SocialNetwork::Mastodon, $this->storedNetworkOf($feedId));
    }

    public function testAFeedWhoseFormatBecomesPrivateGainsItsNetwork(): void
    {
        $feedId = $this->storedFeed('social:bluesky:4:home', SourceFormat::XML);
        self::assertNull($this->storedNetworkOf($feedId));
        $reloaded = $this->entityManager->find(Feed::class, $feedId);
        self::assertNotNull($reloaded);

        $reloaded->setSourceFormat(SourceFormat::BLUESKY_API);
        $this->entityManager->flush();

        self::assertSame(SocialNetwork::Bluesky, $this->storedNetworkOf($feedId));
    }

    private function storedFeed(string $url, string $sourceFormat): int
    {
        $feed = new Feed($url);
        $feed->setSourceFormat($sourceFormat);
        $this->entityManager->persist($feed);
        $this->entityManager->flush();
        $this->entityManager->clear();

        return $feed->requireId();
    }

    private function storedNetworkOf(int $feedId): ?SocialNetwork
    {
        $this->entityManager->clear();
        $stored = $this->entityManager->find(Feed::class, $feedId);
        self::assertNotNull($stored);

        return $stored->getNetwork();
    }
}
```

The reads go through `clear()` and `find()`, so every assertion sees the database row, not the object the test changed. The updates load the feed fresh from the database, which is the path a refresh redirect and a catalog edit take.

- [ ] **Step 2: Run it to verify it fails**

Run: `php bin/phpunit tests/Service/Social/FeedNetworkListenerTest.php`
Expected: FAIL. The four network-bearing cases and the two "gains" tests read `null`. The ordinary-feed case and the redirect-loses test pass vacuously; the break-test in Step 5 makes them meaningful.

- [ ] **Step 3: Write the listener**

Create `src/Service/Social/FeedNetworkListener.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Social;

use App\Entity\Feed;
use App\Service\Social\Support\SocialFeedClassifier;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsEntityListener;
use Doctrine\ORM\Events;

#[AsEntityListener(event: Events::preFlush, method: 'preFlush', entity: Feed::class)]
final readonly class FeedNetworkListener
{
    public function preFlush(Feed $feed): void
    {
        $feed->classifyAs(SocialFeedClassifier::networkOf($feed));
    }
}
```

Doctrine's `ListenersInvoker` passes `(entity, PreFlushEventArgs)`. The method declares only the entity it reads, and PHP accepts the surplus argument.

- [ ] **Step 4: Run it to verify it passes**

Run: `php bin/phpunit tests/Service/Social/FeedNetworkListenerTest.php`
Expected: PASS (8 tests).

- [ ] **Step 5: Break-test the redirect case**

Temporarily change the listener body to `$feed->classifyAs(SocialFeedClassifier::networkOf($feed) ?? $feed->getNetwork());`. That is the "only ever sets, never clears" bug. Run the test file.
Expected: `testAProfileFeedRedirectedToAnOrdinaryUrlLosesItsNetwork` FAILS with `Failed asserting that enum(…SocialNetwork::Bluesky) is null`.
Restore the body by hand (no `git checkout --`) and rerun: PASS.

- [ ] **Step 6: Run the suites the listener now touches**

Run: `php bin/phpunit tests/Service/Social tests/Repository tests/Service/Refresh tests/Service/Reading`
Expected: PASS. No reader uses the column yet, so nothing changes in behaviour.

- [ ] **Step 7: Gates and commit**

Run: `bin/console cache:warmup && composer check && composer md`
Expected: clean. `ServiceRoleRule` accepts a root service named `…Listener`, and `ServiceModuleCycleRule` sees no new module edge.

```bash
git add backend/src/Service/Social/FeedNetworkListener.php backend/tests/Service/Social/FeedNetworkListenerTest.php
git commit -m "feat(#1606): keep a feed's stored network the classifier's verdict on every flush"
```

---

### Task 3: The entry list scopes a network by `feed.network`

**Files:**
- Modify: `backend/src/Repository/EntryQuery.php`
- Modify: `backend/src/Repository/EntryAliases.php`
- Modify: `backend/src/Repository/EntryScopePredicates.php`
- Modify: `backend/src/Repository/DateOrderedPage.php` (docblock wording)
- Modify: `backend/src/Controller/Api/EntryController.php`
- Modify: `backend/tests/Repository/EntryQueryTest.php`
- Modify: `backend/tests/Repository/EntryListTest.php`
- Unchanged, must stay green: `backend/tests/Controller/Api/EntryNetworkScopeTest.php`

**Interfaces:**
- Consumes: the column and the listener (Tasks 1–2).
- Produces:
  - `EntryQuery::__construct(…, ListOrder $order = ListOrder::NewestFirst, ?SocialNetwork $network = null)`, which replaces `?array $subscriptionIds`;
  - `public ?SocialNetwork $network`;
  - `EntryAliases::$networkFeed` (`'nf'` primary, `'nf2'` collapse).

- [ ] **Step 1: Rewrite the failing tests**

In `tests/Repository/EntryQueryTest.php` (add `use App\Enum\SocialNetwork;`), replace `testASubscriptionSetScopedViewNeverHides` and `testATagOrSubscriptionSetNarrowsTheFanIn`:

```php
    public function testANetworkScopedViewNeverHides(): void
    {
        self::assertFalse((new EntryQuery(1, EntryView::All, network: SocialNetwork::Bluesky))->hidesExcludedFeeds());
        self::assertFalse((new EntryQuery(1, EntryView::Unread, network: SocialNetwork::Mastodon))->hidesExcludedFeeds());
    }
```

```php
    public function testATagOrANetworkNarrowsTheFanIn(): void
    {
        self::assertTrue((new EntryQuery(1, EntryView::All, tagId: 3))->isNarrowedFanIn());
        self::assertTrue((new EntryQuery(1, EntryView::Unread, network: SocialNetwork::Bluesky))->isDateOrderedFanIn());
        self::assertTrue((new EntryQuery(1, EntryView::Unread, network: SocialNetwork::Bluesky))->isNarrowedFanIn());
        self::assertFalse((new EntryQuery(1, EntryView::All))->isNarrowedFanIn());
    }
```

In `tests/Repository/EntryListTest.php`, add `use App\Enum\SocialNetwork;` and `use App\Tests\Support\NetworkFeedSubscriptions;`. Replace the three tests `testASubscriptionSetListsOnlyItsFeedsExcludedOnesIncluded`, `testAnEmptySubscriptionSetListsNothing` and `testASubscriptionSetIsWindowedLikeATag` with:

```php
    public function testANetworkListsOnlyItsFeedsExcludedOnesIncluded(): void
    {
        [$profile, $profileSubscription] = $this->secondSubscription(NetworkFeedSubscriptions::BLUESKY_PROFILE);
        $profileSubscription->setIncludeInAllItems(false);
        [$mastodon] = $this->secondSubscription(NetworkFeedSubscriptions::MASTODON_PROFILE);
        $this->entityManager->flush();
        $this->entry('plain', '2026-07-05T00:00:00Z');
        $this->entryIn($profile, 'bluesky', '2026-07-06T00:00:00Z');
        $this->entryIn($mastodon, 'mastodon', '2026-07-07T00:00:00Z');

        $rows = $this->repository()->listForUser(
            new EntryQuery($this->user->requireId(), network: SocialNetwork::Bluesky),
        );

        self::assertSame(['bluesky'], $this->guids($rows));
    }

    public function testANetworkWithoutFeedsListsNothing(): void
    {
        $this->entry('plain', '2026-07-05T00:00:00Z');

        $rows = $this->repository()->listForUser(
            new EntryQuery($this->user->requireId(), network: SocialNetwork::Mastodon),
        );

        self::assertSame([], $rows);
    }

    public function testANetworkIsWindowedLikeATag(): void
    {
        [$profile] = $this->secondSubscription(NetworkFeedSubscriptions::BLUESKY_PROFILE);
        $firstEntry = $this->entryIn($profile, 's1', '2026-07-10T00:00:00Z');
        $secondEntry = $this->entryIn($profile, 's2', '2026-07-09T00:00:00Z');
        $this->entryIn($this->fillerFeed(), 'filler', '2026-07-08T00:00:00Z');
        $query = new EntryQuery($this->user->requireId(), limit: 2, network: SocialNetwork::Bluesky);

        $recorded = $this->recordedList($this->repositoryWithWindow(2), $query);

        self::assertCount(2, $recorded['queries'], 'a full windowed page must never fall back');
        self::assertSame([$firstEntry->getGuid(), $secondEntry->getGuid()], $this->guids($recorded['rows']));
    }

    public function testACopyOutsideTheNetworkNeverHidesTheNetworksOwnCopy(): void
    {
        [$profile] = $this->secondSubscription(NetworkFeedSubscriptions::BLUESKY_PROFILE);
        $at = new \DateTimeImmutable('2026-07-05T00:00:00Z');
        $this->entityManager->persist(
            new Entry($this->feed, 'plain-copy', 'https://example.com/copy', 'Plain copy', $at, $at, 'shared-url-hash'),
        );
        $this->entityManager->persist(
            new Entry($profile, 'network-copy', 'https://bsky.app/copy', 'Network copy', $at, $at, 'shared-url-hash'),
        );
        $this->entityManager->flush();

        $rows = $this->repository()->listForUser(
            new EntryQuery($this->user->requireId(), network: SocialNetwork::Bluesky),
        );

        self::assertSame(['network-copy'], $this->guids($rows));
        self::assertSame([], $rows[0]->duplicates);
    }
```

The last test pins the collapse semi-join. If the inner query lost the network scope, the plain copy (lower id) would hide the network's copy, and the page would come back empty.

- [ ] **Step 2: Run them to verify they fail**

Run: `php bin/phpunit --filter 'EntryQueryTest|EntryListTest'`
Expected: FAIL with `Unknown named parameter $network`.

- [ ] **Step 3: Replace the id list with the network on `EntryQuery`**

In `src/Repository/EntryQuery.php`:
- add `use App\Enum\SocialNetwork;`;
- drop the `@param list<int>|null $subscriptionIds` docblock;
- change the last constructor parameter and `isNarrowed()`;
- change the docblock of `isNarrowedFanIn()` to "A fan-in list narrowed to one tag or a network".

```php
    public function __construct(
        public int $userId,
        public EntryView $view = EntryView::All,
        public ?int $subscriptionId = null,
        public ?int $tagId = null,
        public ?EntryCursor $cursor = null,
        int $limit = self::DEFAULT_LIMIT,
        public ListOrder $order = ListOrder::NewestFirst,
        public ?SocialNetwork $network = null,
    ) {
```

```php
    private function isNarrowed(): bool
    {
        return $this->tagId !== null || $this->network !== null;
    }
```

In `src/Repository/DateOrderedPage.php`'s class docblock, change "a possibly-sparse tag or subscription-set scope" to "a possibly-sparse tag or network scope".

- [ ] **Step 4: Add the alias and the predicate**

`src/Repository/EntryAliases.php`:

```php
    public function __construct(
        public string $entry,
        public string $state,
        public string $subscription,
        public string $tag,
        public string $networkFeed,
    ) {
    }

    public static function primary(): self
    {
        return new self('e', 'es', 's', 'st', 'nf');
    }

    public static function collapse(): self
    {
        return new self('e2', 'es2', 's2', 'st2', 'nf2');
    }
```

In `src/Repository/EntryScopePredicates.php`:
- add `use App\Enum\SocialNetwork;`;
- replace the `subscriptionIds` branch in `applyList()`;
- replace the private `inSubscriptions()` with `onNetwork()`.

```php
        if ($query->network !== null) {
            $this->onNetwork($qb, $aliases, $query->network);
        }
```

```php
    private function onNetwork(QueryBuilder $qb, EntryAliases $aliases, SocialNetwork $network): void
    {
        $qb->innerJoin(
            \sprintf('%s.feed', $aliases->entry),
            $aliases->networkFeed,
            'WITH',
            \sprintf('%s.network = :network', $aliases->networkFeed),
        )->setParameter('network', $network);
    }
```

The collapse semi-join is rendered from its DQL alone, so its `:network` binds to the outer query's parameter. This is the same as `:tagId` today.

- [ ] **Step 5: Pass the network from the controller**

In `src/Controller/Api/EntryController.php`:
- remove `use App\Service\Social\NetworkSubscriptions;` and the `private NetworkSubscriptions $networkSubscriptions,` constructor parameter;
- replace the `subscriptionIds:` argument:

```php
            order: $listOrder,
            network: $socialNetwork,
        );
```

- [ ] **Step 6: Run the tests to verify they pass**

Run: `php bin/phpunit --filter 'EntryQueryTest|EntryListTest|EntryNetworkScopeTest|EntryControllerTest'`
Expected: PASS. `EntryNetworkScopeTest` is unchanged and still lists exactly `['profile-post', 'home-post']` for Bluesky, the hidden-from-All home feed included.

Confirm that nothing else still names the old field:
`grep -rn "subscriptionIds" src/Repository src/Controller/Api/EntryController.php tests/Repository`
Expected: only `SubscriptionRepository`'s `findAllByIds…` parameters, which this plan does not touch.

- [ ] **Step 7: Gates and commit**

Run: `bin/console cache:warmup && composer check && composer md`
Expected: clean.

```bash
git add backend/src/Repository/EntryQuery.php backend/src/Repository/EntryAliases.php \
  backend/src/Repository/EntryScopePredicates.php backend/src/Repository/DateOrderedPage.php \
  backend/src/Controller/Api/EntryController.php backend/tests/Repository/EntryQueryTest.php \
  backend/tests/Repository/EntryListTest.php
git commit -m "refactor(#1606): scope the entry list to a network by the feed's stored network"
```

---

### Task 4: Mark-all-read of a network by `feed.network`

**Files:**
- Modify: `backend/src/Repository/SubscriptionRepository.php`
- Modify: `backend/src/Service/Reading/MarkReadService.php`
- Create: `backend/tests/Repository/SubscriptionRepositoryNetworkTest.php`
- Unchanged, must stay green: `backend/tests/Service/Reading/MarkReadServiceTest.php` (`testNetworkScopeMarksExactlyTheNetworksFeedsExcludedOnesIncluded`), `backend/tests/Controller/Api/EntryNetworkScopeTest.php`

**Interfaces:**
- Consumes: the column and the listener (Tasks 1–2).
- Produces: `SubscriptionRepository::findForUserOnNetwork(int $userId, SocialNetwork $network): list<Subscription>`. It returns the user's subscriptions whose feed is on `$network`, hidden-from-All included, feed fetch-joined, ordered by subscription id.

- [ ] **Step 1: Write the failing repository test**

This carries `NetworkSubscriptionsTest`'s three cases over to the query that replaces it. Create `tests/Repository/SubscriptionRepositoryNetworkTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\Subscription;
use App\Entity\User;
use App\Enum\SocialNetwork;
use App\Enum\SourceFormat;
use App\Repository\SubscriptionRepository;
use App\Tests\DbTestCase;
use App\Tests\Support\NetworkFeedSubscriptions;

final class SubscriptionRepositoryNetworkTest extends DbTestCase
{
    public function testANetworkHoldsItsPrivateAndProfileFeedsExcludedOnesIncluded(): void
    {
        $user = $this->user('network-owner@example.com');
        $home = $this->fixtures()->subscribe(
            $user,
            NetworkFeedSubscriptions::blueskyHome(1),
            SourceFormat::BLUESKY_API,
        );
        $home->setIncludeInAllItems(false);
        $profile = $this->fixtures()->subscribe($user, NetworkFeedSubscriptions::BLUESKY_PROFILE);
        $this->fixtures()->subscribe($user, NetworkFeedSubscriptions::MASTODON_PROFILE);
        $this->fixtures()->subscribe($user, NetworkFeedSubscriptions::PLAIN);
        $this->entityManager->flush();

        self::assertSame(
            [$home->requireId(), $profile->requireId()],
            $this->idsOf($this->subscriptions()->findForUserOnNetwork($user->requireId(), SocialNetwork::Bluesky)),
        );
    }

    public function testAnotherUsersFeedsOfTheNetworkAreNeverIncluded(): void
    {
        $user = $this->user('network-self@example.com');
        $own = $this->fixtures()->subscribe($user, NetworkFeedSubscriptions::MASTODON_PROFILE);
        $stranger = $this->user('network-stranger@example.com');
        $this->fixtures()->subscribe($stranger, 'https://fosstodon.org/@bob.rss');

        self::assertSame(
            [$own->requireId()],
            $this->idsOf($this->subscriptions()->findForUserOnNetwork($user->requireId(), SocialNetwork::Mastodon)),
        );
    }

    public function testANetworkWithoutFeedsHoldsNone(): void
    {
        $user = $this->user('network-empty@example.com');
        $this->fixtures()->subscribe($user, NetworkFeedSubscriptions::PLAIN);

        self::assertSame([], $this->subscriptions()->findForUserOnNetwork($user->requireId(), SocialNetwork::Bluesky));
    }

    /**
     * @param list<Subscription> $subscriptions
     *
     * @return list<int>
     */
    private function idsOf(array $subscriptions): array
    {
        return array_map(static fn (Subscription $subscription): int => $subscription->requireId(), $subscriptions);
    }

    private function user(string $email): User
    {
        $user = new User($email, new \DateTimeImmutable('2026-10-01T00:00:00Z'));
        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return $user;
    }

    private function fixtures(): NetworkFeedSubscriptions
    {
        return new NetworkFeedSubscriptions($this->entityManager);
    }

    private function subscriptions(): SubscriptionRepository
    {
        $repository = $this->entityManager->getRepository(Subscription::class);
        self::assertInstanceOf(SubscriptionRepository::class, $repository);

        return $repository;
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `php bin/phpunit tests/Repository/SubscriptionRepositoryNetworkTest.php`
Expected: FAIL with `Call to undefined method …findForUserOnNetwork()`.

- [ ] **Step 3: Add the query**

In `src/Repository/SubscriptionRepository.php`, add `use App\Enum\SocialNetwork;` and, after `findForUserByTagId()`:

```php
    /**
     * The user's subscriptions to one network's feeds, private and profile alike, whether All items shows them or not.
     *
     * @return list<Subscription>
     */
    public function findForUserOnNetwork(int $userId, SocialNetwork $network): array
    {
        /** @var list<Subscription> $rows */
        $rows = $this->createQueryBuilder('s')
            ->join('s.feed', 'f')->addSelect('f')
            ->andWhere('s.user = :userId')->setParameter('userId', $userId)
            ->andWhere('f.network = :network')->setParameter('network', $network)
            ->orderBy('s.id', 'ASC')
            ->getQuery()
            ->getResult();

        return $rows;
    }
```

- [ ] **Step 4: Switch `MarkReadService`**

In `src/Service/Reading/MarkReadService.php`:
- remove `use App\Service\Social\NetworkSubscriptions;` and the `private NetworkSubscriptions $networkSubscriptions,` constructor parameter;
- change the network arm:

```php
            ReadScopeKind::Network => $this->subscriptions->findForUserOnNetwork($userId, $scope->targetNetwork()),
```

- [ ] **Step 5: Run the tests to verify they pass**

Run: `php bin/phpunit --filter 'SubscriptionRepositoryNetworkTest|MarkReadServiceTest|EntryNetworkScopeTest'`
Expected: PASS. `MarkReadServiceTest`'s network case is unchanged: it still advances exactly the Bluesky home and profile watermarks, and never the Mastodon, plain or stranger ones.

- [ ] **Step 6: Gates and commit**

Run: `bin/console cache:warmup && composer check && composer md`
Expected: clean. `Reading` no longer names `Social` (the cycle collector drops the edge).

```bash
git add backend/src/Repository/SubscriptionRepository.php backend/src/Service/Reading/MarkReadService.php \
  backend/tests/Repository/SubscriptionRepositoryNetworkTest.php
git commit -m "refactor(#1606): mark a network read by the feed's stored network"
```

---

### Task 5: Refresh a network by `feed.network`; delete `NetworkSubscriptions`

**Files:**
- Modify: `backend/src/Service/Refresh/Model/RefreshFeedSetModel.php`
- Modify: `backend/src/Service/Refresh/UserRefreshScope.php`
- Modify: `backend/src/Service/Refresh/RefreshRunStore.php`
- Modify: `backend/src/Service/Refresh/RefreshRunner/RefreshRunner.php`
- Modify: `backend/src/Repository/DueFeedCriteria.php`
- Modify: `backend/src/Repository/FeedRepository.php` (`restrictToFeedSet` → `restrictToNetwork`)
- Delete: `backend/src/Service/Social/NetworkSubscriptions.php`, `backend/tests/Service/Social/NetworkSubscriptionsTest.php`
- Rename: `backend/tests/Repository/FeedRepositoryFeedSetScopeTest.php` → `backend/tests/Repository/FeedRepositoryNetworkScopeTest.php`
- Create: `backend/tests/Service/Refresh/Model/RefreshFeedSetModelTest.php`
- Modify: `backend/tests/Service/Refresh/UserRefreshScopeTest.php`, `backend/tests/Service/Refresh/RefreshRunStoreTest.php`, `backend/tests/Service/Refresh/Model/RefreshRequestModelTest.php`, `backend/tests/Repository/FeedRepositoryTest.php`
- Unchanged, must stay green: `backend/tests/Controller/Api/RefreshControllerTest.php` (`testNetworkRefreshSweepsOnlyThatNetworksFeeds`)

**Interfaces:**
- Consumes: the column and the listener (Tasks 1–2).
- Produces:
  - `RefreshFeedSetModel::__construct(SocialNetwork $network)`, with `public SocialNetwork $network` and `key(): string` (`'network-' . $network->value`);
  - `DueFeedCriteria::__construct(…, array $excludedFeedIds = [], ?SocialNetwork $network = null)`, which replaces `?array $feedIds`;
  - `public ?SocialNetwork $network`.
  - `RefreshRequestModel` is unchanged (`forUserFeedSet(int, RefreshFeedSetModel, int)`, `?RefreshFeedSetModel $feedSet`).

- [ ] **Step 1: Write the failing tests**

Create `tests/Service/Refresh/Model/RefreshFeedSetModelTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Refresh\Model;

use App\Enum\SocialNetwork;
use App\Service\Refresh\Model\RefreshFeedSetModel;
use PHPUnit\Framework\TestCase;

final class RefreshFeedSetModelTest extends TestCase
{
    public function testANetworksSetIsKeyedByTheNetwork(): void
    {
        self::assertSame('network-bluesky', (new RefreshFeedSetModel(SocialNetwork::Bluesky))->key());
        self::assertSame('network-mastodon', (new RefreshFeedSetModel(SocialNetwork::Mastodon))->key());
    }
}
```

In `tests/Service/Refresh/Model/RefreshRequestModelTest.php`, add `use App\Enum\SocialNetwork;` and replace the two feed-set tests:

```php
    public function testAFeedSetRequestRefreshesThatNetworksFeedsOfTheUserIgnoringTheSchedule(): void
    {
        $request = RefreshRequestModel::forUserFeedSet(7, new RefreshFeedSetModel(SocialNetwork::Bluesky), 30);

        self::assertSame(7, $request->userId);
        self::assertSame(SocialNetwork::Bluesky, $request->feedSet?->network);
        self::assertNull($request->feedId);
        self::assertNull($request->tagId);
        self::assertTrue($request->force);
        self::assertFalse($request->prune);
    }

    public function testDerivedRequestsKeepTheFeedSet(): void
    {
        $feedSet = new RefreshFeedSetModel(SocialNetwork::Bluesky);
        $request = RefreshRequestModel::forUserFeedSet(7, $feedSet, 30);

        self::assertSame($feedSet, $request->withoutPruning()->feedSet);
        self::assertSame($feedSet, $request->ignoringSchedule()->feedSet);
        self::assertSame($feedSet, $request->limitedTo(5)->feedSet);
    }
```

In `tests/Service/Refresh/RefreshRunStoreTest.php` (add `use App\Enum\SocialNetwork;`):
- in `testFeedSetRunsAreKeptApartFromEachOtherAndFromTheSweep`, call `$this->feedSetRequest(SocialNetwork::Bluesky)` and `$this->feedSetRequest(SocialNetwork::Mastodon)`;
- replace the helper and the last assertion of `testTheCacheKeyEncodesTheUserAndTheRequestsScope`:

```php
    private function feedSetRequest(SocialNetwork $network): RefreshRequestModel
    {
        return RefreshRequestModel::forUserFeedSet(1, new RefreshFeedSetModel($network), self::BUDGET);
    }
```

```php
        self::assertSame(
            ['refresh_run_3.set-network-bluesky'],
            $this->rawKeyFor(
                RefreshRequestModel::forUserFeedSet(3, new RefreshFeedSetModel(SocialNetwork::Bluesky), self::BUDGET),
            ),
        );
```

In `tests/Service/Refresh/UserRefreshScopeTest.php`, replace `testANetworkRefreshesExactlyItsFeedsOfTheUser`. The "exactly its feeds" part moves to the repository test below. Remove the now-unused imports `SourceFormat` and `NetworkFeedSubscriptions` if nothing else in the file uses them.

```php
    public function testANetworkRefreshIsAForcedRunOverThatNetworksFeedSet(): void
    {
        $request = $this->scope()->requestForNetwork($this->user->requireId(), SocialNetwork::Bluesky);

        self::assertSame($this->user->requireId(), $request->userId);
        self::assertNotNull($request->feedSet);
        self::assertSame(SocialNetwork::Bluesky, $request->feedSet->network);
        self::assertSame('network-bluesky', $request->feedSet->key());
        self::assertNull($request->feedId);
        self::assertNull($request->tagId);
        self::assertTrue($request->force);
        self::assertSame(25, $request->budgetSeconds);
    }
```

In `tests/Repository/FeedRepositoryTest.php::testExcludingKeepsEveryOtherScope` (add `use App\Enum\SocialNetwork;`), add `network: SocialNetwork::Mastodon,` as the last named argument of `$original`, and add this assertion:

```php
        self::assertSame(SocialNetwork::Mastodon, $narrowed->network);
```

Rename the feed-set repository test and rewrite it:

```bash
git mv backend/tests/Repository/FeedRepositoryFeedSetScopeTest.php backend/tests/Repository/FeedRepositoryNetworkScopeTest.php
```

Its full new content:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\Feed;
use App\Entity\User;
use App\Enum\SocialNetwork;
use App\Enum\SourceFormat;
use App\Repository\DueFeedCriteria;
use App\Repository\FeedRepository;
use App\Tests\DbTestCase;
use App\Tests\Support\NetworkFeedSubscriptions;

final class FeedRepositoryNetworkScopeTest extends DbTestCase
{
    private const string NOW = '2026-06-01T00:00:00Z';

    public function testANetworkScopeSelectsOnlyThatNetworksFeedsOfTheUser(): void
    {
        $owner = $this->user('network-scope-owner@example.com');
        $home = $this->fixtures()->subscribe(
            $owner,
            NetworkFeedSubscriptions::blueskyHome(1),
            SourceFormat::BLUESKY_API,
        )->getFeed();
        $profile = $this->fixtures()->subscribe($owner, NetworkFeedSubscriptions::BLUESKY_PROFILE)->getFeed();
        $this->fixtures()->subscribe($owner, NetworkFeedSubscriptions::MASTODON_PROFILE);
        $this->fixtures()->subscribe($owner, NetworkFeedSubscriptions::PLAIN);

        $criteria = $this->criteria($owner, SocialNetwork::Bluesky);

        self::assertSame(
            [$home->requireId(), $profile->requireId()],
            $this->idsOf($this->feeds()->findDue($criteria, 50)),
        );
        self::assertSame(2, $this->feeds()->countDue($criteria));
    }

    public function testANetworkWithoutFeedsSelectsNothing(): void
    {
        $owner = $this->user('network-scope-empty@example.com');
        $this->fixtures()->subscribe($owner, NetworkFeedSubscriptions::PLAIN);

        $criteria = $this->criteria($owner, SocialNetwork::Mastodon);

        self::assertSame([], $this->feeds()->findDue($criteria, 50));
        self::assertSame(0, $this->feeds()->countDue($criteria));
    }

    public function testANetworkScopeNeverReachesAFeedTheUserDoesNotSubscribeTo(): void
    {
        $owner = $this->user('network-scope-self@example.com');
        $stranger = $this->user('network-scope-stranger@example.com');
        $this->fixtures()->subscribe($stranger, 'https://bsky.app/profile/bob.bsky.social/rss');

        self::assertSame([], $this->feeds()->findDue($this->criteria($owner, SocialNetwork::Bluesky), 50));
    }

    private function criteria(User $owner, SocialNetwork $network): DueFeedCriteria
    {
        return new DueFeedCriteria(
            new \DateTimeImmutable(self::NOW),
            $owner->requireId(),
            force: true,
            network: $network,
        );
    }

    /**
     * @param list<Feed> $feeds
     *
     * @return list<int>
     */
    private function idsOf(array $feeds): array
    {
        return array_map(static fn (Feed $feed): int => $feed->requireId(), $feeds);
    }

    private function fixtures(): NetworkFeedSubscriptions
    {
        return new NetworkFeedSubscriptions($this->entityManager);
    }

    private function user(string $email): User
    {
        $user = new User($email, new \DateTimeImmutable('2026-01-01T00:00:00Z'));
        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return $user;
    }

    private function feeds(): FeedRepository
    {
        $repository = $this->entityManager->getRepository(Feed::class);
        self::assertInstanceOf(FeedRepository::class, $repository);

        return $repository;
    }
}
```

`findDue` orders never-scheduled feeds by id, so the home feed (created first) comes first. A private feed with no `SocialFeed` row passes `restrictToConnectedAccounts`.

- [ ] **Step 2: Run them to verify they fail**

Run: `php bin/phpunit --filter 'RefreshFeedSetModelTest|RefreshRequestModelTest|RefreshRunStoreTest|UserRefreshScopeTest|FeedRepositoryNetworkScopeTest|FeedRepositoryTest'`
Expected: FAIL with `Unknown named parameter $network` and wrong argument types for `RefreshFeedSetModel`.

- [ ] **Step 3: The feed set carries a network**

Replace `src/Service/Refresh/Model/RefreshFeedSetModel.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Refresh\Model;

use App\Enum\SocialNetwork;

/** The user's feeds on one network, refreshed together; the key keeps its run apart. */
final readonly class RefreshFeedSetModel
{
    public function __construct(public SocialNetwork $network)
    {
    }

    public function key(): string
    {
        return 'network-' . $this->network->value;
    }
}
```

In `src/Service/Refresh/RefreshRunStore.php::scopeOf()`:

```php
        if (null !== $request->feedSet) {
            return 'set-' . $request->feedSet->key();
        }
```

In `src/Service/Refresh/RefreshRunner/RefreshRunner.php::dueCriteria()`, replace the last argument:

```php
            network: $request->feedSet?->network,
```

- [ ] **Step 4: The criteria and the repository restrict by network**

In `src/Repository/DueFeedCriteria.php`:
- add `use App\Enum\SocialNetwork;`;
- drop the `@param list<int>|null $feedIds` line;
- replace the parameter and its pass-through in `excluding()`:

```php
    /**
     * @param list<int> $excludedFeedIds
     */
    public function __construct(
        public \DateTimeImmutable $now,
        public ?int $userId = null,
        public ?int $feedId = null,
        public ?int $tagId = null,
        public bool $force = false,
        public ?\DateTimeImmutable $cooldownCutoff = null,
        public array $excludedFeedIds = [],
        public ?SocialNetwork $network = null,
    ) {
    }
```

```php
        return new self(
            $this->now,
            $this->userId,
            $this->feedId,
            $this->tagId,
            $this->force,
            $this->cooldownCutoff,
            $feedIds,
            $this->network,
        );
```

In `src/Repository/FeedRepository.php`, call `$this->restrictToNetwork($qb, $criteria);` in `dueQueryBuilder()` where `restrictToFeedSet` was, and replace that method:

```php
    private function restrictToNetwork(QueryBuilder $qb, DueFeedCriteria $criteria): void
    {
        if ($criteria->network === null) {
            return;
        }

        $qb->andWhere('f.network = :network')->setParameter('network', $criteria->network);
    }
```

- [ ] **Step 5: `UserRefreshScope` builds the request without a query; delete `NetworkSubscriptions`**

In `src/Service/Refresh/UserRefreshScope.php`:
- remove `use App\Entity\Subscription;`, `use App\Service\Social\NetworkSubscriptions;` and the `private NetworkSubscriptions $networkSubscriptions,` constructor parameter;
- replace `requestForNetwork()`:

```php
    public function requestForNetwork(int $userId, SocialNetwork $network): RefreshRequestModel
    {
        return RefreshRequestModel::forUserFeedSet($userId, new RefreshFeedSetModel($network), self::BUDGET_SECONDS);
    }
```

Then delete the class and its test, which now have no caller:

```bash
git rm backend/src/Service/Social/NetworkSubscriptions.php backend/tests/Service/Social/NetworkSubscriptionsTest.php
grep -rn "NetworkSubscriptions\b" backend/src backend/tests backend/config
```

Expected from the grep: no output. `NetworkFeedSubscriptions` is a different class, a test fixture, and stays.

- [ ] **Step 6: Run the tests to verify they pass**

Run: `php bin/phpunit --filter 'RefreshFeedSetModelTest|RefreshRequestModelTest|RefreshRunStoreTest|UserRefreshScopeTest|FeedRepositoryNetworkScopeTest|FeedRepositoryTest|RefreshControllerTest|RefreshRunner'`
Expected: PASS. `RefreshControllerTest::testNetworkRefreshSweepsOnlyThatNetworksFeeds` is unchanged and still reports a run of one feed with `remaining` 0.

- [ ] **Step 7: Gates and commit**

Run: `bin/console cache:warmup && composer check && composer md`
Expected: clean. `Refresh` no longer names `Social`.

```bash
git add backend/src/Service/Refresh backend/src/Repository/DueFeedCriteria.php backend/src/Repository/FeedRepository.php \
  backend/tests/Service/Refresh backend/tests/Repository/FeedRepositoryNetworkScopeTest.php \
  backend/tests/Repository/FeedRepositoryTest.php
git commit -m "refactor(#1606): refresh a network by the feed's stored network, drop NetworkSubscriptions"
```

(The `git mv` and `git rm` from the steps above are already staged.)

---

### Task 6: The avatar sweep selects by `feed.network`

**Files:**
- Modify: `backend/src/Repository/FeedRepository.php` (`findBlueskyProfileFeedsWithImageDue`)
- Modify: `backend/tests/Repository/FeedRepositoryProfileImageDueTest.php`

**Interfaces:**
- Consumes: the column and the listener (Tasks 1–2).
- Produces: `findBlueskyProfileFeedsWithImageDue(\DateTimeImmutable $checkedBefore, int $limit): list<Feed>`. The signature is unchanged. It selects subscribed feeds with `network = bluesky` and a non-private `sourceFormat`.

- [ ] **Step 1: Add the guard for private Bluesky feeds**

A private Bluesky feed now carries `network = bluesky` too, so the new query must keep it out explicitly. The LIKE scan kept it out by URL shape, so this test passes on the old code. It is the guard that the switch must keep green. Append to `tests/Repository/FeedRepositoryProfileImageDueTest.php` (add `use App\Enum\SourceFormat;`):

```php
    public function testAPrivateBlueskyFeedIsNeverDue(): void
    {
        $home = new Feed('social:bluesky:1:home');
        $home->setSourceFormat(SourceFormat::BLUESKY_API);
        $this->entityManager->persist($home);
        $this->entityManager->persist(
            new Subscription($this->subscriber(), $home, new \DateTimeImmutable(self::CHECKED_BEFORE)),
        );
        $this->entityManager->flush();

        self::assertSame(
            [],
            $this->feeds()->findBlueskyProfileFeedsWithImageDue(new \DateTimeImmutable(self::CHECKED_BEFORE), 10),
        );
    }
```

Run: `php bin/phpunit tests/Repository/FeedRepositoryProfileImageDueTest.php`
Expected: PASS (4 tests) on the old query.

- [ ] **Step 2: Switch the selection**

In `src/Repository/FeedRepository.php`:
- add `use App\Enum\SocialNetwork;`;
- remove `use App\Service\Bluesky\Support\BlueskyWeb;` (its only use was here);
- replace the method:

```php
    /**
     * Subscribed Bluesky profile feeds whose picture was never asked for or not since $checkedBefore, never-asked
     * first.
     *
     * @return list<Feed>
     */
    public function findBlueskyProfileFeedsWithImageDue(\DateTimeImmutable $checkedBefore, int $limit): array
    {
        /** @var list<Feed> $feeds */
        $feeds = $this->createQueryBuilder('f')
            ->andWhere('f.network = :bluesky')->setParameter('bluesky', SocialNetwork::Bluesky)
            ->andWhere('f.sourceFormat NOT IN (:socialFormats)')->setParameter('socialFormats', SourceFormat::SOCIAL)
            ->andWhere(sprintf('EXISTS (SELECT s.id FROM %s s WHERE s.feed = f)', Subscription::class))
            ->andWhere('f.imageCheckedAt IS NULL OR f.imageCheckedAt < :checkedBefore')
            ->setParameter('checkedBefore', $checkedBefore)
            ->orderBy('f.imageCheckedAt', 'ASC')
            ->addOrderBy('f.id', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return $feeds;
    }
```

`ProfileImageSweep::actorsOf()` still reads each actor with `ProfileFeedUrl::identityOf()`. That is how it gets the handle or DID; it is no longer a filter.

- [ ] **Step 3: Break-test the format guard**

Temporarily delete the `sourceFormat NOT IN` line and run `php bin/phpunit tests/Repository/FeedRepositoryProfileImageDueTest.php`.
Expected: `testAPrivateBlueskyFeedIsNeverDue` FAILS (one feed returned). Restore the line by hand and rerun: PASS.

- [ ] **Step 4: Run the sweep's tests**

Run: `php bin/phpunit --filter 'FeedRepositoryProfileImageDueTest|ProfileImageSweepTest|RefreshProfileImagesHandlerTest|MaintenanceTickTest'`
Expected: PASS. The `http://` and upper-case-host spellings stay due, because the classifier normalises both.

- [ ] **Step 5: Gates and commit**

Run: `bin/console cache:warmup && composer check && composer md`
Expected: clean.

```bash
git add backend/src/Repository/FeedRepository.php backend/tests/Repository/FeedRepositoryProfileImageDueTest.php
git commit -m "refactor(#1606): select the avatar sweep's feeds by stored network, not a URL prefix"
```

---

### Task 7: The JSON mappers, the enricher and the post address read `getNetwork()`

**Files:**
- Modify: `backend/src/Http/EntryJson.php`
- Modify: `backend/src/Http/SubscriptionJson.php`
- Modify: `backend/src/Repository/EntryListRowEnricher.php`
- Modify: `backend/src/Service/Social/Support/SocialPostAddress.php`
- Modify: `backend/tests/Http/EntryJsonTest.php`, `backend/tests/Http/SubscriptionJsonTest.php`, `backend/tests/Service/Social/Support/SocialPostAddressTest.php`
- Unchanged, must stay green: `backend/tests/Repository/EntryListRowEnricherTest.php`, `backend/tests/Http/SubscriptionJsonListTest.php`, `backend/tests/Service/Social/SocialLikesTest.php`, `backend/tests/Controller/Api/EntryLikeControllerTest.php`, `backend/tests/Controller/Api/SubscriptionSocialListTest.php`

**Interfaces:**
- Consumes: `Feed::getNetwork()` (Task 1), filled by the listener (Task 2).
- Produces:
  - `SocialPostAddress::of(Entry $entry): ?SocialPostAddressModel`, unchanged;
  - `SocialPostAddress::onFeedNetwork()` becomes private;
  - the JSON shapes are unchanged.

These unit tests build `Feed` objects without flushing them, so they set the network the listener would have stored with `classifyAs()`. A new test in each file pins that the mapper trusts the stored value and does not reclassify the URL.

- [ ] **Step 1: Write the failing tests**

`tests/Http/SubscriptionJsonTest.php` (add `use App\Enum\SocialNetwork;`):

In `testAPrivateFeedNamesItsNetworkAccountAndKind` and `testAPrivateFeedKeepsItsOwnSocialPartOverAProfileFeedReading`, add `$feed->classifyAs(SocialNetwork::Bluesky);` after `setSourceFormat`. In `testABlueskyProfileFeedIsAProfileFeedOfThatNetwork`, add `$feed->classifyAs(SocialNetwork::Bluesky);`. In `testAMastodonProfileFeedIsAProfileFeedOfThatNetwork`, add `$feed->classifyAs(SocialNetwork::Mastodon);`.

Replace `testASocialLookingUrlOfAnRssFeedIsNoPrivateFeed` and `testASocialFormatWithAnUnparsableUrlHasNoSocialPart`: those inputs are now the classifier's concern, and `SocialFeedClassifierTest` covers them. The replacement:

```php
    public function testAFeedStoredWithoutANetworkHasNoSocialPartWhateverItsUrl(): void
    {
        $feed = new Feed('https://bsky.app/profile/did:plc:bob/rss');

        self::assertNull(SubscriptionJson::one($this->subscriptionTo($feed))['social']);
    }
```

`tests/Http/EntryJsonTest.php`: in `profileFeedEntry()`, add `$feed->classifyAs(SocialNetwork::Bluesky);` after `setImageUrl`. Then add:

```php
    public function testAProfileFeedStoredWithoutANetworkHasNoPoster(): void
    {
        $entry = $this->profileFeedEntry('https://cdn.example/politico.jpg');
        $entry->getFeed()->classifyAs(null);

        self::assertNull(EntryJson::listRow($this->row($entry))['poster']);
    }
```

`tests/Service/Social/Support/SocialPostAddressTest.php`: in `posts()`, classify the network feeds right after building them:

```php
        $profile = new Feed('https://bsky.app/profile/did:plc:bob/rss');
        $profile->classifyAs(SocialNetwork::Bluesky);
        $home = new Feed('social:bluesky:3:home');
        $home->setSourceFormat(SourceFormat::BLUESKY_API);
        $home->classifyAs(SocialNetwork::Bluesky);
        $mastodon = new Feed('https://mastodon.social/@Gargron.rss');
        $mastodon->classifyAs(SocialNetwork::Mastodon);
        $plain = new Feed('https://example.com/feed.xml');
        $unclassified = new Feed('https://bsky.app/profile/did:plc:carol/rss');
```

and add a case before `'plain feed'`:

```php
        yield 'a profile url stored without a network' => [self::entry($unclassified, self::POST_URI, null), null];
```

- [ ] **Step 2: Run them to verify they fail**

Run: `php bin/phpunit --filter 'SubscriptionJsonTest|EntryJsonTest|SocialPostAddressTest'`
Expected: FAIL in exactly the three new tests (the old code reclassifies the URL and finds a network).

- [ ] **Step 3: Read the stored network**

`src/Http/SubscriptionJson.php`:
- replace `social()` and its docblock;
- remove `use App\Service\Social\Support\ProfileFeedUrl;` if nothing else in the file uses it (`grep -n ProfileFeedUrl src/Http/SubscriptionJson.php`).

```php
    /**
     * The feed's stored network; a private feed's synthetic URL also names its account and kind, any other feed on a
     * network is its `profile` feed.
     *
     * @return array{network: string, accountId: int|null, kind: string}|null
     */
    private static function social(Feed $feed): ?array
    {
        $network = $feed->getNetwork();
        if (null === $network) {
            return null;
        }
        $address = SocialFeedClassifier::privateAddressOf($feed);

        return null === $address
            ? ['network' => $network->value, 'accountId' => null, 'kind' => self::PROFILE_KIND]
            : ['network' => $network->value, 'accountId' => $address->accountId, 'kind' => $address->kind->value];
    }
```

`src/Http/EntryJson.php`, in `posterOf()`:

```php
        if (!$isPrivateFeed && null !== $feed->getNetwork()) {
```

Then remove `use App\Service\Social\Support\SocialFeedClassifier;` from `EntryJson.php`.

`src/Service/Social/Support/SocialPostAddress.php`: remove `use … SocialFeedClassifier;`, and change `of()` and the visibility of `onFeedNetwork()`. Drop its docblock, which explained the public entry point.

```php
    public static function of(Entry $entry): ?SocialPostAddressModel
    {
        return self::onFeedNetwork($entry, $entry->getFeed()->getNetwork());
    }

    private static function onFeedNetwork(Entry $entry, ?SocialNetwork $feedNetwork): ?SocialPostAddressModel
    {
```

`src/Repository/EntryListRowEnricher.php`:
- remove `use App\Service\Social\Support\SocialFeedClassifier;`;
- delete `feedNetworksOf()`;
- simplify `postNetworksOf()`:

```php
    /**
     * @param array<int, Entry> $entries
     *
     * @return array<int, SocialNetwork> the network of each entry that is a social post, decided without a query
     */
    private static function postNetworksOf(array $entries): array
    {
        $networks = [];
        foreach ($entries as $entryId => $entry) {
            $network = SocialPostAddress::of($entry)?->network;
            if (null !== $network) {
                $networks[$entryId] = $network;
            }
        }

        return $networks;
    }
```

Check what is left of the old classification on the read path:

```bash
grep -rn "SocialFeedClassifier::networkOf\|ProfileFeedUrl::networkOf\|onFeedNetwork" backend/src
```

Expected:
- `SocialFeedClassifier::networkOf` only in `Service/Social/FeedNetworkListener.php`;
- `ProfileFeedUrl::networkOf` only in `SocialFeedClassifier.php`;
- `onFeedNetwork` only inside `SocialPostAddress.php`.

`privateAddressOf` keeps its two callers (`SubscriptionJson`, `ActingAccountChooser`).

- [ ] **Step 4: Run the tests to verify they pass**

Run: `php bin/phpunit --filter 'SubscriptionJson|EntryJsonTest|SocialPostAddressTest|EntryListRowEnricherTest|SocialLikesTest|EntryLikeControllerTest|SubscriptionSocialListTest|ActingAccountChooserTest'`
Expected: PASS.

- [ ] **Step 5: Gates and commit**

Run: `bin/console cache:warmup && composer check && composer md`
Expected: clean.

```bash
git add backend/src/Http/EntryJson.php backend/src/Http/SubscriptionJson.php \
  backend/src/Repository/EntryListRowEnricher.php backend/src/Service/Social/Support/SocialPostAddress.php \
  backend/tests/Http/EntryJsonTest.php backend/tests/Http/SubscriptionJsonTest.php \
  backend/tests/Service/Social/Support/SocialPostAddressTest.php
git commit -m "refactor(#1606): read a feed's network from the column in the mappers and the post address"
```

---

### Task 8: Final verification

**Files:** none new. Fix anything a gate reports, in the task's own files.

- [ ] **Step 1: Merge develop and rerun the migration check if develop moved**

```bash
git status
git fetch origin develop
git log --oneline HEAD..origin/develop
```

If that lists commits, run `git merge origin/develop`, resolve any conflict, and confirm `Version20261013150000` is still the newest migration (`ls backend/migrations | tail -3`). If it is not, rename the class and file to a later timestamp, rerun Task 1 Step 8 (SQLite from empty plus `doctrine:schema:validate`), and commit.

- [ ] **Step 2: Run every backend gate**

From `backend/`:

```bash
bin/console cache:warmup
composer check
composer md
composer test:parallel
docker compose exec php bin/console doctrine:migrations:migrate --no-interaction
docker compose exec php composer test
git fetch origin develop && composer infection:diff
```

Expected:
- `composer check` is clean. That covers cs, stan (incl. `ServiceRoleRule`, `ServiceModuleCycleRule`, `ServiceModuleBoundaryRule`, `QueriesLiveInRepositoriesRule`, `PersistenceKnowsNoServiceRule`) and tramp.
- `composer md` lists no finding in any `src` file this branch touches: `Feed`, `FeedRepository`, `EntryScopePredicates`, `SubscriptionRepository`, `EntryListRowEnricher`, `SubscriptionJson`, `EntryJson`, `UserRefreshScope`, `RefreshRunner`, `RefreshRunStore`, `DueFeedCriteria`, `EntryQuery`, `EntryAliases`, `MarkReadService`, `EntryController`, `SocialPostAddress`, `SocialFeedClassifier`, `FeedNetworkListener`.
- Both suite legs are green.
- `infection:diff` meets `minMsi`. An escaped mutant on a touched line gets a test, never a threshold change.

If the MySQL suite fails only in the rate-limiter test, rerun once (a known flake). Any other failure is real.

- [ ] **Step 3: PhpStorm inspections and the dev log**

Run `mcp__phpstorm__lint_files` on every PHP file the branch changed (`git diff --name-only origin/develop -- '*.php'`). Block on ERROR or WARNING.

Then scan the dev log after hitting the network view and a network refresh in the running app (`https://localhost:8443`, Social section):

```bash
ls -t backend/var/log/dev-*.log | head -1 | xargs tail -n 300 | jq 'select(.level_name != "DEBUG" and .level_name != "INFO")'
```

Expected: no new deprecation or error from the entry list, mark-read, refresh or subscription paths.

- [ ] **Step 4: Confirm what the issue's "Done when" asks for**

```bash
grep -rn "NetworkSubscriptions\b\|restrictToFeedSet\|subscriptionIds:" backend/src
grep -rn "LIKE :httpsProfile\|BlueskyWeb" backend/src/Repository/FeedRepository.php
git diff --stat origin/develop -- frontend
```

Expected: all three outputs are empty. In particular, the frontend is untouched.

## Self-review

- **Spec coverage:**
  - Design 1 (column, enum, index; the classifier computes): Task 1.
  - Design 2 (preFlush listener in `Service/Social`, functional test with flush/clear/re-read covering private, Bluesky and Mastodon profile, plain → null, and redirect → null): Task 2.
  - Design 3 (migration and PHP backfill through the classifier; dialect-correct; verified from empty on both legs, plus a backfill check and the live Docker DB with a SELECT): Task 1, Steps 7–9.
  - Design 4: the entry list in Task 3, mark-read in Task 4, refresh with the run key unchanged in Task 5, the avatar sweep in Task 6, the readers in Task 7, and the `NetworkSubscriptions` deletion in Task 5.
  - Design 5 (keep the classifier methods that still have callers): `networkOf` (listener), `networkFor` (migration, `networkOf`), `privateAddressOf` (`SubscriptionJson`, `ActingAccountChooser`) and `ProfileFeedUrl::networkOf` (classifier) all keep callers; Task 7 Step 3 greps for it.
  - Issue "Done when": Task 8 Step 4.
  - Backup coverage of the new column: Task 1 Step 6.
- **Placeholder scan:** every code step shows its code. Every command has its expected result. "Fix anything a gate reports" in Task 8 is a gate instruction, not missing content.
- **Type consistency:**
  - `Feed::classifyAs(?SocialNetwork)` and `Feed::getNetwork(): ?SocialNetwork` are used alike in Tasks 1, 2 and 7.
  - `SocialFeedClassifier::networkFor(string, string)` is used alike in Task 1's test, the classifier and the migration.
  - `EntryQuery::$network`, `DueFeedCriteria::$network` and `RefreshFeedSetModel::$network` are all `?SocialNetwork` / `SocialNetwork`, constructed with the named argument `network:` everywhere.
  - `RefreshFeedSetModel::key()` is a method in Task 5's model, `RefreshRunStore` and `UserRefreshScopeTest`.
  - `EntryAliases::$networkFeed` appears only in Task 3.
  - `SubscriptionRepository::findForUserOnNetwork(int, SocialNetwork)` is the same in Task 4's test and `MarkReadService`.
