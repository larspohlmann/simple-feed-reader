# For You badge from the server's unread totals — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** The For You badge is refreshed whenever the other unread badges are. Opening a pick, any mark-read and the poll tick all update it.

**Architecture:** `unreadTotals` (on `GET /api/subscriptions` and `GET /api/subscriptions/counts`) gets a `forYou` number from `RecommendationItemRepository::countForYou`, the query the For You list's unread filter already uses. The frontend reads the badge and the list-heading count from `SubscriptionsStore.unreadTotals()`. The run report (`GET /api/recommendations/runs/current`) loses its now-unused `forYou.itemCount`, so the badge has one source. The For-You-only `refreshStatus()` calls after a mark-read go away. A completed run and a purge trigger a counts reload instead.

**Tech Stack:** Symfony 7.4 / PHP 8.4 / Doctrine (backend), Angular 20 signals + Jest (frontend).

**Spec:** GitHub issue #1700 and its audit comment. Read both: `gh issue view 1700 --repo larspohlmann/simple-feed-reader --comments`.

## Global Constraints

- CLAUDE.md applies in full: Clean Code rules, `final readonly`, no comment unless a future reader would get the code wrong without it, `declare(strict_types=1)`, HTTP status via `Response::HTTP_*`.
- Commit format: `type(#1700): summary`. No Claude attribution lines anywhere.
- **Work in the worktree** `/Users/lars/Documents/work/eigenes/simple-feed-reader-1700` on branch `fix/1700-for-you-badge-server-count`. Never `checkout`/`reset`/`stash` in the main checkout: another session works there on `feature/1699`.
- The worktree has no `vendor/` or `node_modules/`. Run `composer install` in `backend/` and `npm ci` in `frontend/`. Never symlink `vendor/`, because that breaks `project_dir`.
- The Docker stack mounts the main checkout, not this worktree. So in the worktree, run backend tests natively (`php bin/phpunit`, SQLite) and frontend gates natively (`npm run check`, which includes `tsc` and Jest). Do not run tests in the Docker containers for this branch. CI runs the MySQL leg. Use Docker only for the read-only EXPLAIN in Task 1.
- Prettier is 100 columns. Stylelint and hex rules are unaffected (no styles change).
- Ratchets: never lower `minMsi` and never add a baseline. Every `src` file you touch must be PHPMD-clean.

## File map

Backend:
- `backend/src/Service/Subscription/Model/UnreadTotalsModel.php`: gains `int $forYou`.
- `backend/src/Service/Subscription/SubscriptionTallyReader.php`: injects `RecommendationItemRepository` and fills `forYou`.
- `backend/src/Http/UnreadTotalsJson.php`, `backend/src/Http/SubscriptionCountsJson.php`: emit `forYou` and widen the array shapes.
- `backend/src/Service/Recommendation/Feed/Model/RecommendationForYouSummaryModel.php`, `.../RecommendationForYouSummaryProvider.php`, `backend/src/Http/RecommendationRunStatusJson.php`: drop `itemCount`.
- Tests: `tests/Http/UnreadTotalsJsonTest.php`, `tests/Http/SubscriptionCountsJsonTest.php`, `tests/Http/SubscriptionJsonListTest.php`, `tests/Service/Subscription/SubscriptionTallyReaderTest.php`, `tests/Controller/Api/SubscriptionControllerTest.php`, `tests/Http/RecommendationRunStatusJsonTest.php`, `tests/Service/Recommendation/Feed/RecommendationForYouSummaryProviderTest.php`, `tests/Controller/Api/RecommendationRunControllerTest.php`.

