# Stable Trending Stories, History and Following Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A Trending story keeps its id across rebuilds of both windows, keeps every member it ever had, can be followed from its card into a sidebar row with a "new since you last opened it" count, and is hidden by id.

**Architecture:** Grouping and linking stay exactly as they are. After grouping, a new pure step (`StoryMatching`) matches each group to the stored stories by member overlap; the builder then updates stories in place instead of deleting and re-inserting them. `trending_story_entry` becomes the story's history: each row keeps `joined_at` and two placement flags (`in_day`, `in_week`) that the read path filters on exactly where it filtered `time_window` before. Follows and hides are per-user rows keyed by story id.

**Tech Stack:** Symfony 8.1 / PHP 8.5, Doctrine ORM + DBAL native SQL, MySQL 8.0 (Strato) / 8.4 (Docker) and SQLite; Angular 20 standalone components with signals, Transloco, Jest.

**Spec:** `docs/superpowers/specs/2026-11-16-2128-stable-trending-stories-design.md` (approved, binding). Read it before every task.

## Global Constraints

- Approach A: match groups to stored stories after grouping; `StoryLinks`, `StoryGroups`, `TrendingGrouping`'s grouping and source counting (#2056) do not change.
- Only the week build merges; the day build only attaches (`TrendingWindow::mergesStories()`).
- Overlap = shared entries ÷ size of the smaller of (the group, the story's members whose entry falls inside the build's window).
- A match needs overlap ≥ `StoryMatching::MATCH_THRESHOLD = 0.5` **and** at least one shared entry; the constant carries its reason in one line.
- Assignment is one-to-one per build, greedy by overlap (highest first); ties go to the story with more in-window members, then the lower (older) id; a group left over gets a new story.
- Quiet = no member joined for 48 h (`TrendingStoryFollowing::QUIET_AFTER_HOURS = 48`).
- Pruning runs with the week build only: delete a story no one follows once none of its members' entries falls inside the week window. A followed story is never pruned.
- Follows and hides are per user, by story id; hidden wins on a merge; on a merge the earlier `last_opened_at` stays.
- "New" = joined after the reader's `last_opened_at`, not published after it.
- Counting follows the reader's Trending rules: only feeds they follow and include in Trending (`TrendingInclusionSql` / `InclusionScopeDql` with `InclusionSurface::Trending`), an article once (`url_hash`), a social account once (`author_url`, else the feed).
- No `GET` mutates; opening records through `POST /api/trending/stories/{id}/opened`.
- Datetimes are naive UTC; every new column is `DATETIME_IMMUTABLE` written as `Y-m-d H:i:s` UTC.
- Native iOS stays viable: bearer auth, JSON in, `application/problem+json` out, no browser-only inputs (architecture.md §6 checklist on each new endpoint).
- Every user-visible string in de, en, fr and es (German "du", French "vous", Spanish "tú"; "story" is Geschichte / sujet / noticia; Trending is „Im Trend“ / Tendances / Tendencias).
- CLAUDE.md applies in full: role folders (§10), queries only in `src/Repository`, thin controllers, `*Json`/page mappers in `src/Http`, typed exceptions mapped in `TrendingProblems`, PHPStan max with no new ignores, PHPMD-clean touched `src` files without count-driven splits (`rm -rf ~/.pdepend` before `composer md`), phptramp, Infection on changed files, `Response::HTTP_*` never a magic number, no comments that do not clear the bar.
- Frontend: standalone + signals, component styles in sibling `.scss`, no hex/px literals outside the theme, `docs/design-language.md` for tokens and shared components; frontend tests run in the Docker frontend container.
- Never clear the dev database, never `docker compose down -v`.

## Review Focus

1. **D41 with nothing shown in the day.** The week read now leaves out day story ids with `story.id NOT IN (...)`; an empty list must leave out nothing (a bound `IN (NULL)` inside `NOT IN` would hide every story). Test in Task 2.
2. **An entry on the window boundary.** The stored-members read and the grouping must use one `since`; otherwise a group member is "new" to a story that already holds it and the member insert hits the primary key. Test in Task 4.
3. **A merge where both stories hold the same entry.** Moving Y's rows into X must not violate the `(story_id, entry_id)` key, must keep the earlier `joined_at` and the day flag, and must not use a self-referencing `UPDATE … (SELECT … same table)` (MySQL error 1093). Test in Task 4 on both legs.
4. **A reader following (or hiding) both stories of a merge.** One follow row survives with the earlier `followed_at` and `last_opened_at`; one hide survives. Test in Task 4.
5. **A followed story the reader can no longer see** (they unsubscribed every member feed, or retention removed its members). It stays in `GET /api/trending/followed` with `headline: null`, `newCount: 0`, and the sidebar shows the fallback label. Tests in Task 6 and Task 10.

---

### Task 1: StoryMatching — the pure matching step

**Files:**
- Create: `backend/src/Service/Curation/Trending/StoryMatching.php`
- Create: `backend/src/Service/Curation/Trending/Model/StoredStoryModel.php`, `StoryAssignmentModel.php`, `StoryMergeModel.php`, `StoryMatchModel.php`
- Modify: `backend/src/Enum/TrendingWindow.php` (add `mergesStories()`)
- Test: `backend/tests/Service/Curation/Trending/StoryMatchingTest.php`, `backend/tests/Enum/TrendingWindowTest.php` (add a case if the file exists, else create it)

**Interfaces:**
- Consumes: `TrendingStoryModel` (`members: non-empty-list<StoryMemberModel>`, `firstSeen`, `lastSeen`), `StoryMemberModel::$entryId`.
- Produces:
  - `TrendingWindow::mergesStories(): bool` — `Week` true, `Day` false.
  - `final readonly class StoredStoryModel(int $id, list<int> $entryIds)` — `$entryIds` are the story's members whose entry falls inside the build's window.
  - `final readonly class StoryAssignmentModel(TrendingStoryModel $group, ?int $storyId, list<int> $storedEntryIds)` with `joining(): list<StoryMemberModel>` (group members not in `$storedEntryIds`) and `staying(): list<int>` (group entry ids in `$storedEntryIds`). `$storyId === null` means a new story; then `$storedEntryIds === []`.
  - `final readonly class StoryMergeModel(int $survivorId, int $mergedId)`.
  - `final readonly class StoryMatchModel(list<StoryAssignmentModel> $assignments, list<StoryMergeModel> $merges, int $split)` with `kept(): int` (assignments with a story id), `created(): int` (assignments without), `merged(): int` (count of merges), and `toLogContext(): array{kept: int, created: int, merged: int, split: int}`.
  - `StoryMatching::match(list<TrendingStoryModel> $groups, list<StoredStoryModel> $stored, TrendingWindow $window): StoryMatchModel` and `public const float MATCH_THRESHOLD = 0.5;`.

- [ ] **Step 1: Write the failing tests**

Build groups from entry-id lists with a local helper `group(int ...$entryIds): TrendingStoryModel` (feed id = entry id, null site, fixed dates). Tests and their assertions:

```php
public function testAnUnchangedGroupKeepsItsStory(): void
// stored [new StoredStoryModel(7, [1, 2, 3])], groups [group(1, 2, 3)], Week
// => one assignment, storyId 7, joining() === [], staying() === [1, 2, 3]; kept 1, created 0, merged 0, split 0

public function testAGroupWithANewEntryKeepsItsStoryAndJoinsTheEntry(): void
// stored [7 => [1, 2, 3]], groups [group(1, 2, 3, 4)] => storyId 7, joining entry ids [4]

public function testOverlapIsMeasuredAgainstTheSmallerSide(): void
// stored [7 => [1, 2]], groups [group(1, 2, 3, 4, 5, 6)] => storyId 7 (2 / min(6, 2) = 1.0)

public function testBelowHalfIsANewStory(): void
// stored [7 => [1, 2, 3, 4, 5]], groups [group(1, 2, 8, 9, 10)] => storyId null (2 / 5 = 0.4); created 1, split 0

public function testExactlyHalfMatches(): void
// stored [7 => [1, 2, 3, 4]], groups [group(1, 2, 8, 9)] => storyId 7

public function testAStoryWithNoInWindowMembersMatchesNothing(): void
// stored [7 => []], groups [group(1, 2)] => storyId null, no division by zero

public function testASplitGivesTheLargerPartTheIdAndTheSmallerANewStory(): void
// stored [7 => [1, 2, 3, 4, 5, 6]], groups [group(1, 2), group(3, 4, 5, 6)], Week
// => group(3..6) storyId 7; group(1, 2) storyId null; split 1, merged 0
// (both overlaps are 1.0; the tie between groups goes to the larger shared count — see Step 3)

public function testAWeekGroupOverTwoStoriesMergesTheSmallerIntoTheLarger(): void
// stored [7 => [1, 2, 3], 9 => [4, 5]], groups [group(1, 2, 3, 4, 5)], Week
// => storyId 7, merges [StoryMergeModel(7, 9)], storedEntryIds [1, 2, 3, 4, 5], joining [], merged 1

public function testADayGroupOverTwoStoriesAttachesToTheBestAndMergesNothing(): void
// same input, Day => storyId 7, merges [], storedEntryIds [1, 2, 3], joining entry ids [4, 5]

public function testTiesGoToTheStoryWithMoreInWindowMembersThenTheOlderId(): void
// stored [9 => [1, 2], 7 => [1, 2]], groups [group(1, 2)], Day => storyId 7
// stored [9 => [1, 2, 3], 7 => [1, 2]], groups [group(1, 2)], Day => storyId 9

public function testAssignmentIsOneToOne(): void
// stored [7 => [1, 2, 3, 4]], groups [group(1, 2), group(3, 4)], Day
// => exactly one assignment carries 7, the other null; split 1

public function testTheOutcomeDoesNotDependOnInputOrder(): void
// shuffle groups and stored of the split and merge cases; assert identical StoryMatchModel
```

