# Scoped unread badges Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Every unread badge counts exactly what its own list shows. A feed's badge collapses duplicates only within that feed. The All items, tag, network and network-profiles totals come from the backend, each collapsed within its own scope, instead of being summed from per-feed badges in the browser.

**Architecture:** A new `UnreadCountRepository` runs one grouped query per scope type (subscription, tag, network, network profiles) plus one scalar query for All items. Each puts the outer row's scope key into `DuplicateCollapseDql`'s semi-join, so only a copy *in the same scope* can hide another. `SubscriptionTallyReader` packs the results into a `SubscriptionTalliesModel` that now carries an `UnreadTotalsModel`. Both the sidebar bootstrap and the counts poll serialise it as `unreadTotals`. The frontend keeps those totals in one signal in `SubscriptionsStore`, reads the sidebar and heading totals from it, and adjusts it optimistically through pure functions in `state/unread-totals.ts`.

**Tech Stack:** Symfony 7.4 / PHP 8.4 / Doctrine ORM / PHPUnit 12; Angular 20 standalone + signals, Jest.

**Spec:** GitHub issue #1679 (`gh issue view 1679`). Background: #496 §2 (scope-aware collapse) in `gh issue view 496`.

## Global Constraints

- Branch `fix/1679-scoped-unread-badges` off `develop`; commits `type(#1679): lower-case summary`; no Claude attribution anywhere.
- The response change only adds fields: `unreadTotals` is new, and every existing field keeps its name and meaning except that a subscription's `unreadCount` is now collapsed within that subscription only. Keep the native iOS client viable (docs/architecture.md §6).
- Each scope's count uses **the same unread predicate (`UnreadDql`) and the same scope rule its list uses** (`EntryScopePredicates::applyList`). All items = subscriptions with `includeInAllItems = true` (the unread view is chronological, so `EntryQuery::hidesExcludedFeeds()` applies). Network = `feed.network`. Network profiles = `PublicOrAuthorFeedDql::restrict(…, SourceFormat::NOT_PROFILES)` on the feed (as in `NetworkScopeDql`).
- `entryCount` is out of scope (see the issue). Leave `sumEntries` and the tag/network entry sums alone.
- Clean Code rules from CLAUDE.md apply: no abbreviations, guard clauses, no comment unless a future reader would get the code wrong, `final readonly`, queries only in `src/Repository`.
- Backend: `php bin/phpunit <path>` natively (from `backend/`). `composer check` and `composer md` must be clean for touched `src` files.
- Frontend tests run in the container: `docker compose exec -T frontend npx jest <path>`; the gate is `docker compose exec -T frontend npm run check`. Never run two Jest runs at once (container OOM).
- Before the PR: `/simplify`. After merge: deploy to Strato with the next `-dev.N` tag (see Task 7).

---

### Task 1: Backend — `UnreadCountRepository`, one count per scope

**Files:**
- Create: `backend/src/Repository/UnreadCountRepository.php`
- Test: `backend/tests/Repository/UnreadCountRepositoryTest.php` (move the four cases from `backend/tests/Repository/UnreadCountsTest.php` here, then delete that file)

**Interfaces:**
- Consumes: `DuplicateCollapseDql::apply(QueryBuilder $qb, callable(QueryBuilder, EntryAliases): void $applyScope, int $userId)`, `UnreadDql::predicate(?EntryAliases)`, `PublicOrAuthorFeedDql::restrict(QueryBuilder, string $feedAlias, list<string>)`, `SourceFormat::NOT_PROFILES`.
- Produces:
  - `UnreadCountRepository::perSubscription(int $userId): array<int, int>`: subscription id → unread; missing key = 0
  - `UnreadCountRepository::perTag(int $userId): array<int, int>`: tag id → unread
  - `UnreadCountRepository::perNetwork(int $userId): array<string, int>`: `SocialNetwork` value → unread
  - `UnreadCountRepository::perNetworkProfiles(int $userId): array<string, int>`: same keys, profile feeds only
  - `UnreadCountRepository::allItems(int $userId): int`

**Watch out:** `DuplicateCollapseDql` concatenates the inner builder's **DQL only**, so a parameter set on the inner builder is lost. Every parameter the inner scope uses (`:notHidden`, `:excludedFormats`, `:personFeedFormats`, `:personKinds`, `:user`) must also be bound on the outer query. The scopes below meet that, because the outer query applies the same predicate. Don't add an inner-only parameter.

- [ ] **Step 1: Write the failing tests**

