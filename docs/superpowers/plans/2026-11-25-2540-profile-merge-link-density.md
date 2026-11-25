# Profile Merge With Link Density Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Two Trending groups also merge at a profile similarity ≥ 0.25 when their whole groups link at a density ≥ 0.10, so a fast-moving story no longer splits off its early coverage.

**Architecture:** A new per-run `LinkedPairs` pass holds the run's link adjacency once and counts links between two member lists. `StoryGroups` uses it in place of its own map, and `ProfileMerges` uses it to compute the link density of a candidate pair whose similarity falls in the band [0.25, 0.30).

**Tech Stack:** PHP 8.5, PHPUnit 12.

**Spec:** issue #2540 and its comments (measurements, simulated merges, owner's ruling "0.25 + link density 0.10").

## Global Constraints

- Merge rule (owner's ruling): similarity ≥ 0.30 merges as today; similarity ≥ 0.25 merges when links ÷ (members × members) between the two whole groups is ≥ 0.10.
- Density counts only links between a member of one group and a member of the other, over the groups as they stand in that round (after earlier merges).
- `MIN_MEMBERS = 3`, the link-gap check and the most-alike-first order stay as they are.
- `Pass/` objects are built with `new`, never by the container (docs/architecture.md §10).
- House rules in CLAUDE.md: `final` classes, no abbreviations, no boolean flag parameters, no comment that restates code.

## Review Focus

- A link between two members of the same group must not count toward the density of a pair: only cross links count.
- A pair in the band whose density falls short must stay apart even when a stronger pair merges in the same round.
- The density uses the current (merged) groups in a later round, not the groups that arrived.
- Similarity just below 0.25 with full density stays apart: the link evidence never lowers the floor below 0.25.
- A pair at ≥ 0.30 still merges with no links at all (today's behaviour).

---

### Task 1: `LinkedPairs` pass, used by `StoryGroups`

**Files:**
- Create: `backend/src/Service/Curation/Trending/Pass/LinkedPairs.php`
- Modify: `backend/src/Service/Curation/Trending/Pass/StoryGroups.php` (drop `$linked` and `linkCount()`, take `LinkedPairs`)
- Modify: `backend/src/Service/Curation/Trending/StoryGrouping.php` (build it once and pass it in)
- Test: `backend/tests/Service/Curation/Trending/Pass/LinkedPairsTest.php`

**Interfaces:**
- Produces: `final readonly class LinkedPairs` with
  - `public static function fromLinks(array $links): self` (`@param list<StoryLinkModel> $links`)
  - `public function countBetween(array $entryIds, array $otherEntryIds): int` (`@param list<int>` both): for each id in `$entryIds`, the number of ids in `$otherEntryIds` it links to, summed. This is today's `StoryGroups::linkCount()`.
- `StoryGroups::__construct(TrendingRun $run, LinkedPairs $linkedPairs)`

- [ ] **Step 1: Write the failing tests** in `LinkedPairsTest`:
  - `testALinkCountsInBothDirections`: links `1–2`; `countBetween([1], [2])` and `countBetween([2], [1])` are both `1`.
  - `testUnlinkedEntriesCountNothing`: links `1–2`; `countBetween([1], [3])` is `0`, `countBetween([4], [2])` is `0`.
  - `testEveryLinkedPairBetweenTheListsCounts`: links `1–3, 1–4, 2–4`; `countBetween([1, 2], [3, 4])` is `3`.
  - `testADuplicateLinkCountsOnce`: links `1–2` given twice; `countBetween([1], [2])` is `1`.

  Build links with `new StoryLinkModel($one, $other, 1.0)`.
- [ ] **Step 2: Run** `php bin/phpunit tests/Service/Curation/Trending/Pass/LinkedPairsTest.php` (from `backend/`). Expected: FAIL, class not found.
- [ ] **Step 3: Implement `LinkedPairs`** as an adjacency `array<int, array<int, true>>`, both directions, and move `linkCount()`'s `array_intersect_key` body into `countBetween()`. Then switch `StoryGroups` to it and make `StoryGrouping::group()` build `LinkedPairs::fromLinks($links)` once.
- [ ] **Step 4: Run** `php bin/phpunit tests/Service/Curation/Trending` and `composer cs`, plus `vendor/bin/phpstan analyse` on the changed files. Expected: all green; `StoryGroupingTest` is unchanged and passes.
- [ ] **Step 5: Commit** `refactor(#2540): one per-run link adjacency the story groups count against`

### Task 2: `ProfileMerges` merges an alike pair whose whole groups link densely

**Files:**
- Modify: `backend/src/Service/Curation/Trending/Pass/ProfileMerges.php`
- Modify: `backend/src/Service/Curation/Trending/StoryGrouping.php` (pass the same `LinkedPairs`)
- Test: `backend/tests/Service/Curation/Trending/Pass/ProfileMergesTest.php`

**Interfaces:**
- Consumes: `LinkedPairs::countBetween()` from Task 1.
- Produces: `ProfileMerges::__construct(TrendingRun $run, array $groups, LinkedPairs $linkedPairs)`.
- Constants: keep `MERGE_SIMILARITY = 0.30`; add `LINKED_MERGE_SIMILARITY = 0.25` and `MIN_LINK_DENSITY = 0.10`.

- [ ] **Step 1: Update the test helper and write the failing tests.** `merged()` takes an optional `list<array{int, int}> $links = []` and builds `LinkedPairs::fromLinks()` from it. Existing tests pass no links and keep their expectations. The similarities below come from `runWithEvenRarity()`, where only counts matter: 3 shared words, plus 3 own words in one group and *n* in the other, give 3 / (6 + *n*).
  - `testGroupsInTheBandMergeWhenTheirMembersLinkDensely`: `runWithEvenRarity('Kalbo Paskel Gimbor', 'Hodrin Lumpo Fradel Quobin Wistel')` (3/11 ≈ 0.27), links `1–4`; expected `[[1, 2, 3, 4, 5, 6]]` (1 of 9 pairs = 0.11).
  - `testGroupsInTheBandWithoutCrossLinksStayApart`: same run, no links; expected `[[1, 2, 3], [4, 5, 6]]`.
  - `testLinksInsideOneGroupDoNotCountTowardTheDensity`: same run, links `1–2, 4–5`; expected `[[1, 2, 3], [4, 5, 6]]`.
  - `testGroupsExactlyAtTheLinkedMergeSimilarityMerge`: words `'Kalbo Paskel Gimbor'` / `'Hodrin Lumpo Fradel Quobin Wistel Trumbel'` (3/12 = 0.25), link `1–4`; expected one group.
  - `testGroupsBelowTheLinkedMergeSimilarityStayApartHoweverDense`: words `'Kalbo Paskel Gimbor'` / `'Hodrin Lumpo Fradel Quobin Wistel Trumbel Hopsik'` (3/13 ≈ 0.23), all 9 cross links; expected two groups.
  - `testTheDensityMustReachATenth`: two groups of 4 members at ≈ 0.27 (extend the even-rarity helper with a member count, or build an equivalent run); 1 cross link of 16 pairs (0.0625) stays apart, 2 of 16 (0.125) merges. Pins `MIN_LINK_DENSITY` from both sides.
- [ ] **Step 2: Run** `php bin/phpunit tests/Service/Curation/Trending/Pass/ProfileMergesTest.php`. Expected: the new tests FAIL (the band pairs stay apart, or the constructor arity errors).
- [ ] **Step 3: Implement.** In `collectCandidatePairs()`, keep a pair when its similarity is ≥ `MERGE_SIMILARITY`, or when it is ≥ `LINKED_MERGE_SIMILARITY` and `linkDensity($lowerKey, $higherKey)` is ≥ `MIN_LINK_DENSITY`. `private function linkDensity(int $lowerKey, int $higherKey): float` is `countBetween()` over the two current member lists ÷ (count × count). Compute it only for pairs in the band. `StoryGrouping::group()` passes its `LinkedPairs` to `new ProfileMerges(...)`.
- [ ] **Step 4: Run** `php bin/phpunit tests/Service/Curation/Trending`, `composer cs`, phpstan on the changed files and `vendor/bin/phpmd` on `ProfileMerges.php` (codesize). Expected: all green and PHPMD-clean.
- [ ] **Step 5: Commit** `feat(#2540): story groups alike at a quarter merge when their members link densely`

### After the tasks

The pre-PR gate set runs once (CLAUDE.md "Gate cadence"), backend only. Then, after deploy, a read-only rerun of `app:trending:explain --user=4 --window=week` on Strato confirms that the Nvidia groups are one.