- [ ] **Step 2: Run them to verify they fail**

Run: `php bin/phpunit tests/Service/Curation/Trending/StoryMatchingTest.php` (from `backend/`)
Expected: FAIL, class `StoryMatching` not found.

- [ ] **Step 3: Implement `StoryMatching::match()`**

Algorithm (the signatures do not determine it):

```
candidates = every (group g, stored s) with shared(g, s) ≥ 1 and shared / min(|g|, |s.entryIds|) ≥ MATCH_THRESHOLD
sort candidates by: overlap desc, shared desc, |s.entryIds| desc, s.id asc, g's lowest entry id asc
assigned = greedy walk: take (g, s) when neither g nor s is taken yet
if window->mergesStories():
    walk candidates in the same order; for (g, s) where s is untaken and g was assigned story X:
        merge s into X once (StoryMergeModel(X, s.id)); add s.entryIds to X's storedEntryIds
groups never assigned get storyId null; split = how many of them had at least one candidate
```

`shared desc` before the size tie-break is what gives a split's larger part the id when both parts overlap 1.0. Output assignments in group order (the order `TrendingGrouping` returned). `MATCH_THRESHOLD` gets one comment line: why half (a group that shares less than half of the smaller side is a different event).

- [ ] **Step 4: Run the tests to verify they pass**

Run: `php bin/phpunit tests/Service/Curation/Trending/StoryMatchingTest.php tests/Enum`
Expected: PASS.

- [ ] **Step 5: Gates and commit**

Run: `composer check && rm -rf ~/.pdepend && composer md` — expected clean.

```bash
git add backend/src/Service/Curation/Trending/StoryMatching.php backend/src/Service/Curation/Trending/Model/Stored*.php backend/src/Service/Curation/Trending/Model/Story{Assignment,Merge,Match}Model.php backend/src/Enum/TrendingWindow.php backend/tests/Service/Curation/Trending/StoryMatchingTest.php backend/tests/Enum
git commit -m "feat(#2128): match new Trending groups to stored stories by member overlap"
```

---

### Task 2: Persistent story schema in the ORM, read path on placement, hides by story

The suite builds its schema from ORM metadata, so this task changes the mapping and every query on it; Task 3 writes the migration. The builder keeps delete-and-insert for now (interim, replaced in Task 4).

**Files:**
- Modify: `backend/src/Entity/TrendingStory.php`, `backend/src/Entity/TrendingStoryEntry.php`
- Create: `backend/src/Entity/TrendingStoryFollow.php`, `backend/src/Entity/TrendingStoryHide.php`
- Delete: `backend/src/Entity/TrendingHiddenEntry.php`, `backend/src/Repository/TrendingHiddenEntryRepository.php`
- Create: `backend/src/Repository/TrendingPlacementSql.php`, `backend/src/Repository/TrendingStoryHideRepository.php`
- Modify: `backend/src/Repository/TrendingStoryRepository.php`, `TrendingSnapshotRepository.php`, `TrendingEntryRepository.php` (`activeFeedCount`), `AccountWipeRepository.php`
- Modify: `backend/src/Service/Curation/Trending/TrendingStories.php`, `ReaderWindows.php`, `TrendingStoryHiding.php`, `SnapshotBuilder/TrendingSnapshotBuilder.php`, `Model/ReaderWindowModel.php` (doc only), `Model/TrendingStoryCardModel.php`, `Pass/StoryCardSource.php`
- Modify: `backend/src/Dto/Trending/HideTrendingStoryRequest.php`, `backend/src/Controller/Api/TrendingController.php`, `backend/src/Http/TrendingJson.php`, `backend/src/Http/Problem/ExceptionProblems/TrendingProblems.php`
- Modify tests: `backend/tests/Support/TrendingFixtures.php`, `tests/Repository/TrendingStoryRepositoryTest.php`, `tests/Repository/TrendingSnapshotRepositoryTest.php`, `tests/Service/Curation/Trending/TrendingStoriesTest.php`, `TrendingStoryHidingTest.php`, `TrendingInclusionTest.php`, `SnapshotBuilder/TrendingSnapshotBuilderTest.php`, `tests/Controller/Api/TrendingHiddenControllerTest.php`, `TrendingControllerTest.php`, `tests/Service/Identity/Account/AccountResetTest.php`, `tests/Service/Operations/Backup/Support/BackupSchemaCoverageTest.php`

**Interfaces:**
- Produces (schema, fixed for Task 3):
  - `trending_story`: `id`, `first_seen`, `last_seen`, `first_joined_at`, `last_joined_at` (all datetime NOT NULL); `time_window` and `idx_trending_story_window` are gone.
  - `trending_story_entry`: PK `(story_id, entry_id)`, `feed_id`, `article_site`, `shared_link_id` as today, plus `joined_at` datetime NOT NULL, `in_day` bool NOT NULL, `in_week` bool NOT NULL (ORM `options: ['default' => false]` on both flags so the SQLite rebuild and `schema:validate` agree).
  - `trending_story_follow`: PK `(user_id, story_id)`, both FKs `ON DELETE CASCADE`, `followed_at`, `last_opened_at`.
  - `trending_story_hide`: PK `(user_id, story_id)`, both FKs `ON DELETE CASCADE`, `hidden_at`.
- Produces (code):
  - `TrendingStory::__construct(\DateTimeImmutable $firstSeen, \DateTimeImmutable $lastSeen, \DateTimeImmutable $joinedAt)` — sets both joined columns to `$joinedAt`.
  - `TrendingStoryEntry::__construct(TrendingStory $story, Entry $entry, ?string $articleSite, ?SharedLink $link, \DateTimeImmutable $joinedAt)` plus `placeIn(TrendingWindow $window): void` (entities are only built with `new` in fixtures; the rebuild writes through DBAL).
  - `TrendingStoryFollow::__construct(User $user, TrendingStory $story, \DateTimeImmutable $followedAt)` (`last_opened_at` starts at `$followedAt`); `TrendingStoryHide::__construct(User $user, TrendingStory $story, \DateTimeImmutable $hiddenAt)`.
  - `final class TrendingPlacementSql { public static function placedIn(TrendingWindow $window, string $member): string }` → `"{$member}.in_day = 1"` / `"{$member}.in_week = 1"`; the one place that maps a window to its flag.
  - `TrendingStoryRepository::shownEntriesByStory(int $userId, TrendingWindow $window, list<int> $storyIds): ShownEntriesModel`, `viewedStoryIds(int $userId, TrendingWindow $window, list<int> $storyIds): list<int>`, `requireExists(int $storyId): void` (throws `TrendingStoryNotFoundException`). `storyIdContaining()` is deleted.
  - `TrendingStoryHideRepository::hide(int $userId, int $storyId, \DateTimeImmutable $hiddenAt): void` (insert-ignore through `DatabasePlatform::insertIgnoreClause`).
  - `TrendingStoryHiding::hide(int $userId, int $storyId): void` throws `TrendingStoryNotFoundException`.
  - `HideTrendingStoryRequest(#[Assert\Positive] int $storyId)`; `window` is dropped.
  - `TrendingStoryCardModel` gains `public int $storyId` via `RankedStoryModel::$storyId` (already there) — `TrendingJson::story()` emits `story.id`. `followed` arrives in Task 6.
  - `TrendingFixtures::story(TrendingWindow $window, string $lastSeen, Entry ...$members)` keeps its signature; members get `joinedAt` = `$lastSeen` and `placeIn($window)`.