Create `backend/tests/Repository/UnreadCountRepositoryTest.php`, a `DbTestCase`, with the repository from the container (`self::getContainer()->get(UnreadCountRepository::class)`, asserted with `assertInstanceOf`). Move the three cases of `UnreadCountsTest` that still hold (`…RespectingWatermarkAndState`, `…NoUnreadIsAbsentFromMap`, `testUnreadCopySurvivesWhenTheLowerIdDuplicateIsAlreadyRead`) unchanged except that they call `perSubscription()`. Replace `testCrossFeedDuplicateCountsOnceAcrossSubscriptions` with the cases below. Pull the repeated fixture code into private helpers (`user()`, `subscribedFeed(User, string $url, ?SocialNetwork $network = null)`, `unreadEntry(Feed, string $guid, ?string $urlHash)`), and **flush after each entry** so ids rise in creation order. Use the real constructors as `UnreadCountsTest` does today: `new Entry($feed, $guid, $url, $title, $createdAt, $publishedAt, $urlHash)`. For a networked feed call `$feed->classifyAs(SocialNetwork::Bluesky)`. For a tag, copy how `tests/Repository/NetworkScopeTest.php` builds a `Tag` + `SubscriptionTag`.

```php
public function testEachSubscriptionCountsItsOwnCopyOfACrossFeedDuplicate(): void
{
    $user = $this->user();
    [$home, $homeFeed] = $this->subscribedFeed($user, 'social:bluesky:1:home');
    [$custom, $customFeed] = $this->subscribedFeed($user, 'social:bluesky:1:feed:x');
    $this->unreadEntry($homeFeed, 'home-copy', 'shared-hash');
    $this->unreadEntry($customFeed, 'custom-copy', 'shared-hash');

    $counts = $this->repository()->perSubscription($user->requireId());

    self::assertSame(1, $counts[$home->requireId()] ?? 0);
    self::assertSame(1, $counts[$custom->requireId()] ?? 0);
}

public function testAllItemsCountsACrossFeedDuplicateOnce(): void
{
    $user = $this->user();
    [, $feedA] = $this->subscribedFeed($user, 'https://example.com/a.xml');
    [, $feedB] = $this->subscribedFeed($user, 'https://example.com/b.xml');
    $this->unreadEntry($feedA, 'a', 'shared-hash');
    $this->unreadEntry($feedB, 'b', 'shared-hash');
    $this->unreadEntry($feedB, 'unique', null);

    self::assertSame(2, $this->repository()->allItems($user->requireId()));
}

public function testAllItemsSkipsFeedsExcludedFromAllItemsAndKeepsTheirCopyOutOfTheCollapse(): void
{
    $user = $this->user();
    [$excluded, $excludedFeed] = $this->subscribedFeed($user, 'https://example.com/excluded.xml');
    $excluded->setIncludeInAllItems(false);
    [, $includedFeed] = $this->subscribedFeed($user, 'https://example.com/included.xml');
    $this->unreadEntry($excludedFeed, 'lower', 'shared-hash');
    $this->unreadEntry($includedFeed, 'higher', 'shared-hash');
    $this->entityManager->flush();

    self::assertSame(1, $this->repository()->allItems($user->requireId()));
}

public function testATagCountsADuplicateOnceWhenBothCopiesCarryIt(): void
{
    // two subscriptions both tagged T, one shared-hash copy each → perTag[T] === 1
}

public function testATagCountsItsCopyWhenTheLowerCopyIsOutsideTheTag(): void
{
    // lower-id copy in an untagged subscription, higher-id copy in a T-tagged one → perTag[T] === 1
}

public function testANetworkCountsADuplicateOnceAcrossItsFeeds(): void
{
    // the subscription-1905 shape: Bluesky Home + Bluesky custom feed share 4 posts, the custom feed has 1 more
    // → perNetwork['bluesky'] === 5 (Home's 4 + the extra one), perSubscription: Home 4, custom 5
}

public function testNetworkProfilesCountOnlyProfileFeeds(): void
{
    // a Bluesky Home (private) + a Bluesky profile feed: perNetworkProfiles['bluesky'] counts only the profile's entries
    // build the profile feed the way tests/Repository/FeedRepositoryNetworkScopeTest.php does
}
```

Write the three commented cases out in full, in the same style as the first three. For the profile case, reuse the profile-vs-private fixture in `tests/Repository/FeedRepositoryNetworkScopeTest.php`.

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php bin/phpunit tests/Repository/UnreadCountRepositoryTest.php`
Expected: FAIL (`UnreadCountRepository` not found).

- [ ] **Step 3: Implement the repository**

```php
<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Entry;
use App\Entity\EntryState;
use App\Entity\Subscription;
use App\Enum\SourceFormat;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;

