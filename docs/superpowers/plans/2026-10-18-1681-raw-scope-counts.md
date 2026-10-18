# Raw Scope Counts Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Unread counts ignore cross-feed duplicates, and each count is built from the same scope rules the entry lists use.

**Architecture:** `EntryScopePredicates` exposes its scope rules as reusable pieces (join the tags, join the network feeds with a feed-kind restriction, keep All-items feeds, unread). `EntryAliases` names each scope's key expression. A list binds the key to a value (`s.id = :sid`); a count groups by it. `UnreadCountRepository` keeps no scope rule of its own and no duplicate collapse; `DuplicateCollapseDql::applyToCount()` is deleted.

**Tech Stack:** Symfony 7.4, PHP 8.4, Doctrine ORM QueryBuilder (DQL), PHPUnit 12.

**Spec:** GitHub issue #1681 (`gh issue view 1681`), which builds on #1679.

## Global Constraints

- Counts ignore duplicates: a post in two feeds counts once in every scope that holds a copy.
- The wire shape is unchanged: `unreadTotals` and the per-subscription `unreadCount` keep their keys and types.
- `DuplicateCollapseDql::apply()` (the lists) stays byte-for-byte unchanged; only `applyToCount()` goes.
- `UnreadCountRepository` holds no hand-written scope rule (no subscription-tag join, no `includeInAllItems`, no `network =`, no profile restriction): it calls the shared pieces.
- Commit format `type(#1681): lower-case summary`; no Claude attribution anywhere.
- Every `src` file touched is PHPMD-clean (`composer md`); `composer check` (cs + stan + tramp) passes.
- Clean Code per CLAUDE.md: no flag parameters, no abbreviations, comments only where a reader would otherwise get it wrong.
- Run backend tests from `backend/`: `php bin/phpunit <path>` (SQLite). The MySQL leg runs `docker compose exec -T php php bin/phpunit <path>` from the repo root.

---

### Task 1: Expose the list scope rules as reusable pieces

A pure refactor: lists behave exactly as before, and the existing list tests are the safety net.

**Files:**
- Modify: `backend/src/Repository/EntryAliases.php`
- Modify: `backend/src/Repository/NetworkScopeDql.php`
- Modify: `backend/src/Repository/EntryScopePredicates.php`
- Test: `backend/tests/Repository/EntryAliasesTest.php` (create)

**Interfaces:**
- Produces (Task 2 relies on these exact signatures):
  - `EntryAliases::subscriptionKey(): string` → `'s.id'` for `primary()`
  - `EntryAliases::tagKey(): string` → `'IDENTITY(st.tag)'` for `primary()`
  - `EntryAliases::networkKey(): string` → `'nf.network'` for `primary()`
  - `EntryScopePredicates::joinTags(QueryBuilder $qb, EntryAliases $aliases): void`
  - `EntryScopePredicates::joinNetworkFeeds(QueryBuilder $qb, EntryAliases $aliases, ?NetworkFeedKind $feedKind): void`
  - `EntryScopePredicates::inAllItems(QueryBuilder $qb, EntryAliases $aliases): void`
  - `EntryScopePredicates::unread(QueryBuilder $qb, EntryAliases $aliases): void` (was private)
  - `NetworkScopeDql::restrictToNetwork(QueryBuilder $qb, string $feedAlias, SocialNetwork $network): void`
  - `NetworkScopeDql::restrictToKind(QueryBuilder $qb, string $feedAlias, ?NetworkFeedKind $feedKind): void`

- [ ] **Step 1: Write the failing test** — `backend/tests/Repository/EntryAliasesTest.php`