Frontend (`frontend/src/app/…`, plus `frontend/e2e/`):
- `reader/models.ts`: `UnreadTotalsDto.forYou` is added, and `RecommendationRunReport.forYou.itemCount` is removed.
- `reader/state/unread-totals.ts`: `UnreadTotals.forYou`.
- `reader/state/subscriptions.store.ts`: `forYouUnread` computed.
- `reader/state/recommendations.service.ts`: `forYouCount` removed.
- `reader/shell/sidebar/sidebar.component.{ts,html}`: `forYouUnread` input in place of `recs.forYouCount()`.
- `reader/shell/reader-shell.component.html`: binds `[forYouUnread]`.
- `reader/shell/list-heading.service.ts`: the For You count comes from the subscriptions store.
- `reader/shell/mark-read-actions.service.ts`: the `refreshStatus()` calls are removed.
- `reader/shell/list-reload.service.ts`: recounts when a run completes.
- `settings/recommendations/recommendation-settings.service.ts`: recounts after a purge.
- Fixtures: every `UnreadTotalsDto` literal gets `forYou`, and every run-report `forYou` literal loses `itemCount`.

---

### Task 1: Backend — `unreadTotals.forYou`

**Files:**
- Modify: `backend/src/Service/Subscription/Model/UnreadTotalsModel.php`
- Modify: `backend/src/Service/Subscription/SubscriptionTallyReader.php`
- Modify: `backend/src/Http/UnreadTotalsJson.php`
- Modify: `backend/src/Http/SubscriptionCountsJson.php` (two phpdoc array shapes)
- Test: `backend/tests/Http/UnreadTotalsJsonTest.php`, `backend/tests/Http/SubscriptionCountsJsonTest.php`, `backend/tests/Http/SubscriptionJsonListTest.php`, `backend/tests/Service/Subscription/SubscriptionTallyReaderTest.php`, `backend/tests/Controller/Api/SubscriptionControllerTest.php`

**Interfaces:**
- Produces: `UnreadTotalsModel::__construct(int $allItems, array $tags, array $networks, array $networkProfiles, int $forYou)`. `forYou` goes **last**, so that the existing positional constructions change only by an appended argument.
- Produces wire: `unreadTotals: { allItems: int, forYou: int, tags: [...], networks: [...] }`, with key order `allItems`, `forYou`, `tags`, `networks`.
- Consumes: `RecommendationItemRepository::countForYou(int $userId): int`, unchanged. It already applies the list's criteria and `UnreadDql`, and `tests/Repository/RecommendationFeedTest.php` (`testCountForYouCountsUnreadPicksOnly`) covers it.

`App\Service\Subscription` using `App\Repository\RecommendationItemRepository` is a repository dependency, not a dependency between Service modules, so `ServiceModuleCycleRule` and `ServiceModuleBoundaryRule` are not affected.

- [ ] **Step 1: Write the failing tests**

`backend/tests/Http/UnreadTotalsJsonTest.php`: extend the existing test.

```php
        $payload = UnreadTotalsJson::from(new UnreadTotalsModel(
            allItems: 7,
            tags: [3 => 2],
            networks: ['bluesky' => 5, 'mastodon' => 1],
            networkProfiles: ['bluesky' => 2],
            forYou: 4,
        ));

        self::assertSame([
            'allItems' => 7,
            'forYou' => 4,
            'tags' => [['id' => 3, 'unreadCount' => 2]],
            'networks' => [
                ['network' => 'bluesky', 'unreadCount' => 5, 'profilesUnreadCount' => 2],
                ['network' => 'mastodon', 'unreadCount' => 1, 'profilesUnreadCount' => 0],
            ],
        ], $payload);
```

`backend/tests/Http/SubscriptionCountsJsonTest.php`: in the first test, use `new UnreadTotalsModel(7, [3 => 2], ['bluesky' => 5], ['bluesky' => 2], 3)` and the expected `'unreadTotals' => ['allItems' => 7, 'forYou' => 3, 'tags' => …, 'networks' => …]`. In the second test, use `new UnreadTotalsModel(0, [], [], [], 0)` and add `self::assertSame(0, $payload['unreadTotals']['forYou']);`.

`backend/tests/Http/SubscriptionJsonListTest.php`: append `, 0` to its three `new UnreadTotalsModel(…)` calls. Do the same in any other construction that `grep -rn "new UnreadTotalsModel(" backend` finds. If a test there asserts the whole `unreadTotals` array, add `'forYou' => 0` after `allItems`.

