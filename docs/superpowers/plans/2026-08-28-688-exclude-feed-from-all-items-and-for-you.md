# Exclude a feed from "All items" and from "For You" — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Give every subscription two independent per-feed switches — `includeInAllItems` and `includeInForYou` — that hide a kept feed from the reading flow and from recommendations without unsubscribing.

**Architecture:** Two boolean columns on `subscription`, both defaulting to `true`. The hiding rule has exactly one home per surface: an `EntryQuery::hidesExcludedFeeds()` predicate gates the All-items list; one predicate in the shared recommendation candidate builder gates new runs; one predicate in the shared For-You criteria gates the list and its badge; a filtered scope in `MarkReadService` keeps "mark all read" honest. Both flags round-trip through backup. The API extends the existing `UpdateSubscriptionRequest` with `?bool` fields where `null` means unchanged. The frontend adds two menu toggles (both sidebar branches), two dialog switches, a single muted `ti-eye-off` row marker when either flag is off, and drops excluded feeds from the All-items badge.

**Tech Stack:** Symfony 7.4 / PHP 8.4 / Doctrine (backend), Angular 20 standalone + signals (frontend), MySQL + SQLite, PHPUnit + Jest.

**Spec:** GitHub issue #688 (`gh issue view 688`). This plan is the design of record; the sections below record the deltas found while verifying the issue against the code.

## Global Constraints

- **Branch:** `feature/688-exclude-feed-from-all-items-and-for-you`, off `develop`. Commit messages use `type(#688): summary`.
- **PHP:** `declare(strict_types=1)` in every file; PSR-12; PHPStan level max; `composer md` clean on every touched `src` file; Clean Code (guard clauses, no boolean-flag parameters, intent-revealing names, thin controllers).
- **Frontend:** standalone components + signals; no hex or raw `px` in `.scss` outside `theme/`; component styles in a sibling `.scss` file; en/de i18n parity.
- **Datetimes:** naive UTC — not relevant here (no new datetime fields), but do not regress existing ones.
- **Native iOS:** JSON in, `application/problem+json` out, no browser-only input. The two `?bool` fields must be drivable by a plain PATCH.
- **Both flags default `true`.** A feed with no explicit choice behaves exactly as today.

## Decisions of record (deltas from the issue prose)

1. **Sidebar marker (visual round, user-approved):** ONE muted `ti-eye-off` glyph shows when `includeInAllItems === false` **or** `includeInForYou === false`. A `title` tooltip names the exact exclusions. It sits immediately left of the unread count and must **not** displace it (both render when a feed is excluded *and* has unread items). This is the first sidebar-row status marker — record the convention in `docs/design-language.md`.
2. **`ManageActions.retag()` does not send the full `SubscriptionDto`** — it sends `{ customTitle, tagIds }`. Because `customTitle`/`tagIds` clear on omission, the sidebar toggle must send those alongside the flag. The toggle action builds the body from the store's current sub (`customTitle`, current `tagIds`, both flags with one flipped).
3. **The For-You pager is `listForYou`, not `pageForYou`.** The predicate lands in the shared `applyForYouCriteria`, which both `listForYou` and `countForYou` use.
4. **`EntryQuery` has no predicate methods today.** `hidesExcludedFeeds()` is the first — it gets its own unit test.
5. **Backup is a full round-trip** (issue: "restored account must come back with its exclusions intact"). Both columns go into `SubscriptionLine`, the exporter, `RestoreLoadPass::loadSubscription`, and `BackupFieldDeclarations::BACKED_UP` — NOT the deferred `NOT_BACKED_UP` path #636 took for `includeInDigest`. Because the backup declaration and the entity column must land together to keep `BackupSchemaCoverageTest` green, Task 1 adds the columns and wires backup in one deliverable.

---

## File Structure

**Backend — create:**
- `backend/migrations/Version20260828NNNNNN.php` — adds both columns.

**Backend — modify:**
- `backend/src/Entity/Subscription.php` — two `bool` columns + accessors.
- `backend/src/Service/Backup/Dto/SubscriptionLine.php` — two `bool` fields + `fromLine`.
- `backend/src/Service/Backup/AccountBackupExporter.php` — `subscriptionLine()` emits both.
- `backend/src/Service/Backup/RestoreLoadPass.php` — `loadSubscription()` sets both.
- `backend/tests/Support/BackupFieldDeclarations.php` — declare both in `BACKED_UP[Subscription::class]`.
- `backend/src/Repository/EntryQuery.php` — `hidesExcludedFeeds(): bool`.
- `backend/src/Repository/EntryListRepository.php` — one predicate in `listForUser`.
- `backend/src/Service/Recommendation/RecommendationCandidateLoader.php` — predicate in `candidateQueryBuilder`.
- `backend/src/Repository/RecommendationItemRepository.php` — predicate in `applyForYouCriteria`.
- `backend/src/Service/Reader/MarkReadService.php` — filter the `all` scope.
- `backend/src/Dto/Subscription/UpdateSubscriptionRequest.php` — two `?bool` fields.
- `backend/src/Controller/Api/SubscriptionController.php` — apply the two flags on PATCH.
- `backend/src/Http/SubscriptionJson.php` — emit both flags.

**Frontend — modify:**
- `frontend/src/app/reader/models.ts` — `SubscriptionDto` + `SubscriptionUpdate` fields.
- `frontend/src/app/reader/subscriptions.store.ts` — `sumUnread` filters on `includeInAllItems`.
- `frontend/src/app/reader/manage/manage-actions.service.ts` — two toggle actions.
- `frontend/src/app/reader/sidebar/sidebar.component.ts` / `.html` / `.scss` — two outputs, menu items (both branches + coarse sheet), row marker.
- `frontend/src/app/reader/reader-shell.component.html` — wire the two new outputs to `ManageActions`.
- `frontend/src/app/reader/manage/edit-subscription-dialog.component.ts` / `.html` — two switches.
- `frontend/public/i18n/en.json` + `de.json` — strings.
- Icon registry (verify `eye-off` exists; add if missing).