/** Each sidebar scope's unread count, collapsing cross-feed copies within that scope only, as its list does. */
final readonly class UnreadCountRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private DuplicateCollapseDql $collapse,
    ) {
    }

    /** @return array<int, int> subscription id => unread entries; a subscription with none is absent */
    public function perSubscription(int $userId): array
    {
        $qb = $this->unreadEntries($userId)
            ->select('s.id AS scopeKey', 'COUNT(e.id) AS unreadCount')
            ->groupBy('s.id');
        $this->collapseWithin($qb, $userId, static function (QueryBuilder $inner): void {
            $inner->andWhere('s2.id = s.id');
        });

        return $this->countsByIntegerKey($qb);
    }

    /** @return array<int, int> tag id => unread entries; a tag with none is absent */
    public function perTag(int $userId): array
    {
        $qb = $this->unreadEntries($userId)
            ->innerJoin('s.subscriptionTags', 'st')
            ->select('IDENTITY(st.tag) AS scopeKey', 'COUNT(e.id) AS unreadCount')
            ->groupBy('st.tag');
        $this->collapseWithin($qb, $userId, static function (QueryBuilder $inner): void {
            $inner->innerJoin('s2.subscriptionTags', 'st2', 'WITH', 'IDENTITY(st2.tag) = IDENTITY(st.tag)');
        });

        return $this->countsByIntegerKey($qb);
    }

    /** @return array<string, int> network => unread entries; a network with none is absent */
    public function perNetwork(int $userId): array
    {
        return $this->countsByStringKey($this->networkCounts($userId, static function (): void {
        }));
    }

    /** @return array<string, int> network => unread entries of its profile feeds; a network with none is absent */
    public function perNetworkProfiles(int $userId): array
    {
        return $this->countsByStringKey($this->networkCounts(
            $userId,
            static function (QueryBuilder $qb, string $feedAlias): void {
                PublicOrAuthorFeedDql::restrict($qb, $feedAlias, SourceFormat::NOT_PROFILES);
            },
        ));
    }

    public function allItems(int $userId): int
    {
        $qb = $this->unreadEntries($userId)
            ->select('COUNT(e.id)')
            ->andWhere('s.includeInAllItems = true');
        $this->collapseWithin($qb, $userId, static function (QueryBuilder $inner): void {
            $inner->andWhere('s2.includeInAllItems = true');
        });

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    // networkCounts(), unreadEntries(), collapseWithin(), countsByIntegerKey(), countsByStringKey(): below
}
```

The profiles variant restricts the outer feed alias **and** the inner one, so `networkCounts()` takes the restriction as a closure and applies it to both:

```php
/** @param callable(QueryBuilder, string): void $restrictFeeds applied to the outer feed alias and the inner one */
private function networkCounts(int $userId, callable $restrictFeeds): QueryBuilder
{
    $qb = $this->unreadEntries($userId)
        ->innerJoin('e.feed', 'nf')
        ->andWhere('nf.network IS NOT NULL')
        ->select('nf.network AS scopeKey', 'COUNT(e.id) AS unreadCount')
        ->groupBy('nf.network');
    $restrictFeeds($qb, 'nf');
    $this->collapseWithin($qb, $userId, static function (QueryBuilder $inner) use ($restrictFeeds): void {
        $inner->innerJoin('e2.feed', 'nf2', 'WITH', 'nf2.network = nf.network');
        $restrictFeeds($inner, 'nf2');
    });

    return $qb;
}
```

If the no-op closure in `perNetwork()` reads badly, make the restriction a nullable parameter or use two named private methods. Pick whichever passes `composer md` and reads as a sentence.

The shared helpers:

```php
private function unreadEntries(int $userId): QueryBuilder
{
    return $this->entityManager->createQueryBuilder()
        ->from(Subscription::class, 's')
        ->join(Entry::class, 'e', 'ON', 'e.feed = s.feed')
        ->leftJoin(EntryState::class, 'es', 'ON', 'es.entry = e AND es.user = s.user')
        ->andWhere('s.user = :user')
        ->andWhere(UnreadDql::predicate())
        ->setParameter('user', $userId)
        ->setParameter('notHidden', false, Types::BOOLEAN);
}

