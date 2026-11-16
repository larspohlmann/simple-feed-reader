# Trending story follow-ups (#2272) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Simplify and speed up what #2128 built: placement as rows, one per-window build policy, an in-memory claim gate, a refreshed build lock, one entry-list page value, per-row `joinedAt` for a followed story, and a frontend where the visit, the dividers, the story actions and "is it followed" each have one owner.

**Architecture:** Backend first, small items before the schema change: the claim gate (Task 6) and the page value (Task 4) land on today's schema, then Task 7 swaps the `in_day`/`in_week` flags for a `trending_story_placement` table keyed `(time_window, entry_id)`, which also answers item 4 (the key is the index; the write is a diff). Task 8 moves the day/week choice into one policy the builder picks. Frontend tasks follow the data: per-row `joinedAt` (Task 9) makes the visit a stored timestamp, which lets the visit leave `EntriesStore` (Task 10) and lets one segmentation path draw both dividers (Task 11).

**Tech Stack:** Symfony 8.1 / PHP 8.5 / Doctrine ORM+DBAL (MySQL 8 + SQLite), Angular 20 signals, Jest, PHPUnit 12.

**Spec:** GitHub issue #2272 (`gh issue view 2272`); domain in `docs/superpowers/specs/2026-11-16-2128-stable-trending-stories-design.md` and `docs/superpowers/plans/2026-11-16-2128-stable-trending-stories.md`.

## Global Constraints

- CLAUDE.md binds every task: Clean Code rules, folder roles (§10), `composer check` + `composer md` clean on every touched `src` file, phptramp (4 hops fail, 3 warn), no PHPStan ignores, no `tests/PhpStan` edits, no PHPMD suppressions, no count-driven class splits.
- API response shapes only gain fields; `lastOpenedAt`, `newEntryIds`, `lastJoinedAt`, `followed` stay (native iOS client, architecture.md §6).
- A schema change ships a migration for MySQL 8.0 and SQLite that carries Strato's existing rows (249 stories / 1329 members); migrations are not run by the suite.
- Datetimes are naive UTC in storage; ATOM in JSON.
- Every task is TDD: a test that fails without the change. Backend: `php bin/phpunit <path>` from `backend/`. Frontend: `docker compose exec -T frontend npx jest <path>` (path relative to `frontend/`).
- HTTP statuses as `Response::HTTP_*`, never a number.
- Frontend: standalone components and signals; styles in sibling `.scss`; strings in de, en, fr, es (no new strings are planned).
- Commit format `type(#2272): lower-case imperative`; no Claude attribution. Plan file itself is not committed.
- Run `/simplify` before the PR; both backend legs (SQLite natively, `docker compose exec php composer test:parallel`) and `npm run check` before the PR.

## Rulings (items not planned as the issue words them)

- **Item 4 (clearPlacement index / write only flips) folds into Task 7.** On the flag schema it would add an index Task 7 deletes. The placement table's primary key `(time_window, entry_id)` is the index, and `place()` writes the diff.
- **Item 3, second half (prune candidates from the stored set): not done.** `pruneUnfollowedOutside` (`src/Repository/TrendingStoryHistoryRepository.php:104-114`) is one statement driven by `trending_story`, which the weekly prune keeps to about a week of stories plus the followed ones, with a PK probe of the members. Picking candidates in PHP adds a read, chunked deletes and an ordering trap: a story created earlier in the same build is not in the stored set, so it would look prunable. The claim half is done (Task 6).
- **Item 7 (day/week flip re-counting an entry as new) stays a watch.** Neither Task 7 nor Task 9 fixes it. The loop: the week claim deletes the day story D's row for entry e (`claimChunk`, `TrendingStoryHistoryRepository.php:129-141`), then the next day build attaches its group to D again, sees e as joining (`StoryAssignmentModel::joining()`), and inserts it with `joined_at` = now. Placement rows keep that delete, because the delete is what stops the day build tying a split-off part back to its old story. Per-row `joinedAt` shows the same reset `joined_at`. Both fixes change a rule, so Lars has to choose (see Open questions): (a) an entry that joins a story takes the earliest `joined_at` it holds in any story, or (b) the claim keeps D's history row, and the matching index counts a member only where its week placement does not name another story. No task until he decides.
- **Item 2 uses the house strategy pattern** (tag + keyed locator, as `Service/Ingestion/Refresh/FeedBodyParser.php:20-66`). Once Task 6 removes the repository's window branch, the bool is left in two places. A plain `match` in the builder would also work. The locator is chosen because CLAUDE.md asks strategies to use one.

## Review Focus

1. **A rebuild over unchanged input writes no placement row.** The diff write has to be a no-op the second time (Task 7, `testPlacingTheSamePlacementAgainWritesNothing`).
2. **An entry on the window start.** The in-memory claim gate trusts that `storedInWindow($since)` sees every stored story holding a group entry. An entry whose `effective_date` equals `since` must still be claimed (Task 6, `testAGroupEntryOnTheWindowStartIsStillClaimed`).
3. **A week merge keeps the merged story's day placement.** Today the flags are ORed onto the survivor. With rows, the `trending_story` cascade would silently drop the day view of a merged story (Task 7, `testAMergeMovesTheMergedStorysDayPlacementToTheSurvivor`).
4. **The visit across pages and reloads.** Page 2 of a followed story marks its new rows. A reload inside the visit keeps the first page's marks, although the server's `lastOpenedAt` has moved (Task 9, two tests).
5. **The follow bell before the followed list has loaded, and while a follow is on the wire.** The card shows the server's `followed` until the store has answered once. A follow stays shown as followed from the click until the reload lists it (Task 12, two tests).

---

### Task 1: Story actions provided by the shell, not a route check in the leaf (item 11)

**Files:**
- Create: `frontend/src/app/reader/trending/story-actions/story-actions.ts`
- Modify: `frontend/src/app/reader/trending/story-actions/story-actions.component.ts:20-31`, its `.html` (uses `actions.follow.run(...)` / `actions.hide.run(...)`)
- Modify: `frontend/src/app/reader/shell/reader-shell.component.ts:121-122` (providers)
- Test: `frontend/src/app/reader/trending/story-actions/story-actions.component.spec.ts`, `frontend/src/app/reader/trending/story-actions/story-actions.spec.ts` (new)