```php
<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Repository\EntryAliases;
use PHPUnit\Framework\TestCase;

final class EntryAliasesTest extends TestCase
{
    public function testScopeKeysReadTheirOwnAliases(): void
    {
        $primary = EntryAliases::primary();
        $collapse = EntryAliases::collapse();

        self::assertSame('s.id', $primary->subscriptionKey());
        self::assertSame('IDENTITY(st.tag)', $primary->tagKey());
        self::assertSame('nf.network', $primary->networkKey());
        self::assertSame('s2.id', $collapse->subscriptionKey());
        self::assertSame('IDENTITY(st2.tag)', $collapse->tagKey());
        self::assertSame('nf2.network', $collapse->networkKey());
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `cd backend && php bin/phpunit tests/Repository/EntryAliasesTest.php`
Expected: FAIL, `Call to undefined method App\Repository\EntryAliases::subscriptionKey()`.

- [ ] **Step 3: Add the key methods to `EntryAliases`** (after `collapse()`), and update the class docblock to:
`/** The DQL aliases a scope rule reads, so one rule serves the list, its duplicate-collapse join and the unread counts. */`

```php
    public function subscriptionKey(): string
    {
        return $this->subscription . '.id';
    }

    public function tagKey(): string
    {
        return \sprintf('IDENTITY(%s.tag)', $this->tag);
    }

    public function networkKey(): string
    {
        return $this->networkFeed . '.network';
    }
```

- [ ] **Step 4: Split `NetworkScopeDql::restrict`** into its two rules (add `use App\Enum\SocialNetwork;`):

```php
    public static function restrict(QueryBuilder $qb, string $feedAlias, NetworkScope $scope): void
    {
        self::restrictToNetwork($qb, $feedAlias, $scope->network);
        self::restrictToKind($qb, $feedAlias, $scope->feedKind);
    }

    public static function restrictToNetwork(QueryBuilder $qb, string $feedAlias, SocialNetwork $network): void
    {
        $qb->andWhere(\sprintf('%s.network = :network', $feedAlias))->setParameter('network', $network);
    }

    public static function restrictToKind(QueryBuilder $qb, string $feedAlias, ?NetworkFeedKind $feedKind): void
    {
        if ($feedKind === NetworkFeedKind::Profile) {
            PublicOrAuthorFeedDql::restrict($qb, $feedAlias, SourceFormat::NOT_PROFILES);
        }
    }
```

- [ ] **Step 5: Rebuild `EntryScopePredicates::applyList` on public pieces.** Replace `applyList` and `onNetwork`, make `unread` public, add `use App\Enum\NetworkFeedKind;`, and change the class docblock's second line to "…so the list, its duplicate-collapse semi-join and the unread counts share one definition."

```php
    public function applyList(QueryBuilder $qb, EntryAliases $aliases, EntryQuery $query): void
    {
        if ($query->subscriptionId !== null) {
            $qb->andWhere($aliases->subscriptionKey() . ' = :sid')->setParameter('sid', $query->subscriptionId);
        }
        if ($query->tagId !== null) {
            $this->joinTags($qb, $aliases);
            $qb->andWhere($aliases->tagKey() . ' = :tagId')->setParameter('tagId', $query->tagId);
        }
        if ($query->networkScope !== null) {
            $this->joinNetworkFeeds($qb, $aliases, $query->networkScope->feedKind);
            NetworkScopeDql::restrictToNetwork($qb, $aliases->networkFeed, $query->networkScope->network);
        }
        if ($query->hidesExcludedFeeds()) {
            $this->inAllItems($qb, $aliases);
        }
        $this->applyView($qb, $aliases, $query->view);
    }

    /** One row per tag a subscription carries: filter it to one tag, or group by the tag. */
    public function joinTags(QueryBuilder $qb, EntryAliases $aliases): void
    {
        $qb->innerJoin(\sprintf('%s.subscriptionTags', $aliases->subscription), $aliases->tag);
    }

    public function joinNetworkFeeds(QueryBuilder $qb, EntryAliases $aliases, ?NetworkFeedKind $feedKind): void
    {
        $qb->innerJoin(\sprintf('%s.feed', $aliases->entry), $aliases->networkFeed);
        NetworkScopeDql::restrictToKind($qb, $aliases->networkFeed, $feedKind);
    }

    public function inAllItems(QueryBuilder $qb, EntryAliases $aliases): void
    {
        $qb->andWhere(\sprintf('%s.includeInAllItems = true', $aliases->subscription));
    }