/** @param callable(QueryBuilder): void $correlateScope keeps the semi-join inside the outer row's scope */
private function collapseWithin(QueryBuilder $qb, int $userId, callable $correlateScope): void
{
    $this->collapse->apply(
        $qb,
        static function (QueryBuilder $inner, EntryAliases $aliases) use ($correlateScope): void {
            $inner->andWhere(UnreadDql::predicate($aliases));
            $correlateScope($inner);
        },
        $userId,
    );
}
```

`countsByIntegerKey()` / `countsByStringKey()` read `getScalarResult()` rows of `{scopeKey, unreadCount}` into `array<int, int>` / `array<string, int>`. For `nf.network`, check in the network test which form Doctrine's scalar hydration returns for an `enumType` column (the backed string, or a `SocialNetwork`). Write the single conversion that matches and no defensive second branch. If PHPStan needs a `@var` on the rows, use the exact row shape.

If DQL rejects `groupBy('st.tag')` or `groupBy('nf.network')`, group by the result variable `scopeKey`.

- [ ] **Step 4: Run the tests to verify they pass**

Run: `php bin/phpunit tests/Repository/UnreadCountRepositoryTest.php`
Expected: PASS. Then delete `backend/tests/Repository/UnreadCountsTest.php`; its cases now live in the new test.

- [ ] **Step 5: Break-test the scope correlation**

One at a time, delete each correlation line (`s2.id = s.id`, the `st2` join, the `nf2` join, `s2.includeInAllItems = true`) and run the test file. Quote the FAIL each removal produces. Restore by re-applying the line with Edit, not `git checkout --`. If removing a line fails no test, the test for that scope is missing; add it.

- [ ] **Step 6: Commit**

```bash
git add backend/src/Repository/UnreadCountRepository.php backend/tests/Repository/UnreadCountRepositoryTest.php backend/tests/Repository/UnreadCountsTest.php
git commit -m "feat(#1679): count unread per scope, collapsing duplicates within each scope"
```

---

### Task 2: Backend — tallies carry the scope totals; both payloads send `unreadTotals`

**Files:**
- Create: `backend/src/Service/Subscription/Model/UnreadTotalsModel.php`
- Create: `backend/src/Http/UnreadTotalsJson.php`
- Modify: `backend/src/Service/Subscription/Model/SubscriptionTalliesModel.php`
- Modify: `backend/src/Service/Subscription/SubscriptionTallyReader.php`
- Modify: `backend/src/Http/SubscriptionCountsJson.php`, `backend/src/Http/SubscriptionJson.php` (`list()`)
- Modify: `backend/src/Repository/EntryStateRepository.php`: delete `unreadCountsForUser()` and the now-unused `DuplicateCollapseDql` constructor dependency and imports
- Test: `backend/tests/Service/Subscription/SubscriptionTallyReaderTest.php`, `backend/tests/Http/SubscriptionCountsJsonTest.php`, new `backend/tests/Http/UnreadTotalsJsonTest.php`, and every test constructing `SubscriptionTalliesModel` (`grep -rln "new SubscriptionTalliesModel" tests`)
- Test (functional): whichever controller test asserts the `GET /api/subscriptions` and counts payload shape (`grep -rln "favoritesCount" tests/Controller`)

**Interfaces:**
- Consumes: Task 1's five methods.
- Produces:
  - `final readonly class UnreadTotalsModel { public function __construct(public int $allItems, /** array<int,int> */ public array $tags, /** array<string,int> */ public array $networks, /** array<string,int> */ public array $networkProfiles) }`
  - `SubscriptionTalliesModel` gets a fourth promoted property, `public UnreadTotalsModel $unreadTotals`. `$unreadCounts` stays, now filled from `perSubscription()`.
  - `UnreadTotalsJson::from(UnreadTotalsModel): array{allItems: int, tags: list<array{id: int, unreadCount: int}>, networks: list<array{network: string, unreadCount: int, profilesUnreadCount: int}>}`. `networks` lists every key of `$networks`. `profilesUnreadCount` defaults to 0.
  - Wire: both `SubscriptionJson::list()` and `SubscriptionCountsJson::from()` add `'unreadTotals' => UnreadTotalsJson::from($tallies->unreadTotals)`.

- [ ] **Step 1: Write the failing tests**
  - `UnreadTotalsJsonTest`: a model with `allItems: 7, tags: [3 => 2], networks: ['bluesky' => 5, 'mastodon' => 1], networkProfiles: ['bluesky' => 2]` maps to exactly
    ```php
    ['allItems' => 7, 'tags' => [['id' => 3, 'unreadCount' => 2]], 'networks' => [
        ['network' => 'bluesky', 'unreadCount' => 5, 'profilesUnreadCount' => 2],
        ['network' => 'mastodon', 'unreadCount' => 1, 'profilesUnreadCount' => 0],
    ]]
    ```
  - `SubscriptionCountsJsonTest`: the payload has `unreadTotals`.
  - `SubscriptionTallyReaderTest`: the tallies carry `unreadTotals` from the repository. Follow how the test builds the reader today. If it uses the real container, seed one tagged subscription and assert `tags[tagId]`.
  - The functional controller test: `unreadTotals.allItems` is present on both `GET /api/subscriptions` and the counts endpoint.

- [ ] **Step 2: Run them, expect FAIL**

Run: `php bin/phpunit tests/Http tests/Service/Subscription`

- [ ] **Step 3: Implement**

`SubscriptionTallyReader` injects `UnreadCountRepository $unreadCounts` in place of `EntryStateRepository`'s count call. It still needs `EntryStateRepository` for `stateCountsForUser`:

```php
public function forUser(int $userId): SubscriptionTalliesModel
{
    return new SubscriptionTalliesModel(
        $this->unreadCounts->perSubscription($userId),
        $this->entryCounts->forUser($userId),
        $this->entryStates->stateCountsForUser($userId),
        new UnreadTotalsModel(
            $this->unreadCounts->allItems($userId),
            $this->unreadCounts->perTag($userId),
            $this->unreadCounts->perNetwork($userId),
            $this->unreadCounts->perNetworkProfiles($userId),
        ),
    );
}
```

`new UnreadTotalsModel` inside a service method: `ServiceRoleRule` and the "no `new` on a collaborator" rule allow constructing a Model (it's data, not a collaborator). If `composer stan` objects, move the construction into a static `UnreadTotalsModel::…` named constructor. Then delete `unreadCountsForUser()` from `EntryStateRepository` and drop its constructor's `DuplicateCollapseDql`.

- [ ] **Step 4: Run the backend suite and the gates**

Run: `php bin/phpunit` then `composer check` and `composer md` (from `backend/`).
Expected: all green, no PHPMD findings in touched `src` files.

- [ ] **Step 5: Commit**

```bash
git add -A backend
git commit -m "feat(#1679): send the scope unread totals with the sidebar and counts payloads"
```

---

### Task 3: Frontend — `unread-totals.ts`, the totals and their pure updates

**Files:**
- Create: `frontend/src/app/reader/state/unread-totals.ts`
- Create: `frontend/src/app/reader/state/unread-totals.spec.ts`
- Modify: `frontend/src/app/reader/models.ts`: add `UnreadTotalsDto` and the `unreadTotals` field to `SubscriptionsResponse` and `SubscriptionCountsResponse`
- Modify: `frontend/src/app/reader/state/subscriptions.store.ts`: **move** `export type ZeroTarget` out to `unread-totals.ts`, and update its importers (`subscriptions.store.ts`, `shell/mark-read-actions.service.ts`)

**Interfaces:**
- Produces:

```ts
// models.ts
export interface UnreadTotalsDto {
  allItems: number;
  tags: { id: number; unreadCount: number }[];
  networks: { network: SocialNetworkName; unreadCount: number; profilesUnreadCount: number }[];
}