**Docs:**
- `docs/design-language.md` — record the sidebar-row status marker.

---

## Task 1: Subscription columns + backup round-trip

**Files:**
- Modify: `backend/src/Entity/Subscription.php`
- Modify: `backend/src/Service/Backup/Dto/SubscriptionLine.php`
- Modify: `backend/src/Service/Backup/AccountBackupExporter.php:271-282`
- Modify: `backend/src/Service/Backup/RestoreLoadPass.php:163-181`
- Modify: `backend/tests/Support/BackupFieldDeclarations.php:77-84`
- Test: `backend/tests/Entity/SubscriptionTest.php` (create or extend), `backend/tests/Service/Backup/` round-trip test (extend the existing backup round-trip test)

**Interfaces:**
- Produces: `Subscription::isIncludeInAllItems(): bool`, `setIncludeInAllItems(bool): void`, `isIncludeInForYou(): bool`, `setIncludeInForYou(bool): void`. New defaults are `true`. `SubscriptionLine` constructor gains `bool $includeInAllItems, bool $includeInForYou` (append after `array $tags`, both required).

- [ ] **Step 1: Write the failing entity default test**

Create/extend `backend/tests/Entity/SubscriptionTest.php`:

```php
public function testANewSubscriptionIsIncludedEverywhereByDefault(): void
{
    $subscription = new Subscription($this->user(), $this->feed(), new \DateTimeImmutable());

    self::assertTrue($subscription->isIncludeInAllItems());
    self::assertTrue($subscription->isIncludeInForYou());
}

public function testExclusionFlagsCanBeToggled(): void
{
    $subscription = new Subscription($this->user(), $this->feed(), new \DateTimeImmutable());
    $subscription->setIncludeInAllItems(false);
    $subscription->setIncludeInForYou(false);

    self::assertFalse($subscription->isIncludeInAllItems());
    self::assertFalse($subscription->isIncludeInForYou());
}
```

