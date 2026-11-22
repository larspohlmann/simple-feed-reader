# Recommendation runs load favorites only (#2470) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** The three LLM run paths read only the favorites history section; the shared, liked, kept and viewed queries run for the profile run alone.

**Architecture:** The run paths stop carrying a `RecommendationHistoryModel` at all. `PromptContext` and `RecommendationPromptBuilder::packBatches()` take the favorites list (`list<ArticleLineModel>`) instead, and the callers fetch it with the existing `RecommendationHistoryLoader::favorites()`, the way `ScoringRecommendationEngine` already does. There is never a half-filled history model: the only model left is the full one `load()` builds for `ProfileInputsLoader` (whose `isEmpty()` and `ProfileInputFingerprint` read all five sections).

**Tech Stack:** PHP 8.5, Symfony 8.1, Doctrine, PHPUnit 12.

**Spec:** https://github.com/larspohlmann/simple-feed-reader/issues/2470

## Global Constraints

- No behaviour change for the reader: every prompt renders the same FAVORITES section as before.
- `RecommendationHistoryLoader::load()` and `RecommendationHistoryModel` keep all five sections; `ProfileInputsLoader` is untouched.
- Clean Code rules from `CLAUDE.md`; every touched `src` file PHPMD-clean; no new PHPStan ignores.

## Review Focus

- A reader with kept, viewed, shared and liked history but no favorites: run prompts still say `FAVORITES (newest first):\n- none` and nothing else from history — pinned by the existing `RecommendationPromptBuilderTest` cases once they pass favorites lists.
- The pin must prove the four queries are absent, not that some query is: each new test seeds no history and asserts an empty list of matching SQL; the red step (Task 1, Step 2) proves the matcher catches today's queries.

---

### Task 1: Run paths carry favorites, not the history model

**Files:**
- Modify: `backend/src/Service/Curation/Recommendation/Llm/Prompt/Pass/PromptContext.php`
- Modify: `backend/src/Service/Curation/Recommendation/Llm/Prompt/RecommendationPromptBuilder.php` (`packBatches`, `consolidationInputSize`, `batchUserSections`, `consolidationMessages`, `historySections`, `favoritesSection`)
- Modify: `backend/src/Service/Curation/Recommendation/Llm/LlmRecommendationEngine.php`
- Modify: `backend/src/Service/Curation/Recommendation/Llm/Run/WaveContextLoader.php`
- Modify: `backend/src/Service/Curation/Recommendation/Llm/Run/RecommendationConsolidationResolver.php`
- Create: `backend/tests/Support/RecordsHistorySectionQueries.php`
- Test: `backend/tests/Service/Curation/Recommendation/Llm/Run/WaveContextLoaderTest.php`, `.../Llm/LlmRecommendationEngineTest.php`, `.../Llm/Run/RecommendationConsolidationResolverTest.php`, `.../Llm/Prompt/RecommendationPromptBuilderTest.php`

**Interfaces:**
- Produces: `PromptContext::__construct(array $favorites, EffectiveRecommendationSettingsModel $settings, ?string $profile)` with `@param list<ArticleLineModel> $favorites`; `RecommendationPromptBuilder::packBatches(array $candidates, array $favorites, EffectiveRecommendationSettingsModel $settings): array`, `$favorites` a `list<ArticleLineModel>`.
- Test helper trait `App\Tests\Support\RecordsHistorySectionQueries` for `DbTestCase`s: `startRecordingQueries(): void` (resets `doctrine.debug_data_holder`) and `recordedHistorySectionQueries(): list<string>` — the recorded SQL matching `/(shared_at|liked_at|voted_at|viewed_at) DESC|is_kept = \?/`, the ORDER BY / WHERE fragments only the shared, liked (likes and upvotes), viewed and kept queries of `ReadingHistoryRepository` produce.

- [ ] **Step 1: Write the three failing pin tests**

Each test calls `startRecordingQueries()` right before the call under test and ends with `self::assertSame([], $this->recordedHistorySectionQueries());`.
- `WaveContextLoaderTest::testTheWaveReadsFavoritesAloneFromTheHistory` — `load()` on a one-batch plan.
- `LlmRecommendationEngineTest::testPackingReadsFavoritesAloneFromTheHistory` — `packBatches()` over two candidates.
- `RecommendationConsolidationResolverTest::testConsolidationReadsFavoritesAloneFromTheHistory` — `resolve()` with a usable reply, built like `testAUsableReplySettlesItsCallAsUsable`.

- [ ] **Step 2: Run them and watch them fail**

Run: `php bin/phpunit --filter 'ReadsFavoritesAloneFromTheHistory'`
Expected: 3 failures, each listing the shared, liked, kept and viewed statements. A test that passes here means the matcher misses a query: fix the pattern before going on.

- [ ] **Step 3: Switch `PromptContext` and `packBatches` to the favorites list**

`favoritesSection(array $favorites)` takes the list; `historySections()` passes `$history->favorites`. The three callers call `$this->historyLoader->favorites($tick->userId(), $tick->settings)`. Update the `RecommendationPromptBuilderTest` call sites to pass favorites lists (`$history->favorites` → the list directly; `emptyHistory()` → `[]` where it only fed a `PromptContext`).

- [ ] **Step 4: Run the recommendation suite**

Run: `php bin/phpunit tests/Service/Curation/Recommendation`
Expected: all green, the three new tests included.

- [ ] **Step 5: Gates**

Run: `composer check && composer md` (after `bin/console cache:warmup`), PhpStorm lint on the changed files, then `composer test:parallel` natively and `docker compose exec php composer test:parallel`.
Expected: clean and green.

- [ ] **Step 6: Commit**

```bash
git add backend/src/Service/Curation/Recommendation backend/tests
git commit -m "perf(#2470): recommendation runs load favorites alone, not the whole history"
```