// unread-totals.ts
export type ZeroTarget =
  | 'all'
  | { tag: number }
  | { subscription: number }
  | { network: SocialNetworkName; feedKind?: NetworkFeedKind };

export interface UnreadTotals {
  readonly allItems: number;
  readonly tags: ReadonlyMap<number, number>;
  readonly networks: ReadonlyMap<SocialNetworkName, number>;
  readonly networkProfiles: ReadonlyMap<SocialNetworkName, number>;
}

export const NO_UNREAD_TOTALS: UnreadTotals;
export function unreadTotalsFrom(dto: UnreadTotalsDto): UnreadTotals;
export function sameUnreadTotals(left: UnreadTotals, right: UnreadTotals): boolean;
/** Move every scope holding at least one of an article's copies by `by`, once per scope, never below 0. */
export function shiftUnreadTotals(totals: UnreadTotals, copies: readonly SubscriptionDto[], by: number): UnreadTotals;
export function zeroUnreadTotals(totals: UnreadTotals, target: ZeroTarget): UnreadTotals;
```

- [ ] **Step 1: Write the failing spec**

Cover, with `SubscriptionDto` fixtures built the way `social-sections.spec.ts` builds them:
- `unreadTotalsFrom` maps the DTO lists into maps.
- `shiftUnreadTotals` with two copies, both tagged 3 and both on Bluesky: `allItems`, `tags[3]` and `networks.bluesky` each move by exactly 1, not 2.
- A copy with `includeInAllItems: false` alone leaves `allItems` unchanged.
- A profile copy (`social.kind === 'profile'`) moves `networkProfiles`; a Home copy doesn't.
- Clamped at 0.
- Unchanged maps keep their identity when no copy touches them (`toBe`).
- `zeroUnreadTotals` for `'all'`, `{tag}`, `{network}`, `{network, feedKind: 'profile'}` (use the real `NetworkFeedKind` value) and `{subscription}` (returns `totals` unchanged).
- `sameUnreadTotals`: true for structurally equal totals, false when one map value differs.

- [ ] **Step 2: Run, expect FAIL**

Run: `docker compose exec -T frontend npx jest src/app/reader/state/unread-totals.spec.ts`

- [ ] **Step 3: Implement**

```ts
export const NO_UNREAD_TOTALS: UnreadTotals = {
  allItems: 0,
  tags: new Map(),
  networks: new Map(),
  networkProfiles: new Map(),
};

