# Cross-feed Duplicate Collapse Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Collapse cross-feed duplicate articles to one card per user at read time, and show on that card which other feeds also carry the article, with a click-through popover to each other copy.

**Architecture:** A read-time, per-user collapse keyed on the existing `entry.url_hash`. A shared `DuplicateCollapseDql` predicate (built from a per-query scope, applied to a fixed second alias set) is applied at the five per-user read paths. The three card paths enrich each survivor with the copies it hid, serialized as `EntryDto.duplicates`. Read/viewed state mirrors across a group; favorite/kept do not. The frontend renders a footer that names each other copy and opens it in a popover reusing the existing entry-row card.

**Tech Stack:** Symfony 7.4 / PHP 8.4 / Doctrine ORM (backend, SQLite in tests), Angular 20 / signals / Jest (frontend), MySQL in Docker/prod.

**Spec:** `docs/superpowers/specs/2026-09-11-cross-feed-duplicate-collapse-design.md`

## Global Constraints

- **Backend Clean Code.** `composer check` (PSR-12 + `declare(strict_types=1)`; PHPStan level max incl. `ThinControllerRule`; phptramp) and `composer md` must be clean **on every touched `src` file** — fix the design, not the threshold.
- **PhpStorm inspections** on changed PHP block on ERROR and WARNING.
- **Mutation gate:** `composer infection:diff` with `minMsi: 80` over touched files. Never lower it.
- **Datetimes are naive UTC.** The collapse uses `id`, not dates, but the recommendation scope reads `effectiveDate`.
- **Native iOS stays viable:** the only API change is an additive `duplicates` array on the existing entry JSON. No CSRF, no browser-only input.
- **Frontend:** no hex outside `src/app/theme/`; no raw `px` spacing / media literals; component styles in a sibling `.scss` (`styleUrl`), never inline; new copy needs a Transloco key added to **both** `public/i18n/en.json` and `public/i18n/de.json`; `npm run check` is the gate; standalone components + signals; never nest `cdkDropList`.
- **Branch:** `feature/496-cross-feed-duplicate-collapse` (already created). Commit format `type(#496): summary`. No attribution lines.
- **Tests are production code** — same naming and standards.
- Run backend tests with `php bin/phpunit`; frontend tests with `docker compose exec -T frontend npm test`.

---

# Part A — Backend

Part A is independently shippable: once done, the API returns collapsed lists with a `duplicates` array and mirrors read state, with no frontend change.

---

### Task A1: Add the `(url_hash, id)` index

**Files:**
- Modify: `src/Entity/Entry.php:13-16` (table index attributes)
- Create: `migrations/Version<timestamp>.php`

**Interfaces:**
- Produces: an index `idx_entry_url_hash (url_hash, id)` on the `entry` table, backing the collapse semi-join and the enrichment query.

- [ ] **Step 1: Add the index to the entity mapping**

In `src/Entity/Entry.php`, after the existing `#[ORM\Index(...)]` lines (currently ending at line 16), add:

```php
#[ORM\Index(name: 'idx_entry_url_hash', columns: ['url_hash', 'id'])]
```

- [ ] **Step 2: Generate the migration diff**

Run: `bin/console doctrine:migrations:diff --no-interaction`
Expected: a new `migrations/Version<timestamp>.php` whose `up()` creates `idx_entry_url_hash` and `down()` drops it, for both platforms. Open it and confirm it contains only that index (no unrelated drift). If it contains drift, discard it and hand-write a migration matching the style of `migrations/Version20260821120000.php` (platform-aware `up()`/`down()`), creating the index on MySQL (`CREATE INDEX idx_entry_url_hash ON entry (url_hash, id)`) and SQLite alike.

- [ ] **Step 3: Migrate from empty on SQLite and validate the schema**

Run:
```bash
bin/console doctrine:migrations:migrate --no-interaction --env=test
bin/console doctrine:schema:validate --env=test
```
Expected: migration applies; schema validate reports "in sync" for mapping and database.

- [ ] **Step 4: Commit**

```bash
git add src/Entity/Entry.php migrations/
git commit -m "feat(#496): index entry(url_hash, id) for read-time duplicate collapse"
```

---

### Task A2: Make `UnreadDql` alias-aware and add `EntryAliases`

**Files:**
- Create: `src/Repository/EntryAliases.php`
- Modify: `src/Repository/UnreadDql.php`
- Test: `tests/Repository/UnreadDqlTest.php`

**Interfaces:**
- Produces: `EntryAliases` value object with `->entry`, `->state`, `->subscription`, `->tag` (all `string`) and static `EntryAliases::primary()` = `(e, es, s, st)`, `EntryAliases::collapse()` = `(e2, es2, s2, st2)`.
- Produces: `UnreadDql::predicate(?EntryAliases $aliases = null): string` — unchanged output for the default (primary) aliases; alias-substituted output otherwise.

- [ ] **Step 1: Write the failing test**

Create `tests/Repository/UnreadDqlTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Repository\EntryAliases;
use App\Repository\UnreadDql;
use PHPUnit\Framework\TestCase;

final class UnreadDqlTest extends TestCase
{
    public function testDefaultAliasesMatchThePrimaryQuery(): void
    {
        self::assertSame(
            'es.isHidden = :notHidden OR (es.isHidden IS NULL AND '
            . '(s.markedReadUntil IS NULL OR e.effectiveDate > s.markedReadUntil))',
            UnreadDql::predicate(),
        );
    }

    public function testCollapseAliasesRewriteEveryReference(): void
    {
        self::assertSame(
            'es2.isHidden = :notHidden OR (es2.isHidden IS NULL AND '
            . '(s2.markedReadUntil IS NULL OR e2.effectiveDate > s2.markedReadUntil))',
            UnreadDql::predicate(EntryAliases::collapse()),
        );
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php bin/phpunit tests/Repository/UnreadDqlTest.php`
Expected: FAIL — `EntryAliases` not found / `predicate()` takes no argument.

- [ ] **Step 3: Add `EntryAliases`**

Create `src/Repository/EntryAliases.php`:

```php
<?php

declare(strict_types=1);

namespace App\Repository;

/**
 * The four DQL aliases the entry-list scope predicates read. Two fixed sets:
 * the primary query (e/es/s/st) and the duplicate-collapse semi-join
 * (e2/es2/s2/st2), so one scope definition serves the outer query and the
 * NOT EXISTS that hides its lower-id copies.
 */
final readonly class EntryAliases
{
    public function __construct(
        public string $entry,
        public string $state,
        public string $subscription,
        public string $tag,
    ) {
    }

    public static function primary(): self
    {
        return new self('e', 'es', 's', 'st');
    }

    public static function collapse(): self
    {
        return new self('e2', 'es2', 's2', 'st2');
    }
}
```

- [ ] **Step 4: Make `UnreadDql` alias-aware**

Replace the body of `src/Repository/UnreadDql.php`'s `predicate()`:

```php
public static function predicate(?EntryAliases $aliases = null): string
{
    $alias = $aliases ?? EntryAliases::primary();

    return \sprintf(
        '%1$s.isHidden = :notHidden OR (%1$s.isHidden IS NULL AND '
        . '(%2$s.markedReadUntil IS NULL OR %3$s.effectiveDate > %2$s.markedReadUntil))',
        $alias->state,
        $alias->subscription,
        $alias->entry,
    );
}
```

Update the class docblock: the aliases are no longer "fixed" but default to the primary set.

- [ ] **Step 5: Run the tests to verify they pass**

Run: `php bin/phpunit tests/Repository/UnreadDqlTest.php`
Expected: PASS.

- [ ] **Step 6: Verify the three existing callers are unaffected**

Run: `php bin/phpunit tests/Repository/EntryListTest.php tests/Repository/UnreadCountsTest.php`
Expected: PASS — the no-argument `UnreadDql::predicate()` still emits the original string.

- [ ] **Step 7: Commit**

```bash
git add src/Repository/EntryAliases.php src/Repository/UnreadDql.php tests/Repository/UnreadDqlTest.php
git commit -m "refactor(#496): make UnreadDql alias-aware and add EntryAliases"
```

---

### Task A3: Extract `EntryScopePredicates`; make the term builder alias-aware

Pure refactor of the list/search scope out of `EntryListRepository`, plus threading an entry alias through `SearchTermsPredicateBuilder`. No behaviour change — proven by the existing repository tests staying green.

