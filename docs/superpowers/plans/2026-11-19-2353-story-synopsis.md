# Story Title Wraps, Bold, With an LLM Synopsis — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** The title card behind a Trending story shows its whole title, wrapped and bold. When an LLM wrote the title, a 2–3 sentence synopsis shows beneath it, both on that card and at the top of the story's own list.

**Architecture:**
- **Backend:**
  - The story-title call asks for `{title, synopsis}` in one reply, and the parser returns a `StoryHeadingModel`.
  - A new `trending_story.llm_synopsis` column is written together with `llm_title`.
  - The read paths that carry a story's title carry its synopsis beside it: `titlesOf` → `headingsOf`, `titleOf` → `headingOf`. They reach the Trending card JSON and the story page JSON as `synopsis`.
  - A one-time migration makes every story that already has an LLM title due again, so the next sweep writes both.
- **Frontend:**
  - `app-story-title` wraps, turns bold, and shows the synopsis beneath the title.
  - The story's list shows the synopsis through `app-entry-list`'s existing `topBlock` slot, so it scrolls away with the rows.

**Tech Stack:** Symfony 8.1 / PHP 8.5 (Doctrine DBAL, MySQL + SQLite), Angular 20 (signals, standalone), Jest in Docker, Playwright.

**Spec:** GitHub issue #2353 (`gh issue view 2353`), a follow-up to #2329 (plan `docs/superpowers/plans/2026-11-18-2329-trending-story-titles.md`).

## Global Constraints

