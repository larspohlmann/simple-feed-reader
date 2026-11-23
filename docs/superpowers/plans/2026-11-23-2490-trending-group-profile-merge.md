# Trending Group Profile Merge Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** One event no longer splits into several Trending stories. After the link-by-link grouping, a second pass merges whole groups whose headline profiles are alike.

**Architecture:** `StoryGroups` stays as it is. `StoryGrouping::group()` hands its groups to a new per-call pass, `Pass/ProfileMerges`. The pass gives each group of at least 3 members a profile and merges, round by round, the most similar pairs within the window's link gap. A profile is a stem id ⇒ weight map: each filler-free headline stem that more than a third of the members carry, weighted by share × rarity. Similarity is weighted Jaccard. Both the day and the week build go through `StoryGrouping`. The week reshape then merges the already-stored fragments through `StoryMatching`'s existing ≥ 0.5 overlap rule, so no story code changes.

**Tech Stack:** PHP 8.5, Symfony 8.1, PHPUnit 12.

**Spec:** GitHub issue #2490. It holds the cause, the measurements and the rejected alternatives.

## Global Constraints

- Constants, measured on Strato's day window 2026-11-23: `MERGE_SIMILARITY = 0.25`, `MIN_MEMBERS = 3`. A profile keeps a stem whose member share is **strictly more than 1/3**.
- Similarity is weighted Jaccard: Σ min(wₐ, w_b) / Σ max(wₐ, w_b) over the union of both profiles' stems; two empty profiles give 0.0.
- Gap: two groups may merge only when the distance between their time spans (0 when they overlap) is ≤ `TrendingWindow::maxLinkGapSeconds()` (day 36 h, week 48 h). The spans use `TrendingRun::readEffectiveTime()`.
- Only headline stems count (the entry's own headline plus its card's headline, as `HeadlineWindow` already files them). Body stems don't count, and neither does any stem `HeadlineWindow` treats as filler for that entry (window filler or feed filler).
- Output shape stays `list<non-empty-list<int>>`: members ascending, groups ordered by their lowest member, the same as `StoryGroups::groups()`.
- The constants are a ruling, not a fact pinned to this week's news. Tests use synthetic headlines, never COP31 wording, and Task 4 validates the constants by net effect.
- House rules from CLAUDE.md apply: PHPMD-clean touched files, `composer check`, both test legs, `composer infection:diff`. No comments unless one clears the bar. Commits are `type(#2490): …`.

## Review Focus

- **Chaining:** a grown group must not absorb an unrelated story through one shared generic stem ("trump", "house"). One shared stem among many distinct ones stays below 0.25. Pinned in Task 3 (`testGroupsSharingOneStemAmongManyStayApart`).
- **Gap on the week window:** two alike groups 3 days apart stay two stories. Pinned in Task 3 (`testAlikeGroupsBeyondTheLinkGapStayApart`).
- **Order independence:** the same groups in a different input order give the same result. Pinned in Task 3 (`testTheOrderGroupsArriveInDoesNotChangeTheMerges`).
- **Pairs untouched:** a 2-member group is never merged by the pass, because its profile is just two headlines, which the 40 % link rule already judged. Pinned in Task 3 (`testATwoMemberGroupIsNeverMerged`).
- **Week build time:** Strato's week build already takes ~50–68 s. The pass must use a stem ⇒ groups index, not all pairs. Measured in Task 4.

---

### Task 1: `HeadlineWindow` exposes an entry's headline stems and a stem's rarity

**Files:**
- Modify: `backend/src/Service/Curation/Trending/Pass/HeadlineWindow.php`
- Test: `backend/tests/Service/Curation/Trending/Pass/HeadlineWindowTest.php`

**Interfaces:**
- Produces: `HeadlineWindow::collectHeadlineStemIds(int $entryId): list<int>`, the entry's headline stem ids with the ones `isFillerFor($entryId, …)` rejects left out. `HeadlineWindow::computeRarity(int $stemId): float` is the existing private `rarity()` made public and renamed. Update its callers (`weighTotal`, `sharedWeightsWithEarlier`, the `PartWeightCaps` callable).

- [ ] **Step 1: Write the failing tests** in `HeadlineWindowTest`, using the file's own `add()` helper and constants:
  - `testHeadlineStemIdsLeaveOutBodyStems`: `add(1, 10, 'Storm floods', 'Harbour warning')`. Then `count(collectHeadlineStemIds(1)) === 2`.
  - `testHeadlineStemIdsLeaveOutFeedFiller`: feed 10 gets 4 entries whose headlines all carry `Briefing`, plus one distinct word each. Then `count(collectHeadlineStemIds(1)) === 1`.
  - `testRarityFollowsDocumentFrequency`: `add(1, 10, 'Storm', '')`, `add(2, 20, 'Storm', '')`. The single stem id from `collectHeadlineStemIds(1)` gives `computeRarity(...)` equal to `self::TWICE` (delta 0.0001).
