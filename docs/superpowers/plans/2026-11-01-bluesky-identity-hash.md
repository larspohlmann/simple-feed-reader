# Bluesky identity hash (#1954) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A Bluesky post reached through the social timeline and through a profile RSS feed gets one `url_hash`, so the list collapses the copies into "also in".

**Architecture:** The list collapse (`DuplicateCollapseDql`, `EntryListRepository::attachDuplicates`) keys on `entry.url_hash`. The timeline stores the DID-form post URL (`AtPostUri::webUrl`), Bluesky's RSS the handle-form one, so the hashes differ although both rows carry the same AT URI guid. A new Url-module service `EntryUrlHash` computes an entry's identity hash from its guid and URL: a tagged `EntryIdentityUrlInterface` (owned by `Service/Url`, the lowest module) may name a canonical identity URL for the guid; Bluesky implements it with the DID-form URL. Both `url_hash` writers call `EntryUrlHash`. The stored `url` is untouched. A migration recomputes `url_hash` for stored Bluesky rows.

**Tech Stack:** Symfony 7.4, PHP 8.4, Doctrine ORM/Migrations, PHPUnit 12.

**Spec:** GitHub issue #1954 (root cause measured on Strato: 1525844 vs 1525866; 762 split posts of 8,705).

## Global Constraints

- Module dependencies: `Bluesky → Url` and `Bluesky → Ingest` exist, so neither `Url` nor `Ingest` may import `App\Service\Bluesky` (`ServiceModuleCycleRule`). The interface lives in `Url`; Bluesky implements it.
- One role per folder: the interface sits in `src/Service/Url/EntryIdentityUrl/`; the Bluesky implementation is a stateless service in `src/Service/Bluesky/` (like `BlueskyEntryRule`).
- `final readonly`, constructor promotion, no comments beyond the bar in CLAUDE.md.
- `EntryBatchInserter` is already on `PersistenceKnowsNoServiceRule`'s allow-list for hashing URLs itself; it swaps `UrlNormalizer` for `EntryUrlHash`, nothing else.
- Commits: `fix(#1954): …`, no attribution lines.

---

### Task 1: `EntryUrlHash` and the Bluesky identity URL

**Files:**
- Create: `backend/src/Service/Url/EntryIdentityUrl/EntryIdentityUrlInterface.php`
- Create: `backend/src/Service/Url/EntryUrlHash.php`
- Create: `backend/src/Service/Bluesky/BlueskyEntryIdentityUrl.php`
- Modify: `backend/config/services.yaml` (`_instanceof` block, after `PlatformEntryRuleInterface`)
- Test: `backend/tests/Service/Url/EntryUrlHashTest.php`, `backend/tests/Service/Bluesky/BlueskyEntryIdentityUrlTest.php`

**Interfaces:**
- Produces: `EntryIdentityUrlInterface::forGuid(string $guid): ?string`; `EntryUrlHash::of(string $guid, ?string $url): ?string` (same value as `UrlNormalizer::hash($url)` unless an identity URL applies).

- [ ] **Step 1: Write the failing tests**

`backend/tests/Service/Url/EntryUrlHashTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Url;

use App\Service\Bluesky\BlueskyEntryIdentityUrl;
use App\Service\Url\EntryUrlHash;
use App\Service\Url\UrlNormalizer;
use PHPUnit\Framework\TestCase;

final class EntryUrlHashTest extends TestCase
{
    private const string POST_GUID = 'at://did:plc:xjc7mkotpenvhh6ffewkdpyn/app.bsky.feed.post/3mz7vexdd5c2n';

    public function testATimelineCopyAndAnRssCopyOfOnePostShareTheHash(): void
    {
        $hash = new EntryUrlHash(new UrlNormalizer(), [new BlueskyEntryIdentityUrl()]);

        self::assertSame(
            $hash->of(self::POST_GUID, 'https://bsky.app/profile/did:plc:xjc7mkotpenvhh6ffewkdpyn/post/3mz7vexdd5c2n'),
            $hash->of(self::POST_GUID, 'https://bsky.app/profile/meidastouch.com/post/3mz7vexdd5c2n'),
        );
    }

    public function testAnIdentityUrlHashesEvenWithoutALink(): void
    {
        $hash = new EntryUrlHash(new UrlNormalizer(), [new BlueskyEntryIdentityUrl()]);

        self::assertSame(
            hash('sha256', 'https://bsky.app/profile/did:plc:xjc7mkotpenvhh6ffewkdpyn/post/3mz7vexdd5c2n'),
            $hash->of(self::POST_GUID, null),
        );
    }

    public function testAnyOtherGuidHashesItsNormalizedLink(): void
    {
        $normalizer = new UrlNormalizer();
        $hash = new EntryUrlHash($normalizer, [new BlueskyEntryIdentityUrl()]);
        $url = 'https://example.com/story?utm_source=feed';

        self::assertSame($normalizer->hash($url), $hash->of('https://example.com/story', $url));
    }

    public function testAnUnlinkedEntryWithAPlainGuidHasNoHash(): void
    {
        self::assertNull((new EntryUrlHash(new UrlNormalizer(), []))->of('only-guid', null));
    }
}
```