```

`unread()` keeps its body; only `private` becomes `public`. The old two-line comment on the tag join goes (the docblock on `joinTags` replaces it). Delete `onNetwork()`.

- [ ] **Step 6: Run the tests that cover the lists and the network scope**

Run: `cd backend && php bin/phpunit tests/Repository/EntryAliasesTest.php tests/Repository/EntryListTest.php tests/Repository/DuplicateCollapseTest.php tests/Repository/NetworkScopeTest.php tests/Repository/FeedRepositoryNetworkScopeTest.php tests/Repository/FeedRepositoryTagScopeTest.php tests/Repository/SubscriptionScopeRepositoryTest.php tests/Controller/Api/EntryControllerTest.php`
Expected: all PASS.

- [ ] **Step 7: Gates on the touched files**

Run: `cd backend && composer cs && composer stan && composer md`
Expected: clean.

- [ ] **Step 8: Commit**

```bash
git add backend/src/Repository/EntryAliases.php backend/src/Repository/NetworkScopeDql.php backend/src/Repository/EntryScopePredicates.php backend/tests/Repository/EntryAliasesTest.php
git commit -m "refactor(#1681): the list scope rules become reusable pieces with named keys"
```

---

### Task 2: Unread counts group by the shared scope keys, without the duplicate collapse

**Files:**
- Modify: `backend/src/Repository/UnreadCountRepository.php` (rewrite)
- Modify: `backend/src/Repository/DuplicateCollapseDql.php` (delete `applyToCount()` and its docblock)
- Modify: `backend/tests/Repository/UnreadCountRepositoryTest.php`

**Interfaces:**
- Consumes: everything Task 1 produces (see its Interfaces block).
- Produces: `UnreadCountRepository` keeps its public signatures exactly: `perSubscription(int): array<int,int>`, `perTag(int): array<int,int>`, `perNetwork(int): array<string,int>`, `perNetworkProfiles(int): array<string,int>`, `allItems(int): int`. Its constructor now takes `(EntityManagerInterface $entityManager, EntryScopePredicates $scope)`; it is autowired, so no config change.

- [ ] **Step 1: Flip the collapse tests to raw expectations and add the two new cases** in `UnreadCountRepositoryTest.php`:

  - Rename `testUnreadCopySurvivesWhenTheLowerIdDuplicateIsAlreadyRead` → `testReadingOneCopyLeavesItsSiblingUnread`; body unchanged (B = 1, A = 0).
  - Replace `testAllItemsCountsACrossFeedDuplicateOnce` with:

```php
    public function testAllItemsCountsEveryCopyOfACrossFeedDuplicate(): void
    {
        $user = $this->user();
        [, $feedA] = $this->subscribedFeed($user, 'https://example.com/a.xml');
        [, $feedB] = $this->subscribedFeed($user, 'https://example.com/b.xml');
        $this->unreadEntry($feedA, 'a', 'shared-hash');
        $this->unreadEntry($feedB, 'b', 'shared-hash');
        $this->unreadEntry($feedB, 'unique', null);

        self::assertSame(3, $this->repository()->allItems($user->requireId()));
    }
```

  - Rename `testAllItemsSkipsFeedsExcludedFromAllItemsAndKeepsTheirCopyOutOfTheCollapse` → `testAllItemsSkipsFeedsExcludedFromAllItems`; body unchanged (expects 1).
  - Replace `testATagCountsADuplicateOnceWhenBothCopiesCarryIt` with:

```php
    public function testATagCountsEveryCopyItHolds(): void
    {
        $user = $this->user();
        $tag = $this->tag($user, 'news');
        [$first, $feedA] = $this->subscribedFeed($user, 'https://example.com/a.xml');
        [$second, $feedB] = $this->subscribedFeed($user, 'https://example.com/b.xml');
        $first->addTag($tag);
        $second->addTag($tag);
        $this->unreadEntry($feedA, 'a', 'shared-hash');
        $this->unreadEntry($feedB, 'b', 'shared-hash');

        $counts = $this->repository()->perTag($user->requireId());

        self::assertSame(2, $counts[$tag->requireId()] ?? 0);
    }
