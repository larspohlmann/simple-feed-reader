# Service module groups (#2219) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Every `src/Service` module moves, intact, into one of ten group folders (`App\Service\Reader\…` → `App\Service\Content\Reader\…`); `tests/Service` mirrors it.

**Architecture:** A scripted, re-runnable move (`git mv` per module) in its own commit, then one scripted reference rewrite over code, config and live docs. The PHPStan module definition becomes "the second directory under `src/Service`" in `ServiceModules`, so every module rule follows from one change. A new `ServiceRoleRule` check rejects a class loose in a group folder or in the `Service` root.

**Tech Stack:** PHP 8.5, Symfony 8.1, PHPStan custom rules.

**Spec:** GitHub issue #2219.

## Global Constraints

- The ten groups and their modules are exactly the issue's table (59 modules, each in exactly one group).
- Groups are navigation only: no rule orders groups or forbids a dependency between them; the module graph, the three boundary prohibitions and the two sub-modules stay as they are.
- No class moves between modules; no module is split, merged or renamed.
- The commit that moves files holds no other edit (`git mv` only).
- No new baseline, ignore or loosened threshold.
- Historical documents (`docs/superpowers/plans/`, `CHANGELOG.md`) are not rewritten.

## Review Focus

- Prefix-sharing modules (`Reader`/`ReaderAudit`, `Subscription`/`SubscriptionHistory`, `Feed`/`FeedDirectory`): the rewrite must match a whole segment only. Pinned by a post-rewrite grep for `Service\(Content\)\?ReaderAudit` landing in `Content\ReaderAudit`, not `Content\Reader\Audit` or `Content\ReaderAudit` twice.
- The `Social` group holds the `Social` module: a rewrite that runs twice yields `Social\Social\Social`. Pinned by the script refusing to run when any file already contains `App\Service\<Group>\`.
- Escaped class strings (`'App\\Service\\Reader\\…'` in PHP, YAML, JSON5) and path strings (`src/Service/Reader/…`, `Service/Reading` in messages and comments) must both move. Pinned by a final grep for the old forms returning nothing.
- A class loose in a group folder (`App\Service\Content\Helper`): the new check reports it. Pinned by a fixture in Task 3.
- A loose root class still counts as its own module for the cycle rule (`LooseService` fixture stays green).

---

### Task 1: Move the files

**Files:**
- Create: `backend/bin/service-groups.php` (scratch, deleted in Task 2's commit) — the group table as `const GROUPS = ['Foundation' => ['Clock', …], …]`, shared by the move and the rewrite.
- Move: `backend/src/Service/<Module>` → `backend/src/Service/<Group>/<Module>`; same for `backend/tests/Service/<Module>`.
- Move: `backend/tests/Service/FillMissingImagesTest.php` → `backend/tests/Service/Ingestion/Ingest/FillMissingImagesTest.php` (it tests `Ingest\EntryIngestor`).

- [ ] **Step 1:** The script's `move` mode asserts that the table names every directory in `src/Service` exactly once and nothing else, then `git mv`s each module (src and tests; a module with no tests dir is skipped for tests).
- [ ] **Step 2:** Run it. Expected: `ls src/Service` prints the ten groups only; `git status` shows only renames.
- [ ] **Step 3:** Commit the renames alone: `refactor(#2219): move the service modules into their group folders`.

### Task 2: Rewrite the references

**Files:**
- Modify: every PHP file under `backend/src`, `backend/tests`, `backend/migrations`; `backend/config/**`; `backend/infection.json5`; `backend/phpstan.dist.neon`; `CLAUDE.md`; `docs/*.md` (not `docs/superpowers/`).

- [ ] **Step 1:** The script's `rewrite` mode, per file, replaces in one pass:
  - `App\Service\<Module>` followed by a non-word character, in both `\` and `\\` spellings → `App\Service\<Group>\<Module>`;
  - `Service/<Module>` followed by a non-word character (paths, comments, rule messages) → `Service/<Group>/<Module>`.
  It refuses to run when any target file already contains `App\Service\<Group>\` or `Service/<Group>/` for a group that is not also a module name (re-run guard).
- [ ] **Step 2:** Fix the fake-module fixtures by hand in `tests/PhpStan/data/service-module-cycle/*`, `service-role*.php`, `service-module-boundary-fixtures.php`, and their `*RuleTest.php`: fake modules (`Alpha`, `Beta`, `Shop`, …) move under a `Fixture` group (`App\Service\Fixture\Alpha`). `LooseService` stays loose.
- [ ] **Step 3:** `phpstan.dist.neon` `serviceSubModules` → `Curation\Recommendation\Llm`, `Curation\Recommendation\Scoring`; `ServiceModulesTest` sub-module strings follow.
- [ ] **Step 4:** `ServiceModules::of()` returns the first two segments below `App\Service` (one if only one exists), sub-modules still winning. Docblocks follow.
- [ ] **Step 5:** Verify: `grep -rE 'App\\\\+Service\\\\+(Reader|Social|…59 names)[^A-Za-z]'` over the rewritten set returns nothing outside fixtures; `bin/console cache:clear && bin/console lint:container`; `composer check`; `php bin/phpunit`. Expected: all green.
- [ ] **Step 6:** Delete the scratch script; commit `refactor(#2219): follow the service group folders in namespaces, config and rules`.

### Task 3: Reject a class loose in a group or the Service root

**Files:**
- Modify: `backend/tests/PhpStan/ServiceRoleCheck.php` (case `ModuleHome = 'moduleHome'`), a new checker `backend/tests/PhpStan/ModulePlacement.php implements ServiceRoleChecker`, `ServiceRoleRule` (register it).
- Test: `backend/tests/PhpStan/ServiceRoleRuleTest.php`, fixture `backend/tests/PhpStan/data/service-role-fixtures.php`.

- [ ] **Step 1:** Fixture classes `App\Service\Fixture\LooseInGroup` and `App\Service\LooseInRoot`; test `testAClassLooseInAGroupOrTheServiceRootIsReported` expects one `simpleFeedReader.serviceRole.moduleHome` error each, message naming `src/Service/<Group>/<Module>/` as the home.
- [ ] **Step 2:** Run `php bin/phpunit tests/PhpStan/ServiceRoleRuleTest.php`. Expected: FAIL.
- [ ] **Step 3:** `ModulePlacement::violationsIn(ServiceRoleMap $map): array` reports every service class whose namespace has fewer than two segments below `App\Service`.
- [ ] **Step 4:** Run the test and `composer stan`. Expected: PASS, and the real tree is clean.
- [ ] **Step 5:** Commit `feat(#2219): reject a service class outside a module folder`.

### Task 4: Docs

**Files:** `docs/architecture.md` §9 (module = second directory, sub-modules, loose classes rejected) and §10 (group table; "groups carry no rule"), `CLAUDE.md` Layout table and rule descriptions.

- [ ] **Step 1:** Write the sections; commit `docs(#2219): describe the service groups`.

### Task 5: Prove and gate

- [ ] **Step 1:** Break-test the cycle rule: add `use App\Service\Content\Reader\…` to a class in a module Reader already depends on so a module cycle closes; `composer stan` must report it; quote the message; restore by hand.
- [ ] **Step 2:** Break-test the boundary rule: import `App\Service\Library\Search\…` in a `Content\Reader` class; quote the failure; restore.
- [ ] **Step 3:** `composer md`, `composer tramp`, `php bin/phpunit`, `docker compose exec php composer test:parallel` (after `cache:clear` in the container), `EveryApplicationServiceBuildsTest`. Scan today's dev log.
- [ ] **Step 4:** /simplify, push, PR with `Closes #2219`, naming that #2220 (open, backend) will conflict and how to resolve it: rerun the rewrite against that branch.