`backend/tests/Service/Bluesky/BlueskyEntryIdentityUrlTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Bluesky;

use App\Service\Bluesky\BlueskyEntryIdentityUrl;
use PHPUnit\Framework\TestCase;

final class BlueskyEntryIdentityUrlTest extends TestCase
{
    public function testAPostUriNamesItsDidFormWebUrl(): void
    {
        self::assertSame(
            'https://bsky.app/profile/did:plc:abc/post/3mz7',
            (new BlueskyEntryIdentityUrl())->forGuid('at://did:plc:abc/app.bsky.feed.post/3mz7'),
        );
    }

    public function testAnyOtherGuidNamesNone(): void
    {
        self::assertNull((new BlueskyEntryIdentityUrl())->forGuid('https://example.com/story'));
    }
}
```

- [ ] **Step 2: Run them to see them fail**

Run (from `backend/`): `php bin/phpunit tests/Service/Url/EntryUrlHashTest.php tests/Service/Bluesky/BlueskyEntryIdentityUrlTest.php`
Expected: errors, class `App\Service\Url\EntryUrlHash` / `App\Service\Bluesky\BlueskyEntryIdentityUrl` not found.

- [ ] **Step 3: Implement**

`backend/src/Service/Url/EntryIdentityUrl/EntryIdentityUrlInterface.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Url\EntryIdentityUrl;

/** A platform whose guid names its post more stably than the link a feed happens to print. */
interface EntryIdentityUrlInterface
{
    public function forGuid(string $guid): ?string;
}
```

`backend/src/Service/Url/EntryUrlHash.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Url;

use App\Service\Url\EntryIdentityUrl\EntryIdentityUrlInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/** The url_hash of an entry: every writer of it calls here, or copies of one article stop collapsing. */
final readonly class EntryUrlHash
{
    /** @param iterable<EntryIdentityUrlInterface> $identityUrls */
    public function __construct(
        private UrlNormalizer $urlNormalizer,
        #[AutowireIterator('app.entry_identity_url')]
        private iterable $identityUrls,
    ) {
    }

    public function of(string $guid, ?string $url): ?string
    {
        return $this->urlNormalizer->hash($this->identityUrl($guid) ?? $url);
    }

    private function identityUrl(string $guid): ?string
    {
        foreach ($this->identityUrls as $identityUrl) {
            $named = $identityUrl->forGuid($guid);
            if ($named !== null) {
                return $named;
            }
        }

        return null;
    }
}
```

`backend/src/Service/Bluesky/BlueskyEntryIdentityUrl.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Bluesky;

use App\Service\Bluesky\Support\AtPostUri;
use App\Service\Url\EntryIdentityUrl\EntryIdentityUrlInterface;

/** The timeline links a post by DID, Bluesky's RSS by handle: the AT URI guid is what both share. */
final readonly class BlueskyEntryIdentityUrl implements EntryIdentityUrlInterface
{
    public function forGuid(string $guid): ?string
    {
        return AtPostUri::webUrl($guid);
    }
}
```

`backend/config/services.yaml`, in `_instanceof` right after the `PlatformEntryRuleInterface` entry:

```yaml
        App\Service\Url\EntryIdentityUrl\EntryIdentityUrlInterface:
            tags: ['app.entry_identity_url']
```