**Interfaces:**
- Produces: `export interface StoryActions { follow(entry: EntryDto): void; hide(entry: EntryDto): void }`; `export const STORY_ACTIONS: InjectionToken<Signal<StoryActions | null>>`; `export function provideTrendingStoryActions(): Provider[]`. The provider includes `TrendingFollowAction` and `TrendingHideAction`, plus a factory that returns `computed(() => routeState.selection().kind === 'trending' ? { follow: e => follow.run(e), hide: e => hide.run(e) } : null)`.
- The leaf injects `inject(STORY_ACTIONS, { optional: true })` and drops `ReaderRouteState` and both action injects. Its `actions` computed returns `{ story, actions }` when the entry has a `story` and the token's signal holds actions, else `null`. The template calls `actions.follow(entry())` / `actions.hide(entry())`.

- [ ] **Step 1: Rewrite the component spec against the token.** Provide `{ provide: STORY_ACTIONS, useValue: signal<StoryActions | null>({ follow, hide }) }` in place of the three providers. Keep every existing `it`. Replace `'offers nothing outside Trending'` with `'offers nothing while the list provides no story actions'` (token value `null`). Keep `'offers nothing on a card rendered outside the reader shell'` (no provider). Add `story-actions.spec.ts`: `it('provides the actions on Trending and none on another list')`. That test configures `provideTrendingStoryActions()` with a stub `ReaderRouteState` selection signal and stub actions, then checks that `inject(STORY_ACTIONS)()` is non-null for `{kind:'trending'}` and null for `{kind:'all'}`. Check also that `follow(entry)` reaches `TrendingFollowAction.run(entry)`.
- [ ] **Step 2: Run** `docker compose exec -T frontend npx jest src/app/reader/trending/story-actions`. Expected: FAIL, `story-actions.ts` not found.
- [ ] **Step 3: Implement** `story-actions.ts`. Switch the component to the token. In the shell, replace `TrendingFollowAction, TrendingHideAction` in `providers` with `...provideTrendingStoryActions()`.
- [ ] **Step 4: Run** the same command plus `src/app/reader/shell/reader-shell.component.spec.ts`. Expected: PASS.
- [ ] **Step 5: Commit** `refactor(#2272): provide story actions from the shell instead of a route check in the card`

### Task 2: Gate the followed-stories poll on the next build (item 13)

**Files:**
- Modify: `backend/src/Service/Curation/Trending/Model/TrendingLastBuildsModel.php`, `backend/src/Service/Curation/Trending/TrendingStoryFollowing.php:46-57`, `backend/src/Http/FollowedStoryJson.php`, `backend/src/Controller/Api/TrendingStoryFollowController.php:25`
- Create: `backend/src/Service/Curation/Trending/Model/FollowedStoriesModel.php`
- Modify: `frontend/src/app/reader/trending/trending.models.ts` (`FollowedStoriesResponseDto`), `frontend/src/app/reader/state/followed-stories.store.ts:30-44`
- Test: `backend/tests/Service/Curation/Trending/Model/TrendingLastBuildsModelTest.php`, `backend/tests/Controller/Api/TrendingStoryFollowControllerTest.php`, `frontend/src/app/reader/state/followed-stories.store.spec.ts`

**Interfaces:**
- Produces: `TrendingLastBuildsModel::nextBuildDueAt(): ?\DateTimeImmutable`. It returns the earliest `builtAt(window) + window->rebuildIntervalSeconds()` over `TrendingWindow::cases()`, or `null` when any window was never built. Also `final readonly class FollowedStoriesModel { /** @param list<FollowedStoryModel> $stories */ __construct(public array $stories, public ?\DateTimeImmutable $nextBuildDueAt) }`, `TrendingStoryFollowing::followedBy(int $userId): FollowedStoriesModel` (injects `TrendingSnapshotRepository`), and `FollowedStoryJson::list(FollowedStoriesModel $followed): array{stories: list<…>, nextBuildDueAt: string|null}` (ATOM).
- Frontend: `FollowedStoriesResponseDto.nextBuildDueAt: string | null`. `reloadIfStale()` loads only when `loads.isStale()` and (`nextBuildDueAt` is null, or `Date.now() >= Date.parse(nextBuildDueAt)`). `load()` is not gated, since follows, opens and unfollows call it directly.

- [ ] **Step 1: Write the failing tests.**
  - `testTheNextBuildIsDueAtTheEarliestWindowsIntervalAfterItsBuild`: day built 10:00, week 09:30 → `10:10`.
  - `testNoNextBuildTimeWhileAWindowWasNeverBuilt`: week missing → `null`.
  - Controller: `testTheFollowedListSaysWhenTheNextBuildIsDue`: snapshots recorded, then `nextBuildDueAt` is the ATOM of day `built_at + 600 s`. The existing `stories` assertions stay unchanged.
  - Jest: `it('skips a stale tick until the next build is due')` (fake timers; answer `nextBuildDueAt` 5 min ahead; the stale tick sends no request; after 5 min one request). `it('polls every stale tick while no build time is known')`.