- Synopsis: **2–3 sentences, at most about 350 characters**. It is written in the same call, language (the user's UI language), connection and limits as the title. Without an LLM, or with the fallback title, there is no synopsis.
- It shows beneath the title on the Trending card **and** beneath the title at the top of the story's own list (the overlay).
- Stories that already have an LLM title but no synopsis count as due, so the next sweep writes both.
- The title card shows the full title: it **wraps** (no ellipsis) and is **bold**. The synopsis is regular weight.
- Sign-off happens on a **real render** (the capture script with stubbed data, desktop 1440×900 and phone 390×760) before the PR.
- CLAUDE.md rules are binding:
  - queries live only in `src/Repository`; models live in `Model/`; `*Json` mappers shape responses;
  - PHPMD stays clean on every touched `src` file; phptramp and PHPStan max pass;
  - every migration has a MySQL path and a SQLite path;
  - component styles live in sibling `.scss` files, with no hex colours and no ad-hoc px values.
- Jest runs only via `docker compose exec -T frontend …`, one at a time. Commits are `feat(#2353): …`, with no attribution. Branch `feature/2353-story-synopsis`, one PR with `Closes #2353`.

## Review Focus

1. **A reply with a good title but a missing, empty or overlong synopsis** keeps the title and stores no synopsis. It must not throw the title away or retry every hour. Pinned in Task 1 (parser cases plus `testATitleWithoutAUsableSynopsisIsKept`).
2. **A story that falls back to its fallback title** (the LLM title is cleared, or titles are switched off before a title was written) must never show an orphaned synopsis. The synopsis is read only where `llm_title` is set. Pinned in Task 2 (`testAStoryWithoutAnLlmTitleHasNoSynopsis`).
3. **The migration's catch-up** must make only the stories that have an LLM title due. Untitled stories and stories without an LLM keep their state. Pinned in Task 1 (`testAStoryTitledBeforeSynopsesIsDueAgain` against the repository, and the migrate-from-empty leg).
4. **A long German title plus synopsis on a phone** wraps inside the card without overflow, and the story card still tucks under it. Pinned in Task 3's real-render capture (phone, de, a 120-character title).
5. **Opening a story whose page has not landed yet** shows no synopsis block, rather than an empty one. Once the page lands, the block appears. Pinned in Task 3 (`story-layer` spec).

---

### Task 1: The call writes a title and a synopsis

**Files:**
- Create:
  - `backend/src/Service/Curation/Trending/Model/StoryHeadingModel.php`
  - `backend/migrations/Version20261119090000.php`
- Modify:
  - `backend/src/Entity/TrendingStory.php` (column `llm_synopsis`, `length: 500`, nullable)
  - `backend/src/Service/Curation/Trending/Support/StoryTitlePrompt.php`
  - `backend/src/Service/Curation/Trending/StoryTitleParser.php`
  - `backend/src/Service/Curation/Trending/StoryTitleTask.php`
  - `backend/src/Service/Curation/Recommendation/Llm/Prompt/Model/RecommendationResponseSchema.php` (`STORY_TITLE_SCHEMA`)
  - `backend/src/Service/Curation/Recommendation/Llm/Prompt/RecommendationAnswerBudget.php` (`STORY_TITLE_ANSWER_TOKENS`)
  - `backend/src/Repository/TrendingStoryRepository.php` (`recordTitle`, and `memberHeadlinesOf`'s excerpts)
  - `backend/src/Service/Curation/Trending/Support/HeadlineBody.php`
- Test:
  - `tests/Service/Curation/Trending/Support/HeadlineBodyTest.php` (create it if it does not exist)
  - `tests/Service/Curation/Trending/StoryTitleParserTest.php`
  - `tests/Service/Curation/Trending/StoryTitleTaskTest.php`
  - `tests/Repository/TrendingStoryRepositoryTest.php`

**Interfaces:**
- Produces:
  - `final readonly class StoryHeadingModel { public function __construct(public string $title, public ?string $synopsis) }`
  - `StoryTitleParser::parse(string $content): ?StoryHeadingModel`. Null only when the title is unusable.
  - `TrendingStoryRepository::recordTitle(int $storyId, StoryHeadingModel $heading, \DateTimeImmutable $attemptedAt): void`. It writes `llm_title` and `llm_synopsis` together.
  - The schema becomes `{title: string, synopsis: string}`, with both required and `additionalProperties: false`.
  - **The synopsis is written from the article texts, not the headlines alone (Lars, 2026-11-19).** `HeadlineBody` gains `excerpt(?string $summary): string` and `excerptAfterTitle(string $title, ?string $summary): string`. They work like `body`/`bodyAfterTitle`, but cut at **1,000** characters on a word boundary. `memberHeadlinesOf` builds its `snippet` with them; the title call is its only caller. The 300-character `body`/`bodyAfterTitle` used by Trending's scoring stay unchanged. `SOURCE_CHARACTERS` (2,000) already reads enough.

- [ ] **Step 1: Write the failing tests.**
  - `StoryTitleParserTest::testParse` data, in the shape `content → expected heading`:
    - `{"title":"Harbour barrier approved","synopsis":"The authority approved it. Work starts in May."}` → title `Harbour barrier approved`, synopsis as given.
    - Synopsis whitespace collapsed and trimmed: `"  Two\n sentences.  "` → `Two sentences.`.
    - Synopsis `""`, or missing → title kept, synopsis `null`.
    - Synopsis of 501 characters → title kept, synopsis `null` (the parser accepts at most **500**; the prompt asks for at most 350).
    - Title cases: every existing case keeps its expected title, with `synopsis: null` wherever the content has none.
  - `StoryTitleTaskTest`:
    - `testAVisibleStoryGetsATitleInTheUsersLanguage` also asserts the stored synopsis.
    - New: `testATitleWithoutAUsableSynopsisIsKept`. The reply `{"title":"T","synopsis":""}` stores `llm_title = 'T'` and `llm_synopsis = null`, and the story is not due within the hour.
    - `testTheRequestCarriesAtMostTwelveHeadlinesAndTheSchema` asserts that the schema requires `synopsis`.
  - `HeadlineBodyTest`: `testAnExcerptKeepsUpToAThousandCharactersOnAWordBoundary` (a 1,500-character body gives at most 1,000 characters, ending on a whole word); `testAnExcerptAfterTitleDropsTheRepeatedHeadline`.
  - `StoryTitleTaskTest::testTheRequestCarriesAtMostTwelveHeadlinesAndTheSchema` also asserts that a member with a 900-character body sends all 900 characters.
  - `TrendingStoryRepositoryTest`: new `testAStoryTitledBeforeSynopsesIsDueAgain`. A story with `llm_title` set, `llm_title_member_count = 0`, and an attempt more than an hour ago is in `titleDueOf`. A story with `llm_title` null and no LLM is unaffected (Review Focus 3; the migration sets that count).
- [ ] **Step 2: Run** `php bin/phpunit tests/Service/Curation/Trending tests/Repository/TrendingStoryRepositoryTest.php`. Expected: FAIL.
- [ ] **Step 3: Implement.**
  - **Prompt.** The system text's opening says the input is each article's headline and text. It adds one line: `Then write a synopsis of the whole story in %s from what the article texts report, not from the headlines alone: two or three plain sentences, at most 350 characters, neutral and factual, naming no outlet.` Its final line becomes `Reply only with JSON: {"title": "...", "synopsis": "..."}`.
  - **Token budget.** `STORY_TITLE_ANSWER_TOKENS` = **220**.
  - **Migration.** It adds `llm_synopsis VARCHAR(500) DEFAULT NULL` on both dialects, following `Version20261118130000`'s MySQL and SQLite split, and runs `UPDATE trending_story SET llm_title_member_count = 0 WHERE llm_title IS NOT NULL`. The `down()` drops the column.
- [ ] **Step 4: Run** the Step 2 command again. Expected: PASS. Then run:
  - `composer check`
  - `composer md`
  - a migrate from empty on SQLite, `bin/console doctrine:migrations:migrate -n` on the Docker MySQL, then `doctrine:schema:validate` on both.
- [ ] **Step 5: Commit** — `feat(#2353): the story-title call also writes a synopsis`

### Task 2: The synopsis reaches the card and the story page

**Files:**
- Modify:
  - `backend/src/Repository/TrendingStoryRepository.php` (`titlesOf` → `headingsOf`)
  - `backend/src/Repository/TrendingStoryEntryListRepository.php` (`titleOf` → `headingOf`)
  - `backend/src/Service/Curation/Trending/Model/RankedStoryModel.php` (`titled(?string)` → `headed(?StoryHeadingModel)`, adds `public ?string $synopsis = null`)
  - `backend/src/Service/Curation/Trending/Pass/StoryCardSource.php`
  - `backend/src/Service/Curation/Trending/TrendingStories.php`
  - `backend/src/Service/Curation/Trending/TrendingStoryEntries.php`
  - `backend/src/Service/Curation/Trending/Model/TrendingStoryEntriesModel.php` (adds `public ?string $synopsis`)
  - `backend/src/Http/TrendingJson.php`
  - `backend/src/Http/TrendingStoryPage.php`
- Test:
  - `tests/Repository/TrendingStoryRepositoryTest.php`
  - `tests/Repository/TrendingStoryEntryListRepositoryTest.php`
  - `tests/Controller/Api/TrendingControllerTest.php`
  - `tests/Controller/Api/TrendingStoryEntriesControllerTest.php`

**Interfaces:**
- Consumes: Task 1's `StoryHeadingModel` and the `llm_synopsis` column.
- Produces:
  - `headingsOf(list<int> $storyIds): array<int, StoryHeadingModel>`. A story whose stored title is null is left out, as `titlesOf` did. The synopsis is `llm_synopsis` only when `llm_title` is set, and null otherwise.
  - `headingOf(TrendingStoryListQuery): ?StoryHeadingModel`. The title keeps `TrendingStoryTitleSql::TITLE`; the synopsis follows the same rule as `headingsOf`.
  - Card JSON gains `synopsis: string|null`; the story page JSON gains `synopsis: string|null`.

- [ ] **Step 1: Write the failing tests.**
  - Repository: `testHeadingsCarryTheLlmSynopsis`; `testAStoryWithoutAnLlmTitleHasNoSynopsis`, where a fallback title plus a leftover `llm_synopsis` gives a synopsis of `null` (Review Focus 2).
  - Entry-list repository: `testTheHeadingOfAStoryWithoutStoredTitlesHasNoSynopsis`.
  - API: the Trending card returns `synopsis` for an LLM-titled story and `null` for a fallback story. `testThePageCarriesTheStorysSynopsis` sits beside `testThePageCarriesTheStorysTitle`.
- [ ] **Step 2: Run** the four test files. Expected: FAIL.
- [ ] **Step 3: Implement** the Interfaces. Delete `titlesOf` and `titleOf` rather than keeping both.
- [ ] **Step 4: Run** the four test files and `composer check`, `composer md` and `composer tramp`. Expected: PASS.
- [ ] **Step 5: Commit** — `feat(#2353): Trending cards and the story page carry the synopsis`

### Task 3: The title card wraps and is bold; the synopsis shows on the card and in the story's list

**Files:**
- Modify:
  - `frontend/src/app/reader/trending/trending.models.ts` (`TrendingStoryDto.synopsis: string | null`, `StoryPageDto.synopsis: string | null`)
  - `frontend/src/testing/entry-testing.ts` (`aStory` defaults `synopsis: null`)
  - `frontend/src/app/reader/trending/story-title/story-title.component.{html,scss}`
  - `frontend/src/app/reader/state/list-page.ts` (pick `synopsis`)
  - `frontend/src/app/reader/state/open-story.ts` (`synopsisOf(id: number): string | null`)
  - `frontend/src/app/reader/shell/story-layer/story-layer.component.{ts,html,scss}`
  - `frontend/e2e/trending-story-title.spec.ts`
- Test:
  - `story-title.component.spec.ts`
  - `frontend/src/app/reader/state/open-story.spec.ts`
  - `story-layer.component.spec.ts`

**Interfaces:**
- Consumes: Task 2's JSON `synopsis`.
- Produces:
  - `app-story-title` renders `<p class="synopsis">` beneath the `h3` when `story().synopsis` is set. The `h3` loses `nowrap`, `overflow` and `text-overflow` and gets `font-weight: 700` (or the theme's bold token if `src/app/theme` has one). The synopsis is `--fs-xs`, weight 400, `--text-secondary`. The `[attr.title]` tooltip goes, since the full text now shows. The icon aligns to the first line (`align-items: flex-start` with a small top offset).
  - In the story layer, `[topBlock]` is set to a template that renders `<p class="story-synopsis">{{ synopsis }}</p>` only when `openStory.synopsisOf(id)` is non-null. Otherwise it passes `null`.

- [ ] **Step 1: Write the failing specs.**
  - `story-title` spec:
    - `shows the synopsis beneath the title`;
    - `shows no synopsis element without one`;
    - `shows the whole title` (the `.title` has no `title` attribute; the computed `white-space` is not `nowrap`, if jsdom reports it, or else assert the class contract).
  - `open-story`: `keeps the landed page's synopsis`.
  - `story-layer`: `shows the story's synopsis above its rows once the page lands`; `renders no synopsis block before the page lands or without a synopsis` (Review Focus 5).
- [ ] **Step 2: Run** the three specs via `docker compose exec -T frontend npx jest <paths>`. Expected: FAIL.
- [ ] **Step 3: Implement** the Interfaces.
- [ ] **Step 4: Run** `docker compose exec -T frontend npm run check`. Expected: green.
- [ ] **Step 5: Extend the e2e.** In `trending-story-title.spec.ts`, the stubbed Trending story carries `synopsis`. Assert:
  - the card shows it beneath the title;
  - opening the story (stubbed page with `synopsis`) shows it at the top of the list;
  - a fallback story (`synopsis: null`) shows none.

  Run `cd frontend && npm run e2e -- trending-story-title.spec.ts`. Expected: all pass.
- [ ] **Step 6: Real render.** Capture with the scratchpad script, or a fresh one modelled on `frontend/e2e/support` stubs:
  - Trending magazine and list, desktop 1440×900 and phone 390×760;
  - one story with a 120-character German title and a 350-character synopsis, one with a short title and no synopsis;
  - the story's list open on both viewports.

  Send the PNGs to Lars for sign-off before the commit lands in a PR (Review Focus 4).
- [ ] **Step 7: Commit** — `feat(#2353): the story title wraps in bold, with its synopsis on the card and in the story's list`

### Final: before the PR
- [ ] `/simplify` in two passes (backend, then frontend).
- [ ] Both backend legs (`php bin/phpunit`, `docker compose exec php composer test:parallel`) and `composer infection:diff`.
- [ ] `npm run check`.
- [ ] The e2e set from #2329.
- [ ] PR with `Closes #2353`.