- [ ] **Step 1: Write the failing tests**

In `TrendingStoryRepositoryTest`:

```php
public function testAStoryPlacedInBothWindowsRanksInEachWithOnlyThatWindowsMembers(): void
// one TrendingStory, three article members placed in week, two of them also in day (fixture: placeIn both)
// rankedForUser(day reader) outletCount === 2; rankedForUser(week reader) outletCount === 3; same storyId in both

public function testAMemberPlacedInNeitherWindowCountsInNeither(): void

public function testTheWeekLeavesOutAStoryIdShownInTheDay(): void
// ReaderWindowModel(week, shorterWindowStoryIds: [$story->requireId()]) => rankedForUser() === []

public function testAnEmptyDayLeavesNothingOutOfTheWeek(): void   // Review Focus 1
// ReaderWindowModel(week, shorterWindowStoryIds: []) => the story ranks

public function testAHiddenStoryIsLeftOutForThatReaderOnly(): void
// TrendingStoryHide for reader A => A sees no story; reader B sees it

public function testShownEntriesAndViewedReadOnlyTheWindowsMembers(): void
```

In `TrendingEntryRepository` coverage (existing test file for it, or `TrendingStoriesTest`): `testAnEntryOfAHiddenStoryDoesNotMakeItsFeedActive()` — `activeFeedCount` ignores entries that are members of a story the reader hid.

In `TrendingHiddenControllerTest` (rewrite around story ids; delete the window and entry cases that no longer apply):

```php
public function testHidingAStoryTakesItOffTheListAndTheBadge(): void   // POST {storyId} => 204
public function testHidingAgainAnswersTheSameAndLeavesOtherReadersAlone(): void
public function testAnUnknownStoryIsNotFound(): void   // 404, problem type 'trending_story_not_found'
public function testAMalformedBodyIsRefusedAndHidesNothing(array $body, string $field): void   // {}, {storyId: 0}, {storyId: "x"} => 422
```

In `TrendingControllerTest`: `testEachStoryCarriesItsId()` — `stories[0].story.id` equals the stored story's id.

In `BackupSchemaCoverageTest`: replace the `TrendingHiddenEntry` line with reasons for `TrendingStoryFollow` and `TrendingStoryHide` ("story ids do not survive a restore, which regroups Trending from the restored entries"). In `AccountResetTest`: a reset deletes the user's follows and hides.

- [ ] **Step 2: Run them to verify they fail**

Run: `php bin/phpunit --filter 'TrendingStoryRepositoryTest|TrendingHiddenControllerTest|TrendingControllerTest|BackupSchemaCoverageTest|AccountResetTest'`
Expected: FAIL (unknown classes / columns).

- [ ] **Step 3: Change the mapping and the read path**

- Entities as in Interfaces; `TrendingStory` loses `time_window` and its index. Keep the one-line class docblocks true (stories now persist).
- `TrendingStoryRepository`: every `trending_story_entry` alias the window read touches (`story_member`, `earlier_member`, `shown`, `opened`) filters with `TrendingPlacementSql::placedIn($window, '<alias>')`; `visibleStories()` takes the window and starts `WHERE <story_member placed>`; the D41 clause becomes `AND story.id NOT IN (:shorterWindowStoryIds)` appended **only when the list is non-empty** (and the parameter is bound only then); the hidden clause becomes `NOT EXISTS (SELECT 1 FROM trending_story_hide hidden WHERE hidden.story_id = story.id AND hidden.user_id = :user)`; `window` leaves `visibilityParameters()`.
- `TrendingEntryRepository::activeFeedCount`: the hidden test becomes `NOT EXISTS (SELECT 1 FROM trending_story_entry hidden_member JOIN trending_story_hide hidden ON hidden.story_id = hidden_member.story_id AND hidden.user_id = :user WHERE hidden_member.entry_id = recent.id)`.
- `TrendingStories`/`StoryCardSource` pass the window through to the two repository reads.
- `TrendingStoryHiding::hide()`: `requireExists()`, then `TrendingStoryHideRepository::hide()` with the clock's now. `TrendingProblems` detail becomes `'No Trending story has that id.'`.
- `TrendingController::hide()` passes `$request->storyId`.
- Interim writer: `TrendingSnapshotRepository::replaceStories(TrendingWindow $window, list<TrendingStoryModel> $stories, \DateTimeImmutable $builtAt)` deletes the stories with a member placed in `$window`, then inserts each story with `first_joined_at = last_joined_at = $builtAt` and its members with `joined_at = $builtAt` and the window's flag set. The builder takes `$builtAt = $this->clock->now()` once and passes it to both `replaceStories()` and the report.
- `AccountWipeRepository::deleteOwnedRows()`: delete `TrendingStoryFollow` and `TrendingStoryHide` by user in place of `TrendingHiddenEntry`.

- [ ] **Step 4: Run the whole SQLite suite**

Run: `php bin/phpunit`
Expected: PASS. Fix every test the mapping change broke by adapting its fixture, not its assertion, unless the assertion tested `time_window` or `trending_hidden_entry` themselves.

- [ ] **Step 5: MySQL leg, gates, commit**

Run: `docker compose exec php composer test:parallel` and `composer check && rm -rf ~/.pdepend && composer md && composer infection:diff` — expected green.

```bash
git add -A backend/src backend/tests
git commit -m "feat(#2128): store Trending stories once for both windows and hide them by id"
```

---

### Task 3: The migration (SQLite and MySQL 8.0)

**Files:**
- Create: `backend/migrations/Version20261116180000.php`
- Test: `backend/tests/Migrations/Version20261116180000Test.php`

**Interfaces:**
- Consumes: the schema of Task 2 exactly (columns, keys, FK names Doctrine generates — copy them from `bin/console doctrine:schema:update --dump-sql` on each platform).

- [ ] **Step 1: Write the failing migration test (SQLite, in-memory, as `Version20261115220000Test`)**

Set up the pre-migration tables by hand: `user`, `entry`, `feed`, `shared_link`, `trending_snapshot` (rows: day built `2026-11-16 09:50:00`, week built `2026-11-16 09:00:00`), `trending_story` with `time_window` (story 11 day, story 12 week), `trending_story_entry` (11: entries 1, 2; 12: entries 1, 2, 3), `trending_hidden_entry` (user 5 hid entry 3 at `2026-11-15 08:00:00`; user 6 hid entry 1 twice-over via two stories). Tests:

```php
public function testStoriesKeepTheirIdsAndTakeTheirSnapshotsBuildTimeAsJoinTime(): void
// trending_story ids [11, 12]; first_joined_at = last_joined_at = '2026-11-16 09:50:00' for 11, '… 09:00:00' for 12

public function testMembersArePlacedInTheirStorysWindow(): void
// (11, 1) in_day 1 in_week 0; (12, 3) in_day 0 in_week 1; joined_at = the snapshot's built_at

public function testAHiddenEntryHidesEveryStoryHoldingIt(): void
// trending_story_hide rows: (5, 12, '2026-11-15 08:00:00'); (6, 11), (6, 12) — earliest hidden_at per (user, story)

public function testTheHiddenEntryTableAndTheWindowColumnAreGone(): void

public function testAWindowWithNoSnapshotRowFallsBackToTheStorysLastSeen(): void

public function testDownRestoresTheWindowColumnAndTheHiddenEntries(): void
// story 11 time_window 'day'; trending_hidden_entry holds (5, 3)
```

- [ ] **Step 2: Run it to verify it fails**

Run: `php bin/phpunit tests/Migrations/Version20261116180000Test.php`
Expected: FAIL, migration class missing.

- [ ] **Step 3: Write `Version20261116180000`**

Platform switch as in `Version20261115140200`; `isTransactional(): false`; `skipIf` on `trending_story_follow` existing (up) / missing (down).