`backend/tests/Service/Subscription/SubscriptionTallyReaderTest.php`: add a test, plus the helpers it needs. Model the seeding on `backend/tests/Repository/RecommendationFeedTest.php` and reuse its way of building a completed `RecommendationRun` and a `RecommendationItem`. Read that file's setup and helpers first and copy the constructor calls exactly as they appear there; do not guess the signatures.

```php
    public function testCountsTheUnreadForYouPicksOnly(): void
    {
        $when = new \DateTimeImmutable('2026-07-01T00:00:00Z');
        $user = new User('tallies-for-you@example.com', $when);
        $this->entityManager->persist($user);
        $feed = new Feed('https://example.com/for-you.xml');
        $this->entityManager->persist($feed);
        $this->entityManager->persist(new Subscription($user, $feed, $when));
        $readPick = $this->entry($feed, 'read-pick');
        $unreadPick = $this->entry($feed, 'unread-pick');
        $this->entry($feed, 'not-a-pick');
        $state = new EntryState($user, $readPick);
        $state->hide($when);
        $this->entityManager->persist($state);
        $run = $this->completedRun($user);
        $this->pick($run, $readPick, 1);
        $this->pick($run, $unreadPick, 2);
        $this->entityManager->flush();

        $reader = self::getContainer()->get(SubscriptionTallyReader::class);
        self::assertInstanceOf(SubscriptionTallyReader::class, $reader);

        self::assertSame(1, $reader->forUser($user->requireId())->unreadTotals->forYou);
    }
```

`completedRun(User $user): RecommendationRun` and `pick(RecommendationRun $run, Entry $entry, int $position): void` are private helpers that persist the run and the item the way `RecommendationFeedTest` does. In `testCarriesTheTagAndNetworkTotalsFromTheirOwnScopes`, add `self::assertSame(0, $totals->forYou);`, because that user has no runs.

`backend/tests/Controller/Api/SubscriptionControllerTest.php`: add `self::assertSame(0, $list['unreadTotals']['forYou']);` after line 146 and `self::assertSame(0, $counts['unreadTotals']['forYou']);` after line 191. This proves that both endpoints carry the key through the real wiring.

- [ ] **Step 2: Run the tests to verify they fail**

Run (from `backend/`): `php bin/phpunit tests/Http/UnreadTotalsJsonTest.php tests/Http/SubscriptionCountsJsonTest.php tests/Service/Subscription/SubscriptionTallyReaderTest.php tests/Controller/Api/SubscriptionControllerTest.php`
Expected: FAIL. The `UnreadTotalsModel` constructor has no `forYou` parameter (an unknown named parameter, or too many arguments).

- [ ] **Step 3: Implement**

`UnreadTotalsModel.php`:

```php
    /**
     * @param array<int, int>    $tags            tag id => unread entries, 0 absent
     * @param array<string, int> $networks        network => unread entries, 0 absent
     * @param array<string, int> $networkProfiles network => unread entries of its profile feeds, 0 absent
     */
    public function __construct(
        public int $allItems,
        public array $tags,
        public array $networks,
        public array $networkProfiles,
        public int $forYou,
    ) {
    }
```

`SubscriptionTallyReader.php`: add `private RecommendationItemRepository $recommendationItems,` to the constructor (with `use App\Repository\RecommendationItemRepository;`). Append `$this->recommendationItems->countForYou($userId),` as the last `UnreadTotalsModel` argument.

`UnreadTotalsJson.php`: return `['allItems' => $totals->allItems, 'forYou' => $totals->forYou, 'tags' => $tags, 'networks' => $networks]`, and add `forYou: int,` after `allItems: int,` in the `@return` shape. Add the same line to both shapes in `SubscriptionCountsJson.php`.

- [ ] **Step 4: Run the tests to verify they pass**

Run the Step 2 command. Expected: PASS. Then run `php bin/phpunit tests/Http tests/Service/Subscription tests/Controller/Api/SubscriptionControllerTest.php`. Expected: PASS.

- [ ] **Step 5: Measure the cost on the dev DB (read-only)**

`countForYou` now runs on every counts request. From the **main checkout** (`/Users/lars/Documents/work/eigenes/simple-feed-reader`), where Docker runs, find the user with the most recommendation runs:

```bash
docker compose exec -T mysql mysql -ufeedreader -pfeedreader feedreader -e "SELECT user_id, COUNT(*) FROM recommendation_run WHERE status='completed' GROUP BY user_id"
```

Then run `EXPLAIN ANALYZE` on this SQL for that user (replace `:user`), and time it with `SELECT SQL_NO_CACHE COUNT(*) …` run three times:

```sql
SELECT COUNT(i.id) FROM recommendation_item i
 JOIN recommendation_run r ON r.id = i.recommendation_run_id
 JOIN entry e ON e.id = i.entry_id
 JOIN subscription s ON s.feed_id = e.feed_id AND s.user_id = :user
 LEFT JOIN entry_state es ON es.entry_id = e.id AND es.user_id = :user
 WHERE r.user_id = :user AND r.status = 'completed' AND s.include_in_for_you = 1
 AND NOT EXISTS (SELECT 1 FROM recommendation_item i2 JOIN recommendation_run r2 ON r2.id = i2.recommendation_run_id
   WHERE i2.entry_id = i.entry_id AND r2.user_id = :user AND r2.status = 'completed' AND r2.id > r.id)
 AND (es.is_hidden = 0 OR (es.is_hidden IS NULL AND (s.marked_read_until IS NULL OR e.effective_date > s.marked_read_until)))
```

Acceptable: under ~30 ms, with index lookups and no full scan of `recommendation_item`. If it is slower, stop and report the plan to the planner before you add an index. An index needs a migration and its own verification leg. Record the timing for the PR body.

- [ ] **Step 6: Gates and commit**

Run (from `backend/`): `composer cs && bin/console cache:warmup && composer stan && composer md && composer tramp`. Expected: all clean.

```bash
git add backend
git commit -m "feat(#1700): unread totals carry the For You count"
```

---

### Task 2: Backend — the run report drops `forYou.itemCount`

**Files:**
- Modify: `backend/src/Service/Recommendation/Feed/Model/RecommendationForYouSummaryModel.php`
- Modify: `backend/src/Service/Recommendation/Feed/RecommendationForYouSummaryProvider.php`
- Modify: `backend/src/Http/RecommendationRunStatusJson.php`
- Test: `backend/tests/Http/RecommendationRunStatusJsonTest.php`, `backend/tests/Service/Recommendation/Feed/RecommendationForYouSummaryProviderTest.php`, `backend/tests/Controller/Api/RecommendationRunControllerTest.php`

**Interfaces:**
- Produces: `RecommendationForYouSummaryModel::__construct(int $totalCount, ?\DateTimeImmutable $generatedAt, ?int $newestRunId = null)`.
- Produces wire: `forYou: { totalCount, generatedAt, newestRunId }`, with no `itemCount`.

- [ ] **Step 1: Update the tests to the new shape**

- `RecommendationRunStatusJsonTest.php` lines 153 and 194: change the expected value to `'forYou' => ['totalCount' => 0, 'generatedAt' => null, 'newestRunId' => null]`. Line 115: drop the first argument, giving `new RecommendationForYouSummaryModel(9, new \DateTimeImmutable(…), …)`, and adjust any assertion on `itemCount` in that test. Line 220: `new RecommendationForYouSummaryModel(0, null, null)`.
- `RecommendationForYouSummaryProviderTest.php`: delete the two `self::assertSame(…, $summary->itemCount);` lines (118, 133). `totalCount` keeps the dedup covered, and `RecommendationFeedTest::testCountForYouCountsUnreadPicksOnly` keeps the unread filter covered.
- `RecommendationRunControllerTest.php` lines 280, 427 and 819: remove `'itemCount' => 0, ` from the expected arrays. Line 397: change `self::assertSame(1, $forYou['itemCount']);` to `self::assertSame(1, $forYou['totalCount']);`. Line 862: change `self::assertSame(0, $forYou['itemCount']);` to `self::assertSame(0, $forYou['totalCount']);`. Then add `self::assertArrayNotHasKey('itemCount', $forYou);` after the line-397 assertion. That pins the removal.

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php bin/phpunit tests/Http/RecommendationRunStatusJsonTest.php tests/Service/Recommendation/Feed/RecommendationForYouSummaryProviderTest.php tests/Controller/Api/RecommendationRunControllerTest.php`
Expected: FAIL. The arrays still contain `itemCount`, and the constructor still takes four arguments.

- [ ] **Step 3: Implement**

`RecommendationForYouSummaryModel.php`: remove the `itemCount` parameter and its comment, and the "unlike itemCount" comment on `totalCount`. Keep the `newestRunId` comment.

```php
    public function __construct(
        public int $totalCount,
        public ?\DateTimeImmutable $generatedAt,
        // The run whose time the header shows; the client hides that run's divider by id, not by matching timestamps.
        public ?int $newestRunId = null,
    ) {
    }
