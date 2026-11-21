# Every AI model call visible (#2355) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** The run history and the debug log show every run that sent a prompt to a model: For you runs, profile distillation runs and Trending story AI runs.

**Architecture:** One backend enum `AiRunKind` names the three run tables. The history repository asks each table with the same scalar DQL shape and merges the rows newest first in PHP, with a cursor of (createdAt, kind, id) because ids collide across tables. The debug-log loader offers the retained runs of all three kinds as `DebugLogRunModel`s and reads the chosen run's rows through the existing owner-generic log queries. The frontend adds a kind filter to the history and one merged picker to the debug log.

**Tech Stack:** Symfony 8.1 / Doctrine DQL / PHPUnit, Angular 20 signals / Jest, Transloco (en, de, fr, es).

**Spec:** GitHub issue #2355, plus Lars's rulings of 2026-11-21:
- History: one row per run with a kind label, plus a kind filter (All by default). The all-time total always counts every kind.
- Debug log: one merged picker of the retained runs of all kinds, newest first, each labelled with its kind and time. The default stays the newest For you run. The profile panel keeps its own newest-profile-run log.

## Global Constraints

- Every prompt-sending call counts. Model listing and credential probes do not.
- Wire kind values are `for_you`, `profile` and `story`.
- Retention stays `RunLogRetention::RUNS = 10` per kind, so the picker offers at most 30 runs.
- A story run has no status, error, attempts or completion time. Those are `null` on the wire and are never invented.
- The new copy goes in all four locale files (`frontend/public/i18n/{en,de,fr,es}.json`), and French and Spanish are first-class: no English placeholders. The kind labels are translated. Status words stay the untranslated wire vocabulary, as today.
- CLAUDE.md naming: method verbs from the table, no `…Of` names, `final readonly`, PHPMD-clean touched files, `Response::HTTP_*`.
- No migration: nothing new is stored.

## Review Focus

- **Two runs of different kinds created in the same second, straddling a page break.** Expected: no row is lost or repeated. Pinned in Task 2 with `testPageBreakInsideOneSecondKeepsEveryRun`.
- **An old or garbage `before` cursor** (a bare integer from the old client, `abc`, an unknown kind). Expected: the first page, not a 500. Pinned in Task 1 with `testParseRejectsMalformedCursor` and in Task 3 with `testMonthWithMalformedCursorAnswersFirstPage`.
- **An account with story runs but no For you run.** Expected: the debug log defaults to the newest run of any kind, not an empty panel. Pinned in Task 4 with `testDefaultsToNewestRunOfAnyKindWithoutForYouRuns`.
- **Another user's story-run log row.** Expected: the entry endpoint answers 404, and `getOneForUser` does not widen to every row. Pinned in Task 4 with `testStoryRunEntryOfAnotherUserIs404`.
- **A filter that matches no run.** Expected: an empty months list and `latest: null`. The total still covers every kind, and the card does not vanish while the filter is set. Pinned in Task 3 with `testKindFilterWithoutRunsKeepsTotal` and in Task 6 with `keeps the card and filter when the filtered kind has no runs`.

---

### Task 1: `AiRunKind` and the history cursor

**Files:**
- Create: `backend/src/Enum/AiRunKind.php`
- Create: `backend/src/Service/Curation/Recommendation/Feed/Model/RunHistoryCursorModel.php`
- Test: `backend/tests/Enum/AiRunKindTest.php`, `backend/tests/Service/Curation/Recommendation/Feed/Model/RunHistoryCursorModelTest.php`

**Interfaces:**
- Produces:
  - `enum AiRunKind: string { ForYou = 'for_you'; Profile = 'profile'; Story = 'story'; }`, with `tieRank(): int` (ForYou 2, Profile 1, Story 0). Among runs created in the same second, the higher rank comes first.
  - `final readonly class RunHistoryCursorModel(public \DateTimeImmutable $createdAt, public AiRunKind $kind, public int $id)`
  - `RunHistoryCursorModel::parse(string $cursor): ?self` reads `"<unix seconds>-<kind>-<id>"`, as UTC naive.
  - `RunHistoryCursorModel::toCursor(): string`