- **MySQL:** create `trending_story_follow` and `trending_story_hide`; `ALTER trending_story ADD first_joined_at DATETIME NULL, ADD last_joined_at DATETIME NULL`; `ALTER trending_story_entry ADD joined_at DATETIME NULL, ADD in_day TINYINT(1) DEFAULT 0 NOT NULL, ADD in_week TINYINT(1) DEFAULT 0 NOT NULL`; set join times and flags with `UPDATE … JOIN trending_story … LEFT JOIN trending_snapshot ON trending_snapshot.time_window = trending_story.time_window` (fallback `trending_story.last_seen`); insert hides with `INSERT INTO trending_story_hide SELECT hidden.user_id, member.story_id, MIN(hidden.hidden_at) … GROUP BY hidden.user_id, member.story_id`; `MODIFY` the three join columns `NOT NULL`; `DROP TABLE trending_hidden_entry`; `ALTER trending_story DROP INDEX idx_trending_story_window, DROP time_window`. No FK column changes type or nullability here; if MySQL 8.0 still refuses a statement with error 1832, drop and re-add the key around it as `Version20261115140200` does (#2254).
- **SQLite:** create the two new tables; rebuild `trending_story` and `trending_story_entry` as `*_new` tables (create, `INSERT … SELECT` computing the new columns from `time_window` and `trending_snapshot`, drop, rename, recreate the indexes and FKs Doctrine generates); then the hide insert and `DROP TABLE trending_hidden_entry`. The rebuilt tables carry no FTS triggers (those live on `entry`/`entry_transcript`), so `EntrySearchIndex::install()` is not needed.
- **down:** recreate `trending_hidden_entry`, refill it from `trending_story_hide` × members, restore `time_window` (`'day'` when any member is `in_day`, else `'week'`) and its index, drop the new columns and tables. Follows are lost on down.

- [ ] **Step 4: Run the test, then migrate from empty on both platforms and validate**

Run: `php bin/phpunit tests/Migrations/Version20261116180000Test.php` — expected PASS.

Then the CI leg locally, against throwaway databases (never the dev database):

```bash
DATABASE_URL="sqlite:///%kernel.project_dir%/var/migrate-check.db" bin/console doctrine:migrations:migrate --no-interaction --env=test && DATABASE_URL="sqlite:///%kernel.project_dir%/var/migrate-check.db" bin/console doctrine:schema:validate --env=test; rm -f var/migrate-check.db
docker compose exec mysql mysql -uroot -proot -e 'CREATE DATABASE migrate_check'
docker compose exec php sh -c 'DATABASE_URL="mysql://root:root@mysql:3306/migrate_check?serverVersion=8.0" bin/console doctrine:migrations:migrate --no-interaction && DATABASE_URL="mysql://root:root@mysql:3306/migrate_check?serverVersion=8.0" bin/console doctrine:schema:validate'
docker compose exec mysql mysql -uroot -proot -e 'DROP DATABASE migrate_check'
```

Expected: both report "The database schema is in sync with the mapping files." Then apply it to the live dev database: `docker compose exec php bin/console doctrine:migrations:migrate --no-interaction`, and check that `SELECT COUNT(*) FROM trending_story_hide` is at least the number of distinct (user, story) pairs the old hidden entries implied.

- [ ] **Step 5: Commit**

```bash
git add backend/migrations/Version20261116180000.php backend/tests/Migrations/Version20261116180000Test.php
git commit -m "feat(#2128): migrate Trending stories to persistent stories with history, follows and hides"
```

---

### Task 4: The builder updates stories in place — attach, merge, split, prune

**Files:**
- Create: `backend/src/Repository/TrendingStoryHistoryRepository.php`
- Create: `backend/src/Service/Curation/Trending/Model/StoryBuildModel.php`
- Modify: `backend/src/Service/Curation/Trending/Pass/TrendingRun.php` (add `public readonly \DateTimeImmutable $since`), `TrendingGrouping.php` (`collect()` passes the `since` it computes), `SnapshotBuilder/TrendingSnapshotBuilder.php`, `Model/TrendingBuildReportModel.php`, `backend/src/Repository/TrendingSnapshotRepository.php` (drop `replaceStories()`)
- Test: `backend/tests/Service/Curation/Trending/SnapshotBuilder/TrendingSnapshotBuilderTest.php`, `backend/tests/Repository/TrendingStoryHistoryRepositoryTest.php`

**Interfaces:**
- Consumes: Task 1 (`StoryMatching::match`, the four models), Task 2 schema and `TrendingPlacementSql`.
- Produces:
  - `final readonly class StoryBuildModel(TrendingWindow $window, \DateTimeImmutable $since, \DateTimeImmutable $builtAt)`.
  - `TrendingStoryHistoryRepository` (the one writer of stories, members, and the follow/hide moves of a merge):
    - `storedInWindow(\DateTimeImmutable $since): list<StoredStoryModel>` — every story with a member whose `entry.effective_date >= $since`, those entry ids ascending, stories by id.
    - `merge(StoryMergeModel $merge): void`
    - `clearPlacement(TrendingWindow $window): void`
    - `save(StoryAssignmentModel $assignment, StoryBuildModel $build): void`
    - `pruneUnfollowedOutside(\DateTimeImmutable $weekStart): int`
  - `TrendingBuildReportModel` gains `public StoryMatchModel $match` and `public int $pruned`; `toLogContext()` adds `kept`, `created`, `merged`, `split`, `pruned`.

- [ ] **Step 1: Write the failing tests**

In `TrendingSnapshotBuilderTest` (fixtures and clock as today; the clock moved between builds with `MockClock::modify()`):

```php
public function testARebuildOverUnchangedInputChangesNothing(): void
// build week twice at the same clock; snapshot of (story id, entry id, joined_at, in_day, in_week) and
// trending_story rows are identical; second report: kept === story count, created 0, merged 0, split 0, pruned 0

public function testTheDayAndTheWeekPlaceOneStory(): void
// build day then week over one event => one trending_story row; its members in_day and in_week as each window grouped them

public function testANewArticleJoinsTheStoryWithTheBuildTime(): void
// build week, add a fourth hafenstreik entry, advance the clock 1 h, build week
// => same story id; the new member's joined_at = the second build time, the others keep the first; last_joined_at moved

public function testAMemberThatLeavesTheWindowStaysInTheHistory(): void

public function testTwoStoriesOneWeekGroupBridgesAreMergedIntoTheLarger(): void
// seed two stored stories (fixtures) whose members one week group covers; after the week build: one story,
// the larger id survives, its history holds both member sets; report merged 1

public function testTheDayBuildNeverMerges(): void

public function testASplitKeepsTheIdAndTheFollowOnTheLargerPart(): void

public function testSeedStoriesOfBothWindowsConvergeOnTheFirstWeekBuild(): void
// a day story and a week story over one event (as the migration leaves them) => after the week build, one story

public function testAnEntryOnTheWindowStartIsNeverInsertedTwice(): void   // Review Focus 2
// an entry dated exactly at $since is already a member; the build succeeds and the member keeps its joined_at

public function testAnUnfollowedStoryOutsideTheWeekIsPrunedAndAFollowedOneStays(): void
// two stories whose members are all older than 7 days, one followed => after a week build only the followed remains;
// a day build prunes nothing

public function testAFailedSaveKeepsTheOldStories(): void
// SimulatedFailure on save (as the existing rollback test does) => stories, members and placement unchanged
```

In `TrendingStoryHistoryRepositoryTest` (both legs run it):

```php
public function testAMergeMovesTheHistoryAndKeepsTheEarlierJoinOfASharedEntry(): void   // Review Focus 3
// X: entries 1 (joined 10:00), 2; Y: entries 2 (joined 09:00, in_day), 3 => X holds 1, 2, 3; (X, 2) joined 09:00, in_day 1; Y is gone

public function testAMergeKeepsOneFollowWithTheEarlierTimesAndOneHide(): void   // Review Focus 4
// reader follows X (opened 12:00) and Y (opened 08:00), hides Y => one follow on X, last_opened_at 08:00,
// followed_at the earlier of the two; a hide on X; another reader's follow of Y moved to X

public function testStoredInWindowReadsOnlyMembersInsideTheWindow(): void
```

- [ ] **Step 2: Run them to verify they fail**

Run: `php bin/phpunit tests/Service/Curation/Trending/SnapshotBuilder tests/Repository/TrendingStoryHistoryRepositoryTest.php`
Expected: FAIL.

- [ ] **Step 3: Implement**

`TrendingSnapshotBuilder::build()` reads as: collect → groups (`stories($run)`) → `$build = new StoryBuildModel($window, $run->since, $this->clock->now())` → `$stored = storedInWindow($build->since)` → `$match = StoryMatching::match(...)` → in one transaction: each merge, `clearPlacement`, `save` each assignment, `pruneUnfollowedOutside(TrendingWindow::Week->startBefore($build->builtAt))` only when `$window->mergesStories()`, `recordSnapshot`. Keep `unset($run)` before the transaction.

Repository rules the tests do not spell out:
- `save()`: a null story id inserts the story (`first_seen`/`last_seen` from the group, both joined columns = `builtAt`); otherwise one `UPDATE trending_story` with portable `CASE` expressions keeps `first_seen` the earlier, `last_seen` the later, and sets `last_joined_at = builtAt` only when `joining()` is non-empty. `staying()` rows get the window's flag through `UPDATE … WHERE story_id = ? AND entry_id IN (?)` in chunks of `INSERT_ROWS`; `joining()` rows go in through `BulkInsert` with `joined_at = builtAt` and only this window's flag set. Stored `article_site`/`shared_link_id` of staying rows are not rewritten.
- `merge()`: read Y's member rows in PHP; insert those X lacks (keeping Y's `joined_at` and flags); for shared entries update X's row where Y's `joined_at` is earlier or Y's row is `in_day`/`in_week`. Follows: where the user follows both, set X's `followed_at`/`last_opened_at` to the earlier of each and drop Y's; else move the row to X. Hides: insert-ignore Y's onto X. Delete Y (members, follows, hides cascade). No `UPDATE t … (SELECT … FROM t)`.
- `clearPlacement()`: `UPDATE trending_story_entry SET in_<w> = 0 WHERE in_<w> = 1`.
- `pruneUnfollowedOutside()`: delete stories with no follow and no member whose entry is dated at or after `$weekStart`; returns the count.