```

  - Rename `testATagCountsItsCopyWhenTheLowerCopyIsOutsideTheTag` → `testATagCountsOnlyItsTaggedFeeds`; body unchanged (expects 1).
  - Add, after it:

```php
    public function testASubscriptionCountsInEachOfItsTags(): void
    {
        $user = $this->user();
        $news = $this->tag($user, 'news');
        $tech = $this->tag($user, 'tech');
        [$subscription, $feed] = $this->subscribedFeed($user, 'https://example.com/a.xml');
        $subscription->addTag($news);
        $subscription->addTag($tech);
        $this->unreadEntry($feed, 'a', null);

        $counts = $this->repository()->perTag($user->requireId());

        self::assertSame([$news->requireId() => 1, $tech->requireId() => 1], $counts);
    }
```

  - Replace `testANetworkCountsADuplicateOnceAcrossItsFeeds` with (same fixture, raw expectations):

```php
    public function testANetworkCountsEveryCopyAcrossItsFeeds(): void
    {
        $user = $this->user();
        [$home, $homeFeed] = $this->subscribedFeed($user, 'social:bluesky:1:home', SourceFormat::BLUESKY_API);
        [$custom, $customFeed] = $this->subscribedFeed($user, 'social:bluesky:1:feed:x', SourceFormat::BLUESKY_API);
        foreach (['one', 'two', 'three', 'four'] as $post) {
            $this->unreadEntry($homeFeed, 'home-' . $post, 'hash-' . $post);
            $this->unreadEntry($customFeed, 'custom-' . $post, 'hash-' . $post);
        }
        $this->unreadEntry($customFeed, 'custom-extra', 'hash-extra');

        $repository = $this->repository();
        $perSubscription = $repository->perSubscription($user->requireId());

        self::assertSame(9, $repository->perNetwork($user->requireId())[SocialNetwork::Bluesky->value] ?? 0);
        self::assertSame(4, $perSubscription[$home->requireId()] ?? 0);
        self::assertSame(5, $perSubscription[$custom->requireId()] ?? 0);
    }
```

  - Add:

```php
    public function testAFeedOnNoNetworkIsInNoNetworkCount(): void
    {
        $user = $this->user();
        [, $blueskyFeed] = $this->subscribedFeed($user, 'social:bluesky:1:home', SourceFormat::BLUESKY_API);
        [, $plainFeed] = $this->subscribedFeed($user, 'https://example.com/plain.xml');
        $this->unreadEntry($blueskyFeed, 'post', null);
        $this->unreadEntry($plainFeed, 'article', null);

        self::assertSame([SocialNetwork::Bluesky->value => 1], $this->repository()->perNetwork($user->requireId()));
    }
```

  - Delete `testNetworkProfilesCountAProfileCopyWhoseLowerCopyIsInAPrivateFeed` (it only covered the collapse's survivor choice; `testNetworkProfilesCountOnlyProfileFeeds` covers the profile rule).
  - Rename `testANetworkCountsACopyAlsoHeldByAnotherNetwork` → `testEachNetworkCountsItsOwnCopy`; body unchanged.

- [ ] **Step 2: Run the tests to verify the flipped ones fail**

Run: `cd backend && php bin/phpunit tests/Repository/UnreadCountRepositoryTest.php`
Expected: FAIL in `testAllItemsCountsEveryCopyOfACrossFeedDuplicate` (2 ≠ 3), `testATagCountsEveryCopyItHolds` (1 ≠ 2) and `testANetworkCountsEveryCopyAcrossItsFeeds` (5 ≠ 9); the rest pass.

- [ ] **Step 3: Rewrite `UnreadCountRepository`**

```php
<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Entry;
use App\Entity\EntryState;
use App\Entity\Subscription;
use App\Enum\NetworkFeedKind;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;