- [ ] **Step 1: Write the failing tests**
  - `testTieRankOrdersForYouProfileStory`
  - `testRoundTrip`: `parse((new RunHistoryCursorModel(new \DateTimeImmutable('2026-11-20 10:00:00'), AiRunKind::Story, 123))->toCursor())` equals the same model, and `toCursor()` is `'1795168800-story-123'`.
  - `testParseRejectsMalformedCursor`: a data provider of `''`, `'42'`, `'abc'`, `'1795168800-nope-1'`, `'1795168800-story-x'` and `'1795168800-story-0'`, each giving `null`.
- [ ] **Step 2:** Run `php bin/phpunit --filter 'AiRunKindTest|RunHistoryCursorModelTest'`. Expected: FAIL, class not found.
- [ ] **Step 3:** Implement. `parse` splits on the first and last `-` (`for_you` holds no `-`), requires a numeric timestamp and an id of 1 or more, and uses `AiRunKind::tryFrom`.
- [ ] **Step 4:** Run the same filter. Expected: PASS.
- [ ] **Step 5:** Commit: `feat(#2355): a run kind and a history cursor that orders runs across tables`

### Task 2: History repository over all three run tables

**Files:**
- Modify: `backend/src/Repository/RecommendationRunHistoryRepository.php`
- Create: `backend/src/Service/Curation/Recommendation/Feed/Model/RunHistoryFilterModel.php`
- Modify: `backend/src/Entity/StoryAiRun.php` (add `getCreatedAt()`)
- Test: `backend/tests/Repository/RecommendationRunHistoryRepositoryTest.php`

**Interfaces:**
- Consumes: Task 1.
- Produces:
  - `final readonly class RunHistoryFilterModel(public ?AiRunKind $kind, public ?RunHistoryCursorModel $before)`, where a null kind means every kind, plus `static all(): self`.
  - `HistoryRow` gains `kind: AiRunKind`, and `status` becomes `?RunStatus`; null for a story run, as are its `completedAt`.
  - `pageForMonth(User $user, MonthWindowModel $window, RunHistoryFilterModel $filter): list<HistoryRow>` returns the rows newest first by (createdAt DESC, tieRank DESC, id DESC), at most `HISTORY_LIMIT + 1` of them.
  - `spendTimeline(User $user, ?AiRunKind $kind): list<array{createdAt, costNanoCredits}>`, all kinds when the kind is null.
  - `totalCostNanoCredits(User $user): ?int` sums all three tables, and is null only when no run of any kind was priced.

- [ ] **Step 1: Write the failing tests.** Seed runs with `RecommendationRunFixtures` plus `new ProfileRun(...)` and `new StoryAiRun(...)`.
  - `testPageMergesEveryKindNewestFirst`: one run of each kind at 10:00, 10:01 and 10:02 gives kinds `[Story, Profile, ForYou]` in that order.
  - `testKindFilterKeepsOneKind`
  - `testStoryRowHasNoStatusOrCompletion`: `status` null, `completedAt` null, `costNanoCredits` from its usage.
  - `testPageBreakInsideOneSecondKeepsEveryRun`: 51 story runs and 1 For you run, all at the same second. The first page has 51 rows and ForYou comes first. The second page, read with the cursor of row 50, is exactly the one remaining row.
  - `testTotalSumsEveryKind`
  - `testTotalIsNullWhenNoKindWasPriced`
  - `testSpendTimelineFiltersByKind`
- [ ] **Step 2:** Run `php bin/phpunit tests/Repository/RecommendationRunHistoryRepositoryTest.php`. Expected: FAIL.
- [ ] **Step 3:** Implement:
  - One private query builder per entity, all selecting the same aliases.
  - The cursor condition per table compares the table kind's `tieRank()` with the cursor's:
    - higher rank: `createdAt < :at`
    - equal rank: `createdAt < :at OR (createdAt = :at AND id < :id)`
    - lower rank: `createdAt <= :at`
  - Each table query caps at `LIMIT + 1`. Tag the rows with their kind, then sort and cut in PHP.
  - Story rows select `NULL`-equivalent status and completedAt. Add those keys in PHP, since DQL can't select a typed null.
  - Update the class docblock: "every run kind's reads".
- [ ] **Step 4:** Run the same file. Expected: PASS. Then run `composer stan` on the repository.
- [ ] **Step 5:** Commit: `feat(#2355): the run history reads profile and Trending story runs too`