- [ ] **Step 4: Run the tests and both legs**

Run: `php bin/phpunit` and `docker compose exec php composer test:parallel`
Expected: PASS on both.

- [ ] **Step 5: Gates and commit**

Run: `composer check && rm -rf ~/.pdepend && composer md && composer infection:diff` — expected green.

```bash
git add -A backend/src backend/tests
git commit -m "feat(#2128): keep Trending story ids across rebuilds, merging and splitting by overlap"
```

---

### Task 5: Measure on the dev database and tune

**Files:**
- Create: `backend/src/Command/TrendingRebuildCommand.php` (`app:trending:rebuild <window>`, builds that window now and prints its report)
- Test: `backend/tests/Command/TrendingRebuildCommandTest.php`
- Modify (only if tuning moves it): `StoryMatching::MATCH_THRESHOLD`

**Interfaces:**
- Consumes: `SnapshotBuilderInterface::build(TrendingWindow)`, `TrendingBuildReportModel::toLogContext()`.

- [ ] **Step 1: Write the failing command test**

`testItBuildsTheNamedWindowAndPrintsItsCounts()` — output contains `kept`, `created`, `merged`, `split`, `pruned` with numbers; `testAnUnknownWindowFails()` — exit code `Command::INVALID`.

- [ ] **Step 2: Run it to verify it fails, implement the command, run it to pass**

Run: `php bin/phpunit tests/Command/TrendingRebuildCommandTest.php` — FAIL, then PASS. The command parses the argument with `TrendingWindow::fromRequestValue()` and prints `toLogContext()` as a table.

- [ ] **Step 3: Real run on the dev Docker MySQL**

Migrated in Task 3. Never clear the database. Run each step and keep the output:

```bash
docker compose stop worker      # no ingestion or due rebuilds between the builds below
docker compose exec php bin/console app:trending:rebuild week   # first build after the migration: seeds converge
docker compose exec php bin/console app:trending:rebuild day
docker compose exec php bin/console app:trending:rebuild week   # unchanged input
docker compose exec php bin/console app:trending:rebuild day    # unchanged input
docker compose start worker
```

Around the two unchanged-input builds, snapshot `SELECT story_id, entry_id, joined_at, in_day, in_week FROM trending_story_entry ORDER BY 1, 2` and `SELECT * FROM trending_story ORDER BY id` into the scratchpad and `diff` them. Expected: no difference except rows of entries that crossed the window start between the two builds (count them; expect 0 when the builds run seconds apart), and the reports show created 0, merged 0, split 0, kept = story count, i.e. 100 % of ids survived. Then leave the worker running for at least three hours and read the per-build counts from the dev log (`ls -t backend/var/log/dev-*.log | head -1 | xargs grep -h '"kept"' | jq -c '.context'`).

- [ ] **Step 4: Tune or confirm the threshold**

Look at every merge and split in that period (`app:trending:explain` on the entries involved). If a merge joined two different events or a split broke one event, move `MATCH_THRESHOLD` and rerun Step 3; record the rule that moved it in the constant's one-line reason. Write the table (per window: builds, kept, created, merged, split, pruned, unchanged-input id survival) into the PR description.

- [ ] **Step 5: Commit**

```bash
git add backend/src/Command/TrendingRebuildCommand.php backend/tests/Command/TrendingRebuildCommandTest.php backend/src/Service/Curation/Trending/StoryMatching.php
git commit -m "feat(#2128): rebuild a Trending window on demand and report its story matching"
```

---

### Task 6: Following a story — endpoints and the followed list

**Files:**
- Create: `backend/src/Repository/TrendingStoryFollowRepository.php`
- Create: `backend/src/Service/Curation/Trending/TrendingStoryFollowing.php`
- Create: `backend/src/Service/Curation/Trending/Model/FollowedStoryRowModel.php`, `FollowedStoryModel.php`
- Create: `backend/src/Service/Curation/Trending/Exception/TrendingStoryNotFollowedException.php`
- Create: `backend/src/Controller/Api/TrendingStoryFollowController.php`, `backend/src/Http/FollowedStoryJson.php`
- Modify: `backend/src/Http/Problem/ExceptionProblems/TrendingProblems.php`, `TrendingJson.php`, `TrendingStories.php`, `Pass/StoryCardSource.php`, `Model/TrendingStoryCardModel.php`
- Test: `backend/tests/Controller/Api/TrendingStoryFollowControllerTest.php`, `backend/tests/Repository/TrendingStoryFollowRepositoryTest.php`, `tests/Controller/Api/TrendingControllerTest.php`

**Interfaces:**
- Consumes: Task 2 schema, `TrendingStoryRepository::requireExists()`, `NaiveUtcClock`.
- Produces:
  - `TrendingStoryFollowRepository::follow(int $userId, int $storyId, \DateTimeImmutable $at): void` (insert-ignore; a second follow keeps the first `followed_at`/`last_opened_at`), `unfollow(int $userId, int $storyId): void`, `markOpened(int $userId, int $storyId, \DateTimeImmutable $at): int` (rows changed), `followedStoryIds(int $userId, list<int> $storyIds): list<int>`, `followedBy(int $userId): list<FollowedStoryRowModel>`, `lastOpenedAt(int $userId, int $storyId): \DateTimeImmutable` (throws `TrendingStoryNotFollowedException`).
  - `FollowedStoryRowModel(int $storyId, ?string $headline, int $newCount, \DateTimeImmutable $lastJoinedAt)`; `FollowedStoryModel(FollowedStoryRowModel $row, bool $quiet)`.
  - `TrendingStoryFollowing`: `public const int QUIET_AFTER_HOURS = 48;`, `follow(int $userId, int $storyId): void` (throws `TrendingStoryNotFoundException`), `unfollow(int $userId, int $storyId): void`, `markOpened(int $userId, int $storyId): void` (throws `TrendingStoryNotFollowedException`), `followedBy(int $userId): list<FollowedStoryModel>`.
  - Routes (all bearer, JSON, problem+json): `PUT /api/trending/stories/{id}/follow` → 204, 404 `trending_story_not_found`; `DELETE /api/trending/stories/{id}/follow` → 204 (idempotent); `POST /api/trending/stories/{id}/opened` → 204, 404 `trending_story_not_followed`; `GET /api/trending/followed` → `{stories: [{id, headline, newCount, quiet, lastJoinedAt}]}`, `lastJoinedAt` ATOM, ordered `lastJoinedAt` desc then id desc.
  - `TrendingStoryCardModel` gains `bool $followed`; `TrendingJson` emits `story.followed`.

- [ ] **Step 1: Write the failing tests**

`TrendingStoryFollowControllerTest`:

```php
public function testFollowingAStoryListsItWithNothingNew(): void   // PUT 204; GET followed: one row, newCount 0, quiet false
public function testFollowingTwiceKeepsTheFirstVisit(): void
public function testFollowingAnUnknownStoryIsNotFound(): void   // 404 trending_story_not_found
public function testUnfollowingRemovesTheRowAndIsIdempotent(): void   // DELETE twice => 204, 204
public function testMembersJoinedAfterTheVisitCountAsNew(): void
// story joined_at 09:00 ×2, follow at 10:00, two members joined at 11:00 => newCount 2
public function testAnOldArticleGroupedInNowCountsAsNew(): void
// member published three days ago, joined_at after last_opened_at => counts
public function testNewCountsAnArticleOnceAndAnAccountOnce(): void
// two copies of one url_hash in two followed feeds + two posts of one author_url => newCount 2
public function testOnlyFeedsTheReaderFollowsAndIncludesInTrendingCount(): void
public function testOpeningClearsTheNewCount(): void   // POST opened 204 => newCount 0
public function testOpeningAStoryNotFollowedIsNotFound(): void   // 404 trending_story_not_followed
public function testAStoryWithNothingJoinedForTwoDaysIsQuiet(): void   // last_joined_at = now − 48 h => quiet true; − 47 h => false
public function testTheHeadlinePrefersTheNewestArticleOverANewerPost(): void
public function testAStoryTheReaderCanNoLongerSeeStaysWithNoHeadline(): void   // Review Focus 5: headline null, newCount 0
public function testOtherReadersFollowsAreTheirOwn(): void
public function testFollowedStoriesAreNewestJoinedFirst(): void
```

`TrendingControllerTest::testAFollowedStoryCardSaysSo()` — `story.followed` true for the follower, false for another reader.

- [ ] **Step 2: Run to verify failure**

Run: `php bin/phpunit tests/Controller/Api/TrendingStoryFollowControllerTest.php tests/Controller/Api/TrendingControllerTest.php`
Expected: FAIL.

- [ ] **Step 3: Implement**

- `followedBy()` is at most two queries (no query per story): the follows with `last_joined_at`, then per-story headline and new count over `story_id IN (...)`. Visibility joins `subscription` with `TrendingInclusionSql::includes()`. Headline: title of the newest (`effective_date`, then id) member from a feed with `network IS NULL`, else of the newest visible member, else null. New count: members with `joined_at > last_opened_at`, `COUNT(DISTINCT url_hash)` over article feeds + `COUNT(DISTINCT author_url)` over social posts + `COUNT(DISTINCT feed_id)` over social posts with no `author_url`.
- Quiet: `lastJoinedAt <= now − QUIET_AFTER_HOURS`.
- Controller actions only read the request, call the service, return through `FollowedStoryJson` or `204`; `TrendingProblems` gains the `trending_story_not_followed` arm (404, title "Trending story not followed").
- `TrendingStories::sourceFor()` asks `followedStoryIds()` once for the ranked ids and hands the set to `StoryCardSource`.

- [ ] **Step 4: Run both legs**

Run: `php bin/phpunit` and `docker compose exec php composer test:parallel` — PASS.

- [ ] **Step 5: Gates and commit**

Run: `composer check && rm -rf ~/.pdepend && composer md && composer infection:diff` — green.

```bash
git add -A backend/src backend/tests
git commit -m "feat(#2128): follow a Trending story and list followed stories with their new count"
```

---

### Task 7: A followed story's entry list

**Files:**
- Create: `backend/src/Repository/TrendingStoryEntryListRepository.php`, `backend/src/Repository/TrendingStoryListQuery.php`
- Create: `backend/src/Service/Curation/Trending/FollowedStoryEntries.php`, `Model/FollowedStoryEntriesModel.php`
- Create: `backend/src/Controller/Api/FollowedStoryEntriesController.php`, `backend/src/Http/FollowedStoryPage.php`
- Test: `backend/tests/Controller/Api/FollowedStoryEntriesControllerTest.php`

**Interfaces:**
- Consumes: `SavedSearchEntryRepository::listMembers()` as the pattern (`EntryProjection`, `DuplicateCollapseDql`, `EntryListRowHydrator`, `EntryListRowEnricher`), `EntryPageParameters`, `EntryPage::of()`, `TrendingStoryFollowRepository::lastOpenedAt()`.
- Produces:
  - `TrendingStoryListQuery(int $userId, int $storyId, ?EntryCursor $cursor = null, int $limit = EntryQuery::DEFAULT_LIMIT, ListOrder $order = ListOrder::NewestFirst)` with clamped `$limit` and `ordering(): EntryListOrdering` (published date), mirroring `SavedSearchListQuery`.
  - `TrendingStoryEntryListRepository::listMembers(TrendingStoryListQuery $query): list<EntryListRow>` — every member of the story (any placement, the whole history), restricted on the primary alias and inside the collapse subquery to feeds the reader follows and includes in Trending (`InclusionScopeDql::restrict(..., InclusionSurface::Trending)`); `joinedAfter(int $storyId, list<int> $entryIds, \DateTimeImmutable $since): list<int>`.
  - `FollowedStoryEntries::list(TrendingStoryListQuery $query): FollowedStoryEntriesModel(list<EntryListRow> $rows, \DateTimeImmutable $lastOpenedAt, list<int> $newEntryIds)`; throws `TrendingStoryNotFollowedException`. A row is new when it or one of its collapsed duplicates joined after `lastOpenedAt`; `newEntryIds` holds the row ids.
  - `GET /api/entries/trending-stories/{id}` (`requirements: ['id' => '\d+']`, `EntryPageParameters` from the query string) → `{entries, nextCursor, lastOpenedAt, newEntryIds}`; 404 `trending_story_not_followed`.

- [ ] **Step 1: Write the failing tests**

```php
public function testItListsTheStorysWholeHistoryNewestFirst(): void   // a member out of both windows still listed
public function testItPagesWithTheUsualCursor(): void   // limit 2 over 3 members: page 1 nextCursor set, page 2 the third
public function testOnlyFeedsTheReaderFollowsAndIncludesInTrendingAreListed(): void
public function testMembersJoinedAfterTheVisitAreMarkedNew(): void   // newEntryIds, lastOpenedAt ATOM
public function testListingDoesNotRecordAVisit(): void   // GET twice => same lastOpenedAt and newEntryIds
public function testAStoryNotFollowedIsNotFound(): void
public function testRowsCarryTheUsualEntryStateAndEnrichment(): void   // isFavorite after starring
```

- [ ] **Step 2–4: Fail, implement, pass**

Run: `php bin/phpunit tests/Controller/Api/FollowedStoryEntriesControllerTest.php` (FAIL, then PASS after implementing), then both legs.

- [ ] **Step 5: Gates and commit**

Run: `composer check && rm -rf ~/.pdepend && composer md && composer infection:diff`.

```bash
git add -A backend/src backend/tests
git commit -m "feat(#2128): list a followed Trending story's entries with what joined since the last visit"
```

---

### Task 8: Frontend — story ids on cards, hide by id, follow from the card

**Files:**
- Modify: `frontend/src/app/reader/trending/trending.models.ts`, `trending-api.ts`, `trending-hide-action.ts`, `trending-list.directive.ts`
- Create: `frontend/src/app/reader/trending/trending-follow-action.ts`
- Modify: `frontend/src/app/reader/entry/entry-actions/entry-row-action.ts`, `entry-actions.component.ts`/`.html`, `frontend/src/app/reader/list/magazine/entry-duplicates.component.ts`
- Modify: `frontend/src/app/reader/state/entries.store.ts`
- Modify: `frontend/public/i18n/{de,en,fr,es}.json`, `frontend/src/app/core/i18n/i18n-dictionaries.spec.ts`
- Test: `trending-api.spec.ts`, `trending-hide-action.spec.ts`, `trending-follow-action.spec.ts`, `entry-actions.component.spec.ts`, `entries.store` spec

**Interfaces:**
- Consumes: `GET /api/trending` `story.id`, `story.followed`; `POST /api/trending/hidden {storyId}`; `PUT`/`DELETE /api/trending/stories/{id}/follow`.
- Produces:
  - `TrendingStory` gains `id: number; followed: boolean`.
  - `TrendingApi.hideStory(storyId: number): Observable<void>`, `follow(storyId: number)`, `unfollow(storyId: number)`, `followed(): Observable<FollowedStoriesResponseDto>`, `opened(storyId: number): Observable<void>`; `FollowedStoryDto { id: number; headline: string | null; newCount: number; quiet: boolean; lastJoinedAt: string }`.
  - `EntryRowAction` becomes `{ presentationOf(entry: EntryDto): EntryRowActionPresentation; run(entry: EntryDto): void }` with `EntryRowActionPresentation { icon: string; label: string; hint: string; pressed?: boolean }`; the token becomes `ENTRY_ROW_ACTIONS: InjectionToken<Signal<readonly EntryRowAction[]>>`, rendered in order, `aria-pressed` when `pressed` is set. `entry-duplicates` provides `signal([])`.
  - `EntriesStore.hideTrendingStory(entryId, outcome)` sends `entry.story.id`; `EntriesStore.setStoryFollowed(entryId: number, followed: boolean, outcome: StateSaveOutcome)` patches `story.followed` optimistically, calls follow/unfollow, rolls back and reports on failure.
  - `TrendingFollowAction`: `bookmark_add`/`bookmark_added` icon, label `reader.followStory`/`reader.unfollowStory`, hint `reader.actionHint.followStory`/`reader.actionHint.unfollowStory`, `pressed = followed`; Task 9 adds the sidebar reload after it succeeds.
  - The Trending list provides `[follow, hide]` (Follow sits next to Hide, before it).

