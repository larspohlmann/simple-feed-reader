# Per-user, story-first Trending Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Each user owns their Trending stories, built from their own Trending-included feeds, and the API and SPA treat a story as the object its articles hang off. Today a story is the lead article's row.

**Architecture:** `trending_story`, `trending_story_placement` and `trending_snapshot` gain a `user_id`. The build runs once per user and window, over that user's included feeds; matching, merging and pruning stay inside that user's stories. The read side drops every subscription and inclusion join, because a user's story holds only their own entries. `GET /api/trending` returns story objects with their articles; the SPA's shared list pipeline carries a list item that is either an entry row or a story.

**Tech Stack:** Symfony 8.1 / PHP 8.5, Doctrine DBAL native SQL (SQLite + MySQL), Angular 20 signals, Jest, Playwright.

**Spec:** GitHub issue #2328 (`gh issue view 2328`), "Decisions" section. This plan argues from it; read both.

## Global Constraints

- One PR for the whole issue; its body says `Closes #2328`. Commits are `type(#2328): …`.
- Follows and hides were never released (not in `v1.9.4`): the migration **deletes** every Trending row and carries nothing over. Following and hiding stay as features.
- Active user = `user.last_login_at >= now - 14 days`. Users whose `ReadingSettings::isTrendingEnabled()` is false are not built (nobody would see the result; a switch-on is built on the next sweep).
- A due build is skipped when none of the user's Trending-included feeds has an entry with `created_at` after that user's last `built_at` for the window.
- The build keeps every group with `outletCount() >= TrendingWindow::MULTIPLE_SOURCES` (2); the 3 (day) / 5 (week) floor and its relaxation to 5 shown stories stay read-time (`ReaderWindows`).
- Inclusion switches (`TrendingInclusionSql`, `InclusionScopeDql` with `InclusionSurface::Trending`) are applied **only in the build**. No Trending read joins `subscription` for inclusion any more.
- Lead choice stays `TrendingStoryCardModel`'s; only its wire shape changes.
- Look stays the same (not pixel-exact): before/after screenshots at the usual viewports (see the screenshot-refresh recipe in memory).
- Every `src` file touched is PHPMD-clean; PHPStan max with no new ignores; `composer tramp` clean.

## Review Focus

1. **Cross-user ids:** a story id belongs to one user. Following, hiding, opening or listing another user's story id must answer 404 (`TrendingStoryNotFoundException` / `TrendingStoryNotFollowedException`), never act on it. Pinned in Task 6.
2. **Shared entries:** two users subscribed to the same feeds get two independent story sets from the same entries. A build for user A never moves, claims, merges or prunes user B's members, placements or stories. Pinned in Task 3.
3. **A user with no snapshot yet** (new account, Trending just switched on): `GET /api/trending` returns `builtAt: null, stories: []`, and `followed` returns `nextBuildDueAt: null`, without error. Pinned in Task 6.
4. **Account deletion** cascades through the user's stories, members, placements, snapshots, follows and hides. Pinned in Task 1.
5. **An optimistic patch to an article inside a story** (mark read, star, hide-post) reaches the story card's copy, as it reached a `duplicates` copy before. Pinned in the frontend store task.

---

## Phase 1 — backend: stories per user

### Task 1: Schema — stories, placements and snapshots carry their user

**Files:**
- Modify: `backend/src/Entity/TrendingStory.php`, `backend/src/Entity/TrendingStoryPlacement.php`, `backend/src/Entity/TrendingSnapshot.php`
- Create: `backend/migrations/Version20261118120000.php`
- Test: `backend/tests/Entity/TrendingStoryCascadeTest.php`

**Interfaces:**
- Produces:
  - `TrendingStory`: `ManyToOne User $user`, column `user_id` NOT NULL, `onDelete: 'CASCADE'`, index `idx_trending_story_user (user_id)`. Constructor: `__construct(User $user, \DateTimeImmutable $firstSeen, \DateTimeImmutable $lastSeen, \DateTimeImmutable $joinedAt)`.
  - `TrendingStoryPlacement`: a third `#[ORM\Id]` `ManyToOne User $user` (column `user_id`, CASCADE). Primary key `(user_id, time_window, entry_id)`. Constructor gains `User $user` first.
  - `TrendingSnapshot`: `#[ORM\Id]` `ManyToOne User $user` (column `user_id`, CASCADE) next to `time_window`, so the key is `(user_id, time_window)`. New column `active_feed_count` INT NOT NULL with `getActiveFeedCount(): int`. Constructor gains `User $user` first and `int $activeFeedCount` after `$entryCount`.