- [ ] **Step 4: Run the tests to see them pass**

Run: `php bin/phpunit tests/Service/Url/EntryUrlHashTest.php tests/Service/Bluesky/BlueskyEntryIdentityUrlTest.php`
Expected: OK (6 tests).

- [ ] **Step 5: Commit**

```bash
git add backend/src/Service/Url/EntryIdentityUrl backend/src/Service/Url/EntryUrlHash.php backend/src/Service/Bluesky/BlueskyEntryIdentityUrl.php backend/config/services.yaml backend/tests/Service/Url/EntryUrlHashTest.php backend/tests/Service/Bluesky/BlueskyEntryIdentityUrlTest.php
git commit -m "fix(#1954): hash a Bluesky post's identity from its AT URI guid"
```

### Task 2: Both url_hash writers use `EntryUrlHash`

**Files:**
- Modify: `backend/src/Service/Ingest/EntryIngestor.php` (constructor `UrlNormalizer $urlNormalizer` → `EntryUrlHash $urlHash`; `incoming()`)
- Modify: `backend/src/Repository/EntryBatchInserter.php` (constructor, `row()`)
- Modify: `backend/tests/Support/EntryIngestors.php:47`, `backend/tests/Service/Backup/EntryMediaBackupRoundTripTest.php:66,120,174`, `backend/tests/Service/Backup/Pass/RestoreEntryLoaderTest.php:104`
- Test: `backend/tests/Service/Ingest/PlatformEntryRulesWiringTest.php` (one more container test)

**Interfaces:**
- Consumes: `EntryUrlHash::of(string $guid, ?string $url): ?string` from Task 1.

- [ ] **Step 1: Write the failing wiring test**