```

`RecommendationForYouSummaryProvider.php`: remove the `countForYou(...)` argument.

`RecommendationRunStatusJson.php`: delete the two comment lines and `'itemCount' => $summary->itemCount,`, and remove `itemCount` from the method's `@return` shape if it is listed there.

Check that nothing else reads it: `grep -rn "itemCount" backend/src | grep -iv preview` must print nothing.

- [ ] **Step 4: Run the tests to verify they pass**

Run the Step 2 command. Expected: PASS.

- [ ] **Step 5: Gates and commit**

Run: `composer cs && composer stan && composer md && composer tramp`. Expected: clean.

```bash
git add backend
git commit -m "refactor(#1700): the run report no longer carries the For You unread count"
```

---

### Task 3: Frontend — the badge and the heading read `unreadTotals.forYou`

**Files:**
- Modify: `frontend/src/app/reader/models.ts` (`UnreadTotalsDto` around line 155; `RecommendationRunReport.forYou` around lines 496–506)
- Modify: `frontend/src/app/reader/state/unread-totals.ts`
- Modify: `frontend/src/app/reader/state/subscriptions.store.ts`
- Modify: `frontend/src/app/reader/state/recommendations.service.ts` (lines 212–214)
- Modify: `frontend/src/app/reader/shell/sidebar/sidebar.component.ts`, `sidebar.component.html` (lines 101–102)
- Modify: `frontend/src/app/reader/shell/reader-shell.component.html` (next to `[totalUnread]` at line 79)
- Modify: `frontend/src/app/reader/shell/list-heading.service.ts` (line 171)
- Test: `frontend/src/app/reader/state/unread-totals.spec.ts`, `frontend/src/app/reader/state/subscriptions.store.spec.ts`, `frontend/src/app/reader/shell/sidebar/sidebar.component.spec.ts`, `frontend/src/app/reader/state/recommendations.service.spec.ts`, `frontend/src/app/reader/shell/reader-shell.component.spec.ts`, `frontend/src/app/settings/recommendations/recommendation-settings-card.component.spec.ts`, and all fixtures listed in Step 5.

**Interfaces:**
- Consumes: wire `unreadTotals.forYou: number` (Task 1); the run report without `itemCount` (Task 2).
- Produces: `UnreadTotals.forYou: number`, `SubscriptionsStore.forYouUnread: Signal<number>`, and `SidebarComponent.forYouUnread = input(0)`. `RecommendationsService.forYouCount` no longer exists.

- [ ] **Step 1: Write the failing tests**

`unread-totals.spec.ts`: give `totals` the field `forYou: 3`. In `maps the DTO lists into maps`, pass `forYou: 5` in the DTO and add `expect(result.forYou).toBe(5);`. Give the `unreadTotalsFrom({ allItems: 0, tags: [], networks: [] })` call `forYou: 0`. In `is false when one map value differs`, add `expect(sameUnreadTotals(totals, { ...totals, forYou: 2 })).toBe(false);`.

`subscriptions.store.spec.ts`: give the `unreadTotalsDto` factory (line 58) the default `forYou: 0`. Add a test next to the one at lines 895–906, which asserts `store.unreadTotals().allItems` after a counts reload. Use the same setup, with `unreadTotals: { allItems: 1, forYou: 4, tags: [...] }`, and assert `expect(store.forYouUnread()).toBe(4);`.

`sidebar.component.spec.ts`, `describe('for-you row')`: `mountWithAi(ready, running = false, forYouUnread = 0)` now fakes `RecommendationsService` as `{ running: signal(running) }` and sets `fixture.componentRef.setInput('forYouUnread', forYouUnread);` before `detectChanges()`. The two badge tests keep their expectations (12 shown, 0 hidden).

`recommendations.service.spec.ts` lines 218–231: drop `itemCount: 2` and the `forYouCount()` expectation, and keep `forYouTotal()`. Line 23's fixture loses `itemCount`.

`reader-shell.component.spec.ts`: the test at lines 3530–3567 ("refreshes the for-you count to zero after marking all read") becomes "takes the for-you count from the recount after marking all read". Seed `markedReadCountsBody`'s `unreadTotals` with `forYou: 0` and the boot's counts with `forYou: 5`. Assert `subscriptions.forYouUnread()` (`TestBed.inject(SubscriptionsStore)`) goes 5 → 0. Remove the `runs/current` expectation and the `recommendations.report.set(...)` seeding from that test. Task 4 removes the request itself, so in this task keep the `ctrl.expectOne('…/runs/current')` lines that other tests at 3517 and 3585 need until Task 4.

`recommendation-settings-card.component.spec.ts` lines 714–731: drop the `forYouCount()` assertion and the `itemCount` fields.

- [ ] **Step 2: Run to verify the tests fail**

Run (from `frontend/`): `npm run typecheck:spec`, then `npx jest src/app/reader/state src/app/reader/shell/sidebar`.
Expected: type errors (`forYou` missing on `UnreadTotalsDto`/`UnreadTotals`, no `forYouUnread`) and failing specs.

- [ ] **Step 3: Implement**

`models.ts`:

```ts
/** Unread per scope, each article counted once within its scope (#1679). */
export interface UnreadTotalsDto {
  allItems: number;
  forYou: number;
  tags: { id: number; unreadCount: number }[];
  networks: { network: SocialNetworkName; unreadCount: number; profilesUnreadCount: number }[];
}
```

In `RecommendationRunReport`, remove `itemCount: number;` from `forYou`. Rewrite its docblock so it no longer mentions the unread count or "`itemCount` keeps its wire name". Keep the rest: the list's total, generation time and generating run; it describes the list, not this run; `newestRunId` is used for the divider (#348).

`unread-totals.ts`: add `readonly forYou: number;` after `allItems`, `forYou: 0` to `NO_UNREAD_TOTALS`, `forYou: dto.forYou` to `unreadTotalsFrom`, and `left.forYou === right.forYou &&` to `sameUnreadTotals`.

`subscriptions.store.ts`, next to `totalUnread`:

```ts
  readonly forYouUnread = computed(() => this.unreadTotals().forYou);