### Task 3: History service, JSON and controller take the kind

**Files:**
- Modify: `backend/src/Service/Curation/Recommendation/Feed/RecommendationRunHistory.php`
- Modify: `backend/src/Service/Curation/Recommendation/Feed/Model/RunHistoryMonthPageModel.php` (`nextCursor: ?RunHistoryCursorModel`)
- Modify: `backend/src/Http/RecommendationRunHistoryJson.php`
- Modify: `backend/src/Controller/Api/RecommendationRunHistoryController.php`
- Test: `backend/tests/Controller/Api/RecommendationRunHistoryControllerTest.php`, `backend/tests/Http/RecommendationRunHistoryJsonTest.php`

**Interfaces:**
- Consumes: Task 2.
- Produces the wire format:
  - `GET /api/recommendations/runs/history?tz=&kind=` and `GET …/history/{month}?tz=&kind=&before=<cursor>`. An unknown or absent `kind` means all kinds; a malformed `before` means the first page.
  - The service signatures are `overview(User, ViewerTimeZoneModel, ?AiRunKind)` and `month(User, MonthWindowModel, RunHistoryFilterModel)`.
  - In the payload:
    - each row gains `kind`, and its `status` may be `null`
    - `nextCursor` becomes a string or null
    - `months` and `latest` follow the filter
    - `totalCostNanoCredits` always covers every kind

- [ ] **Step 1: Write the failing tests**
  - `testOverviewListsEveryKind`: the row kinds include `for_you`, `profile` and `story`, and the total equals the sum of all three.
  - `testKindFilterNarrowsMonthsAndRows`: with `?kind=story`, the month's `runCount` counts only story runs, and the total is still all kinds.
  - `testKindFilterWithoutRunsKeepsTotal`: `months === []`, `latest === null`, total not null.
  - `testMonthPagesWithStringCursor`: 51 runs. The first page carries a string `nextCursor`, and the second page is 1 row.
  - `testMonthWithMalformedCursorAnswersFirstPage`: `before=42` gives the first page.
  - Json test: a story row gives `status` null and `durationSeconds` null.
- [ ] **Step 2:** Run both test files. Expected: FAIL.
- [ ] **Step 3:** Implement:
  - `truncate()` builds the cursor from the last kept row.
  - The controller reads `AiRunKind::tryFrom($request->query->getString('kind'))` and `RunHistoryCursorModel::parse($request->query->getString('before'))`.
  - `readCompletedAt` returns null when the status is null.
  - Update the controller docblock: "every AI run", not "every for-you run".
- [ ] **Step 4:** Run the files. Expected: PASS.
- [ ] **Step 5:** Commit: `feat(#2355): the run history lists, filters and totals every run kind`

### Task 4: Debug log serves every run kind

**Files:**
- Create: `backend/src/Service/Curation/Recommendation/Feed/Model/DebugLogRunModel.php`, `…/Feed/Model/RunCallCountersModel.php`, `…/Feed/Model/DebugLogRunRequestModel.php`
- Modify:
  - `backend/src/Service/Curation/Recommendation/Feed/RecommendationDebugLogLoader.php`
  - `…/Feed/Model/RecommendationDebugLogModel.php`
  - `backend/src/Http/RecommendationDebugLogJson.php`
  - `backend/src/Controller/Api/RecommendationDebugLogController.php`
- Modify:
  - `backend/src/Repository/RecommendationRunLogRepository.php`: `listForStoryAiRun`, `streamingTextForStoryAiRun`, and `getOneForUser` joining `storyAiRun`
  - `backend/src/Repository/ProfileRunRepository.php`: `findNewestForUser(User, int $limit): list<ProfileRun>`
  - `backend/src/Repository/StoryAiRunRepository.php`: `findNewestForUser(User, int $limit): list<StoryAiRun>`
- Test:
  - `backend/tests/Controller/Api/RecommendationDebugLogControllerTest.php`
  - `backend/tests/Repository/RecommendationRunLogRepositoryTest.php`
  - `backend/tests/Http/RecommendationDebugLogJsonTest.php`
  - a test for each of the two new `findNewestForUser`

