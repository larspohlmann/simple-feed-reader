# For you reads link cards (#2238) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Every article `For you` handles (candidates, history, lines by id) carries the story its social post
shares, every engine input shows it next to the post text, and the final ranking keeps one recommendation per
shared article.

**Architecture:** A new `LinkCardRepository::storyLinksByEntryIds()` reads each entry's first root card with the
shortener landing applied (`LinkLandingSql`). The story-card rule moves from Trending's `WindowLinkCardModel` to
`Service/Content/LinkCard/Support/StoryLinkRule` so Trending and Recommendation share it. A new Pool service,
`ArticleLines`, builds `ArticleLineModel`s from `TitledEntry`s plus their cards and replaces every
`ArticleLineModel::of(...)` map. `RecommendationWinnerRanker` loads the winners' lines and drops second copies of an
article (`Run/Support/OneWinnerPerArticle`).

**Tech Stack:** PHP 8.5, Symfony 8.1, Doctrine ORM/DBAL, PHPUnit 12, MySQL 8.4 / SQLite.

**Spec:** GitHub issue #2238 (`gh issue view 2238`). It depends on #2229, #2236 and #2237, all merged.

## Global Constraints

- `CLAUDE.md` PHP Clean Code rules are mandatory: `final readonly`, names reveal intent, ≤3 parameters (constructor
  injection aside), no boolean flag parameters, no comment unless a future reader would get the code wrong.
- Commit messages: `type(#2238): lower-case imperative summary`. No Claude attribution, no Co-Authored-By.
- Never edit `tests/PhpStan`, never add a PHPStan ignore or docblock to silence a finding, never lower a threshold.
- Every touched `src` file is PHPMD-clean: `rm -rf ~/.pdepend && composer md` from `backend/`.
- The work runs in the worktree `/Users/lars/Documents/work/eigenes/sfr-2238` (real `vendor`, not a symlink). The
  Docker stack belongs to the main checkout: never `docker compose up` from here, and never write to the dev DB.
- Same article = same dedupe hash: an outlet entry's `entry.url_hash`, a post's story card
  `COALESCE(landing.resolved_url_hash, link.url_hash)`. Never re-hash in PHP.
- A card counts only when it names a story (`StoryLinkRule`), and only on a social post (feed with a network). An
  outlet entry gets no shared article.
- Token budgets stay as they are: the shared article's text comes out of the existing description budget
  (prompt 1000 characters, scoring 600), so batch sizes and `consolidationInputSize()` keep their estimates.

## Review Focus

1. **A post whose card was resolved from a shortener** must key on the landing hash, so it groups with the outlet
   entry for the article. Pinned in Task 2 (`testAResolvedShortLinkKeysOnItsLanding`).
2. **A winner pruned since its batch** has no line. The ranker must keep it ungrouped, not crash, so consolidation's
   `stillPresent()` drops it as before. Pinned in Task 5 (`testAWinnerWithoutALineStaysUngrouped`).
3. **Two copies of one post in two timelines** (same post URL, different entries) share the card hash and count as one
   article. Pinned in Task 5 (`testTwoPostsSharingOneArticleKeepTheBestRanked`).
4. **A card with an empty description, or a site name equal to the title.** The rendered line must not contain an
   empty `: ` tail, and a card named for its site is no story. Pinned in Task 4 (`testASharedArticleWithoutADescriptionEndsAtItsSite`)
   and by the moved rule tests in Task 1.
5. **A long post with a long card.** Post text plus shared article stay within the description budget. Pinned in Task 4
   (`testThePostTextGivesWayToTheSharedArticle`, both renderers).

---

### Task 1: Share the story-card rule

**Files:**
- Create: `backend/src/Service/Content/LinkCard/Support/StoryLinkRule.php`
- Modify: `backend/src/Service/Curation/Trending/Model/WindowLinkCardModel.php:30-59`
- Test: `backend/tests/Service/Content/LinkCard/Support/StoryLinkRuleTest.php`; existing `WindowLinkCardModel` tests
  stay green unchanged

**Interfaces:**
- Produces: `StoryLinkRule::namesAStory(string $url, string $title, string $siteName): bool` (static, private ctor).