(Reuse the module's existing helpers for building `User`/`Feed`; if none exist here, construct minimal instances the way the nearest entity test does.)

- [ ] **Step 2: Run to verify it fails**

Run: `cd backend && php bin/phpunit --filter SubscriptionTest`
Expected: FAIL — `Call to undefined method …isIncludeInAllItems()`.

- [ ] **Step 3: Add the two columns to `Subscription.php`**

Follow the `SavedSearch::$includeInDigest` precedent (explicit snake_case `name:`, `options: ['default' => …]`, PHP default). Place after `$position`:

```php
/** Whether this feed appears in "All items" and its unread badge (#688). */
#[ORM\Column(name: 'include_in_all_items', options: ['default' => true])]
private bool $includeInAllItems = true;

/** Whether this feed feeds the "For You" recommendation pool (#688). */
#[ORM\Column(name: 'include_in_for_you', options: ['default' => true])]
private bool $includeInForYou = true;
```

Add accessors next to the existing getters/setters:

```php
public function isIncludeInAllItems(): bool
{
    return $this->includeInAllItems;
}

public function setIncludeInAllItems(bool $includeInAllItems): void
{
    $this->includeInAllItems = $includeInAllItems;
}

public function isIncludeInForYou(): bool
{
    return $this->includeInForYou;
}

public function setIncludeInForYou(bool $includeInForYou): void
{
    $this->includeInForYou = $includeInForYou;
}
```

- [ ] **Step 4: Run the entity test — expect PASS**

Run: `cd backend && php bin/phpunit --filter SubscriptionTest`
Expected: PASS.

- [ ] **Step 5: Run `BackupSchemaCoverageTest` — expect FAIL (columns undeclared)**

Run: `cd backend && php bin/phpunit --filter BackupSchemaCoverageTest`
Expected: FAIL — every persisted field must carry a decision; the two new columns have none yet. This confirms the guard (#556) fired.

- [ ] **Step 6: Wire the backup round-trip**

`SubscriptionLine.php` — add both fields to the constructor and `fromLine`:

```php
public function __construct(
    public string $feedUrl,
    public ?string $customTitle,
    public int $position,
    public ?\DateTimeImmutable $markedReadUntil,
    public \DateTimeImmutable $createdAt,
    /** @var list<string> */
    public array $tags,
    public bool $includeInAllItems,
    public bool $includeInForYou,
) {}
```

In `fromLine`, read them via the same `LineField` helper the booleans need. Backup lines are string-keyed; use a boolean reader. If `LineField` has no `bool` helper, read the raw string and compare (`'1' === LineField::string($line, 'includeInAllItems')`), defaulting a MISSING key to `true` so older backups restore as included:

```php
includeInAllItems: LineField::boolOrDefault($line, 'includeInAllItems', true),
includeInForYou: LineField::boolOrDefault($line, 'includeInForYou', true),
```

If `LineField::boolOrDefault` does not exist, add it (mirror `stringOrNull`) — a small, tested helper is cleaner than an inline ternary duplicated twice.

`AccountBackupExporter.php::subscriptionLine()` — add to the returned array:

```php
'includeInAllItems' => $subscription->isIncludeInAllItems() ? '1' : '0',
'includeInForYou'   => $subscription->isIncludeInForYou() ? '1' : '0',
```

(Match the exporter's existing scalar formatting; if it emits raw bools elsewhere, follow that instead.)

`RestoreLoadPass.php::loadSubscription()` — after the existing setters:

```php
$subscription->setIncludeInAllItems($line->includeInAllItems);
$subscription->setIncludeInForYou($line->includeInForYou);
```

`BackupFieldDeclarations.php` — in `BACKED_UP[Subscription::class]` add:

```php
'includeInAllItems' => 'includeInAllItems',
'includeInForYou'   => 'includeInForYou',
```

- [ ] **Step 7: Write the failing round-trip assertion**

Extend the existing backup export→restore test (find it under `backend/tests/Service/Backup/`) so a subscription with both flags `false` restores with both `false`:

```php
$subscription->setIncludeInAllItems(false);
$subscription->setIncludeInForYou(false);
// … export, restore …
self::assertFalse($restored->isIncludeInAllItems());
self::assertFalse($restored->isIncludeInForYou());
```

- [ ] **Step 8: Run the backup suite — expect PASS**

Run: `cd backend && php bin/phpunit tests/Service/Backup`
Expected: PASS (coverage test green again, round-trip green).

- [ ] **Step 9: `composer cs:fix` and check the touched files**

Run: `cd backend && composer cs:fix && composer stan && composer md`
Expected: clean.

- [ ] **Step 10: Commit**

```bash
git add backend/src/Entity/Subscription.php backend/src/Service/Backup backend/tests
git commit -m "feat(#688): add subscription exclusion flags with backup round-trip"
```

---

## Task 2: Migration

**Files:**
- Create: `backend/migrations/Version20260828NNNNNN.php`

**Interfaces:**
- Consumes: the two columns from Task 1.
- Produces: `subscription.include_in_all_items` and `subscription.include_in_for_you`, `TINYINT(1) DEFAULT 1 NOT NULL` on MySQL, the SQLite equivalent.

- [ ] **Step 1: Generate the migration skeleton**

Run: `cd backend && bin/console cache:warmup && bin/console doctrine:migrations:generate`
This creates a `VersionYYYYMMDDHHMMSS.php`. Do NOT use `diff` against the dev DB (never touch it).

- [ ] **Step 2: Write `up()` and `down()`**

Mirror `migrations/Version20260828120000.php` (the `include_in_digest` precedent): keep `assertSupportedPlatform()` (MySQL + SQLite only). Both columns default to `1` (true):

```php
public function up(Schema $schema): void
{
    $this->assertSupportedPlatform();
    $this->addSql('ALTER TABLE subscription ADD include_in_all_items TINYINT(1) DEFAULT 1 NOT NULL');
    $this->addSql('ALTER TABLE subscription ADD include_in_for_you TINYINT(1) DEFAULT 1 NOT NULL');
}

public function down(Schema $schema): void
{
    $this->assertSupportedPlatform();
    $this->addSql('ALTER TABLE subscription DROP include_in_all_items');
    $this->addSql('ALTER TABLE subscription DROP include_in_for_you');
}
```

Copy `assertSupportedPlatform()` verbatim from the precedent migration. If the precedent emits separate SQLite SQL, follow that shape for both platforms.

- [ ] **Step 3: Apply from empty on SQLite in a scratch database and validate**

Do NOT run against the dev DB. Use a throwaway sqlite file:

```bash
cd backend && DATABASE_URL="sqlite:///%kernel.project_dir%/var/scratch-688.db" \
  bin/console doctrine:database:create && \
  DATABASE_URL="sqlite:///%kernel.project_dir%/var/scratch-688.db" \
  bin/console doctrine:migrations:migrate --no-interaction && \
  DATABASE_URL="sqlite:///%kernel.project_dir%/var/scratch-688.db" \
  bin/console doctrine:schema:validate
rm -f backend/var/scratch-688.db
```

Expected: migrations run clean; `doctrine:schema:validate` reports the mapping and database in sync.

- [ ] **Step 4: Apply from empty on MySQL (Docker) — the leg CI gates**

```bash
docker compose up -d
docker compose exec php bin/console doctrine:migrations:migrate --no-interaction
docker compose exec php bin/console doctrine:schema:validate
```

Expected: clean. (If the stack already has data, the migration adds the columns with the default in place — no data loss.)

- [ ] **Step 5: Commit**

```bash
git add backend/migrations/
git commit -m "feat(#688): migration for subscription exclusion flags"
```

---

## Task 3: `EntryQuery::hidesExcludedFeeds()`

**Files:**
- Modify: `backend/src/Repository/EntryQuery.php`
- Test: `backend/tests/Repository/EntryQueryTest.php` (create)

**Interfaces:**
- Produces: `EntryQuery::hidesExcludedFeeds(): bool` — `true` only when `view` is `all` or `unread` AND `subscriptionId === null` AND `tagId === null`.

- [ ] **Step 1: Write the failing unit test**

```php
final class EntryQueryTest extends TestCase
{
    public function testAllAndUnreadWithNoFeedOrTagHideExcludedFeeds(): void
    {
        self::assertTrue((new EntryQuery(1, 'all'))->hidesExcludedFeeds());
        self::assertTrue((new EntryQuery(1, 'unread'))->hidesExcludedFeeds());
    }

    public function testAFeedScopedViewNeverHides(): void
    {
        self::assertFalse((new EntryQuery(1, 'all', subscriptionId: 5))->hidesExcludedFeeds());
        self::assertFalse((new EntryQuery(1, 'unread', subscriptionId: 5))->hidesExcludedFeeds());
    }

    public function testATagScopedViewNeverHides(): void
    {
        self::assertFalse((new EntryQuery(1, 'all', tagId: 3))->hidesExcludedFeeds());
        self::assertFalse((new EntryQuery(1, 'unread', tagId: 3))->hidesExcludedFeeds());
    }

    public function testFavoritesKeptViewedAndForYouNeverHide(): void
    {
        foreach (['favorites', 'kept', 'viewed', 'for-you'] as $view) {
            self::assertFalse((new EntryQuery(1, $view))->hidesExcludedFeeds(), $view);
        }
    }
}
```

- [ ] **Step 2: Run — expect FAIL**

Run: `cd backend && php bin/phpunit --filter EntryQueryTest`
Expected: FAIL — undefined method.

- [ ] **Step 3: Implement the predicate**

```php
public function hidesExcludedFeeds(): bool
{
    if ($this->subscriptionId !== null || $this->tagId !== null) {
        return false;
    }

    return $this->view === 'all' || $this->view === 'unread';
}
```

- [ ] **Step 4: Run — expect PASS**

Run: `cd backend && php bin/phpunit --filter EntryQueryTest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add backend/src/Repository/EntryQuery.php backend/tests/Repository/EntryQueryTest.php
git commit -m "feat(#688): EntryQuery predicate for the All-items hiding rule"
```

---

## Task 4: `EntryListRepository::listForUser` hides excluded feeds

**Files:**
- Modify: `backend/src/Repository/EntryListRepository.php:49-74`
- Test: `backend/tests/Repository/EntryListRepositoryTest.php` (create or extend the nearest functional list test)

**Interfaces:**
- Consumes: `EntryQuery::hidesExcludedFeeds()`; the `s` alias already joined in `rowQueryBuilder`.

- [ ] **Step 1: Write the failing functional test**

Seed two subscriptions for one user, each with one unread entry; set feed B `includeInAllItems = false`; tag feed B with a tag; favorite one of B's entries. Assert:

```php
// All items: only A
$all = $repo->listForUser(new EntryQuery($userId, 'all'));
self::assertSame([$entryA->getId()], $this->ids($all));

// Unread: only A
$unread = $repo->listForUser(new EntryQuery($userId, 'unread'));
self::assertSame([$entryA->getId()], $this->ids($unread));

// B's own list: B present
$own = $repo->listForUser(new EntryQuery($userId, 'all', subscriptionId: $subB->getId()));
self::assertContains($entryB->getId(), $this->ids($own));

// B's tag list: B present
$tagged = $repo->listForUser(new EntryQuery($userId, 'all', tagId: $tag->getId()));
self::assertContains($entryB->getId(), $this->ids($tagged));

// Favorites: B present
$favs = $repo->listForUser(new EntryQuery($userId, 'favorites'));
self::assertContains($favEntryOfB->getId(), $this->ids($favs));
```

(Follow the existing repository test's fixture helpers; if `EntryListRepositoryTest` does not exist, model it on the nearest `*RepositoryTest`.)

- [ ] **Step 2: Run — expect FAIL**

Run: `cd backend && php bin/phpunit --filter EntryListRepositoryTest`
Expected: FAIL — feed B leaks into All items / unread.

- [ ] **Step 3: Add the predicate to `listForUser`**

After the `subscriptionId`/`tagId` guards, before `applyView`:

```php
if ($query->hidesExcludedFeeds()) {
    $qb->andWhere('s.includeInAllItems = true');
}
```

- [ ] **Step 4: Run — expect PASS**

Run: `cd backend && php bin/phpunit --filter EntryListRepositoryTest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add backend/src/Repository/EntryListRepository.php backend/tests/Repository/EntryListRepositoryTest.php
git commit -m "feat(#688): hide includeInAllItems=false feeds from All items"
```

---

## Task 5: Recommendation candidate pool excludes For-You feeds

**Files:**
- Modify: `backend/src/Service/Recommendation/RecommendationCandidateLoader.php:170-178`
- Test: `backend/tests/Service/Recommendation/RecommendationCandidateLoaderTest.php` (create or extend)

**Interfaces:**
- Consumes: the `s` alias already joined in `candidateQueryBuilder`.
- Produces: `load()`, `linesForIds()`, `summarize()` all inherit the predicate.

- [ ] **Step 1: Write the failing test**

Seed two subscriptions with recent entries; set feed B `includeInForYou = false`. Assert `load()` returns no entry from B, and `linesForIds([...B ids])` returns empty / omits B, and `summarize([...B ids])` counts zero for B:

```php
$pool = $loader->load($userId, new CandidatePoolRequest(poolSize: 50, since: $since));
self::assertNotContains($entryB->getId(), $this->entryIds($pool));

$lines = $loader->linesForIds($userId, [$entryB->getId()]);
self::assertSame([], $lines);
```

- [ ] **Step 2: Run — expect FAIL**

Run: `cd backend && php bin/phpunit --filter RecommendationCandidateLoaderTest`
Expected: FAIL — B is a candidate.

- [ ] **Step 3: Add the predicate to the shared builder**

In `candidateQueryBuilder`, chain onto the return:

```php
private function candidateQueryBuilder(int $userId): QueryBuilder
{
    return $this->entityManager->createQueryBuilder()
        ->select('e', 'f', 's.customTitle AS customTitle')
        ->from(Entry::class, 'e')
        ->join('e.feed', 'f')
        ->join(Subscription::class, 's', 'ON', 's.feed = e.feed AND s.user = :user')
        ->andWhere('s.includeInForYou = true')
        ->setParameter('user', $userId);
}
```

- [ ] **Step 4: Run — expect PASS**

Run: `cd backend && php bin/phpunit --filter RecommendationCandidateLoaderTest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add backend/src/Service/Recommendation/RecommendationCandidateLoader.php backend/tests/Service/Recommendation
git commit -m "feat(#688): drop includeInForYou=false feeds from the candidate pool"
```

---

## Task 6: For-You list and badge drop excluded feeds

**Files:**
- Modify: `backend/src/Repository/RecommendationItemRepository.php:114-125`
- Test: `backend/tests/Repository/RecommendationItemRepositoryTest.php` (create or extend)

**Interfaces:**
- Consumes: the `s` alias already joined in `applyForYouCriteria`.
- Produces: both `listForYou()` and `countForYou()` inherit the predicate.

- [ ] **Step 1: Write the failing test**

Seed a completed run with recommended items for feeds A and B; set feed B `includeInForYou = false`. Assert `listForYou` omits B's items and `countForYou` drops them:

```php
$page = $repo->listForYou($userId, null, 50);
self::assertNotContains($itemForB->getEntry()->getId(), $this->entryIds($page));
self::assertSame(1, $repo->countForYou($userId)); // only A's item counts
```

- [ ] **Step 2: Run — expect FAIL**

Run: `cd backend && php bin/phpunit --filter RecommendationItemRepositoryTest`
Expected: FAIL — B's past picks still show and still count.

- [ ] **Step 3: Add the predicate to `applyForYouCriteria`**

```php
return $qb
    ->join('i.run', 'r')
    ->join('i.entry', 'e')
    ->join(Subscription::class, 's', 'ON', 's.feed = e.feed AND s.user = :user')
    ->andWhere('r.user = :user')
    ->andWhere('r.status = :completed')
    ->andWhere('s.includeInForYou = true')
    ->andWhere($this->notDedupedByNewerRunDql())
    ->setParameter('user', $userId)
    ->setParameter('completed', RecommendationRun::STATUS_COMPLETED);
```

- [ ] **Step 4: Run — expect PASS**

Run: `cd backend && php bin/phpunit --filter RecommendationItemRepositoryTest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add backend/src/Repository/RecommendationItemRepository.php backend/tests/Repository/RecommendationItemRepositoryTest.php
git commit -m "feat(#688): drop excluded feeds from the For You list and badge"
```

---

## Task 7: "Mark all read" respects the All-items exclusion

**Files:**
- Modify: `backend/src/Service/Reader/MarkReadService.php:83-93`
- Test: `backend/tests/Service/Reader/MarkReadServiceTest.php` (create or extend)

**Interfaces:**
- Consumes: `Subscription::isIncludeInAllItems()`.

- [ ] **Step 1: Write the failing test**

Seed feeds A (included) and B (`includeInAllItems = false`), each with unread entries. Assert:

```php
// scope 'all' skips B
$service->mark($user, 'all', null);
self::assertTrue($this->isUnread($entryB));   // B untouched
self::assertFalse($this->isUnread($entryA));  // A marked

// scope 'feed' on B still marks it
$service->mark($user, 'feed', $subB->getId());
self::assertFalse($this->isUnread($entryB));
```

Add a second case: scope `tag` on a tag that contains B still marks B.

- [ ] **Step 2: Run — expect FAIL**

Run: `cd backend && php bin/phpunit --filter MarkReadServiceTest`
Expected: FAIL — `all` marks B.

- [ ] **Step 3: Filter the `all` scope**

Extract a small private helper (a service may hold helpers; this is not a controller) and use it in the `all` arm:

```php
'all' => $this->includedInAllItems($this->subscriptions->findForUserWithTags($userId)),
```

```php
/**
 * @param  list<Subscription> $subscriptions
 * @return list<Subscription>
 */
private function includedInAllItems(array $subscriptions): array
{
    return array_values(array_filter(
        $subscriptions,
        static fn (Subscription $subscription): bool => $subscription->isIncludeInAllItems(),
    ));
}
```

Leave `feed` and `tag` arms unchanged.

- [ ] **Step 4: Run — expect PASS**

Run: `cd backend && php bin/phpunit --filter MarkReadServiceTest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add backend/src/Service/Reader/MarkReadService.php backend/tests/Service/Reader/MarkReadServiceTest.php
git commit -m "feat(#688): mark-all-read skips feeds hidden from All items"
```

---

## Task 8: `SubscriptionJson::one()` emits both flags

**Files:**
- Modify: `backend/src/Http/SubscriptionJson.php:36-68`
- Test: `backend/tests/Http/SubscriptionJsonTest.php` (create or extend), plus the subscriptions-list functional test if one asserts payload shape.

**Interfaces:**
- Produces: JSON keys `includeInAllItems: bool`, `includeInForYou: bool` in `one()` and in the `@return` shape.

- [ ] **Step 1: Write the failing test**

```php
public function testItSerialisesTheExclusionFlags(): void
{
    $subscription = $this->subscriptionWith(includeInAllItems: false, includeInForYou: true);
    $json = SubscriptionJson::one($subscription);

    self::assertFalse($json['includeInAllItems']);
    self::assertTrue($json['includeInForYou']);
}
```

- [ ] **Step 2: Run — expect FAIL**

Run: `cd backend && php bin/phpunit --filter SubscriptionJsonTest`
Expected: FAIL — undefined array key.

- [ ] **Step 3: Add the keys**

In the returned array and the `@return` phpdoc shape:

```php
'includeInAllItems' => $sub->isIncludeInAllItems(),
'includeInForYou'   => $sub->isIncludeInForYou(),
```

- [ ] **Step 4: Run — expect PASS**

Run: `cd backend && php bin/phpunit --filter SubscriptionJsonTest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add backend/src/Http/SubscriptionJson.php backend/tests/Http/SubscriptionJsonTest.php
git commit -m "feat(#688): expose exclusion flags in the subscription JSON"
```

---

## Task 9: PATCH accepts the two flags (`null` = unchanged)

**Files:**
- Modify: `backend/src/Dto/Subscription/UpdateSubscriptionRequest.php`
- Modify: `backend/src/Controller/Api/SubscriptionController.php:99-139`
- Test: `backend/tests/Controller/Api/SubscriptionControllerTest.php` (extend the PATCH tests)

**Interfaces:**
- Consumes: `Subscription::setIncludeInAllItems/ForYou`, `SubscriptionJson::one`.
- Produces: `UpdateSubscriptionRequest::$includeInAllItems: ?bool = null`, `$includeInForYou: ?bool = null`. `null` leaves the stored value unchanged; `customTitle`/`tagIds` semantics are untouched.

- [ ] **Step 1: Write the failing functional tests**

```php
public function testPatchSetsIncludeInAllItemsFalse(): void
{
    // PATCH { customTitle, tagIds, includeInAllItems: false }
    // assert 200, response includeInAllItems === false, includeInForYou unchanged (true)
}

public function testOmittingAFlagLeavesItUnchanged(): void
{
    // pre-set includeInForYou = false in the DB
    // PATCH { customTitle, tagIds } — no flags
    // assert includeInForYou still false (null left it alone), customTitle/tagIds applied as today
}

public function testTagClearOnOmissionStillHolds(): void
{
    // PATCH with tagIds omitted still clears tags — regression guard on unchanged behaviour
}
```

- [ ] **Step 2: Run — expect FAIL**

Run: `cd backend && php bin/phpunit --filter SubscriptionControllerTest`
Expected: FAIL — flags ignored / not accepted.

- [ ] **Step 3: Extend the DTO**

```php
public function __construct(
    #[Assert\Length(max: 512)]
    public ?string $customTitle = null,
    #[Assert\All([new Assert\Type('integer'), new Assert\Positive()])]
    public array $tagIds = [],
    public ?bool $includeInAllItems = null,
    public ?bool $includeInForYou = null,
) {}
```

- [ ] **Step 4: Apply the flags in the controller**

After the tag diff, before `flush()`, with guard clauses (null = unchanged):

```php
if ($request->includeInAllItems !== null) {
    $subscription->setIncludeInAllItems($request->includeInAllItems);
}
if ($request->includeInForYou !== null) {
    $subscription->setIncludeInForYou($request->includeInForYou);
}
```

Keep the action thin — this is direct entity mutation from the request, matching the existing `setCustomTitle` line; no new private method.

- [ ] **Step 5: Run — expect PASS**

Run: `cd backend && php bin/phpunit --filter SubscriptionControllerTest`
Expected: PASS.

- [ ] **Step 6: `composer check` on the backend**

Run: `cd backend && composer cs:fix && composer check`
Expected: clean.

- [ ] **Step 7: Commit**

```bash
git add backend/src/Dto/Subscription/UpdateSubscriptionRequest.php backend/src/Controller/Api/SubscriptionController.php backend/tests/Controller/Api/SubscriptionControllerTest.php
git commit -m "feat(#688): accept exclusion flags on PATCH /api/subscriptions/{id}"
```

---

## Task 10: Frontend model + All-items badge

**Files:**
- Modify: `frontend/src/app/reader/models.ts:53-80` (`SubscriptionDto`), `:249-253` (`SubscriptionUpdate`)
- Modify: `frontend/src/app/reader/subscriptions.store.ts:72-74` (`sumUnread`)
- Test: `frontend/src/app/reader/subscriptions.store.spec.ts`

**Interfaces:**
- Produces: `SubscriptionDto.includeInAllItems: boolean`, `SubscriptionDto.includeInForYou: boolean`; `SubscriptionUpdate.includeInAllItems?: boolean`, `SubscriptionUpdate.includeInForYou?: boolean`.

- [ ] **Step 1: Write the failing Jest spec**

In `subscriptions.store.spec.ts`, extend the `sub(...)` factory to accept the flags (default both `true`), then:

```ts
it('excludes includeInAllItems=false feeds from the All items badge', () => {
  const subs = [sub(1, 5), { ...sub(2, 8), includeInAllItems: false }];
  expect(sumUnread(subs)).toBe(5);
});

it('still counts an excluded feed under its tag', () => {
  const excluded = { ...sub(2, 8, [tag(3)]), includeInAllItems: false };
  const tree = buildTagTree([excluded], /* tags */);
  expect(tree[0].unreadCount).toBe(8); // per-tag count unchanged
});
```

- [ ] **Step 2: Run — expect FAIL**

Run: `docker compose exec -T frontend npx --no-install jest subscriptions.store` (or `./node_modules/.bin/jest` if a `+` is in the path)
Expected: FAIL — `sumUnread` still counts feed 2.

- [ ] **Step 3: Add the DTO fields and filter `sumUnread`**

`models.ts` — add to `SubscriptionDto`:

```ts
  includeInAllItems: boolean;
  includeInForYou: boolean;
```

and to `SubscriptionUpdate`:

```ts
  includeInAllItems?: boolean;
  includeInForYou?: boolean;
```

`subscriptions.store.ts`:

```ts
export function sumUnread(subs: SubscriptionDto[]): number {
  return subs.reduce((n, s) => (s.includeInAllItems ? n + s.unreadCount : n), 0);
}
```

- [ ] **Step 4: Run — expect PASS**

Run: `docker compose exec -T frontend npx --no-install jest subscriptions.store`
Expected: PASS. (Per-tag `buildTagTree` still sums every feed — leave it unchanged.)

- [ ] **Step 5: Commit**

```bash
git add frontend/src/app/reader/models.ts frontend/src/app/reader/subscriptions.store.ts frontend/src/app/reader/subscriptions.store.spec.ts
git commit -m "feat(#688): model flags and drop excluded feeds from the All items badge"
```

---

## Task 11: Toggle actions in `ManageActions`

**Files:**
- Modify: `frontend/src/app/reader/manage/manage-actions.service.ts:49-74`
- Test: `frontend/src/app/reader/manage/manage-actions.service.spec.ts`

**Interfaces:**
- Consumes: `ReaderApi.updateSubscription(id, SubscriptionUpdate)`, the store's current sub.
- Produces: `ManageActions.setIncludeInAllItems(sub: SubscriptionDto, value: boolean): void`, `setIncludeInForYou(sub: SubscriptionDto, value: boolean): void`. Each PATCHes `{ customTitle, tagIds, includeInAllItems, includeInForYou }` built from `sub` with the one flag set to `value`, and optimistically updates the store.

- [ ] **Step 1: Write the failing spec**

```ts
it('setIncludeInAllItems PATCHes the full body with the flag flipped', () => {
  const s = sub(7, 3, [tag(2)]); // includeInAllItems/ForYou default true
  actions.setIncludeInAllItems(s, false);
  const req = http.expectOne('/api/subscriptions/7');
  expect(req.request.method).toBe('PATCH');
  expect(req.request.body).toEqual({
    customTitle: s.customTitle,
    tagIds: [2],
    includeInAllItems: false,
    includeInForYou: true,
  });
});
```

- [ ] **Step 2: Run — expect FAIL**

Run: `docker compose exec -T frontend npx --no-install jest manage-actions`
Expected: FAIL — method not defined.

- [ ] **Step 3: Implement the two actions**

Follow the `retag` shape (optimistic update, then `reloadAfter`). Extract a shared private builder to stay DRY:

```ts
setIncludeInAllItems(sub: SubscriptionDto, value: boolean): void {
  this.patchFlags(sub, { includeInAllItems: value, includeInForYou: sub.includeInForYou });
}

setIncludeInForYou(sub: SubscriptionDto, value: boolean): void {
  this.patchFlags(sub, { includeInAllItems: sub.includeInAllItems, includeInForYou: value });
}

private patchFlags(
  sub: SubscriptionDto,
  flags: { includeInAllItems: boolean; includeInForYou: boolean },
): void {
  this.subs.patchLocal(sub.id, flags); // optimistic; add a store mutator mirroring decrementUnread
  this.reloadAfter(
    this.api.updateSubscription(sub.id, {
      customTitle: sub.customTitle,
      tagIds: sub.tags.map((t) => t.id),
      ...flags,
    }),
    () => this.subs.load(),
  );
}
```

Add `SubscriptionsStore.patchLocal(id, Partial<Pick<SubscriptionDto,'includeInAllItems'|'includeInForYou'>>)` using the same immutable `.update()` spread pattern as `decrementUnread` (`subscriptions.store.ts:179`).

- [ ] **Step 4: Run — expect PASS**

Run: `docker compose exec -T frontend npx --no-install jest manage-actions subscriptions.store`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add frontend/src/app/reader/manage/manage-actions.service.ts frontend/src/app/reader/manage/manage-actions.service.spec.ts frontend/src/app/reader/subscriptions.store.ts frontend/src/app/reader/subscriptions.store.spec.ts
git commit -m "feat(#688): ManageActions toggles for the exclusion flags"
```

---

## Task 12: Sidebar menu items, outputs, and row marker

**Files:**
- Modify: `frontend/src/app/reader/sidebar/sidebar.component.ts` (outputs, coarse sheet), `.html` (both menu branches + both row markers), `.scss` (marker)
- Modify: `frontend/src/app/reader/reader-shell.component.html` (wire outputs)
- Modify: `frontend/public/i18n/en.json` + `de.json`
- Verify/modify: icon registry (`eye-off`)
- Test: `frontend/src/app/reader/sidebar/sidebar.component.spec.ts`

**Interfaces:**
- Consumes: `ManageActions.setIncludeInAllItems/ForYou`.
- Produces: sidebar outputs `toggleAllItems = output<SubscriptionDto>()`, `toggleForYou = output<SubscriptionDto>()`.

- [ ] **Step 1: Verify the icon exists**

Run: `grep -rin "eye-off\|eyeOff" frontend/src/app | head`
If the app's `<app-icon>` registry has no `eye-off`, add it following the registry's existing entries (the Tabler `eye-off` path). Note the exact `name` to use in the template.

- [ ] **Step 2: Write the failing sidebar specs**

```ts
it('shows both exclusion toggles in the untagged feed row menu and emits', () => {
  // open the untagged feed menu, find the two toggle buttons, click each,
  // expect f.componentInstance.toggleAllItems / toggleForYou to emit the sub
});

it('shows both exclusion toggles in the tagged feed row menu', () => { /* … */ });

it('renders the eye-off marker when a feed is excluded from either surface', () => {
  const excluded = { ...sub(2, 4), includeInForYou: false };
  // render, assert one .feed-exclusion-marker (ti-eye-off) present on that row,
  // and the unread count "4" is STILL present (marker did not displace it)
});

it('renders no marker when both flags are true', () => { /* assert absent */ });
```

- [ ] **Step 3: Run — expect FAIL**

Run: `docker compose exec -T frontend npx --no-install jest sidebar.component`
Expected: FAIL.

- [ ] **Step 4: Add outputs + coarse-sheet actions in `sidebar.component.ts`**

```ts
toggleAllItems = output<SubscriptionDto>();
toggleForYou = output<SubscriptionDto>();
```

Add the two actions to `openFeedSheet(subscription)` alongside edit/unsubscribe (label from i18n, emitting `toggleAllItems`/`toggleForYou`).

- [ ] **Step 5: Add menu items to BOTH desktop branches in `sidebar.component.html`**

In the tagged branch `.pop` menu (near `:380-388`) and the untagged branch `.pop` menu (near `:487-496`), add next to Edit/Unsubscribe:

```html
<button type="button" (click)="toggleAllItems.emit(s); closeMenu()">
  {{ (s.includeInAllItems ? 'reader.excludeFromAllItems' : 'reader.includeInAllItems') | transloco }}
</button>
<button type="button" (click)="toggleForYou.emit(s); closeMenu()">
  {{ (s.includeInForYou ? 'reader.excludeFromForYou' : 'reader.includeInForYou') | transloco }}
</button>
```

- [ ] **Step 6: Add the row marker to BOTH row templates**

In the tagged row (count at `:360-362`) and untagged row (count at `:468-470`), immediately before the `.count` span:

```html
@if (!s.includeInAllItems || !s.includeInForYou) {
  <app-icon
    class="feed-exclusion-marker"
    name="eye-off"
    size="xs"
    [title]="exclusionTooltip(s) | transloco: { … }"
    aria-hidden="false"
  />
}
```

Add a small helper `exclusionTooltip(s)` returning the right i18n key: both-off → `reader.excludedFromBoth`, all-items only → `reader.excludedFromAllItems`, for-you only → `reader.excludedFromForYou`. Keep the row single-line — marker + count both fit.

`.scss`: `.feed-exclusion-marker { color: var(--text-muted); margin-right: var(--space-1); }` (no hex, no raw px).

- [ ] **Step 7: Wire the outputs in `reader-shell.component.html`**

Where `editFeed`/`unsubscribe` are bound to `ManageActions`, add:

```html
(toggleAllItems)="manage.setIncludeInAllItems($event, !$event.includeInAllItems)"
(toggleForYou)="manage.setIncludeInForYou($event, !$event.includeInForYou)"
```

- [ ] **Step 8: Add i18n keys (en + de)**

`en.json` `reader` block: `excludeFromAllItems`, `includeInAllItems`, `excludeFromForYou`, `includeInForYou`, `excludedFromAllItems`, `excludedFromForYou`, `excludedFromBoth`. Add German equivalents in `de.json` at the same keys.

- [ ] **Step 9: Run — expect PASS**

Run: `docker compose exec -T frontend npx --no-install jest sidebar.component`
Expected: PASS.

- [ ] **Step 10: Commit**

```bash
git add frontend/src/app/reader/sidebar frontend/src/app/reader/reader-shell.component.html frontend/public/i18n
git commit -m "feat(#688): sidebar toggles, output wiring, and the exclusion marker"
```

---

## Task 13: Edit-feed dialog switches

**Files:**
- Modify: `frontend/src/app/reader/manage/edit-subscription-dialog.component.ts:43-88`, `.html` (after the tags block)
- Modify: `frontend/public/i18n/en.json` + `de.json` (`dialog.editFeed`)
- Test: `frontend/src/app/reader/manage/edit-subscription-dialog.component.spec.ts:131,144`

**Interfaces:**
- Consumes: `DIALOG_DATA` sub (now carries the two flags), `ReaderApi.updateSubscription`.

- [ ] **Step 1: Update the failing dialog specs**

Extend the two exact-equality body assertions to include the flags, and add coverage for the switches reflecting stored state:

```ts
// existing assertion at :139 becomes:
expect(req.request.body).toEqual({
  customTitle: 'My Heise', tagIds: [2],
  includeInAllItems: true, includeInForYou: true,
});

it('reflects stored exclusion state and sends the toggled values', () => {
  // DIALOG_DATA sub with includeInAllItems=false
  // assert the All-items switch renders unchecked; toggle For You off; Save
  // expect body includeInAllItems:false, includeInForYou:false
});
```

- [ ] **Step 2: Run — expect FAIL**

Run: `docker compose exec -T frontend npx --no-install jest edit-subscription-dialog`
Expected: FAIL — body missing the flags.

- [ ] **Step 3: Add the switches**

In the component, hold the two flags as signals seeded from `this.data` (mirroring `checked`):

```ts
includeInAllItems = signal<boolean>(this.data.includeInAllItems);
includeInForYou = signal<boolean>(this.data.includeInForYou);
```

In `submit()`'s body:

```ts
const body = {
  customTitle: this.form.getRawValue().customTitle.trim() || null,
  tagIds: [...this.checked()],
  includeInAllItems: this.includeInAllItems(),
  includeInForYou: this.includeInForYou(),
};
```

In the template, after the tags block (before the error slot), two labelled switches following the app's existing switch/toggle pattern (reuse the same control the settings/digest toggles use), bound to the signals, with `dialog.editFeed.*` labels.

- [ ] **Step 4: Add i18n keys (en + de)**

`dialog.editFeed`: `showInAllItems`, `showInForYou` (and helper subtitles if the switch pattern shows them). German parity.

- [ ] **Step 5: Run — expect PASS**

Run: `docker compose exec -T frontend npx --no-install jest edit-subscription-dialog`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add frontend/src/app/reader/manage/edit-subscription-dialog.component.ts frontend/src/app/reader/manage/edit-subscription-dialog.component.html frontend/public/i18n
git commit -m "feat(#688): exclusion switches in the edit-feed dialog"
```

---

## Task 14: Document the marker + full verification

**Files:**
- Modify: `docs/design-language.md`
- No code beyond doc.

- [ ] **Step 1: Record the sidebar-row status marker in `docs/design-language.md`**

Add a short entry: the sidebar row's first status marker — a muted `ti-eye-off` glyph (`--text-muted`, `size="xs"`, `--space-1` gap) shown left of the unread count when a feed is excluded from All items or For You; it never displaces the count. Note it as the pattern for future single-line row markers.

- [ ] **Step 2: Backend gates**

Run: `cd backend && composer cs && composer stan && composer md && composer tramp && php bin/phpunit`
Expected: all clean/green (SQLite leg).

- [ ] **Step 3: Backend MySQL leg**

Run: `docker compose exec php vendor/bin/phpunit`
Expected: green.

- [ ] **Step 4: Scan the dev log**

Run: `ls -t backend/var/log/dev-*.log | head -1` then read the newest for deprecations/swallowed errors from the backend work.
Expected: nothing new attributable to #688.

- [ ] **Step 5: Frontend gate**

Run: `cd frontend && npm run check` (or `docker compose exec -T frontend npm run check`)
Expected: ESLint + Prettier + Stylelint + Jest green.

- [ ] **Step 6: Mutation on changed files (the CI gate)**

Run: `cd backend && composer infection:diff`
Expected: at or above `minMsi`. Address escaped mutants on the changed lines.

- [ ] **Step 7: Manual smoke (optional but recommended)**

With the Docker stack up, exclude a noisy feed from All items in the UI: confirm it leaves All items and the All-items badge, still shows in its own list, its tag, favorites, and search; confirm the marker + tooltip; exclude another from For You and confirm its past picks and badge drop. Flip both back and confirm immediate restore.

- [ ] **Step 8: Commit the doc**

```bash
git add docs/design-language.md
git commit -m "docs(#688): record the sidebar-row exclusion marker"
```

---

## Self-review notes

- **Spec coverage:** every acceptance-criterion in #688 maps to a task — menu+dialog switches in both branches (T12/T13), All-items hiding (T4) with feed/tag/favorites/search unaffected (search untouched by design), For-You candidates + past picks + badge (T5/T6), independence of the two flags (separate predicates, T4 vs T5/T6), All-items badge vs per-feed/per-tag counts (T10), mark-all-read (T7), the marker (T12), backup round-trip (T1), migration on both engines (T2), unit+functional tests throughout, and the full gate run (T14).
- **Out of scope, honored:** `searchForUser` and saved-search/digest queries are not touched; no OPML attribute; no per-tag/per-view override; no bulk action (that is #659, which reuses the shared `UpdateSubscriptionRequest` this plan extends).
- **Green between commits:** Task 1 bundles the entity columns with the backup declaration so `BackupSchemaCoverageTest` never sits red across a commit boundary.
