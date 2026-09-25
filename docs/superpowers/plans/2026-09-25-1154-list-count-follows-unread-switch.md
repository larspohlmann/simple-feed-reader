# List count follows the unread switch — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** On every list that offers the "All posts / Only unread" switch, the count pill in the header (and the count in the browser tab) shows the unread count when "Only unread" is on and the total post count when "All posts" is on.

**Architecture:** The backend sends a total next to every unread count it already sends — `entryCount` per subscription, `memberCount` per saved search, `forYou.totalCount` — as plain `COUNT`s. The frontend stores sum them exactly the way they sum the unread counts today (tags and All items from the feeds, the combined saved-searches view from the searches), and `ReaderShellComponent.titleCount` picks unread or total from `selection().unread`. Sidebar badges are unchanged: they stay unread.

**Tech Stack:** Symfony 7.4 / Doctrine DQL (PHP 8.4), Angular 20 signals, PHPUnit 12, Jest.

**Spec:** None — the design was agreed in chat (2026-09-25). The decisions it rests on are listed below.

## Agreed decisions (from chat)

- Scope: every list with the switch: All items, tag, feed, For you, a saved search, the combined saved searches. Search keeps its own result-count pill, untouched.
- **Simplest code wins; imprecision is accepted.** The totals are plain `COUNT`s with no duplicate collapse (`DuplicateCollapseDql`), so an article syndicated into two feeds counts in both. Tag and All-items totals are client-side sums of feed totals, just like their unread counts.
- **The combined saved-searches double count is NOT fixed.** The combined total is the sum of the per-search totals, the same way the combined unread count is the sum of the per-search unread counts.
- Totals are not updated optimistically: reading a post doesn't change them. They refresh with the loads/polls that already bring the unread counts.
- In "All posts" mode the pill is labelled as items (`TitleCount.counts = 'items'`, "N items"); in "Only unread" mode it stays `'unread'`.

## Global Constraints

- CLAUDE.md PHP rules: `final readonly` value objects, no boolean flag params, ≤3 params, no new comments unless they clear the bar, PHPMD-clean touched `src` files.
- `composer check`, `composer md`, `php bin/phpunit` (SQLite) and `docker compose exec php composer test` (MySQL) green; frontend `npm run check` green; frontend Jest inside the container: `docker compose exec -T frontend npm test -- <path>`.
- Never run two Jest processes in the container at once (OOM).
- Commit format `type(#1154): …`, where NN is the GitHub issue for this work.

## File map

| File | Change |
|---|---|
| `backend/src/Repository/SubscriptionRepository.php` | + `entryCountsForUser(int $userId): array<int,int>` |
| `backend/src/Http/SubscriptionJson.php` | `one()` takes `int $entryCount = 0`, emits `entryCount` |
| `backend/src/Http/SubscriptionCountsJson.php` | `from($unreadCounts, $entryCounts, $flags)`; rows `{id, unreadCount, entryCount}` |
| `backend/src/Controller/Api/SubscriptionController.php` | list + counts pass entry counts |
| `backend/src/Repository/SavedSearchEntryRepository.php` | + `memberCountsBySavedSearch(int $userId, array $savedSearchIds): array<int,int>` |
| `backend/src/Service/Search/SavedSearchTally.php` | **new** VO: `list<int> $unreadEntryIds`, `int $memberCount` |
| `backend/src/Service/Search/SavedSearchMatchIds.php` → `SavedSearchTallies.php` | renamed; `forAll()` returns `array<int, SavedSearchTally>`, `forOne()` returns `SavedSearchTally` |
| `backend/src/Http/SavedSearchJson.php` | `one(SavedSearch, SavedSearchTally)`, emits `memberCount` |
| `backend/src/Controller/Api/SavedSearchController.php` | uses `SavedSearchTallies` |
| `backend/src/Repository/RecommendationItemRepository.php` | + `countForYouIncludingRead(int $userId): int` |
| `backend/src/Service/Recommendation/RecommendationForYouSummary.php` | + `int $totalCount` (2nd param) |
| `backend/src/Service/Recommendation/RecommendationForYouSummaryProvider.php` | fills it |
| `backend/src/Http/RecommendationRunStatusJson.php` | emits `forYou.totalCount` |
| `frontend/src/app/reader/models.ts` | `SubscriptionDto.entryCount`, counts row `entryCount`, `SavedSearchWire/Dto.memberCount`, `forYou.totalCount` |
| `frontend/src/app/reader/subscriptions.store.ts` | `TagNode.entryCount`, `sumEntries`, `totalEntries`, counts-only patch carries `entryCount` |
| `frontend/src/app/reader/saved-searches.store.ts` | maps `memberCount` |
| `frontend/src/app/reader/recommendations.service.ts` | `forYouTotal` |
| `frontend/src/app/reader/reader-shell.component.ts` | `titleCount` follows the switch; `savedSearchesTotal` |
| spec fixtures (`testing/subscription.factory.ts`, inline literals) | new fields |