- [ ] **Step 1:** Find the existing tests of `namesAStory` (`grep -rn namesAStory backend/tests`) and copy their cases
  into `StoryLinkRuleTest` against the new signature: a front page (`https://example.com/`), a title with an account
  handle (`@someone`), a title equal to the site name or the host's first label, and a real story
  (`https://example.com/2026/11/story`, `Council votes on budget`) that passes.
- [ ] **Step 2:** Run `php bin/phpunit tests/Service/Content/LinkCard/Support/StoryLinkRuleTest.php`. Expected: FAIL
  with class not found.
- [ ] **Step 3:** Move the three private rules and the two regex constants from `WindowLinkCardModel` into
  `StoryLinkRule`. The site comes from `HostKey::forUrlOrNull($url)`, the same call `ArticleSite::of()` makes.
  `WindowLinkCardModel::namesAStory()` becomes a one-line delegate.
- [ ] **Step 4:** Run the new test and `php bin/phpunit tests/Service/Curation/Trending`. Expected: PASS.
- [ ] **Step 5:** Commit `refactor(#2238): move the story-card rule to the link-card module`.

### Task 2: Read each entry's story link

**Files:**
- Create: `backend/src/Service/Content/LinkCard/Model/StoryLinkModel.php`
- Modify: `backend/src/Repository/LinkCardRepository.php`
- Test: `backend/tests/Repository/LinkCardRepositoryTest.php`

**Interfaces:**
- Consumes: `StoryLinkRule::namesAStory()` (Task 1), `LinkLandingSql::{JOIN,URL,URL_HASH}`.
- Produces: `final readonly class StoryLinkModel(string $urlHash, string $title, ?string $description, string $siteName)`
  with `summary(): string` returning `"<title> (<siteName>): <description>"`, or `"<title> (<siteName>)"` when the
  description is null or blank.
  `LinkCardRepository::storyLinksByEntryIds(list<int> $entryIds): array<int, StoryLinkModel>`: one row per entry,
  its first root card (lowest `position`, `thread_post_id IS NULL`), url and hash through the landing, left out when
  `StoryLinkRule::namesAStory()` says no. Empty input returns `[]` without a query.