**Interfaces:**
- Consumes: `AiRunKind` (Task 1), `StoryAiRun::getCreatedAt()` (Task 2).
- Produces:
  - `RunCallCountersModel(int $attempts, int $maxAttempts, int $transportFailures, int $maxTransportFailures)`
  - `DebugLogRunModel(AiRunKind $kind, int $id, ?RunStatus $status, ?string $error, ?RunCallCountersModel $counters, \DateTimeImmutable $createdAt, ?\DateTimeImmutable $completedAt)`, with the named constructors `fromRecommendationRun()`, `fromProfileRun()` and `fromStoryAiRun()`. A story run gets null status, error, counters and completedAt.
  - `DebugLogRunRequestModel(AiRunKind $kind, int $id)`
  - `RecommendationDebugLogLoader::forUser(User, ?DebugLogRunRequestModel $requested): RecommendationDebugLogModel`
  - The model's `selectedRun` becomes `?DebugLogRunModel`, and `retainedRuns` becomes `list<DebugLogRunModel>` (newest first by createdAt, ties by `tieRank`).
  - On the wire:
    - `GET …/debug-log?kind=&run=`. `run` without `kind` means `for_you`, so the old client keeps working.
    - `run` is `{kind, id, status|null, error, attempts|null, maxAttempts|null, transportFailures|null, maxTransportFailures|null, createdAt, completedAt}`.
    - Each picker choice is `{kind, id, status|null, createdAt}`.

- [ ] **Step 1: Write the failing tests**
  - `testPickerListsRetainedRunsOfEveryKindNewestFirst`
  - `testDefaultsToNewestForYouRun`: even when a story run is newer.
  - `testDefaultsToNewestRunOfAnyKindWithoutForYouRuns`
  - `testRequestedStoryRunShowsItsCalls`: with `?kind=story&run=<id>`, entries have phase `story_title`, and `run.status` is null.
  - `testRequestedProfileRunShowsItsCalls`
  - `testRunWithoutKindStillMeansForYou`
  - `testStoryRunEntryIsServed`: the entry endpoint answers 200 for the user's own story-run row.
  - `testStoryRunEntryOfAnotherUserIs404`
  - Repository: `testGetOneForUserServesStoryRunRow`
  - `testListForStoryAiRun`
- [ ] **Step 2:** Run the files. Expected: FAIL.
- [ ] **Step 3:** Implement:
  - The loader fetches `RunLogRetention::RUNS` runs of each kind, maps them to `DebugLogRunModel`, merges, selects, and reads rows and streaming text by kind with a `match`.
  - The controller builds the request model from `kind` and `run`. A missing `run` is null.
  - Delete the `choice(RecommendationRun)` and `run(RecommendationRun)` mappers in favour of `DebugLogRunModel` mappers.
- [ ] **Step 4:** Run the files and `composer stan`. Expected: PASS.
- [ ] **Step 5:** Commit: `feat(#2355): the debug log picks and shows runs of every kind`

### Task 5: Frontend models, API and debug-log picker

**Files:**
- Modify: `frontend/src/app/settings/settings.models.ts`
  - Add `AiRunKind = 'for_you' | 'profile' | 'story'`.
  - `DebugLogEntry.phase` and `DebugLogDetail.phase` become `'distill' | 'batch' | 'consolidate' | 'story_title'` (drop the dead `'dedup'`).
  - The `DebugLogRunChoice` and `DebugLogRunSummary` statuses gain `'cancelled'` and become nullable, and both gain `kind`.
  - The summary counters become nullable.
- Modify: `frontend/src/app/settings/settings-api.ts`: `debugLog(run?: { kind: AiRunKind; id: number })`
- Modify: `frontend/src/app/settings/recommendations/recommendation-debug-log.component.{ts,html}`
  - `selectedRun` becomes `signal<{kind, id} | null>`.
  - The option value is `` `${kind}:${id}` ``, and its label is `time · kind label · status` (no status for a story run).
  - Picking the newest For you run clears the selection.
  - Hide the summary's attempts line when `attempts` is null.