---

### Task 1: Subscription totals on the wire

**Files:**
- Modify: `backend/src/Repository/SubscriptionRepository.php`, `backend/src/Http/SubscriptionJson.php`, `backend/src/Http/SubscriptionCountsJson.php`, `backend/src/Controller/Api/SubscriptionController.php:51-81`
- Test: `backend/tests/Repository/SubscriptionEntryCountsTest.php` (new), `backend/tests/Http/SubscriptionCountsJsonTest.php`, `backend/tests/Controller/Api/SubscriptionControllerTest.php:124-190`, `backend/tests/Service/Tracing/TracedServiceMethodsTest.php`

**Interfaces:**
- Produces: `SubscriptionRepository::entryCountsForUser(int $userId): array<int, int>` (subscription id ⇒ entry count; subscriptions with no entries are absent). Wire: every subscription row has `entryCount: int`; `/api/subscriptions/counts` rows are `{id, unreadCount, entryCount}`, one per subscription that has entries.

- [ ] **Step 1: Write the failing repository test** — `backend/tests/Repository/SubscriptionEntryCountsTest.php`

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

final class SubscriptionEntryCountsTest extends DbTestCase
{
    public function testCountsEveryEntryPerSubscriptionReadOrNot(): void
    {
        $user = $this->user('reader@example.com');
        $feed = $this->feed('https://example.com/f.xml');
        $sub = new Subscription($user, $feed, new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $sub->setMarkedReadUntil(new \DateTimeImmutable('2026-07-10T00:00:00Z'));
        $this->em->persist($sub);
        $read = $this->entry($feed, 'a', '2026-07-20');
        $this->entry($feed, 'b', '2026-07-05');
        $this->entry($feed, 'c', '2026-07-21');
        $state = new EntryState($user, $read);
        $state->setIsHidden(true);
        $this->em->persist($state);
        $this->em->flush();

        $counts = $this->repo()->entryCountsForUser((int) $user->getId());

        self::assertSame([(int) $sub->getId() => 3], $counts);
    }

    public function testLeavesOutSubscriptionsWithoutEntriesAndOtherUsersFeeds(): void
    {
        $user = $this->user('reader@example.com');
        $stranger = $this->user('stranger@example.com');
        $empty = $this->feed('https://example.com/empty.xml');
        $theirs = $this->feed('https://example.com/theirs.xml');
        $this->em->persist(new Subscription($user, $empty, new \DateTimeImmutable('2026-07-01T00:00:00Z')));
        $this->em->persist(new Subscription($stranger, $theirs, new \DateTimeImmutable('2026-07-01T00:00:00Z')));
        $this->entry($theirs, 'x', '2026-07-20');
        $this->em->flush();

        self::assertSame([], $this->repo()->entryCountsForUser((int) $user->getId()));
    }

    private function repo(): SubscriptionRepository
    {
        $repo = $this->em->getRepository(Subscription::class);
        self::assertInstanceOf(SubscriptionRepository::class, $repo);

        return $repo;
    }

    private function user(string $email): User
    {
        $user = new User($email, new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $this->em->persist($user);

        return $user;
    }

    private function feed(string $url): Feed
    {
        $feed = new Feed($url);
        $this->em->persist($feed);

        return $feed;
    }

    private function entry(Feed $feed, string $guid, string $day): Entry
    {
        $publishedAt = new \DateTimeImmutable($day . 'T00:00:00Z');
        $entry = new Entry($feed, $guid, null, $guid, new \DateTimeImmutable('2026-07-01T00:00:00Z'), $publishedAt);
        $entry->setPublishedAt($publishedAt);
        $this->em->persist($entry);

        return $entry;
    }
}
```

- [ ] **Step 2: Run it, expect FAIL** (`Call to undefined method …entryCountsForUser`)

Run (from `backend/`): `php bin/phpunit --filter SubscriptionEntryCountsTest`

- [ ] **Step 3: Implement `entryCountsForUser`** in `SubscriptionRepository` (add `use App\Entity\Entry;`)

```php
    /**
     * @return array<int, int> subscription id => entries in its feed, read or not
     */
    #[WithSpan]
    public function entryCountsForUser(int $userId): array
    {
        /** @var list<array{subscriptionId: int, entryCount: int}> $rows */
        $rows = $this->createQueryBuilder('s')
            ->select('s.id AS subscriptionId', 'COUNT(e.id) AS entryCount')
            ->join(Entry::class, 'e', 'ON', 'e.feed = s.feed')
            ->andWhere('s.user = :user')
            ->groupBy('s.id')
            ->setParameter('user', $userId)
            ->getQuery()
            ->getScalarResult();

        $counts = [];
        foreach ($rows as $row) {
            $counts[(int) $row['subscriptionId']] = (int) $row['entryCount'];
        }

        return $counts;
    }
```

Add to `TracedServiceMethodsTest::…` provider, next to the `unreadCountsForUser` line:
`yield 'subscriptions list, entry counts' => [SubscriptionRepository::class, 'entryCountsForUser'];` (import `App\Repository\SubscriptionRepository` if not already there).

- [ ] **Step 4: Run, expect PASS** — `php bin/phpunit --filter 'SubscriptionEntryCountsTest|TracedServiceMethodsTest'`

- [ ] **Step 5: Update the JSON tests first (they fail)** — `SubscriptionCountsJsonTest`:

```php
    public function testMapsUnreadAndEntryCountsAndSurfaceTotals(): void
    {
        $payload = SubscriptionCountsJson::from(
            [12 => 3],
            [12 => 40, 8 => 5],
            ['favorites' => 8, 'kept' => 2, 'viewed' => 41],
        );

        self::assertSame([
            'subscriptions' => [
                ['id' => 12, 'unreadCount' => 3, 'entryCount' => 40],
                ['id' => 8, 'unreadCount' => 0, 'entryCount' => 5],
            ],
            'favoritesCount' => 8,
            'keptCount' => 2,
            'viewedCount' => 41,
        ], $payload);
    }

    public function testAnEmptyAccountMapsToEmptySubscriptionsAndZeroTotals(): void
    {
        $payload = SubscriptionCountsJson::from([], [], ['favorites' => 0, 'kept' => 0, 'viewed' => 0]);

        self::assertSame([], $payload['subscriptions']);
        self::assertSame(0, $payload['favoritesCount']);
    }
```

(Replaces `testMapsUnreadCountsAndSurfaceTotals`.) Rows are driven by `$entryCounts`: a subscription with unread entries always has entries, so no unread count is lost.

In `SubscriptionControllerTest::testCountsReturnsUnreadPerFeedAndSurfaceTotals` change the expectation to
`[['id' => $subscriptionId, 'unreadCount' => 2, 'entryCount' => 2]]`, and in the list test (around `:139-143`, where `$first['unreadCount']` is asserted) add `self::assertSame(2, $first['entryCount']);`.

- [ ] **Step 6: Run, expect FAIL** — `php bin/phpunit --filter 'SubscriptionCountsJsonTest|SubscriptionControllerTest'`

- [ ] **Step 7: Implement.** `SubscriptionCountsJson::from`:

```php
    /**
     * @param array<int, int>                               $unreadCounts subscription id => unread count
     * @param array<int, int>                               $entryCounts  subscription id => entry count
     * @param array{favorites: int, kept: int, viewed: int} $flags
     *
     * @return array{
     *   subscriptions: list<array{id: int, unreadCount: int, entryCount: int}>,
     *   favoritesCount: int, keptCount: int, viewedCount: int
     * }
     */
    public static function from(array $unreadCounts, array $entryCounts, array $flags): array
    {
        $subscriptions = [];
        foreach ($entryCounts as $id => $entryCount) {
            $subscriptions[] = ['id' => $id, 'unreadCount' => $unreadCounts[$id] ?? 0, 'entryCount' => $entryCount];
        }
        // … return unchanged
    }
```

Update the class docblock's "A subscription absent from `$unreadCounts`…" sentence to "A subscription absent from the list has no entries; …".

`SubscriptionJson::one(Subscription $sub, int $unreadCount = 0, int $entryCount = 0)`: add `'entryCount' => $entryCount,` right after `'unreadCount'`, and `entryCount: int` to the return shape.

`SubscriptionController::list`: `$entryCounts = $this->subscriptionRepo->entryCountsForUser((int) $user->getId());` and
`SubscriptionJson::one($s, $counts[(int) $s->getId()] ?? 0, $entryCounts[(int) $s->getId()] ?? 0)`.
`SubscriptionController::counts`: pass `$this->subscriptionRepo->entryCountsForUser((int) $user->getId())` as the new second argument.

- [ ] **Step 8: Run, expect PASS** — `php bin/phpunit --filter 'Subscription'`

- [ ] **Step 9: Commit**

```bash
git add backend/src/Repository/SubscriptionRepository.php backend/src/Http/SubscriptionJson.php backend/src/Http/SubscriptionCountsJson.php backend/src/Controller/Api/SubscriptionController.php backend/tests/Repository/SubscriptionEntryCountsTest.php backend/tests/Http/SubscriptionCountsJsonTest.php backend/tests/Controller/Api/SubscriptionControllerTest.php backend/tests/Service/Tracing/TracedServiceMethodsTest.php
git commit -m "feat(#1154): send each subscription's total entry count"
```

---

### Task 2: Saved-search totals on the wire

**Files:**
- Modify: `backend/src/Repository/SavedSearchEntryRepository.php`, `backend/src/Http/SavedSearchJson.php`, `backend/src/Controller/Api/SavedSearchController.php`
- Create: `backend/src/Service/Search/SavedSearchTally.php`
- Rename: `backend/src/Service/Search/SavedSearchMatchIds.php` → `SavedSearchTallies.php` (`git mv`)
- Test: `backend/tests/Repository/SavedSearchMembershipReadsTest.php`, `backend/tests/Http/SavedSearchJsonTest.php`, `backend/tests/Controller/Api/SavedSearchControllerTest.php:73,97`, `backend/tests/Service/Tracing/TracedServiceMethodsTest.php:23,53`

**Interfaces:**
- Produces: `SavedSearchEntryRepository::memberCountsBySavedSearch(int $userId, array $savedSearchIds): array<int,int>` (every requested id keeps its key, 0 when empty); `final readonly class SavedSearchTally { list<int> $unreadEntryIds; int $memberCount }`; `SavedSearchTallies::forAll(list<SavedSearch>, int): array<int, SavedSearchTally>`, `forOne(SavedSearch, int): SavedSearchTally`. Wire: each saved search gains `memberCount: int`.

- [ ] **Step 1: Failing repository test** — add to `SavedSearchMembershipReadsTest` (uses its existing `search()`, `entry()`, `member()`, `hide()` helpers and `$this->stranger`):

```php
    public function testMemberCountsCountReadAndUnreadMembersWithEveryRequestedKeyPresent(): void
    {
        $climate = $this->search('climate');
        $rocket = $this->search('rocket');
        $empty = $this->search('zebra');
        $unread = $this->entry('a', '2026-07-10T00:00:00Z');
        $read = $this->entry('b', '2026-07-09T00:00:00Z');
        $this->member($climate, $unread);
        $this->member($climate, $read);
        $this->member($rocket, $unread);
        $this->hide($read);

        $counts = $this->repo()->memberCountsBySavedSearch(
            (int) $this->user->getId(),
            [(int) $climate->getId(), (int) $rocket->getId(), (int) $empty->getId()],
        );

        self::assertSame([
            (int) $climate->getId() => 2,
            (int) $rocket->getId() => 1,
            (int) $empty->getId() => 0,
        ], $counts);
    }

    public function testMemberCountsIgnoreAnotherUsersSearches(): void
    {
        $theirs = $this->search('climate', $this->stranger);
        $this->member($theirs, $this->entry('a', '2026-07-10T00:00:00Z'));

        $counts = $this->repo()->memberCountsBySavedSearch((int) $this->user->getId(), [(int) $theirs->getId()]);

        self::assertSame([(int) $theirs->getId() => 0], $counts);
    }
```

If the existing `search()` helper takes no owner argument, check how the file's other stranger tests build a foreign search and use that instead; don't add a new helper.

- [ ] **Step 2: Run, expect FAIL** — `php bin/phpunit --filter SavedSearchMembershipReadsTest`

- [ ] **Step 3: Implement** in `SavedSearchEntryRepository` (add `use App\Entity\Subscription;`):

```php
    /**
     * Saved-search id => how many members, read or not, the caller may see.
     * Every requested id keeps its key.
     *
     * @param list<int> $savedSearchIds
     *
     * @return array<int, int>
     */
    public function memberCountsBySavedSearch(int $userId, array $savedSearchIds): array
    {
        $countsBySearch = array_fill_keys($savedSearchIds, 0);
        if ($savedSearchIds === []) {
            return $countsBySearch;
        }

        /** @var list<array{searchId: int, memberCount: int}> $rows */
        $rows = $this->getEntityManager()->createQueryBuilder()
            ->select('ss.id AS searchId', 'COUNT(e.id) AS memberCount')
            ->from(SavedSearchEntry::class, 'sse')
            ->join('sse.savedSearch', 'ss')
            ->join('sse.entry', 'e')
            ->join(Subscription::class, 's', 'ON', 's.feed = e.feed AND s.user = ss.user')
            ->andWhere('ss.id IN (:searchIds)')
            ->andWhere('ss.user = :user')
            ->groupBy('ss.id')
            ->setParameter('searchIds', $savedSearchIds)
            ->setParameter('user', $userId)
            ->getQuery()
            ->getScalarResult();
        foreach ($rows as $row) {
            $countsBySearch[(int) $row['searchId']] = (int) $row['memberCount'];
        }

        return $countsBySearch;
    }
```

- [ ] **Step 4: Run, expect PASS** — `php bin/phpunit --filter SavedSearchMembershipReadsTest`

- [ ] **Step 5: Failing JSON/controller tests.** `SavedSearchJsonTest`: every `SavedSearchJson::one($search, [7, 8])` becomes `SavedSearchJson::one($search, new SavedSearchTally([7, 8], 5))` (`[]` → `new SavedSearchTally([], 0)`), and `testOneEmitsIncludeInDigest` also asserts `self::assertSame(5, $json['memberCount']);`. `SavedSearchControllerTest`: next to both `unreadEntryIds` asserts at `:73` and `:97` add `self::assertSame(1, $created['savedSearch']['memberCount']);` / `self::assertSame(1, $list['savedSearches'][0]['memberCount']);`. `TracedServiceMethodsTest`: import `SavedSearchTallies` instead of `SavedSearchMatchIds`; the yield becomes `[SavedSearchTallies::class, 'forAll']`.

- [ ] **Step 6: Run, expect FAIL** — `php bin/phpunit --filter 'SavedSearchJsonTest|SavedSearchControllerTest|TracedServiceMethodsTest'`

- [ ] **Step 7: Implement.** `backend/src/Service/Search/SavedSearchTally.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Search;

final readonly class SavedSearchTally
{
    /**
     * @param list<int> $unreadEntryIds
     */
    public function __construct(
        public array $unreadEntryIds,
        public int $memberCount,
    ) {
    }
}
```

`git mv backend/src/Service/Search/SavedSearchMatchIds.php backend/src/Service/Search/SavedSearchTallies.php`, then:

```php
/**
 * What each saved search's badge and heading count: the unread member ids,
 * which the client drops one by one as they are read, and the member total.
 * Read from the membership table (#1116), every search in one query each.
 */
final readonly class SavedSearchTallies
{
    public function __construct(private SavedSearchEntryRepository $entries)
    {
    }

    /**
     * @param list<SavedSearch> $savedSearches
     *
     * @return array<int, SavedSearchTally> saved-search id => its tally
     */
    #[WithSpan]
    public function forAll(array $savedSearches, int $userId): array
    {
        $ids = array_map(static fn (SavedSearch $s): int => (int) $s->getId(), $savedSearches);
        $unreadIds = $this->entries->unreadMemberIdsBySavedSearch($userId, $ids);
        $memberCounts = $this->entries->memberCountsBySavedSearch($userId, $ids);

        return array_combine($ids, array_map(
            static fn (int $id): SavedSearchTally => new SavedSearchTally($unreadIds[$id], $memberCounts[$id]),
            $ids,
        ));
    }

    public function forOne(SavedSearch $savedSearch, int $userId): SavedSearchTally
    {
        return $this->forAll([$savedSearch], $userId)[(int) $savedSearch->getId()];
    }
}
```

`SavedSearchJson::one(SavedSearch $savedSearch, SavedSearchTally $tally)`: `'unreadEntryIds' => $tally->unreadEntryIds,` plus `'memberCount' => $tally->memberCount,` right after it, and the return shape gets `memberCount: int` (drop the `@param list<int> $unreadEntryIds`).

`SavedSearchController`: inject `SavedSearchTallies $tallies` in place of `SavedSearchMatchIds $matches`; in `list`, `$tallies = $this->tallies->forAll($rows, $userId);` and `SavedSearchJson::one($s, $tallies[(int) $s->getId()])`; `create`/`update` use `$this->tallies->forOne($savedSearch, $userId)`.

Update the two comments in `SavedSearchControllerTest` (`:122`, `:165`) that name `SavedSearchMatchIds` to `SavedSearchTallies`. The plan/spec docs under `docs/superpowers/` are history — leave them.

- [ ] **Step 8: Run, expect PASS** — `php bin/phpunit --filter 'SavedSearch|TracedServiceMethodsTest'`

- [ ] **Step 9: Commit**

```bash
git add -A backend/src/Service/Search backend/src/Repository/SavedSearchEntryRepository.php backend/src/Http/SavedSearchJson.php backend/src/Controller/Api/SavedSearchController.php backend/tests
git commit -m "feat(#1154): send each saved search's member count"
```

---

### Task 3: For-you total on the wire

**Files:**
- Modify: `backend/src/Repository/RecommendationItemRepository.php:106-118`, `backend/src/Service/Recommendation/RecommendationForYouSummary.php`, `…/RecommendationForYouSummaryProvider.php:29`, `backend/src/Http/RecommendationRunStatusJson.php:29-35`
- Test: `backend/tests/Repository/RecommendationFeedTest.php`, `backend/tests/Service/Recommendation/RecommendationForYouSummaryProviderTest.php`, `backend/tests/Http/RecommendationRunStatusJsonTest.php:99,120`, `backend/tests/Controller/Api/RecommendationRunControllerTest.php:247,368,806`

**Interfaces:**
- Produces: `RecommendationItemRepository::countForYouIncludingRead(int $userId): int`; `RecommendationForYouSummary(int $itemCount, int $totalCount, ?\DateTimeImmutable $generatedAt, ?int $newestRunId = null)`; wire `forYou: {itemCount, totalCount, generatedAt, newestRunId}`.

- [ ] **Step 1: Failing repository test** — in `RecommendationFeedTest`, beside `testCountForYouCountsUnreadPicksOnly` (reuse its fixture shape: `entry()`, `seedRun()`, `item()`, and however that test marks an entry read):

```php
    public function testCountForYouIncludingReadCountsReadPicksToo(): void
    {
        $unread = $this->entry('unread');
        $read = $this->entry('read');
        $run = $this->seedRun($this->user, RecommendationRun::STATUS_COMPLETED);
        $this->item($run, $unread, 1, 'reason unread');
        $this->item($run, $read, 2, 'reason read');
        $state = new EntryState($this->user, $read);
        $state->setIsHidden(true);
        $this->em->persist($state);
        $this->em->flush();

        self::assertSame(1, $this->repo()->countForYou((int) $this->user->getId()));
        self::assertSame(2, $this->repo()->countForYouIncludingRead((int) $this->user->getId()));
    }
```

- [ ] **Step 2: Run, expect FAIL** — `php bin/phpunit --filter RecommendationFeedTest`

- [ ] **Step 3: Implement**, below `countForYou`:

```php
    public function countForYouIncludingRead(int $userId): int
    {
        $qb = $this->applyForYouCriteria($this->createQueryBuilder('i')->select('COUNT(i.id)'), $userId);

        return (int) $qb->getQuery()->getSingleScalarResult();
    }
```

- [ ] **Step 4: Run, expect PASS.**

- [ ] **Step 5: Failing summary/JSON tests.** `RecommendationForYouSummaryProviderTest::testCountsDedupedItems…` adds `self::assertSame(2, $summary->totalCount);` and the empty case `self::assertSame(0, $summary->totalCount);`. `RecommendationRunStatusJsonTest`: `new RecommendationForYouSummary(4, 9, new \DateTimeImmutable('2026-08-09T10:00:00Z'), 42)` with the expected `forYou` array gaining `'totalCount' => 9` right after `itemCount`; the empty helper becomes `new RecommendationForYouSummary(0, 0, null, null)`. `RecommendationRunControllerTest` lines 247, 368, 806: `['itemCount' => 0, 'totalCount' => 0, 'generatedAt' => null, 'newestRunId' => null]`.

- [ ] **Step 6: Run, expect FAIL** — `php bin/phpunit --filter 'RecommendationForYouSummaryProviderTest|RecommendationRunStatusJsonTest|RecommendationRunControllerTest'`

- [ ] **Step 7: Implement.** `RecommendationForYouSummary` gets `public int $totalCount,` as the 2nd promoted property (below `$itemCount` and its comment). The provider passes `$this->items->countForYouIncludingRead((int) $user->getId())` 2nd. `RecommendationRunStatusJson::report` adds `'totalCount' => $summary->totalCount,` after `itemCount`. `grep -rn "new RecommendationForYouSummary(" backend` must show no call left with the old arity.

- [ ] **Step 8: Run, expect PASS** — `php bin/phpunit --filter Recommendation`

- [ ] **Step 9: Commit**

```bash
git add backend/src/Repository/RecommendationItemRepository.php backend/src/Service/Recommendation backend/src/Http/RecommendationRunStatusJson.php backend/tests
git commit -m "feat(#1154): send the for-you list's total pick count"
```

---

### Task 4: Frontend models and stores carry the totals

**Files:**
- Modify: `frontend/src/app/reader/models.ts` (`SubscriptionDto` ~`:108`, `SubscriptionCountsResponse` `:142`, `SavedSearchDto` `:22`, `SavedSearchWire` `:43`, `RecommendationRunReport.forYou` `:473`), `subscriptions.store.ts`, `saved-searches.store.ts`, `recommendations.service.ts:215`
- Test: `subscriptions.store.spec.ts`, `saved-searches.store.spec.ts`, `recommendations.service.spec.ts`; fixtures (`testing/subscription.factory.ts` and inline literals)

**Interfaces:**
- Consumes: the wire fields from Tasks 1–3.
- Produces: `SubscriptionDto.entryCount: number`; `TagNode.entryCount: number`; `sumEntries(subs): number`; `SubscriptionsStore.totalEntries: Signal<number>`; `SavedSearchDto.memberCount: number`; `RecommendationsService.forYouTotal: Signal<number>`.

- [ ] **Step 1: Failing store specs.** In `subscriptions.store.spec.ts`, next to the tag-sum specs at `:50`/`:73`, and next to the counts-only specs at `:532-605` (reuse the file's own builders and flush helpers):

```ts
  it("sums a tag's entry counts from its feeds, like its unread count", () => {
    const tree = buildTagTree(
      [
        subscription({ id: 1, entryCount: 30, tags: [tagRef(7)] }),
        subscription({ id: 2, entryCount: 12, tags: [tagRef(7)] }),
      ],
      [tag(7)],
    );
    expect(tree[0].entryCount).toBe(42);
  });

  it('totals entries over the feeds included in All items only', () => {
    expect(
      sumEntries([
        subscription({ entryCount: 30, includeInAllItems: true }),
        subscription({ entryCount: 12, includeInAllItems: false }),
      ]),
    ).toBe(30);
  });

  it('patches entry counts from the counts-only tick', () => {
    // load a list with subscription id 1 (entryCount 3), then flush
    // /api/subscriptions/counts with { subscriptions: [{ id: 1, unreadCount: 1, entryCount: 5 }], … }
    expect(store.subscriptions()[0].entryCount).toBe(5);
  });
```

Write the helper calls (`subscription`, `tagRef`, `tag`, flush) with whatever the spec file already uses for the neighbouring tests; the assertions are what matter.

`saved-searches.store.spec.ts`: a wire with `memberCount: 9` maps to `savedSearches()[0].memberCount === 9`.
`recommendations.service.spec.ts`: after a report with `forYou: { itemCount: 2, totalCount: 7, … }`, `forYouTotal()` is `7`, and `0` with no report.

- [ ] **Step 2: Run, expect FAIL** — `docker compose exec -T frontend npm test -- src/app/reader/subscriptions.store.spec.ts src/app/reader/saved-searches.store.spec.ts src/app/reader/recommendations.service.spec.ts`

- [ ] **Step 3: Implement.**
  - `models.ts`: `SubscriptionDto` gains `entryCount: number;` beside `unreadCount`; `SubscriptionCountsResponse.subscriptions` is `{ id: number; unreadCount: number; entryCount: number }[]` and its doc says "A feed absent from `subscriptions` has no entries."; `SavedSearchWire` and `SavedSearchDto` gain `memberCount: number;`; `forYou` becomes `{ itemCount: number; totalCount: number; generatedAt: string | null; newestRunId: number | null }`.
  - `subscriptions.store.ts`: `TagNode` gains `entryCount: number`; `buildTagTree` sets `entryCount: feeds.reduce((n, s) => n + s.entryCount, 0)`; add below `sumUnread`:

    ```ts
    export function sumEntries(subs: SubscriptionDto[]): number {
      return subs.reduce((n, s) => (s.includeInAllItems ? n + s.entryCount : n), 0);
    }
    ```

    `readonly totalEntries = computed(() => sumEntries(this.subscriptions()));` beside `totalUnread`. `applyCountsOnly` patches both counts:

    ```ts
    const countsById = new Map(response.subscriptions.map((s) => [s.id, s]));
    let moved = false;
    const next = this.subscriptions().map((sub) => {
      const unreadCount = countsById.get(sub.id)?.unreadCount ?? 0;
      const entryCount = countsById.get(sub.id)?.entryCount ?? 0;
      if (unreadCount === sub.unreadCount && entryCount === sub.entryCount) return sub;
      moved = true;
      return { ...sub, unreadCount, entryCount };
    });
    ```

    Update its doc comment "Patch unread counts…" to "Patch unread and entry counts…".
  - `saved-searches.store.ts`: `memberCount: wire.memberCount,` in the `savedSearches` mapping.
  - `recommendations.service.ts`: beside `forYouCount`: `readonly forYouTotal = computed(() => this.report()?.forYou.totalCount ?? 0);`

- [ ] **Step 4: Fix fixtures.** Add `entryCount: 0` to `testing/subscription.factory.ts`, then find every other literal the new required fields break:
  `docker compose exec -T frontend npx tsc -p tsconfig.spec.json --noEmit`
  Add `entryCount`, `memberCount: 0` / `totalCount: 0` wherever it reports a missing property (in `src/` and `e2e/` stubs; the `forYou: { itemCount: … }` literals are the bulk). Pick values other than 0 only where a spec asserts on a count.

- [ ] **Step 5: Run, expect PASS** — the three specs from Step 2, then the typecheck again (clean).

- [ ] **Step 6: Commit**

```bash
git add frontend/src frontend/e2e
git commit -m "feat(#1154): carry total counts in the reader stores"
```

---

### Task 5: The heading and tab count follow the switch

**Files:**
- Modify: `frontend/src/app/reader/reader-shell.component.ts:513-543` (`titleCount`), `:1151` (beside `savedSearchesUnread`), `:1338-1350` (helpers)
- Test: `frontend/src/app/reader/reader-shell.component.spec.ts` (`titleCount` specs at `:1429-1496`, `:3150-3242`, `:3694-3723`; saved-search count `:726`, `:764`; for-you `:3018`, `:3050`)

**Interfaces:**
- Consumes: `subs.totalEntries`, `TagNode.entryCount`, `SubscriptionDto.entryCount`, `recs.forYouTotal`, `SavedSearchDto.memberCount`.

- [ ] **Step 1: Pin the switch in the existing specs.** The switch is off ("All posts") unless `sfr.user.1.unread-only` is `'1'`, so every existing spec that expects an `unread` count now needs "Only unread": add `localStorage.setItem('sfr.user.1.unread-only', '1');` before `boot()`/`bootWithSavedSearches()` in each `titleCount`/tab-title spec that asserts `counts: 'unread'` or an unread number in the tab (e.g. "names the browser tab after the list on screen", "…the selected feed and its unread count", "hands the list heading the same count the tab shows", "titles the for-you count as unread", the saved-search count specs). Follow the precedent at `:3206`. Run the spec: these pass unchanged against today's code, and pin the unread path.

- [ ] **Step 2: Failing "All posts" specs**, in the same `describe` as the existing heading-count specs (`subsBody` rows need `entryCount` values: give the feed with `unreadCount: 2` an `entryCount: 9`, and use the neighbouring specs' boot/flush sequence):

```ts
  it('counts every post in the heading and tab when All posts is on', () => {
    const f = boot();
    f.detectChanges();

    expect(f.componentInstance.titleCount()).toEqual({ value: 9, counts: 'items' });
    expect(TestBed.inject(Title).getTitle()).toBe('All items (9) | simple feed reader');
  });

  it("counts every post of a feed when All posts is on", () => {
    const f = boot();
    qp.next(convertToParamMap({ subscription: '5' }));
    f.detectChanges();
    ctrl
      .expectOne((r) => r.url === 'https://api.test/api/entries')
      .flush({ entries: [], nextCursor: null });
    f.detectChanges();

    expect(f.componentInstance.titleCount()).toEqual({ value: 9, counts: 'items' });
  });

  it('switches the count when the unread switch flips', () => {
    const f = boot();
    TestBed.inject(UnreadFilterService).set(true);
    f.detectChanges();

    expect(f.componentInstance.titleCount()).toEqual({ value: 2, counts: 'unread' });
  });
```

Plus one each for a tag, For you (report with `itemCount: 7, totalCount: 20` → `{ value: 20, counts: 'items' }`), a single saved search (`memberCount: 6` → 6), and the combined view (two searches with `memberCount: 4` and `5` → `{ value: 9, counts: 'items' }` — a plain sum, as agreed). Flush any request the flip triggers, the way the spec at `:3414` does.

- [ ] **Step 3: Run, expect FAIL** — `docker compose exec -T frontend npm test -- src/app/reader/reader-shell.component.spec.ts`

- [ ] **Step 4: Implement.** `titleCount`:

```ts
  readonly titleCount = computed<TitleCount>(() => {
    const s = this.selection();
    switch (s.kind) {
      case 'all':
        return bySwitch(s, this.subs.totalUnread(), this.subs.totalEntries());
      case 'tag': {
        const node = this.subs.tagTree().find((n) => n.tag.id === s.id);
        return bySwitch(s, node?.unreadCount ?? 0, node?.entryCount ?? 0);
      }
      case 'subscription': {
        const sub = this.subs.subscriptions().find((x) => x.id === s.id);
        return bySwitch(s, sub?.unreadCount ?? 0, sub?.entryCount ?? 0);
      }
      case 'favorites':
        return items(this.subs.favoritesCount());
      case 'kept':
        return items(this.subs.keptCount());
      case 'viewed':
        return items(this.subs.viewedCount());
      case 'for-you':
        return bySwitch(s, this.recs.forYouCount(), this.recs.forYouTotal());
      case 'saved-searches':
        return bySwitch(s, this.savedSearchesUnread(), this.savedSearchesTotal());
      case 'saved-search': {
        const saved = this.activeSavedSearch();
        return bySwitch(s, saved?.unreadCount ?? 0, saved?.memberCount ?? 0);
      }
      case 'search':
        return items(0);
    }
  });
```

Beside `savedSearchesUnread`:

```ts
  readonly savedSearchesTotal = computed(() =>
    this.savedSearchesStore.savedSearches().reduce((sum, saved) => sum + saved.memberCount, 0),
  );
```

Beside `unread()`/`items()`:

```ts
/** The unread count under "Only unread", every post under "All posts". */
function bySwitch(selection: Selection, unreadCount: number, allCount: number): TitleCount {
  return selection.unread ? unread(unreadCount) : items(allCount);
}
```

Update `titleCount`'s doc comment: it is no longer "the same number the sidebar row shows" in every case. Say it shows the sidebar's unread number under "Only unread" and the list's total under "All posts" (#709, #1154). Update `items()`'s doc ("what the sidebar counts for the saved views…") to also cover "and every list's total under All posts".

- [ ] **Step 5: Run, expect PASS** — the shell spec, then `docker compose exec -T frontend npm test -- src/app/reader` (one Jest run at a time).

- [ ] **Step 6: Commit**

```bash
git add frontend/src/app/reader/reader-shell.component.ts frontend/src/app/reader/reader-shell.component.spec.ts
git commit -m "fix(#1154): make the list count follow the unread switch"
```

---

### Task 6: Verification

- [ ] Backend gates from `backend/`: `composer check`, `composer md`, `php bin/phpunit`, `docker compose exec php composer test`. `composer infection:diff` over the branch (commit first — untracked files are ignored).
- [ ] Frontend gate: `docker compose exec -T frontend npm run check`.
- [ ] PhpStorm inspections on changed PHP (`mcp__phpstorm__lint_files`): no ERROR/WARNING.
- [ ] Real render: make sure the php and frontend containers serve this code (standing rule), then in the built-in browser (Mobile viewport) open All items, a tag, a feed, For you, one saved search and the combined saved searches, and flip the switch on each: the pill and the tab show the total under "All posts" and the unread count under "Only unread". Read one post under "All posts": the total doesn't move.
- [ ] Scan today's dev log: `ls -t backend/var/log/dev-*.log | head -1 | xargs tail -n 100 | jq -c 'select(.level_name != "DEBUG" and .level_name != "INFO")'`.