- [ ] **Step 1:** Write the failing tests in `LinkCardRepositoryTest`, using its `entry()`/`link()` helpers:
  `testStoryLinksReadTheFirstRootCard` (two root cards, the first one's title comes back),
  `testStoryLinksSkipThreadCards` (only a thread-post card, entry absent),
  `testStoryLinksLeaveOutCardsThatNameNoStory` (a front-page URL, entry absent),
  `testAResolvedShortLinkKeysOnItsLanding` (set `shared_link_resolution` as `TrendingEntryRepositoryTest:225` does;
  `urlHash` is the resolved hash), `testStoryLinksForNoEntriesIsEmpty`.
- [ ] **Step 2:** Run `php bin/phpunit tests/Repository/LinkCardRepositoryTest.php`. Expected: the five new tests FAIL.
- [ ] **Step 3:** Implement with native SQL on `$this->getEntityManager()->getConnection()`, using the first-card
  subquery from `TrendingEntryRepository::chunkAfter()`, ids bound as an `ArrayParameterType::INTEGER` list.
- [ ] **Step 4:** Run the test file. Expected: PASS on SQLite. The MySQL leg runs in CI only: the Docker stack
  serves the main checkout's code, not this worktree's.
- [ ] **Step 5:** Commit `feat(#2238): read each entry's story link with its landing`.

### Task 3: Lines carry the shared article

**Files:**
- Create: `backend/src/Service/Curation/Recommendation/Pool/ArticleLines.php`
- Modify: `Pool/Model/ArticleLineModel.php`, `Pool/RecommendationCandidateLoader.php`,
  `Pool/RecommendationHistoryLoader.php` (all under `backend/src/Service/Curation/Recommendation/`), and any test
  that builds an `ArticleLineModel` by its constructor (`grep -rln "new ArticleLineModel" backend/tests`)
- Test: `backend/tests/Service/Curation/Recommendation/Pool/ArticleLinesTest.php`, plus one case each in
  `RecommendationCandidateLoaderTest` and `RecommendationHistoryLoaderTest`

**Interfaces:**
- Consumes: `LinkCardRepository::storyLinksByEntryIds()`, `StoryLinkModel` (Task 2).
- Produces:
  - `ArticleLineModel` gains `public ?StoryLinkModel $sharedArticle = null` and `public ?string $ownArticleHash = null`
    as trailing constructor parameters, and `articleKey(): ?string` (`$ownArticleHash ?? $sharedArticle?->urlHash`)
    and `isAnOutletArticle(): bool` (`$ownArticleHash !== null`).
  - `ArticleLineModel::of(TitledEntry $titled, ?StoryLinkModel $storyLink): self`: for a feed with no network,
    `ownArticleHash` is `entry->getUrlHash()` and `sharedArticle` is null; for a social feed, `ownArticleHash` is null
    and `sharedArticle` is `$storyLink`.
  - `ArticleLines::of(list<TitledEntry> $titled): list<ArticleLineModel>`, in input order, one card query per call.
    Both loaders inject it and call it wherever they mapped `ArticleLineModel::of(...)`.

- [ ] **Step 1:** Write `ArticleLinesTest` (DbTestCase): `testAPostCarriesTheStoryItsCardShares`,
  `testAnOutletArticleKeysOnItsOwnUrl` (`sharedArticle` null even with a card, `articleKey()` = entry url hash),
  `testAPostWithoutACardHasNoArticleKey`, `testLinesKeepTheirInputOrder`. Add
  `testHistoryLinesCarryTheirSharedArticle` and `testLinesForIdsCarryTheirSharedArticle` to the loader tests.
- [ ] **Step 2:** Run the three test files. Expected: FAIL.
- [ ] **Step 3:** Implement. The history loader calls `ArticleLines::of()` once per section.
- [ ] **Step 4:** Run `php bin/phpunit tests/Service/Curation/Recommendation`. Expected: PASS. `RecommendationCandidateLoaderTest`
  has a `QueryRecorder` query-count test: if it fails, raise its expected count by exactly one and say why in the
  commit body.
- [ ] **Step 5:** Commit `feat(#2238): carry the shared article on For you's lines`.

### Task 4: Show the shared article to every engine

**Files:**
- Modify: `backend/src/Service/Curation/Recommendation/Llm/Prompt/RecommendationPromptBuilder.php:387-413`,
  `backend/src/Service/Curation/Recommendation/Llm/Prompt/Support/RecommendationPromptText.php` (`BATCH_SYSTEM_ROLE`,
  `CONSOLIDATION_ROLE`, `DISTILL_ROLE`), `backend/src/Service/Curation/Recommendation/Scoring/Support/ScoringArticle.php`
- Test: `backend/tests/Service/Curation/Recommendation/Llm/Prompt/RecommendationPromptBuilderTest.php`,
  `backend/tests/Service/Curation/Recommendation/Scoring/Support/ScoringArticleTest.php` (create if absent)

**Interfaces:**
- Consumes: `ArticleLineModel::$sharedArticle`, `StoryLinkModel::summary()` (Tasks 2–3).
- Produces: no new public API. The rendered text:
  - Prompt, candidate and history lines: the current line, then ` — shares: <summary>` with the summary clipped to
    `SHARED_ARTICLE_CHARS = 500`. The post description is clipped to `DESCRIPTION_CHARS − mb_strlen(that segment)`, so
    the segment and the description together stay within 1000 characters. Without a shared article, the line is
    unchanged.
  - Role texts gain one sentence each: a line may end with `shares:` and the article the post links to, and the
    reader is choosing that article as much as the post.
  - `ScoringArticle::of()` adds `'sharedArticle' => <summary clipped to 300>` and clips `description` to
    `600 − mb_strlen(sharedArticle)`. `ScoringArticle::line()` appends ` Shares: <same text>`.

- [ ] **Step 1:** Write the failing tests. Prompt builder: `testACandidateLineShowsTheArticleItsPostShares` (asserts the
  exact line `- [7] This 👇 — Mastodon — 2026-11-10 — This 👇 — shares: Council votes on budget (Example News): The
  council approved…`), `testAHistoryLineShowsTheSharedArticle`,
  `testThePostTextGivesWayToTheSharedArticle` (1200-char description + 400-char card description: the segment after
  ` — ` is at most 1000 + separator characters, and the card title is intact),
  `testASharedArticleWithoutADescriptionEndsAtItsSite`, `testALineWithoutASharedArticleIsUnchanged`.
  ScoringArticle: the same three budget/shape cases for `of()` and `line()`.
- [ ] **Step 2:** Run both test files. Expected: FAIL.
- [ ] **Step 3:** Implement. The clipping goes in one private method per renderer. `ClippedText::of` (prompt) and
  `ClippedText::ofScrubbed` (scoring) as each file already uses.
- [ ] **Step 4:** Run `php bin/phpunit tests/Service/Curation/Recommendation`. Expected: PASS, including the
  `consolidationInputSize` tests, which this task must not change.
- [ ] **Step 5:** Commit `feat(#2238): show the shared article in every For you prompt`.

### Task 5: One recommendation per shared article

**Files:**
- Create: `backend/src/Service/Curation/Recommendation/Run/Support/OneWinnerPerArticle.php`
- Modify: `backend/src/Service/Curation/Recommendation/Run/RecommendationWinnerRanker.php`,
  `Llm/Run/RecommendationConsolidationResolver.php:57`, `Scoring/ScoringRecommendationEngine.php:55`
- Test: `backend/tests/Service/Curation/Recommendation/Run/Support/OneWinnerPerArticleTest.php`,
  `backend/tests/Service/Curation/Recommendation/Run/RecommendationWinnerRankerTest.php`

**Interfaces:**
- Consumes: `RecommendationCandidateLoader::linesForIds()`, `ArticleLineModel::articleKey()` / `isAnOutletArticle()`.
- Produces:
  - `OneWinnerPerArticle::of(list<array{id:int,score:int,reason:string}> $ranked, array<int, ArticleLineModel> $linesById): list<…>`
    (static, same shape out, order kept). A winner with no line or no key stays. Within a key: if any winner is an
    outlet article, the best-ranked outlet winner stays at its own rank and every other winner of the key drops;
    otherwise the best-ranked winner stays.
  - `RecommendationWinnerRanker::__construct(RecommendationCandidateLoader $candidateLoader)` and
    `ranked(int $userId, list<list<…>> $batchWinners): list<…>`: merge, stable sort by score, then
    `OneWinnerPerArticle::of()` over `linesForIds($userId, <the ids>)`. Both callers pass `$tick->userId()`.

- [ ] **Step 1:** Write `OneWinnerPerArticleTest` (plain TestCase, lines built by constructor):
  `testTwoPostsSharingOneArticleKeepTheBestRanked`, `testTheOutletArticleBeatsBetterRankedPosts` (post 900, outlet
  600, post 500 → only the outlet, at its place after any unrelated 700),
  `testWinnersWithoutAnArticleKeyAllStay`, `testAWinnerWithoutALineStaysUngrouped`,
  `testDifferentArticlesAllStay`. Update `RecommendationWinnerRankerTest` to the new signature: make it a DbTestCase
  or give it a candidate loader over an empty user, and keep its existing sort assertions.
- [ ] **Step 2:** Run both files. Expected: FAIL.
- [ ] **Step 3:** Implement. Two passes over `$ranked`: find each key's first outlet winner, then filter.
- [ ] **Step 4:** Run `php bin/phpunit tests/Service/Curation/Recommendation`. Expected: PASS. A consolidation or
  scoring-engine test that mocks the ranker or builds it with `new` takes the new constructor.
- [ ] **Step 5:** Commit `feat(#2238): keep one For you recommendation per shared article`.

### Task 6: Gates, measurement, PR

- [ ] **Step 1:** From `backend/`: `composer cs`, `bin/console cache:warmup && composer stan`, `composer tramp`,
  `rm -rf ~/.pdepend && composer md`, `composer test:parallel`, `composer infection:diff`. Expected: all clean; an
  escaped mutant on a changed line gets a test, not a lowered `minMsi`.
- [ ] **Step 2:** Measure on the dev data, **read-only**: a scratchpad PHP script booting the kernel with the main
  checkout's DB settings, for the user with the most social feeds. It prints the share of candidate and history
  lines that gain a shared article; prompt tokens per batch (`TokenEstimate` over `batchMessages()` for the first
  three batches) with and without the shared article; and, over the last finished run's winners, how many slots a
  second copy of one URL holds today and after `OneWinnerPerArticle`. The eyeball check of the For you top goes to Lars
  as a checklist item for after the branch reaches the main checkout.
- [ ] **Step 3:** Push `feature/2238-for-you-link-cards`, open the PR into `develop` with `Closes #2238`, the
  measurement table, and a note that the MySQL leg runs in CI only because the worktree has no Docker stack.