```

`recommendations.service.ts`: delete `forYouCount` and its docblock.

`sidebar.component.ts`: add `readonly forYouUnread = input(0);` next to `viewedCount`. In `sidebar.component.html`, replace `recs.forYouCount()` twice with `forYouUnread()`.

`reader-shell.component.html`: add `[forYouUnread]="subs.forYouUnread()"` after `[totalUnread]="subs.totalUnread()"`.

`list-heading.service.ts` line 171:

```ts
    'for-you': (selection) =>
      bySwitch(selection, this.subscriptions.forYouUnread(), this.recommendations.forYouTotal()),
```

- [ ] **Step 4: Update the remaining fixtures**

TypeScript reports every `UnreadTotalsDto` literal that is missing `forYou`. Add `forYou: 0` after `allItems` (or the value a test needs). Also remove `itemCount` from every run-report `forYou` literal, including ones that `flush()` accepts untyped. Find them with:

```bash
grep -rln "allItems:\|itemCount:" src e2e | xargs grep -n "allItems:\|forYou: {"
```

Known sites: `reader/reader-api.spec.ts`, `reader/shell/reader-shell.component.spec.ts` (`NO_UNREAD_TOTALS_DTO` and the inline totals), `reader/shell/mark-read-actions.service.spec.ts:104`, `reader/feeds/manage/manage-actions.service.spec.ts:474`, `discover/discover.component.spec.ts:190,218`, `list/for-you-progress/for-you-progress.component.spec.ts:23`, `e2e/support/reader.ts` (`unreadTotalsOf` returns `forYou: 0`), `e2e/list-header-count-one-line.spec.ts:85`, `e2e/saved-search-layout.spec.ts:69`, `e2e/desktop-search-split.spec.ts:108`. Leave the feed-preview `itemCount` (`add-feed`) alone; it is a different field.

- [ ] **Step 5: Run to verify everything passes**

Run (from `frontend/`): `npm run check`. Expected: ESLint, Prettier, Stylelint, tsc (app, spec, e2e) and Jest are all green. If Prettier complains, run `npx prettier --write` on the touched files only.

- [ ] **Step 6: Commit**

```bash
git add frontend
git commit -m "feat(#1700): the For You badge reads the server's unread totals"
```

---

### Task 4: Frontend — refresh paths: one recount, no special case

**Files:**
- Modify: `frontend/src/app/reader/shell/mark-read-actions.service.ts` (lines 10, 22, 58, 96–97)
- Modify: `frontend/src/app/reader/shell/list-reload.service.ts` (the `completedStamp` effect, lines 60–68)
- Modify: `frontend/src/app/settings/recommendations/recommendation-settings.service.ts` (purge `next`, line 145)
- Test: `frontend/src/app/reader/shell/mark-read-actions.service.spec.ts`, `frontend/src/app/reader/shell/reader-shell.component.spec.ts`, `frontend/src/app/settings/recommendations/recommendation-settings-card.component.spec.ts`

**Interfaces:**
- Consumes: `SubscriptionsStore.reloadCountsQuietly(): void` (existing; a no-op until the store has resolved) and `SubscriptionsStore.forYouUnread` (Task 3).

Behaviour:
- After any mark-read, the existing `subscriptions.reloadCountsQuietly()` brings the For You count with it. So `markAboveReadNow` no longer calls `recommendations.refreshStatus()`, `reloadAfterMarkRead` loses the `if (target.scope === 'for-you')` line and its comment, and `MarkReadActions` no longer injects `RecommendationsService`.
- When a run completes while the reader is open, `ListReload`'s `completedStamp` effect also calls `this.subs.reloadCountsQuietly()`, for every selection, not only `for-you`.
- After a purge, `recommendation-settings.service.ts` keeps `refreshStatus()` (it refreshes `forYouTotal`/`generatedAt`) and also calls `subscriptions.reloadCountsQuietly()`, with `SubscriptionsStore` injected (it is `providedIn: 'root'`).
- A run that a background worker completes while no tab polls it is picked up by the counts poll's floor tick (≤ 5 minutes). No change is needed for that.

- [ ] **Step 1: Write the failing tests**

`mark-read-actions.service.spec.ts`: remove the `RecommendationsService` provider at line 79. Running the spec then fails while the service still injects it (`NullInjectorError`), which proves the dependency is gone once it passes. Add an assertion to the existing mark-above test: after the mark-read flushes, exactly one `…/subscriptions/counts` request is made. Use the `expectOne` pattern that spec already uses.

`reader-shell.component.spec.ts`:
- In the mark-all-read-in-For-You tests (around lines 3500–3530 and 3530–3567), replace `ctrl.expectOne('…/api/recommendations/runs/current').flush(…)` with `ctrl.expectNone('https://api.test/api/recommendations/runs/current');` after the counts flush.
- Add a test in the `describe` that covers `completedStamp`. Put it next to the existing `completedStamp` tests (around lines 2200–2240) and reuse their boot helper. Boot on `{ kind: 'all' }`, call `TestBed.inject(RecommendationsService).completedStamp.update((stamp) => stamp + 1)`, run `fixture.detectChanges()`, and assert `ctrl.expectOne('https://api.test/api/subscriptions/counts')`.

`recommendation-settings-card.component.spec.ts` (purge test around line 714): the `SubscriptionsStore` there is unresolved, so `reloadCountsQuietly()` sends nothing. Assert the call with `jest.spyOn(TestBed.inject(SubscriptionsStore), 'reloadCountsQuietly')` and `expect(spy).toHaveBeenCalledTimes(1)` after the purge flush, rather than expecting an HTTP request.

- [ ] **Step 2: Run to verify the tests fail**

Run: `npx jest src/app/reader/shell/mark-read-actions.service.spec.ts src/app/reader/shell/reader-shell.component.spec.ts src/app/settings/recommendations`
Expected: FAIL. There is a `NullInjectorError` for `RecommendationsService`, an unexpected `runs/current` request, a missing counts request on completion, and the spy is not called.

- [ ] **Step 3: Implement**

`mark-read-actions.service.ts`: delete the `RecommendationsService` import and field, `this.recommendations.refreshStatus();` in `markAboveReadNow`, and in `reloadAfterMarkRead` the comment line plus `if (target.scope === 'for-you') this.recommendations.refreshStatus();`.

`list-reload.service.ts`:

```ts
    // A completed for-you run changes the For You unread count, and reloads the
    // list if the user is on it. `completedStamp` starts at 0, which is the signal's
    // initial value, not a completion — the guard keeps a boot from reloading.
    effect(() => {
      if (this.recs.completedStamp() === 0) return;
      untracked(() => {
        this.subs.reloadCountsQuietly();
        const selection = this.selection();
        if (selection.kind === 'for-you') this.entries.load(queryFromSelection(selection));
      });
    });