/** Each sidebar scope's unread count, by the list's own scope rules; a cross-feed copy counts in every scope holding it. */
final readonly class UnreadCountRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private EntryScopePredicates $scope,
    ) {
    }

    /** @return array<int, int> subscription id => unread entries; a subscription with none is absent */
    public function perSubscription(int $userId): array
    {
        return $this->countsByIntegerKey(
            $this->countedBy($this->unreadEntries($userId), EntryAliases::primary()->subscriptionKey()),
        );
    }

    /** @return array<int, int> tag id => unread entries; a tag with none is absent */
    public function perTag(int $userId): array
    {
        $aliases = EntryAliases::primary();
        $qb = $this->unreadEntries($userId);
        $this->scope->joinTags($qb, $aliases);

        return $this->countsByIntegerKey($this->countedBy($qb, $aliases->tagKey()));
    }

    /** @return array<string, int> network => unread entries; a network with none is absent */
    public function perNetwork(int $userId): array
    {
        return $this->countsByNetwork($this->networkCounts($userId, null));
    }

    /** @return array<string, int> network => unread entries of its profile feeds; a network with none is absent */
    public function perNetworkProfiles(int $userId): array
    {
        return $this->countsByNetwork($this->networkCounts($userId, NetworkFeedKind::Profile));
    }

    public function allItems(int $userId): int
    {
        $qb = $this->unreadEntries($userId)->select('COUNT(e.id)');
        $this->scope->inAllItems($qb, EntryAliases::primary());

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    private function networkCounts(int $userId, ?NetworkFeedKind $feedKind): QueryBuilder
    {
        $aliases = EntryAliases::primary();
        $qb = $this->unreadEntries($userId);
        $this->scope->joinNetworkFeeds($qb, $aliases, $feedKind);
        $qb->andWhere($aliases->networkKey() . ' IS NOT NULL');

        return $this->countedBy($qb, $aliases->networkKey());
    }

    private function unreadEntries(int $userId): QueryBuilder
    {
        $qb = $this->entityManager->createQueryBuilder()
            ->from(Subscription::class, 's')
            ->join(Entry::class, 'e', 'ON', 'e.feed = s.feed')
            ->leftJoin(EntryState::class, 'es', 'ON', 'es.entry = e AND es.user = s.user')
            ->andWhere('s.user = :user')
            ->setParameter('user', $userId);
        $this->scope->unread($qb, EntryAliases::primary());

        return $qb;
    }

    private function countedBy(QueryBuilder $qb, string $scopeKey): QueryBuilder
    {
        return $qb->select($scopeKey . ' AS scopeKey', 'COUNT(e.id) AS unreadCount')->groupBy('scopeKey');
    }
```

Keep `countsByIntegerKey()` and `countsByNetwork()` exactly as they are. Remove the now-unused imports (`SourceFormat`, `Types`).

`groupBy('scopeKey')` groups by the select's result variable, which DQL allows and which works for `IDENTITY(st.tag)` (a function, not a path). If SQLite or MySQL rejects it, do not add a second parameter for the GROUP BY path. Report BLOCKED with the SQL error, and the controller rules on it.

- [ ] **Step 4: Delete `DuplicateCollapseDql::applyToCount()`** and its docblock. `apply()` and `copyLookup()` stay unchanged; keep the imports `copyLookup()` still uses.

- [ ] **Step 5: Run the count tests on both legs**

Run: `cd backend && php bin/phpunit tests/Repository/UnreadCountRepositoryTest.php tests/Service/Subscription/SubscriptionTallyReaderTest.php tests/Controller/Api/EntryControllerTest.php tests/Controller/Api/SubscriptionControllerTest.php`
Expected: all PASS.

Run (repo root): `docker compose exec -T php php bin/phpunit tests/Repository/UnreadCountRepositoryTest.php`
Expected: all PASS (MySQL, `ONLY_FULL_GROUP_BY` accepts a grouped select alias).

- [ ] **Step 6: Full gates**

Run: `cd backend && composer test:parallel && composer check && composer md && composer infection:diff`
Expected: all green; Infection at or above `minMsi`.

- [ ] **Step 7: Commit**

```bash
git add backend/src/Repository/UnreadCountRepository.php backend/src/Repository/DuplicateCollapseDql.php backend/tests/Repository/UnreadCountRepositoryTest.php
git commit -m "refactor(#1681): unread counts group by the list's scope keys and ignore duplicates"
```

---

### After the tasks (controller, not an implementer)

- EXPLAIN on the dev MySQL: the raw counts, and an All-items and a tag list page. If no query reads `url_hash` through `idx_entry_feed_effective_hash`, record that; the issue keeps the index unless nothing needs it.
- Measure the counts request and `/api/subscriptions` in the built-in browser with `scratchpad/bench.js` against the ~0.72 s baseline.
- Frontend: no code change; `npm run check` stays green, since the wire shape is unchanged.