- Modify: `frontend/src/app/settings/debug-log/debug-log-entry.component.html`: add `@case ('story_title')` with the label `debugStoryTitle`. The `@default` stays consolidate's `debugDedup`.
- Modify: `frontend/public/i18n/{en,de,fr,es}.json`:
  - `settings.ai.runKind.for_you|profile|story`: en "For you" / "Profile" / "Trending stories", de "Für dich" / "Profil" / "Trending-Stories", fr "Pour vous" / "Profil" / "Stories tendance", es "Para ti" / "Perfil" / "Historias en tendencia". Check each against the locale's existing For you and Trending wording first, and reuse that wording if it differs.
  - `settings.ai.recommendations.debugStoryTitle`: "Story title" / "Story-Titel" / "Titre de story" / "Título de historia".
- Test:
  - `recommendation-debug-log.component.spec.ts`: picker labels carry the kind; picking a story run calls `debugLog({kind:'story', id})`; picking the newest For you run clears the selection.
  - `debug-log-entry.component.spec.ts`: `story_title` renders "Story title".
  - `settings-api.spec.ts`: query `kind=story&run=7`.

- [ ] **Step 1:** Write the failing specs.
- [ ] **Step 2:** Run `./node_modules/.bin/jest --testPathIgnorePatterns "/node_modules/" "/e2e/" src/app/settings` from `frontend/` (the worktree path rule). Expected: FAIL.
- [ ] **Step 3:** Implement.
- [ ] **Step 4:** Run the same command. Expected: PASS.
- [ ] **Step 5:** Commit: `feat(#2355): the debug log picker offers runs of every kind`

### Task 6: Frontend run history kind label and filter

**Files:**
- Modify:
  - `frontend/src/app/settings/settings.models.ts`: `RunHistoryRow` gains `kind`, its status may be null, and `RunHistoryMonthPage.nextCursor` becomes `string | null`.
  - `frontend/src/app/settings/settings-api.ts`: `runHistory(tz, kind?)` and `runHistoryMonth(month, tz, kind?, before?: string)`.
- Modify: `frontend/src/app/settings/recommendations/recommendation-run-history.component.{ts,html,scss}`
  - Add a `kind` signal (`AiRunKind | null`, null meaning All).
  - Add an `app-segmented-choice` above the months with four options: All, For you, Profile and Trending stories. Changing it refetches the overview and drops every loaded month section.
  - The card stays while a filter is set, even with no months.
  - `carriedTail` becomes "prior rows whose `kind:id` is not on the fresh page". The fresh page is a prefix of the order, so that is exactly the tail.
- Modify: `frontend/src/app/settings/recommendations/recommendation-run-history-month.component.{ts,html}`
  - Track rows by `kind + id`.
  - Row 2 (the provider line) leads with the kind label.
  - A row with a null status shows `—` in the status cell, with no icon.
- Modify: `frontend/public/i18n/{en,de,fr,es}.json`
  - `settings.ai.recommendations.historyKindAll`: "All" / "Alle" / "Tous" / "Todos"
  - `historyHint`: reword from For you runs to every AI run, in all four locales.
- Test:
  - `recommendation-run-history.component.spec.ts`:
    - the filter refetches with `kind`
    - keeps the card and filter when the filtered kind has no runs
    - carried rows dedupe by `kind:id` across kinds with equal ids
  - `recommendation-run-history-month.component.spec.ts`: the kind label renders, and a null status renders `—`.

- [ ] **Step 1:** Write the failing specs.
- [ ] **Step 2:** Run jest as in Task 5. Expected: FAIL.
- [ ] **Step 3:** Implement, with the shared `segmented-choice` from `src/app/shared/` and the tokens from `docs/design-language.md`.
- [ ] **Step 4:** Run jest. Expected: PASS.
- [ ] **Step 5:** Commit: `feat(#2355): the run history labels and filters runs by kind`

### Task 7: Gates

- [ ] From `backend/`, run `composer check`, `composer md` and `php bin/phpunit`. Expected: green, and PHPMD clean on touched files.
- [ ] Run `composer infection:diff`. Expected: MSI ≥ `minMsi`.
- [ ] From `frontend/`, run `npm run check` (with `./node_modules/.bin/jest --testPathIgnorePatterns "/node_modules/" "/e2e/"` for the Jest leg). Expected: green.
- [ ] The MySQL leg, e2e, PhpStorm inspections and the real render of the history and the picker need the main checkout (the Docker stack is bound to it). List them in the PR as pending.