```

`recommendation-settings.service.ts`: inject `private readonly subscriptions = inject(SubscriptionsStore);`, using the import path that file's neighbours use for `SubscriptionsStore`. In the purge `next`, call `this.subscriptions.reloadCountsQuietly();` after `this.recommendations.refreshStatus();`.

Check: `grep -rn "refreshStatus" src/app | grep -v spec` should print only the definition and the purge call.

- [ ] **Step 4: Run to verify**

Run: `npm run check`. Expected: all green.

- [ ] **Step 5: Commit**

```bash
git add frontend
git commit -m "refactor(#1700): every recount refreshes the For You badge; the For-You-only status reloads go"
```

---

### Task 5: Verify and open the PR

- [ ] **Step 1: Full backend gates** (from `backend/`): `composer test:parallel`, `composer check`, `composer md`, `composer infection:diff`. Infection gates only the changed lines. If mutants escape on `UnreadTotalsJson`, `SubscriptionTallyReader` or `RecommendationRunStatusJson`, kill them with an assertion; never lower `minMsi`.
- [ ] **Step 2: Frontend gate** (from `frontend/`): `npm run check`.
- [ ] **Step 3: Dev log scan**: there is nothing to scan from the worktree (native tests use the `test` env). Skip it, and say so in the PR body.
- [ ] **Step 4: Merge develop in** if it moved: `git fetch origin && git merge origin/develop`, then rerun Steps 1–2 if anything merged.
- [ ] **Step 5: Push and open the PR** into `develop`: `git push -u origin fix/1700-for-you-badge-server-count`, then `gh pr create --base develop`. Title: `fix(#1700): the For You badge follows the server's unread totals`. The body says `Closes #1700`, gives the Task 1 EXPLAIN timing, and notes that the MySQL leg runs in CI because the Docker stack serves the main checkout. Add no attribution lines.
- [ ] **Step 6: After deploy (Lars's call)**: on Strato, read a For You pick in All items and check that the sidebar For You badge drops without a reload.

## Self-review notes

- Issue coverage: `forYou` in `unreadTotals` (T1); the badge and heading read it (T3); special cases removed (T4); module boundaries checked (T1 note; a repository dependency only); cost measured (T1 Step 5); run completion recounts (T4); "Done when" gates (T5). The audit comment's saved-search duplicate case is **not** in scope. The comment asked only that it be checked while planning, and saved searches already reload through `load()` on every mark-read and on the poll tick. Raise it as its own issue if Lars wants it.
- Names used across tasks: `UnreadTotalsModel::$forYou`, `UnreadTotals.forYou`, `UnreadTotalsDto.forYou`, `SubscriptionsStore.forYouUnread`, `SidebarComponent.forYouUnread`, `RecommendationForYouSummaryModel($totalCount, $generatedAt, $newestRunId)`.