export function unreadTotalsFrom(dto: UnreadTotalsDto): UnreadTotals {
  return {
    allItems: dto.allItems,
    tags: new Map(dto.tags.map((tag) => [tag.id, tag.unreadCount])),
    networks: new Map(dto.networks.map((network) => [network.network, network.unreadCount])),
    networkProfiles: new Map(
      dto.networks.map((network) => [network.network, network.profilesUnreadCount]),
    ),
  };
}

export function shiftUnreadTotals(
  totals: UnreadTotals,
  copies: readonly SubscriptionDto[],
  by: number,
): UnreadTotals {
  return {
    allItems: copies.some((copy) => copy.includeInAllItems)
      ? Math.max(0, totals.allItems + by)
      : totals.allItems,
    tags: shifted(totals.tags, new Set(copies.flatMap((copy) => copy.tags.map((tag) => tag.id))), by),
    networks: shifted(totals.networks, networksOf(copies), by),
    networkProfiles: shifted(totals.networkProfiles, networksOf(copies.filter(isProfileFeed)), by),
  };
}

function networksOf(copies: readonly SubscriptionDto[]): ReadonlySet<SocialNetworkName> {
  return new Set(copies.flatMap((copy) => networkOf(copy) ?? []));
}

function shifted<Key>(
  counts: ReadonlyMap<Key, number>,
  keys: ReadonlySet<Key>,
  by: number,
): ReadonlyMap<Key, number> {
  if (keys.size === 0) return counts;
  const next = new Map(counts);
  for (const key of keys) next.set(key, Math.max(0, (counts.get(key) ?? 0) + by));
  return next;
}
```

`zeroUnreadTotals` sets the one targeted entry to 0 through a `withZero(map, key)` helper. `sameUnreadTotals` compares `allItems` and each map's size and entries. `networkOf` / `isProfileFeed` come from `social-sections.ts`; if importing them creates a cycle once Task 4 makes `social-sections.ts` import `UnreadTotals`, move the two predicates to a small `social-feed-kinds.ts` and re-point their importers.

- [ ] **Step 4: Run, expect PASS**; then `docker compose exec -T frontend npx tsc -p tsconfig.app.json --noEmit` (native Jest skips the typecheck).

- [ ] **Step 5: Commit**

```bash
git add frontend/src/app/reader
git commit -m "feat(#1679): unread totals and their optimistic updates as pure functions"
```

---

### Task 4: Frontend — the store holds the totals; the sidebar and headings read them

**Files:**
- Modify: `frontend/src/app/reader/state/subscriptions.store.ts`
- Modify: `frontend/src/app/reader/state/subscription-derivations.ts`: `buildTagTree` takes the tag totals; **delete `sumUnread`**
- Modify: `frontend/src/app/reader/state/social-sections.ts`: `buildSocialSections` and `socialProfiles` take the totals
- Modify: callers found by `grep -rn "buildTagTree\|buildSocialSections\|socialProfiles(\|sumUnread" frontend/src` (including `settings/organise/organise.store.ts`)
- Modify: `frontend/e2e/support/reader.ts` and any Playwright stub of `/api/subscriptions` or the counts endpoint: add `unreadTotals`
- Test: `subscriptions.store.spec.ts`, `subscription-derivations` spec (if present), `social-sections.spec.ts`, `sidebar*.spec.ts`, `list-heading.service.spec.ts`, plus every spec whose stubbed responses lack `unreadTotals` (tsc will list them)

**Interfaces:**
- Consumes: Task 3.
- Produces:
  - `SubscriptionsStore.unreadTotals: Signal<UnreadTotals>` (read-only; a private `WritableSignal` created with `signal(NO_UNREAD_TOTALS, { equal: sameUnreadTotals })`)
  - `SubscriptionsStore.totalUnread = computed(() => this.unreadTotals().allItems)`
  - `buildTagTree(subscriptions, orderedTags = [], tagUnread: ReadonlyMap<number, number> = new Map())`: node `unreadCount = tagUnread.get(tag.id) ?? 0`; `entryCount` stays summed
  - `buildSocialSections(subscriptions, socialAccounts, totals: UnreadTotals)`: section `unreadCount = totals.networks.get(network) ?? 0`, `profiles.unreadCount = totals.networkProfiles.get(network) ?? 0`
  - `socialProfiles(subscriptions, unreadCount: number)`

- [ ] **Step 1: Write the failing tests**
  - Store: after `load()` answers a `SubscriptionsResponse` whose `unreadTotals.allItems` is 4 while the per-feed `unreadCount`s sum to 6, `totalUnread()` is 4. A counts tick with identical totals leaves `unreadTotals()` identical (`toBe`). A tick with a changed tag total updates `tagTree()`'s node.
  - `buildTagTree`: a node's `unreadCount` comes from the map, not the feed sum.
  - `buildSocialSections`: the section and profile counts come from the totals.
- [ ] **Step 2: Run, expect FAIL** (`docker compose exec -T frontend npx jest src/app/reader/state`)
- [ ] **Step 3: Implement.** `applyCounts` and `applyCountsOnly` both `this.storedUnreadTotals.set(unreadTotalsFrom(response.unreadTotals))`; the custom `equal` keeps the old value when nothing moved. `tagTree` passes `this.unreadTotals().tags`; `socialSections` passes `this.unreadTotals()`. Logout/reset (around line 141, where `socialAccounts.set([])` runs) resets the totals to `NO_UNREAD_TOTALS`. Update the `applyCountsOnly` doc comment's list of computeds.
- [ ] **Step 4: Run** the state specs, then `docker compose exec -T frontend npm run check`; fix every spec and e2e stub that tsc flags for the missing `unreadTotals`.
- [ ] **Step 5: Commit** `feat(#1679): the sidebar and list headings read the server's scope totals`

---

### Task 5: Frontend — optimistic read/unread moves every copy and every scope once; mark-read reconciles

**Files:**
- Modify: `frontend/src/app/reader/state/subscriptions.store.ts`: replace `decrementUnread(subscriptionId, by)` / `incrementUnread(subscriptionId, by)` with `markArticleRead(entry: EntryDto)` / `markArticleUnread(entry: EntryDto)`; `zeroUnread` also zeroes the targeted total
- Modify: `frontend/src/app/reader/shell/entry-state-actions.service.ts` (the four call sites at lines ~70, 77, 104, 110)
- Modify: `frontend/src/app/reader/shell/mark-read-actions.service.ts`: `reloadAfterMarkRead` calls `this.subscriptions.reloadQuietly()` right after `zeroUnread(watermark)`
- Modify: any other caller (`grep -rn "decrementUnread\|incrementUnread" frontend/src`, e.g. `feeds/manage/manage-actions.service.spec.ts`)
- Test: `subscriptions.store.spec.ts`, `entry-state-actions.service` spec, `mark-read-actions.service.spec.ts`

**Interfaces:**
- Consumes: `shiftUnreadTotals`, `zeroUnreadTotals` (Task 3); `EntryDto.duplicates?: EntryDto[]` and `EntryDto.subscriptionId`, `EntryDto.isHidden`.
- Produces: `markArticleRead(entry: EntryDto): void`, `markArticleUnread(entry: EntryDto): void`.

The article's copies are `[entry, ...(entry.duplicates ?? []).filter((copy) => !copy.isHidden)]`. The caller has already checked `!entry.isHidden` for the entry itself, and `isHidden` includes the watermark (`EntryListRow`). Each copy's subscription `unreadCount` moves by 1. The totals move once through `shiftUnreadTotals(totals, copiesSubscriptions, ∓1)`, where `copiesSubscriptions` are the held `SubscriptionDto`s for those ids; a copy whose subscription isn't held is skipped. Both edits go through `patchSubscriptions`, and the totals edit increments `localEdits` too, so an in-flight tick is dropped (see `quietFetch`). Add a private `patchUnreadTotals(patch)` that does `++this.localEdits` and updates the signal.

- [ ] **Step 1: Write the failing tests**
  - Store: an entry in subscription A with an unread duplicate in subscription B (both Bluesky, both included in All items). `markArticleRead` brings A and B down by 1 each, `totalUnread()` by 1 and `networks.bluesky` by 1. `markArticleUnread` restores them.
  - A duplicate with `isHidden: true` doesn't move B.
  - `zeroUnread({ tag: 3 })` zeroes `unreadTotals().tags.get(3)`.
  - `mark-read-actions`: after a tag mark-read, `reloadQuietly` is called after `zeroUnread`.
  - `entry-state-actions`: opening/ticking calls `markArticleRead(entry)`; the error path calls `markArticleUnread(entry)`.
- [ ] **Step 2: Run, expect FAIL**
- [ ] **Step 3: Implement** as described; delete the old two methods.
- [ ] **Step 4: Run** the touched specs, then `docker compose exec -T frontend npm run check`.
- [ ] **Step 5: Commit** `feat(#1679): reading an article moves each copy's badge and each scope total once`

---

### Task 6: Verify on the real stack, simplify, PR

- [ ] **Step 1: Containers current.** `docker compose exec php bin/console cache:clear`, and restart `worker` if the counts are computed there (they aren't, but the DI container changed). Confirm `http://localhost:4200` serves the new chunk.
- [ ] **Step 2: The reported case.** As user 2 in the dev DB, `GET /api/subscriptions/counts` (use the real counts route; `bin/console debug:router | grep -i count`) or open `http://localhost:4200/?subscription=1905` in the built-in browser. The with-friends badge equals the number of unread rows its list shows (5 at filing time; recount with the Task-1 SQL from the issue if it moved). The Bluesky section total and the All-items total each match their own unread lists (open `?network=bluesky` and the All list in the unread view, and compare their list totals).
- [ ] **Step 3: Measure.** Time the counts endpoint before (on `develop`) and after, on the dev DB, three runs each. Report the numbers in the PR. If it regresses by more than ~2×, `EXPLAIN` the slowest of the five queries and report before optimising.
- [ ] **Step 4: Dev log.** `ls -t backend/var/log/dev-*.log | head -1 | xargs tail -n 200 | jq -c 'select(.level_name != "DEBUG" and .level_name != "INFO")'` should show nothing new.
- [ ] **Step 5: Both backend legs.** `composer test:parallel` natively and `docker compose exec php composer test` (MySQL).
- [ ] **Step 6: `/simplify`** over the branch diff; apply its fixes, rerun the touched tests and gates, commit `refactor(#1679): …`.
- [ ] **Step 7: Mutation gate locally.** `composer infection:diff` from `backend/`; kill any escaped mutant in the new repository with a test.
- [ ] **Step 8: PR** into `develop`, body ending `Closes #1679`, including the before/after timings and the subscription-1905 check.

### Task 7: Merge and deploy to Strato (the user asked for this on 2026-10-18)

- [ ] **Step 1:** `gh pr checks <PR> --repo larspohlmann/simple-feed-reader --watch --fail-fast --interval 30` in the background; merge with `gh pr merge <PR> --merge` only after it exits 0 (`--auto` merges immediately here). Verify #1679 closed.
- [ ] **Step 2: Pick the tag.** `git fetch --tags`; list release tags (`git tag --sort=-v:refname | grep -v -- -dev | head -3`) and dev tags (`git tag --sort=-v:refname | grep -- -dev | head -3`). If the newest release equals the newest dev tag's base, bump the patch and start at `.1`; otherwise increment `N`. When it's ambiguous, ask.
- [ ] **Step 3:** wait until CI is green on the merge commit on `develop`, then `git tag vX.Y.Z-dev.N <merge-sha> && git push origin vX.Y.Z-dev.N`, and watch `deploy-strato.yml` (`gh run watch`) to completion. Report the result.

---

### Task 8: Backend — run the collapse semi-join only for entries that have a copy

Measured on the dev DB (user 2, 75,164 subscribed entries, 64,166 with a URL hash): the scope counts take ~2.5 s, of which the base scan is ~50 ms and each `DuplicateCollapseDql` semi-join is ~850 ms. Only 2,036 entries (1,013 URL hashes) have a copy among the user's subscriptions. The `NOT EXISTS` lookup runs for every hashed row, though for 97% of them it can only answer "no copy".

**Files:**
- Modify: `backend/src/Repository/DuplicateCollapseDql.php`
- Test: `backend/tests/Repository/` (find the existing `DuplicateCollapseDql` coverage with `grep -rln "DuplicateCollapseDql\|collapse" tests/Repository`)

**Interfaces:** `DuplicateCollapseDql::apply()` keeps its signature and meaning. All callers (the entry list, search, `rowsByIdsForUser`, `UnreadCountRepository`, `RecommendationCandidateRepository`) get the speed-up unchanged.

- [ ] **Step 1:** Add an uncorrelated subselect of the URL hashes that occur more than once among the caller's subscribed entries (aliases `e3`/`s3`, which no caller uses):

```php
$duplicatedHashes = $this->entityManager->createQueryBuilder()
    ->select('e3.location.urlHash')
    ->from(Entry::class, 'e3')
    ->join(Subscription::class, 's3', 'ON', 's3.feed = e3.feed AND s3.user = :user')
    ->andWhere('e3.location.urlHash IS NOT NULL')
    ->groupBy('e3.location.urlHash')
    ->having('COUNT(e3.id) > 1');
```

and make the predicate `e.location.urlHash IS NULL OR e.location.urlHash NOT IN (<duplicatedHashes>) OR NOT EXISTS (<inner>)`. This is a superset test: a hash held once among all the user's subscriptions can have no copy in any narrower scope, so the result is identical. Update the class docblock only if its sentence about the predicate stops being true.

- [ ] **Step 2:** `php bin/phpunit tests/Repository` and the full `php bin/phpunit` must stay green unchanged (the change preserves behaviour). Run the MySQL leg too: `docker compose exec -T php composer test`. MySQL's handling of `NOT IN` with a grouped subselect is what this is for, and a dialect error would only show there.
- [ ] **Step 3:** Run `composer check`, `composer md` and `composer infection:diff`. A mutant that deletes the prefilter can survive, since no behaviour test can tell the difference. Report the MSI, and whether the gate still passes, rather than writing a test that pins the DQL text.
- [ ] **Step 4:** Commit `perf(#1679): run the duplicate semi-join only for entries that have a copy`. The controller measures afterwards with the same benchmark as the baseline.
