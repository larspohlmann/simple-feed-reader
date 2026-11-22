# VIEWED and LIKED list each post once (#2471) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** In the reader's recommendation history, a social post reached through several subscribed feeds takes one VIEWED line, and neither VIEWED nor LIKED lists it when another of the reader's copies sits in a higher section.

**Architecture:** All in `ReadingHistoryRepository`. Viewed state spreads to every copy of a `canonical_item` (#2404); favorite, kept and share do not. `viewed()` gains one sibling-copy condition: drop the row when another copy of its item, in a feed the reader follows, is favorite, kept or shared, or is an earlier (lower-id) viewed copy. `liked()` swaps its per-copy favorite and share checks for the same "any followed copy of the item" checks. Entries without an item never match a sibling (`canonicalItem = NULL` is never true), so RSS articles are untouched.

**Tech Stack:** PHP 8.5, Doctrine DQL, PHPUnit 12; MySQL 8.4 for the measurement.

**Spec:** https://github.com/larspohlmann/simple-feed-reader/issues/2471, scope ruled by Lars on 2026-11-22: VIEWED + LIKED (KEPT, FAVORITES and SHARED unchanged).

## Global Constraints

- Entries without a canonical item keep exactly today's VIEWED and LIKED behaviour.
- A copy in a feed the reader does not follow never hides the reader's own copy (in either direction: it is not a "higher" sibling and not an "earlier viewed" sibling).
- The VIEWED line is the lowest-id followed viewed copy, as LIKED already picks the lowest-id followed copy.
- Queries stay in `src/Repository`; no new class unless the DQL fragment is needed outside this repository (it is not).
- Measure the real statement on the dev MySQL before and after (baseline 2026-11-22, user 2, 145k entries: `viewed()` 15–60 ms, each `liked()` statement 1–8 ms, `EXPLAIN ANALYZE` actual time; 13 of 80 VIEWED lines were repeats over 9 posts).

## Review Focus

- A post whose lower copy is viewed and kept, higher copy viewed only: it lists in KEPT only, not also in VIEWED through the higher copy.
- Two viewed copies, the lower one in a feed only a stranger follows: the reader's copy still lists.
- A favorite copy in a feed the reader left: the viewed copy in a followed feed still lists in VIEWED.
- Two viewed RSS articles without an item, even with the same URL: both keep their lines.

---

### Task 1: One VIEWED line per post, and item-wide section exclusions for VIEWED and LIKED

**Files:**
- Modify: `backend/src/Repository/ReadingHistoryRepository.php` (`viewed()`, `likedEntriesQueryBuilder()`, class docblock)
- Test: `backend/tests/Repository/ReadingHistoryRepositoryTest.php`

**Interfaces:**
- Produces: no public signature change. Private DQL builders in the repository: `buildFollowedCopyStateCondition(string $copyAlias, string $itemExpression, string $stateCondition): string` (an `EXISTS` over `Entry` copies of the item joined to the reader's `Subscription` and `EntryState`) and `buildFollowedCopySharedCondition(string $copyAlias, string $itemExpression): string` (the same over `EntryShare`). Both bind `:user`. Names follow the `build…` row of the verb table.

- [ ] **Step 1: Write the failing tests** in `ReadingHistoryRepositoryTest`, using the existing `persistSubscribedFeed`, `linkNewItem`, `SavedPostFixtures`, `EntryShareFixtures` helpers:
  - `testTwoViewedCopiesOfAPostGiveOneLine` — both copies viewed: `viewed()` is `[$lower]`.
  - `testAViewedLowerCopyInAFeedOnlyAStrangerFollowsDoesNotHideMine` — `viewed()` is `[$higher]`.
  - `testAViewedPostWithACopyInAHigherSectionIsNotInViewed` with a data provider `favorite`, `kept`, `shared` marking the *other* copy: `viewed()` is `[]`, and that section lists the marked copy.
  - `testAViewedAndKeptLowerCopyKeepsThePostOutOfViewed` — lower copy viewed + kept, higher viewed: `kept()` is `[$lower]`, `viewed()` is `[]`.
  - `testAFavoriteCopyInAFeedTheReaderLeftDoesNotHideTheViewedCopy` — `viewed()` is `[$followedCopy]`.
  - `testViewedEntriesWithoutAnItemEachKeepTheirLine` — two viewed entries, no item, same URL: both listed, newest viewed first.
  - `testALikedPostWithAFavoriteHigherCopyIsOnlyInFavorites` and `testALikedPostWithASharedHigherCopyIsOnlyInShared` — liked item, the higher copy marked: `liked()` is `[]`.

- [ ] **Step 2: Run them and watch them fail**

Run: `php bin/phpunit tests/Repository/ReadingHistoryRepositoryTest.php`
Expected: the new tests fail (repeat lines, or the post also in VIEWED/LIKED); `testViewedEntriesWithoutAnItemEachKeepTheirLine` and the stranger test may already pass — they guard the rule, not the bug.

- [ ] **Step 3: Implement**

`viewed()` adds `NOT buildFollowedCopyStateCondition('sibling', 'e.canonicalItem', '<sibling is not e> AND (favorite OR kept OR (viewed AND sibling.id < e.id))')` and `NOT buildFollowedCopySharedCondition('sharedSibling', 'e.canonicalItem')`, keeping its per-entry checks. `likedEntriesQueryBuilder()` replaces the `es` left join, the `es.isFavorite` check and `EntryShareDql::exists` with `NOT` of the two builders over `liking.canonicalItem` (favorite only for the state one); its lowest-copy rule stays. Update the class docblock: VIEWED and LIKED treat a post's followed copies as one.

- [ ] **Step 4: Run the history tests**

Run: `php bin/phpunit tests/Repository/ReadingHistoryRepositoryTest.php tests/Service/Curation/Recommendation`
Expected: green.

- [ ] **Step 5: Measure on MySQL**

Capture the new `viewed()` and `liked()` SQL from the dev log (`backend/var/measure-viewed.php`, untracked), bind the params, `EXPLAIN ANALYZE` four times each. Expected: within noise of the baseline; VIEWED for user 2 shows 0 repeated items. Record the numbers in the PR body.

- [ ] **Step 6: Gates and suites**

Run: `composer check && composer md`, PhpStorm lint on both files, `composer test:parallel` natively and `docker compose exec php composer test:parallel`.
Expected: clean and green.

- [ ] **Step 7: Commit**

```bash
git add backend/src/Repository/ReadingHistoryRepository.php backend/tests/Repository/ReadingHistoryRepositoryTest.php
git commit -m "fix(#2471): VIEWED lists a post once, and VIEWED and LIKED skip a post another copy holds higher"
```
