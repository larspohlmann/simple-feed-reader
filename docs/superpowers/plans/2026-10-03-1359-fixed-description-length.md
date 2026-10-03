# Fixed Description Length Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Every article line in the recommendation prompts clips its description to a fixed 1,000 characters (#1359).

**Architecture:** `RecommendationPromptBuilder::descriptionLength(int $contextWindow)` scales 120-480 by the context window. It loses its reason to exist: it becomes the private constant `DESCRIPTION_CHARS = 1000`, and the `$descriptionLength` argument threaded through the section and line helpers is replaced by it. `packBatches()` and `consolidationInputSize()` keep budgeting by the real rendered line (`candidateLine()` tokens, `CANDIDATE_LINE_FRAME_CHARS`), so a small window simply packs fewer articles per batch.

**Tech Stack:** PHP 8.4, PHPUnit 12.

**Spec:** GitHub issue #1359.

## Global Constraints

- The Jev engine's 600-character clip (`Jev/Support/JevArticle.php`) is untouched.
- Clean Code per CLAUDE.md; touched files PHPMD-clean; no new comments beyond the bar.

---

### Task 1: Fixed 1,000-character description clip

**Files:**
- Modify: `backend/src/Service/Recommendation/Llm/Prompt/RecommendationPromptBuilder.php`
- Test: `backend/tests/Service/Recommendation/Llm/Prompt/RecommendationPromptBuilderTest.php`

**Interfaces:**
- Consumes: `ClippedText::of(string, int): string`.
- Produces: `descriptionLength()` is removed; no caller outside the builder and its test.

- [ ] **Step 1: Write the failing tests**

Replace `testDescriptionLengthScalesAndClamps` with a data-provided test over windows 8192 and 131072: a 1,001-character description renders as 1,000 characters plus `…`; a 999-character one renders whole. Update the two 120-character boundary tests to 1,000/1,001 (multi-byte `é` kept).

- [ ] **Step 2: Run to verify failure**

Run: `cd backend && php bin/phpunit tests/Service/Recommendation/Llm/Prompt/RecommendationPromptBuilderTest.php`
Expected: FAIL (clip is still 120 at 8k).

- [ ] **Step 3: Implement**

Delete `DESCRIPTION_MIN_CHARS`, `DESCRIPTION_MAX_CHARS`, `DESCRIPTION_WINDOW_DIVISOR` and `descriptionLength()`; add `private const int DESCRIPTION_CHARS = 1000;` and use it in `historyLine()`, `candidateLine()` and `consolidationInputSize()`; drop the `$descriptionLength` parameters.

- [ ] **Step 4: Run tests, then the gates**

Run: `composer test:parallel`, `composer check`, `composer md`, `composer infection:diff`; MySQL leg: `docker compose exec php php bin/phpunit tests/Service/Recommendation`.
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add backend/src backend/tests
git commit -m "feat(#1359): clip every prompt description to a fixed 1,000 characters"
```
