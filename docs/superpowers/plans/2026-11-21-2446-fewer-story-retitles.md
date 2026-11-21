# Fewer Trending story re-titles (#2446) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A titled Trending story is re-titled only once it has doubled since its last title, and at most once every 6 hours; an untitled story keeps the 1-hour retry.

**Architecture:** The due rule lives in one SQL query, `TrendingStoryRepository::findStoriesDueForTitle`. It splits into an untitled arm, gated by the 1-hour retry cutoff, and a titled arm, gated by the 6-hour re-title cutoff and by the doubling test. `StoryTitleTask` owns both durations and passes them as one cutoffs value.

**Tech Stack:** Symfony 8.1, Doctrine DBAL, PHPUnit.

**Spec:** GitHub issue #2446, decided by Lars on 2026-11-21 ("Make both changes"). Strato's last 24 h: 42 title calls, 12 new and 30 re-titles, no failures.

## Global Constraints

- Re-title when the member count is at least twice `llm_title_member_count`. The old rule was count × 2 ≥ stored × 3.
- Re-title only when `llm_title_attempted_at` is at least 6 hours old.
- An untitled story is due when it was never attempted, or its last attempt is at least 1 hour old. This is unchanged.
- No migration. Every number lives in `StoryTitleTask`.
- Follow CLAUDE.md: no more than three parameters, so pass the cutoffs as a `…Model` in `Model/`.

## Review Focus

- **A story whose last title attempt failed after it had been titled** keeps its old title and `llm_title_attempted_at` moves forward, so the next try waits 6 hours. That is acceptable. `testATitledStoryDoublesButWaitsSixHours` pins the wait.
- **A pre-synopsis story with `llm_title_member_count = 0`** (from #2353) is still due, once 6 hours have passed. `testAStoryTitledBeforeSynopsesIsDueAgain` is adjusted to an attempt older than 6 hours.

---

### Task 1: Re-title after doubling, at most every 6 hours

**Files:**
- Create: `backend/src/Service/Curation/Trending/Model/StoryTitleCutoffsModel.php`, a `final readonly` class with `public \DateTimeImmutable $retryBefore` and `public \DateTimeImmutable $retitleBefore`
- Modify: `backend/src/Repository/TrendingStoryRepository.php`, which becomes `findStoriesDueForTitle(int $userId, StoryTitleCutoffsModel $cutoffs, \DateTimeImmutable $seenSince): list<int>`
- Modify: `backend/src/Service/Curation/Trending/StoryTitleTask.php`. Add `RETITLE_AFTER = '-6 hours'` beside `RETRY_AFTER = '-1 hour'`, build the cutoffs, and fix the class docblock.
- Test: `backend/tests/Repository/TrendingStoryRepositoryTest.php`. Move the existing calls to the new signature.

- [ ] **Step 1:** Write these failing repository tests. In each, the current time is 2031-05-14 12:00, so the retry cutoff is 11:00 and the re-title cutoff is 06:00.
  - `testATitledStoryGrownByHalfIsNotDue`: two members, titled at count 1… no. Use three members titled at count 2, attempted at 2031-05-14 04:00. Expect `[]`.
  - `testATitledStoryDoublesButWaitsSixHours`: two members titled at count 1, attempted at 08:00. Expect `[]`.
  - `testADoubledStoryIsDueSixHoursAfterItsTitle`: two members titled at count 1, attempted at 05:00. Expect `[storyId]`.
  - `testAnUntitledStoryIsRetriedAfterAnHour`: no title, attempted at 10:30. Expect `[storyId]`.
- [ ] **Step 2:** Run `php bin/phpunit tests/Repository/TrendingStoryRepositoryTest.php`. Expected: FAIL.
- [ ] **Step 3:** Implement. The query's WHERE becomes `user AND last_seen >= :seenSince AND ((llm_title IS NULL AND (attempted IS NULL OR attempted <= :retryBefore)) OR (llm_title IS NOT NULL AND attempted <= :retitleBefore AND <member count> >= llm_title_member_count * 2))`.
- [ ] **Step 4:** Run the repository test, `tests/Service/Curation/Trending/StoryTitleTaskTest.php`, `composer stan`, `composer cs` and `composer md`. Expected: green.
- [ ] **Step 5:** Commit: `feat(#2446): a titled Trending story is re-titled after it doubles, at most every six hours`