- [ ] **Step 1: Write the failing specs**

- `trending-api.spec.ts`: `hideStory(12)` posts `{ storyId: 12 }` to `/api/trending/hidden`; `follow(12)` is `PUT /api/trending/stories/12/follow`; `unfollow(12)` `DELETE` the same; `opened(12)` `POST /api/trending/stories/12/opened`; `followed()` `GET /api/trending/followed`.
- `trending-follow-action.spec.ts`: an unfollowed card presents `reader.followStory`, `pressed: false`; a followed one `reader.unfollowStory`, `pressed: true`; `run()` flips `story.followed` at once and back when the request fails.
- `entry-actions.component.spec.ts`: two list actions render in order with their labels; `aria-pressed="true"` on a pressed one; none render where the list provides `[]`.
- `i18n-dictionaries.spec.ts`: add the new keys to the Trending label check (all four languages non-empty).

- [ ] **Step 2: Run them to verify they fail**

Run: `docker compose exec -T frontend npm test -- trending entry-actions i18n-dictionaries entries.store`
Expected: FAIL.

- [ ] **Step 3: Implement, with these strings**

| key | en | de | fr | es |
|---|---|---|---|---|
| `reader.followStory` | Follow story | Geschichte folgen | Suivre le sujet | Seguir noticia |
| `reader.unfollowStory` | Unfollow story | Geschichte nicht mehr folgen | Ne plus suivre le sujet | Dejar de seguir noticia |
| `reader.actionHint.followStory` | Follow this story — what joins it shows in the sidebar | Dieser Geschichte folgen — was neu dazukommt, erscheint in der Seitenleiste | Suivre ce sujet — ce qui s'y ajoute apparaît dans la barre latérale | Seguir esta noticia: lo que se le añada aparece en la barra lateral |
| `reader.actionHint.unfollowStory` | Stop following this story | Dieser Geschichte nicht mehr folgen | Ne plus suivre ce sujet | Dejar de seguir esta noticia |

- [ ] **Step 4: Run the specs and the gate**

Run: `docker compose exec -T frontend npm test` and `npm run check` (from `frontend/`) — PASS.

- [ ] **Step 5: Commit**

```bash
git add -A frontend/src frontend/public/i18n
git commit -m "feat(#2128): follow a Trending story from its card and hide it by id"
```

---

### Task 9: Frontend — the "Followed stories" sidebar section

**Files:**
- Create: `frontend/src/app/reader/state/followed-stories.store.ts`
- Create: `frontend/src/app/reader/shell/sidebar/sidebar-followed-stories.component.{ts,html,scss,spec.ts}`
- Modify: `frontend/src/app/reader/shell/sidebar/sidebar.component.{ts,html}`, `frontend/src/app/reader/shell/sidebar-counts-poll.service.ts`, `trending-follow-action.ts`
- Modify: `frontend/public/i18n/{de,en,fr,es}.json`
- Test: `followed-stories.store.spec.ts`, `sidebar-followed-stories.component.spec.ts`, `sidebar-counts-poll.service.spec.ts`

