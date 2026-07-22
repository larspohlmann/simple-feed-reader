# Plan 4b — Reader entries, read/favorite/kept state, unread counts, OPML

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add the reading surface on top of Plan 4a's subscriptions/tags/refresh: a paginated entry list with per-user read/favorite/kept state, a mark-all-read watermark, unread counts on the subscription list, and OPML import/export.

**Architecture:** Entries are shared and hang off `Feed`; all user state is either an explicit `EntryState` row (composite PK `user`+`entry`) or the sparse `Subscription.markedReadUntil` watermark. The entry list is a single keyset-paginated DQL query that LEFT JOINs the caller's `EntryState` and folds the watermark into an *effective* `isRead`. Writes are a thin PATCH (lazy `EntryState` create) and a `MarkReadService` that advances the watermark and flips any explicit rows below it to read. OPML is two stateless services (`OpmlExporter`, `OpmlImporter`) over the same hardened-XML approach the feed parser uses. Every endpoint is bearer-JWT, stateless, JSON-in/JSON-out (OPML export/import carry XML as a body/file), so the native-iOS path stays open.

**Tech Stack:** Symfony 7.4 LTS, PHP 8.3 (no 8.4 syntax; always `(new Foo())->bar()`), Doctrine ORM (DQL, portable across SQLite dev/test + MySQL prod), PHPUnit (functional `WebTestCase` + `DbTestCase`), phpunit.dist.xml `failOnDeprecation=true`.

---

## Design decisions (read before implementing)

These refine the spec's API sketch (`docs/superpowers/specs/2026-07-21-simple-feed-reader-design.md`, "API surface"). They are settled for this plan:

1. **The entry filter and per-feed mark-read scope key on `subscriptionId`, not raw feed id.** The spec wrote `?feed=`; we use `?subscription=` (and `mark-read` `scope:"feed"` carries a subscription id). The subscription is the thing the caller owns, so ownership/IDOR falls out of the lookup for free, and it matches what `GET /subscriptions` already returns to the client.

2. **Effective read state = explicit row wins, else the watermark decides.** For an entry `E` and subscriber `U`:
   - if an `EntryState` row exists → `isRead` is that row's flag (an explicit "mark unread" survives and shows unread even under a watermark);
   - else → read iff the subscription's `markedReadUntil` is set and `effectiveDate(E) <= markedReadUntil`.
   `effectiveDate(E) = publishedAt ?? createdAt` (entries always have `createdAt`), used for ordering, the cursor, and every watermark comparison so there are no NULL edge cases.

3. **`mark-read` both advances the watermark and flips explicit rows.** "Mark all read until T" means *everything up to T is read*, including entries a user had explicitly marked unread. So the service (a) sets `markedReadUntil = max(current, T)` on each affected subscription and (b) runs one bulk `UPDATE` setting `isRead=true, readAt=now` on the caller's existing `EntryState` rows in the affected feeds whose `effectiveDate <= T`. Favorited/kept rows are updated in place (kept, just now read), never deleted.

4. **PATCH `/entries/{id}/state` never auto-deletes rows.** A row with all three flags false is a deliberate "mark unread" override and is meaningful. Sparseness means we don't *pre-create* rows, not that we garbage-collect them.

5. **OPML import does not fetch feeds inline.** A file can hold hundreds of feeds; fetching each synchronously would be slow and multiply the SSRF surface. Import find-or-creates `Feed` rows with `nextFetchAt = now` (so the next refresh cycle populates them) and creates subscriptions + tags from the outline structure. No content is fetched at import time; the fetcher's SSRF guard still applies whenever those feeds are later refreshed. Import respects the same `MAX_SUBSCRIPTIONS_PER_USER = 500` cap as subscribe.

6. **Cursor is opaque.** `EntryCursor` base64url-encodes `"<effectiveDate ISO8601>|<id>"`. The client treats it as a token; the server owns the format.

---

## File structure

**Create:**
- `backend/src/Http/EntryCursor.php` — opaque cursor encode/decode
- `backend/src/Repository/EntryListRow.php` — read-model row (entry + folded state) *(kept next to the repo that produces it)*
- `backend/src/Repository/EntryQuery.php` — value object of list filters
- `backend/src/Http/EntryJson.php` — entry JSON shaper
- `backend/src/Dto/Entry/UpdateEntryStateRequest.php`
- `backend/src/Dto/Entry/MarkReadRequest.php`
- `backend/src/Service/Reader/MarkReadService.php`
- `backend/src/Controller/Api/EntryController.php`
- `backend/src/Exception/InvalidOpmlException.php`
- `backend/src/Service/Opml/OpmlExporter.php`
- `backend/src/Service/Opml/OpmlImporter.php`
- `backend/src/Service/Opml/OpmlImportResult.php`
- `backend/src/Controller/Api/OpmlController.php`
- tests alongside each (see tasks)
- `backend/tests/Fixtures/opml/subscriptions.opml` — import fixture

**Modify:**
- `backend/src/Repository/EntryRepository.php` — `listForUser`, `findOneSubscribedByUser`
- `backend/src/Repository/EntryStateRepository.php` — `findOneForUserEntry`
- `backend/src/Repository/SubscriptionRepository.php` — `unreadCountsForUser`, `findForUserByTagId`
- `backend/src/Repository/TagRepository.php` — `findOneByNameForUser`
- `backend/src/Http/SubscriptionJson.php` — add `unreadCount`
- `backend/src/Controller/Api/SubscriptionController.php` — pass unread counts
- `backend/tests/Controller/Api/SubscriptionControllerTest.php` — flip the `unreadCount` assertion
- `backend/tests/E2e/ReaderJourneyE2eTest.php` — extend the real-feed journey

---

## Task 1: `EntryCursor` — opaque keyset cursor

**Files:**
- Create: `backend/src/Http/EntryCursor.php`
- Test: `backend/tests/Http/EntryCursorTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Http\EntryCursor;
use PHPUnit\Framework\TestCase;

final class EntryCursorTest extends TestCase
{
    public function testRoundTrips(): void
    {
        $date = new \DateTimeImmutable('2026-07-20T08:30:00+02:00');
        $encoded = EntryCursor::encode($date, 4242);

        $decoded = EntryCursor::decode($encoded);
        self::assertNotNull($decoded);
        self::assertSame($date->getTimestamp(), $decoded->date->getTimestamp());
        self::assertSame(4242, $decoded->id);
    }

    public function testEncodeIsUrlSafeAndOpaque(): void
    {
        $encoded = EntryCursor::encode(new \DateTimeImmutable('2026-01-01T00:00:00Z'), 1);
        self::assertSame($encoded, rawurlencode($encoded)); // no +, /, = to escape
        self::assertStringNotContainsString('|', $encoded);
    }

    public function testDecodeRejectsGarbage(): void
    {
        self::assertNull(EntryCursor::decode('not-a-cursor'));
        self::assertNull(EntryCursor::decode(base64_encode('only-one-part')));
        self::assertNull(EntryCursor::decode(base64_encode('bad-date|1')));
        self::assertNull(EntryCursor::decode(base64_encode('2026-01-01T00:00:00+00:00|notint')));
        self::assertNull(EntryCursor::decode(''));
    }
}
```

- [ ] **Step 2: Run it, expect failure** — `vendor/bin/phpunit tests/Http/EntryCursorTest.php` → error "Class EntryCursor not found".

- [ ] **Step 3: Implement**

```php
<?php

declare(strict_types=1);

namespace App\Http;

/**
 * Opaque keyset-pagination cursor for the entry list: base64url of
 * "<effectiveDate ISO8601>|<id>". The client treats it as a token; the format
 * is ours to change. `date` is the entry's publishedAt ?? createdAt — the same
 * value the list orders by — and `id` is the tie-breaker for equal timestamps.
 */
final readonly class EntryCursor
{
    public function __construct(
        public \DateTimeImmutable $date,
        public int $id,
    ) {
    }

    public static function encode(\DateTimeImmutable $date, int $id): string
    {
        $raw = $date->format(\DateTimeInterface::ATOM) . '|' . $id;

        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    public static function decode(string $cursor): ?self
    {
        if ($cursor === '') {
            return null;
        }

        $raw = base64_decode(strtr($cursor, '-_', '+/'), true);
        if ($raw === false) {
            return null;
        }

        $parts = explode('|', $raw);
        if (\count($parts) !== 2 || !ctype_digit($parts[1])) {
            return null;
        }

        $date = \DateTimeImmutable::createFromFormat(\DateTimeInterface::ATOM, $parts[0]);
        if ($date === false) {
            return null;
        }

        return new self($date, (int) $parts[1]);
    }
}
```

- [ ] **Step 4: Run tests, expect pass.**

- [ ] **Step 5: Commit** — `git add -A && git commit -m "feat(reader): opaque keyset cursor for entry pagination"`

---

## Task 2: `EntryRepository::listForUser` — the paginated read-model query

**Files:**
- Create: `backend/src/Repository/EntryQuery.php`, `backend/src/Repository/EntryListRow.php`
- Modify: `backend/src/Repository/EntryRepository.php`
- Test: `backend/tests/Repository/EntryListTest.php`

**Contract.** `listForUser(EntryQuery $q): list<EntryListRow>` returns entries belonging to feeds the user subscribes to, newest first by `effectiveDate` then `id` descending, at most `$q->limit` rows. Each row carries the entry plus its owning subscription id/title and the **effective** flags (watermark folded into `isRead`). Filters: optional `subscriptionId`, optional `tagId`, and `view ∈ {all, unread, favorites, kept}`. `cursor` (an `EntryCursor`) pages forward.

- [ ] **Step 1: Write the value objects**

`backend/src/Repository/EntryQuery.php`:

```php
<?php

declare(strict_types=1);

namespace App\Repository;

use App\Http\EntryCursor;

final readonly class EntryQuery
{
    public const DEFAULT_LIMIT = 50;
    public const MAX_LIMIT = 100;

    /** @param 'all'|'unread'|'favorites'|'kept' $view */
    public function __construct(
        public int $userId,
        public string $view = 'all',
        public ?int $subscriptionId = null,
        public ?int $tagId = null,
        public ?EntryCursor $cursor = null,
        public int $limit = self::DEFAULT_LIMIT,
    ) {
    }
}
```

`backend/src/Repository/EntryListRow.php`:

```php
<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Entry;

/**
 * One row of the entry list: the shared Entry plus the caller-specific view of
 * it. `isRead` already has the subscription watermark folded in, so the client
 * never re-derives it. `subscriptionId`/`subscriptionTitle` identify the source
 * for a cross-feed listing.
 */
final readonly class EntryListRow
{
    public function __construct(
        public Entry $entry,
        public int $subscriptionId,
        public string $subscriptionTitle,
        public bool $isRead,
        public bool $isFavorite,
        public bool $isKept,
    ) {
    }
}
```