**Files:**
- Create: `src/Repository/EntryScopePredicates.php`
- Modify: `src/Repository/SearchTermsPredicateBuilder.php` (add `string $entryAlias = 'e'`)
- Modify: `src/Repository/EntryListRepository.php` (delegate to the collaborator)
- Test: existing `tests/Repository/EntryListTest.php`, `tests/Repository/EntrySearchTest.php`

**Interfaces:**
- Produces: `EntryScopePredicates` (injectable, stateless) with:
  - `applyList(QueryBuilder $qb, EntryAliases $a, EntryQuery $query): void`
  - `applySearch(QueryBuilder $qb, EntryAliases $a, EntrySearchQuery $query): void`
  - `applyIds(QueryBuilder $qb, EntryAliases $a, array $entryIds): void`
- Consumes: `SearchTermsPredicateBuilder::build(QueryBuilder, SearchTerms, string $prefix, string $entryAlias = 'e'): string`.

- [ ] **Step 1: Thread an entry alias through `SearchTermsPredicateBuilder`**

In `src/Repository/SearchTermsPredicateBuilder.php`, add a trailing `string $entryAlias = 'e'` parameter to `build()`, `substringPredicate()`, `wholeWordPredicate()`, and `wholeWordColumnPredicate()`, and replace the hard-coded `e.title` / `e.summary` with `sprintf('%s.title', $entryAlias)` / `sprintf('%s.summary', $entryAlias)`. Pass `$entryAlias` down from `build()` into the private helpers.

- [ ] **Step 2: Run the existing search tests to confirm no behaviour change**

Run: `php bin/phpunit tests/Repository/EntrySearchTest.php`
Expected: PASS — the default `'e'` reproduces the old predicate.

- [ ] **Step 3: Create `EntryScopePredicates`**

Create `src/Repository/EntryScopePredicates.php`:

```php
<?php

declare(strict_types=1);

namespace App\Repository;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\QueryBuilder;

/**
 * The entry-list scope filters, applied to a given EntryAliases set so the
 * primary query and the duplicate-collapse semi-join share one definition.
 * Holds no query state; the caller passes the aliases and the query.
 */
final readonly class EntryScopePredicates
{
    public function __construct(private SearchTermsPredicateBuilder $terms)
    {
    }

    public function applyList(QueryBuilder $qb, EntryAliases $a, EntryQuery $query): void
    {
        if ($query->subscriptionId !== null) {
            $qb->andWhere(\sprintf('%s.id = :sid', $a->subscription))
                ->setParameter('sid', $query->subscriptionId);
        }
        if ($query->tagId !== null) {
            $qb->innerJoin(
                \sprintf('%s.subscriptionTags', $a->subscription),
                $a->tag,
                'WITH',
                \sprintf('IDENTITY(%s.tag) = :tagId', $a->tag),
            )->setParameter('tagId', $query->tagId);
        }
        if ($query->hidesExcludedFeeds()) {
            $qb->andWhere(\sprintf('%s.includeInAllItems = true', $a->subscription));
        }
        $this->applyView($qb, $a, $query->view);
    }

    public function applySearch(QueryBuilder $qb, EntryAliases $a, EntrySearchQuery $query): void
    {
        $qb->andWhere($this->terms->build($qb, $query->terms, 'term', $a->entry));
        if ($query->unread) {
            $this->unread($qb, $a);
        }
    }

    /**
     * @param list<int> $entryIds
     */
    public function applyIds(QueryBuilder $qb, EntryAliases $a, array $entryIds): void
    {
        $qb->andWhere(\sprintf('%s.id IN (:ids)', $a->entry))->setParameter('ids', $entryIds);
    }

    private function applyView(QueryBuilder $qb, EntryAliases $a, string $view): void
    {
        switch ($view) {
            case 'unread':
                $this->unread($qb, $a);
                break;
            case 'favorites':
                $qb->andWhere(\sprintf('%s.isFavorite = :flag', $a->state))
                    ->setParameter('flag', true, Types::BOOLEAN);
                break;
            case 'kept':
                $qb->andWhere(\sprintf('%s.isKept = :flag', $a->state))
                    ->setParameter('flag', true, Types::BOOLEAN);
                break;
            case 'viewed':
                $qb->andWhere(\sprintf('%s.isViewed = :flag', $a->state))
                    ->setParameter('flag', true, Types::BOOLEAN);
                break;
            default:
                break;
        }
    }

    private function unread(QueryBuilder $qb, EntryAliases $a): void
    {
        $qb->andWhere(UnreadDql::predicate($a))->setParameter('notHidden', false, Types::BOOLEAN);
    }
}
```

- [ ] **Step 4: Delegate from `EntryListRepository` to the collaborator**

In `src/Repository/EntryListRepository.php`:
- Add `private readonly EntryScopePredicates $scope` to the constructor (Symfony autowires it).
- In `listForUser`, replace the inline subscription/tag/excluded blocks and the `$this->applyView(...)` call with `$this->scope->applyList($qb, EntryAliases::primary(), $query);` (keep the `$sort`/`orderedBy`/`setMaxResults` and `applyCursor` calls).
- In `searchForUser`, replace `$this->applyTerms(...)` and the `if ($query->unread) { $this->applyUnreadFilter($qb); }` with `$this->scope->applySearch($qb, EntryAliases::primary(), $query);`.
- Delete the now-unused private `applyView`, `applyTerms`, `applyUnreadFilter` methods. (`unreadMatchQueryBuilder` still needs term matching — replace its `$this->applyTerms($qb, $query->terms)` with `$qb->andWhere($this->termsPredicateBuilder->build($qb, $query->terms, 'term'));`, keeping the existing `termsPredicateBuilder` dependency.)

- [ ] **Step 5: Run the full list/search suite to confirm no behaviour change**

Run: `php bin/phpunit tests/Repository/EntryListTest.php tests/Repository/EntrySearchTest.php tests/Repository/EntryListRepositoryDigestTest.php`
Expected: PASS — identical behaviour, scope now lives in the collaborator.

- [ ] **Step 6: Lint the touched files**

Run: `composer cs && composer stan && composer md`
Expected: clean.

- [ ] **Step 7: Commit**

```bash
git add src/Repository/EntryScopePredicates.php src/Repository/SearchTermsPredicateBuilder.php src/Repository/EntryListRepository.php
git commit -m "refactor(#496): extract EntryScopePredicates keyed by alias set"
```

---

### Task A4: `DuplicateCollapseDql` and collapse in `listForUser`

**Files:**
- Create: `src/Repository/DuplicateCollapseDql.php`
- Modify: `src/Repository/EntryListRepository.php`
- Test: `tests/Repository/DuplicateCollapseTest.php`