- [ ] **Step 2: Run** `php bin/phpunit tests/Service/Curation/Trending/Model/TrendingLastBuildsModelTest.php tests/Controller/Api/TrendingStoryFollowControllerTest.php` and `docker compose exec -T frontend npx jest src/app/reader/state/followed-stories.store.spec.ts`. Expected: FAIL, the method/field does not exist.
- [ ] **Step 3: Implement** as in Interfaces. The store keeps `private nextBuildDueAt: number | null` set in `load()`'s adopt.
- [ ] **Step 4: Run** the same. Expected: PASS. Also `composer check`.
- [ ] **Step 4b (controller ruling, open question 2):** every Trending inclusion switch the reader flips (feed, network, account; find where the frontend sends them) calls `FollowedStoriesStore.load()` once it succeeds, so counts never lag a reader's own change. Jest: `it('reloads the followed stories after the reader switches a feed's Trending inclusion')`.
- [ ] **Step 5: Commit** `perf(#2272): poll the followed stories only once a build is due`

### Task 3: SubscriptionsStore onto StaleGatedLoad (item 14)

**Files:**
- Modify: `frontend/src/app/reader/state/stale-gated-load.ts:11-25`, `frontend/src/app/reader/state/subscriptions.store.ts:111-295`
- Test: `frontend/src/app/reader/state/stale-gated-load.spec.ts`, `frontend/src/app/reader/state/subscriptions.store.spec.ts` (existing suite is the regression net)

**Interfaces:**
- Produces: `StaleGatedLoad.run<T>(request: Observable<T>, adopt: (response: T) => void, failed?: (error: unknown) => void): void`. `failed` runs after the in-flight slot is cleared. An unsubscribed (superseded) request runs neither callback.
- SubscriptionsStore: `private readonly loads = new StaleGatedLoad()` replaces `latestLoad`, `lastLoadedAt`, `inFlight`, `beginRequest`, `settle` and `trackWhileOpen`. The unsubscribe on supersede already drops a late answer, which is what the epoch did. `invalidate()` calls `loads.forget()`. `loadIfStale` and `quietReload` gate on `loads.isStale()`. `quietFetch` keeps the `localEdits` overtaking rule inside its adopt closure: totals and Trending counts are applied even when overtaken, then `whenOvertaken()` runs. `load()`'s error branch moves into `failed`.

- [ ] **Step 1: Write the failing test** in `stale-gated-load.spec.ts`: `it('reports a failure to the caller after freeing the slot')`. Inside `failed`, `isStale()`'s in-flight half is already clear, so `loads.run(...)` called from there sends a new request. Add `it('reports nothing for a request a newer one superseded')`.
- [ ] **Step 2: Run** `docker compose exec -T frontend npx jest src/app/reader/state/stale-gated-load.spec.ts`. Expected: FAIL (no third parameter).
- [ ] **Step 3: Implement** `failed`, then move SubscriptionsStore onto it as in Interfaces.
- [ ] **Step 4: Run** `docker compose exec -T frontend npx jest src/app/reader/state`. Expected: PASS with no edits to `subscriptions.store.spec.ts`. If a spec there pins the epoch or the parked subscription itself rather than behaviour, rewrite it against behaviour and name it in the commit body.
- [ ] **Step 5: Commit** `refactor(#2272): load the subscriptions through the shared stale-gated load`

### Task 4: One entry-list page value (item 5)

**Files:**
- Create: `backend/src/Repository/EntryListPage.php`
- Modify: `backend/src/Dto/Entry/EntryPageParameters.php`, `backend/src/Repository/SavedSearchListQuery.php`, `backend/src/Repository/TrendingStoryListQuery.php`, `backend/src/Repository/SavedSearchEntryRepository.php:44-53`, `backend/src/Repository/TrendingStoryEntryListRepository.php:37-46`, `backend/src/Service/Curation/Trending/FollowedStoryEntries.php:39`, `backend/src/Controller/Api/EntryController.php:61-79`, `backend/src/Controller/Api/SavedSearchEntriesController.php:49-79`, `backend/src/Controller/Api/FollowedStoryEntriesController.php:40-47`, and the tests constructing either query (`tests/Repository/SavedSearchExclusionReadsTest.php:252`, `tests/Repository/SavedSearchMembershipReadsTest.php:312`, `tests/Service/Library/Search/SavedSearchEntriesTest.php:47`, `tests/Repository/TrendingStoryListQueryTest.php`)
- Test: `backend/tests/Repository/EntryListPageTest.php`, `backend/tests/Dto/Entry/EntryPageParametersTest.php` (unit; the functional `tests/Controller/Api/EntryPageParametersTest.php` stays)

**Interfaces:**
- Produces: `final readonly class EntryListPage { public int $limit; __construct(public ?EntryCursor $cursor = null, int $limit = EntryQuery::DEFAULT_LIMIT, public ListOrder $order = ListOrder::NewestFirst, public bool $onlyUnread = false) }`. `$limit` is clamped with `EntryQuery::clampLimit`. Also `ordering(): EntryListOrdering` (`byPublishedDate($this->order)`) and `isFirstPage(): bool`.
- `EntryPageParameters::toListPage(): EntryListPage` maps `EntryCursor::fromRequestValue($this->cursor)`, `limit`, `ListOrder::fromRequestValue($this->order)` and `unread`.
- `SavedSearchListQuery(int $userId, array $savedSearchIds, EntryListPage $page = new EntryListPage())` and `TrendingStoryListQuery(int $userId, int $storyId, EntryListPage $page = new EntryListPage())`, each exposing `public EntryListPage $page`. The queries lose their own `limit`/`cursor`/`order`/`onlyUnread`/`ordering()`/`isFirstPage()`. Consumers read `$query->page->…`.
- `EntryController::list` keeps `EntryQuery` as it is but fills cursor, limit and order from one `$listPage = $page->toListPage()`. `ForYouFeedQuery` keeps the raw cursor string.

- [ ] **Step 1: Write the failing tests.** `EntryListPageTest`: `testClampsTheLimitToTheMaximum` (10_000 → `EntryQuery::clampLimit(10_000)`), `testOrdersByPublishedDateInItsOrder` (equals `EntryListOrdering::byPublishedDate(ListOrder::OldestFirst)`), `testIsTheFirstPageOnlyWithoutACursor`. `EntryPageParametersTest`: `testMapsTheQueryStringOntoAListPage` (`new EntryPageParameters(EntryCursor::encode(new \DateTimeImmutable('2026-11-16 10:00:00'), 7), 20, true, 'asc')->toListPage()` has cursor id 7, limit 20, `ListOrder::OldestFirst`, `onlyUnread` true).
- [ ] **Step 2: Run** `php bin/phpunit tests/Repository/EntryListPageTest.php tests/Dto/Entry/EntryPageParametersTest.php`. Expected: FAIL, class not found.
- [ ] **Step 3: Implement** and move every consumer.
- [ ] **Step 4: Run** `php bin/phpunit tests/Repository tests/Controller/Api tests/Service/Library/Search tests/Service/Curation/Trending tests/Dto`. Expected: PASS. Also `composer check`.
- [ ] **Step 5: Commit** `refactor(#2272): read an entry list's page through one value`

### Task 5: Refresh the build lock before writing (item 6)

**Files:**
- Modify: `backend/src/Service/Curation/Trending/SnapshotBuilder/TrendingSnapshotBuilder.php:44-84`
- Test: `backend/tests/Service/Curation/Trending/SnapshotBuilder/TrendingSnapshotBuilderTest.php`

**Interfaces:**
- `buildLocked(TrendingWindow $window, LockInterface $lock)` calls `$lock->refresh()` after matching and right before `wrapInTransaction`, which ends the grouping/matching phase that can run long. A `LockConflictedException` or `LockExpiredException` from the refresh is rethrown as `TrendingBuildBusyException` with the original as `previous`. `TrendingRebuild` already logs and skips that exception. Nothing is written.

- [ ] **Step 1: Write the failing tests** with a test-local store implementing `PersistingStoreInterface` that delegates to `InMemoryStore` and counts `putOffExpiration`:
  - `testABuildPutsOffItsLockBeforeWriting`: after `build(Day)`, the count is ≥ 1.
  - `testABuildThatLostItsLockWritesNothing`: `putOffExpiration` throws `LockConflictedException`. `build(Day)` then throws `TrendingBuildBusyException`, and `trending_story` and `trending_snapshot` stay empty.
- [ ] **Step 2: Run** `php bin/phpunit tests/Service/Curation/Trending/SnapshotBuilder/TrendingSnapshotBuilderTest.php`. Expected: the two new tests FAIL.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run** the same. Expected: PASS.
- [ ] **Step 5: Commit** `fix(#2272): put off the build lock's expiry before a build writes`

### Task 6: Claim only what the stored index shows (item 3, claim half)

**Files:**
- Modify: `backend/src/Service/Curation/Trending/Model/StoryAssignmentModel.php`, `backend/src/Service/Curation/Trending/StoryMatching.php:34-54,214-245`, `backend/src/Repository/TrendingStoryHistoryRepository.php:88-101`
- Test: `backend/tests/Service/Curation/Trending/StoryMatchingTest.php`, `backend/tests/Repository/TrendingStoryHistoryRepositoryTest.php` (the tests at :315 and :343 build assignments with the claimed ids), `backend/tests/Service/Curation/Trending/SnapshotBuilder/TrendingSnapshotBuilderTest.php`

**Interfaces:**
- Produces: `StoryAssignmentModel(TrendingStoryModel $group, ?int $storyId, array $storedEntryIds, array $claimedEntryIds = [])`, where `/** @param list<int> $claimedEntryIds the group's entries another stored story holds after this build's merges */`.
- `StoryMatching` fills it for the week only. A group entry is claimed when `storiesByEntry` (`StoryMatching.php:84-94`) lists a story index whose survivor differs from the group's own story. A merged story resolves to its survivor, and a group with no story claims every entry held anywhere. Day assignments always carry `[]`.
- `TrendingStoryHistoryRepository::save` claims `$assignment->claimedEntryIds` when non-empty and no longer reads `$build->window->mergesStories()`. `claimFromOtherStories` takes `non-empty-list<int>` as today.

- [ ] **Step 1: Write the failing tests.**
  - Matching: `testAWeekGroupClaimsOnlyTheEntriesAnotherStoryHolds`: stored A{1,2,3}, B{4,5}; groups {1,2,3,4}, {5} → claimed `[4]` on A's assignment, `[]` on the other. The assertion holds whatever B's fate is; if it changes, check the threshold arithmetic and fix the fixture rather than the rule.
  - `testAWeekGroupClaimsNothingFromAStoryMergedIntoItsOwn`.
  - `testTheDayNeverClaims`.
  - Repository: `testASaveWithNothingToClaimReadsNoOtherStory`, using an `EntityManagerInterface` whose connection is wrapped by a DBAL middleware that records SQL. Assert no statement contains `story_id <> ?`.
  - Builder (Review Focus 2): `testAGroupEntryOnTheWindowStartIsStillClaimed`. A week build after an entry whose `effective_date` equals the window start sits in another story: afterwards it is in exactly one story.
- [ ] **Step 2: Run** `php bin/phpunit tests/Service/Curation/Trending/StoryMatchingTest.php tests/Repository/TrendingStoryHistoryRepositoryTest.php tests/Service/Curation/Trending/SnapshotBuilder`. Expected: the new tests FAIL.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run** the same. Expected: PASS. `composer check` (phptramp: the claimed ids ride on the assignment, not a parameter chain).
- [ ] **Step 5: Commit** `perf(#2272): claim only the entries the stored stories show elsewhere`

### Task 7: Placement as rows (items 1 and 4)

**Files:**
- Create: `backend/src/Entity/TrendingStoryPlacement.php`, `backend/migrations/Version20261116220000.php`, `backend/tests/Migrations/Version20261116220000Test.php`
- Modify: `backend/src/Entity/TrendingStoryEntry.php:41-45` (drop the flags), `backend/src/Repository/TrendingPlacementSql.php` (keep `placedIn` only), `backend/src/Repository/TrendingStoryHistoryRepository.php` (whole write path), `backend/src/Service/Curation/Trending/SnapshotBuilder/TrendingSnapshotBuilder.php:87-98`, `backend/tests/Support/TrendingFixtures.php:173-185`, and the flag-reading test helpers: `tests/Repository/TrendingStoryHistoryRepositoryTest.php:665-800`, `tests/Service/Curation/Trending/SnapshotBuilder/TrendingSnapshotBuilderTest.php:272-620`, `tests/Repository/TrendingPlacementSqlTest.php`
- Test: `backend/tests/Repository/TrendingStoryHistoryRepositoryTest.php`, `backend/tests/Repository/TrendingPlacementSqlTest.php`, the migration test

**Interfaces:**
- Schema: `trending_story_placement (time_window VARCHAR(8) NOT NULL, entry_id INT NOT NULL, story_id INT NOT NULL, PRIMARY KEY (time_window, entry_id))` with an index on `story_id`. FK `entry_id → entry(id)` and FK `story_id → trending_story(id)`, both `ON DELETE CASCADE`. The entity has `#[ORM\Id] #[ORM\Column(name: 'time_window', length: 8)] string $window` (the `TrendingSnapshot` pattern, `src/Entity/TrendingSnapshot.php:17-19`), `#[ORM\Id] ManyToOne Entry $entry` and `ManyToOne TrendingStory $story`. The key states the invariant: in one window an entry sits in one story.
- `TrendingPlacementSql::placedIn(TrendingWindow $window, string $member): string` keeps its signature and call sites (`TrendingStoryRepository.php:102,132,157,211`). It returns `EXISTS (SELECT 1 FROM trending_story_placement placed_<member> WHERE placed_<member>.time_window = '<value>' AND placed_<member>.entry_id = <member>.entry_id AND placed_<member>.story_id = <member>.story_id)`. `flagOf()` and `placementIn()` go.
- Repository write path:
  - `save(StoryAssignmentModel $assignment, StoryBuildModel $build): int` returns the story id and no longer places.
  - `place(TrendingWindow $window, array $storyIdByEntry): int` (`array<int, int>`) returns the rows written. It reads `SELECT entry_id, story_id … WHERE time_window = ?` (a PK prefix scan), deletes the entries that are gone or moved (chunked `IN`), and bulk-inserts the new and moved ones.
  - `clearPlacement()` goes.
  - Merge and claim become set statements over a source story list. Rows the target lacks are copied with `INSERT … SELECT target, entry_id, MIN(feed_id), MIN(article_site), MIN(shared_link_id), MIN(joined_at) … GROUP BY entry_id` (aggregates keep MySQL's ONLY_FULL_GROUP_BY happy). Rows the target holds take the earlier join with `UPDATE trending_story_entry SET joined_at = (SELECT MIN(source.joined_at) FROM (SELECT entry_id, joined_at FROM trending_story_entry WHERE story_id IN (:sources)) source WHERE source.entry_id = trending_story_entry.entry_id) WHERE story_id = :target AND joined_at > (…same…)`. The derived table is what keeps MySQL from refusing a subquery on the updated table (error 1093). Then `UPDATE trending_story_placement SET story_id = :target WHERE story_id IN (:sources)`; for a claim, narrowed to `entry_id IN (:claimed)`. Then the source member rows are deleted. `MemberRow`, `absorbMembers`, `combined`, `combineMember` and `membersOfOtherStories` go.
- Builder `write()`: merges → `save` each assignment, collecting `$storyIdByEntry[$entryId] = $storyId` over `$assignment->group->entryIds()` → `place($build->window, $storyIdByEntry)` → prune (week). Still branches on `mergesStories()` until Task 8.
- Migration `Version20261116220000`:
  - Up (MySQL and SQLite): create the table. Then `INSERT INTO trending_story_placement (time_window, entry_id, story_id) SELECT 'day', entry_id, MIN(story_id) FROM trending_story_entry WHERE in_day = 1 GROUP BY entry_id`, the same for `'week'`/`in_week`. `MIN` makes a duplicate deterministic rather than fatal. Then drop `in_day` and `in_week` (MySQL `ALTER TABLE … DROP in_day, DROP in_week`; SQLite two `ALTER TABLE … DROP COLUMN`). Neither column carries an index or FK, so SQLite needs no table rebuild and the entry FTS triggers are untouched. Skip when the table exists.
  - Down: add the flags back (`DEFAULT 0 NOT NULL`), set them from the rows, drop the table.

- [ ] **Step 1: Write the failing repository tests.**
  - `testPlacingAWindowWritesItsRowsAndLeavesTheOtherWindow`.
  - `testPlacingTheSamePlacementAgainWritesNothing` (Review Focus 1): the second `place()` returns 0.
  - `testPlacingMovesAnEntryToItsNewStoryAndDropsOneNoLongerGrouped`: the return value counts the moved and the dropped rows.
  - `testAMergeMovesTheMergedStorysDayPlacementToTheSurvivor` (Review Focus 3).
  - `testAClaimTakesTheEarlierJoinAndTheOtherStorysPlacementOfTheClaimedEntriesOnly`.
  - Rewrite every existing test that reads `in_day`/`in_week` to read placement rows through a helper `placedEntries(TrendingWindow, int $storyId): list<int>`. Each keeps its name and its expected values.
  - `TrendingPlacementSqlTest::testPlacedInFiltersByTheWindowsRows` (executes the fragment against fixtures).
- [ ] **Step 2: Write the failing migration test**, modelled on `tests/Migrations/Version20261116180000Test.php` (in-memory SQLite, old schema built by hand). It has `testFlagsBecomePlacementRows`, `testAnEntryFlaggedInTwoStoriesOfOneWindowGoesToTheLowerStoryId`, `testTheFlagColumnsAreGone`, and `testDownPutsTheFlagsBack`.
- [ ] **Step 3: Run** `php bin/phpunit tests/Repository tests/Migrations/Version20261116220000Test.php tests/Service/Curation/Trending`. Expected: the new tests FAIL.
- [ ] **Step 4: Implement** the entity, migration, `placedIn`, write path, builder and fixtures.
- [ ] **Step 5: Run** `php bin/phpunit` (whole SQLite suite) and `docker compose exec php composer test:parallel` (MySQL leg). This leg is where error 1093 and ONLY_FULL_GROUP_BY show up. Expected: both green.
- [ ] **Step 6: Verify the migrations.** These are the CI leg's commands (`.github/workflows/ci.yml:180-199`):
  - From empty on SQLite, from `backend/`: `rm -f var/migration-check.db && DATABASE_URL='sqlite:///%kernel.project_dir%/var/migration-check.db' php bin/console doctrine:migrations:migrate --no-interaction && DATABASE_URL='sqlite:///%kernel.project_dir%/var/migration-check.db' php bin/console doctrine:schema:validate`. Expected: `[OK]` for mapping and database.
  - From empty on MySQL: `docker compose exec php sh -c "DATABASE_URL='mysql://root:root@mysql:3306/feedreader_migrations?serverVersion=8.0&charset=utf8mb4' bin/console doctrine:database:create --if-not-exists && … doctrine:migrations:migrate --no-interaction && … doctrine:schema:validate"`. Take host and credentials from `compose.yaml`. Expected: `[OK]`.
  - Populated: on the dev Docker DB, first read `SELECT SUM(in_day), SUM(in_week) FROM trending_story_entry`. Then run `docker compose exec php bin/console doctrine:migrations:migrate --no-interaction`, then `SELECT time_window, COUNT(*) FROM trending_story_placement GROUP BY time_window`. Expected: counts equal, unless a duplicate went to the lower story id. Restart the worker afterwards, since it holds stale code. Never clear the dev DB.
  - Strato, read-only: `SELECT COUNT(*) FROM (SELECT entry_id FROM trending_story_entry WHERE in_week = 1 GROUP BY entry_id HAVING COUNT(*) > 1) duplicates`, and the same for `in_day`. Report the counts. Write nothing on Strato.
- [ ] **Step 7:** Run `composer check`, `composer md` and `composer infection:diff`. `TrendingStoryHistoryRepository` must be PHPMD-clean and shorter than its 390 lines.
- [ ] **Step 8: Commit** `refactor(#2272): keep a story's window placement as rows`

### Task 8: One per-window build policy (item 2)

**Files:**
- Create: `backend/src/Service/Curation/Trending/StoryBuildPolicy/StoryBuildPolicyInterface.php`, `…/StoryBuildPolicy/DayStoryBuildPolicy.php`, `…/StoryBuildPolicy/WeekStoryBuildPolicy.php`
- Modify: `backend/src/Service/Curation/Trending/StoryMatching.php:34-54,155-163`, `backend/src/Service/Curation/Trending/SnapshotBuilder/TrendingSnapshotBuilder.php`, `backend/src/Enum/TrendingWindow.php:62-65` (delete `mergesStories`), `backend/tests/Enum/TrendingWindowTest.php:32-33`
- Test: `backend/tests/Service/Curation/Trending/StoryMatchingTest.php`, `backend/tests/Service/Curation/Trending/StoryBuildPolicy/*Test.php`, `backend/tests/Service/Curation/Trending/TrendingContainerWiringTest.php`, `TrendingSnapshotBuilderTest::builderWith` (`:480-491`)

**Interfaces:**
- `#[AutoconfigureTag('app.trending_story_build_policy')] interface StoryBuildPolicyInterface` with these methods:
  - `/** @param list<TrendingStoryModel> $groups @param list<StoredStoryModel> $stored */ public function match(array $groups, array $stored): StoryMatchModel;`
  - `/** @return int the stories pruned */ public function prune(StoryBuildModel $build): int;`
- `#[AsTaggedItem(index: 'day')] final readonly class DayStoryBuildPolicy`: `match` → `StoryMatching::attach()`, and `prune` → 0.
- `#[AsTaggedItem(index: 'week')] final readonly class WeekStoryBuildPolicy`: `match` → `StoryMatching::reshape()`, and `prune` → `TrendingStoryHistoryRepository::pruneUnfollowedOutside($build->since)`.
- `StoryMatching::attach(array $groups, array $stored): StoryMatchModel` and `StoryMatching::reshape(array $groups, array $stored): StoryMatchModel` replace `match(…, TrendingWindow)`.
- The builder replaces `StoryMatching $matching` with `#[AutowireLocator('app.trending_story_build_policy')] ContainerInterface $policies` and resolves it with `private function policyFor(TrendingWindow $window): StoryBuildPolicyInterface`. Resolution mirrors `FeedBodyParser::resolve` (`src/Service/Ingestion/Refresh/FeedBodyParser.php:58-67`): a `LogicException` names the window. Nothing else in `src` asks which window merges.

- [ ] **Step 1: Write the failing tests.** `DayStoryBuildPolicyTest::testAttachesAndPrunesNothing` and `WeekStoryBuildPolicyTest::testReshapesAndPrunesOutsideTheWeek` (integration, `TrendingFixtures`). Add `TrendingContainerWiringTest::testEachWindowHasABuildPolicy`, which gets both policies from the container locator. Rename the `StoryMatchingTest` calls to `attach`/`reshape` with their expectations unchanged.
- [ ] **Step 2: Run** `php bin/phpunit tests/Service/Curation/Trending tests/Enum`. Expected: FAIL.
- [ ] **Step 3: Implement** and delete `mergesStories()` and its test lines. Confirm with `grep -rn mergesStories backend/src backend/tests`, which should return nothing.
- [ ] **Step 4: Run** the same plus `composer check` and `composer md`. Expected: PASS.
- [ ] **Step 5: Commit** `refactor(#2272): pick one build policy per window in the builder`

### Task 9: Per-row `joinedAt` on a followed story's entries (item 8)

**Files:**
- Modify: `backend/src/Repository/TrendingStoryEntryListRepository.php:54-92`, `backend/src/Service/Curation/Trending/FollowedStoryEntries.php`, `backend/src/Service/Curation/Trending/Model/FollowedStoryEntriesModel.php`, `backend/src/Http/FollowedStoryPage.php`, `backend/src/Repository/EntryListPage.php` (drop `isFirstPage` if unused)
- Modify: `frontend/src/app/reader/models.ts:307-335` (`EntryDto.joinedAt?: string`, doc of `FollowedStoryPageDto`), `frontend/src/app/reader/state/story-visit.ts`, `frontend/src/app/reader/state/entries.store.ts:50-52,78,84,92`
- Test: `backend/tests/Controller/Api/FollowedStoryEntriesControllerTest.php`, `backend/tests/Repository/TrendingStoryEntryListRepositoryTest.php`, `frontend/src/app/reader/state/entries.store.spec.ts` (story-visit cases)

**Interfaces:**
- API: each entry row of `GET /api/entries/trending-stories/{id}` gains `joinedAt` (ATOM): the latest `joined_at` among the row's entry and the collapsed copies the reader sees in the story. That is the same rule `joinedAfter` applies, so the row is new exactly when `joinedAt > lastOpenedAt`. `newEntryIds` stays and is now filled on every page. `lastOpenedAt` stays.
- `TrendingStoryEntryListRepository::joinedAtByEntry(TrendingStoryListQuery $query, array $entryIds): array` returns `array<int, \DateTimeImmutable>` and replaces `joinedAfter`.
- `FollowedStoryEntriesModel(array $rows, \DateTimeImmutable $lastOpenedAt, array $joinedAtByEntry)` with `newEntryIds(): list<int>` (page order, `joinedAt > lastOpenedAt`) and `withRows()` as today. `FollowedStoryPage::of()` adds `joinedAt` to each entry array by id.
- Frontend `StoryVisit` holds `visitedStoryId` and `visitOpenedAt` (`accountSignal<string | null>`). `landed(query, page)` behaves as follows:
  - On a page of another list, it ends the visit (both null).
  - On the first landing for a new story, it takes `page.lastOpenedAt` and calls `markOpened`.
  - On the visited story, it does nothing.

  `newAmong(entries: EntryDto[]): ReadonlySet<number>` returns the ids with `joinedAt` after `visitOpenedAt`, compared with `Date.parse`. `EntriesStore.newSinceVisitIds = computed(() => this.storyVisit.newAmong(this.rawEntries()))`. `starting()`, `failed()`, `endsBeyond`, `visitEndUnknown` and the marked set go: a failed load empties `rawEntries`, which empties the marks.

- [ ] **Step 1: Write the failing backend tests.**
  - `testEachRowSaysWhenItJoinedTheStory`.
  - `testASecondPageMarksItsNewRowsToo`.
  - `testACollapsedRowJoinsAtItsLatestVisibleCopy`.
  - Repository: `testJoinedAtByEntryReadsOnlyCopiesTheReaderSees` (a copy in a feed excluded from Trending does not count).
- [ ] **Step 2: Write the failing Jest tests.**
  - `it('marks the rows of a later page that joined since the visit began')` (Review Focus 4).
  - `it('keeps the visit's marks across a reload whose lastOpenedAt has moved')` (Review Focus 4).
  - `it('records the visit once per story visit')`.
  - `it('ends the visit when another list lands')`.

  Replace the specs that pinned `newEntryIds`-only behaviour, the "visit end unknown" case included.
- [ ] **Step 3: Run** `php bin/phpunit tests/Controller/Api/FollowedStoryEntriesControllerTest.php tests/Repository/TrendingStoryEntryListRepositoryTest.php` and `docker compose exec -T frontend npx jest src/app/reader/state/entries.store.spec.ts`. Expected: FAIL.
- [ ] **Step 4: Implement** the backend, then the frontend.
- [ ] **Step 5: Run** the same plus `docker compose exec -T frontend npx jest src/app/reader/list src/app/reader/shell`. Expected: PASS.
- [ ] **Step 6: Commit** `feat(#2272): say on every followed-story row when it joined`

### Task 10: The followed-story visit leaves EntriesStore (item 9)

**Files:**
- Rename: `frontend/src/app/reader/state/story-visit.ts` → `frontend/src/app/reader/state/followed-story-visit.ts`
- Modify: `frontend/src/app/reader/state/entries.store.ts` (drop `storyVisit` and `newSinceVisitIds`, add the hook), `frontend/src/app/reader/shell/reader-shell.component.ts` and `.html:185`
- Test: `frontend/src/app/reader/state/followed-story-visit.spec.ts` (the Task 9 visit specs move here), `frontend/src/app/reader/state/entries.store.spec.ts`

**Interfaces:**
- `EntriesStore`: `export interface LandedList { readonly query: EntryQuery; readonly page: EntriesPage | FollowedStoryPageDto }` and `readonly listLanded: Observable<LandedList>` (a private `Subject`, emitted in `load()`'s `next` after the rows are set; `loadMore` does not emit).
- `@Injectable({ providedIn: 'root' }) export class FollowedStoryVisit` subscribes to `listLanded` in its constructor. It exposes `readonly newSinceVisitIds: Signal<ReadonlySet<number>>` (computed over `EntriesStore.entries()`) and keeps Task 9's rules. The shell injects it in a field initializer, so it exists before the first load. It binds `[newSinceVisitIds]="storyVisit.newSinceVisitIds()"`.

- [ ] **Step 1: Write the failing tests.** `entries.store.spec.ts`: `it('announces each first page as it lands, with its query')` and `it('announces no appended page')`. `followed-story-visit.spec.ts`: the Task 9 cases, driven through a stub `EntriesStore` whose `listLanded` is a `Subject` and whose `entries` is a signal.
- [ ] **Step 2: Run** `docker compose exec -T frontend npx jest src/app/reader/state`. Expected: FAIL.
- [ ] **Step 3: Implement.** `grep -n storyVisit frontend/src/app/reader/state/entries.store.ts` should return nothing.
- [ ] **Step 4: Run** `docker compose exec -T frontend npx jest src/app/reader`. Expected: PASS.
- [ ] **Step 5: Commit** `refactor(#2272): let the followed-story visit listen for landed lists`

### Task 11: One segmentation path for run headers and the visit divider (item 10)

**Files:**
- Create: `frontend/src/app/reader/list/entry-list/list-segments.ts`
- Modify: `frontend/src/app/reader/list/entry-list/list-content.ts:75-186`, `frontend/src/app/reader/list/entry-list/hidden-overlay.ts:73-82` (drop `runGroupsWithout`), `frontend/src/app/reader/list/entry-list/entry-list.component.html:115-190`
- Test: `frontend/src/app/reader/list/entry-list/list-segments.spec.ts`, `frontend/src/app/reader/list/entry-list/entry-list.component.spec.ts` (the existing divider specs are the regression net)

**Interfaces:**
- `export interface ListSegment { readonly divider: RunHeaderBlock | SinceVisitBlock | null; readonly entries: EntryDto[] }`
- `export interface SegmentMarks { readonly showsRunHeader: (group: RunGroup) => boolean; readonly newSinceVisitIds: ReadonlySet<number>; readonly newestFirst: boolean }`
- `export function segmentList(entries: EntryDto[], marks: SegmentMarks): ListSegment[]` runs `groupByRun`, gives a run its header when `showsRunHeader` allows it, and splits the first segment before its first non-new row. The split only happens when the list is newest first, opens with a new row and holds an older one, which is today's `visitEnd` (`list-content.ts:180-186`).
- `export function segmentsWithout(segments: ListSegment[], hidden: ReadonlySet<number>): ListSegment[]` applies the `withoutEmptyRuns` rules (`hidden-overlay.ts:63-71`): a segment emptied by hiding goes, and the visit divider goes when nothing new is left above it.
- `export function segmentKey(segment: ListSegment, index: number): string` keys a segment by its divider's `listBlockKey`, else `head:${index}`.
- `ListContent` gets `readonly segments` and `readonly visibleSegments`. `blocks` becomes the segments flat-mapped to `[divider?, ...planMagazine({ …collapse, entries, complete: isLast ? complete : true })]`, which keeps the fast path when there is one divider-less segment. `runGroups`, `visibleRunGroups`, `visitEndId`, `showRunHeader` and `visitEnd` go. The list layout renders `@for (segment of content.visibleSegments(); track segmentKey(segment, $index))`, with a run header or a list divider from `segment.divider`.

- [ ] **Step 1: Write the failing tests.**
  - `it('gives each shown run its header')`.
  - `it('splits a followed story at its first row that is not new')`.
  - `it('draws no visit divider oldest first or when the list does not open with a new row')`.
  - `it('drops the visit divider once hiding leaves nothing new above it')`.
  - `it('keeps one divider-less segment for a plain list')`.
- [ ] **Step 2: Run** `docker compose exec -T frontend npx jest src/app/reader/list/entry-list`. Expected: FAIL.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run** the same and `src/app/reader/list`. Expected: PASS, with the existing `entry-list.component.spec.ts` divider cases unchanged in both layouts.
- [ ] **Step 5: Commit** `refactor(#2272): segment the list once for run headers and the visit divider`

### Task 12: One owner of "is this story followed" (item 12)

**Files:**
- Modify: `frontend/src/app/reader/state/followed-stories.store.ts`, `frontend/src/app/reader/trending/trending-follow-action.ts`, `frontend/src/app/reader/trending/story-actions/story-actions.ts` + component `.html` (Task 1), `frontend/src/app/reader/state/entries.store.ts:293-325` (delete `markStoryUnfollowedLocally` and `setStoryFollowed`; rename the private `reportedMutation` to public `runReported`), `frontend/src/app/reader/state/in-flight-patches.ts:5` (drop `'story'`), `frontend/src/app/reader/shell/followed-story-unfollow.service.ts:36-41`
- Test: `frontend/src/app/reader/state/followed-stories.store.spec.ts`, `frontend/src/app/reader/trending/trending-follow-action.spec.ts`, `frontend/src/app/reader/trending/story-actions/story-actions.component.spec.ts`

**Interfaces:**
- `FollowedStoriesStore`:
  - `isFollowed(story: TrendingStory): boolean` checks, in order: the follow on the wire or awaiting its reload for that id, then the loaded list once the store has answered at least once, then `story.followed`.
  - `setFollowing(storyId: number, following: boolean): Observable<void>`. It ignores a call while one for that id is on the wire (today's `onTheWire(entryId, 'story')` guard). It records the intent and sends PUT or DELETE. On success a follow `load()`s, and its intent is cleared by the next adopted load. An unfollow removes the row. On failure it drops the intent.
  - `unfollow(id, onSuccess?)` stays for the sidebar and goes through the same intent map.
- `StoryActions` gains `isFollowed(story: TrendingStory): boolean`. The card's bell reads it instead of `story.followed`.
- `TrendingFollowAction.run(entry)` calls `entries.runReported(followedStories.setFollowing(id, !followedStories.isFollowed(story)), {}, retry)`, which keeps today's banner and retry.
- `FollowedStoryUnfollow.unfollow` no longer patches entries.

- [ ] **Step 1: Write the failing tests.**
  - `it('shows a follow at once and keeps it until the reload lists the story')` (Review Focus 5).
  - `it('shows the card's own flag until the followed list has answered')` (Review Focus 5).
  - `it('puts a failed follow back')`.
  - `it('unfollowing from the sidebar unfollows the card')` (story-actions spec reading the store).
  - `it('ignores a second click while the first follow is on the wire')`.
- [ ] **Step 2: Run** `docker compose exec -T frontend npx jest src/app/reader/state/followed-stories.store.spec.ts src/app/reader/trending`. Expected: FAIL.
- [ ] **Step 3: Implement** and delete the row-sync paths. `grep -rn "markStoryUnfollowedLocally\|setStoryFollowed" frontend/src` should return nothing.
- [ ] **Step 4: Run** `docker compose exec -T frontend npx jest src/app/reader`. Expected: PASS.
- [ ] **Step 5: Commit** `refactor(#2272): let the followed stories alone say whether a story is followed`

### Task 12b: A regrouped entry keeps its earliest Trending join (item 7, controller ruling: rule (a))

**Files:** `backend/src/Repository/TrendingStoryHistoryRepository.php` (the member insert for joining entries), test `backend/tests/Repository/TrendingStoryHistoryRepositoryTest.php`, `backend/tests/Service/Curation/Trending/SnapshotBuilder/TrendingSnapshotBuilderTest.php`.

**Rule:** when an entry joins a story, its `joined_at` is the earliest `joined_at` that entry holds in any `trending_story_entry` row (any story), else the build time. A genuinely new entry still joins at the build time.

- [ ] **Step 1: Write the failing tests.** Repository: `testAnEntryJoiningASecondStoryKeepsItsEarliestJoin`. Builder: `testAnEntryTheWeekClaimsAndTheDayReattachesIsNotNewAgain`: week claims e from D into A; the next day build re-attaches e's group to D; e's row in D carries e's original join, so `newCount` for D's follower does not rise.
- [ ] **Step 2: Run** `php bin/phpunit tests/Repository/TrendingStoryHistoryRepositoryTest.php tests/Service/Curation/Trending/SnapshotBuilder`. Expected: FAIL.
- [ ] **Step 3: Implement** in the one insert path (a `COALESCE((SELECT MIN(joined_at) FROM trending_story_entry WHERE entry_id = ?), :builtAt)` or one batched read; keep it a set operation).
- [ ] **Step 4: Run** the same plus `composer check`, `composer md`. Expected: PASS.
- [ ] **Step 5: Commit** `fix(#2272): keep an entry's earliest trending join when it changes story` — its own commit, so Lars can drop it.

### Task 13: Branch verification

- [ ] **Step 1:** Backend from `backend/`: run `composer check`, `composer md` (after `rm -rf ~/.pdepend`), `php bin/phpunit`, `docker compose exec php composer test:parallel` and `composer infection:diff`. Expected: all green, MSI ≥ `minMsi`.
- [ ] **Step 2:** Frontend from `frontend/`: run `npm run check` inside the container (`docker compose exec -T frontend npm run check`). Expected: green.
- [ ] **Step 3: Measure.** On the dev Docker DB (rebuilding is fine, clearing is not), run `docker compose exec php bin/console app:trending:rebuild day` three times in a row, then the same for `week`. Keep the worker from building in between. Report per build the kept, created, merged and split figures the command prints. Before and after the second and third builds, dump `SELECT time_window, entry_id, story_id FROM trending_story_placement ORDER BY 1, 2`. Expected: 100 % of ids survive, and the dumps are identical.
- [ ] **Step 4:** Read today's dev log (`ls -t backend/var/log/dev-*.log | head -1 | xargs tail -n 200 | jq .`) for deprecations and swallowed errors.
- [ ] **Step 5:** Run `/simplify`, then open a PR into `develop` with `Closes #2272`. The body records the Rulings, item 7 as an open watch, and the Strato duplicate counts from Task 7 Step 6.

## Open questions for Lars

1. **Item 7:** fix the re-count with rule (a), where an entry joining a story keeps its earliest Trending join, or rule (b), where the claim keeps the old row and the matching index ignores members week-placed elsewhere? Or leave it a watch until it is seen on Strato?
2. **Item 13:** the gate means a followed count can lag up to one day interval (10 min) after a change only the reader made, such as including or excluding a feed in Trending. Accept that, or should those switches call `FollowedStoriesStore.load()`?
3. **Item 2:** a keyed locator for two fixed windows, per the house pattern, or a plain `match` in the builder?