- [ ] **Step 2: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\Entry;
use App\Entity\EntryState;
use App\Entity\Feed;
use App\Entity\Subscription;
use App\Entity\Tag;
use App\Entity\User;
use App\Http\EntryCursor;
use App\Repository\EntryQuery;
use App\Repository\EntryRepository;
use App\Tests\DbTestCase;

final class EntryListTest extends DbTestCase
{
    private User $user;
    private Feed $feed;
    private Subscription $sub;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = new User('reader@example.com', new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $this->em->persist($this->user);

        $this->feed = new Feed('https://example.com/feed.xml');
        $this->feed->setTitle('Example');
        $this->em->persist($this->feed);

        $this->sub = new Subscription($this->user, $this->feed, new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $this->em->persist($this->sub);

        $this->em->flush();
    }

    private function entry(string $guid, string $published): Entry
    {
        $e = new Entry($this->feed, $guid, 'https://example.com/' . $guid, 'Title ' . $guid, new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $e->setPublishedAt(new \DateTimeImmutable($published));
        $this->em->persist($e);
        $this->em->flush();

        return $e;
    }

    private function repo(): EntryRepository
    {
        $repo = $this->em->getRepository(Entry::class);
        self::assertInstanceOf(EntryRepository::class, $repo);

        return $repo;
    }

    public function testNewestFirstAndCarriesSubscriptionTitle(): void
    {
        $this->entry('a', '2026-07-10T00:00:00Z');
        $this->entry('b', '2026-07-12T00:00:00Z');

        $rows = $this->repo()->listForUser(new EntryQuery($this->user->getId() ?? 0));

        self::assertCount(2, $rows);
        self::assertSame('Title b', $rows[0]->entry->getTitle());
        self::assertSame($this->sub->getId(), $rows[0]->subscriptionId);
        self::assertSame('Example', $rows[0]->subscriptionTitle);
        self::assertFalse($rows[0]->isRead);
    }

    public function testWatermarkFoldsIntoIsReadAndUnreadFilter(): void
    {
        $old = $this->entry('old', '2026-07-05T00:00:00Z');
        $this->entry('new', '2026-07-20T00:00:00Z');
        $this->sub->setMarkedReadUntil(new \DateTimeImmutable('2026-07-10T00:00:00Z'));
        $this->em->flush();

        $all = $this->repo()->listForUser(new EntryQuery($this->user->getId() ?? 0));
        $byGuid = [];
        foreach ($all as $r) {
            $byGuid[$r->entry->getGuid()] = $r;
        }
        self::assertTrue($byGuid['old']->isRead);   // under the watermark
        self::assertFalse($byGuid['new']->isRead);  // above it

        $unread = $this->repo()->listForUser(new EntryQuery($this->user->getId() ?? 0, view: 'unread'));
        self::assertCount(1, $unread);
        self::assertSame('new', $unread[0]->entry->getGuid());
        self::assertNotNull($old); // referenced
    }

    public function testExplicitStateBeatsWatermark(): void
    {
        $e = $this->entry('x', '2026-07-05T00:00:00Z');
        $this->sub->setMarkedReadUntil(new \DateTimeImmutable('2026-07-10T00:00:00Z'));
        // Explicitly unread despite being under the watermark.
        $state = new EntryState($this->user, $e);
        $state->setIsRead(false);
        $this->em->persist($state);
        $this->em->flush();

        $unread = $this->repo()->listForUser(new EntryQuery($this->user->getId() ?? 0, view: 'unread'));
        self::assertCount(1, $unread);
        self::assertFalse($unread[0]->isRead);
    }

    public function testFavoritesAndKeptViews(): void
    {
        $fav = $this->entry('fav', '2026-07-05T00:00:00Z');
        $kept = $this->entry('kept', '2026-07-06T00:00:00Z');
        $this->entry('plain', '2026-07-07T00:00:00Z');

        $s1 = new EntryState($this->user, $fav);
        $s1->setIsFavorite(true);
        $s2 = new EntryState($this->user, $kept);
        $s2->setIsKept(true);
        $this->em->persist($s1);
        $this->em->persist($s2);
        $this->em->flush();

        $favs = $this->repo()->listForUser(new EntryQuery($this->user->getId() ?? 0, view: 'favorites'));
        self::assertCount(1, $favs);
        self::assertSame('fav', $favs[0]->entry->getGuid());
        self::assertTrue($favs[0]->isFavorite);

        $kepts = $this->repo()->listForUser(new EntryQuery($this->user->getId() ?? 0, view: 'kept'));
        self::assertCount(1, $kepts);
        self::assertSame('kept', $kepts[0]->entry->getGuid());
    }

    public function testTagFilter(): void
    {
        $otherFeed = new Feed('https://other.example.com/feed.xml');
        $this->em->persist($otherFeed);
        $otherSub = new Subscription($this->user, $otherFeed, new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $tag = new Tag($this->user, 'news');
        $this->em->persist($tag);
        $otherSub->addTag($tag);
        $this->em->persist($otherSub);
        $this->em->flush();

        $this->entry('untagged', '2026-07-05T00:00:00Z');
        $tagged = new Entry($otherFeed, 'tagged', 'https://other.example.com/1', 'Tagged', new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $tagged->setPublishedAt(new \DateTimeImmutable('2026-07-06T00:00:00Z'));
        $this->em->persist($tagged);
        $this->em->flush();

        $rows = $this->repo()->listForUser(new EntryQuery($this->user->getId() ?? 0, tagId: $tag->getId()));
        self::assertCount(1, $rows);
        self::assertSame('tagged', $rows[0]->entry->getGuid());
    }

    public function testSubscriptionFilterAndCursorPaginate(): void
    {
        $e1 = $this->entry('e1', '2026-07-10T00:00:00Z');
        $this->entry('e2', '2026-07-11T00:00:00Z');
        $e3 = $this->entry('e3', '2026-07-12T00:00:00Z');

        $page1 = $this->repo()->listForUser(new EntryQuery($this->user->getId() ?? 0, subscriptionId: $this->sub->getId(), limit: 2));
        self::assertCount(2, $page1);
        self::assertSame('e3', $page1[0]->entry->getGuid());
        self::assertSame('e2', $page1[1]->entry->getGuid());

        $cursor = new EntryCursor(
            $page1[1]->entry->getPublishedAt() ?? $page1[1]->entry->getCreatedAt(),
            $page1[1]->entry->getId() ?? 0,
        );
        $page2 = $this->repo()->listForUser(new EntryQuery($this->user->getId() ?? 0, cursor: $cursor, limit: 2));
        self::assertCount(1, $page2);
        self::assertSame('e1', $page2[0]->entry->getGuid());
        self::assertNotNull($e1);
        self::assertNotNull($e3);
    }

    public function testExcludesFeedsTheUserDoesNotSubscribeTo(): void
    {
        $strangerFeed = new Feed('https://stranger.example.com/feed.xml');
        $this->em->persist($strangerFeed);
        $orphan = new Entry($strangerFeed, 'orphan', null, 'Orphan', new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $orphan->setPublishedAt(new \DateTimeImmutable('2026-07-20T00:00:00Z'));
        $this->em->persist($orphan);
        $this->em->flush();

        $rows = $this->repo()->listForUser(new EntryQuery($this->user->getId() ?? 0));
        foreach ($rows as $r) {
            self::assertNotSame('orphan', $r->entry->getGuid());
        }
    }
}
```

- [ ] **Step 3: Run it, expect failure** — `vendor/bin/phpunit tests/Repository/EntryListTest.php` → error "Call to undefined method ...::listForUser()".

- [ ] **Step 4: Implement `listForUser` (and `findOneSubscribedByUser`, used in Task 4) in `EntryRepository`**

Add these imports at the top of `EntryRepository.php`:

```php
use App\Entity\EntryState;
use App\Entity\Subscription;
use App\Entity\User;
use App\Http\EntryCursor;
use Doctrine\DBAL\Types\Types;
```

Add the methods to the class body:

```php
    /**
     * Entries in feeds the caller subscribes to, newest first by
     * effectiveDate (publishedAt ?? createdAt) then id, keyset-paginated.
     * LEFT JOINs the caller's EntryState and folds Subscription.markedReadUntil
     * into an effective isRead. `view` narrows to unread/favorites/kept.
     *
     * @return list<EntryListRow>
     */
    public function listForUser(EntryQuery $query): array
    {
        $limit = max(1, min($query->limit, EntryQuery::MAX_LIMIT));

        $qb = $this->createQueryBuilder('e')
            ->leftJoin('e.feed', 'f')->addSelect('f')
            // Unrelated-entity joins: the caller's subscription to this entry's
            // feed, and the caller's optional per-entry state row.
            ->join(Subscription::class, 's', 'WITH', 's.feed = e.feed AND s.user = :user')
            ->leftJoin(EntryState::class, 'es', 'WITH', 'es.entry = e AND es.user = :user')
            ->addSelect('s.id AS subscriptionId')
            ->addSelect('s.customTitle AS customTitle')
            ->addSelect('f.title AS feedTitle')
            ->addSelect('f.url AS feedUrl')
            ->addSelect('es.isRead AS esRead')
            ->addSelect('es.isFavorite AS esFavorite')
            ->addSelect('es.isKept AS esKept')
            ->addSelect('s.markedReadUntil AS markedReadUntil')
            ->addSelect('COALESCE(e.publishedAt, e.createdAt) AS HIDDEN effectiveDate')
            ->setParameter('user', $query->userId)
            ->orderBy('effectiveDate', 'DESC')
            ->addOrderBy('e.id', 'DESC')
            ->setMaxResults($limit);

        if ($query->subscriptionId !== null) {
            $qb->andWhere('s.id = :sid')->setParameter('sid', $query->subscriptionId);
        }

        if ($query->tagId !== null) {
            // A tag id matches at most one row of s.tags, so this inner join
            // never duplicates an entry.
            $qb->innerJoin('s.tags', 't', 'WITH', 't.id = :tagId')
                ->setParameter('tagId', $query->tagId);
        }

        $this->applyView($qb, $query->view);

        if ($query->cursor !== null) {
            $qb->andWhere(
                '(COALESCE(e.publishedAt, e.createdAt) < :curDate '
                . 'OR (COALESCE(e.publishedAt, e.createdAt) = :curDate AND e.id < :curId))',
            )
                ->setParameter('curDate', $query->cursor->date, Types::DATETIME_IMMUTABLE)
                ->setParameter('curId', $query->cursor->id);
        }

        /** @var list<array<string, mixed>> $rows */
        $rows = $qb->getQuery()->getResult();

        return array_map(fn (array $row): EntryListRow => $this->hydrateRow($row), $rows);
    }

    /**
     * The entry only if the caller subscribes to its feed — the IDOR gate for
     * per-entry state writes. Returns a managed Entry (or null → 404).
     */
    public function findOneSubscribedByUser(int $entryId, int $userId): ?Entry
    {
        /** @var Entry|null $entry */
        $entry = $this->createQueryBuilder('e')
            ->join(Subscription::class, 's', 'WITH', 's.feed = e.feed AND s.user = :user')
            ->andWhere('e.id = :id')
            ->setParameter('id', $entryId)
            ->setParameter('user', $userId)
            ->getQuery()
            ->getOneOrNullResult();

        return $entry;
    }

    private function applyView(\Doctrine\ORM\QueryBuilder $qb, string $view): void
    {
        switch ($view) {
            case 'unread':
                $qb->andWhere(
                    'es.isRead = :readFalse '
                    . 'OR (es.isRead IS NULL AND (s.markedReadUntil IS NULL '
                    . 'OR COALESCE(e.publishedAt, e.createdAt) > s.markedReadUntil))',
                )->setParameter('readFalse', false, Types::BOOLEAN);
                break;
            case 'favorites':
                $qb->andWhere('es.isFavorite = :flag')->setParameter('flag', true, Types::BOOLEAN);
                break;
            case 'kept':
                $qb->andWhere('es.isKept = :flag')->setParameter('flag', true, Types::BOOLEAN);
                break;
            default:
                // 'all' — no state filter.
                break;
        }
    }

    /**
     * @param array<string, mixed> $row a mixed DQL result: [0 => Entry, scalars...]
     */
    private function hydrateRow(array $row): EntryListRow
    {
        /** @var Entry $entry */
        $entry = $row[0];

        $esRead = $row['esRead'];
        $markedReadUntil = $row['markedReadUntil'];
        $effectiveDate = $entry->getPublishedAt() ?? $entry->getCreatedAt();

        if ($esRead === null) {
            $isRead = $markedReadUntil instanceof \DateTimeInterface
                && $effectiveDate <= $markedReadUntil;
        } else {
            $isRead = (bool) $esRead;
        }

        $customTitle = $row['customTitle'];
        $title = \is_string($customTitle) && $customTitle !== ''
            ? $customTitle
            : ((\is_string($row['feedTitle']) && $row['feedTitle'] !== '') ? $row['feedTitle'] : (string) $row['feedUrl']);

        return new EntryListRow(
            entry: $entry,
            subscriptionId: (int) $row['subscriptionId'],
            subscriptionTitle: $title,
            isRead: $isRead,
            isFavorite: (bool) ($row['esFavorite'] ?? false),
            isKept: (bool) ($row['esKept'] ?? false),
        );
    }
```

Add the `use` for the new value objects (same namespace `App\Repository`, so `EntryQuery` and `EntryListRow` need no import).

- [ ] **Step 5: Run tests, expect pass** — `vendor/bin/phpunit tests/Repository/EntryListTest.php`.

- [ ] **Step 6: Run cs/stan/md on touched files**

```bash
composer cs && composer stan && composer md 2>&1 | grep -E 'EntryRepository|EntryQuery|EntryListRow' || echo "no phpmd findings in touched files"
```

Expected: clean. If phpmd flags `listForUser`/`hydrateRow` complexity, that is a touched-file finding you MUST clear (extract further).

- [ ] **Step 7: Commit** — `git add -A && git commit -m "feat(reader): keyset-paginated entry read-model query"`

---

## Task 3: `GET /api/entries` — the list endpoint

**Files:**
- Create: `backend/src/Http/EntryJson.php`, `backend/src/Controller/Api/EntryController.php`
- Test: `backend/tests/Controller/Api/EntryControllerTest.php`

- [ ] **Step 1: Write `EntryJson`**

```php
<?php

declare(strict_types=1);

namespace App\Http;

use App\Repository\EntryListRow;

final class EntryJson
{
    /**
     * @return array{
     *   id: int|null, title: string, url: string|null, author: string|null,
     *   summary: string|null, contentHtml: string|null, publishedAt: string|null,
     *   createdAt: string, subscriptionId: int, source: string,
     *   isRead: bool, isFavorite: bool, isKept: bool
     * }
     */
    public static function one(EntryListRow $row): array
    {
        $e = $row->entry;

        return [
            'id' => $e->getId(),
            'title' => $e->getTitle(),
            'url' => $e->getUrl(),
            'author' => $e->getAuthor(),
            'summary' => $e->getSummary(),
            'contentHtml' => $e->getContentHtml(),
            'publishedAt' => $e->getPublishedAt()?->format(\DateTimeInterface::ATOM),
            'createdAt' => $e->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'subscriptionId' => $row->subscriptionId,
            'source' => $row->subscriptionTitle,
            'isRead' => $row->isRead,
            'isFavorite' => $row->isFavorite,
            'isKept' => $row->isKept,
        ];
    }
}
```

- [ ] **Step 2: Write the failing functional test**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Entity\Entry;
use App\Entity\Feed;
use App\Entity\Subscription;
use App\Entity\User;
use App\Tests\Support\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class EntryControllerTest extends WebTestCase
{
    /** @return array{0: array<string,string>, 1: User} */
    private function auth(string $email): array
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        self::assertInstanceOf(UserPasswordHasherInterface::class, $hasher);
        $user = (new UserFactory($em, $hasher))->create($email);

        $tokens = self::getContainer()->get(JWTTokenManagerInterface::class);
        self::assertInstanceOf(JWTTokenManagerInterface::class, $tokens);

        return [['HTTP_AUTHORIZATION' => 'Bearer ' . $tokens->create($user)], $user];
    }

    private function seedFeedWithEntries(User $user, int $count): Subscription
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        $feed = new Feed('https://example.com/feed-' . uniqid() . '.xml');
        $feed->setTitle('Seeded');
        $em->persist($feed);
        $sub = new Subscription($user, $feed, new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $em->persist($sub);

        for ($i = 1; $i <= $count; $i++) {
            $e = new Entry($feed, "g$i", "https://example.com/$i", "Post $i", new \DateTimeImmutable('2026-07-01T00:00:00Z'));
            $e->setPublishedAt(new \DateTimeImmutable(sprintf('2026-07-%02dT00:00:00Z', $i)));
            $em->persist($e);
        }
        $em->flush();

        return $sub;
    }

    public function testAnonymousIsRejected(): void
    {
        $client = self::createClient();
        $client->request('GET', '/api/entries');
        self::assertResponseStatusCodeSame(401);
    }

    public function testListsNewestFirstWithState(): void
    {
        $client = self::createClient();
        [$headers, $user] = $this->auth('e-list@example.com');
        $this->seedFeedWithEntries($user, 3);

        $client->request('GET', '/api/entries', server: $headers);
        self::assertResponseIsSuccessful();
        $body = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        self::assertIsArray($body['entries']);
        self::assertCount(3, $body['entries']);
        self::assertSame('Post 3', $body['entries'][0]['title']);
        self::assertFalse($body['entries'][0]['isRead']);
        self::assertSame('Seeded', $body['entries'][0]['source']);
        self::assertArrayHasKey('nextCursor', $body);
        self::assertNull($body['nextCursor']);
    }

    public function testPaginatesWithCursor(): void
    {
        $client = self::createClient();
        [$headers, $user] = $this->auth('e-page@example.com');
        $this->seedFeedWithEntries($user, 3);

        $client->request('GET', '/api/entries?limit=2', server: $headers);
        $page1 = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($page1);
        self::assertCount(2, $page1['entries']);
        self::assertNotNull($page1['nextCursor']);

        $client->request('GET', '/api/entries?limit=2&cursor=' . urlencode((string) $page1['nextCursor']), server: $headers);
        $page2 = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($page2);
        self::assertCount(1, $page2['entries']);
        self::assertSame('Post 1', $page2['entries'][0]['title']);
        self::assertNull($page2['nextCursor']);
    }

    public function testRejectsUnknownView(): void
    {
        $client = self::createClient();
        [$headers] = $this->auth('e-view@example.com');

        $client->request('GET', '/api/entries?view=bogus', server: $headers);
        self::assertResponseStatusCodeSame(422);
    }

    public function testInvalidCursorIsRejected(): void
    {
        $client = self::createClient();
        [$headers] = $this->auth('e-cursor@example.com');

        $client->request('GET', '/api/entries?cursor=not-a-cursor', server: $headers);
        self::assertResponseStatusCodeSame(422);
    }
}
```

- [ ] **Step 3: Run it, expect failure** (route not found → 404).

- [ ] **Step 4: Implement `EntryController` (list action only for now)**

```php
<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\User;
use App\Exception\ValidationException;
use App\Http\EntryCursor;
use App\Http\EntryJson;
use App\Repository\EntryQuery;
use App\Repository\EntryRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

#[Route('/api/entries')]
final class EntryController
{
    public function __construct(
        private readonly EntryRepository $entries,
    ) {
    }

    #[Route('', name: 'api_entries_list', methods: ['GET'])]
    public function list(
        #[CurrentUser] User $user,
        #[MapQueryParameter(filter: \FILTER_VALIDATE_REGEXP, options: ['regexp' => '/^(all|unread|favorites|kept)$/'])]
        string $view = 'all',
        #[MapQueryParameter] ?int $subscription = null,
        #[MapQueryParameter] ?int $tag = null,
        #[MapQueryParameter] ?string $cursor = null,
        #[MapQueryParameter] int $limit = EntryQuery::DEFAULT_LIMIT,
    ): JsonResponse {
        $decodedCursor = null;
        if ($cursor !== null && $cursor !== '') {
            $decodedCursor = EntryCursor::decode($cursor);
            if ($decodedCursor === null) {
                throw new ValidationException('cursor', 'The cursor is malformed.');
            }
        }

        $rows = $this->entries->listForUser(new EntryQuery(
            userId: (int) $user->getId(),
            view: $view,
            subscriptionId: $subscription,
            tagId: $tag,
            cursor: $decodedCursor,
            limit: $limit,
        ));

        $last = $rows === [] ? null : $rows[array_key_last($rows)];
        $nextCursor = null;
        // A full page implies there may be more; hand back a cursor from the
        // last row. (A short page cannot have a next page.)
        if ($last !== null && \count($rows) >= min(max(1, $limit), EntryQuery::MAX_LIMIT)) {
            $entry = $last->entry;
            $nextCursor = EntryCursor::encode(
                $entry->getPublishedAt() ?? $entry->getCreatedAt(),
                $entry->getId() ?? 0,
            );
        }

        return new JsonResponse([
            'entries' => array_map(static fn ($r) => EntryJson::one($r), $rows),
            'nextCursor' => $nextCursor,
        ]);
    }
}
```

Note: an out-of-range `view` makes `MapQueryParameter`'s regexp filter fail, which Symfony reports as a 422 via the validation path — matching `testRejectsUnknownView`. If your Symfony minor renders that as 400 instead, adjust the test to the observed status and note it; do not weaken the guard.

- [ ] **Step 5: Add the `ValidationException` if it does not already exist**

Check first: `ls backend/src/Exception/ValidationException.php`. If absent, create it — a 422 that reuses the `validation_error` contract so the client's existing switch covers it:

```php
<?php

declare(strict_types=1);

namespace App\Exception;

use Symfony\Component\HttpFoundation\Response;

/**
 * A single-field validation failure raised from controller/service code, in the
 * same `validation_error` shape #[MapRequestPayload] produces for DTOs.
 */
final class ValidationException extends ApiException
{
    public function __construct(string $field, string $message)
    {
        parent::__construct(
            'validation_error',
            Response::HTTP_UNPROCESSABLE_ENTITY,
            'Validation failed',
            'One or more fields are invalid.',
            [$field => [$message]],
        );
    }
}
```

(If it already exists, reuse it as-is and delete this snippet's intent — do not duplicate.)

- [ ] **Step 6: Run tests, expect pass** — `vendor/bin/phpunit tests/Controller/Api/EntryControllerTest.php`.

- [ ] **Step 7: cs/stan/md on touched files, then commit** — `git add -A && git commit -m "feat(reader): GET /api/entries with cursor pagination and views"`

---

## Task 4: `PATCH /api/entries/{id}/state` — read/favorite/kept write

**Files:**
- Create: `backend/src/Dto/Entry/UpdateEntryStateRequest.php`
- Modify: `backend/src/Repository/EntryStateRepository.php`, `backend/src/Controller/Api/EntryController.php`
- Test: extend `backend/tests/Controller/Api/EntryControllerTest.php`

- [ ] **Step 1: Write the DTO**

```php
<?php

declare(strict_types=1);

namespace App\Dto\Entry;

/**
 * Partial update: a null field means "leave unchanged". At least one non-null
 * field is expected, but an all-null body is a harmless no-op, not an error.
 */
final readonly class UpdateEntryStateRequest
{
    public function __construct(
        public ?bool $isRead = null,
        public ?bool $isFavorite = null,
        public ?bool $isKept = null,
    ) {
    }
}
```

- [ ] **Step 2: Add `findOneForUserEntry` to `EntryStateRepository`**

```php
    public function findOneForUserEntry(int $userId, int $entryId): ?EntryState
    {
        /** @var EntryState|null $row */
        $row = $this->createQueryBuilder('es')
            ->andWhere('IDENTITY(es.user) = :user')->setParameter('user', $userId)
            ->andWhere('IDENTITY(es.entry) = :entry')->setParameter('entry', $entryId)
            ->getQuery()
            ->getOneOrNullResult();

        return $row;
    }
```

- [ ] **Step 3: Write the failing tests (append to `EntryControllerTest`)**

```php
    public function testPatchStateLazilyCreatesAndReturnsState(): void
    {
        $client = self::createClient();
        [$headers, $user] = $this->auth('e-patch@example.com');
        $sub = $this->seedFeedWithEntries($user, 1);
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $entryId = $em->getRepository(Entry::class)->findOneBy(['feed' => $sub->getFeed()])?->getId();
        self::assertNotNull($entryId);

        $client->request(
            'PATCH',
            "/api/entries/$entryId/state",
            server: $headers + ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['isRead' => true, 'isFavorite' => true], \JSON_THROW_ON_ERROR),
        );
        self::assertResponseIsSuccessful();
        $body = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        self::assertTrue($body['state']['isRead']);
        self::assertTrue($body['state']['isFavorite']);
        self::assertFalse($body['state']['isKept']);
        self::assertNotNull($body['state']['readAt']);
    }

    public function testPatchStateUnreadClearsReadAt(): void
    {
        $client = self::createClient();
        [$headers, $user] = $this->auth('e-unread@example.com');
        $sub = $this->seedFeedWithEntries($user, 1);
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $entryId = $em->getRepository(Entry::class)->findOneBy(['feed' => $sub->getFeed()])?->getId();
        self::assertNotNull($entryId);

        $client->request('PATCH', "/api/entries/$entryId/state", server: $headers + ['CONTENT_TYPE' => 'application/json'], content: json_encode(['isRead' => true], \JSON_THROW_ON_ERROR));
        $client->request('PATCH', "/api/entries/$entryId/state", server: $headers + ['CONTENT_TYPE' => 'application/json'], content: json_encode(['isRead' => false], \JSON_THROW_ON_ERROR));
        $body = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        self::assertFalse($body['state']['isRead']);
        self::assertNull($body['state']['readAt']);
    }

    public function testCannotPatchEntryOfUnsubscribedFeed(): void
    {
        $client = self::createClient();
        [$headers] = $this->auth('e-idor@example.com');
        [, $stranger] = $this->auth('e-owner@example.com');
        $strangerSub = $this->seedFeedWithEntries($stranger, 1);
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $entryId = $em->getRepository(Entry::class)->findOneBy(['feed' => $strangerSub->getFeed()])?->getId();
        self::assertNotNull($entryId);

        $client->request('PATCH', "/api/entries/$entryId/state", server: $headers + ['CONTENT_TYPE' => 'application/json'], content: json_encode(['isRead' => true], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(404);
    }
```

- [ ] **Step 4: Add the action to `EntryController`**

Add constructor deps (`EntityManagerInterface $em`, `EntryStateRepository $states`, `ClockInterface $clock`) and the route:

```php
    #[Route('/{id}/state', name: 'api_entries_state', methods: ['PATCH'], requirements: ['id' => '\d+'])]
    public function updateState(
        int $id,
        #[CurrentUser] User $user,
        #[MapRequestPayload] UpdateEntryStateRequest $request,
    ): JsonResponse {
        $entry = $this->entries->findOneSubscribedByUser($id, (int) $user->getId())
            ?? throw new NotFoundHttpException('No such entry.');

        $state = $this->states->findOneForUserEntry((int) $user->getId(), $id);
        if ($state === null) {
            $state = new EntryState($user, $entry);
            $this->em->persist($state);
        }

        if ($request->isRead !== null) {
            $state->setIsRead($request->isRead);
            $state->setReadAt($request->isRead ? $this->clock->now() : null);
        }
        if ($request->isFavorite !== null) {
            $state->setIsFavorite($request->isFavorite);
        }
        if ($request->isKept !== null) {
            $state->setIsKept($request->isKept);
        }

        $this->em->flush();

        return new JsonResponse(['state' => [
            'entryId' => $id,
            'isRead' => $state->isRead(),
            'isFavorite' => $state->isFavorite(),
            'isKept' => $state->isKept(),
            'readAt' => $state->getReadAt()?->format(\DateTimeInterface::ATOM),
        ]]);
    }
```

Add imports: `App\Dto\Entry\UpdateEntryStateRequest`, `App\Entity\EntryState`, `App\Repository\EntryStateRepository`, `Doctrine\ORM\EntityManagerInterface`, `Psr\Clock\ClockInterface`, `Symfony\Component\HttpKernel\Attribute\MapRequestPayload`, `Symfony\Component\HttpKernel\Exception\NotFoundHttpException`.

- [ ] **Step 5: Run tests, expect pass. cs/stan/md on touched files.**

- [ ] **Step 6: Commit** — `git add -A && git commit -m "feat(reader): PATCH /api/entries/{id}/state read/favorite/kept"`

---

## Task 5: `POST /api/entries/mark-read` — watermark + bulk read

**Files:**
- Create: `backend/src/Dto/Entry/MarkReadRequest.php`, `backend/src/Service/Reader/MarkReadService.php`
- Modify: `backend/src/Repository/SubscriptionRepository.php` (`findForUserByTagId`), `backend/src/Controller/Api/EntryController.php`
- Test: `backend/tests/Service/Reader/MarkReadServiceTest.php`, extend `EntryControllerTest`

- [ ] **Step 1: Write the DTO**

```php
<?php

declare(strict_types=1);

namespace App\Dto\Entry;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class MarkReadRequest
{
    public function __construct(
        #[Assert\Choice(choices: ['all', 'feed', 'tag'])]
        public string $scope,
        public \DateTimeImmutable $until,
        #[Assert\Positive]
        public ?int $id = null,
    ) {
    }
}
```

(`until` is denormalized from an ISO-8601 string by the serializer's DateTimeNormalizer; a missing/unparseable value yields a 422 automatically. `id` is required for `feed`/`tag` — enforced in the service, which knows the scope.)

- [ ] **Step 2: Add `findForUserByTagId` to `SubscriptionRepository`**

```php
    /**
     * The user's subscriptions carrying a given tag (feed eager-loaded).
     *
     * @return list<Subscription>
     */
    public function findForUserByTagId(int $userId, int $tagId): array
    {
        /** @var list<Subscription> $rows */
        $rows = $this->createQueryBuilder('s')
            ->leftJoin('s.feed', 'f')->addSelect('f')
            ->innerJoin('s.tags', 't')
            ->andWhere('s.user = :user')->setParameter('user', $userId)
            ->andWhere('t.id = :tagId')->setParameter('tagId', $tagId)
            ->getQuery()
            ->getResult();

        return $rows;
    }
```

- [ ] **Step 3: Write the failing service test**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader;

use App\Entity\Entry;
use App\Entity\EntryState;
use App\Entity\Feed;
use App\Entity\Subscription;
use App\Entity\Tag;
use App\Entity\User;
use App\Service\Reader\MarkReadService;
use App\Tests\DbTestCase;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class MarkReadServiceTest extends DbTestCase
{
    private function service(): MarkReadService
    {
        $svc = self::getContainer()->get(MarkReadService::class);
        self::assertInstanceOf(MarkReadService::class, $svc);

        return $svc;
    }

    /** @return array{User, Subscription, Entry, Entry} */
    private function seed(): array
    {
        $user = new User('m@example.com', new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $this->em->persist($user);
        $feed = new Feed('https://example.com/f.xml');
        $this->em->persist($feed);
        $sub = new Subscription($user, $feed, new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $this->em->persist($sub);

        $old = new Entry($feed, 'old', null, 'Old', new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $old->setPublishedAt(new \DateTimeImmutable('2026-07-05T00:00:00Z'));
        $new = new Entry($feed, 'new', null, 'New', new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $new->setPublishedAt(new \DateTimeImmutable('2026-07-20T00:00:00Z'));
        $this->em->persist($old);
        $this->em->persist($new);
        $this->em->flush();

        return [$user, $sub, $old, $new];
    }

    public function testAllScopeSetsWatermarkAndFlipsExplicitUnread(): void
    {
        [$user, $sub, $old] = $this->seed();
        // A pre-existing explicit "unread" below the mark point.
        $state = new EntryState($user, $old);
        $state->setIsRead(false);
        $this->em->persist($state);
        $this->em->flush();

        $this->service()->mark($user, 'all', null, new \DateTimeImmutable('2026-07-10T00:00:00Z'));
        $this->em->clear();

        $reloaded = $this->em->getRepository(Subscription::class)->find($sub->getId());
        self::assertNotNull($reloaded);
        self::assertSame('2026-07-10T00:00:00+00:00', $reloaded->getMarkedReadUntil()?->format(\DateTimeInterface::ATOM));

        $flipped = $this->em->getRepository(EntryState::class)->findOneForUserEntry((int) $user->getId(), (int) $old->getId());
        self::assertNotNull($flipped);
        self::assertTrue($flipped->isRead());
        self::assertNotNull($flipped->getReadAt());
    }

    public function testWatermarkOnlyAdvances(): void
    {
        [$user, $sub] = $this->seed();
        $sub->setMarkedReadUntil(new \DateTimeImmutable('2026-07-15T00:00:00Z'));
        $this->em->flush();

        $this->service()->mark($user, 'all', null, new \DateTimeImmutable('2026-07-10T00:00:00Z'));
        $this->em->clear();

        $reloaded = $this->em->getRepository(Subscription::class)->find($sub->getId());
        self::assertNotNull($reloaded);
        self::assertSame('2026-07-15T00:00:00+00:00', $reloaded->getMarkedReadUntil()?->format(\DateTimeInterface::ATOM));
    }

    public function testFeedScopeRequiresOwnership(): void
    {
        [$user] = $this->seed();
        $this->expectException(NotFoundHttpException::class);
        $this->service()->mark($user, 'feed', 999999, new \DateTimeImmutable('2026-07-10T00:00:00Z'));
    }

    public function testTagScope(): void
    {
        [$user, $sub, , $new] = $this->seed();
        $tag = new Tag($user, 'news');
        $this->em->persist($tag);
        $sub->addTag($tag);
        $this->em->flush();

        $this->service()->mark($user, 'tag', (int) $tag->getId(), new \DateTimeImmutable('2026-07-25T00:00:00Z'));
        $this->em->clear();

        $reloaded = $this->em->getRepository(Subscription::class)->find($sub->getId());
        self::assertNotNull($reloaded);
        self::assertSame('2026-07-25T00:00:00+00:00', $reloaded->getMarkedReadUntil()?->format(\DateTimeInterface::ATOM));
        self::assertNotNull($new);
    }
}
```

- [ ] **Step 4: Run it, expect failure** (service class missing).

- [ ] **Step 5: Implement `MarkReadService`**

```php
<?php

declare(strict_types=1);

namespace App\Service\Reader;

use App\Entity\Entry;
use App\Entity\EntryState;
use App\Entity\Subscription;
use App\Entity\User;
use App\Repository\SubscriptionRepository;
use App\Repository\TagRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * "Mark all read until T" for a scope. Advances each affected subscription's
 * watermark to max(current, T) and — so entries a user had explicitly marked
 * unread also become read — flips the caller's existing EntryState rows in the
 * affected feeds whose effectiveDate <= T to isRead=true. Sparse (no-row)
 * entries are covered by the watermark alone.
 */
final readonly class MarkReadService
{
    public function __construct(
        private EntityManagerInterface $em,
        private SubscriptionRepository $subscriptions,
        private TagRepository $tags,
        private ClockInterface $clock,
    ) {
    }

    public function mark(User $user, string $scope, ?int $id, \DateTimeImmutable $until): void
    {
        $subs = $this->resolveScope($user, $scope, $id);
        if ($subs === []) {
            return;
        }

        $feedIds = [];
        foreach ($subs as $sub) {
            $feedIds[] = (int) $sub->getFeed()->getId();
            $current = $sub->getMarkedReadUntil();
            if ($current === null || $current < $until) {
                $sub->setMarkedReadUntil($until);
            }
        }

        $this->em->createQuery(sprintf(
            'UPDATE %s es SET es.isRead = :true, es.readAt = :now
             WHERE es.user = :user AND es.isRead = :false
             AND es.entry IN (
                 SELECT e.id FROM %s e
                 WHERE e.feed IN (:feeds) AND COALESCE(e.publishedAt, e.createdAt) <= :until
             )',
            EntryState::class,
            Entry::class,
        ))
            ->setParameter('true', true, Types::BOOLEAN)
            ->setParameter('false', false, Types::BOOLEAN)
            ->setParameter('now', $this->clock->now(), Types::DATETIME_IMMUTABLE)
            ->setParameter('user', $user->getId())
            ->setParameter('feeds', $feedIds)
            ->setParameter('until', $until, Types::DATETIME_IMMUTABLE)
            ->execute();

        $this->em->flush();
    }

    /**
     * @return list<Subscription>
     */
    private function resolveScope(User $user, string $scope, ?int $id): array
    {
        $userId = (int) $user->getId();

        return match ($scope) {
            'all' => $this->subscriptions->findForUserWithTags($userId),
            'feed' => [$this->requireSubscription($id, $userId)],
            'tag' => $this->subscriptions->findForUserByTagId($userId, $this->requireTag($id, $userId)),
            default => throw new BadRequestHttpException(sprintf('Unknown scope "%s".', $scope)),
        };
    }

    private function requireSubscription(?int $id, int $userId): Subscription
    {
        if ($id === null) {
            throw new BadRequestHttpException('scope "feed" requires an id.');
        }

        return $this->subscriptions->findOneOwnedBy($id, $userId)
            ?? throw new NotFoundHttpException('No such subscription.');
    }

    private function requireTag(?int $id, int $userId): int
    {
        if ($id === null) {
            throw new BadRequestHttpException('scope "tag" requires an id.');
        }

        $tag = $this->tags->findOneOwnedBy($id, $userId)
            ?? throw new NotFoundHttpException('No such tag.');

        return (int) $tag->getId();
    }
}
```

- [ ] **Step 6: Run the service test, expect pass.**

- [ ] **Step 7: Add the controller action + functional test**

Append to `EntryController` (inject `MarkReadService $markRead` in the constructor):

```php
    #[Route('/mark-read', name: 'api_entries_mark_read', methods: ['POST'])]
    public function markRead(
        #[CurrentUser] User $user,
        #[MapRequestPayload] MarkReadRequest $request,
    ): JsonResponse {
        $this->markRead->mark($user, $request->scope, $request->id, $request->until);

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }
```

Add imports `App\Dto\Entry\MarkReadRequest`, `App\Service\Reader\MarkReadService`, `Symfony\Component\HttpFoundation\Response`.

**Route ordering caveat:** `/mark-read` must not be captured by `/{id}/state`. It is not (different path + method), but keep the literal `/mark-read` route declared and rely on Symfony's exact-match precedence. Add a functional test:

```php
    public function testMarkReadAllThenListUnreadIsEmpty(): void
    {
        $client = self::createClient();
        [$headers, $user] = $this->auth('e-markread@example.com');
        $this->seedFeedWithEntries($user, 3);

        $client->request(
            'POST',
            '/api/entries/mark-read',
            server: $headers + ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['scope' => 'all', 'until' => '2026-08-01T00:00:00Z'], \JSON_THROW_ON_ERROR),
        );
        self::assertResponseStatusCodeSame(204);

        $client->request('GET', '/api/entries?view=unread', server: $headers);
        $body = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        self::assertCount(0, $body['entries']);
    }

    public function testMarkReadRejectsBadTimestamp(): void
    {
        $client = self::createClient();
        [$headers] = $this->auth('e-markbad@example.com');
        $client->request('POST', '/api/entries/mark-read', server: $headers + ['CONTENT_TYPE' => 'application/json'], content: json_encode(['scope' => 'all', 'until' => 'nonsense'], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(422);
    }
```

- [ ] **Step 8: Run the full new test files, expect pass. cs/stan/md on touched files.**

- [ ] **Step 9: Commit** — `git add -A && git commit -m "feat(reader): POST /api/entries/mark-read watermark + bulk read"`

---

## Task 6: Unread counts on `GET /api/subscriptions`

**Files:**
- Modify: `backend/src/Repository/SubscriptionRepository.php`, `backend/src/Http/SubscriptionJson.php`, `backend/src/Controller/Api/SubscriptionController.php`, `backend/tests/Controller/Api/SubscriptionControllerTest.php`
- Test: `backend/tests/Repository/UnreadCountsTest.php`

- [ ] **Step 1: Write the failing repository test**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\Entry;
use App\Entity\EntryState;
use App\Entity\Feed;
use App\Entity\Subscription;
use App\Entity\User;
use App\Repository\SubscriptionRepository;
use App\Tests\DbTestCase;

final class UnreadCountsTest extends DbTestCase
{
    private function repo(): SubscriptionRepository
    {
        $repo = $this->em->getRepository(Subscription::class);
        self::assertInstanceOf(SubscriptionRepository::class, $repo);

        return $repo;
    }

    public function testCountsUnreadPerSubscriptionRespectingWatermarkAndState(): void
    {
        $user = new User('u@example.com', new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $this->em->persist($user);
        $feed = new Feed('https://example.com/f.xml');
        $this->em->persist($feed);
        $sub = new Subscription($user, $feed, new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $sub->setMarkedReadUntil(new \DateTimeImmutable('2026-07-10T00:00:00Z'));
        $this->em->persist($sub);

        // under watermark → read; above → unread; explicit read; explicit unread.
        foreach ([['a', '2026-07-05'], ['b', '2026-07-20'], ['c', '2026-07-21'], ['d', '2026-07-22']] as [$g, $d]) {
            $e = new Entry($feed, $g, null, $g, new \DateTimeImmutable('2026-07-01T00:00:00Z'));
            $e->setPublishedAt(new \DateTimeImmutable($d . 'T00:00:00Z'));
            $this->em->persist($e);
            if ($g === 'c') {
                $st = new EntryState($user, $e);
                $st->setIsRead(true);
                $this->em->persist($st);
            }
        }
        $this->em->flush();

        // Unread: b and d (a is under watermark, c is explicitly read).
        $counts = $this->repo()->unreadCountsForUser((int) $user->getId());
        self::assertSame(2, $counts[(int) $sub->getId()] ?? 0);
    }

    public function testSubscriptionWithNoUnreadIsAbsentFromMap(): void
    {
        $user = new User('empty@example.com', new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $this->em->persist($user);
        $feed = new Feed('https://example.com/empty.xml');
        $this->em->persist($feed);
        $sub = new Subscription($user, $feed, new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $this->em->persist($sub);
        $this->em->flush();

        $counts = $this->repo()->unreadCountsForUser((int) $user->getId());
        self::assertArrayNotHasKey((int) $sub->getId(), $counts);
    }
}
```

- [ ] **Step 2: Implement `unreadCountsForUser`**

```php
    /**
     * Unread entry counts keyed by subscription id, in one query across all the
     * user's subscriptions. Unread = no explicit state and above the watermark,
     * OR an explicit isRead=false row. Subscriptions with zero unread are absent
     * from the map (the caller defaults them to 0).
     *
     * @return array<int, int>
     */
    public function unreadCountsForUser(int $userId): array
    {
        /** @var list<array{subscriptionId: int, unreadCount: int}> $rows */
        $rows = $this->getEntityManager()->createQuery(sprintf(
            'SELECT s.id AS subscriptionId, COUNT(e.id) AS unreadCount
             FROM %s s
             JOIN %s e WITH e.feed = s.feed
             LEFT JOIN %s es WITH es.entry = e AND es.user = s.user
             WHERE s.user = :user AND (
                 es.isRead = :false
                 OR (es.isRead IS NULL AND (s.markedReadUntil IS NULL
                     OR COALESCE(e.publishedAt, e.createdAt) > s.markedReadUntil))
             )
             GROUP BY s.id',
            Subscription::class,
            \App\Entity\Entry::class,
            \App\Entity\EntryState::class,
        ))
            ->setParameter('user', $userId)
            ->setParameter('false', false, \Doctrine\DBAL\Types\Types::BOOLEAN)
            ->getResult();

        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row['subscriptionId']] = (int) $row['unreadCount'];
        }

        return $map;
    }
```

- [ ] **Step 3: Add `unreadCount` to `SubscriptionJson`**

Change the signature and payload:

```php
    public static function one(Subscription $sub, int $unreadCount = 0): array
    {
        // ... existing body ...
        return [
            // ... existing keys ...
            'tags' => $tags,
            'unreadCount' => $unreadCount,
        ];
    }
```

Update the docblock `@return` to include `unreadCount: int`.

- [ ] **Step 4: Wire counts into `SubscriptionController::list`**

```php
    public function list(#[CurrentUser] User $user): JsonResponse
    {
        $rows = $this->subscriptionRepo->findForUserWithTags((int) $user->getId());
        $counts = $this->subscriptionRepo->unreadCountsForUser((int) $user->getId());

        return new JsonResponse([
            'subscriptions' => array_map(
                static fn ($s) => SubscriptionJson::one($s, $counts[(int) $s->getId()] ?? 0),
                $rows,
            ),
        ]);
    }
```

The `create`/`update` responses keep the default `unreadCount: 0` (a freshly created/edited subscription has none surfaced yet), which is correct — no change needed there.

- [ ] **Step 5: Flip the existing assertion in `SubscriptionControllerTest`**

Change line ~105 from:

```php
        self::assertArrayNotHasKey('unreadCount', $first); // 4b adds it
```

to:

```php
        self::assertArrayHasKey('unreadCount', $first);
        self::assertSame(0, $first['unreadCount']);
```

(The subscribed fixture feed's entries came from `rss2-basic.xml` ingestion via discovery? No — subscribe stores the feed and ingests on subscribe. If that fixture yields unread > 0, assert `assertGreaterThanOrEqual(0, ...)` instead and confirm the actual number; adjust to observed reality rather than forcing 0.)

- [ ] **Step 6: Run the affected tests, expect pass** — `vendor/bin/phpunit tests/Repository/UnreadCountsTest.php tests/Controller/Api/SubscriptionControllerTest.php`. cs/stan/md on touched files.

- [ ] **Step 7: Commit** — `git add -A && git commit -m "feat(reader): unread counts on GET /api/subscriptions"`

---

## Task 7: OPML export — `GET /api/opml/export`

**Files:**
- Create: `backend/src/Service/Opml/OpmlExporter.php`, `backend/src/Controller/Api/OpmlController.php`
- Modify: `backend/src/Repository/TagRepository.php` (add `findOneByNameForUser`, used by Task 8 — add here to keep repo edits together)
- Test: `backend/tests/Service/Opml/OpmlExporterTest.php`, `backend/tests/Controller/Api/OpmlControllerTest.php`

- [ ] **Step 1: Write the failing exporter test**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Opml;

use App\Entity\Feed;
use App\Entity\Subscription;
use App\Entity\Tag;
use App\Entity\User;
use App\Service\Opml\OpmlExporter;
use App\Tests\DbTestCase;

final class OpmlExporterTest extends DbTestCase
{
    private function exporter(): OpmlExporter
    {
        $svc = self::getContainer()->get(OpmlExporter::class);
        self::assertInstanceOf(OpmlExporter::class, $svc);

        return $svc;
    }

    public function testExportsTaggedAndUntaggedFeeds(): void
    {
        $user = new User('x@example.com', new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $this->em->persist($user);

        $tagged = new Feed('https://news.example.com/feed.xml');
        $tagged->setTitle('News & Views');
        $tagged->setSiteUrl('https://news.example.com/');
        $this->em->persist($tagged);
        $untagged = new Feed('https://blog.example.com/feed.xml');
        $untagged->setTitle('Blog');
        $this->em->persist($untagged);

        $tag = new Tag($user, 'Daily');
        $this->em->persist($tag);

        $s1 = new Subscription($user, $tagged, new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $s1->addTag($tag);
        $this->em->persist($s1);
        $s2 = new Subscription($user, $untagged, new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $this->em->persist($s2);
        $this->em->flush();

        $xml = $this->exporter()->export($user);

        self::assertStringContainsString('<opml version="2.0">', $xml);
        // Tag group wraps its feed; XML-escaped ampersand survives.
        self::assertStringContainsString('text="Daily"', $xml);
        self::assertStringContainsString('xmlUrl="https://news.example.com/feed.xml"', $xml);
        self::assertStringContainsString('News &amp; Views', $xml);
        self::assertStringContainsString('xmlUrl="https://blog.example.com/feed.xml"', $xml);

        // It must be well-formed and re-parseable.
        $doc = new \DOMDocument();
        self::assertTrue($doc->loadXML($xml));
    }
}
```

- [ ] **Step 2: Implement `OpmlExporter`**

```php
<?php

declare(strict_types=1);

namespace App\Service\Opml;

use App\Entity\Subscription;
use App\Entity\User;
use App\Repository\SubscriptionRepository;

/**
 * Serialises a user's subscriptions to OPML 2.0, grouped by tag. A feed with
 * several tags appears under each group (OPML is a tree); untagged feeds sit at
 * the body root. DOMDocument handles all escaping.
 */
final readonly class OpmlExporter
{
    public function __construct(
        private SubscriptionRepository $subscriptions,
    ) {
    }

    public function export(User $user): string
    {
        $subs = $this->subscriptions->findForUserWithTags((int) $user->getId());

        $doc = new \DOMDocument('1.0', 'UTF-8');
        $doc->formatOutput = true;

        $opml = $doc->createElement('opml');
        $opml->setAttribute('version', '2.0');
        $doc->appendChild($opml);

        $head = $doc->createElement('head');
        $head->appendChild($doc->createElement('title', 'Simple Feed Reader subscriptions'));
        $opml->appendChild($head);

        $body = $doc->createElement('body');
        $opml->appendChild($body);

        [$byTag, $untagged] = $this->group($subs);

        foreach ($byTag as $tagName => $group) {
            $outline = $doc->createElement('outline');
            $outline->setAttribute('text', (string) $tagName);
            $outline->setAttribute('title', (string) $tagName);
            foreach ($group as $sub) {
                $outline->appendChild($this->feedOutline($doc, $sub));
            }
            $body->appendChild($outline);
        }

        foreach ($untagged as $sub) {
            $body->appendChild($this->feedOutline($doc, $sub));
        }

        return (string) $doc->saveXML();
    }

    /**
     * @param list<Subscription> $subs
     *
     * @return array{0: array<string, list<Subscription>>, 1: list<Subscription>}
     */
    private function group(array $subs): array
    {
        $byTag = [];
        $untagged = [];
        foreach ($subs as $sub) {
            $tags = $sub->getTags();
            if ($tags->isEmpty()) {
                $untagged[] = $sub;
                continue;
            }
            foreach ($tags as $tag) {
                $byTag[$tag->getName()][] = $sub;
            }
        }

        return [$byTag, $untagged];
    }

    private function feedOutline(\DOMDocument $doc, Subscription $sub): \DOMElement
    {
        $feed = $sub->getFeed();
        $title = $sub->getCustomTitle() ?? $feed->getTitle() ?? $feed->getUrl();

        $outline = $doc->createElement('outline');
        $outline->setAttribute('type', 'rss');
        $outline->setAttribute('text', $title);
        $outline->setAttribute('title', $title);
        $outline->setAttribute('xmlUrl', $feed->getUrl());
        if ($feed->getSiteUrl() !== null) {
            $outline->setAttribute('htmlUrl', $feed->getSiteUrl());
        }

        return $outline;
    }
}
```

- [ ] **Step 3: Add `findOneByNameForUser` to `TagRepository`** (case-insensitive; used by import)

```php
    public function findOneByNameForUser(int $userId, string $name): ?Tag
    {
        /** @var Tag|null $tag */
        $tag = $this->createQueryBuilder('t')
            ->andWhere('t.user = :user')->setParameter('user', $userId)
            ->andWhere('LOWER(t.name) = LOWER(:name)')->setParameter('name', $name)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $tag;
    }
```

- [ ] **Step 4: Implement `OpmlController::export` + functional test**

`OpmlController.php`:

```php
<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\User;
use App\Service\Opml\OpmlExporter;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

#[Route('/api/opml')]
final class OpmlController
{
    public function __construct(
        private readonly OpmlExporter $exporter,
    ) {
    }

    #[Route('/export', name: 'api_opml_export', methods: ['GET'])]
    public function export(#[CurrentUser] User $user): Response
    {
        $xml = $this->exporter->export($user);

        return new Response($xml, Response::HTTP_OK, [
            'Content-Type' => 'text/x-opml; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="feeds.opml"',
        ]);
    }
}
```

Functional test `backend/tests/Controller/Api/OpmlControllerTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Entity\Feed;
use App\Entity\Subscription;
use App\Entity\User;
use App\Tests\Support\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class OpmlControllerTest extends WebTestCase
{
    /** @return array{0: array<string,string>, 1: User} */
    private function auth(string $email): array
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        self::assertInstanceOf(UserPasswordHasherInterface::class, $hasher);
        $user = (new UserFactory($em, $hasher))->create($email);
        $tokens = self::getContainer()->get(JWTTokenManagerInterface::class);
        self::assertInstanceOf(JWTTokenManagerInterface::class, $tokens);

        return [['HTTP_AUTHORIZATION' => 'Bearer ' . $tokens->create($user)], $user];
    }

    public function testExportRequiresAuth(): void
    {
        $client = self::createClient();
        $client->request('GET', '/api/opml/export');
        self::assertResponseStatusCodeSame(401);
    }

    public function testExportReturnsOpmlAttachment(): void
    {
        $client = self::createClient();
        [$headers, $user] = $this->auth('opml-export@example.com');
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $feed = new Feed('https://example.com/feed.xml');
        $feed->setTitle('Example');
        $em->persist($feed);
        $em->persist(new Subscription($user, $feed, new \DateTimeImmutable('2026-07-01T00:00:00Z')));
        $em->flush();

        $client->request('GET', '/api/opml/export', server: $headers);
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('text/x-opml', (string) $client->getResponse()->headers->get('Content-Type'));
        self::assertStringContainsString('xmlUrl="https://example.com/feed.xml"', (string) $client->getResponse()->getContent());
    }
}
```

- [ ] **Step 5: Run tests, expect pass. cs/stan/md on touched files.**

- [ ] **Step 6: Commit** — `git add -A && git commit -m "feat(reader): OPML export endpoint"`

---

## Task 8: OPML import — `POST /api/opml/import`

**Files:**
- Create: `backend/src/Exception/InvalidOpmlException.php`, `backend/src/Service/Opml/OpmlImportResult.php`, `backend/src/Service/Opml/OpmlImporter.php`, `backend/tests/Fixtures/opml/subscriptions.opml`
- Modify: `backend/src/Controller/Api/OpmlController.php`
- Test: `backend/tests/Service/Opml/OpmlImporterTest.php`, extend `OpmlControllerTest`

- [ ] **Step 1: Create the fixture** `backend/tests/Fixtures/opml/subscriptions.opml`

```xml
<?xml version="1.0" encoding="UTF-8"?>
<opml version="2.0">
  <head><title>Export</title></head>
  <body>
    <outline text="News" title="News">
      <outline type="rss" text="Heise" xmlUrl="https://www.heise.de/rss/heise.rdf" htmlUrl="https://www.heise.de/"/>
      <outline type="rss" text="Tagesschau" xmlUrl="https://www.tagesschau.de/index~rss2.xml"/>
    </outline>
    <outline type="rss" text="Rootfeed" xmlUrl="https://blog.example.com/feed.xml"/>
    <outline text="Empty folder" title="Empty folder"/>
    <outline text="No url outline"/>
  </body>
</opml>
```

- [ ] **Step 2: Write `InvalidOpmlException` and `OpmlImportResult`**

`InvalidOpmlException.php`:

```php
<?php

declare(strict_types=1);

namespace App\Exception;

use Symfony\Component\HttpFoundation\Response;

final class InvalidOpmlException extends ApiException
{
    public function __construct(?string $detail = null)
    {
        parent::__construct('invalid_opml', Response::HTTP_UNPROCESSABLE_ENTITY, 'The OPML document could not be parsed', $detail);
    }
}
```

`OpmlImportResult.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Opml;

final readonly class OpmlImportResult
{
    public function __construct(
        public int $imported = 0,
        public int $alreadySubscribed = 0,
        public int $invalid = 0,
        public int $skippedOverLimit = 0,
    ) {
    }

    public function with(
        int $imported = 0,
        int $alreadySubscribed = 0,
        int $invalid = 0,
        int $skippedOverLimit = 0,
    ): self {
        return new self(
            $this->imported + $imported,
            $this->alreadySubscribed + $alreadySubscribed,
            $this->invalid + $invalid,
            $this->skippedOverLimit + $skippedOverLimit,
        );
    }
}
```

- [ ] **Step 3: Write the failing importer test**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Opml;

use App\Entity\Feed;
use App\Entity\Subscription;
use App\Entity\Tag;
use App\Entity\User;
use App\Exception\InvalidOpmlException;
use App\Service\Opml\OpmlImporter;
use App\Tests\DbTestCase;

final class OpmlImporterTest extends DbTestCase
{
    private function importer(): OpmlImporter
    {
        $svc = self::getContainer()->get(OpmlImporter::class);
        self::assertInstanceOf(OpmlImporter::class, $svc);

        return $svc;
    }

    private function user(string $email): User
    {
        $user = new User($email, new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    private function fixture(): string
    {
        return (string) file_get_contents(__DIR__ . '/../../Fixtures/opml/subscriptions.opml');
    }

    public function testImportsFeedsCreatesTagsAndSchedulesFetch(): void
    {
        $user = $this->user('import@example.com');

        $result = $this->importer()->import($user, $this->fixture());

        self::assertSame(3, $result->imported); // 2 under News + 1 root feed
        self::assertSame(0, $result->alreadySubscribed);

        $subs = $this->em->getRepository(Subscription::class)->findBy(['user' => $user]);
        self::assertCount(3, $subs);

        // New feeds are due now so the next refresh populates them (no inline fetch).
        $feed = $this->em->getRepository(Feed::class)->findOneBy(['url' => 'https://blog.example.com/feed.xml']);
        self::assertNotNull($feed);
        self::assertNotNull($feed->getNextFetchAt());

        // The "News" folder became a tag attached to its two feeds.
        $tag = $this->em->getRepository(Tag::class)->findOneBy(['user' => $user, 'name' => 'News']);
        self::assertNotNull($tag);
    }

    public function testReusesExistingFeedAndSkipsAlreadySubscribed(): void
    {
        $user = $this->user('dupe@example.com');
        $feed = new Feed('https://blog.example.com/feed.xml');
        $this->em->persist($feed);
        $this->em->persist(new Subscription($user, $feed, new \DateTimeImmutable('2026-07-01T00:00:00Z')));
        $this->em->flush();

        $result = $this->importer()->import($user, $this->fixture());

        self::assertSame(2, $result->imported);        // the two News feeds
        self::assertSame(1, $result->alreadySubscribed); // blog.example.com
        self::assertCount(1, $this->em->getRepository(Feed::class)->findBy(['url' => 'https://blog.example.com/feed.xml']));
    }

    public function testRejectsNonOpml(): void
    {
        $user = $this->user('bad@example.com');
        $this->expectException(InvalidOpmlException::class);
        $this->importer()->import($user, '<html><body>not opml</body></html>');
    }

    public function testRejectsMalformedXml(): void
    {
        $user = $this->user('malformed@example.com');
        $this->expectException(InvalidOpmlException::class);
        $this->importer()->import($user, 'this is { not xml');
    }

    public function testRejectsDtd(): void
    {
        $user = $this->user('dtd@example.com');
        $this->expectException(InvalidOpmlException::class);
        $this->importer()->import(
            $user,
            '<?xml version="1.0"?><!DOCTYPE opml [<!ENTITY z "zz">]><opml version="2.0"><body/></opml>',
        );
    }
}
```

- [ ] **Step 4: Implement `OpmlImporter`**

```php
<?php

declare(strict_types=1);

namespace App\Service\Opml;

use App\Entity\Feed;
use App\Entity\Subscription;
use App\Entity\Tag;
use App\Entity\User;
use App\Exception\InvalidOpmlException;
use App\Repository\FeedRepository;
use App\Repository\SubscriptionRepository;
use App\Repository\TagRepository;
use App\Service\Subscription\SubscriptionService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * Imports an OPML file into a user's subscriptions WITHOUT fetching anything.
 * Feeds are find-or-created and marked due (nextFetchAt = now) so the next
 * refresh cycle populates them; the fetcher's SSRF guard applies then. The XML
 * is hardened the same way FeedParser hardens feeds: no network, no DTD.
 */
final readonly class OpmlImporter
{
    private const MAX_TAG_NAME = 100;

    public function __construct(
        private EntityManagerInterface $em,
        private FeedRepository $feeds,
        private SubscriptionRepository $subscriptions,
        private TagRepository $tags,
        private ClockInterface $clock,
    ) {
    }

    public function import(User $user, string $opml): OpmlImportResult
    {
        $body = $this->parseBody($opml);

        $existing = $this->subscriptions->countForUser((int) $user->getId());
        $result = new OpmlImportResult();

        // Depth-first: each feed outline inherits the nearest ancestor group's
        // title as its tag. `null` tag = body root (untagged).
        foreach ($this->collectFeeds($body, null) as [$xmlUrl, $tagName]) {
            if (!$this->isImportableUrl($xmlUrl)) {
                $result = $result->with(invalid: 1);
                continue;
            }

            $feed = $this->findOrCreateFeed($xmlUrl);

            if ($feed->getId() !== null && $this->subscriptions->existsForUserAndFeed((int) $user->getId(), (int) $feed->getId())) {
                $result = $result->with(alreadySubscribed: 1);
                continue;
            }

            if ($existing >= SubscriptionService::MAX_SUBSCRIPTIONS_PER_USER) {
                $result = $result->with(skippedOverLimit: 1);
                continue;
            }

            $sub = new Subscription($user, $feed, $this->clock->now());
            $tag = $this->resolveTag($user, $tagName);
            if ($tag !== null) {
                $sub->addTag($tag);
            }
            $this->em->persist($sub);
            $existing++;
            $result = $result->with(imported: 1);
        }

        $this->em->flush();

        return $result;
    }

    private function parseBody(string $opml): \DOMElement
    {
        $doc = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try {
            $loaded = $doc->loadXML($opml, \LIBXML_NONET | \LIBXML_COMPACT);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        $root = $doc->documentElement;
        if ($loaded === false || $root === null || $doc->doctype !== null || $root->localName !== 'opml') {
            throw new InvalidOpmlException('Not a well-formed OPML 2.0 document.');
        }

        $body = $doc->getElementsByTagName('body')->item(0);
        if (!$body instanceof \DOMElement) {
            throw new InvalidOpmlException('OPML has no <body>.');
        }

        return $body;
    }

    /**
     * @return list<array{0: string, 1: string|null}> [xmlUrl, tagName]
     */
    private function collectFeeds(\DOMElement $node, ?string $inheritedTag): array
    {
        $out = [];
        foreach ($node->childNodes as $child) {
            if (!$child instanceof \DOMElement || $child->localName !== 'outline') {
                continue;
            }

            $xmlUrl = trim($child->getAttribute('xmlUrl'));
            if ($xmlUrl !== '') {
                $out[] = [$xmlUrl, $inheritedTag];
                continue;
            }

            // A group outline: its text/title becomes the tag for descendants.
            $groupName = trim($child->getAttribute('text'));
            if ($groupName === '') {
                $groupName = trim($child->getAttribute('title'));
            }
            $childTag = $groupName !== '' ? $groupName : $inheritedTag;
            foreach ($this->collectFeeds($child, $childTag) as $descendant) {
                $out[] = $descendant;
            }
        }

        return $out;
    }

    private function isImportableUrl(string $url): bool
    {
        if (mb_strlen($url) > 2048) {
            return false;
        }
        $scheme = parse_url($url, \PHP_URL_SCHEME);
        $host = parse_url($url, \PHP_URL_HOST);

        return \in_array($scheme, ['http', 'https'], true) && \is_string($host) && $host !== '';
    }

    private function findOrCreateFeed(string $xmlUrl): Feed
    {
        $feed = $this->feeds->findOneBy(['url' => $xmlUrl]);
        if ($feed !== null) {
            return $feed;
        }

        $feed = new Feed($xmlUrl);
        $feed->setNextFetchAt($this->clock->now()); // due now → next refresh populates it
        $this->em->persist($feed);

        return $feed;
    }

    private function resolveTag(User $user, ?string $name): ?Tag
    {
        if ($name === null) {
            return null;
        }
        $name = mb_substr($name, 0, self::MAX_TAG_NAME);

        $tag = $this->tags->findOneByNameForUser((int) $user->getId(), $name);
        if ($tag !== null) {
            return $tag;
        }

        $tag = new Tag($user, $name);
        $this->em->persist($tag);

        return $tag;
    }
}
```

**Note on `MAX_SUBSCRIPTIONS_PER_USER`:** confirm the constant is `public` on `SubscriptionService`. If it is `private`, either make it `public` (preferred — it is a shared domain limit) in this task, or duplicate the literal with a comment pointing at the source. Do not leave a dangling reference.

- [ ] **Step 5: Run the importer test, expect pass.**

- [ ] **Step 6: Add the controller action + functional test**

Append to `OpmlController` (inject `OpmlImporter $importer`):

```php
    #[Route('/import', name: 'api_opml_import', methods: ['POST'])]
    public function import(#[CurrentUser] User $user, Request $request): JsonResponse
    {
        $body = $request->getContent();
        if ($body === '' || \strlen($body) > 1_048_576) {
            throw new InvalidOpmlException('The OPML body is empty or larger than 1 MB.');
        }

        $result = $this->importer->import($user, $body);

        return new JsonResponse([
            'imported' => $result->imported,
            'alreadySubscribed' => $result->alreadySubscribed,
            'invalid' => $result->invalid,
            'skippedOverLimit' => $result->skippedOverLimit,
        ]);
    }
```

Add imports `App\Exception\InvalidOpmlException`, `App\Service\Opml\OpmlImporter`, `Symfony\Component\HttpFoundation\JsonResponse`, `Symfony\Component\HttpFoundation\Request`.

Functional test (append to `OpmlControllerTest`):

```php
    public function testImportCreatesSubscriptions(): void
    {
        $client = self::createClient();
        [$headers] = $this->auth('opml-import@example.com');
        $opml = (string) file_get_contents(__DIR__ . '/../../Fixtures/opml/subscriptions.opml');

        $client->request('POST', '/api/opml/import', server: $headers + ['CONTENT_TYPE' => 'text/x-opml'], content: $opml);
        self::assertResponseIsSuccessful();
        $body = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        self::assertSame(3, $body['imported']);

        $client->request('GET', '/api/subscriptions', server: $headers);
        $list = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($list);
        self::assertCount(3, $list['subscriptions']);
    }

    public function testImportRejectsEmptyBody(): void
    {
        $client = self::createClient();
        [$headers] = $this->auth('opml-empty@example.com');
        $client->request('POST', '/api/opml/import', server: $headers + ['CONTENT_TYPE' => 'text/x-opml'], content: '');
        self::assertResponseStatusCodeSame(422);
    }
```

- [ ] **Step 7: Run all OPML tests + full suite once, expect pass. cs/stan/md on touched files.**

- [ ] **Step 8: Commit** — `git add -A && git commit -m "feat(reader): OPML import endpoint"`

---

## Task 9: Extend the real-feed e2e journey

**Files:**
- Modify: `backend/tests/E2e/ReaderJourneyE2eTest.php`

Per the standing rule ([[e2e-via-docker-and-devlog]]), the black-box suite grows with each feature. Add reader-surface coverage to the existing per-domain journey (or a sibling test if that keeps it readable): after subscribe + refresh, assert entries come back, exercise state, and round-trip OPML. Keep the host-side reachability probe and `markTestSkipped` for unreachable domains.

- [ ] **Step 1: Read the current e2e test** to reuse its auth helper, reachability probe, and base URL constants.

- [ ] **Step 2: Add an entries+state+opml leg** (illustrative — adapt to the file's existing helpers):

```php
    // After the existing subscribe→refresh for a reachable domain:

    // 1. Entries list is populated and unread.
    $entries = $this->getJson('/api/entries?subscription=' . $subscriptionId, $headers);
    self::assertNotEmpty($entries['entries'], 'refresh should have ingested entries');
    self::assertFalse($entries['entries'][0]['isRead']);
    $entryId = $entries['entries'][0]['id'];

    // 2. Mark one read; it flips.
    $this->patchJson("/api/entries/$entryId/state", ['isRead' => true], $headers);
    $afterRead = $this->getJson('/api/entries?subscription=' . $subscriptionId . '&view=unread', $headers);
    foreach ($afterRead['entries'] as $e) {
        self::assertNotSame($entryId, $e['id']);
    }

    // 3. Unread count is surfaced on the subscription list.
    $subs = $this->getJson('/api/subscriptions', $headers);
    self::assertArrayHasKey('unreadCount', $subs['subscriptions'][0]);

    // 4. mark-read all → unread view empties.
    $this->postJson('/api/entries/mark-read', ['scope' => 'all', 'until' => (new \DateTimeImmutable('+1 day'))->format(DATE_ATOM)], $headers);
    $empty = $this->getJson('/api/entries?view=unread', $headers);
    self::assertSame([], $empty['entries']);

    // 5. OPML round-trip: export contains the feed's xmlUrl.
    $opml = $this->getRaw('/api/opml/export', $headers);
    self::assertStringContainsString('xmlUrl=', $opml);
```

If the e2e helper set lacks `patchJson`/`postJson`/`getRaw`, add thin wrappers mirroring the existing `getJson`. Clean up created subscriptions at the end as the current test does.

- [ ] **Step 3: Run e2e against the Docker stack**

```bash
composer e2e
```

Expected: reader legs pass for reachable domains; unreachable domains skip. Scan `backend/var/log/dev.log` in the container for new deprecations/errors.

- [ ] **Step 4: Commit** — `git add -A && git commit -m "test(e2e): reader entries, state, mark-read and OPML round-trip"`

---

## Task 10: Final gate sweep + docs/memory

- [ ] **Step 1: Full suite on both engines**

```bash
cd backend
vendor/bin/phpunit                              # SQLite; MUST print "OK" (not "OK, but there were issues!")
DATABASE_URL="mysql://root@127.0.0.1:3306/feedreader_test?serverVersion=8.4" vendor/bin/phpunit
```

Both green, exit 0. A "risky"/"deprecation" line means failure — fix it.

- [ ] **Step 2: Quality gate**

```bash
composer cs && composer stan
composer md 2>&1 | grep -E 'Entry|Opml|MarkRead|Subscription|Tag' || echo "touched files clean"
```

Run PhpStorm inspections on the changed PHP via `mcp__phpstorm__lint_files`; block on ERROR/WARNING (per [[phpstorm-inspections-quality-gate]]). Every touched `src` file must be phpmd-clean (per [[phpmd-fix-touched-files]] — extract to clear any codesize/complexity finding on a file this plan created or edited).

- [ ] **Step 3: Update docs/memory**
- Update `feed-reader-plan-progress.md`: mark 4b DONE, note the endpoints shipped and the six design decisions; next is plan 5 (Angular).
- Note in [[native-ios-readiness]] if anything here is web-coupled (nothing should be — flag if it is).

- [ ] **Step 4: Final review + finish the branch**
- Dispatch a final code-reviewer subagent over the whole diff (`git diff develop...HEAD`).
- Then use **superpowers:finishing-a-development-branch** to merge `feature/04b-reader-entries-state-opml` into `develop` (git-flow; no worktree).

---

## Self-review notes (author)

- **Spec coverage:** entries list + cursor (T1–T3) ✔, PATCH state (T4) ✔, mark-read watermark+until (T5) ✔, unread counts on subscriptions (T6) ✔, OPML import/export (T7–T8) ✔. Retention protection for favorite/kept already exists in `EntryPruner` (verified) — no task needed.
- **Type consistency:** `EntryQuery`/`EntryListRow`/`EntryCursor` names are stable across T1–T3; `unreadCountsForUser` returns `array<int,int>` consumed as `$counts[id] ?? 0` in T6; `MarkReadService::mark(User,string,?int,\DateTimeImmutable)` matches its DTO in T5.
- **Portability:** all queries use DQL with `COALESCE` and `Types::BOOLEAN` params (mirrors `EntryPruner`), no engine-specific SQL. Keyset pagination avoids offset drift.
- **Native iOS:** every endpoint is bearer-JWT/stateless/JSON; OPML export is a file download, import is a raw body — both fine for a native client.
- **Risk to watch during execution:** the unrelated-entity DQL joins (`JOIN Subscription s WITH s.feed = e.feed`) and mixed scalar+entity hydration in `listForUser`. If Doctrine hydration of the aliased scalars misbehaves on either engine, fall back to a DBAL native query returning the same `EntryListRow` list — the row shape is the contract, not the query mechanism.