**Interfaces:**
- Consumes: `TrendingApi.followed()`, `.unfollow()`, `.opened()`; `countsAreStale()` from `state/sidebar-freshness`; `SidebarRowMenuComponent`.
- Produces:
  - `FollowedStoriesStore` (root, cleared on identity change like the other stores): `stories: Signal<FollowedStoryDto[]>`, `load(): void`, `reloadIfStale(): void` (same freshness and no-stacking rules as `SavedSearchesStore`), `byId(id: number): FollowedStoryDto | null` (a computed lookup, not a method that reads a signal per call if a computed map serves), `unfollow(id: number, onSuccess?: () => void): void` (removes the row on success), `markOpened(id: number): void` (sets `newCount` 0 at once, POSTs, keeps the zero on failure and lets the next load correct it).
  - `<app-sidebar-followed-stories [stories] [activeStoryId]>` placed right after `<app-sidebar-views>` (Trending's row lives there), before saved searches; renders nothing when `stories` is empty; collapsible with the saved-searches chevron pattern, default collapsed, collapsed head shows the summed `newCount`. Row: `routerLink` `['/trending/followed', id]`, the headline on one line with ellipsis (`reader.followedStoryFallback` when null), the count flush right in the sidebar count style when > 0, a quiet marker (`reader.followedStoryQuiet`, title `reader.followedStoryQuietHint`) when `quiet`, and an `app-sidebar-row-menu` with one item, Unfollow. Rows in the server's order (no client re-ranking).
  - `SidebarCountsPollService.refreshCounts()` and `reloadFully()` also call `followedStories.reloadIfStale()`.

- [ ] **Step 1: Write the failing specs**

- store: `load()` adopts the response; `reloadIfStale()` does nothing within the freshness window and while a request is out; `markOpened(3)` zeroes row 3's count before the POST answers; `unfollow(3)` drops the row on success and keeps it on failure.
- component: no `.card` with no stories; head label `reader.followedStories` with summed count; expanded rows show headline, count, quiet marker; a null headline shows the fallback (Review Focus 5); the active row has `.active`; the menu's Unfollow calls `store.unfollow(id)`.
- poll: a counts tick reloads followed stories.

- [ ] **Step 2: Run to verify failure**

Run: `docker compose exec -T frontend npm test -- followed-stories sidebar-followed-stories sidebar-counts-poll` — FAIL.

- [ ] **Step 3: Implement, with these strings**

| key | en | de | fr | es |
|---|---|---|---|---|
| `reader.followedStories` | Followed stories | Verfolgte Geschichten | Sujets suivis | Noticias seguidas |
| `reader.toggleFollowedStories` | Show or hide followed stories | Verfolgte Geschichten ein- oder ausklappen | Afficher ou masquer les sujets suivis | Mostrar u ocultar las noticias seguidas |
| `reader.followedStoryFallback` | Followed story | Verfolgte Geschichte | Sujet suivi | Noticia seguida |
| `reader.followedStoryQuiet` | quiet | ruhig | calme | en calma |
| `reader.followedStoryQuietHint` | Nothing new for two days | Seit zwei Tagen nichts Neues | Rien de nouveau depuis deux jours | Nada nuevo desde hace dos días |
| `reader.followedStoryMenu` | Followed story actions | Aktionen für die verfolgte Geschichte | Actions du sujet suivi | Acciones de la noticia seguida |

Styles reuse `_sidebar-row.scss` and the tokens from `docs/design-language.md`; no new hex or px values. `TrendingFollowAction` now calls `FollowedStoriesStore.load()` after a follow or unfollow succeeds.

- [ ] **Step 4: Run specs and gate**

Run: `docker compose exec -T frontend npm test` and `npm run check` — PASS.

- [ ] **Step 5: Commit**

```bash
git add -A frontend/src frontend/public/i18n
git commit -m "feat(#2128): show followed Trending stories in the sidebar with their new counts"
```

---

### Task 10: Frontend — opening a followed story

**Files:**
- Modify: `frontend/src/app/reader/query/query.ts`, `entry-query.ts`, `mark-read-target.ts`, `reader-matcher.ts`, `frontend/src/app/reader/models.ts`, `reader-api.ts`
- Modify: `frontend/src/app/reader/shell/list-heading.service.ts`, `frontend/src/app/reader/list/list-header/list-header.component.{ts,html}`, `frontend/src/app/reader/list/entry-list/entry-list.component.{ts,html,scss}`, `frontend/src/app/reader/state/entries.store.ts`, every other `BySelectionKind`/`Record<SelectionKind, …>` table `tsc` names
- Create: `frontend/src/app/reader/shell/followed-story-unfollow.service.ts`
- Modify: `frontend/public/i18n/{de,en,fr,es}.json`
- Test: `query.spec.ts`, `reader-matcher.spec.ts`, `entry-query.spec.ts`, `reader-api.spec.ts`, `entries.store` spec, `entry-list.component.spec.ts`, `list-heading.service.spec.ts`, `followed-story-unfollow.service.spec.ts`

**Interfaces:**
- Consumes: `GET /api/entries/trending-stories/{id}`, `FollowedStoriesStore.byId()`, `.markOpened()`, `.unfollow()`.
- Produces:
  - Selection kind `'followed-story': OrderedList & { id: number }`; identity key `String(id)`; URL `/trending/followed/:id` matched by `readerMatcher` (three segments `trending`, `followed`, an id; `?entry=` composes on top as for saved searches; a non-numeric id falls back to the Trending list).
  - `EntryQuery.followedStoryId?: number`; `VIEW_QUERY['followed-story'] = (selection) => ({ view: 'all', followedStoryId: selection.id })`; `markReadTarget` → `null`; `LIST_ICON` → `'bookmark_added'`; heading title = the store's headline, else `reader.followedStoryFallback`; count = `items(entries().length)`.
  - `ReaderApi.entries()` sends a `followedStoryId` query to `/api/entries/trending-stories/{id}` with the usual cursor, limit and order params; response `FollowedStoryPageDto extends EntriesPage { lastOpenedAt: string; newEntryIds: number[] }`.
  - `EntriesStore.newSinceVisitIds: Signal<ReadonlySet<number>>`, set from the first page of a followed-story query (empty for any other list or later page); when that first page lands, the store calls `FollowedStoriesStore.markOpened(id)` once per selection.
  - The entry list draws a "Since your last visit" divider (`reader.sinceLastVisit`) above the first row not in `newSinceVisitIds`, only when the first row is new and some row is not.
  - `FollowedStoryUnfollow` (list-header action, the `SavedSearchToggle` pattern): shown only on `'followed-story'`, label `reader.unfollowStory`; on success navigates to the Trending list in the current window.

- [ ] **Step 1: Write the failing specs**

- matcher: `/trending/followed/42` → `{ kind: 'followed-story', id: 42 }`; `/trending/followed/42?entry=7` keeps entry 7; `/trending/followed/x` → Trending.
- entry-query/api: the selection requests `GET /api/entries/trending-stories/42` with `order=oldest` when set.
- store: first page sets `newSinceVisitIds` and calls `markOpened(42)` once; page 2 leaves the set alone and calls nothing; another list clears the set.
- entry-list: rows `[new, new, old]` show the divider before the third; `[old, …]` and `[new, new]` show none.
- heading: title is the headline; the fallback when the store has no headline.
- unfollow service: visible only on a followed story; success navigates to Trending.

- [ ] **Step 2: Run to verify failure**

Run: `docker compose exec -T frontend npm test -- query reader-matcher entry-query reader-api entries.store entry-list list-heading followed-story-unfollow` — FAIL.

- [ ] **Step 3: Implement, with this string**

| key | en | de | fr | es |
|---|---|---|---|---|
| `reader.sinceLastVisit` | Since your last visit | Seit deinem letzten Besuch | Depuis votre dernière visite | Desde tu última visita |

- [ ] **Step 4: Run specs and gate**

Run: `docker compose exec -T frontend npm test` and `npm run check` — PASS.

- [ ] **Step 5: Commit**

```bash
git add -A frontend/src frontend/public/i18n
git commit -m "feat(#2128): open a followed Trending story as an entry list marked since the last visit"
```

---

### Task 11: Whole-branch verification and live check

**Files:** none new; fixes land in the task's own files.

- [ ] **Step 1: Every backend gate on the whole branch**

Run from `backend/`: `php bin/phpunit`, `docker compose exec php composer test:parallel` (in parallel), `composer check`, `rm -rf ~/.pdepend && composer md`, `composer infection:diff`. Expected: all green; `composer show larspohlmann/phptramp` printed if tramp fails.

- [ ] **Step 2: Frontend gate**

Run: `docker compose exec -T frontend npm test` and `npm run check` (from `frontend/`). Expected: green.

- [ ] **Step 3: Dev log scan**

Run: `ls -t backend/var/log/dev-*.log | head -1 | xargs tail -n 400 | jq -c 'select(.level_name != "DEBUG" and .level_name != "INFO")'`. Expected: no deprecation or error from the new code.

- [ ] **Step 4: Live check on http://localhost:4200 against the dev database**

With the worker running (Task 5 left it on): follow a story from a Trending card in "24 h"; it shows as followed in "7 days" too; the sidebar shows "Followed stories" with its headline; after the next build that adds a member, the row's count rises; opening it lists the entries with the divider and the count drops to 0; Unfollow from the row menu and from the list header both remove it; hiding a story keeps it hidden across a `app:trending:rebuild week`. Switch the UI to fr and es and read every new string in place. Mobile width: the row's headline ellipsises and the count stays flush right.

- [ ] **Step 5: Commit any fixes, then hand off**

```bash
git add -A
git commit -m "fix(#2128): <what the verification found>"   # only if it found something
```

---

## Calls made while planning

- **Placement as two flags on the member table** (`in_day`, `in_week`) instead of a separate placement table — cost if wrong: one more migration to split the table; the read path already goes through `TrendingPlacementSql`, so only that class and the writer change.
- **`first_seen`/`last_seen` stay and only widen** (earlier first, later last) when either window saves a story — cost: ranking ties between equal-outlet stories may order slightly differently than today; one `CASE` to revise.
- **Staying members keep their stored `article_site`/`shared_link_id`**; only joining members write them — cost: a post whose short link resolves after it joined keeps its old site in that story's counting; fix is an update in `save()`.
- **D41 compares story ids with `NOT IN`, appended only for a non-empty list** — cost: if a day story and a week story of one event end up with different ids (a day group attached elsewhere), the event shows in both windows until the next week build merges them.
- **`TrendingRun` carries the `since` it was collected with**, so the stored-members read uses the same instant — cost: none beyond one constructor argument; skipping it risks a duplicate-key failure on the boundary.
- **Merging is done in PHP rows, not a self-referencing UPDATE** (MySQL 1093) — cost: a few more statements per merge; merges are rare.
- **Hide is by story id only; the request drops `window`** and a hide needs only that the story exists, not that the reader sees it — cost: a client could hide a story it never saw; harmless, it is their own row.
- **The 404 for opening or listing a story the reader does not follow is a new `trending_story_not_followed` problem** — cost: one mapper arm; the frontend treats both 404s the same.
- **The followed story's list is its own endpoint `GET /api/entries/trending-stories/{id}`**, as the single saved search is (`/api/entries/saved-searches/{id}`) — cost: if a shared `/api/entries?story=` filter is wanted later, the controller moves; repository and service stay.
- **The list shows the story's whole history** (every member, any placement) from feeds the reader follows and includes in Trending, newest published first, with the usual order toggle and no unread switch or mark-all-read — cost: a reader expecting only in-window entries sees more; one predicate to narrow.
- **"New" travels as `newEntryIds` on the page plus `lastOpenedAt`; the frontend POSTs `opened` when the first page lands and draws one divider from page 1 only** — cost: when every row of page 1 is new, later new rows are not marked; fixing it means sending the visit time back as a query parameter.
- **The divider sits above the first row not new** in a published-date list, so a newly grouped old article below it is not marked — cost: one per-row marker instead of the divider.
- **Headline is the newest member's title by `effective_date`, null when the reader sees no member**; the sidebar shows "Followed story" then — cost: a headline that reads oddly; the rule lives in one repository query.
- **New count counts social posts by account whether or not they share a card** (the spec's "a social account once"), unlike the Trending outlet count, which counts only card-sharing posts — cost: a slightly higher count; one `CASE` to align.
- **Follows and hides are left out of the account backup** with a coverage reason — cost: a restored account loses its followed stories; adding them means mapping old story ids to regrouped ones, which the spec does not ask for.
- **`TrendingStoryEntry`'s constructor takes five arguments** (story, entry, site, link, joinedAt) plus `placeIn()`; it is built with `new` only in fixtures — cost: a PHPMD or review objection; then the card pair becomes an `App\Entity` value.
- **`app:trending:rebuild <window>` is added** to run the spec's measuring builds on demand — cost: one small command to delete if unwanted.
- **Follow icon `bookmark_add`/`bookmark_added`, header icon `bookmark_added`, rows in the server's `lastJoinedAt` order, section default collapsed, placed between the views and saved searches** — cost: cosmetic; a template change each.
- **`EntryRowAction` becomes a list of actions with a per-entry presentation** so Follow can sit beside Hide and show its state — cost: touches the shared action row; every provider of the token changes with it (two today).
- **Migration down restores hidden entries from story hides and drops follows** — cost: follows lost on a rollback.