Append to `PlatformEntryRulesWiringTest` (it already ingests through the container's `EntryIngestor`); the expected hash is a literal, no new import:

```php
    public function testTheContainersIngestorHashesABlueskyRssPostLikeItsTimelineCopy(): void
    {
        $ingestor = self::getContainer()->get(EntryIngestor::class);
        self::assertInstanceOf(EntryIngestor::class, $ingestor);
        $feed = new Feed('https://bsky.app/profile/did:plc:xjc7mkotpenvhh6ffewkdpyn/rss');
        $this->entityManager->persist($feed);
        $this->entityManager->flush();
        $post = new ParsedEntryModel(
            'at://did:plc:xjc7mkotpenvhh6ffewkdpyn/app.bsky.feed.post/3mz7vexdd5c2n',
            'https://bsky.app/profile/meidastouch.com/post/3mz7vexdd5c2n',
            Headline::derived('NEWS'),
            null,
            null,
            '<p>NEWS</p>',
            null,
        );

        $ingestor->ingest(
            $feed,
            new ParsedFeedModel('Feed', null, null, null, [$post]),
            new FeedIngestContext(new \DateTimeImmutable('2026-11-01T12:00:00Z'), null),
        );
        $this->entityManager->flush();

        $entry = $this->entityManager->getRepository(Entry::class)->findOneBy(['feed' => $feed]);
        self::assertInstanceOf(Entry::class, $entry);
        self::assertSame('https://bsky.app/profile/meidastouch.com/post/3mz7vexdd5c2n', $entry->getUrl());
        self::assertSame(
            hash('sha256', 'https://bsky.app/profile/did:plc:xjc7mkotpenvhh6ffewkdpyn/post/3mz7vexdd5c2n'),
            $entry->getUrlHash(),
        );
    }
```

- [ ] **Step 2: Run it to see it fail**

Run: `php bin/phpunit --filter testTheContainersIngestorHashesABlueskyRssPostLikeItsTimelineCopy`
Expected: FAIL, url hash is the sha256 of the handle-form URL.

- [ ] **Step 3: Switch the writers**

`EntryIngestor`: replace the import `use App\Service\Url\UrlNormalizer;` with `use App\Service\Url\EntryUrlHash;`, the constructor parameter `private UrlNormalizer $urlNormalizer,` with `private EntryUrlHash $urlHash,`, and `incoming()` with:

```php
    private function incoming(ParsedEntryModel $entry): IncomingEntryModel
    {
        return new IncomingEntryModel($entry, self::guidHash($entry->guid), $this->urlHash->of($entry->guid, $entry->url));
    }
```

(If that line passes 120 columns for phpcs, wrap the arguments one per line.)

`EntryBatchInserter`: replace `use App\Service\Url\UrlNormalizer;` with `use App\Service\Url\EntryUrlHash;`, the constructor parameter with `private EntryUrlHash $urlHash,`, and in `row()` `$this->urlNormalizer->hash($line->url)` with `$this->urlHash->of($line->guid, $line->url)`.

Test assemblies — each `new UrlNormalizer()` handed to these two classes becomes:

```php
new EntryUrlHash(new UrlNormalizer(), [new BlueskyEntryIdentityUrl()])
```

with imports `App\Service\Url\EntryUrlHash` and `App\Service\Bluesky\BlueskyEntryIdentityUrl` added (`tests/Support/EntryIngestors.php`, `EntryMediaBackupRoundTripTest.php` ×3, `RestoreEntryLoaderTest.php`). `tests/Support/FullyPopulatedAccount.php:150` hashes an `https://` fixture URL for an `Entry` it builds by hand and stays as is.

- [ ] **Step 4: Run the touched suites**

Run: `php bin/phpunit tests/Service/Ingest tests/Service/Backup tests/Service/Url tests/Service/Bluesky`
Expected: OK.

- [ ] **Step 5: Commit**

```bash
git add backend/src/Service/Ingest/EntryIngestor.php backend/src/Repository/EntryBatchInserter.php backend/tests
git commit -m "fix(#1954): write url_hash through EntryUrlHash on ingest and restore"
```

### Task 3: Migration rehashes stored Bluesky posts

**Files:**
- Create: `backend/migrations/Version20261101120000.php`

The migration must not import application services (they change; a migration is history). It repeats the one rule inline: an AT post guid `at://<did>/app.bsky.feed.post/<rkey>` hashes `sha256('https://bsky.app/profile/<did>/post/<rkey>')` — exactly what `UrlNormalizer::hash(AtPostUri::webUrl($guid))` yields (host already lower-case, no query, path verbatim).

- [ ] **Step 1: Write the migration**

```php
<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261101120000 extends AbstractMigration
{
    private const string POST_URI = '#^at://([^/\s]+)/app\.bsky\.feed\.post/([^/\s]+)\z#';

    public function getDescription(): string
    {
        return 'Rehash Bluesky entries from their AT URI guid, so timeline and RSS copies collapse (#1954)';
    }

    public function up(Schema $schema): void
    {
        $rows = $this->connection->fetchAllAssociative("SELECT id, guid FROM entry WHERE guid LIKE 'at://%'");
        foreach ($rows as $row) {
            if (preg_match(self::POST_URI, (string) $row['guid'], $parts) !== 1) {
                continue;
            }
            $this->addSql(
                'UPDATE entry SET url_hash = ? WHERE id = ?',
                [hash('sha256', 'https://bsky.app/profile/' . $parts[1] . '/post/' . $parts[2]), $row['id']],
            );
        }
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('The handle-form hashes are not kept.');
    }
}
```

- [ ] **Step 2: Verify on the Docker dev database**

From the repo root, first check the containers are current (memory: verify-containers-are-current), then:

```bash
docker compose exec php bin/console doctrine:migrations:migrate --no-interaction
```

Expected: the migration runs. Then confirm no AT guid group has more than one url_hash:

```bash
docker compose exec php bin/console dbal:run-sql "SELECT COUNT(*) FROM (SELECT guid_hash FROM entry WHERE guid LIKE 'at://%' GROUP BY guid_hash HAVING COUNT(DISTINCT url_hash) > 1) t"
```

Expected: `0`.

- [ ] **Step 3: Commit**

```bash
git add backend/migrations/Version20261101120000.php
git commit -m "fix(#1954): rehash stored Bluesky entries from their guid"
```

### Task 4: Gates

- [ ] `composer check`, `composer md` on touched `src` files, `php bin/phpunit` (SQLite) and `docker compose exec php composer test:parallel` (MySQL) in parallel; `composer infection:diff`.
- [ ] Scan today's dev log.
- [ ] /simplify, then PR into `develop` with `Closes #1954`.