- [ ] **Step 1: Write the failing test** — `TrendingStoryCascadeTest::testDeletingAUserRemovesTheirTrendingRows`. It seeds two users, gives each a story with one member, one placement, one snapshot, a follow and a hide (DBAL inserts), and deletes user A through the entity manager. It asserts that `trending_story`, `trending_story_entry`, `trending_story_placement`, `trending_snapshot`, `trending_story_follow` and `trending_story_hide` each hold user B's row only (`COUNT(*) = 1` per table).
- [ ] **Step 2: Run it** — `php bin/phpunit tests/Entity/TrendingStoryCascadeTest.php`. Expected: FAIL (no `user_id` column).
- [ ] **Step 3: Change the three entities** as in Interfaces. Fix every `new TrendingStory(`, `new TrendingStoryPlacement(` and `new TrendingSnapshot(` the test suite has (`grep -rn` them in `tests/`).
- [ ] **Step 4: Write the migration.** `up()` first deletes in FK order: `trending_story_follow`, `trending_story_hide`, `trending_story_placement`, `trending_story_entry`, `trending_snapshot`, `trending_story`. It then alters the three tables per platform, as `Version20261116220000` does: `isTransactional(): false`, a MySQL branch, and a SQLite branch that rebuilds the tables. Take the exact DDL, constraint names included, from `bin/console doctrine:schema:update --dump-sql` on each platform (SQLite natively, MySQL via `docker compose exec php`). `down()` reverses the DDL and keeps no data. Description: `'Trending stories, placements and snapshots belong to one user (#2328); the unreleased rows are dropped.'`
- [ ] **Step 5: Verify the migration on both platforms**
  - SQLite: on an empty database, `bin/console doctrine:migrations:migrate -n` then `bin/console doctrine:schema:validate`.
  - MySQL: `docker compose exec php` with the same two commands against a scratch database, never the dev DB (dev-database-safety memory).
  - Expected: `[OK] The database schema is in sync with the mapping files.` on both.
- [ ] **Step 6: Run the test** — expected PASS.
- [ ] **Step 7: Commit** — `refactor(#2328): trending stories, placements and snapshots belong to a user`

### Task 2: The build reads one user's included feeds

**Files:**
- Modify: `backend/src/Repository/TrendingEntryRepository.php`, `backend/src/Service/Curation/Trending/TrendingGrouping.php`, `backend/src/Service/Curation/Trending/TrendingExplainer.php`, `backend/src/Command/TrendingExplainCommand.php`
- Test: `backend/tests/Repository/TrendingEntryRepositoryTest.php`, `backend/tests/Service/Curation/Trending/TrendingExplainerTest.php`, `backend/tests/Command/TrendingExplainCommandTest.php`

**Interfaces:**
- Produces:
  - `TrendingEntryRepository::windowEntries(int $userId, \DateTimeImmutable $since): iterable<WindowEntryModel>`. It joins `subscription included ON included.feed_id = e.feed_id AND included.user_id = :user AND TrendingInclusionSql::includes('included')`, and keeps the chunking.
  - `TrendingEntryRepository::hasEntriesCreatedAfter(int $userId, \DateTimeImmutable $after): bool` — `EXISTS` over the same included-subscription join with `e.created_at > :after` (it uses `idx_entry_feed_created`).
  - `activeFeedCount(int $userId, \DateTimeImmutable $since): int` keeps its signature. It is now called by the build (Task 4), not the read side.
  - `TrendingGrouping::collect(int $userId, TrendingWindow $window): TrendingRun`
  - `TrendingExplainer::explain(int $userId, TrendingWindow $window): TrendingExplanationModel`
  - `app:trending:explain` takes a required `--user=<id>` option, as `RefreshFeedsCommand` names it.

- [ ] **Step 1: Write the failing tests** in `TrendingEntryRepositoryTest`:
  - `testWindowEntriesAreOnlyTheUsersIncludedFeeds` — three feeds, each with one entry in the window. The user subscribes to feed 1 (included) and to feed 2 with its Trending switch off, and not to feed 3. Asserts the yielded ids equal `[feed1Entry]`.
  - `testWindowEntriesLeaveOutAFeedUnderASwitchedOffTag` — the tag's Trending switch is off; asserts no entry is yielded.
  - `testHasEntriesCreatedAfter` — one included entry whose `created_at` is T. Asserts `true` for `T - 1 s` and `false` for `T`; also `false` when the only newer entry is in an unsubscribed feed.
- [ ] **Step 2: Run them** — expected FAIL (wrong signature).
- [ ] **Step 3: Implement** the repository changes. Thread `$userId` through `collect` and `explain`, and add the `--user` option. Fix the explainer and command tests by passing the seeded user's id; the command test gets `testRejectsAMissingUser` (returns `Command::INVALID`).
- [ ] **Step 4: Run** `php bin/phpunit tests/Repository/TrendingEntryRepositoryTest.php tests/Service/Curation/Trending/TrendingExplainerTest.php tests/Command/TrendingExplainCommandTest.php` — expected PASS.
- [ ] **Step 5: Commit** — `refactor(#2328): a trending window reads one user's included feeds`

### Task 3: Stories are written, matched, merged and pruned within one user

