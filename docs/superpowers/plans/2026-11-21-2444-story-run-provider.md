# Story run provider and debug-log picker layout (#2444) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Trending story runs name their provider and model in the run history, and the debug log's picker row lays out on a phone.

**Architecture:** A story run records its provider host and model when `StoryAiRuns::openFor` opens it, the way For you runs (`RecommendationRunStarter::stampProvider`) and profile runs (`ProfileRun::start`) already do. On the frontend, the picker label stops shrinking and the row wraps. A story run's summary strip, which shows only a start time that the group header already shows, is no longer rendered.

**Tech Stack:** Symfony 8.1 / Doctrine / PHPUnit; Angular 20 / Jest / SCSS.

**Spec:** GitHub issue #2444, which reports two problems on Strato v1.9.5-dev.37:
- Every Trending story row in the run history reads "unknown provider".
- On a phone, the "Run to inspect" label wraps one letter per line, and a story run's summary is a bare box holding only a time.

## Global Constraints

- No migration. Existing story runs keep a null provider; only new runs are stamped.
- CLAUDE.md naming verbs apply, and touched files must be PHPMD-clean.
- No hex colours, ad-hoc px or media-query literals in .scss; tokens only.

## Review Focus

- **A story connection whose base URL has no host:** the provider host stays null and the model is still stamped. Pinned in Task 1 with `testOpeningARunStampsTheConnectionsProviderAndModel`, which uses a hostful URL; `ProviderHost::parseHost` already covers the hostless case.
- **A second call of the same pass:** it reuses the run and does not stamp it again. Pinned by the existing `testASecondCallOfThePassReusesItsRun`.

---

### Task 1: Story runs record their provider and model

**Files:**
- Modify: `backend/src/Entity/StoryAiRun.php` (add `stampProvider(?string $providerHost, ?string $model): void`, delegating to `ProviderUsage::stamp`, plus `getProviderHost(): ?string` and `getModel(): ?string`)
- Modify: `backend/src/Service/Curation/StoryAi/StoryAiRuns.php` (`openFor` stamps `ProviderHost::parseHost($pass->connection)` and `$pass->connection->getModel()` before persisting)
- Test: `backend/tests/Service/Curation/StoryAi/StoryAiRunsTest.php`

- [ ] **Step 1:** Write `testOpeningARunStampsTheConnectionsProviderAndModel`. The pass's connection has base URL `https://api.anthropic.com/v1` and model `claude-haiku-5-5`. After `openFor`, the reloaded run has `getProviderHost() === 'api.anthropic.com'` and `getModel() === 'claude-haiku-5-5'`.
- [ ] **Step 2:** Run `php bin/phpunit tests/Service/Curation/StoryAi/StoryAiRunsTest.php`. Expected: FAIL, because the getter is missing or returns null.
- [ ] **Step 3:** Implement as described in the Files list.
- [ ] **Step 4:** Run the same file, the history repository test and the debug-log controller test. Expected: PASS.
- [ ] **Step 5:** Commit: `fix(#2444): a Trending story run records its provider and model`

### Task 2: The debug-log picker row and the story summary

**Files:**
- Modify: `frontend/src/app/settings/recommendations/recommendation-debug-log.component.scss`. In `.debug-panel__runs`, add `flex-wrap: wrap`. Its label span gets `flex: none; white-space: nowrap`. The select gets `flex: 1 1 auto; min-width: 0; max-width: 100%`.
- Modify: `frontend/src/app/settings/recommendations/recommendation-debug-log.component.html`. Render the summary strip only when `r.status !== null`, and drop the now-dead start-only timeline branch.
- Test: `frontend/src/app/settings/recommendations/recommendation-debug-log.component.spec.ts`

- [ ] **Step 1:** Write the spec `shows no summary strip for a story run`. With `run.kind = 'story'` and `status = null`, `.debug-panel__summary` is absent. The For you summary specs stay as they are. Replace the existing start-only story timeline spec.
- [ ] **Step 2:** Run `./node_modules/.bin/jest src/app/settings/recommendations --testPathIgnorePatterns "/node_modules/" "/e2e/"`. Expected: FAIL.
- [ ] **Step 3:** Implement the template and SCSS changes.
- [ ] **Step 4:** Run jest, tsc, eslint, prettier and stylelint on the changed files. Expected: green.
- [ ] **Step 5:** Commit: `fix(#2444): the debug-log picker label keeps its width and a story run shows no bare summary`