- [ ] **Step 2: Run them and check they fail.** Run `cd backend && php bin/phpunit tests/Service/Curation/Trending/Pass/HeadlineWindowTest.php`. Expected: an error, undefined method.
- [ ] **Step 3: Implement both methods.**
- [ ] **Step 4: Run them and check they pass.** Run the same command plus `php bin/phpunit tests/Service/Curation/Trending`. Expected: all green.
- [ ] **Step 5: Commit** `feat(#2490): a headline window lists an entry's headline stems and weighs a stem's rarity`.

### Task 2: A group's profile

**Files:**
- Create: `backend/src/Service/Curation/Trending/Model/GroupProfileModel.php`
- Create: `backend/src/Service/Curation/Trending/Support/GroupProfiles.php`
- Test: `backend/tests/Service/Curation/Trending/Model/GroupProfileModelTest.php`, `backend/tests/Service/Curation/Trending/Support/GroupProfilesTest.php`

**Interfaces:**
- Consumes: Task 1's two methods, `TrendingRun::readEffectiveTime()` and `TrendingRun::$headlines`.
- Produces:
  - `final readonly class GroupProfileModel(array<int, float> $stemWeights, int $firstSeen, int $lastSeen)` with:
    - `computeSimilarity(self $other): float`, the weighted Jaccard from Global Constraints;
    - `isWithinGap(self $other, int $maxGapSeconds): bool`.
  - `GroupProfiles::build(TrendingRun $run, non-empty-list<int> $entryIds): GroupProfileModel`, a static-only class with a private constructor, like `CentroidScores`.

- [ ] **Step 1: Write the failing model tests** (plain arrays, no run):
  - `testIdenticalProfilesScoreOne`: `[1 => 2.0, 2 => 3.0]` against itself gives `1.0`.
  - `testSimilarityIsSumOfMinimaOverSumOfMaxima`: `[1 => 2.0, 2 => 4.0]` against `[2 => 2.0, 3 => 2.0]` gives `2.0 / 8.0`.
  - `testDisjointOrEmptyProfilesScoreZero`: `[1 => 1.0]` against `[2 => 1.0]` gives `0.0`, and `[]` against `[]` gives `0.0`.
  - `testOverlappingSpansAreWithinAnyGap`: spans 100–200 and 150–300 with gap 0 give true.
  - `testSpansFurtherApartThanTheGapAreNot`: spans 0–100 and 200–300 give true at gap 100 and false at gap 99.
- [ ] **Step 2: Write the failing builder tests.** Each uses `TrendingRuns::of(TrendingWindow::Day)` and `TrendingRuns::entry(...)`, one feed per entry, and headlines of invented words.
  - `testAStemMoreThanAThirdOfMembersCarryIsKept`: 3 entries, 2 carry `Zorbel`. The profile holds that stem, weighing `2/3 * computeRarity(id)`.
  - `testAStemOnlyAThirdCarryIsLeftOut`: 3 entries, 1 carries `Quaffle`. That stem is absent.
  - `testTheSpanRunsFromTheOldestToTheNewestMember`: entries at 08:00, 11:00 and 09:00 give `firstSeen`/`lastSeen` equal to the 08:00 and 11:00 timestamps.
- [ ] **Step 3: Run them and check they fail.** Run `php bin/phpunit tests/Service/Curation/Trending/Model/GroupProfileModelTest.php tests/Service/Curation/Trending/Support/GroupProfilesTest.php`. Expected: an error, class not found.
- [ ] **Step 4: Implement.** Count each stem once per member.
- [ ] **Step 5: Run them and check they pass.** Same command. Expected: green.
- [ ] **Step 6: Commit** `feat(#2490): a story group's headline profile and how alike two are`.

### Task 3: `ProfileMerges` merges alike groups after `StoryGroups`

**Files:**
- Create: `backend/src/Service/Curation/Trending/Pass/ProfileMerges.php`
- Modify: `backend/src/Service/Curation/Trending/StoryGrouping.php` (it returns `(new ProfileMerges($run, $groups->groups()))->groups()`)
- Test: `backend/tests/Service/Curation/Trending/Pass/ProfileMergesTest.php`, `backend/tests/Service/Curation/Trending/StoryGroupingTest.php`

**Interfaces:**
- Consumes: `GroupProfiles::build()`, `GroupProfileModel::computeSimilarity()`, `isWithinGap()`, `$run->window->maxLinkGapSeconds()`.
- Produces: `final class ProfileMerges`, with `__construct(TrendingRun $run, list<non-empty-list<int>> $groups)` and `groups(): list<non-empty-list<int>>`. The constants `MERGE_SIMILARITY = 0.25` and `MIN_MEMBERS = 3` are private on it.

The algorithm is not fixed by the signature. It runs in rounds:

1. Build an index of stem id ⇒ indexes of the groups of at least `MIN_MEMBERS` whose profile holds that stem.
2. Candidate pairs are groups that share at least one stem, are within the gap, and have similarity ≥ `MERGE_SIMILARITY`.
3. Sort the pairs by similarity descending, then by the lower of the two groups' lowest member ids ascending, then by the higher one ascending.
4. Take pairs greedily, each group at most once per round, and merge each taken pair.
5. Rebuild the merged groups' profiles and repeat until a round merges nothing.

- [ ] **Step 1: Keep the existing `StoryGroupingTest` honest.** Its `runPublishedAt()` gives every entry the headline `'Entry'`, so the new pass would merge its separate groups. Give each entry a headline no other entry shares, e.g. `sprintf('Zeile%03d', $entryId)`, and confirm the stemmer keeps it as one unique stem. Run `php bin/phpunit tests/Service/Curation/Trending/StoryGroupingTest.php`. Expected: green before any `src` change.
- [ ] **Step 2: Write the failing `ProfileMergesTest`.** Build runs as in Task 2, and pass the groups in directly as entry id lists.
  - `testAlikeGroupsMerge`: groups [1,2,3] and [4,5,6]. All six headlines carry `Zorbel Quintar`, and each has one unique word. Result: `[[1,2,3,4,5,6]]`.
  - `testMergesChainAcrossRounds`: three alike groups. A and C score below 0.25 against each other, but each scores ≥ 0.25 against B, and A∪B still scores ≥ 0.25 against C. Result: one group.
  - `testGroupsSharingOneStemAmongManyStayApart`: two 3-member groups share only `Trumbel`. Each group's members also share three more stems of their own. Result: unchanged.
  - `testAlikeGroupsBeyondTheLinkGapStayApart`: a week run, the same headlines as `testAlikeGroupsMerge`, the second group published 3 days after the first. Result: unchanged.
  - `testATwoMemberGroupIsNeverMerged`: a group [1,2] whose headlines match a 3-member group exactly. Result: unchanged.
  - `testTheOrderGroupsArriveInDoesNotChangeTheMerges`: the `testMergesChainAcrossRounds` groups, passed in reversed. Same result.
  - `testOutputKeepsMembersAscendingAndGroupsByLowestMember`: the merged output of [4,5,6] and [1,2,3] with an unrelated group [7,8,9] is `[[1,2,3,4,5,6],[7,8,9]]`.
- [ ] **Step 3: Run it and check it fails.** Run `php bin/phpunit tests/Service/Curation/Trending/Pass/ProfileMergesTest.php`. Expected: an error, class not found.
- [ ] **Step 4: Implement `ProfileMerges` and wire it into `StoryGrouping::group()`.**
- [ ] **Step 5: Run the Trending suite.** Run `php bin/phpunit tests/Service/Curation/Trending tests/Command`. Expected: green. If a snapshot or rebuild test changes, it is a fixture whose separate groups share headlines: give them distinct headlines, as in Step 1, and name each one in the commit body.
- [ ] **Step 6: Gates.** Run `composer cs && composer stan && composer tramp && composer md`. Expected: clean on the touched files.
- [ ] **Step 7: Commit** `feat(#2490): story groups with alike headline profiles merge into one`.

### Task 4: Validate the constants on real windows before the PR

No `src` change unless the numbers call for it. Every script lives in the session scratchpad, never in the repo.

- [ ] **Step 1: Dev MySQL, develop against the branch.** Run `app:trending:explain --user=<dev user> --window=day` and then `--window=week` on both sides, using the native env from the #2225 memory. Record the group count, the **entry pairs sharing a group**, the build ms and the peak MB.
- [ ] **Step 2: List the pass's merges.** Use a throwaway script that diffs the two explain outputs. Print each new merge with the newest headline of each side. Classify each one as the same event or a false merge.
- [ ] **Step 3: Strato.** This needs Lars's yes first, because it uploads a branch build to a scratch folder in the Strato home: `~/sfr-2490/`, not `releases/`, with no symlink flip and no migration. Then run the read-only explain for user 4 on the day and week windows against both the live release and the branch copy, and remove the scratch folder afterwards.
- [ ] **Step 4: Report to Lars.** Include:
  - the net change in grouped pairs and the false-merge count per window;
  - the week build time delta;
  - whether 0.25 / 3 / one-third hold.

  A constant changes only on a structural reason, never to fit one story. Write the outcome into issue #2490 as a comment.
- [ ] **Step 5: Gate before the PR.**
  - Run `php bin/phpunit` (SQLite) and `docker compose exec php composer test:parallel` (MySQL) in parallel.
  - Then run `composer infection:diff`.
  - Then `/simplify`.
  - The PR body says `Closes #2490` and names the "Keep `StoryGroupingTest` honest" fixture change.