**Files:**
- Modify: `backend/src/Repository/TrendingStoryHistoryRepository.php`, `backend/src/Service/Curation/Trending/Model/StoryBuildModel.php`, `backend/src/Service/Curation/Trending/StoryBuildPolicy/{StoryBuildPolicyInterface,DayStoryBuildPolicy,WeekStoryBuildPolicy}.php`
- Test: `backend/tests/Repository/TrendingStoryHistoryRepositoryTest.php`, `backend/tests/Service/Curation/Trending/StoryBuildPolicy/WeekStoryBuildPolicyTest.php`

**Interfaces:**
- Consumes: Task 1's columns.
- Produces:
  - `StoryBuildModel(int $userId, TrendingWindow $window, \DateTimeImmutable $since, \DateTimeImmutable $builtAt)`
  - `TrendingStoryHistoryRepository`:
    - `storedInWindow(int $userId, \DateTimeImmutable $since): list<StoredStoryModel>`
    - `saveAll(StoryBuildModel $build, array $assignments): array<int,int>` (inserts `user_id`)
    - `place(StoryBuildModel $build, array $storyIdByEntry): int` (placement rows carry `user_id`; `placedIn` reads only this user's)
    - `pruneUnfollowedOutside(StoryBuildModel $build): int`
    - `merge(StoryMergeModel $merge): void` is unchanged in signature.
  - Every statement that selects members by `entry_id` across stories is scoped to `story_id IN (SELECT id FROM trending_story WHERE user_id = ?)`. That covers `earliestJoinsOf`, the claimed-entries `absorb` in `save`, and the derived tables in `EARLIEST_SOURCE_JOIN` / `AMONG_SOURCE_ENTRIES`. A merge only ever joins two stories of one user, so `absorb` by `story_id` is already scoped.
  - `StoryBuildPolicyInterface::prune(StoryBuildModel $build): int` is unchanged; the week policy passes `$build` through.

- [ ] **Step 1: Write the failing tests** in `TrendingStoryHistoryRepositoryTest`:
  - `testTwoUsersHoldTheSameEntriesInSeparateStories` — users A and B; `saveAll` and `place` for both with the same two entry ids. Asserts two `trending_story` rows (one per `user_id`), four member rows and four placement rows, and that `storedInWindow(A)` returns only A's story.
  - `testAClaimNeverTakesAnotherUsersMember` — B's story holds entry 7. A's assignment claims entry 7 (`claimedEntryIds: [7]`). Asserts B's member row and placement for entry 7 are untouched.
  - `testAnEntryKeepsItsEarliestJoinOnlyWithinItsUser` — B's member 7 joined at T1; A's first save of entry 7 at T2. Asserts A's `joined_at` is T2, not T1.
  - `testPruneLeavesOtherUsersStories` — both users hold a story with no member in the week. Asserts pruning A deletes only A's.
- [ ] **Step 2: Run them** — expected FAIL.
- [ ] **Step 3: Implement** the scoping in Interfaces. Update the callers in `TrendingSnapshotBuilder` (pass `$build`; its `$userId` is threaded for real in Task 4) and the policies, and adapt the existing tests to the new signatures.
- [ ] **Step 4: Run** both test files — expected PASS.
- [ ] **Step 5: Commit** — `refactor(#2328): trending story history stays within one user`

### Task 4: One build per user and window, with its own lock and snapshot

**Files:**
- Modify: `backend/src/Service/Curation/Trending/SnapshotBuilder/{SnapshotBuilderInterface,TrendingSnapshotBuilder}.php`, `backend/src/Repository/TrendingSnapshotRepository.php`, `backend/src/Service/Curation/Trending/Model/TrendingBuildReportModel.php`, `backend/src/Command/TrendingRebuildCommand.php`
- Test: `backend/tests/Service/Curation/Trending/SnapshotBuilder/TrendingSnapshotBuilderTest.php`, `backend/tests/Repository/TrendingSnapshotRepositoryTest.php`, `backend/tests/Command/TrendingRebuildCommandTest.php`

**Interfaces:**
- Consumes: Tasks 2–3.
- Produces:
  - `SnapshotBuilderInterface::build(int $userId, TrendingWindow $window): TrendingBuildReportModel` — throws `TrendingBuildBusyException`.
  - The lock is named `TrendingSnapshotBuilder::LOCK_NAME . '-' . $userId` (`'trending-build-42'`); different users build concurrently.
  - The build keeps groups at `TrendingWindow::MULTIPLE_SOURCES`. `TrendingGrouping::stories()` filters on `MULTIPLE_SOURCES` instead of `minSubscribedFeeds()`, and `minSubscribedFeeds()` is deleted if nothing else calls it.
  - `TrendingBuildReportModel` gains `int $userId` (first) and `int $activeFeedCount` (after `$entryCount`); `toLogContext()` adds `userId`.
  - `TrendingSnapshotRepository::lastBuilds(int $userId): TrendingLastBuildsModel`, and `recordSnapshot(TrendingBuildReportModel $report)`, which writes `user_id` and `active_feed_count`.
  - `TrendingLastBuildsModel` gains `activeFeedCount(TrendingWindow $window): int` (0 when never built).
  - `app:trending:rebuild <window> --user=<id>` (required option).

- [ ] **Step 1: Write the failing tests:**
  - `TrendingSnapshotBuilderTest::testBuildsOnlyTheUsersStories` — two users with different subscriptions over the same three-outlet story. Asserts each build writes a story with `user_id` equal to its user, and that only the subscribed user gets the story.
  - `TrendingSnapshotBuilderTest::testKeepsATwoOutletGroup` — asserts a two-outlet group is stored.
  - `TrendingSnapshotBuilderTest::testAnotherUsersRunningBuildDoesNotBlock` — holds `trending-build-<B>`; asserts `build(A, Day)` succeeds and `build(B, Day)` throws `TrendingBuildBusyException`.
  - `TrendingSnapshotRepositoryTest::testLastBuildsAreTheUsers` — snapshots for A (day) and B (week). Asserts `lastBuilds(A)->builtAt(Week)` is null and `activeFeedCount(Day)` is A's.
- [ ] **Step 2: Run them** — expected FAIL.
- [ ] **Step 3: Implement.** The builder computes `activeFeedCount($userId, $build->since)` (Task 2) before the transaction and puts it on the report.
- [ ] **Step 4: Run** the three test files — expected PASS.
- [ ] **Step 5: Commit** — `refactor(#2328): build trending once per user and window`

### Task 5: The sweep builds active users whose feeds have news

**Files:**
- Modify: `backend/src/Service/Curation/Trending/TrendingRebuild.php`, `backend/src/Repository/UserRepository.php`
- Test: `backend/tests/Service/Curation/Trending/TrendingRebuildTest.php`, `backend/tests/Repository/UserRepositoryTest.php`, `backend/tests/Service/Operations/Maintenance/MaintenanceSweep/TrendingTickSweepTest.php`

**Interfaces:**
- Consumes: Task 4's `build`, `lastBuilds`, Task 2's `hasEntriesCreatedAfter`.
- Produces:
  - `UserRepository::trendingReaderIdsLoggedInSince(\DateTimeImmutable $since): list<int>` — users with `last_login_at >= :since`, joined to `user_reading_settings` with Trending enabled (a user without a settings row counts as enabled, since `trendingEnabled` defaults to true), ordered by id.
  - `TrendingRebuild::ACTIVE_DAYS = 14`.
  - `rebuildDue()` and `rebuildDueBefore(\DateTimeImmutable $deadline)` keep their signatures and loop users, then windows. A pair is built when it is due (`lastBuilds($userId)->dueAt($window)`) **and** it was never built or `hasEntriesCreatedAfter($userId, builtAt)` is true. `rebuildDueBefore` asks its time check before each pair, with that user's `expectedDurationMilliseconds`.
  - `TrendingRebuildReportModel` and `TrendingRebuildJson` are unchanged (`windowsRebuilt` now counts user-window builds).

- [ ] **Step 1: Write the failing tests:**
  - `UserRepositoryTest::testTrendingReaderIdsLoggedInSince` — users logged in 13 and 15 days ago, one never logged in, and one recent user with Trending off. Asserts `[the 13-day user]`.
  - `TrendingRebuildTest::testBuildsEachActiveUsersDueWindows` — a fake builder records `(userId, window)` calls. Two active users, never built. Asserts four calls, in user-then-window order.
  - `TrendingRebuildTest::testSkipsAPairWithNothingNew` — a snapshot older than the interval and no entry created since. Asserts no call for that pair.
  - `TrendingRebuildTest::testOneUsersBusyBuildDoesNotStopTheNext` — the builder throws busy for user A. Asserts user B is still built.
  - Adapt `TrendingTickSweepTest` to the per-user loop.
- [ ] **Step 2: Run them** — expected FAIL.
- [ ] **Step 3: Implement.** If PHPMD flags `TrendingRebuild` after the loop grows, fix the design it points at, and don't split by count (#2025).
- [ ] **Step 4: Run** the test files — expected PASS.
- [ ] **Step 5: Commit** — `refactor(#2328): the trending sweep builds each active reader`

### Task 6: Reads, follows and hides work on the user's own stories

**Files:**
- Modify:
  - `backend/src/Repository/TrendingStoryRepository.php`
  - `backend/src/Repository/TrendingStoryFollowRepository.php`
  - `backend/src/Repository/TrendingStoryEntryListRepository.php`
  - `backend/src/Service/Curation/Trending/{TrendingStories,ReaderWindows,TrendingStoryFollowing,TrendingStoryHiding}.php`
  - `backend/src/Repository/TrendingEntryRepository.php` (`activeFeedCount` is no longer read here)
- Test:
  - `backend/tests/Repository/TrendingStoryRepositoryTest.php`
  - `backend/tests/Controller/Api/TrendingStoryFollowControllerTest.php`
  - the hide controller's test
  - `backend/tests/Service/Curation/Trending/ReaderWindowsTest.php` (create if absent)

**Interfaces:**
- Consumes: Task 4's `lastBuilds($userId)->activeFeedCount()`.
- Produces:
  - `TrendingStoryRepository::requireOwnedBy(int $userId, int $storyId): void` throws `TrendingStoryNotFoundException`. It replaces `requireExists`.
  - `rankedForUser` selects `FROM trending_story story WHERE story.user_id = :user` and drops the `subscribed` and `earlier_reader` subscription joins. `noEarlierCopy` keeps its lowest-id rule without the subscription join. `shownEntriesByStory` drops its `reader` join.
  - `TrendingStoryFollowRepository::seenThrough()` is deleted; `headline()` and `newCount()` read members without a subscription join.
  - `TrendingStoryEntryListRepository::applyMembership()` drops `InclusionScopeDql::restrict(…Trending)`.
  - `ReaderWindows::relaxedMinimum` takes the active feed count from the snapshot (`TrendingSnapshotRepository::lastBuilds($userId)->activeFeedCount($window)`) instead of querying.
  - `TrendingStories::forReader` reads `builtAt` from `lastBuilds($userId)`; `TrendingStoryFollowing::followedBy` reads `nextBuildDueAt()` from `lastBuilds($userId)`.

- [ ] **Step 1: Write the failing tests:**
  - `TrendingStoryFollowControllerTest::testFollowingAnotherUsersStoryIs404` — `PUT /api/trending/stories/{B's id}/follow` as A returns `Response::HTTP_NOT_FOUND` and writes no row. The same for `POST /api/trending/hidden` with B's id, and for `POST /api/trending/stories/{B's id}/opened` (404 through `TrendingStoryNotFollowedException`).
  - `TrendingStoryRepositoryTest::testRankedForUserIsOnlyTheirStories` — A and B each hold a story over the same entries. Asserts A's ranking returns A's story id only.
  - `TrendingStoryRepositoryTest::testAMemberFromASwitchedOffFeedStillCounts` — the feed is switched off after the build. Asserts the story still ranks, and its count is unchanged until the next build (inclusion is build-only).
  - `ReaderWindowsTest::testRelaxedMinimumUsesTheSnapshotsActiveFeedCount` — the snapshot has `active_feed_count = 40`. Asserts the minimum equals `TrendingWindow::Day->minimumOutletsFor(40)` when 5 stories reach it.
  - A controller test: `GET /api/trending?window=day` for a user without a snapshot returns `builtAt: null, stories: []`, and `GET /api/trending/followed` returns `nextBuildDueAt: null`.
- [ ] **Step 2: Run them** — expected FAIL.
- [ ] **Step 3: Implement.** Delete `requireExists` once nothing calls it.
- [ ] **Step 4: Run** `php bin/phpunit --filter Trending` — expected PASS.
- [ ] **Step 5: Commit** — `refactor(#2328): trending reads the reader's own stories`

### Task 7: `GET /api/trending` is story-first

**Files:**
- Modify: `backend/src/Http/TrendingJson.php`
- Test: `backend/tests/Http/TrendingJsonTest.php` (create if absent), the Trending controller test

**Interfaces:**
- Produces the wire shape:

```json
{ "window": "day", "builtAt": "…", "stories": [ {
    "id": 12, "outletCount": 4, "viewed": false, "shareCount": 3, "followed": true,
    "leadEntryId": 501,
    "articles": [ { "…EntryJson::listRow fields…": "", "shares": [ { "…listRow…": "" } ] } ]
} ] }
```

  `articles[0]` is the lead (`$card->articles->lead`), followed by `$card->articles->others` in their order. No article row carries `story` or `duplicates`.

- [ ] **Step 1: Write the failing test** `TrendingJsonTest::testAStoryCarriesItsArticlesLeadFirst`. A card with lead 501 and others 502 and 503; asserts `stories[0].leadEntryId === 501`, the article ids are `[501, 502, 503]`, and there is no `duplicates` key on any article.
- [ ] **Step 2: Run** — expected FAIL.
- [ ] **Step 3: Implement** `TrendingJson::story()` to the shape above.
- [ ] **Step 4: Run** the test and the controller test — expected PASS.
- [ ] **Step 5: Commit** — `feat(#2328): the trending response is story-first`

---

## Phase 2 — SPA: a story is a list item

All paths below are relative to `frontend/src/app/reader/` unless they start with `frontend/`.

The shared pipeline carries `ListItem = EntryDto | TrendingStoryDto`. Anything that plans, keys or segments an item reads `leadOf(item)`: the entry itself, or a story's `articles[0]`. Block keys, `data-entry-id`, leaving ids and hidden-overlay ids therefore stay lead entry ids. A Trending list holds only stories and every other list only entries, so story ids never mix with entry ids in one list. Magazine blocks carry `story: TrendingStoryDto | null` next to `entry`, and every story widget takes the story as an input instead of reading `entry.story`.

Until Task 11, `tsc` and `npm run check` are expected red. Each task runs only its named Jest specs, via `docker compose exec -T frontend npx jest <paths>`. Task 11 makes the whole gate green again.

### Task 8: Story types, list items and the Trending page mapping

**Files:**
- Modify: `trending/trending.models.ts`, `trending/trending-page.ts`, `trending/trending-api.ts`, `reader-api.ts`, `models.ts`
- Create: `state/list-item.ts`, `state/list-page.ts`
- Modify: `frontend/src/testing/entry-testing.ts`
- Test: `state/list-item.spec.ts`, `trending/trending-page.spec.ts`, `trending/trending-api.spec.ts`, `reader-api.spec.ts`

**Interfaces:**
- Produces:
  - `trending.models.ts`:
    - `TrendingStoryDto { id: number; followed: boolean; outletCount: number; viewed: boolean; shareCount: number; leadEntryId: number; articles: StoryArticleDto[] }`
    - `StoryArticleDto = EntryDto & { shares: EntryDto[] }`
    - `TrendingStory` is deleted, replaced by `TrendingStoryDto`. `FollowedStoriesStore.isFollowed` takes `Pick<TrendingStoryDto, 'id' | 'followed'>`.
  - `state/list-item.ts`:
    - `type ListItem = EntryDto | TrendingStoryDto`
    - `isStory(item: ListItem): item is TrendingStoryDto` (`'articles' in item`)
    - `leadOf(item: ListItem): EntryDto`
    - `articlesOf(item: ListItem): EntryDto[]` (`[item]`, or the story's articles)
    - `storyOf(item: ListItem): TrendingStoryDto | null`
  - `state/list-page.ts`: `ListPage { items: ListItem[]; nextCursor: string | null; matchedWords?: EntriesPage['matchedWords'] }` and `listPageOf(page: EntriesPage): ListPage`. It spreads the extra fields of `FollowedStoryPageDto` through as `ListPage & Omit<FollowedStoryPageDto, keyof EntriesPage>`.
  - `trending-page.ts`: `TrendingResponseDto { window; builtAt; stories: TrendingStoryDto[] }`, and `trendingPage(response): ListPage` returning `{ items: response.stories, nextCursor: null }`.
  - `TrendingApi.page(window): Observable<ListPage>`, and `ReaderApi.entries(query, cursor?): Observable<ListPage>`. The non-Trending path maps through `listPageOf`.
  - `entry-testing.ts`:
    - `aStory(overrides?: Partial<TrendingStoryDto>): TrendingStoryDto`, whose default is one article (`aStoryArticle()`) with `leadEntryId` matching it
    - `aStoryArticle(overrides?: Partial<StoryArticleDto>): StoryArticleDto` (`anEntry` plus `shares: []`)

- [ ] **Step 1: Write the failing tests:**
  - `list-item.spec.ts`:
    - `leadOf` is an entry's own self.
    - `leadOf` of a story is `articles[0]`.
    - `articlesOf` of a story with three articles has length 3.
    - `storyOf` of an entry is null.
  - `trending-page.spec.ts`: `trendingPage({window:'day', builtAt:null, stories:[aStory({id: 4})]})` equals `{items: [that story], nextCursor: null}`.
  - `reader-api.spec.ts`: a `/api/entries` response maps to `items` that equal its `entries`, and a followed-story page keeps `lastOpenedAt`.
- [ ] **Step 2: Run them** — expected FAIL.
- [ ] **Step 3: Implement** the types and functions above.
- [ ] **Step 4: Run them** — expected PASS.
- [ ] **Step 5: Commit** — `refactor(#2328): trending stories and list items as frontend types`

### Task 9: The entries store holds list items

**Files:**
- Modify: `state/entries.store.ts`, `state/entry-rows.ts`, `state/in-flight-patches.ts`, `state/landed-list.ts`, `state/subscriptions.store.ts`, `shell/list-heading.service.ts`, `shell/reader-route-state.service.ts`, `trending/trending-hide-action.ts`, `shell/entry-state-actions.service.ts`
- Test: `state/entries.store.spec.ts`, `state/entry-rows.spec.ts`, `shell/list-heading.service.spec.ts`, `trending/trending-hide-action.spec.ts`

**Interfaces:**
- Consumes: Task 8.
- Produces:
  - `EntriesStore`:
    - `readonly items: Signal<ListItem[]>` (the raw signal becomes `rawItems`).
    - `readonly entries: Signal<EntryDto[]>` = `computed(() => items().flatMap(articlesOf))`, every article a list shows, used for lookups.
    - `entry(id)` and `entryOrCopy(id)` search `entries()` (and `duplicates` and `unlisted`, as now).
    - `load` and `loadMore` take `ListPage.items`.
  - `withPatchedItem(item: ListItem, targets: ReadonlySet<number>, patch): ListItem` in `entry-rows.ts`. An entry is patched as now. A story keeps its identity when no article is targeted, and otherwise gets a new `articles` array with each targeted article patched; `shares` are never patched (as before). `patchEntries` uses it.
  - `InFlightPatches.overlayAll(items: ListItem[]): ListItem[]` overlays story articles too.
  - `hideTrendingStory(story: TrendingStoryDto, outcome)` takes the story, not the lead id. `TrendingHideAction` passes the card's story and still marks the lead id as leaving.
  - `ListHeadingService`'s Trending count is `items().length`.
  - `ReaderRouteState.isListed` and `openLinkCard` read `entries()`. A story's articles are therefore listed and need no `/api/entries/{id}` fetch.
  - `SubscriptionsStore.shiftArticleUnread` is unchanged in shape. Since story articles are no longer anyone's `duplicates`, opening a story article shifts only its own subscription. Before relying on that, check that the server's mark-read does the same, and note the result in the commit.

- [ ] **Step 1: Write the failing tests** in `entries.store.spec.ts` (replacing the "a Trending story" block):
  - `loads a Trending page as story items` — `items()` holds the story, and `entries()` holds its three articles.
  - `marking a story's second article viewed patches it inside the story` — `setState(secondId, {isViewed: true})`. Asserts `items()[0].articles[1].isViewed` and that `articles[0]` is the same object (Review Focus 5).
  - `an in-flight patch survives a Trending reload` — a pending star on an article, then reload. The overlay is applied to the story article.
  - `hideTrendingStory posts the story id` — expects `POST /api/trending/hidden` with `{storyId: 4}`.
  - `list-heading.service.spec.ts`: two stories with three articles each count as 2.
- [ ] **Step 2: Run them** — expected FAIL.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run them** — expected PASS.
- [ ] **Step 5: Commit** — `refactor(#2328): the entries store holds trending stories as list items`

### Task 10: The list pipeline and the magazine planner carry list items

**Files:**
- Modify:
  - `list/entry-list/entry-list.component.{ts,html}`
  - `list/entry-list/{list-content,list-segments,hidden-overlay,estimated-list-bottom,list-reading-focus}.ts`
  - `list/paging.ts`
  - `list/magazine/{magazine-block,magazine-planner,magazine-block-height}.ts`
  - `shell/reader-shell.component.html`
- Test:
  - `list/entry-list/list-segments.spec.ts`
  - `list/magazine/magazine-planner.spec.ts`
  - `list/magazine/magazine-block-height.spec.ts`
  - `list/entry-list/entry-list.component.spec.ts` (its Trending block)

**Interfaces:**
- Consumes: Task 9's `items`.
- Produces:
  - `EntryListComponent.items = input.required<ListItem[]>()`, replacing `entries`; the shell passes `entries.items()`.
  - `ListContentOptions.items: Signal<ListItem[]>` and `rendered: Signal<ListItem[]>`. `visibleEntryCount` counts items.
  - `ListSegment.items: ListItem[]`. `segmentList(items, marks)` groups by `leadOf(item).runId` and matches `newSinceVisitIds` against `leadOf(item).id`.
  - `MagazineBlock`'s entry kinds gain `story: TrendingStoryDto | null`; the group kind is unchanged. `MagazinePlanInput.items: ListItem[]`. The planner sizes and places by `leadOf(item)` and sets `story: storyOf(item)` on the block it makes for that item.
  - `blockHeight(kind, entry, story: TrendingStoryDto | null)`, where `storySharersHeight(story)` = `story ? min(story.articles[0].shares.length, SHOWN_SHARERS) : 0` pills.
  - `onMarkAboveRead`, `strippableEntry` and `isBlockLeaving` keep reading `block.entry`, the lead. Trending offers no mark-above-read.

- [ ] **Step 1: Write the failing tests:**
  - `magazine-planner.spec.ts` — `a story plans like its lead and keeps its story`: the plan for `[aStory({articles: [anImageArticle, aStoryArticle()]})]` has the same kinds as the plan for `[anImageArticle]`, and `blocks[0].story` is the story.
  - `magazine-block-height.spec.ts` — rewrite the sharers case: a story whose lead has 4 shares adds 2 rows (3 pills shown), and a null story adds none.
  - `list-segments.spec.ts` — story items segment by their lead's `runId`.
  - `entry-list.component.spec.ts` (Trending block) — the list renders one card per story, each with `data-entry-id` equal to its `leadEntryId`, in both the magazine and the list layout.
- [ ] **Step 2: Run them** — expected FAIL.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run them** — expected PASS.
- [ ] **Step 5: Commit** — `refactor(#2328): the list pipeline plans trending stories as items`

### Task 11: Story widgets take the story; `EntryDto` loses `story` and `shares`

**Files:**
- Modify:
  - `models.ts` (delete `story?` and `shares?` from `EntryDto`)
  - `trending/story.ts`
  - `trending/shared-article-card.ts`
  - `trending/story-actions/story-actions.component.ts`
  - `trending/story-sharers/story-sharers.component.ts`
  - `trending/story-source-count/story-source-count.component.{ts,html}`
  - `trending/trending-follow-action.ts`
  - `list/entry-row/entry-row.component.{ts,html}`
  - `list/magazine/{entry-block-base.ts,magazine-block.component.html,more-sources.component.{ts,html},entry-duplicates.component.{ts,html},also-foot.ts,entry-kicker-line.component.ts}`
  - every `list/magazine/blocks/*/*.html` that renders `<app-more-sources>` or the kicker line
  - `list/magazine/blocks/entry-link/entry-link.component.ts`
- Test: the specs of every file above, plus `frontend/src/testing/entry-testing.ts`

**Interfaces:**
- Consumes: Task 10's `block.story`.
- Produces:
  - `story.ts`:
    - `isStoryViewed(story: TrendingStoryDto): boolean` = `story.viewed || story.articles.some(isViewed)`
    - `showsUnreadMark(entry: EntryDto, story: TrendingStoryDto | null): boolean`
  - `sharedArticleCard(article: StoryArticleDto): LinkCardDto | null`
  - Inputs:
    - `story = input.required<TrendingStoryDto>()` on `StoryActionsComponent` and `StorySourceCountComponent`
    - `article = input.required<StoryArticleDto>()` on `StorySharersComponent`
    - `story = input<TrendingStoryDto | null>(null)` on `EntryRowComponent`, `EntryBlockBase` (so every magazine block), `MoreSourcesComponent`, `EntryDuplicatesComponent` and `EntryKickerLineComponent`
  - `magazine-block.component.html` passes `[story]="block.story"`. The list layout renders `<app-entry-row [entry]="leadOf(item)" [story]="storyOf(item)">`.
  - A story card's other articles are `story.articles.slice(1)` wherever the code read `entry.duplicates` with the Trending meaning (more-sources mini cards, entry-duplicates' "Also covered by"). `alsoFoot(entry, story)` labels `alsoCoveredBy` when `story` is set.
  - `TrendingFollowAction.toggle(story: TrendingStoryDto)`.
  - `entry-link`'s `ledByItsCard` reads `sharedArticleCard(story.articles[0])` when the block has a story, and is false otherwise.

- [ ] **Step 1: Adapt the specs first.** Every spec that built `anEntry({story: aStory(), duplicates: [...], shares: [...]})` builds `aStory({articles: [...]})` and passes it as the input. Keep each spec's assertion unchanged: same text, same icon (`STORY_ICON`), same counts, same open-by-id calls. A spec whose assertion has to change is a behaviour change and needs a reason in the commit.
- [ ] **Step 2: Run them** — expected FAIL.
- [ ] **Step 3: Implement**, then delete `story?` and `shares?` from `EntryDto` and fix whatever `tsc` reports.
- [ ] **Step 4: Run** `docker compose exec -T frontend npm run check` — expected: all green (ESLint, Prettier, Stylelint, tsc for app/spec/e2e, Jest).
- [ ] **Step 5: Commit** — `refactor(#2328): story widgets take the story, not the lead's row`

### Task 12: The Trending smoke and the look

**Files:**
- Modify: `frontend/e2e/trending-smoke.spec.ts`

- [ ] **Step 1: Rewrite the fixture** to the story-first `TrendingResponseDto` (`stories: [aStory-shaped objects with articles]`). Keep every assertion and both layouts (`magazine`, `list`).
- [ ] **Step 2: Run** `cd frontend && npm run e2e -- trending-smoke.spec.ts` against the Docker stack — expected PASS.
- [ ] **Step 3: Screenshots** of Trending day and week, magazine and list, at the usual viewports, on this branch. Compare them with the same shots from `develop` taken before Task 8 (take those first if missing). Expected: the same cards, sharers, story line, counts and actions; a small spacing shift is fine.
- [ ] **Step 4: Commit** — `test(#2328): the trending smoke speaks the story-first response`

---

### Final task: Verification before the PR

- [ ] Backend gates, from `backend/`: `composer check`, `composer md`, `php bin/phpunit`, and `docker compose exec php composer test:parallel` (both legs at the same time, never alongside Jest). Then `composer infection:diff`. Expected: all green, MSI ≥ `minMsi`.
- [ ] Migrations: Task 1 Step 5 again, on the final tree.
- [ ] Dev stack: `docker compose exec php bin/console doctrine:migrations:migrate -n`. This drops the dev DB's Trending rows, which is intended and unreleased. Then `bin/console app:trending:rebuild day --user=<dev user id>`, then scan today's `backend/var/log/dev-*.log` with `jq` for errors and deprecations.
- [ ] Frontend: `docker compose exec -T frontend npm run check`, then `npm run e2e -- trending-smoke.spec.ts` against the stack.
- [ ] Screenshots of Trending (magazine and classic, day and week) at the usual viewports, before (on `develop`) and after. Compare: same layout, not pixel-exact.
- [ ] Run `/simplify` over the branch, re-run the gates, then open the PR with `Closes #2328`.