**Interfaces:**
- Produces: `DuplicateCollapseDql::apply(QueryBuilder $qb, callable $applyScope, int $userId): void` where `$applyScope` is `callable(QueryBuilder $qb, EntryAliases $aliases): void`. ANDs `e.urlHash IS NULL OR NOT EXISTS(...)` onto `$qb`.
- Produces: `DuplicateCollapseDql::fragment(string $innerScope): string` — the same predicate as a raw DQL string (for the count path's hand-written query), correlating on the outer `e`.

- [ ] **Step 1: Write the failing test**

Create `tests/Repository/DuplicateCollapseTest.php`. It extends `DbTestCase` and seeds two feeds both subscribed by one user, with the same `urlHash` on two entries. Assert `listForUser` returns the lower-id copy only. (Helper `dupPair()` seeds a two-feed group with a shared `urlHash`.)

```php
<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\Entry;
use App\Entity\EntryState;
use App\Entity\Feed;
use App\Entity\Subscription;
use App\Entity\User;
use App\Repository\EntryListRepository;
use App\Repository\EntryQuery;
use App\Tests\DbTestCase;

final class DuplicateCollapseTest extends DbTestCase
{
    private User $user;
    private Feed $feedA;
    private Feed $feedB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = new User('dupe@example.com', new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $this->em->persist($this->user);
        $this->feedA = $this->feed('https://a.example/feed.xml', 'Feed A');
        $this->feedB = $this->feed('https://b.example/feed.xml', 'Feed B');
        $this->subscribe($this->user, $this->feedA);
        $this->subscribe($this->user, $this->feedB);
        $this->em->flush();
    }

    public function testCrossFeedDuplicateCollapsesToTheLowerId(): void
    {
        $lower = $this->entry($this->feedA, 'a-guid', 'https://tagesschau.de/x', 'urlhash-x', '2026-07-05T09:00:00Z');
        $higher = $this->entry($this->feedB, 'b-guid', 'https://tagesschau.de/x', 'urlhash-x', '2026-07-05T10:00:00Z');
        $this->em->flush();

        $rows = $this->repo()->listForUser(new EntryQuery((int) $this->user->getId(), view: 'all'));

        self::assertCount(1, $rows);
        self::assertSame($lower->getId(), $rows[0]->entry->getId());
        self::assertNotSame($higher->getId(), $rows[0]->entry->getId());
    }

    public function testAUserOnOnlyOneSideSeesTheirOwnCopy(): void
    {
        $solo = new User('solo@example.com', new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $this->em->persist($solo);
        $this->subscribe($solo, $this->feedB);
        $this->entry($this->feedA, 'a-guid', 'https://tagesschau.de/x', 'urlhash-x', '2026-07-05T09:00:00Z');
        $onlyCopy = $this->entry($this->feedB, 'b-guid', 'https://tagesschau.de/x', 'urlhash-x', '2026-07-05T10:00:00Z');
        $this->em->flush();

        $rows = $this->repo()->listForUser(new EntryQuery((int) $solo->getId(), view: 'all'));

        self::assertCount(1, $rows);
        self::assertSame($onlyCopy->getId(), $rows[0]->entry->getId());
    }

    public function testUnreadViewSurvivorIsTheUnreadCopyWhenTheLowerIdIsRead(): void
    {
        $lower = $this->entry($this->feedA, 'a-guid', 'https://tagesschau.de/x', 'urlhash-x', '2026-07-05T09:00:00Z');
        $higher = $this->entry($this->feedB, 'b-guid', 'https://tagesschau.de/x', 'urlhash-x', '2026-07-05T10:00:00Z');
        $read = new EntryState($this->user, $lower);
        $read->setIsHidden(true);
        $this->em->persist($read);
        $this->em->flush();

        $rows = $this->repo()->listForUser(new EntryQuery((int) $this->user->getId(), view: 'unread'));

        self::assertCount(1, $rows);
        self::assertSame($higher->getId(), $rows[0]->entry->getId());
    }

    public function testNullUrlHashNeverGroups(): void
    {
        $one = $this->entry($this->feedA, 'a-guid', null, null, '2026-07-05T09:00:00Z');
        $two = $this->entry($this->feedB, 'b-guid', null, null, '2026-07-05T10:00:00Z');
        $this->em->flush();

        $rows = $this->repo()->listForUser(new EntryQuery((int) $this->user->getId(), view: 'all'));

        self::assertCount(2, $rows);
        $ids = array_map(static fn ($r) => $r->entry->getId(), $rows);
        self::assertContains($one->getId(), $ids);
        self::assertContains($two->getId(), $ids);
    }

    private function repo(): EntryListRepository
    {
        $repo = self::getContainer()->get(EntryListRepository::class);
        self::assertInstanceOf(EntryListRepository::class, $repo);

        return $repo;
    }

    private function feed(string $url, string $title): Feed
    {
        $feed = new Feed($url);
        $feed->setTitle($title);
        $this->em->persist($feed);

        return $feed;
    }

    private function subscribe(User $user, Feed $feed): Subscription
    {
        $sub = new Subscription($user, $feed, new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $this->em->persist($sub);

        return $sub;
    }

    private function entry(Feed $feed, string $guid, ?string $url, ?string $urlHash, string $effective): Entry
    {
        $at = new \DateTimeImmutable($effective);
        $entry = new Entry($feed, $guid, $url, 'Title ' . $guid, $at, $at, $urlHash);
        $this->em->persist($entry);

        return $entry;
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php bin/phpunit tests/Repository/DuplicateCollapseTest.php`
Expected: FAIL — the two-feed group returns two rows (collapse not implemented).

- [ ] **Step 3: Create `DuplicateCollapseDql`**

Create `src/Repository/DuplicateCollapseDql.php`:

```php
<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Entry;
use App\Entity\EntryState;
use App\Entity\Subscription;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;

/**
 * "Hide every copy but the lowest-id one that is itself in scope." A plain WHERE
 * predicate, so it composes with the keyset cursor and a page still returns
 * `limit` visible rows. The scope is applied to the e2/es2/s2 alias set through
 * the SAME callback the outer query used, so filtering by tag or view scopes the
 * survivor too — see the spec for why carrying the filters prevents holes.
 */
final readonly class DuplicateCollapseDql
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    /**
     * @param callable(QueryBuilder, EntryAliases): void $applyScope
     */
    public function apply(QueryBuilder $qb, callable $applyScope, int $userId): void
    {
        $inner = $this->entityManager->createQueryBuilder()
            ->select('1')
            ->from(Entry::class, 'e2')
            ->join(Subscription::class, 's2', 'WITH', 's2.feed = e2.feed AND s2.user = :user')
            ->leftJoin(EntryState::class, 'es2', 'WITH', 'es2.entry = e2 AND es2.user = :user')
            ->andWhere('e2.urlHash = e.urlHash')
            ->andWhere('e2.id < e.id');
        $applyScope($inner, EntryAliases::collapse());

        $qb->andWhere('e.urlHash IS NULL OR NOT EXISTS (' . $inner->getDQL() . ')')
            ->setParameter('user', $userId);
        foreach ($inner->getParameters() as $parameter) {
            $qb->setParameter($parameter->getName(), $parameter->getValue(), $parameter->getType());
        }
    }

    public function fragment(string $innerScope): string
    {
        $exists = 'SELECT 1 FROM ' . Entry::class . ' e2'
            . ' JOIN ' . Subscription::class . ' s2 WITH s2.feed = e2.feed AND s2.user = :user'
            . ' LEFT JOIN ' . EntryState::class . ' es2 WITH es2.entry = e2 AND es2.user = :user'
            . ' WHERE e2.urlHash = e.urlHash AND e2.id < e.id';
        if ($innerScope !== '') {
            $exists .= ' AND (' . $innerScope . ')';
        }

        return 'e.urlHash IS NULL OR NOT EXISTS (' . $exists . ')';
    }
}
```

- [ ] **Step 4: Apply the collapse in `listForUser`**

In `src/Repository/EntryListRepository.php`:
- Add `private readonly DuplicateCollapseDql $collapse` to the constructor.
- In `listForUser`, build the scope once and reuse it for the outer filter and the collapse:

```php
public function listForUser(EntryQuery $query): array
{
    $sort = EntryListSort::forView($query->view);
    $applyScope = fn (QueryBuilder $qb, EntryAliases $aliases): mixed
        => $this->scope->applyList($qb, $aliases, $query);

    $qb = $this->orderedBy($this->rowQueryBuilder($query->userId), $sort)
        ->setMaxResults($query->limit);
    $applyScope($qb, EntryAliases::primary());
    $this->collapse->apply($qb, $applyScope, $query->userId);
    $this->applyCursor($qb, $query->cursor, $sort);

    /** @var list<array<array-key, mixed>> $rows */
    $rows = $qb->getQuery()->getResult();

    return array_map(fn (array $row): EntryListRow => $this->rowHydrator->hydrate($row), $rows);
}
```

(The `fn` returns `mixed` only because the arrow body is an expression; the callback's contract is `void`. If PHPStan objects, use a multi-line closure with an explicit `: void`.)

- [ ] **Step 5: Run the collapse test to verify it passes**

Run: `php bin/phpunit tests/Repository/DuplicateCollapseTest.php`
Expected: PASS — all four cases.

- [ ] **Step 6: Run the existing list suite for regressions**

Run: `php bin/phpunit tests/Repository/EntryListTest.php`
Expected: PASS — single-feed lists (no shared `urlHash`) are unchanged; keyset paging still returns `limit` rows.

- [ ] **Step 7: Commit**

```bash
git add src/Repository/DuplicateCollapseDql.php src/Repository/EntryListRepository.php tests/Repository/DuplicateCollapseTest.php
git commit -m "feat(#496): collapse cross-feed duplicates in the entry list"
```

---

### Task A5: Collapse in `searchForUser` and `rowsByIdsForUser`

**Files:**
- Modify: `src/Repository/EntryListRepository.php`
- Test: `tests/Repository/DuplicateCollapseTest.php` (add cases)

**Interfaces:**
- Consumes: `DuplicateCollapseDql::apply`, `EntryScopePredicates::applySearch` / `applyIds`.

- [ ] **Step 1: Write the failing tests**

Add to `tests/Repository/DuplicateCollapseTest.php`:

```php
public function testSearchGroupsWithinTheMatchedTermsOnly(): void
{
    // Same urlHash, but only the higher-id copy's title matches the search.
    $this->entry($this->feedA, 'a-guid', 'https://tagesschau.de/x', 'urlhash-x', '2026-07-05T09:00:00Z')
        ->setTitle('Lübecker Hauptbahnhof gesperrt');
    $match = $this->entry($this->feedB, 'b-guid', 'https://tagesschau.de/x', 'urlhash-x', '2026-07-05T10:00:00Z');
    $match->setTitle('Lübeck Zugausfälle am Wochenende');
    $this->em->flush();

    $rows = $this->repo()->searchForUser(new EntrySearchQuery(
        (int) $this->user->getId(),
        SearchTerms::fromQuery('Zugausfälle'),
    ));

    self::assertCount(1, $rows);
    self::assertSame($match->getId(), $rows[0]->entry->getId());
}

public function testRowsByIdsGroupsWithinTheGivenIdSet(): void
{
    $this->entry($this->feedA, 'a-guid', 'https://tagesschau.de/x', 'urlhash-x', '2026-07-05T09:00:00Z');
    $higher = $this->entry($this->feedB, 'b-guid', 'https://tagesschau.de/x', 'urlhash-x', '2026-07-05T10:00:00Z');
    $this->em->flush();

    // Meilisearch matched only the higher-id copy; the lower-id copy is not in
    // the set, so it must not win and delete the article from the results.
    $rows = $this->repo()->rowsByIdsForUser([(int) $higher->getId()], (int) $this->user->getId());

    self::assertCount(1, $rows);
    self::assertSame($higher->getId(), $rows[0]->entry->getId());
}
```

Add the imports `use App\Repository\EntrySearchQuery;` and `use App\Service\Search\SearchTerms;` (confirm `SearchTerms::fromQuery` is the factory the search tests use; match `tests/Repository/EntrySearchTest.php`).

- [ ] **Step 2: Run to verify failure**

Run: `php bin/phpunit tests/Repository/DuplicateCollapseTest.php`
Expected: FAIL — search returns the lower-id non-matching copy count wrong; rowsByIds returns 0.

- [ ] **Step 3: Apply the collapse in both methods**

In `searchForUser`, after `applySearch` on the outer query, add the collapse with the same scope callback:

```php
$applyScope = fn (QueryBuilder $qb, EntryAliases $aliases): void
    => $this->scope->applySearch($qb, $aliases, $query);
$qb = $this->newestFirst($this->rowQueryBuilder($query->userId))->setMaxResults($query->limit);
$applyScope($qb, EntryAliases::primary());
$this->collapse->apply($qb, $applyScope, $query->userId);
$this->applyCursor($qb, $query->cursor, EntryListSort::PublishedDate);
```

In `rowsByIdsForUser`, wrap the id filter in the scope and add the collapse:

```php
$applyScope = fn (QueryBuilder $qb, EntryAliases $aliases): void
    => $this->scope->applyIds($qb, $aliases, $entryIds);
$rowQuery = $this->newestFirst($this->rowQueryBuilder($userId));
$applyScope($rowQuery, EntryAliases::primary());
$this->collapse->apply($rowQuery, $applyScope, $userId);
if ($limit !== null) {
    $rowQuery->setMaxResults($limit);
}
```

(Remove the old inline `->andWhere('e.id IN (:ids)')` in `rowsByIdsForUser`; `applyIds` now owns it.)

- [ ] **Step 4: Run to verify pass**

Run: `php bin/phpunit tests/Repository/DuplicateCollapseTest.php tests/Repository/EntrySearchTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add src/Repository/EntryListRepository.php tests/Repository/DuplicateCollapseTest.php
git commit -m "feat(#496): collapse duplicates in search and by-ids hydration"
```

---

### Task A6: Collapse in `unreadCountsForUser`

**Files:**
- Modify: `src/Repository/EntryStateRepository.php:218-243`
- Test: `tests/Repository/UnreadCountsTest.php`

- [ ] **Step 1: Write the failing test**

Add to `tests/Repository/UnreadCountsTest.php` a test seeding two subscribed feeds with a shared-`urlHash` unread pair, asserting the two subscriptions' counts sum to 1, not 2 (the lower-id survivor counts in its own subscription; the hidden copy does not). Follow the file's existing seeding helpers. Skeleton:

```php
public function testCrossFeedDuplicateCountsOnceAcrossSubscriptions(): void
{
    // feedA/subA lower id, feedB/subB higher id, same urlHash, both unread.
    // ... seed via this file's helpers ...
    $counts = $this->repo()->unreadCountsForUser((int) $user->getId());
    self::assertSame(1, ($counts[$subAId] ?? 0) + ($counts[$subBId] ?? 0));
    self::assertSame(1, $counts[$subAId] ?? 0); // survivor is the lower-id copy
}
```

- [ ] **Step 2: Run to verify failure**

Run: `php bin/phpunit tests/Repository/UnreadCountsTest.php`
Expected: FAIL — the sum is 2.

- [ ] **Step 3: Add the collapse fragment to the count query**

In `EntryStateRepository::unreadCountsForUser`, inject `DuplicateCollapseDql $collapse` (constructor) and extend the WHERE. The inner scope for counts is "subscribed + unread", i.e. `UnreadDql::predicate(EntryAliases::collapse())`:

```php
$collapse = $this->collapse->fragment(UnreadDql::predicate(EntryAliases::collapse()));
$rows = $this->getEntityManager()->createQuery(sprintf(
    'SELECT s.id AS subscriptionId, COUNT(e.id) AS unreadCount
     FROM %s s
     JOIN %s e ON e.feed = s.feed
     LEFT JOIN %s es ON es.entry = e AND es.user = s.user
     WHERE s.user = :user AND (%s) AND (%s)
     GROUP BY s.id',
    Subscription::class,
    Entry::class,
    EntryState::class,
    UnreadDql::predicate(),
    $collapse,
))
    ->setParameter('user', $userId)
    ->setParameter('notHidden', false, Types::BOOLEAN)
    ->getResult();
```

(`:user` and `:notHidden` are already bound and shared by the inner scope; no new parameters.)

- [ ] **Step 4: Run to verify pass**

Run: `php bin/phpunit tests/Repository/UnreadCountsTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add src/Repository/EntryStateRepository.php tests/Repository/UnreadCountsTest.php
git commit -m "feat(#496): count cross-feed duplicates once in the sidebar badges"
```

---

### Task A7: Collapse in `RecommendationCandidateLoader::load`

**Files:**
- Modify: `src/Service/Recommendation/RecommendationCandidateLoader.php`
- Test: `tests/Service/Recommendation/RecommendationCandidateLoaderTest.php`

- [ ] **Step 1: Write the failing test**

Add a test that seeds two For-You feeds with a shared-`urlHash` pair inside the window and asserts the loaded pool contains one `PromptLine` for the group (the lower-id entry). Follow the file's existing seeding.

```php
public function testCrossFeedDuplicateOfferedOnce(): void
{
    // two includeInForYou feeds, same urlHash, both within the since window
    $lines = $this->loader()->load((int) $user->getId(), new CandidatePoolRequest(
        since: new \DateTimeImmutable('2026-07-01T00:00:00Z'),
        poolSize: 50,
        orderSeed: 1,
    ));
    $ids = array_map(static fn (PromptLine $l) => $l->entryId, $lines);
    self::assertSame([$lowerId], array_values(array_unique(array_filter($ids, fn ($id) => in_array($id, [$lowerId, $higherId], true)))));
}
```

(Match `CandidatePoolRequest`'s real constructor signature from the file.)

- [ ] **Step 2: Run to verify failure**

Run: `php bin/phpunit tests/Service/Recommendation/RecommendationCandidateLoaderTest.php`
Expected: FAIL — both copies are offered.

- [ ] **Step 3: Apply the collapse in `load`**

Inject `DuplicateCollapseDql $collapse` into the loader. In `load`, after the existing `andWhere` filters, add the collapse using a scope callback that mirrors the pool's scope (`includeInForYou`, the not-interacted ORs, the `since` window) onto the inner aliases:

```php
$innerScope = static function (QueryBuilder $inner, EntryAliases $aliases): void {
    $inner->andWhere(\sprintf('%s.includeInForYou = true', $aliases->subscription))
        ->andWhere(\sprintf(
            '(%1$s.isFavorite = :notInteracted OR %1$s.isFavorite IS NULL)'
            . ' AND (%1$s.isKept = :notInteracted OR %1$s.isKept IS NULL)'
            . ' AND (%1$s.isViewed = :notInteracted OR %1$s.isViewed IS NULL)',
            $aliases->state,
        ))
        ->andWhere(\sprintf('%s.effectiveDate >= :since', $aliases->entry));
};
$this->collapse->apply($qb, $innerScope, $userId);
```

Place this before `setMaxResults($request->poolSize)`. The `:notInteracted` and `:since` parameters are already bound on `$qb`.

- [ ] **Step 4: Run to verify pass**

Run: `php bin/phpunit tests/Service/Recommendation/RecommendationCandidateLoaderTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add src/Service/Recommendation/RecommendationCandidateLoader.php tests/Service/Recommendation/RecommendationCandidateLoaderTest.php
git commit -m "feat(#496): offer a cross-feed duplicate to the recommender once"
```

---

### Task A8: Provenance payload — `duplicates` on the row and in JSON

**Files:**
- Modify: `src/Repository/EntryListRow.php` (add trailing `duplicates`)
- Modify: `src/Repository/EntryListRepository.php` (enrichment for the three card paths)
- Modify: `src/Http/EntryJson.php` (serialize `duplicates`)
- Test: `tests/Repository/DuplicateCollapseTest.php`, `tests/Http/EntryJsonTest.php`

**Interfaces:**
- Produces: `EntryListRow::$duplicates` (`list<EntryListRow>`, default `[]`) and `EntryListRow::withDuplicates(list<EntryListRow>): self`.
- Produces: `EntryJson::one` output gains `duplicates: EntryDto[]` (siblings carry an empty `duplicates`).

- [ ] **Step 1: Write the failing tests**

Add to `tests/Repository/DuplicateCollapseTest.php`:

```php
public function testSurvivorCarriesTheInScopeHiddenCopy(): void
{
    $lower = $this->entry($this->feedA, 'a-guid', 'https://tagesschau.de/x', 'urlhash-x', '2026-07-05T09:00:00Z');
    $higher = $this->entry($this->feedB, 'b-guid', 'https://tagesschau.de/x', 'urlhash-x', '2026-07-05T10:00:00Z');
    $this->em->flush();

    $rows = $this->repo()->listForUser(new EntryQuery((int) $this->user->getId(), view: 'all'));

    self::assertCount(1, $rows);
    self::assertSame($lower->getId(), $rows[0]->entry->getId());
    self::assertCount(1, $rows[0]->duplicates);
    self::assertSame($higher->getId(), $rows[0]->duplicates[0]->entry->getId());
    self::assertSame([], $rows[0]->duplicates[0]->duplicates); // flattened
}

public function testUnreadViewFooterOmitsAReadCopy(): void
{
    $lower = $this->entry($this->feedA, 'a-guid', 'https://tagesschau.de/x', 'urlhash-x', '2026-07-05T09:00:00Z');
    $higher = $this->entry($this->feedB, 'b-guid', 'https://tagesschau.de/x', 'urlhash-x', '2026-07-05T10:00:00Z');
    $read = new EntryState($this->user, $higher);
    $read->setIsHidden(true);
    $this->em->persist($read);
    $this->em->flush();

    $rows = $this->repo()->listForUser(new EntryQuery((int) $this->user->getId(), view: 'unread'));

    self::assertCount(1, $rows);
    self::assertSame($lower->getId(), $rows[0]->entry->getId());
    self::assertSame([], $rows[0]->duplicates); // the read copy is out of unread scope
}
```

Add to `tests/Http/EntryJsonTest.php` a case that builds a survivor `EntryListRow` with one sibling via `withDuplicates` and asserts `EntryJson::one($row)['duplicates']` is a one-element list whose element has the sibling's `id` and an empty `duplicates`.

- [ ] **Step 2: Run to verify failure**

Run: `php bin/phpunit tests/Repository/DuplicateCollapseTest.php tests/Http/EntryJsonTest.php`
Expected: FAIL — `$rows[0]->duplicates` undefined; `EntryJson` has no `duplicates` key.

- [ ] **Step 3: Add `duplicates` to `EntryListRow`**

In `src/Repository/EntryListRow.php`, add a trailing constructor parameter (keeps all ~10 existing positional call sites valid):

```php
/** @var list<self> */
public array $duplicates = [],
```

and a helper:

```php
/**
 * @param list<self> $duplicates the in-scope copies this row collapsed
 */
public function withDuplicates(array $duplicates): self
{
    return new self(
        $this->entry,
        $this->subscriptionId,
        $this->subscriptionTitle,
        $this->isHidden,
        $this->isFavorite,
        $this->isKept,
        $this->isViewed,
        $this->viewedAt,
        $this->markedReadUntil,
        $duplicates,
    );
}
```

- [ ] **Step 4: Add the enrichment to the three card paths**

In `src/Repository/EntryListRepository.php`, add a private method and call it from `listForUser`, `searchForUser`, `rowsByIdsForUser` on their hydrated rows, passing each path's own `$applyScope` and userId:

```php
/**
 * Attach to each survivor the in-scope copies the collapse hid, so a card can
 * name them. One extra query per page over the same scope, minus the collapse
 * and the cursor.
 *
 * @param list<EntryListRow>                          $survivors
 * @param callable(QueryBuilder, EntryAliases): void  $applyScope
 *
 * @return list<EntryListRow>
 */
private function attachDuplicates(array $survivors, callable $applyScope, int $userId): array
{
    $hashes = [];
    $survivorIds = [];
    foreach ($survivors as $row) {
        $hash = $row->entry->getUrlHash();
        if ($hash !== null) {
            $hashes[$hash] = true;
            $survivorIds[] = (int) $row->entry->getId();
        }
    }
    if ($hashes === []) {
        return $survivors;
    }

    $qb = $this->rowQueryBuilder($userId);
    $applyScope($qb, EntryAliases::primary());
    $qb->andWhere('e.urlHash IN (:dupHashes)')
        ->andWhere('e.id NOT IN (:survivorIds)')
        ->setParameter('dupHashes', array_keys($hashes))
        ->setParameter('survivorIds', $survivorIds);

    /** @var list<array<array-key, mixed>> $rows */
    $rows = $qb->getQuery()->getResult();
    $byHash = [];
    foreach ($rows as $raw) {
        $sibling = $this->rowHydrator->hydrate($raw);
        $byHash[(string) $sibling->entry->getUrlHash()][] = $sibling;
    }

    return array_map(
        static function (EntryListRow $row) use ($byHash): EntryListRow {
            $hash = $row->entry->getUrlHash();

            return $hash !== null && isset($byHash[$hash])
                ? $row->withDuplicates($byHash[$hash])
                : $row;
        },
        $survivors,
    );
}
```

Then wrap each card path's `return array_map(...hydrate...)` so its result is passed through `attachDuplicates($rows, $applyScope, $userId)`. For example in `listForUser`:

```php
$survivors = array_map(fn (array $row): EntryListRow => $this->rowHydrator->hydrate($row), $rows);

return $this->attachDuplicates($survivors, $applyScope, $query->userId);
```

- [ ] **Step 5: Serialize `duplicates` in `EntryJson`**

In `src/Http/EntryJson.php`, add to the returned array:

```php
'duplicates' => array_map(self::one(...), $row->duplicates),
```

Update the method's return-shape docblock to list `duplicates`. Because siblings carry an empty `duplicates`, `self::one` on them yields `'duplicates' => []` — no unbounded recursion.

- [ ] **Step 6: Run to verify pass**

Run: `php bin/phpunit tests/Repository/DuplicateCollapseTest.php tests/Http/EntryJsonTest.php tests/Repository/EntryListTest.php`
Expected: PASS. `EntryListTest` proves non-duplicated lists carry `duplicates = []` and are otherwise unchanged.

- [ ] **Step 7: Add a controller-level assertion**

In `tests/Controller/Api/EntryControllerTest.php`, add a test that seeds a two-feed shared-`urlHash` pair for the authenticated user, requests `GET /api/entries`, and asserts the single returned entry has a one-element `duplicates` array naming the other feed's `source`. Follow the file's `auth()` + `seedFeedWithEntries` helpers (you will need a local helper to seed the second feed with the same `urlHash`).

Run: `php bin/phpunit tests/Controller/Api/EntryControllerTest.php`
Expected: PASS.

- [ ] **Step 8: Lint and commit**

```bash
composer cs && composer stan && composer md
git add src/Repository/EntryListRow.php src/Repository/EntryListRepository.php src/Http/EntryJson.php tests/
git commit -m "feat(#496): expose collapsed copies as EntryDto.duplicates"
```

---

### Task A9: `EntryStateUpdater` — mirror read/viewed; thin controller

**Files:**
- Create: `src/Service/Reader/EntryStateUpdater.php`
- Modify: `src/Controller/Api/EntryController.php:137-172`
- Test: `tests/Service/Reader/EntryStateUpdaterTest.php`, `tests/Controller/Api/EntryControllerTest.php`

**Interfaces:**
- Produces: `EntryStateUpdater::apply(User $user, EntryListRow $row, UpdateEntryStateRequest $request): EntryState` — applies the DTO to the target state, mirrors `isHidden`/`isViewed` onto subscribed siblings (same `urlHash`), leaves `isFavorite`/`isKept` local, flushes.

- [ ] **Step 1: Write the failing test**

Create `tests/Service/Reader/EntryStateUpdaterTest.php` extending `DbTestCase`. Seed one user subscribed to two feeds with a shared-`urlHash` pair. Resolve the survivor row and apply a request with `isHidden = true`. Assert both entries now have an `EntryState` with `isHidden = true`; then apply `isFavorite = true` to one and assert the sibling's `isFavorite` stays false.

```php
public function testReadMirrorsToTheSubscribedSibling(): void
{
    [$user, $target, $sibling] = $this->seedGroup();
    $row = $this->rows()->oneRowForUser((int) $target->getId(), (int) $user->getId());
    self::assertNotNull($row);

    $this->updater()->apply($user, $row, $this->request(isHidden: true));

    self::assertTrue($this->stateOf($user, $target)->isHidden());
    self::assertTrue($this->stateOf($user, $sibling)->isHidden());
}

public function testFavoriteDoesNotMirror(): void
{
    [$user, $target, $sibling] = $this->seedGroup();
    $row = $this->rows()->oneRowForUser((int) $target->getId(), (int) $user->getId());
    self::assertNotNull($row);

    $this->updater()->apply($user, $row, $this->request(isFavorite: true));

    self::assertTrue($this->stateOf($user, $target)->isFavorite());
    self::assertFalse($this->stateOf($user, $sibling)->isFavorite());
}
```

Provide the local helpers `seedGroup()`, `request(...)` (builds an `UpdateEntryStateRequest` — check its constructor/public props), `stateOf()`, `rows()` (the `EntryListRepository`), `updater()` (the service from the container).

- [ ] **Step 2: Run to verify failure**

Run: `php bin/phpunit tests/Service/Reader/EntryStateUpdaterTest.php`
Expected: FAIL — `EntryStateUpdater` not found.

- [ ] **Step 3: Create `EntryStateUpdater`**

Create `src/Service/Reader/EntryStateUpdater.php`. It applies the DTO, then for `isHidden`/`isViewed` loads the subscribed siblings (same `urlHash`, feed the user subscribes to, id ≠ target) via `EntryListRepository::rowsByIdsForUser` — no: `rowsByIdsForUser` collapses. Instead add a dedicated repository method `siblingRowsForUser(string $urlHash, int $excludeEntryId, int $userId): list<EntryListRow>` on `EntryListRepository` (uses `rowQueryBuilder`, `e.urlHash = :hash AND e.id <> :self`, no collapse) and resolve each through `EntryStateResolver`. Mirror the flags:

```php
final readonly class EntryStateUpdater
{
    public function __construct(
        private EntryStateResolver $states,
        private EntryListRepository $rows,
        private EntityManagerInterface $em,
        private ClockInterface $clock,
    ) {
    }

    public function apply(User $user, EntryListRow $row, UpdateEntryStateRequest $request): EntryState
    {
        $state = $this->states->resolve($user, $row);
        $this->applyTo($state, $request);
        $this->mirror($user, $row, $request);
        $this->em->flush();

        return $state;
    }

    private function applyTo(EntryState $state, UpdateEntryStateRequest $request): void
    {
        if ($request->isHidden !== null) {
            $request->isHidden ? $state->hide($this->clock->now()) : $state->markUnread();
        }
        if ($request->isFavorite !== null) {
            $state->setIsFavorite($request->isFavorite);
        }
        if ($request->isKept !== null) {
            $state->setIsKept($request->isKept);
        }
        if ($request->isViewed !== null) {
            $request->isViewed ? $state->markViewed($this->clock->now()) : $state->clearViewed();
        }
    }

    private function mirror(User $user, EntryListRow $row, UpdateEntryStateRequest $request): void
    {
        if ($request->isHidden === null && $request->isViewed === null) {
            return;
        }
        $hash = $row->entry->getUrlHash();
        if ($hash === null) {
            return;
        }
        foreach ($this->rows->siblingRowsForUser($hash, (int) $row->entry->getId(), (int) $user->getId()) as $siblingRow) {
            $sibling = $this->states->resolve($user, $siblingRow);
            if ($request->isHidden !== null) {
                $request->isHidden ? $sibling->hide($this->clock->now()) : $sibling->markUnread();
            }
            if ($request->isViewed !== null) {
                $request->isViewed ? $sibling->markViewed($this->clock->now()) : $sibling->clearViewed();
            }
        }
    }
}
```

Add `siblingRowsForUser` to `EntryListRepository`:

```php
/**
 * Every OTHER copy of the same article this caller subscribes to, as list
 * rows — the group a read/viewed mirror must reach. Not collapsed: the mirror
 * needs each copy, hidden or shown.
 *
 * @return list<EntryListRow>
 */
public function siblingRowsForUser(string $urlHash, int $excludeEntryId, int $userId): array
{
    /** @var list<array<array-key, mixed>> $rows */
    $rows = $this->rowQueryBuilder($userId)
        ->andWhere('e.urlHash = :hash')
        ->andWhere('e.id <> :self')
        ->setParameter('hash', $urlHash)
        ->setParameter('self', $excludeEntryId)
        ->getQuery()
        ->getResult();

    return array_map(fn (array $row): EntryListRow => $this->rowHydrator->hydrate($row), $rows);
}
```

- [ ] **Step 4: Run the service test to verify it passes**

Run: `php bin/phpunit tests/Service/Reader/EntryStateUpdaterTest.php`
Expected: PASS.

- [ ] **Step 5: Delegate from the controller**

In `src/Controller/Api/EntryController.php::updateState`, replace the four flag branches and the `flush()` with:

```php
$state = $this->entryStateUpdater->apply($user, $row, $request);
```

Swap the `EntryStateResolver`, `EntityManagerInterface`, `ClockInterface` constructor deps for `EntryStateUpdater $entryStateUpdater` **only if** they are unused elsewhere in the controller (they are not used by the other actions — confirm and remove the now-dead ones). Keep `oneRowForUser` as the IDOR gate.

- [ ] **Step 6: Verify the controller stays thin and the mirror works end-to-end**

Add to `tests/Controller/Api/EntryControllerTest.php` a test that PATCHes `isHidden: true` on the survivor of a seeded two-feed group and asserts the sibling's unread count drops (via the existing `unreadCountOf` helper) — i.e. the badge clears through the API.

Run:
```bash
php bin/phpunit tests/Controller/Api/EntryControllerTest.php
composer stan
```
Expected: PASS; `ThinControllerRule` reports no violation for `updateState`.

- [ ] **Step 7: Lint and commit**

```bash
composer cs && composer md
git add src/Service/Reader/EntryStateUpdater.php src/Repository/EntryListRepository.php src/Controller/Api/EntryController.php tests/
git commit -m "feat(#496): mirror read/viewed across a duplicate group"
```

---

### Task A10: Backend gate — mutation, whole suite, MySQL leg

- [ ] **Step 1: Run the whole backend suite (SQLite)**

Run: `php bin/phpunit`
Expected: PASS.

- [ ] **Step 2: Run the mutation gate over the branch**

Run: `composer infection:diff`
Expected: MSI ≥ 80 over touched files. Kill any escaped mutants the annotations report (add targeted assertions — e.g. asserting the *survivor id*, not just the row count, catches an `id < e.id` → `id <= e.id` mutant).

- [ ] **Step 3: Scan the dev log**

Run: `ls -t var/log/dev-*.log | head -1` then read that file for deprecations or swallowed errors from the run.

- [ ] **Step 4: Run the MySQL leg in Docker (from the repo root)**

Run:
```bash
docker compose exec php bin/console doctrine:migrations:migrate --no-interaction
docker compose exec php vendor/bin/phpunit tests/Repository/DuplicateCollapseTest.php tests/Service/Reader/EntryStateUpdaterTest.php
```
Expected: the new index migrates on MySQL and the collapse/mirror tests pass there.

- [ ] **Step 5: Commit any mutation-driven test additions**

```bash
git add tests/
git commit -m "test(#496): close infection gaps on the collapse predicate"
```

---

# Part B — Frontend

Part B renders `EntryDto.duplicates`. It depends on Part A shipping the field.

---

### Task B1: `EntryDto.duplicates` type and the `EntryDuplicatesComponent` footer

**Files:**
- Modify: `frontend/src/app/reader/models.ts:155-201` (add `duplicates?`)
- Create: `frontend/src/app/reader/magazine/entry-duplicates.component.ts`
- Create: `frontend/src/app/reader/magazine/entry-duplicates.component.html`
- Create: `frontend/src/app/reader/magazine/entry-duplicates.component.scss`
- Modify: `public/i18n/en.json`, `public/i18n/de.json` (add `reader.alsoIn`, `reader.alsoPublishedAs`)
- Test: `frontend/src/app/reader/magazine/entry-duplicates.component.spec.ts`

**Interfaces:**
- Produces: `EntryDuplicatesComponent`, selector `app-entry-duplicates`, input `entry: EntryDto` (required), outputs `open`/`favorite`/`keep`/`read` (`EntryDto`). Renders one chip per `entry().duplicates`; nothing when empty.

- [ ] **Step 1: Add the type field**

In `frontend/src/app/reader/models.ts`, add to `EntryDto`:

```ts
/** Other copies of this article the reader also subscribes to, in this
 *  list's scope. Set by the API's collapse; empty for a non-duplicated row. */
duplicates?: EntryDto[];
```

- [ ] **Step 2: Add the translation keys**

In `public/i18n/en.json` under `"reader"`:

```json
"alsoIn": "Also in",
"alsoPublishedAs": "Also published as",
```

In `public/i18n/de.json` under `"reader"`:

```json
"alsoIn": "Auch in",
"alsoPublishedAs": "Auch veröffentlicht als",
```

- [ ] **Step 3: Write the failing spec**

Create `frontend/src/app/reader/magazine/entry-duplicates.component.spec.ts`. Reuse the entry factory pattern from `entry-hero.component.spec.ts`.

```ts
import { TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
import { EntryDto } from '../models';
import { EntryDuplicatesComponent } from './entry-duplicates.component';
import { provideTranslocoTesting } from '../../../testing/transloco-testing';

const entry = (over: Partial<EntryDto> = {}): EntryDto => ({
  id: 1, title: 'Main', url: null, author: null, summary: null, contentHtml: null,
  imageUrl: null, imageWidth: null, imageHeight: null, media: [], attachments: [],
  publishedAt: '2026-07-05T09:00:00Z', createdAt: '2026-07-05T09:00:00Z',
  subscriptionId: 1, source: 'tagesschau', faviconUrl: null,
  isHidden: false, isFavorite: false, isKept: false, isViewed: false,
  ...over,
});

function mount(e: EntryDto) {
  TestBed.configureTestingModule({
    imports: [EntryDuplicatesComponent, provideTranslocoTesting()],
    providers: [provideRouter([])],
  });
  const f = TestBed.createComponent(EntryDuplicatesComponent);
  f.componentRef.setInput('entry', e);
  f.detectChanges();
  return f;
}

it('renders nothing without duplicates', () => {
  const el = mount(entry()).nativeElement as HTMLElement;
  expect(el.querySelector('.also-foot')).toBeNull();
});

it('renders one chip per duplicate with its source', () => {
  const dup = entry({ id: 2, source: 'NDR Schleswig-Holstein' });
  const el = mount(entry({ duplicates: [dup] })).nativeElement as HTMLElement;
  const chips = el.querySelectorAll('.also-entry');
  expect(chips.length).toBe(1);
  expect(chips[0].textContent).toContain('NDR Schleswig-Holstein');
});
```

- [ ] **Step 4: Run to verify failure**

Run: `docker compose exec -T frontend npm test -- entry-duplicates`
Expected: FAIL — component does not exist.

- [ ] **Step 5: Create the component (footer only for now)**

`entry-duplicates.component.ts`:

```ts
import { Component, computed, inject, input, output } from '@angular/core';
import { TranslocoPipe } from '@jsverse/transloco';
import { EntryDto } from '../models';
import { LanguageService } from '../../core/language.service';
import { relativeTime } from '../relative-time';

@Component({
  selector: 'app-entry-duplicates',
  imports: [TranslocoPipe],
  templateUrl: './entry-duplicates.component.html',
  styleUrl: './entry-duplicates.component.scss',
})
export class EntryDuplicatesComponent {
  readonly entry = input.required<EntryDto>();
  readonly open = output<EntryDto>();
  readonly favorite = output<EntryDto>();
  readonly keep = output<EntryDto>();
  readonly read = output<EntryDto>();

  private readonly language = inject(LanguageService);
  readonly copies = computed(() => this.entry().duplicates ?? []);
  readonly when = (copy: EntryDto): string =>
    relativeTime(copy.publishedAt ?? copy.createdAt, this.language.lang());
}
```

(Confirm the real import paths for `LanguageService` and `relativeTime` from `entry-block-base.ts`.)

`entry-duplicates.component.html`:

```html
@if (copies().length) {
  <div class="also-foot">
    <span class="layers" aria-hidden="true">⧉</span>
    <span class="lead">{{ 'reader.alsoIn' | transloco }}</span>
    @for (copy of copies(); track copy.id) {
      <button type="button" class="also-entry">
        {{ copy.source }} <span class="sep">·</span>
        <span class="t">{{ when(copy) }}</span>
      </button>
    }
  </div>
}
```

`entry-duplicates.component.scss` — use tokens only:

```scss
.also-foot {
  display: flex;
  align-items: center;
  gap: var(--space-2);
  flex-wrap: wrap;
  margin-top: var(--space-2);
  padding-top: var(--space-2);
  border-top: var(--hairline) dashed var(--card-border);
  font-size: var(--fs-xs);
  color: var(--text-muted);
}
.layers { color: var(--text-muted); }
.also-entry {
  display: inline-flex;
  align-items: center;
  gap: var(--space-1);
  padding: var(--space-1) var(--space-2);
  border: var(--hairline) solid transparent;
  border-radius: var(--radius-pill);
  background: var(--pill-bg);
  color: var(--text-secondary);
  font: inherit;
  cursor: pointer;
}
.also-entry:hover { border-color: var(--card-border); color: var(--text); }
.sep { color: var(--text-muted); }
.t { color: var(--text-muted); }
```

(Confirm each token exists in `src/app/theme`; if `--hairline` or `--pill-bg` is not defined, use the nearest existing token — `--card-border`, and `--pill-bg` may be a local `background: var(--surface-2)` per design-language.md. Do not introduce hex or px.)

- [ ] **Step 6: Run to verify pass**

Run: `docker compose exec -T frontend npm test -- entry-duplicates`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add frontend/src/app/reader/models.ts frontend/src/app/reader/magazine/entry-duplicates.component.* public/i18n/en.json public/i18n/de.json
git commit -m "feat(#496): entry-duplicates footer names other feeds"
```

---

### Task B2: The popover — open each copy's full card, clickable, with actions

**Files:**
- Modify: `entry-duplicates.component.ts/html/scss`
- Test: `entry-duplicates.component.spec.ts`

**Interfaces:**
- The popover hosts `app-entry-row` bound to the selected copy; its `open`/`favorite`/`keep`/`read` outputs re-emit from `EntryDuplicatesComponent` unchanged, so the shell's existing handlers act on the copy.

- [ ] **Step 1: Write the failing spec**

Add:

```ts
it('opens a popover with the copy card and re-emits open for that copy', () => {
  const dup = entry({ id: 2, title: 'NDR wording', source: 'NDR SH' });
  const f = mount(entry({ duplicates: [dup] }));
  const opened = jest.fn();
  f.componentInstance.open.subscribe(opened);

  (f.nativeElement.querySelector('.also-entry') as HTMLElement).click();
  f.detectChanges();
  const panel = f.nativeElement.querySelector('.dup-popover');
  expect(panel).not.toBeNull();
  expect(panel.textContent).toContain('NDR wording');

  (panel.querySelector('app-entry-row .row') as HTMLElement).click();
  expect(opened).toHaveBeenCalledWith(dup);
});
```

- [ ] **Step 2: Run to verify failure**

Run: `docker compose exec -T frontend npm test -- entry-duplicates`
Expected: FAIL — no `.dup-popover`.

- [ ] **Step 3: Add the popover**

Follow the `info-tip.component.ts` precedent (a click-toggled `position: fixed` panel anchored to the trigger's `getBoundingClientRect()`, dismissed with `DismissOnOutsideDirective`, "only one open at a time"). Track the selected copy in a signal; toggle it when a chip is clicked. In the template, render (guarded by `@if (selected(); as copy)`):

```html
<span class="dup-popover" [appDismissOnOutside]="!!selected()" (dismiss)="close()"
      [style.top.px]="panelTop()" [style.left.px]="panelLeft()">
  <span class="phd">{{ 'reader.alsoPublishedAs' | transloco }}</span>
  <app-entry-row [entry]="copy"
    (open)="open.emit($event)" (favorite)="favorite.emit($event)"
    (keep)="keep.emit($event)" (read)="read.emit($event)" />
</span>
```

Add `EntryRowComponent` and `DismissOnOutsideDirective` to the component `imports`. Give each chip a `(click)="toggle(copy, $event)"` that records the copy and the trigger rect. Keep all measurements in `.scss` tokens; the only inline styles are the computed `top`/`left` pixel offsets, which are runtime positioning, not design values.

Watch-out: if the list pane uses a CSS `transform` (drawer/animation), a `position: fixed` popover anchors to that ancestor. If a manual visual round shows misplacement, switch to the CDK `Dialog` + `overlay.position()` approach (`action-sheet.service.ts` precedent) instead. Decide during the visual round, not up front.

- [ ] **Step 4: Run to verify pass**

Run: `docker compose exec -T frontend npm test -- entry-duplicates`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add frontend/src/app/reader/magazine/entry-duplicates.component.*
git commit -m "feat(#496): duplicate copies open in a card popover"
```

---

### Task B3: Render the footer in every card

**Files:**
- Modify: each magazine block template that shows the meta line — `entry-hero.component.html`, `entry-wide.component.html`, `entry-split.component.html`, `entry-kicker.component.html`, `entry-thumb.component.html`, `entry-compact.component.html`, `entry-quote.component.html` (and `source-group.component.html` if it renders per-entry meta) — plus `entry-row/entry-row.component.html`
- Modify: each block's `.ts` `imports` to include `EntryDuplicatesComponent`
- Test: `entry-hero.component.spec.ts` (footer bubbling), `entry-row.component.spec.ts`

**Interfaces:**
- Consumes: `EntryDuplicatesComponent`. Each block re-emits its `open`/`favorite`/`keep`/`read` from the footer, identical to the block's own outputs — so the sibling flows to the same shell handler.

- [ ] **Step 1: Write the failing test (hero)**

Add to `entry-hero.component.spec.ts`:

```ts
it('bubbles open for a duplicate copy through the footer', () => {
  const dup = entry({ id: 9, source: 'NDR SH' });
  const f = mount(entry({ duplicates: [dup] }));
  const opened = jest.fn();
  f.componentInstance.open.subscribe(opened);
  (f.nativeElement.querySelector('app-entry-duplicates .also-entry') as HTMLElement).click();
  f.detectChanges();
  (f.nativeElement.querySelector('.dup-popover app-entry-row .row') as HTMLElement).click();
  expect(opened).toHaveBeenCalledWith(dup);
});
```

- [ ] **Step 2: Run to verify failure**

Run: `docker compose exec -T frontend npm test -- entry-hero`
Expected: FAIL — no `app-entry-duplicates` in the hero.

- [ ] **Step 3: Add the footer to each block**

In each block template, immediately after `<app-entry-meta ... />` (inside the card `.body`), add:

```html
<app-entry-duplicates [entry]="entry()"
  (open)="open.emit($event)" (favorite)="favorite.emit($event)"
  (keep)="keep.emit($event)" (read)="read.emit($event)" />
```

Add `EntryDuplicatesComponent` to each block's `imports`. For `entry-row`, place it inside `.body` after the `.tagline` div and wire the same four outputs (entry-row already declares them).

- [ ] **Step 4: Run to verify pass**

Run: `docker compose exec -T frontend npm test -- entry-hero entry-row`
Expected: PASS.

- [ ] **Step 5: Full frontend check**

Run: `docker compose exec -T frontend npm test`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add frontend/src/app/reader
git commit -m "feat(#496): show the duplicate footer on every card"
```

---

### Task B4: Whole-branch verification and visual rounds

- [ ] **Step 1: Frontend gate**

Run: `cd frontend && npm run check`
Expected: PASS (ESLint + Prettier + Stylelint + Jest). Fix any hex/px/inline-style findings.

- [ ] **Step 2: Backend gate**

Run: `cd backend && composer check && composer md && php bin/phpunit && composer infection:diff`
Expected: all clean; MSI ≥ 80.

- [ ] **Step 3: Bring up the stack and seed a known group**

Run from the repo root: `docker compose up -d`. Ensure the dev database has a two-feed shared-`urlHash` group visible to your account (the NDR/tagesschau case), or seed one.

- [ ] **Step 4: Visual round — per view**

With the browser (companion or the running app), verify on the account that subscribes to both sides:
- All items: one card with the "Also in ⟨feed⟩ · ⟨time⟩" footer; **magazine view** shows no gap or broken masonry across block types.
- Click a footer chip: the popover opens, correctly positioned, showing the other copy's card (and its different headline where applicable).
- Click the popover card: the reader opens that copy.
- Favorite/keep in the popover: acts on that copy only. Read: the whole group clears and the sidebar badge drops by one.
- Repeat in a tag list, a single-feed list, the unread view, and search. Confirm the unread view's footer omits an already-read copy.

- [ ] **Step 5: Scan the dev log**

Run: `ls -t backend/var/log/dev-*.log | head -1` and read it for errors from the manual round.

- [ ] **Step 6: Request code review**

Use the superpowers:requesting-code-review skill (or `/code-review`) against the branch before opening the PR.

- [ ] **Step 7: Open the PR**

```bash
git push -u origin feature/496-cross-feed-duplicate-collapse
```
Open a PR into `develop` whose body says `Closes #496`. After merge, verify the issue closed.

---

## Self-Review notes (author)

- **Spec coverage:** identity/index (A1), collapse + scope predicates (A2–A7), the five read paths (A4 list, A5 search + by-ids, A6 counts, A7 recommendation; `oneRowForUser` deliberately excluded), provenance payload (A8), state mirror + thin controller (A9), migration (A1), mark-all-read (unchanged — recorded in the spec, no code), frontend footer/popover (B1–B3), visual rounds (B4). All covered.
- **`EntryListRow.duplicates`** is a trailing optional param — the ~10 existing constructors (hydrator, `RecommendationItemRepository`, and test builders) keep compiling.
- **Naming consistency:** `applyScope` callback shape `(QueryBuilder, EntryAliases): void` is identical across `DuplicateCollapseDql::apply`, the three card paths, and `attachDuplicates`. `EntryAliases::primary()`/`collapse()` are the only two alias sets. `siblingRowsForUser` (mirror, uncollapsed) is distinct from `rowsByIdsForUser` (search hydration, collapsed).
- **Watch-out for the executor:** several test skeletons (A6, A7, A9) reference this-file helpers and DTO constructors (`CandidatePoolRequest`, `UpdateEntryStateRequest`, `SearchTerms::fromQuery`) whose exact shapes must be read from the codebase before filling in — match the neighbouring tests, do not invent signatures.
