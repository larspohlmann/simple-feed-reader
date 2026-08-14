# Effective Date and Purge by Fetch Date Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Stop purged articles that a feed still serves from re-entering the reader at the top of the list, by sorting the list on one clamped `effective_date` column and measuring retention from the fetch instant instead of the publication date.

**Architecture:** `effective_date` becomes the single list sort key again (`effectiveDate DESC, id DESC`), and `created_at` becomes the honest fetch instant that nothing sorts on. A new `EntryEffectiveDate` policy decides each entry's sort instant from a per-pass `FeedIngestContext` (run instant plus the feed's *previous* fetch time): an article the feed was already serving when we last looked keeps its publication date and sinks; anything newer arrives at the run instant. `EntryPruner` switches its age pass to `created_at` and gains a 20-entry-per-feed floor.

**Tech Stack:** Symfony 7.4 LTS, PHP 8.4, Doctrine ORM, PHPUnit (plain `TestCase` for policy and cursor, `DbTestCase` for repository/pruner/ingest integration), Doctrine Migrations.

Issue: [#384](https://github.com/larspohlmann/simple-feed-reader/issues/384). The issue body is the spec — every decision below was settled there. Follow-up: [#385](https://github.com/larspohlmann/simple-feed-reader/issues/385) (production backfill, deliberately out of scope).

## Global Constraints

- `declare(strict_types=1);` in every PHP file.
- Clean Code house style: `final readonly class` with constructor promotion, guard clauses, names reveal intent, no boolean flag parameters, comments explain *why*.
- Every touched `src` file must be PHPMD-clean (`composer md`), PSR-12 clean (`composer cs`), PHPStan-level-max clean (`composer stan`) and phptramp-clean (`composer tramp`) before commit — not merely free of *new* findings.
- `RETENTION_DAYS` stays **90**. `DEFAULT_MAX_ENTRIES_PER_FEED` stays **2000**.
- The per-feed floor is **20** entries. The first-fetch cap is **200** entries.
- The grace window is per feed and relative to that feed's own previous fetch. No floor, no cap on it.
- `effective_date` is **never later than the fetch instant**, in every code path.
- "First fetch" is feed-level: `feed.getLastFetchedAt() === null`. Never per user.
- No data backfill in this branch. No display change. No tombstones. No `last_seen_at`. No archive crawling.
- Datetimes are naive UTC (see CLAUDE.md); do not introduce a timezone conversion anywhere in this work.
- Run tests with `cd backend && php bin/phpunit …`. Never bare `phpunit`.

## File Structure

| File | Responsibility |
|---|---|
| `backend/src/Service/FeedIngestContext.php` | **Create.** Value object: one ingest pass's run instant and the feed's previous fetch time. |
| `backend/src/Service/EntryEffectiveDate.php` | **Create.** The clamp rule. Pure function, no state, no collaborators. |
| `backend/src/Entity/Entry.php` | **Modify.** Takes `effectiveDate` explicitly; `setPublishedAt()` stops deriving it. |
| `backend/src/Service/EntryIngestor.php` | **Modify.** Takes a `FeedIngestContext` instead of a bare `$fetchedAt`; applies the policy. |
| `backend/src/Service/Subscription/FirstFetchRecorder.php` | **Modify.** Builds a first-fetch context; caps the pass at 200 entries. |
| `backend/src/Service/Refresh/RefreshRunner.php` | **Modify.** Builds a refresh context from the pre-run `lastFetchedAt`. |
| `backend/src/Repository/EntryRepository.php` | **Modify.** Sort and keyset on `(effectiveDate, id)`. |
| `backend/src/Http/EntryCursor.php` | **Modify.** Two parts: `<effectiveDate>|<id>`. |
| `backend/src/Controller/Api/EntryController.php` | **Modify.** Encodes the next cursor from `effectiveDate`. |
| `backend/src/Service/EntryPruner.php` | **Modify.** Age pass on `createdAt`; 20-entry floor; cap pass on `createdAt`. |
| `backend/src/Command/E2eSeedAdminSubscriptionCommand.php` | **Modify.** Follows the new `Entry` constructor. |
| `backend/migrations/Version20260814120000.php` | **Create.** Drops the dead `idx_entry_list`. |

**No frontend work.** The SPA treats the cursor as an opaque string ([reader-api.ts:58](../../../frontend/src/app/reader/reader-api.ts)) and renders `publishedAt ?? createdAt`, which this branch does not change.

---

### Task 1: `FeedIngestContext` and `EntryEffectiveDate`

**Status: already implemented on this branch, uncommitted.** Verify it matches what follows, then commit it. If it differs, the plan wins.

**Files:**
- Create: `backend/src/Service/FeedIngestContext.php`
- Create: `backend/src/Service/EntryEffectiveDate.php`
- Test: `backend/tests/Service/EntryEffectiveDateTest.php`

**Interfaces:**
- Produces: `FeedIngestContext::__construct(\DateTimeImmutable $fetchedAt, ?\DateTimeImmutable $previousFetchAt)` with both as public readonly properties. A null `previousFetchAt` means first fetch.
- Produces: `EntryEffectiveDate::for(?\DateTimeImmutable $publishedAt, FeedIngestContext $context): \DateTimeImmutable`.

The rule, in the order the guards must run:

1. `publishedAt` is null, or later than `fetchedAt` → `fetchedAt`.
2. `previousFetchAt` is null (first fetch) → `publishedAt`.
3. `publishedAt < previousFetchAt` → `publishedAt`.
4. Otherwise → `fetchedAt`.

- [x] **Step 1: Write the failing test** — `backend/tests/Service/EntryEffectiveDateTest.php`, eight cases: first fetch keeps the published date; first fetch without a published date uses the fetch instant; refresh without a published date uses the fetch instant; an article published since the last fetch arrives at the fetch instant; an article published before the last fetch keeps its published date; an article stamped at exactly the previous fetch arrives at the fetch instant (the boundary is exclusive); a future published date is clamped on refresh; a future published date is clamped on a first fetch too.

- [x] **Step 2: Run test to verify it fails**

Run: `cd backend && php bin/phpunit tests/Service/EntryEffectiveDateTest.php`
Expected: FAIL — `Class "App\Service\FeedIngestContext" not found`, 8 errors.

- [x] **Step 3: Write minimal implementation** — both classes as described above. Narrow `previousFetchAt` into a local before comparing, so PHPStan level max sees the null check.

- [x] **Step 4: Run test to verify it passes**

Run: `cd backend && php bin/phpunit tests/Service/EntryEffectiveDateTest.php`
Expected: PASS (8 tests, 8 assertions).

- [ ] **Step 5: Commit**

```bash
git add backend/src/Service/FeedIngestContext.php backend/src/Service/EntryEffectiveDate.php backend/tests/Service/EntryEffectiveDateTest.php
git commit -m "feat(#384): effective-date policy clamped to the feed's own previous fetch"
```

---

### Task 2: `Entry` takes its effective date and the ingest path stamps it

One task because the four changes are mechanically inseparable: the moment `Entry`'s constructor grows a parameter, `EntryIngestor` must pass it, and the moment `ingest()` takes a context, both of its callers must build one. Splitting them would put a commit with a red suite in the branch history.

`Entry` derives `effectiveDate` today and has no setter, so it cannot drift. The rule now needs context the entity has no business knowing, so the derivation moves out and the value arrives as a constructor argument. `setPublishedAt()` must stop touching it — otherwise the ingestor's careful value is silently overwritten by the old formula.

**Files:**
- Modify: `backend/src/Entity/Entry.php:77-91` (constructor), `:185-189` (`setPublishedAt`), `:65-75` (docblock)
- Modify: `backend/src/Service/EntryIngestor.php:34-77`
- Modify: `backend/src/Service/Subscription/FirstFetchRecorder.php:52-65`
- Modify: `backend/src/Service/Refresh/RefreshRunner.php:303-340`
- Modify: `backend/src/Command/E2eSeedAdminSubscriptionCommand.php:212-219`
- Test: `backend/tests/Entity/FeedEntryTest.php`, `backend/tests/Service/EntryIngestorTest.php`, `backend/tests/Service/Refresh/` (extend the existing runner integration test)

**Interfaces:**
- Consumes: `FeedIngestContext(\DateTimeImmutable $fetchedAt, ?\DateTimeImmutable $previousFetchAt)` and `EntryEffectiveDate::for(?\DateTimeImmutable $publishedAt, FeedIngestContext $context): \DateTimeImmutable` from Task 1.
- Produces: `Entry::__construct(Feed $feed, string $guid, ?string $url, string $title, \DateTimeImmutable $createdAt, \DateTimeImmutable $effectiveDate)`. Six parameters on an entity constructor is deliberate and stays under PHPMD's `ExcessiveParameterList` threshold of 10; splitting an entity's own identity into a DTO would be churn with no reader.
- Produces: `EntryIngestor::ingest(Feed $feed, ParsedFeed $parsed, FeedIngestContext $context): int`. The third parameter changes type from `\DateTimeImmutable`; the return stays the count of entries created.

- [ ] **Step 1: Write the failing tests**

Add to `backend/tests/Entity/FeedEntryTest.php`. Read the top of that file first for how it builds a `Feed`; reuse that, do not invent a second way.

```php
public function testEntryKeepsTheEffectiveDateItWasGiven(): void
{
    $feed = new Feed('https://example.test/feed.xml');
    $entry = new Entry(
        $feed,
        'guid-1',
        'https://example.test/a',
        'A',
        new \DateTimeImmutable('2026-08-14 12:00:00'),
        new \DateTimeImmutable('2020-03-01 00:00:00'),
    );

    self::assertSame('2020-03-01 00:00:00', $entry->getEffectiveDate()->format('Y-m-d H:i:s'));
}

public function testSettingThePublishedDateDoesNotMoveTheEffectiveDate(): void
{
    $feed = new Feed('https://example.test/feed.xml');
    $entry = new Entry(
        $feed,
        'guid-2',
        'https://example.test/b',
        'B',
        new \DateTimeImmutable('2026-08-14 12:00:00'),
        new \DateTimeImmutable('2026-08-14 12:00:00'),
    );

    $entry->setPublishedAt(new \DateTimeImmutable('2019-01-01 00:00:00'));

    self::assertSame('2026-08-14 12:00:00', $entry->getEffectiveDate()->format('Y-m-d H:i:s'));
    self::assertSame('2019-01-01 00:00:00', $entry->getPublishedAt()?->format('Y-m-d H:i:s'));
}
```

Add to `backend/tests/Service/EntryIngestorTest.php`. Read the file first — it already has helpers that build an ingestor, a `Feed` and a `ParsedFeed`; reuse them rather than writing a second fixture. Add a private `effectiveDateOf(Feed $feed, string $guid): string` helper that loads the entry by guid hash and formats `getEffectiveDate()`.

```php
public function testAnArticleTheFeedAlreadyServedKeepsItsPublishedDate(): void
{
    $feed = $this->feed();
    $parsed = $this->parsedFeed([
        $this->parsedEntry('old', new \DateTimeImmutable('2020-03-01 00:00:00')),
        $this->parsedEntry('new', new \DateTimeImmutable('2026-08-14 07:30:00')),
    ]);

    $this->ingestor->ingest($feed, $parsed, new FeedIngestContext(
        new \DateTimeImmutable('2026-08-14 12:00:00'),
        new \DateTimeImmutable('2026-08-14 06:00:00'),
    ));
    $this->em->flush();

    self::assertSame('2020-03-01 00:00:00', $this->effectiveDateOf($feed, 'old'));
    self::assertSame('2026-08-14 12:00:00', $this->effectiveDateOf($feed, 'new'));
}
```

Add to the refresh integration test. This one is the regression test for the ordering trap described in Step 3 — it fails if the context is built after `recordSuccess()`, because `previousFetchAt` would then be the run instant and every article would sink.

```php
public function testARefreshSinksAnArticleTheFeedServedBeforeTheLastFetch(): void
{
    $feed = $this->feedFetchedAt(new \DateTimeImmutable('2026-08-14 06:00:00'));
    $this->fetcherReturns($feed, $this->rssWith([
        ['guid' => 'old', 'pubDate' => 'Sun, 01 Mar 2020 00:00:00 GMT'],
        ['guid' => 'new', 'pubDate' => 'Fri, 14 Aug 2026 07:30:00 GMT'],
    ]));

    $this->runner->run($this->refreshRequest());

    self::assertSame('2020-03-01 00:00:00', $this->effectiveDateOf($feed, 'old'));
    self::assertSame(
        $this->runInstant()->format('Y-m-d H:i:s'),
        $this->effectiveDateOf($feed, 'new'),
    );
}
```

Read the refresh test directory first and extend the file that already boots a runner against a stub fetcher; use its existing helpers for seeding a feed, stubbing the fetch and building the `RefreshRequest`, and name yours to match whatever it already calls them.

- [ ] **Step 2: Run tests to verify they fail**

Run: `cd backend && php bin/phpunit tests/Entity/FeedEntryTest.php tests/Service/EntryIngestorTest.php tests/Service/Refresh/`
Expected: FAIL — `ArgumentCountError` on the 5-argument `Entry` constructor, and `ingest()` rejecting a `FeedIngestContext` where it wants a `\DateTimeImmutable`.

- [ ] **Step 3: Write minimal implementation**

`Entry::__construct` — add the parameter and assign it:

```php
public function __construct(
    Feed $feed,
    string $guid,
    ?string $url,
    string $title,
    \DateTimeImmutable $createdAt,
    \DateTimeImmutable $effectiveDate,
) {
    $this->feed = $feed;
    $this->guid = $guid;
    $this->guidHash = hash('sha256', $guid);
    $this->url = $url;
    $this->title = $title;
    $this->createdAt = $createdAt;
    $this->effectiveDate = $effectiveDate;
}
```

`Entry::setPublishedAt` — strip the derivation:

```php
public function setPublishedAt(?\DateTimeImmutable $publishedAt): void
{
    $this->publishedAt = $publishedAt;
}
```

`Entry`'s `$effectiveDate` docblock — replace it with why it no longer derives itself:

```php
/**
 * The list-sort instant, decided by EntryEffectiveDate at ingest and never
 * recomputed here. It used to be `publishedAt ?? createdAt`, derived in this
 * class so it could not drift; the rule now needs the fetch that stored the
 * entry and the feed's previous fetch, which an entity has no business
 * knowing. The invariant moved to one policy with its own tests (#384).
 * Materialized rather than COALESCE'd so idx_entry_effective can serve the
 * reader's sort. The column default exists only for the migration on SQLite,
 * which cannot add a NOT NULL column without one.
 */
```

`EntryIngestor::ingest` — change the signature and the docblock:

```php
/**
 * @param FeedIngestContext $context the run instant shared by every entry
 *        this call ingests, and the feed's previous fetch — together they
 *        decide where each entry lands in the list (see EntryEffectiveDate)
 */
public function ingest(Feed $feed, ParsedFeed $parsed, FeedIngestContext $context): int
```

and inside the loop:

```php
$entry = new Entry(
    $feed,
    $parsedEntry->guid,
    $parsedEntry->url === null ? null : mb_substr($parsedEntry->url, 0, self::URL_MAX),
    mb_substr($parsedEntry->title, 0, self::TITLE_MAX),
    $context->fetchedAt,
    EntryEffectiveDate::for($parsedEntry->publishedAt, $context),
);
```

`setPublishedAt()` stays where it is in the loop — it now only stores the raw date.

`FirstFetchRecorder::record` — build a first-fetch context. A null `previousFetchAt` is correct by construction here: the method already returns early unless `getLastFetchedAt()` is null.

```php
$created = $this->ingestor->ingest(
    $feed,
    $discovered->document,
    new FeedIngestContext($this->clock->now(), null),
);
```

`RefreshRunner::persistOutcome` — build the context immediately **before** the ingest call. This ordering is load-bearing: `scheduler->recordSuccess()` further down stamps the new `lastFetchedAt`, so reading it afterwards would yield the run instant for every entry.

```php
// Read the previous fetch BEFORE recordSuccess() below stamps the new one:
// EntryEffectiveDate needs to know when we last looked at this feed, and
// after recordSuccess() that answer is "now" for every entry.
$context = new FeedIngestContext($now, $feed->getLastFetchedAt());

$parsed = $this->bodyParser->parse($feed, $body);
$created = $this->ingestor->ingest($feed, $parsed, $context);
```

Leave the rest of `persistOutcome()` alone.

`E2eSeedAdminSubscriptionCommand` — pass the seed's `$publishedAt` as the effective date, falling back to the created instant, so the seeded fixture keeps the ordering it has today.

- [ ] **Step 4: Run tests to verify they pass**

Run: `cd backend && php bin/phpunit`
Expected: the whole suite green. This task is not done until it is — no commit on this branch may leave the suite red.

- [ ] **Step 5: Commit**

```bash
git add backend/src/Entity/Entry.php backend/src/Service/EntryIngestor.php backend/src/Service/Subscription/FirstFetchRecorder.php backend/src/Service/Refresh/RefreshRunner.php backend/src/Command/E2eSeedAdminSubscriptionCommand.php backend/tests/
git commit -m "feat(#384): stamp every ingested entry through the effective-date policy"
```

---

### Task 3: `FirstFetchRecorder` bounds the subscribe at 200 entries

Task 2 already wired the first-fetch context. This task adds the only remaining first-fetch behaviour: the pass stores at most 200 entries, newest publication first.

**Files:**
- Modify: `backend/src/Service/Subscription/FirstFetchRecorder.php`
- Test: Create `backend/tests/Service/Subscription/FirstFetchRecorderTest.php`

**Interfaces:**
- Consumes: `EntryIngestor::ingest(Feed, ParsedFeed, FeedIngestContext)` from Task 2.
- Produces: `FirstFetchRecorder::record()` keeps its signature and its return (the number of entries stored).

- [ ] **Step 1: Write the failing test**

Create the test file. Copy the container bootstrap and fixtures from `backend/tests/Service/Subscription/SubscriptionServiceTest.php` — read it first and reuse its helpers for building a `Feed` and a `DiscoveredFeed` rather than writing new ones. Add private helpers `effectiveDateOf(Feed $feed, string $guid): string` and `findByGuid(Feed $feed, string $guid): ?Entry`.

```php
public function testAFirstFetchStoresTheArticlesOwnPublicationDates(): void
{
    $feed = $this->feed();
    $discovered = $this->discovered($feed, [
        $this->parsedEntry('a', new \DateTimeImmutable('2020-03-01 00:00:00')),
    ]);

    $this->recorder->record($feed, $discovered);

    self::assertSame('2020-03-01 00:00:00', $this->effectiveDateOf($feed, 'a'));
}

public function testAFirstFetchStoresAtMostTwoHundredEntries(): void
{
    $feed = $this->feed();

    self::assertSame(200, $this->recorder->record($feed, $this->discovered($feed, $this->parsedEntries(250))));
}

public function testTheFirstFetchKeepsTheNewestEntries(): void
{
    $feed = $this->feed();

    $this->recorder->record($feed, $this->discovered($feed, $this->parsedEntries(250)));

    self::assertNull($this->findByGuid($feed, 'guid-0'));
    self::assertNotNull($this->findByGuid($feed, 'guid-249'));
}
```

with a helper that makes the publication dates ascend with the index, so `guid-249` is the newest:

```php
/** @return list<ParsedEntry> */
private function parsedEntries(int $count): array
{
    $entries = [];
    for ($i = 0; $i < $count; $i++) {
        $entries[] = $this->parsedEntry(
            'guid-' . $i,
            new \DateTimeImmutable(sprintf('2026-01-01 00:00:00 +%d minutes', $i)),
        );
    }

    return $entries;
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd backend && php bin/phpunit tests/Service/Subscription/FirstFetchRecorderTest.php`
Expected: FAIL — the cap test reports 250, and `guid-0` is found.

- [ ] **Step 3: Write minimal implementation**

```php
/**
 * A subscribe inserts every stored entry inside one HTTP request, and a feed
 * that serves its whole archive (841 items for one measured in #384) makes
 * that request crawl. This bounds the request, NOT retention: whatever is cut
 * arrives on the next refresh, with the same effective date it would have had,
 * because an article older than the previous fetch keeps its publication date
 * either way.
 */
private const int FIRST_FETCH_MAX_ENTRIES = 200;
```

In `record()`, wrap the document:

```php
$created = $this->ingestor->ingest(
    $feed,
    $this->newest($discovered->document),
    new FeedIngestContext($this->clock->now(), null),
);
```

Add the private `newest(ParsedFeed $document): ParsedFeed` helper that returns a `ParsedFeed` carrying only the newest `FIRST_FETCH_MAX_ENTRIES` entries, sorted by publication date descending with a null date sorting last. Keep it one named method so `EntryIngestor` stays unaware of the cap.

- [ ] **Step 4: Run test to verify it passes**

Run: `cd backend && php bin/phpunit tests/Service/Subscription/ tests/Service/EntryIngestorTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add backend/src/Service/Subscription/FirstFetchRecorder.php backend/tests/Service/Subscription/FirstFetchRecorderTest.php
git commit -m "feat(#384): bound the subscribe at the newest two hundred entries"
```

---

### Task 4: Sort and paginate on `(effectiveDate, id)`

**Files:**
- Modify: `backend/src/Repository/EntryRepository.php:106-135` (sort) and `:225-255` (keyset predicate)
- Modify: `backend/src/Http/EntryCursor.php`
- Modify: `backend/src/Controller/Api/EntryController.php:104-108`
- Test: `backend/tests/Http/EntryCursorTest.php`, `backend/tests/Repository/EntryListTest.php`

**Interfaces:**
- Consumes: nothing from earlier tasks.
- Produces: `EntryCursor::__construct(\DateTimeImmutable $effectiveDate, int $id)`; `EntryCursor::encode(\DateTimeImmutable $effectiveDate, int $id): string`; `EntryCursor::decode(string $cursor): ?self`. The `publishedAt` parameter and property are removed everywhere.

- [ ] **Step 1: Write the failing test**

In `backend/tests/Http/EntryCursorTest.php`, replace the three-part round-trip cases with:

```php
public function testRoundTripsTheEffectiveDateAndId(): void
{
    $cursor = EntryCursor::decode(
        EntryCursor::encode(new \DateTimeImmutable('2026-08-14 12:00:00'), 42),
    );

    self::assertNotNull($cursor);
    self::assertSame('2026-08-14 12:00:00', $cursor->effectiveDate->format('Y-m-d H:i:s'));
    self::assertSame(42, $cursor->id);
}

public function testRejectsAThreePartCursorFromTheOldFormat(): void
{
    $stale = rtrim(strtr(base64_encode('2026-08-14T12:00:00+00:00||42'), '+/', '-_'), '=');

    self::assertNull(EntryCursor::decode($stale));
}
```

Keep the existing malformed-input cases (empty string, non-base64, non-numeric id) — they still apply.

In `backend/tests/Repository/EntryListTest.php`, add:

```php
public function testSortsByEffectiveDateNotByFetchInstant(): void
{
    // Both fetched in the same run; the older article sank to its publication date.
    $fetchedAt = new \DateTimeImmutable('2026-08-14 12:00:00');
    $this->seedEntry('sunk', $fetchedAt, new \DateTimeImmutable('2020-03-01 00:00:00'));
    $this->seedEntry('fresh', $fetchedAt, $fetchedAt);

    $rows = $this->repository->listForUser($this->query());

    self::assertSame(['fresh', 'sunk'], array_map(
        static fn ($row) => $row->entry->getGuid(),
        $rows,
    ));
}
```

Read `EntryListTest.php` first — it has a seeding helper; extend that helper to accept an effective date rather than writing a parallel one.

- [ ] **Step 2: Run test to verify it fails**

Run: `cd backend && php bin/phpunit tests/Http/EntryCursorTest.php tests/Repository/EntryListTest.php`
Expected: FAIL — `EntryCursor::encode()` expects 3 arguments; the sort test returns `['sunk', 'fresh']` because `createdAt` ties and `publishedAt` decides.

- [ ] **Step 3: Write minimal implementation**

`EntryCursor`: two parts, `"<effectiveDate ATOM>|<id>"`. `decode()` requires exactly 2 parts, so a stale three-part cursor returns null and the endpoint answers 422. Rewrite the class docblock:

```php
/**
 * Opaque keyset-pagination cursor for the entry list: base64url of
 * "<effectiveDate ISO8601>|<id>". The client treats it as a token; the format
 * is ours to change.
 *
 * `effectiveDate` is the entry's list-sort instant (see EntryEffectiveDate);
 * `id` breaks the ties it leaves, and there are many — a whole refresh run
 * shares one effective date.
 */
```

`EntryRepository::listForUser`: order by `e.effectiveDate DESC, e.id DESC`. Replace the null-aware three-column keyset with the two-column form:

```php
$qb->andWhere('(e.effectiveDate < :curEffectiveDate '
    . 'OR (e.effectiveDate = :curEffectiveDate AND e.id < :curId))')
    ->setParameter('curEffectiveDate', $cursor->effectiveDate, Types::DATETIME_IMMUTABLE)
    ->setParameter('curId', $cursor->id);
```

The `$cursor->publishedAt === null` branch disappears entirely — there is no nullable column in the key any more. Update the `listForUser` docblock to describe the new sort.

`EntryController`: `EntryCursor::encode($entry->getEffectiveDate(), $entry->getId() ?? 0)`.

- [ ] **Step 4: Run test to verify it passes**

Run: `cd backend && php bin/phpunit tests/Http/EntryCursorTest.php tests/Repository/EntryListTest.php tests/Controller/`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add backend/src/Repository/EntryRepository.php backend/src/Http/EntryCursor.php backend/src/Controller/Api/EntryController.php backend/tests/Http/EntryCursorTest.php backend/tests/Repository/EntryListTest.php
git commit -m "feat(#384): sort and paginate the entry list on the effective date"
```

---

### Task 5: `EntryPruner` measures retention from the fetch

Three changes, one behaviour: retention counts from when we fetched an entry, and a feed never loses its newest 20.

**Files:**
- Modify: `backend/src/Service/EntryPruner.php:15-33` (class docblock), `:72-89` (age pass), `:91-133` (cap pass)
- Test: `backend/tests/Service/EntryPrunerTest.php`

**Interfaces:**
- Consumes: nothing from earlier tasks.
- Produces: `EntryPruner::prune(): int` unchanged.

- [ ] **Step 1: Write the failing test**

```php
public function testKeepsAnOldArticleThatWasFetchedRecently(): void
{
    $feed = $this->feedWithEntries(1);
    $this->seedEntry($feed, guid: 'archive', createdAt: $this->daysAgo(2), effectiveDate: $this->daysAgo(2000));

    $this->pruner->prune();

    self::assertNotNull($this->findByGuid($feed, 'archive'));
}

public function testDeletesAnArticleFetchedBeforeTheRetentionWindow(): void
{
    $feed = $this->feedWithEntries(30, createdAt: $this->daysAgo(100));

    self::assertSame(10, $this->pruner->prune());
}

public function testNeverDeletesAFeedsNewestTwentyEntries(): void
{
    $feed = $this->feedWithEntries(25, createdAt: $this->daysAgo(100));

    $this->pruner->prune();

    self::assertSame(20, $this->countEntries($feed));
}

public function testAFeedOfTwentyOldEntriesLosesNone(): void
{
    $feed = $this->feedWithEntries(20, createdAt: $this->daysAgo(100));

    self::assertSame(0, $this->pruner->prune());
}
```

`testKeepsAnOldArticleThatWasFetchedRecently` is the regression test for the whole ticket: under today's code it fails, because the age pass reads `effectiveDate`. Read the existing test file first and reuse its seeding helpers; extend them with an explicit `createdAt` and `effectiveDate` rather than adding new ones.

- [ ] **Step 2: Run test to verify it fails**

Run: `cd backend && php bin/phpunit tests/Service/EntryPrunerTest.php`
Expected: FAIL — the archive entry is deleted (age pass reads `effectiveDate`), and the 25-entry feed drops to 0 (no floor).

- [ ] **Step 3: Write minimal implementation**

Age pass: `WHERE e.createdAt < :cutoff`, and exclude each feed's newest 20. Express the floor once, as a private method both passes use, so the two cannot disagree about which entries are old:

```php
/**
 * A feed's non-protected entry ids beyond its newest `$keep`, in fetch order
 * (later fetch = newer; id breaks a tie inside one run). Used by both passes:
 * the floor that spares a small feed, and the cap that bounds a huge one.
 *
 * @return list<int>
 */
private function entryIdsBeyond(int $feedId, int $keep): array
```

Then:
- The age pass deletes `entryIdsBeyond($feedId, self::MIN_ENTRIES_PER_FEED)` intersected with "fetched before the cutoff". Doing it per feed costs one query per feed that has anything to prune; keep the existing "only feeds worth scanning" pattern from `pruneByFeedCap()` and select the candidate feed ids first.
- The cap pass calls `entryIdsBeyond($feedId, $this->maxEntriesPerFeed)` and orders on `createdAt DESC, id DESC`.

```php
/**
 * A feed's floor. Retention now measures from the fetch, so a low-volume feed
 * whose articles are all older than the window would otherwise empty itself
 * completely. A floor, not a skip: "spare feeds with 20 or fewer" would still
 * let a feed of 25 old entries drop to zero.
 */
private const int MIN_ENTRIES_PER_FEED = 20;
```

Rewrite the class docblock's paragraph 1: the age pass deletes entries **fetched** more than 90 days ago, never a feed's newest 20, and still spares favourites and kept.

Keep both passes PHPMD-clean — if `pruneByAge()` grows past the complexity threshold, extract the candidate-feed query into its own named method rather than tuning the threshold.

- [ ] **Step 4: Run test to verify it passes**

Run: `cd backend && php bin/phpunit tests/Service/EntryPrunerTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add backend/src/Service/EntryPruner.php backend/tests/Service/EntryPrunerTest.php
git commit -m "feat(#384): purge by fetch date with a twenty-entry floor per feed"
```

---

### Task 6: Drop the dead index

`idx_entry_list (created_at, published_at, id)` served the #366 sort. Nothing orders on those columns now; `idx_entry_effective (effective_date, id)` serves the new sort and already exists.

**Files:**
- Create: `backend/migrations/Version20260814120000.php`
- Modify: `backend/src/Entity/Entry.php:16` (remove the `#[ORM\Index]` attribute)

**Interfaces:** none.

- [ ] **Step 1: Write the failing check**

There is no unit test for a dropped index; the gate is `doctrine:schema:validate`, which fails when the mapping and the database disagree. Remove the attribute first so the check has something to catch:

```bash
cd backend && bin/console doctrine:schema:validate
```
Expected: FAIL — the database has an index the mapping does not declare.

- [ ] **Step 2: Write the migration**

```php
public function getDescription(): string
{
    return 'Drop idx_entry_list; the entry list sorts on effective_date again (#384)';
}

public function up(Schema $schema): void
{
    $this->addSql('DROP INDEX idx_entry_list ON entry');
}

public function down(Schema $schema): void
{
    $this->addSql('CREATE INDEX idx_entry_list ON entry (created_at, published_at, id)');
}
```

MySQL and SQLite disagree on `DROP INDEX` syntax — SQLite takes `DROP INDEX idx_entry_list`, MySQL requires `ON entry`. Follow whatever the neighbouring migrations do for platform branching (`$this->connection->getDatabasePlatform()`); copy the pattern from `Version20260812120000.php`, which created this index.

- [ ] **Step 3: Verify on both platforms**

```bash
cd backend && bin/console doctrine:schema:validate
docker compose exec php bin/console doctrine:migrations:migrate --no-interaction
docker compose exec php bin/console doctrine:schema:validate
```
Expected: both validate clean. Remember that no test executes a migration (CLAUDE.md), so this manual run on **both** SQLite and MySQL is the only proof. Apply it to the running Docker database too, or php-fpm 500s on the stale schema.

- [ ] **Step 4: Commit**

```bash
git add backend/migrations/Version20260814120000.php backend/src/Entity/Entry.php
git commit -m "feat(#384): drop the index the run-first sort needed"
```

---

### Task 7: Gates, docs, and the live check the ticket owes

**Files:**
- Modify: `docs/architecture.md` if it documents the list sort — grep for `created_at` and `effective_date` and correct anything that now lies.
- Test: the whole suite, both legs.

- [ ] **Step 1: Full backend gates**

```bash
cd backend && composer check && composer md && php bin/phpunit
```
Expected: all clean. `composer stan` needs a warm dev cache — run `bin/console cache:warmup` first if it complains.

- [ ] **Step 2: The MySQL leg**

```bash
docker compose exec php vendor/bin/phpunit
```
Expected: green. A rate-limiter failure that passes in isolation is a known order-dependent flake, not a regression from this branch.

- [ ] **Step 3: Mutation gate on the changed files**

```bash
cd backend && composer infection:diff
```
Expected: at or above `minMsi`. Escaped mutants on the effective-date boundaries mean the boundary tests in Task 1 are too loose — tighten the test, never the threshold.

- [ ] **Step 4: PhpStorm inspections**

Run `mcp__phpstorm__lint_files` over every changed PHP file. Block on ERROR and WARNING; weak warnings are advisory.

- [ ] **Step 5: The live verification the ticket owes**

Issue #384 keeps bullet 2 ("sometimes articles arrive on a later refresh") as a **verification task, not a code task**: 406 of ~493 late-arriving old articles came from one unexplained burst on 2026-08-08, and nothing in the current code reproduces it.

```bash
docker compose up -d
```

Subscribe to an archive-heavy feed through the UI — `https://huggingface.co/blog/feed.xml` serves 841 items — then count what landed:

```bash
docker compose exec -T mysql sh -lc 'mysql -uroot -p"$MYSQL_ROOT_PASSWORD" feedreader -e "SELECT COUNT(*) FROM entry WHERE feed_id = (SELECT MAX(id) FROM feed)"'
```

Expected: exactly 200, the first-fetch bound from Task 3. Anything less means the first fetch really is short and there is a bug to chase — report the number in the issue either way. Do not write a fix for it in this branch.

- [ ] **Step 6: Scan the log**

```bash
tail -n 200 backend/var/log/dev.log
```
Expected: no new deprecations or swallowed errors from this work.

- [ ] **Step 7: Commit and open the PR**

```bash
git add -A && git commit -m "docs(#384): correct the list-sort description"
git push -u origin fix/384-effective-date-and-purge
gh pr create --base develop --title "fix(#384): sort on a clamped effective date, purge by fetch date" --body "Closes #384"
```

`develop` is the default branch, so `Closes #384` auto-closes the issue on merge. Verify it closed rather than closing it by hand.

---

## Self-Review

**Spec coverage.** Every decision in #384 maps to a task: single sort column → Task 4; the four effective-date rules → Task 1, wired in Task 2; feed-level first fetch → Task 2 (the existing `getLastFetchedAt() === null` guard in `FirstFetchRecorder` makes it feed-level by construction); the 200 bound → Task 3; no ingest age gate on refresh → nothing to do, recorded in Global Constraints so nobody adds one; age pass on `created_at`, 20-floor, cap on `created_at` → Task 5; policy plus context instead of tramp data → Tasks 1 and 2; two-part cursor and 422 → Task 4; drop `idx_entry_list` → Task 6; verification of bullet 2 → Task 7 Step 5. Explicit non-goals (backfill, display, tombstones, `last_seen_at`, archive crawling) are in Global Constraints so a task cannot quietly adopt one.

**Ordering.** Every task ends green. Task 2 is deliberately the largest: `Entry`'s constructor, `EntryIngestor`'s signature and both of its call sites are one atomic change, and splitting them would commit a red suite. Tasks 3 through 6 each touch one component and are independently reviewable.

**Type consistency.** `FeedIngestContext(fetchedAt, previousFetchAt)`, `EntryEffectiveDate::for(?\DateTimeImmutable, FeedIngestContext)`, `EntryIngestor::ingest(Feed, ParsedFeed, FeedIngestContext)`, `EntryCursor::encode(\DateTimeImmutable, int)` and the property `effectiveDate` are used identically in every task that mentions them. `Entry::__construct` gains exactly one parameter, in last position.

**Known gap.** Tasks 2 through 5 tell the implementer to read the existing test file and extend its helpers rather than quoting those helpers verbatim, because they differ per suite and quoting a wrong signature would be worse than naming the file. Every *new* assertion is written out in full.
