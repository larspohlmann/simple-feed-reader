# Refactor the Recommendation God Module (#1162) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Close #1162 in four PRs (lettered A, B, C, E: `D1`…`D21` and `D-reconcile-1`/`-2` number the planner decisions). `Service/Recommendation` stops being one flat module of 94 classes (96 before #1182 moved two out): the LLM transport moves into `Service/Ai/Completion`, the rest splits into `Recommendation/{Prompt,Run,Feed,Settings}`, a tick and a wave each build their context once, one envelope catches every provider failure, and the last string states become enums.
- **PR A** (Tasks A0–A3, `Refs #1162`): the transport stops depending on recommendation concepts, then two pure moves.
- **PR B** (Tasks B0–B2, `Refs #1162`): `TickContext`, the phase split with one shared catch envelope, `WaveContext`. Both `@SuppressWarnings("PHPMD.ExcessiveParameterList")` go.
- **PR C** (Tasks C0–C6, `Refs #1162`): the prompt builder uses `RecommendationAnswerBudget` only, one FAVORITES section, `RecordedCall::settle()` split, `RecommendationRunLog::finish()` deleted, the reasoning flag becomes the `Reasoning` enum, and the prompt builder, request factory and call recorder take `PromptContext`, `CallPrompt` and `CallSlot` instead of four or five loose values.
- **PR E** (Tasks E0–E3, `Closes #1162`): `RunStatus`, `CallPhase` and `CallVerdict` enums; the debug-log rows stop carrying wire strings.

**Architecture:**
- **Transport (PR A).** Eighteen classes move to `App\Service\Ai\Completion`. First, A1 cuts their two ties to the recommendation module: `RetryPlan::forDriver(TickDriver)` becomes `RetryPlan::blocking()`/`deferring()` with `TickDriver::retryPlan()` choosing, and `RecommendationRunRateLimitedException` becomes `App\Service\Ai\Exception\ProviderRateLimitedException`.
- **Split (PR A).** `Prompt` (prompt building, reply parsing and their inputs), `Run` (the tick state machine, the call recorder, run lifecycle services), `Feed` (the for-you feed, the status poll, the run history and the debug log readers), `Settings`. The dependency direction is Feed → Run → Prompt → Settings, and all of them → Ai; nothing points back. `Service/Recommendation/Exception` stays where it is.
- **Moves are scripted.** `var/refactor-1162/move-classes.php` takes an old→new FQCN map, `git mv`s each file, rewrites the namespace line, rewrites every FQCN in `src`, `tests` and `config`, drops imports that became same-namespace, and adds an import for every bare name that left its old namespace (code tokens and docblock types).
- **Tick (PR B).** `RecommendationRunAdvancer` keeps the lock and the failure-to-configure ending; it builds one `TickContext(run, connection, settings, driver)` and hands it to `TickPhases`. `TickPhases` picks `SnapshotPhase` or a `ProviderPhase` (`DistillationPhase`, `BatchPhase`, `ConsolidationPhase`) and runs every provider phase inside one catch envelope: a rate limit defers, a transport failure strikes and rethrows. `BatchPhase` halves the wave concurrency on a 429 and rethrows into the same envelope.
- **Wave (PR B).** `WaveContextLoader` builds a `WaveContext(tick, batches, poolSummary, history, profile)` once per wave; `RecommendationBatchWave::resolve(WaveContext)` and its private methods take at most three parameters.
- **Enums (PR E).** `App\Enum\RunStatus`, `CallPhase`, `CallVerdict`, mapped with `enumType` on the existing `length: 16`/`24` string columns. The stored bytes are the old constant values, so no migration exists. Wire mappers write `->value`.

**Tech Stack:** PHP 8.4, Symfony 7.4 (autowiring over `src/`, `config/services.yaml` aliases), Doctrine ORM 3.6.7 (`enumType`), PHPUnit 12, PHPStan level max with the custom rules in `backend/tests/PhpStan/`, PHPMD codesize, phptramp, Infection.

**Spec:**
- GitHub issue #1162 (`gh issue view 1162`): the five concerns, the design-debt bullets and Direction 1–5.
- Carry-forward rulings: `RecordedCall::settle(string, bool $usable)` is a boolean dispatcher, split it (#1170); `RecommendationRunLog::finish()` has only test callers, converge or delete it with evidence (#1164 PR B, M5); the rate-limited exception's docblock omits the `App\` root (#1171/#1172 cosmetic, resolved by A1's rewrite).
- #1182 (`gh issue view 1182 --comments`) has landed (merge `5229c947`) and did: `RecommendationBatchSize` → `App\Enum`, `RecommendationSettingsValues` → `App\Entity`, the six `DEFAULT_*` constants → `App\Entity\RecommendationSettings`, `ForYouSweepReport::toArray()` → `Http/ForYouSweepReportJson`, `RecommendationRunReport::toArray()` → `RecommendationRunStatusJson` (which now spells the eight fields out), `NoToArrayInServicesRule`, `PersistenceKnowsNoServiceRule`, `DomainKnowsNoHttpRule` over `App\Dto` too. See "Landed" below.
- #1159 (admin settings split) has landed (merge `a124ad8a`); it touches no file in this plan.
- CLAUDE.md "PHP code style — Clean Code is mandatory" (including the "Shared values have one home" bullet); `docs/architecture.md` §6, §7 and §8.

## Status

**Status:** Reconciled against 5229c947 (2026-09-27).

| Task | State |
|---|---|
| A0: Preflight (#1159 and #1182 merged) | ⬜ |
| A1: The transport stops depending on recommendation concepts | ⬜ |
| A2: Move the completion transport into `Service/Ai/Completion` | ⬜ |
| A3: Split `Service/Recommendation` into `Prompt`, `Run`, `Feed`, `Settings` | ⬜ |
| B0: Preflight (PR A merged) | ⬜ |
| B1: `TickContext`, the phase split and one catch envelope | ⬜ |
| B2: `WaveContext` | ⬜ |
| C0: Preflight (PR B merged) | ⬜ |
| C1: The batch reply reserve is `RecommendationAnswerBudget`'s bound | ⬜ |
| C2: One FAVORITES section | ⬜ |
| C3: `RecordedCall::settle()` is split | ⬜ |
| C4: `RecommendationRunLog::finish()` goes; tests settle through the production path | ⬜ |
| C5: `Reasoning` and `PromptContext`: no reasoning flag, no five-parameter prompt methods | ⬜ |
| C6: `CallPrompt` and `CallSlot`: a recorded call begins from the request it sends | ⬜ |
| E0: Preflight (PR C merged) | ⬜ |
| E1: `RunStatus` | ⬜ |
| E2: The debug-log rows carry values, `RecommendationDebugLogJson` formats them | ⬜ |
| E3: `CallPhase` and `CallVerdict` | ⬜ |

## Scope

| Issue bullet, direction point or ruling | Task |
|---|---|
| Five concerns in one flat module; Direction 1 (client into `Service/Ai`) | A1, A2 |
| Direction 2 (`Recommendation/{Prompt,Run,Feed,Settings}`) | A3 |
| Missing per-tick context: `run`, `settings`, `userId`, `effectiveSettings` threaded; `requireUserId` + `forUser` ×4 in the advancer; `distill` 5 params, `consolidationResolver->resolve` 6 | B1 |
| Missing per-wave context: `sendRound()` 10 params, `resolve()` 6 | B1 (`resolve()` head), B2 |
| Both `@SuppressWarnings("PHPMD.ExcessiveParameterList")`; Direction 3 | B1 (advancer), B2 (batch wave) |
| Catch envelope ×3; Direction 4, first half | B1 |
| Duplicated budget math, the different formula; Direction 4, second half | C1 |
| `'FAVORITES (newest first):'` ×5 | C2 |
| `RecordedCall::settle(string, bool $usable)`; carry-forward A | C3 |
| `RecommendationRunLog::finish()` only test callers; carry-forward B | C4 |
| Ruling on D16: `bool $suppressesReasoning` in the prompt builder and the budget | C5 |
| Ruling on D16: 4–5 parameters in `batchMessages()`, `consolidationMessages()`, `consolidationInputSize()` | C5 |
| Ruling on D16: 4–5 parameters in `RecommendationCallRecorder::begin()`, `RecommendationCompletionRequestFactory::create()` | C6 |
| `RecommendationRun` status string constants | E1 |
| `RecommendationRunLog` phase and verdict string constants | E3 |
| Direction 5 (presentation through `Http/*Json`) | Audit below; #1158 and #1182 did the classes; E2 does the rest |

**Direction 5 audit (re-taken at `5229c947`):**
- `ForYouFeedResponder`, `RecommendationRunStatusPayload`, `RecommendationRunHistoryView` and `RecommendationDebugLogView` no longer exist: #1158 replaced them with `Http/RecommendationFeedJson`, `RecommendationRunStatusJson`, `RecommendationRunHistoryJson` and `RecommendationDebugLogJson`. `git grep -n "App\\\\Http\|App\\\\Dto\|HttpFoundation" -- src/Service/Recommendation src/Service/Ai` is empty.
- The two `toArray()` methods the module had (`ForYouSweepReport`, `RecommendationRunReport`) are gone: #1182 moved them into `Http/ForYouSweepReportJson` and `Http/RecommendationRunStatusJson`. `git grep -n "function toArray\|function jsonSerialize" -- src/Service` is empty; A0 re-checks it.
- One leak remains, one layer down: `RecommendationRunLogRepository::listForRun()` formats the debug panel's timestamps as ATOM strings, and `RecommendationDebugLog` carries those wire strings to the mapper. E2 moves the formatting into `RecommendationDebugLogJson`.

## Wire changes

None. Every response body, status code and header stays byte-identical. The pins:
- `RecommendationRunControllerTest` pins the run report (`status` values `none`, `busy`, `pending`, `running`, `completed`, `failed`, `cancelled`); `RecommendationRunReport::$status` stays a string (D14). Since #1182, `RecommendationRunStatusJsonTest::testItSendsTheRunReportFieldsInTheirWireOrder` and `testItSendsADistinctRunReportInTheirWireOrder` also pin the report's keys, their order and `'status' => 'failed'` from `RecommendationRunReport::fromRun()`, the line E1 changes to `->value`.
- `RecommendationDebugLogControllerTest` pins a whole list entry (every key, its order, `phase`, `verdict`, both ATOM timestamps) and the whole detail payload. E2 and E3 leave it unedited.
- `RecommendationRunHistoryJsonTest` and `RecommendationRunHistoryControllerTest` pin the history row's `status` string.
- E2 adds `RecommendationDebugLogJsonTest::testEachEntryCarriesItsTimesInAtomFormat`, E3 `testEachEntryCarriesItsPhaseAndVerdictAsWireStrings`: `json_encode` would print a forgotten enum as its value, so the mapper tests are the pins for `->value`.

Every prompt stays byte-identical through C5 and C6 (the three `…ExactRoleContentStructure` tests pin them), and so does the request body the call recorder writes to `recommendation_run_log.request_body` (`RecommendationCallRecorderTest`).

One behaviour change that is not wire: C1 makes the batch packer reserve `RecommendationAnswerBudget::answerBoundTokens()` for the reply. It differs from the old inline formula only when the batch cap is below 69 (`cap × 15 < 1024`): the reserve rises from `max(1024, ⌊cap × 22.5⌋)` to 1536, so a tight context window may pack one more batch. The default cap (100, medium) is unaffected. The PR C body lists it.

No migration. `enumType` on a `string` column changes no DDL; E1 and E3 prove it with `doctrine:schema:update --dump-sql` on MySQL, and CI's migrate-from-empty leg runs `doctrine:schema:validate` on SQLite and MySQL.

## Landed: #1159 and #1182 (checked at `5229c947`)

Drafted at `89c7e17f`, reconciled at `5229c947` (develop after #1159's merge `a124ad8a` and #1182's merges `d3196d49` and `5229c947`). Locate every edit by its **text**; line numbers are for orientation only. A0 Step 4 still re-takes the file lists on the day PR A starts.

What `git diff 89c7e17f 5229c947` changed under this plan, fact by fact:

| This plan | What landed at `5229c947` | Effect on this plan |
|---|---|---|
| A2/A3 file maps | `RecommendationBatchSize` is `src/Enum/RecommendationBatchSize.php` (test `tests/Enum/RecommendationBatchSizeTest.php`); `RecommendationSettingsValues` is `src/Entity/RecommendationSettingsValues.php` (test `tests/Entity/RecommendationSettingsValuesTest.php`); `tests/Service/Recommendation/ForYouSweepReportTest.php` is deleted (replaced by `tests/Http/ForYouSweepReportJsonTest.php`). `src/Service/Recommendation` holds 94 classes plus `Exception` (7 exceptions), `tests/Service/Recommendation` 46 test files. | The maps list exactly those 94 + 46 names (checked name by name); none of the moved-out classes is in them, so nothing #1182 moved is moved again. |
| A2/A3 FQCN rewrite | New files naming a class the maps move: `src/Http/ForYouSweepReportJson.php`, `src/Service/Maintenance/MaintenanceSweeps.php` (both `ForYouSweepReport`), `tests/Http/ForYouSweepReportJsonTest.php`, `tests/Http/MaintenanceTickJsonTest.php`. Edited imports in `RecommendationRunStatusJson`, `RecommendationSettingsJson`, `RecommendationSettingsResolver`, `RecommendationSettingsWriter`, `RecommendationPackingSettings`, `tests/Support/RecommendationRunFixtures.php` and thirteen recommendation, worker and command tests. `src/Dto/Recommendation/SaveRecommendationSettingsRequest.php` imports `RecommendationSettingsBounds`. | The script rewrites FQCNs wherever they are. No PHPStan fixture under `tests/PhpStan` names a moved class; the script now skips `tests/PhpStan` anyway and A2/A3 check it stays untouched. |
| `EffectiveRecommendationSettings` | The six `DEFAULT_*` constants are on `App\Entity\RecommendationSettings`; `FALLBACK_CONTEXT_WINDOW` and the class's `ExcessiveParameterList` suppression stay. `RecommendationRunReport` and `ForYouSweepReport` have no `toArray()`. | No task in this plan names the moved constants or `toArray()`. B0's suppression grep still names `EffectiveRecommendationSettings` (D16). |
| B1 `TickContextTest`, C5 `ReasoningTest` | `SealedSecret` is `App\Entity\SealedSecret`; `RecommendationBatchSize` is `App\Enum\RecommendationBatchSize`. `tests/Support/AiProviderSettingsFactory::build()` builds a connection with a dummy sealed key. | Both tests build their connection with `AiProviderSettingsFactory` and import the post-#1182 names. |
| Every task touching tests | Recommendation tests now import `App\Entity\RecommendationSettings`, `App\Entity\RecommendationSettingsValues` and `App\Enum\RecommendationBatchSize`, and read the defaults as `RecommendationSettings::DEFAULT_*`. `RecommendationRunAdvancerTest` is 3168 lines. | The only lines #1182 changed in those files are imports and `DEFAULT_*` reads, which no "before" block or perl pattern in B–E targets. The anchors were re-checked at `5229c947`; the counts in B0, C0 and E0 are unchanged (C0 now also names `RecordedCall`'s own private `finish()`). |
| `src/Http/RecommendationRunStatusJson.php` | Spells the run report's eight fields out, reading `$report->status`; `RecommendationRunStatusJsonTest` pins them (Wire changes). | E1's `fromRun()` edit (`->value`) keeps that string; D14 stands. |
| PR E enums, E2/E3 mappers | `PersistenceKnowsNoServiceRule` forbids `App\Service` in `App\Entity`, `App\Enum` and `App\Doctrine`; `docs/architecture.md` §8 puts an enum an entity or a repository uses in `App\Enum`, and keeps module enums (`TickDriver`, `RecommendationDriverKind`) in their owning module for now. `NoToArrayInServicesRule` rejects `toArray()`/`jsonSerialize()` in `App\Service`. | `RunStatus`, `CallPhase`, `CallVerdict` are used by `RecommendationRun`/`RecommendationRunLog` and their repositories, so `App\Enum` is right; they import nothing. `CallOutcome` (App\Entity) imports only `App\Enum`. `Reasoning` is used by no entity or repository, so it stays in `Ai/Completion` (D18). The debug-log formatting lands in `Http/RecommendationDebugLogJson` (E2, E3); no task adds a service `toArray()`. See D-reconcile-1 for `TickDriver`. |
| `DomainKnowsNoHttpRule` | Restructured (#1182 PR B) around `ClassNameReferences` and `ForbiddenReference`, with a `REMEDIES` map; its scope is `App\Pagination`, `Service`, `Repository`, `Entity`, `Enum`, `Exception`, and it now forbids `App\Dto` too. | No task edits the rule or depends on its shape. No class this plan writes in a domain namespace names `App\Http`, `App\Dto` or HttpFoundation. |
| #1159 | Proxy, Grafana and mail settings split; `config/services.yaml` gained three aliases, `services_test.yaml` a trimmed `MailCapability` comment. | None of it is in this plan's files; the `services_test.yaml` entries B1 anchors on (`RecommendationRunAdvancer`, the two loaders) are unchanged. |

Not changed by either issue: every `src/Service/Recommendation` file except `EffectiveRecommendationSettings`, `ForYouSweepReport`, `RecommendationPackingSettings`, `RecommendationRunReport`, `RecommendationSettingsResolver` and `RecommendationSettingsWriter` (no task quotes a line #1182 changed in them); `RecommendationRun`, `RecommendationRunLog`, `CallOutcome`, every `Recommendation*` repository, `RecommendationDebugLogJson`, `RecommendationRunHistoryJson`, the migrations and `backend/bin`. Doctrine ORM is still 3.6.7.

## Decisions for the planner

- **D1: four PRs, moves first.** A (moves) is mechanical and reviewed as renames; B, C and E then edit files at their final paths once. B (contexts, envelope) is the riskiest diff and stands alone. Rejected: moves last (every behaviour diff would be re-moved), one PR per direction point (Direction 5 has almost nothing left). **Recommend: as planned.**
- **D2: the transport lands in `Service/Ai/Completion`, not flat in `Service/Ai`.** `Service/Ai` has 22 files of provider configuration; 18 more would make a second flat module. **Recommend: `Ai/Completion`.**
- **D3: history, debug log and the status poll go to `Feed`.** The issue's own "Feed paging & presentation" bullet listed their former `*View`/`*Payload` classes. It also keeps the graph acyclic: `RecommendationRunStatusResolver` needs `RecommendationForYouSummaryProvider` (Feed) and `RecommendationEtaEstimator` (Run); in Run it would make Run → Feed → Run. Alternative: a fifth `Recommendation/History`. **Recommend: `Feed`.**
- **D4: `Service/Recommendation/Exception` stays one namespace**, shared by `Run` and `Feed`. The one exception the transport throws becomes `Ai\Exception\ProviderRateLimitedException`. **Recommend: as planned.**
- **D5: `RetryPlan` loses `TickDriver`.** `blocking()`/`deferring()` plus `TickDriver::retryPlan()`. Without it `Ai/Completion` would import `Recommendation/Run`. **Recommend: as planned.**
- **D6: pure-move commits change only namespaces and imports.** About 120 files move; #1182's ruling (a moved class's comments are brought to the bar) would turn A2/A3 into 120 hand edits and hide the renames. Docblocks are trimmed only in the files a behaviour task edits (the #1159 reconcile ruling). **Ruled: accepted. Trimming the comments of the moved Recommendation files belongs to #1171, the comment sweep; the planner carries it there.**
- **D7: the advancer splits into `TickPhases` + `SnapshotPhase`, `DistillationPhase`, `BatchPhase`, `ConsolidationPhase` + `InvalidReplyRetry`.** The advancer had 18 constructor parameters; `TickContext` alone removes none. Now: advancer 9, `TickPhases` 7, each phase ≤5. **Recommend: as planned.**
- **D8: the envelope is one private method, `TickPhases::advanceWithinTheEnvelope()`.** Rejected: a separate class (one more seam, no second user), a closure-taking helper (control flow through callables). **Recommend: as planned.**
- **D9: `TickContext` holds run, connection, effective settings and driver; `userId()`, `model()`, `retryPlan()` derive.** `model()` replaces `$settings->getModel() ?? ''` ×3. **Recommend: as planned.**
- **D10: `BatchPhase` loads the `WaveContext`; `RecommendationBatchWave::resolve(WaveContext)`.** The batch wave drops the two loaders (9 → 7 parameters). **Recommend: as planned.**
- **D11: C1 adopts the budget's formula**, the bound the provider is actually given, with the behaviour change above. Alternative: a budget method that reproduces the packer's old formula (keeps two formulas under one name). **Recommend: adopt.**
- **D12: `settle()` is split, callers pick `finishUsable()`/`finishUnusable()`.** Rejected: a `ParsedReply` interface on the three parse results (the bool dispatch moves, it does not go). **Recommend: split.**
- **D13: `RecommendationRunLog::finish()` is deleted.** Evidence: `git grep -n -- "->finish(" -- src` names no run-log caller; `RecordedCall` settles through `RecommendationCallRepository` (DBAL) on purpose, so it never flushes the tick's dirty EntityManager. Tests settle through that same path with `RecommendationRunFixtures::settleLog()`. **Recommend: delete.**
- **D14: enum names `RunStatus`, `CallPhase`, `CallVerdict` in `App\Enum`.** `RecommendationRunStatus` is taken by the status-poll value in `Feed`; the names match `CallOutcome`/`CallSettlement` and the `Run*` embeddables. `RecommendationRunReport::$status` stays a string: its vocabulary adds `none` and `busy`. **Recommend: as planned.**
- **D15: stale docblocks.** Seven `src` docblocks name advancer members B1 removes (`distillTick`, `effectiveCap()`, `POLL_MAX_CONCURRENCY`, …); B1 rewrites them to the new owner and trims them. Test comments that name them are left: they describe behaviour, which is unchanged. **Recommend: as planned.**
- **D16: CHANGED by ruling.** The boolean flag and the 4–5-parameter signatures are in scope (C5, C6). The two existing suppressions (`EffectiveRecommendationSettings` ExcessiveParameterList, `RecommendationRun` TooManyPublicMethods) stay out of scope; the planner opens a follow-up issue for them.
- **D18: one `Reasoning` enum in `Ai/Completion` (`Allowed`, `Suppressed`, `preferredBy(AiProviderSettings)`) replaces the flag everywhere it travels**: the budget's headroom, `consolidationInputSize()` and `CompletionRequest::$suppressReasoning`, which the transport reads. Chosen over a headroom strategy object because the value is also the wire hint; the budget keeps the numbers in one `match`. It lives in `Ai/Completion` because `CompletionRequest` carries it; `Prompt` → `Ai` is the existing direction. `StubChatClient` keeps recording `suppressReasoning` as a bool so no assertion changes. **Recommend: as planned.**
- **D19: `PromptContext(history, settings, profile)` in `Prompt`**, the three values every batch and consolidation prompt reads. `WaveContext` carries one instead of `history` and `profile`; the resolver builds one per tick. Rejected: passing `TickContext`/`WaveContext` into the prompt builder (`Prompt` would depend on `Run`, a cycle). `distillMessages()` (2) and `packBatches()` (3) keep their signatures. **Recommend: as planned.**
- **D20: `begin(RecommendationRun, CallSlot, CompletionRequest)`**: the request already carries the model and messages the row renders, so each phase builds the request once, records it and sends it. `CallSlot` replaces the phase string plus nullable batch number with `distillation()`/`batch(int)`/`consolidation()`. `create(AiProviderSettings, CallPrompt)` with `CallPrompt(messages, replyItemCount, schema)`. `TickContext::model()` then has no caller and goes. **Recommend: as planned.**
- **D21: the factory test's `settings(bool $suppressReasoning)` helper stays**: it feeds an entity setter a stored value (the #1182 D2 precedent); it does not select behaviour. **Recommend: as planned.**
- **D17: the real run is started by `POST /api/recommendations/runs` with a JWT minted by `lexik:jwt:generate-token` on the dev stack**, fallback the For You button. No password is typed. **Recommend: as planned.**
- **D-reconcile-1: `TickDriver` keeps `retryPlan()` (D5) and stays a module enum. (ACCEPTED by the planner)** `docs/architecture.md` §8 (#1182) names `TickDriver` among the module enums several `Service/*` modules share (`Service/Worker` imports it) that stay in their owning module "for now", and carries the question of a shared home to #1169. A1 gives it `retryPlan(): RetryPlan`, a `Service/Ai/Completion` type, and A3 moves it to `Recommendation/Run`, still its owning module. Were #1169 to move it to `App\Enum`, `PersistenceKnowsNoServiceRule` would reject that import, so the choice would move off the enum then (a `Run`-side `match`). Alternative now: keep `TickDriver` bare and put the choice in `TickContext::retryPlan()` (one more `match`, D5's second half undone). **Recommend: as planned; the planner adds a line to #1169's carry-forward that moving `TickDriver` to `App\Enum` takes `retryPlan()` off it.**
- **D-reconcile-2: the D16 follow-up issue also names `App\Entity\RecommendationSettingsValues`. (ACCEPTED by the planner)** #1182 moved that class, with its `@SuppressWarnings("PHPMD.ExcessiveParameterList")` (a data carrier mirroring the row), from `Service/Recommendation` to `App\Entity`. It is the same shape as `EffectiveRecommendationSettings`, whose suppression D16 already sends to the follow-up. Nothing changes in this plan. **Recommend: the planner's follow-up issue lists all three suppressions (`EffectiveRecommendationSettings`, `RecommendationSettingsValues`, `RecommendationRun`).**

## Planner rulings (drafting)

- D1–D5, D7–D15 and D17: accepted as recommended.
- D11: PR C's body lists the budget-formula change (up to 512 more reserved tokens for batch caps below 69) as a deliberate behaviour change.
- D17: minting a dev JWT on the local Docker stack is fine.
- D6: accepted. Pure-move commits change only namespaces and imports; trimming the comments in the moved Recommendation files belongs to #1171 (the comment sweep), where the planner carries it.
- D16: changed. The boolean flag (`bool $suppressesReasoning` in the prompt builder and `RecommendationAnswerBudget`) and the 4–5-parameter signatures (`RecommendationCallRecorder::begin()`, `batchMessages()`, `consolidationMessages()`, `consolidationInputSize()`, `RecommendationCompletionRequestFactory::create()`) are in scope: C5 and C6, full code, TDD where behaviour is pinned, prompts and wire byte-identical. The two existing suppressions stay out; the planner opens a follow-up issue. New decisions made for it: D18–D21.
- D18–D21: accepted as recommended (carry-forward): the `Reasoning` enum lives in `Ai/Completion`, `PromptContext` in `Prompt`, `begin()` takes the request and `TickContext::model()` goes, and the factory test's bool helper stays. PR C is C0–C6.

## Not in scope

- The two `toArray()` methods and the `RecommendationBatchSize`/`RecommendationSettingsValues`/`DEFAULT_*` moves: #1182.
- The existing `@SuppressWarnings` on `EffectiveRecommendationSettings` (ExcessiveParameterList) and `RecommendationRun` (TooManyPublicMethods): the planner's follow-up issue.
- Trimming the comments of the classes PR A moves: #1171 (D6 ruling).
- `docs/superpowers/specs/*` naming the old paths: the series' final spec refresh.

## Global Constraints

- **Paths and commands are relative to `backend/`** unless they start with `docs/`, `CLAUDE.md` or `docker compose` (repository root).
- **Wire:** none changes. The only behaviour change is C1's packing reserve.
- **Clean Code (CLAUDE.md) is mandatory.**
  - Names reveal intent. No boolean flag parameters. Three parameters at most, constructors aside.
  - Guard clauses over nesting. `final readonly class` by default.
  - Queries live in `src/Repository`. Domain code imports nothing from `App\Http`, `App\Dto` or HttpFoundation (`DomainKnowsNoHttpRule`).
  - Errors are typed exceptions. `requireId()` for persisted ids, in tests too (`EntityIdCoercionRule`).
  - DRY: a third occurrence is a refactor.
  - Shared values have one home (`docs/architecture.md` §8): an enum an entity or a repository uses goes in `App\Enum`, and `App\Entity`, `App\Enum` and `App\Doctrine` import nothing from `App\Service` (`PersistenceKnowsNoServiceRule`). Services declare no `toArray()` or `jsonSerialize()`; a `src/Http/*Json` mapper shapes the wire (`NoToArrayInServicesRule`).
- **Comments:** default none. At most three lines, only where a future reader would otherwise get the code wrong. A docblock this plan writes or edits is at most three lines; a `@param`/`@return` stays only where PHPStan needs the shape. Pure-move commits change no comment (D6).
- **Every touched `src` file is PHPMD-clean** under `composer md`. No threshold tuning, no new suppression; both `ExcessiveParameterList` suppressions go.
- **PHPStan at level max:** no new baseline entry, no `@phpstan-ignore`.
- **phptramp:** the contexts are the fix; no parameter is forwarded through 3+ methods across 2+ classes unread.
- **PSR-12, 120 columns.** Wrap a call's arguments one per line when a line would pass 120.
- **Reuse, don't hand-roll:** `NaiveUtcClock`, `PersistedId`, `Whitespace`, `ResponseHeader`, `tests/Support` (`ReloadsEntities`, `SeedsUsers`, `RecommendationRunFixtures`, `AiProviderSettingsFactory`). No new code here needs a clock read, an id cast, whitespace handling or an entity reload beyond what the listed helpers already do.
- **Gates for every task:**
  - the task's own tests,
  - `bin/console cache:warmup` after any class move or constructor change, then `composer check` (cs + stan + tramp),
  - `composer md`,
  - PhpStorm inspections on every changed PHP file (`mcp__phpstorm__lint_files`); ERROR and WARNING block.
- **Gates per PR (Finishing):**
  - `php bin/phpunit` (SQLite),
  - `docker compose exec php composer test` (MySQL; never bare `vendor/bin/phpunit`),
  - `composer check`,
  - `composer md`,
  - `composer infection:diff` (commit first: it ignores untracked files),
  - PhpStorm lint on all changed PHP,
  - the **real run** below.
- **Every new test gets a deletion check.** Break the production line it covers, watch it fail, restore the line by hand with the Edit tool (never `git checkout --`). Paste both outputs into the task report.
- **Commits:** `refactor(#1162): <lower-case summary>`, no attribution lines. The plan copy is `docs(#1162): plan — refactor the recommendation module`. Pure moves and behaviour changes never share a commit.
- **PRs A, B and C never say they end the issue.** No commit message and no PR text in them contains "close", "closes", "fix", "fixes", "resolve" or "resolves", anywhere. Their bodies say `Refs #1162`. Only PR E's body says `Closes #1162`.
- **Branches, each cut from `origin/develop` after the previous PR merged:**
  - PR A: `refactor/1162-recommendation-namespaces`
  - PR B: `refactor/1162-tick-and-wave-contexts`
  - PR C: `refactor/1162-answer-budget-and-recorded-calls`
  - PR E: `refactor/1162-run-state-enums`
- **The checkout is shared.** Run `git status --short` and `git branch --show-current` before any `switch`, `reset` or `stash`. Another session may be mid-edit.
- **`var/refactor-1162/` is scratch** (`var/` is gitignored): the scripts live there and are never committed.

### Real run (standing rule for recommendation work; every PR's Finishing runs it)

Run from the repository root after the PR's gates are green and its last commit is in place.

1. The stack serves this checkout, with current code:
```bash
bash backend/bin/e2e-preflight.sh "$(git rev-parse --show-toplevel)"
docker compose ps --format '{{.Service}} {{.State}}'
docker compose exec php bin/console cache:clear
docker compose restart worker
docker compose exec php bin/console doctrine:migrations:up-to-date
```
Expected: the preflight exits 0 silently; `php`, `worker`, `nginx`, `mysql` are `running`; `[OK] Cache for the "dev" environment (debug=true) was successfully cleared.`; `[OK] Up-to-date! No migrations to execute.` A preflight error naming another checkout: stop and report, never test the other checkout.

2. The account with a ready provider (ask Lars if more than one row comes back):
```bash
docker compose exec -T php bin/console dbal:run-sql "SELECT u.id, u.email, s.model FROM app_user u JOIN user_ai_settings s ON s.id = u.active_ai_config_id WHERE s.model IS NOT NULL"
USER_ID=<the id>; EMAIL=<the email>
docker compose exec -T php bin/console dbal:run-sql "SELECT id, status FROM recommendation_run WHERE user_id = $USER_ID AND status IN ('pending', 'running')"
```
Expected: the second query prints no row. If a run is active, let it finish (step 4) before starting one; never write SQL to end it.

3. Start a run (a dev-stack token for this account, typed nowhere; `lexik:jwt:generate-token` prints it on the line starting with eyJ; a blank line follows it):
```bash
TOKEN=$(docker compose exec -T php bin/console lexik:jwt:generate-token "$EMAIL" | grep -E '^eyJ' | tr -d '[:space:]')
curl -sk -X POST https://localhost:8443/api/recommendations/runs -H "Authorization: Bearer $TOKEN" | jq '{status, batchesTotal, batchesDone}'
```
Expected: `"status": "pending"`. If the command prints anything but a token, start the run from the For You view's "Get recommendations" button on `http://localhost:4200` instead.

4. Poll until the run leaves `pending`/`running`. Foreground `sleep` is blocked in this harness: run the loop with the Monitor tool (or `run_in_background`):
```bash
RUN_ID=$(docker compose exec -T php bin/console dbal:run-sql "SELECT MAX(id) AS id FROM recommendation_run WHERE user_id = $USER_ID" | grep -Eo '[0-9]+' | tail -n 1)
until docker compose exec -T php bin/console dbal:run-sql "SELECT status FROM recommendation_run WHERE id = $RUN_ID" | grep -qE 'completed|failed|cancelled'; do sleep 20; done
```

5. The run completed, every batch banked, no transport failure:
```bash
docker compose exec -T php bin/console dbal:run-sql "SELECT status, batches_done, JSON_LENGTH(candidate_batches) + 2 AS batches_total, transport_failures, attempts, error FROM recommendation_run WHERE id = $RUN_ID"
```
Expected: `completed`, `batches_done` equal to `batches_total` (the batch count plus distill and consolidate), `transport_failures` 0, `error` NULL. Anything else fails the PR: report it with steps 6 and 7.

6. The per-call story:
```bash
docker compose exec -T php bin/console dbal:run-sql "SELECT phase, batch_number, attempt, verdict, wire_bytes, finish_reason, error_detail, TIMESTAMPDIFF(SECOND, created_at, finished_at) AS seconds FROM recommendation_run_log WHERE run_id = $RUN_ID ORDER BY id"
```
Expected: one `distill` row, one `batch` row per batch, one `consolidate` row, every verdict `usable`, every attempt 1. A retry (`attempt` > 1, `unusable`, `transport-failed`) is a warning to report even though the run completed.

7. The dev log since the run started holds no new warning or error:
```bash
ls -t backend/var/log/dev-*.log | head -n 1 | xargs tail -n 400 | jq -c 'select(.level >= 300) | {datetime, channel, message}'
```
Expected: nothing dated after the run's `created_at`.

Paste the output of steps 3, 5, 6 and 7 into the PR's Finishing report.

---

# PR A — the transport leaves, the module splits (`Refs #1162`)

### Task A0: Preflight (#1159 and #1182 merged)

**Files:** none changed, except the plan copy.

- [ ] **Step 1: Confirm #1159 and #1182 have landed with the moves this plan builds on**

Run:
```bash
for n in 1159 1182; do gh issue view $n --json state --jq .state; done
git fetch origin
git ls-tree --name-only origin/develop -- src/Enum/RecommendationBatchSize.php src/Entity/RecommendationSettingsValues.php src/Entity/SealedSecret.php src/Http/ForYouSweepReportJson.php tests/PhpStan/NoToArrayInServicesRule.php tests/PhpStan/PersistenceKnowsNoServiceRule.php
git ls-tree --name-only origin/develop -- src/Service/Recommendation/RecommendationBatchSize.php src/Service/Recommendation/RecommendationSettingsValues.php tests/Service/Recommendation/ForYouSweepReportTest.php tests/Service/Recommendation/RecommendationBatchSizeTest.php
git grep -n "function toArray" origin/develop -- src/Service/Recommendation
```
Expected:
- `CLOSED` twice.
- The first `ls-tree` prints all six paths.
- The second `ls-tree` and the `git grep` print nothing.

If an issue is open or a line differs, stop and report: the file maps below assume #1182's moves.

- [ ] **Step 2: Cut the branch**

```bash
git status --short && git branch --show-current
git switch -c refactor/1162-recommendation-namespaces origin/develop
```

- [ ] **Step 3: Commit the plan (from the repository root)**

```bash
cp /private/tmp/claude-501/-Users-lars-Documents-work-eigenes-simple-feed-reader/d391b4d3-0e6b-4c11-9d20-34b69ad80840/scratchpad/plans/1162-draft.md docs/superpowers/plans/2026-09-27-1162-recommendation-module.md
git add docs/superpowers/plans/2026-09-27-1162-recommendation-module.md
git commit -m "docs(#1162): plan — refactor the recommendation module"
```

- [ ] **Step 4: Re-take the file lists the two moves act on**

Run:
```bash
ls src/Service/Recommendation | sort | tr '\n' ' '; echo
ls tests/Service/Recommendation | sort | tr '\n' ' '; echo
ls src/Service/Recommendation | grep -c '\.php$'
ls tests/Service/Recommendation | grep -c '\.php$'
```
Expected: 94 `src` classes plus `Exception`, and 46 test files, exactly the names in the A2 and A3 maps (A1 adds `TickDriverTest.php`, for 47). This held name for name at `5229c947`; a difference means develop moved on since. A file the maps do not name: put it in the group whose rule fits (transport → `Ai/Completion`; prompt text, prompt inputs, reply parsing → `Prompt`; tick, run lifecycle, call recording → `Run`; for-you feed, status poll, history, debug log → `Feed`; settings → `Settings`), add it to the map, and note it in the task report. A name the maps list that is missing: drop it from the map and note it.

---

### Task A1: The transport stops depending on recommendation concepts

Two behaviour-preserving refactors, one commit each, before anything moves. Without them `Ai/Completion` would import `TickDriver` (through `RetryPlan::forDriver()`) and `RecommendationRunRateLimitedException` (through `RateLimitedCompletion::complete()`).

**Files:**
- Modify: `src/Service/Recommendation/RetryPlan.php` (rewritten)
- Modify: `src/Service/Recommendation/TickDriver.php` (rewritten)
- Modify: `src/Service/Recommendation/RecommendationRunAdvancer.php` (one line in A1a; the exception rename in A1b)
- Move: `src/Service/Recommendation/Exception/RecommendationRunRateLimitedException.php` → `src/Service/Ai/Exception/ProviderRateLimitedException.php` (rewritten)
- Modify (A1b, perl): `src/Service/Recommendation/RateLimitedCompletion.php`, `RecommendationBatchWave.php`, `RecommendationRunDeferral.php`, `RecommendationRunAdvancer.php`
- Test: `tests/Service/Recommendation/RetryPlanTest.php` (rewritten), `tests/Service/Recommendation/TickDriverTest.php` (new)
- Test (perl): `tests/Service/Recommendation/RateLimitedCompletionTest.php`, `RecommendationProfileDistillerTest.php`, `RecommendationConsolidationResolverTest.php`, `RecommendationRunAdvancerTest.php` (a comment)

**Interfaces:**
- Produces: `RetryPlan::blocking(): RetryPlan`, `RetryPlan::deferring(): RetryPlan`; `RetryPlan::forDriver()` is gone. `TickDriver::retryPlan(): RetryPlan` (blocking for `Worker`, deferring for `Poll` and `Sweep`). `final class App\Service\Ai\Exception\ProviderRateLimitedException extends \RuntimeException { public function __construct(float $waitSeconds); public function waitSeconds(): float }`, same message as before.

- [ ] **Step 1: Write the failing tests**

`tests/Service/Recommendation/RetryPlanTest.php` (rewritten in full):
```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Recommendation;

use App\Service\Recommendation\RetryPlan;
use PHPUnit\Framework\TestCase;

final class RetryPlanTest extends TestCase
{
    public function testTheBlockingPlanBlocks(): void
    {
        self::assertTrue(RetryPlan::blocking()->blocks());
    }

    public function testTheDeferringPlanDefers(): void
    {
        self::assertFalse(RetryPlan::deferring()->blocks());
    }

    public function testTheBackoffScheduleIsOneTwoFour(): void
    {
        $plan = RetryPlan::blocking();

        self::assertSame(1.0, $plan->waitSecondsFor(0, null));
        self::assertSame(2.0, $plan->waitSecondsFor(1, null));
        self::assertSame(4.0, $plan->waitSecondsFor(2, null));
    }

    public function testRetryAfterOverridesTheBackoffStep(): void
    {
        self::assertSame(30.0, RetryPlan::blocking()->waitSecondsFor(0, 30));
    }

    public function testItAllowsThreeRetriesWithinATwoMinuteBudget(): void
    {
        $plan = RetryPlan::blocking();

        self::assertSame(3, $plan->maxRetries());
        self::assertSame(120.0, $plan->budgetSeconds());
    }
}
```

`tests/Service/Recommendation/TickDriverTest.php`:
```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Recommendation;

use App\Service\Recommendation\TickDriver;
use PHPUnit\Framework\TestCase;

final class TickDriverTest extends TestCase
{
    public function testOnlyTheWorkerWaitsOutARateLimit(): void
    {
        self::assertTrue(TickDriver::Worker->retryPlan()->blocks());
        self::assertFalse(TickDriver::Poll->retryPlan()->blocks());
        self::assertFalse(TickDriver::Sweep->retryPlan()->blocks());
    }
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php bin/phpunit tests/Service/Recommendation/RetryPlanTest.php tests/Service/Recommendation/TickDriverTest.php`
Expected: FAIL: `Call to undefined method App\Service\Recommendation\RetryPlan::blocking()` and `Call to undefined method App\Service\Recommendation\TickDriver::retryPlan()`.

- [ ] **Step 3: Implement**

`src/Service/Recommendation/RetryPlan.php` (rewritten in full):
```php
<?php

declare(strict_types=1);

namespace App\Service\Recommendation;

/**
 * How a caller handles a provider rate limit (#947): a blocking plan waits and retries within a budget,
 * a deferring plan hands the wait back for its caller to record.
 */
final readonly class RetryPlan
{
    /** Waits before the 1st, 2nd, 3rd retry when the provider gives no Retry-After. */
    private const array BACKOFF_SECONDS = [1.0, 2.0, 4.0];

    private const int MAX_RETRIES = 3;

    /** Stays under Strato's 240 s cgi-fcgi cap with room for the call itself. */
    private const float BLOCKING_BUDGET_SECONDS = 120.0;

    private function __construct(private bool $blocks)
    {
    }

    public static function blocking(): self
    {
        return new self(true);
    }

    public static function deferring(): self
    {
        return new self(false);
    }

    public function blocks(): bool
    {
        return $this->blocks;
    }

    public function maxRetries(): int
    {
        return self::MAX_RETRIES;
    }

    public function budgetSeconds(): float
    {
        return self::BLOCKING_BUDGET_SECONDS;
    }

    public function waitSecondsFor(int $retryIndex, ?int $retryAfterSeconds): float
    {
        if (null !== $retryAfterSeconds) {
            return (float) $retryAfterSeconds;
        }

        return self::BACKOFF_SECONDS[$retryIndex] ?? self::BACKOFF_SECONDS[array_key_last(self::BACKOFF_SECONDS)];
    }
}
```

`src/Service/Recommendation/TickDriver.php` (rewritten in full):
```php
<?php

declare(strict_types=1);

namespace App\Service\Recommendation;

/**
 * Which driver ticks the run (#344). Only the worker owns its process; poll and sweep (the maintenance cron's
 * HTTP call) run inside a bounded web request, so they clamp their wave and never wait out a rate limit.
 */
enum TickDriver
{
    case Worker;
    case Poll;
    case Sweep;

    public function retryPlan(): RetryPlan
    {
        return self::Worker === $this ? RetryPlan::blocking() : RetryPlan::deferring();
    }
}
```

The callers:
```bash
perl -pi -e 's/\$plan = RetryPlan::forDriver\(\$driver\);/\$plan = \$driver->retryPlan();/' src/Service/Recommendation/RecommendationRunAdvancer.php
perl -pi -e 's/RetryPlan::forDriver\(TickDriver::Worker\)/RetryPlan::blocking()/g; s/RetryPlan::forDriver\(TickDriver::(?:Poll|Sweep)\)/RetryPlan::deferring()/g; $_ = "" if /^use App\\Service\\Recommendation\\TickDriver;$/' tests/Service/Recommendation/RateLimitedCompletionTest.php tests/Service/Recommendation/RecommendationProfileDistillerTest.php tests/Service/Recommendation/RecommendationConsolidationResolverTest.php
git grep -n "forDriver\|TickDriver" -- src/Service/Recommendation/RetryPlan.php tests/Service/Recommendation/RateLimitedCompletionTest.php tests/Service/Recommendation/RecommendationProfileDistillerTest.php tests/Service/Recommendation/RecommendationConsolidationResolverTest.php
```
Expected: the last command prints nothing. (`RateLimitedCompletionTest` had nine `forDriver` calls, the distiller and resolver tests one each in `plan()`; `TickDriver` was used nowhere else in those three files.)

- [ ] **Step 4: Run the tests to verify they pass**

Run: `php bin/phpunit tests/Service/Recommendation`
Expected: PASS.

- [ ] **Step 5: Deletion check**

In `TickDriver::retryPlan()`, return `RetryPlan::blocking()` unconditionally. Run `php bin/phpunit tests/Service/Recommendation/TickDriverTest.php`. Expected: FAIL on `TickDriver::Poll`. Restore by hand.

- [ ] **Step 6: Gates and commit (A1a)**

Run `composer check` and `composer md`, then PhpStorm `lint_files` on the six changed files. Expected: clean.
```bash
git add src/Service/Recommendation/RetryPlan.php src/Service/Recommendation/TickDriver.php src/Service/Recommendation/RecommendationRunAdvancer.php tests/Service/Recommendation/RetryPlanTest.php tests/Service/Recommendation/TickDriverTest.php tests/Service/Recommendation/RateLimitedCompletionTest.php tests/Service/Recommendation/RecommendationProfileDistillerTest.php tests/Service/Recommendation/RecommendationConsolidationResolverTest.php
git commit -m "refactor(#1162): the tick driver chooses the retry plan, which no longer knows drivers"
```

- [ ] **Step 7: The rate-limit exception becomes the transport's own (A1b)**

```bash
git mv src/Service/Recommendation/Exception/RecommendationRunRateLimitedException.php src/Service/Ai/Exception/ProviderRateLimitedException.php
```
`src/Service/Ai/Exception/ProviderRateLimitedException.php` (rewritten in full):
```php
<?php

declare(strict_types=1);

namespace App\Service\Ai\Exception;

/**
 * A rate-limited provider call its caller will not wait out: it defers for waitSeconds() instead (#947).
 * Named apart from App\Service\RateLimit\Exception\RateLimitedException, our own limiter's refusal.
 */
final class ProviderRateLimitedException extends \RuntimeException
{
    public function __construct(private readonly float $waitSeconds)
    {
        parent::__construct(sprintf('Provider rate limited; deferring for %.0f s.', $waitSeconds));
    }

    public function waitSeconds(): float
    {
        return $this->waitSeconds;
    }
}
```
Then:
```bash
perl -pi -e 's/App\\Service\\Recommendation\\Exception\\RecommendationRunRateLimitedException/App\\Service\\Ai\\Exception\\ProviderRateLimitedException/g; s/\bRecommendationRunRateLimitedException\b/ProviderRateLimitedException/g' src/Service/Recommendation/RateLimitedCompletion.php src/Service/Recommendation/RecommendationBatchWave.php src/Service/Recommendation/RecommendationRunDeferral.php src/Service/Recommendation/RecommendationRunAdvancer.php tests/Service/Recommendation/RateLimitedCompletionTest.php tests/Service/Recommendation/RecommendationRunAdvancerTest.php
git grep -n "RecommendationRunRateLimitedException" -- src tests config
git grep -n "App\\\\Service\\\\Recommendation" -- src/Service/Recommendation/RateLimitedCompletion.php
```
Expected: both greps print nothing. The import line keeps its position in each file (PSR-12 does not order imports).

- [ ] **Step 8: Tests, gates, commit (A1b)**

Run: `php bin/phpunit tests/Service/Recommendation tests/Service/Worker`
Expected: PASS (`RateLimitedCompletionTest::expectException(ProviderRateLimitedException::class)` and every advancer 429 test).

Run `composer check`, `composer md`, PhpStorm lint on the seven changed files. Expected: clean.
```bash
git add src/Service/Ai/Exception/ProviderRateLimitedException.php src/Service/Recommendation/Exception src/Service/Recommendation/RateLimitedCompletion.php src/Service/Recommendation/RecommendationBatchWave.php src/Service/Recommendation/RecommendationRunDeferral.php src/Service/Recommendation/RecommendationRunAdvancer.php tests/Service/Recommendation/RateLimitedCompletionTest.php tests/Service/Recommendation/RecommendationRunAdvancerTest.php
git commit -m "refactor(#1162): a deferred provider rate limit is the transport's ProviderRateLimitedException"
```

---

### Task A2: Move the completion transport into `Service/Ai/Completion`

A pure move: `git mv`, namespace lines, FQCNs, imports. No other line changes. A script rather than `git mv` plus a namespace `sed`: classes that shared one namespace used each other's names bare, and once they part ways each such name needs an import that a `sed` cannot know to add.

**Files:**
- Create (not committed): `var/refactor-1162/move-classes.php`, `var/refactor-1162/ai-completion-map.php`
- Move (18): `src/Service/Recommendation/{ChatCompletionClient, CompletionBodyDecoder, CompletionCallSlot, CompletionOutcome, CompletionRequest, CompletionStreamHeartbeat, CompletionStreamObserver, CompletionStreamProgress, CompletionStreamReader, CompletionUsage, CompositeCompletionStreamHeartbeat, ConcurrentCompletion, JsonSchema, NullCompletionStreamObserver, OpenAiCompatibleChatClient, RateLimitedCompletion, RateLimitedResult, RetryPlan}.php` → `src/Service/Ai/Completion/`
- Move (9): `tests/Service/Recommendation/{CompletionBodyDecoderTest, CompletionOutcomeTest, CompletionStreamHeartbeatWiringTest, CompletionStreamReaderTest, CompletionUsageTest, CompositeCompletionStreamHeartbeatTest, OpenAiCompatibleChatClientTest, RateLimitedCompletionTest, RetryPlanTest}.php` → `tests/Service/Ai/Completion/`
- Modify (by the script, names and imports only): every `src`, `tests` and `config` file naming a moved class, e.g. `config/services.yaml`, `config/services_test.yaml`, `src/Repository/RecommendationCallRepository.php`, `src/Service/Worker/SweepStreamHeartbeat.php`, `tests/Support/StubChatClient.php`, and the recommendation classes that used the moved names bare.

**Interfaces:**
- Produces: the 18 classes as `App\Service\Ai\Completion\<Name>`, code unchanged. No class in `src/Service/Ai` names `App\Service\Recommendation`.

- [ ] **Step 1: Write the move script**

`var/refactor-1162/move-classes.php`:
```php
<?php

declare(strict_types=1);

// php var/refactor-1162/move-classes.php var/refactor-1162/<map>.php
// The map returns array<string, string>: old FQCN => new FQCN, for src and test classes alike.

const TYPE_TAGS = '@(?:phpstan-|psalm-)?(?:param|return|var|throws|extends|implements|use|mixin'
    . '|template(?:-covariant|-contravariant)?|assert(?:-if-true|-if-false)?|property(?:-read|-write)?'
    . '|method|self-out|this-out|require-extends|require-implements)\b';

function pathOf(string $class): string
{
    $relative = str_starts_with($class, 'App\\Tests\\')
        ? 'tests/' . substr($class, strlen('App\\Tests\\'))
        : 'src/' . substr($class, strlen('App\\'));

    return str_replace('\\', '/', $relative) . '.php';
}

function namespaceOf(string $class): string
{
    $separator = strrpos($class, '\\');

    return false === $separator ? '' : substr($class, 0, $separator);
}

function shortNameOf(string $class): string
{
    $separator = strrpos($class, '\\');

    return false === $separator ? $class : substr($class, $separator + 1);
}

function run(string $command): void
{
    passthru($command, $status);
    if (0 !== $status) {
        fwrite(STDERR, "Failed: {$command}\n");
        exit(1);
    }
}

/** @return list<string> tracked files, never a PHPStan rule or fixture: their class names are test data */
function trackedFiles(string ...$pathspecs): array
{
    $output = (string) shell_exec('git ls-files -- ' . implode(' ', array_map('escapeshellarg', $pathspecs)));

    return array_values(array_filter(
        explode("\n", $output),
        static fn (string $file): bool => '' !== $file && !str_starts_with($file, 'tests/PhpStan/'),
    ));
}

/** @return list<string> */
function phpFiles(): array
{
    return array_values(array_filter(
        trackedFiles('src', 'tests'),
        static fn (string $file): bool => str_ends_with($file, '.php'),
    ));
}

function declaredNamespace(string $code): string
{
    return 1 === preg_match('/^namespace ([^;]+);$/m', $code, $match) ? $match[1] : '';
}

/** @return array<string, true> the short names a file imports, aliases included */
function importedNames(string $code): array
{
    preg_match_all('/^use (?!function |const )([\w\\\\]+)(?: as (\w+))?;$/m', $code, $matches, PREG_SET_ORDER);
    $names = [];
    foreach ($matches as $match) {
        $names[$match[2] ?? shortNameOf($match[1])] = true;
    }

    return $names;
}

/** @param list<mixed> $tokens */
function significantNeighbour(array $tokens, int $index, int $step): mixed
{
    for ($position = $index + $step; isset($tokens[$position]); $position += $step) {
        $token = $tokens[$position];
        if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }

        return $token;
    }

    return null;
}

/** @param list<mixed> $tokens */
function isClassPosition(array $tokens, int $index): bool
{
    $previous = significantNeighbour($tokens, $index, -1);
    $next = significantNeighbour($tokens, $index, 1);
    $previousId = is_array($previous) ? $previous[0] : $previous;
    $memberOrDeclaration = [
        T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_CONST,
        T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM, T_NAMESPACE, T_GOTO,
    ];
    if (in_array($previousId, $memberOrDeclaration, true)) {
        return false;
    }
    if (T_CASE === $previousId) {
        return is_array($next) && T_DOUBLE_COLON === $next[0];
    }

    return ':' !== $next;
}

function typeExpressionAfter(string $text, int $offset): string
{
    $length = strlen($text);
    while ($offset < $length && ctype_space($text[$offset])) {
        ++$offset;
    }
    $depth = 0;
    $type = '';
    for (; $offset < $length; ++$offset) {
        $char = $text[$offset];
        if (0 === $depth && ctype_space($char)) {
            if (!str_ends_with($type, ':')) {
                break;
            }

            continue;
        }
        $depth += match ($char) {
            '<', '{', '(', '[' => 1,
            '>', '}', ')', ']' => -1,
            default => 0,
        };
        $type .= $char;
    }

    return $type;
}

/** @return list<string> */
function identifiersIn(string $type): array
{
    preg_match_all('/(?<![\w\\\\$-])[A-Za-z_]\w*(?![\w\\\\-])(?!\s*\??:(?!:))/', $type, $matches);

    return $matches[0];
}

/** @return list<string> class names a docblock uses as a type, an imported type's source, or a @see target */
function docblockNames(string $docblock): array
{
    $text = (string) preg_replace(['~^\s*/\*\*~', '~\*/\s*$~', '~^\s*\*~m'], ' ', $docblock);
    $names = [];
    preg_match_all('/' . TYPE_TAGS . '/', $text, $tags, PREG_OFFSET_CAPTURE);
    foreach ($tags[0] as [$tag, $offset]) {
        array_push($names, ...identifiersIn(typeExpressionAfter($text, $offset + strlen($tag))));
    }
    preg_match_all('/@(?:phpstan-|psalm-)?import-type\s+\w+\s+from\s+([A-Za-z_]\w*)(?![\w\\\\])/', $text, $imports);
    preg_match_all('/(?:\{@see|@see|@uses)\s+([A-Za-z_]\w*)(?![\w\\\\])/', $text, $sees);

    return [...$names, ...$imports[1], ...$sees[1]];
}

/** @return array<string, true> bare names the code and its docblock types use as class names */
function referencedNames(string $code): array
{
    $tokens = token_get_all($code);
    $names = [];
    foreach ($tokens as $index => $token) {
        if (!is_array($token)) {
            continue;
        }
        if (T_DOC_COMMENT === $token[0]) {
            foreach (docblockNames($token[1]) as $name) {
                $names[$name] = true;
            }

            continue;
        }
        if (T_STRING === $token[0] && isClassPosition($tokens, $index)) {
            $names[$token[1]] = true;
        }
    }

    return $names;
}

function removeImport(string $code, string $class): string
{
    $trimmed = str_replace("use {$class};\n", '', $code);
    if ($trimmed === $code) {
        return $code;
    }

    return (string) preg_replace_callback(
        '/^(namespace [^;]+;\n)\n\n+/m',
        static fn (array $match): string => $match[1] . "\n",
        $trimmed,
    );
}

function addImport(string $code, string $class): string
{
    $line = "use {$class};\n";
    if (str_contains($code, "\n{$line}")) {
        return $code;
    }
    preg_match_all(
        '/^use (?!function |const )([\w\\\\]+)(?: as \w+)?;\n/m',
        $code,
        $imports,
        PREG_SET_ORDER | PREG_OFFSET_CAPTURE,
    );
    if ([] === $imports) {
        $namespaceEnd = (int) strpos($code, ";\n", (int) strpos($code, "\nnamespace ")) + 2;

        return substr_replace($code, "\n" . $line, $namespaceEnd, 0);
    }
    foreach ($imports as $import) {
        if (strcasecmp($import[1][0], $class) > 0) {
            return substr_replace($code, $line, $import[0][1], 0);
        }
    }
    $last = $imports[array_key_last($imports)];

    return substr_replace($code, $line, $last[0][1] + strlen($last[0][0]), 0);
}

/** @var array<string, string> $moves */
$moves = require $argv[1];
$oldNamespaces = array_flip(array_map('namespaceOf', array_keys($moves)));

// Where every class of an affected namespace lives once the moves are done.
$destinations = [];
foreach (phpFiles() as $file) {
    $namespace = declaredNamespace((string) file_get_contents($file));
    if (isset($oldNamespaces[$namespace])) {
        $class = $namespace . '\\' . basename($file, '.php');
        $destinations[$namespace][basename($file, '.php')] = $moves[$class] ?? $class;
    }
}

// 1. Before anything moves: which bare names each file will have to import.
$importsFor = [];
foreach (phpFiles() as $file) {
    $code = (string) file_get_contents($file);
    $namespace = declaredNamespace($code);
    if (!isset($oldNamespaces[$namespace])) {
        continue;
    }
    $class = $namespace . '\\' . basename($file, '.php');
    $destination = $moves[$class] ?? $class;
    $imported = importedNames($code);
    foreach (array_keys(referencedNames($code)) as $name) {
        $target = $destinations[$namespace][(string) $name] ?? null;
        if (null === $target || isset($imported[$name]) || $target === $destination) {
            continue;
        }
        if (namespaceOf($target) !== namespaceOf($destination)) {
            $importsFor[pathOf($destination)][$target] = true;
        }
    }
}

// 2. Each file moves, and its namespace line with it.
foreach ($moves as $old => $new) {
    $to = pathOf($new);
    if (!is_dir(dirname($to))) {
        mkdir(dirname($to), 0o775, true);
    }
    run(sprintf('git mv %s %s', escapeshellarg(pathOf($old)), escapeshellarg($to)));
    file_put_contents($to, str_replace(
        'namespace ' . namespaceOf($old) . ";\n",
        'namespace ' . namespaceOf($new) . ";\n",
        (string) file_get_contents($to),
    ));
}

// 3. Every spelling of every old name, in code, strings, docblocks and config, becomes the new one.
$patterns = [];
foreach ($moves as $old => $new) {
    $patterns['/(?<!\w)' . preg_quote($old, '/') . '(?!\w)/'] = $new;
    $patterns['/(?<!\w)' . preg_quote(str_replace('\\', '\\\\', $old), '/') . '(?!\w)/']
        = str_replace('\\', '\\\\', $new);
}
$textFiles = trackedFiles('src', 'tests', 'config', 'phpstan.dist.neon', 'infection.json5', 'phptramp.dist.json');
foreach ($textFiles as $file) {
    $code = (string) file_get_contents($file);
    $rewritten = $code;
    foreach ($patterns as $pattern => $replacement) {
        $rewritten = (string) preg_replace_callback($pattern, static fn (): string => $replacement, $rewritten);
    }
    if ($rewritten !== $code) {
        file_put_contents($file, $rewritten);
    }
}

// 4. An import of a class that now shares the file's namespace goes.
foreach (phpFiles() as $file) {
    $code = (string) file_get_contents($file);
    $namespace = declaredNamespace($code);
    $trimmed = $code;
    foreach ($moves as $new) {
        if (namespaceOf($new) === $namespace) {
            $trimmed = removeImport($trimmed, $new);
        }
    }
    if ($trimmed !== $code) {
        file_put_contents($file, $trimmed);
    }
}

// 5. A bare name that left the file's namespace gets an import.
$added = 0;
foreach ($importsFor as $file => $classes) {
    $code = (string) file_get_contents($file);
    foreach (array_keys($classes) as $class) {
        $code = addImport($code, (string) $class);
        ++$added;
    }
    file_put_contents($file, $code);
}

printf("Moved %d classes; added %d imports in %d files.\n", count($moves), $added, count($importsFor));
```

- [ ] **Step 2: Write the map**

`var/refactor-1162/ai-completion-map.php`:
```php
<?php

declare(strict_types=1);

$classes = [
    'ChatCompletionClient', 'CompletionBodyDecoder', 'CompletionCallSlot', 'CompletionOutcome', 'CompletionRequest',
    'CompletionStreamHeartbeat', 'CompletionStreamObserver', 'CompletionStreamProgress', 'CompletionStreamReader',
    'CompletionUsage', 'CompositeCompletionStreamHeartbeat', 'ConcurrentCompletion', 'JsonSchema',
    'NullCompletionStreamObserver', 'OpenAiCompatibleChatClient', 'RateLimitedCompletion', 'RateLimitedResult',
    'RetryPlan',
];
$tests = [
    'CompletionBodyDecoderTest', 'CompletionOutcomeTest', 'CompletionStreamHeartbeatWiringTest',
    'CompletionStreamReaderTest', 'CompletionUsageTest', 'CompositeCompletionStreamHeartbeatTest',
    'OpenAiCompatibleChatClientTest', 'RateLimitedCompletionTest', 'RetryPlanTest',
];

$moves = [];
foreach ($classes as $class) {
    $moves['App\\Service\\Recommendation\\' . $class] = 'App\\Service\\Ai\\Completion\\' . $class;
}
foreach ($tests as $test) {
    $moves['App\\Tests\\Service\\Recommendation\\' . $test] = 'App\\Tests\\Service\\Ai\\Completion\\' . $test;
}

return $moves;
```

- [ ] **Step 3: Run it**

```bash
git status --short
php var/refactor-1162/move-classes.php var/refactor-1162/ai-completion-map.php
```
Expected: the first command prints nothing (a clean tree, so the move commit holds only the move). Then 27 `git mv` runs without error and `Moved 27 classes; added N imports in M files.` Record N and M in the task report.

- [ ] **Step 4: Verify the move is complete and one-directional**

```bash
ls src/Service/Ai/Completion | wc -l
ls tests/Service/Ai/Completion | wc -l
git grep -nE 'Service\\Recommendation\\(ChatCompletionClient|CompletionBodyDecoder|CompletionCallSlot|CompletionOutcome|CompletionRequest|CompletionStream|CompletionUsage|CompositeCompletionStreamHeartbeat|ConcurrentCompletion|JsonSchema|NullCompletionStreamObserver|OpenAiCompatibleChatClient|RateLimitedCompletion|RateLimitedResult|RetryPlan)' -- src tests config
git grep -n 'App\\Service\\Recommendation' -- src/Service/Ai
git grep -c 'Ai\\Completion' -- config/services.yaml config/services_test.yaml
git diff --cached --stat -M | tail -n 1
git status --short -- tests/PhpStan
```
Expected:
- `18` and `9`.
- The two greps for old names print nothing.
- `config/services.yaml:3` and `config/services_test.yaml:3` (the `ChatCompletionClient` alias line and the two heartbeat lines in each).
- The staged diff shows 27 renames.
- `tests/PhpStan` is untouched (the last command prints nothing): the rules and their fixtures are never rewritten.

- [ ] **Step 5: Gates**

```bash
bin/console cache:clear && bin/console cache:warmup
php bin/phpunit tests/Service/Ai tests/Service/Recommendation tests/Service/Worker tests/Repository tests/Command/RecommendationDrainCommandTest.php
composer check
composer md
```
Expected: PASS and clean. A PHPStan `Class App\Service\Recommendation\X not found` (or `...\Ai\Completion\X not found`) names a bare reference the script missed: add the import by hand and note it. Then PhpStorm `lint_files` on every file `git status --short` lists as renamed or modified. An "unused import" WARNING: delete that import.

- [ ] **Step 6: Commit**

```bash
git add -A src tests config
git status --short | grep -v '^[RM] ' || true
git commit -m "refactor(#1162): move the completion client and its rate-limit plumbing into Service/Ai/Completion"
```
Expected: the `grep -v` prints nothing (only renames and modifications are staged).

---

### Task A3: Split `Service/Recommendation` into `Prompt`, `Run`, `Feed`, `Settings`

A pure move, same script. Groups (D3): **Prompt** builds prompts, parses replies, and loads their inputs; **Run** is the tick, its phases, call recording and the run lifecycle; **Feed** reads for the UI (for-you feed, status poll, run history, debug log); **Settings** resolves and writes the recommendation settings. Dependencies point Feed → Run → Prompt → Settings (and → `Ai`); nothing points back.

**Files:**
- Create (not committed): `var/refactor-1162/recommendation-split-map.php`
- Move to `src/Service/Recommendation/Prompt/` (21): `CandidatePoolRequest`, `CandidatePoolSummary`, `ConsolidationParseResult`, `ModelReplyJsonDecoder`, `PickParseResult`, `PlausibleDuplicateShare`, `ProfileParseResult`, `PromptLine`, `RecommendationAnswerBudget`, `RecommendationCandidateLoader`, `RecommendationCompletionRequestFactory`, `RecommendationConsolidationParser`, `RecommendationHistory`, `RecommendationHistoryLoader`, `RecommendationPick`, `RecommendationPickParser`, `RecommendationPickSalvager`, `RecommendationProfileParser`, `RecommendationPromptBuilder`, `RecommendationPromptText`, `RecommendationResponseSchema`
- Move to `src/Service/Recommendation/Run/` (31): `BatchWaveResult`, `ConsolidationOutcome`, `DueRecommendationRunFinder`, `ForYouSweep`, `ForYouSweepReport`, `PhaseDurations`, `ProfileDistillationOutcome`, `RecommendationBatchWave`, `RecommendationCallRecorder`, `RecommendationConsolidationResolver`, `RecommendationDrainSpawner`, `RecommendationEtaEstimator`, `RecommendationPollDriver`, `RecommendationProfileDistiller`, `RecommendationProviderCall`, `RecommendationRunAdvancer`, `RecommendationRunCanceller`, `RecommendationRunDeferral`, `RecommendationRunFinalizer`, `RecommendationRunPurger`, `RecommendationRunReport`, `RecommendationRunStarter`, `RecommendationTickCheckpoint`, `RecommendationTransportFailureRecorder`, `RecommendationWaveConcurrency`, `RecommendationWinnerRanker`, `RecordedCall`, `RunLogRetention`, `TickDriver`, `TickLockKeepalive`, `WaveBatch`
- Move to `src/Service/Recommendation/Feed/` (19): `FeedAnnotationVisibility`, `ForYouFeed`, `ForYouFeedPage`, `ForYouMarkReadService`, `HistoryMonth`, `HistoryMonthSummariser`, `MonthWindow`, `RecommendationDebugLog`, `RecommendationDebugLogLoader`, `RecommendationFeedPage`, `RecommendationFeedPager`, `RecommendationForYouSummary`, `RecommendationForYouSummaryProvider`, `RecommendationRunHistory`, `RecommendationRunStatus`, `RecommendationRunStatusResolver`, `RunHistoryMonthPage`, `RunHistoryOverview`, `ViewerTimeZone`
- Move to `src/Service/Recommendation/Settings/` (5): `EffectiveRecommendationSettings`, `RecommendationPackingSettings`, `RecommendationSettingsBounds`, `RecommendationSettingsResolver`, `RecommendationSettingsWriter`
- Move tests to the mirroring directory under `tests/Service/Recommendation/` (38): Prompt 10, Run 19, Feed 6, Settings 3, as listed in the map. `RecommendationPipelineTest` (the whole tick pipeline) goes to `Run`, `RecommendationSettingsRoundTripTest` to `Settings`.
- Modify (by the script, names and imports only): every `src`, `tests` and `config` file naming a moved class, among them the ones #1182 added or edited: `src/Http/ForYouSweepReportJson.php`, `src/Service/Maintenance/MaintenanceSweeps.php`, `src/Http/RecommendationRunStatusJson.php`, `src/Http/RecommendationSettingsJson.php`, `src/Dto/Recommendation/SaveRecommendationSettingsRequest.php`, `tests/Http/ForYouSweepReportJsonTest.php`, `tests/Http/MaintenanceTickJsonTest.php`, `tests/Http/RecommendationRunStatusJsonTest.php`. Never `tests/PhpStan` (the script skips it). `RecommendationBatchSize` (`App\Enum`) and `RecommendationSettingsValues` (`App\Entity`) are not in the map and stay where #1182 put them.

**Interfaces:**
- Produces: `App\Service\Recommendation\{Prompt,Run,Feed,Settings}\<Name>`, code unchanged. `src/Service/Recommendation/` holds only `Exception`, `Feed`, `Prompt`, `Run`, `Settings`.

- [ ] **Step 1: Write the map**

`var/refactor-1162/recommendation-split-map.php`:
```php
<?php

declare(strict_types=1);

$groups = [
    'Prompt' => [
        'classes' => [
            'CandidatePoolRequest', 'CandidatePoolSummary', 'ConsolidationParseResult', 'ModelReplyJsonDecoder',
            'PickParseResult', 'PlausibleDuplicateShare', 'ProfileParseResult', 'PromptLine',
            'RecommendationAnswerBudget', 'RecommendationCandidateLoader', 'RecommendationCompletionRequestFactory',
            'RecommendationConsolidationParser', 'RecommendationHistory', 'RecommendationHistoryLoader',
            'RecommendationPick', 'RecommendationPickParser', 'RecommendationPickSalvager',
            'RecommendationProfileParser', 'RecommendationPromptBuilder', 'RecommendationPromptText',
            'RecommendationResponseSchema',
        ],
        'tests' => [
            'ModelReplyJsonDecoderTest', 'RecommendationAnswerBudgetTest', 'RecommendationCandidateLoaderTest',
            'RecommendationCompletionRequestFactoryTest', 'RecommendationConsolidationParserTest',
            'RecommendationHistoryLoaderTest', 'RecommendationPickParserTest', 'RecommendationProfileParserTest',
            'RecommendationPromptBuilderTest', 'RecommendationResponseSchemaTest',
        ],
    ],
    'Run' => [
        'classes' => [
            'BatchWaveResult', 'ConsolidationOutcome', 'DueRecommendationRunFinder', 'ForYouSweep',
            'ForYouSweepReport', 'PhaseDurations', 'ProfileDistillationOutcome', 'RecommendationBatchWave',
            'RecommendationCallRecorder', 'RecommendationConsolidationResolver', 'RecommendationDrainSpawner',
            'RecommendationEtaEstimator', 'RecommendationPollDriver', 'RecommendationProfileDistiller',
            'RecommendationProviderCall', 'RecommendationRunAdvancer', 'RecommendationRunCanceller',
            'RecommendationRunDeferral', 'RecommendationRunFinalizer', 'RecommendationRunPurger',
            'RecommendationRunReport', 'RecommendationRunStarter', 'RecommendationTickCheckpoint',
            'RecommendationTransportFailureRecorder', 'RecommendationWaveConcurrency', 'RecommendationWinnerRanker',
            'RecordedCall', 'RunLogRetention', 'TickDriver', 'TickLockKeepalive', 'WaveBatch',
        ],
        'tests' => [
            'ConsolidationOutcomeTest', 'DueRecommendationRunFinderTest', 'ForYouSweepTest', 'PhaseDurationsTest',
            'ProfileDistillationOutcomeTest', 'RecommendationCallRecorderTest',
            'RecommendationConsolidationResolverTest', 'RecommendationDrainSpawnerTest',
            'RecommendationEtaEstimatorTest', 'RecommendationPipelineTest', 'RecommendationProfileDistillerTest',
            'RecommendationRunAdvancerTest', 'RecommendationRunPurgerTest', 'RecommendationRunStarterTest',
            'RecommendationTickCheckpointTest', 'RecommendationWinnerRankerTest', 'RecordedCallTest',
            'TickDriverTest', 'TickLockKeepaliveTest',
        ],
    ],
    'Feed' => [
        'classes' => [
            'FeedAnnotationVisibility', 'ForYouFeed', 'ForYouFeedPage', 'ForYouMarkReadService', 'HistoryMonth',
            'HistoryMonthSummariser', 'MonthWindow', 'RecommendationDebugLog', 'RecommendationDebugLogLoader',
            'RecommendationFeedPage', 'RecommendationFeedPager', 'RecommendationForYouSummary',
            'RecommendationForYouSummaryProvider', 'RecommendationRunHistory', 'RecommendationRunStatus',
            'RecommendationRunStatusResolver', 'RunHistoryMonthPage', 'RunHistoryOverview', 'ViewerTimeZone',
        ],
        'tests' => [
            'ForYouFeedTest', 'HistoryMonthSummariserTest', 'MonthWindowTest', 'RecommendationFeedPagerTest',
            'RecommendationForYouSummaryProviderTest', 'ViewerTimeZoneTest',
        ],
    ],
    'Settings' => [
        'classes' => [
            'EffectiveRecommendationSettings', 'RecommendationPackingSettings', 'RecommendationSettingsBounds',
            'RecommendationSettingsResolver', 'RecommendationSettingsWriter',
        ],
        'tests' => [
            'RecommendationSettingsResolverTest', 'RecommendationSettingsRoundTripTest',
            'RecommendationSettingsWriterTest',
        ],
    ],
];

$moves = [];
foreach ($groups as $group => $members) {
    foreach ($members['classes'] as $class) {
        $moves['App\\Service\\Recommendation\\' . $class] = 'App\\Service\\Recommendation\\' . $group . '\\' . $class;
    }
    foreach ($members['tests'] as $test) {
        $moves['App\\Tests\\Service\\Recommendation\\' . $test]
            = 'App\\Tests\\Service\\Recommendation\\' . $group . '\\' . $test;
    }
}

return $moves;
```

- [ ] **Step 2: Run it**

```bash
git status --short
php var/refactor-1162/move-classes.php var/refactor-1162/recommendation-split-map.php
```
Expected: a clean tree first; then `Moved 114 classes; added N imports in M files.` Record N and M.

- [ ] **Step 3: Verify**

```bash
ls src/Service/Recommendation | tr '\n' ' '; echo
for group in Prompt Run Feed Settings; do echo "$group $(ls src/Service/Recommendation/$group | wc -l) $(ls tests/Service/Recommendation/$group | wc -l)"; done
ls tests/Service/Recommendation | tr '\n' ' '; echo
git grep -nE 'App\\Service\\Recommendation\\[A-Z]' -- src tests config | grep -vE 'Recommendation\\(Exception|Feed|Prompt|Run|Settings)\\' || true
git grep -nE 'App\\Service\\Recommendation\\(Run|Feed)' -- src/Service/Recommendation/Prompt src/Service/Recommendation/Settings || true
git grep -n 'App\\Service\\Recommendation\\Feed' -- src/Service/Recommendation/Run || true
git grep -n 'App\\Service' -- src/Entity src/Enum src/Doctrine || true
git status --short -- tests/PhpStan
```
Expected:
- `Exception Feed Prompt Run Settings`.
- `Prompt 21 10`, `Run 31 19`, `Feed 19 6`, `Settings 5 3`.
- `tests/Service/Recommendation` holds only `Feed Prompt Run Settings`.
- The first three greps print nothing: no FQCN under the old flat namespace, and no dependency points back up (Prompt/Settings → Run/Feed, Run → Feed).
- The persistence grep prints only the docblock mentions it printed before the move (`AccountLimits`, `InstanceSetting`, `SupportedLocale`; none names a recommendation class). The last command prints nothing.

- [ ] **Step 4: Gates**

```bash
bin/console cache:clear && bin/console cache:warmup
php bin/phpunit
composer check
composer md
```
Expected: PASS and clean. Same rule as A2 for a missed import or an unused one; PhpStorm `lint_files` on every renamed or modified file.

- [ ] **Step 5: Commit**

```bash
git add -A src tests config
git status --short | grep -v '^[RM] ' || true
git commit -m "refactor(#1162): split Service/Recommendation into Prompt, Run, Feed and Settings"
```

---

### Finishing PR A

- [ ] **Step 1: Per-PR gates**

```bash
php bin/phpunit
docker compose exec php composer test
composer check
composer md
composer infection:diff
```
Expected: all green. `infection:diff` runs with `--git-diff-filter=AM`, so renamed files are not mutated; expect mutations only in A1's `RetryPlan`, `TickDriver` and `ProviderRateLimitedException` (added/modified), or "no mutations" (`--ignore-msi-with-no-mutations`). PhpStorm lint on all changed PHP.

- [ ] **Step 2: Real run**

Run the **Real run** procedure in Global Constraints. Expected: `completed`, `batches_done` = total, `transport_failures` 0.

- [ ] **Step 3: Review, push, PR**

Review the diff with `git diff --stat -M origin/develop` (renames dominate) and `git diff -M origin/develop -- src config | grep '^[-+]' | grep -Ev '^[-+](use |namespace )' | grep -Ev '^(---|\+\+\+)'` (expected: only A1's lines and the FQCN lines in `config/*.yaml`).

```bash
git push -u origin refactor/1162-recommendation-namespaces
gh pr create --base develop --title "refactor(#1162): the completion transport moves to Service/Ai, Service/Recommendation splits in four" --body "$(cat <<'EOF'
Refs #1162 (PR A of 4).

- The transport no longer depends on recommendation concepts: `RetryPlan::blocking()`/`deferring()` with `TickDriver::retryPlan()` choosing; `RecommendationRunRateLimitedException` is now `App\Service\Ai\Exception\ProviderRateLimitedException`.
- 18 transport classes move to `App\Service\Ai\Completion` (pure move).
- The remaining 76 split into `Recommendation\{Prompt,Run,Feed,Settings}` (pure move). Dependencies point Feed → Run → Prompt → Settings.
- Tests mirror `src`.

Wire: none. Real run: <paste steps 3, 5, 6 and 7 of the real-run procedure>.
EOF
)"
```
The body must contain no "close", "closes", "fix", "fixes", "resolve" or "resolves".


### Execution rulings (PR A)

- **Preflight (opus scan, dry run on a scratch clone):**
  - A3 Step 3's first grep filters with `(\\|;)`, because namespace lines are expected hits.
  - A1b Step 7's second grep is `^use App\\Service\\Recommendation`.
  - A1b adds `ProviderRateLimitedExceptionTest`, which pins the message and `waitSeconds()`.
  - Pure moves and A1b's renames trim no docblock (D6). The comment bar applies only to the three docblocks A1 writes.
  - `MeilisearchIndex`'s slash-path mention of the chat client goes stale; it is carried to #1171.
- **A1:** one extra advancer test covers the only path where a worker wave is still rate-limited after its retries: the wave halves and counts a transport failure. Infection had flagged the untested `RetryableProviderException` arm. The test's empty `catch` follows the suite's existing pattern.
- **Final review and /simplify:**
  - The moves left import blocks interleaved. The fix wave sorts the file-level `use` block of every PHP file the branch changed (19 files, order only).
  - `CompletionStreamHeartbeatWiringTest` stays under `Ai/Completion`: it is a container-wiring test across modules, and the move map placed it there.
  - `RecommendationEtaEstimator` and `PhaseDurations` stay in `Run`, as D3's map puts them. Whether they belong in `Feed` goes to the planner.
  - `Service/Reading` imports `Recommendation\Feed\ViewerTimeZone`, a pre-existing coupling carried to #1169.
  - `RateLimitedCompletion`'s class docblock still speaks of drivers, a rewording carried to PR B/C.
- **Gates:** the MySQL leg runs without `TEST_TOKEN`, because the dev `feedreader` user cannot create `feedreader_test<token>`, and the container leg shares no database with the native runs. The real run's token is the output line matching `^eyJ`: `lexik:jwt:generate-token` ends with a blank line, so `tail -n 1` yields an empty token and a 401.
- **Real run:** run 124 as user 2 (`qwen/qwen3.7-flash`) completed with 6/6 batches, 0 transport failures, every call usable on attempt 1, and no new warning in the dev log.

---

# PR B — one tick context, one envelope, one wave context (`Refs #1162`)

### Task B0: Preflight (PR A merged)

**Files:** none.

- [ ] **Step 1: PR A is on `develop`, the tree has its shape**

```bash
git fetch origin
gh pr list --state merged --search "1162 in:title" --json number,title --jq '.[].title'
git ls-tree --name-only origin/develop -- src/Service/Recommendation/ src/Service/Ai/Completion/RetryPlan.php src/Service/Ai/Exception/ProviderRateLimitedException.php
git grep -n "ExcessiveParameterList" origin/develop -- src/Service/Recommendation
```
Expected: PR A's title; `src/Service/Recommendation/Exception`, `Feed`, `Prompt`, `Run`, `Settings` and the two Ai paths; the grep names `Run/RecommendationBatchWave.php`, `Run/RecommendationRunAdvancer.php` and `Settings/EffectiveRecommendationSettings.php` (the last stays, D16).

- [ ] **Step 2: Branch**

```bash
git status --short && git branch --show-current
git switch -c refactor/1162-tick-and-wave-contexts origin/develop
```

- [ ] **Step 3: Re-take the call sites B1 rewrites**

```bash
git grep -n "requireId()\|settingsResolver->forUser" -- src/Service/Recommendation/Run/RecommendationRunAdvancer.php
git grep -n -e "->distill(" -e "->resolve(" -- src/Service/Recommendation tests/Service/Recommendation tests/Service/Worker
git grep -n "getModel() ?? ''" -- src/Service/Recommendation
git grep -n "new RecommendationRunAdvancer(" -- src tests
```
Expected (re-checked at `5229c947`, the same as at `89c7e17f`; paths after PR A):
- The advancer: `lockNameFor()`'s `requireId()` plus four `$userId = $user->requireId();` and four `forUser($user)` (snapshot, provider, distill, consolidate ticks).
- `->distill(`: the advancer once, `RecommendationProfileDistillerTest` six times. `->resolve(`: the advancer twice (batch wave, consolidation), `RecommendationConsolidationResolverTest` twice.
- `getModel() ?? ''`: `RecommendationBatchWave`, `RecommendationConsolidationResolver`, `RecommendationProfileDistiller`, `Prompt/RecommendationCompletionRequestFactory` (the last stays: it builds the request, not the log row).
- `new RecommendationRunAdvancer(`: only `tests/Service/Worker/AdvanceRecommendationRunsHandlerTest.php`.

---

### Task B1: `TickContext`, the phase split and one catch envelope

**Files:**
- Create: `src/Service/Recommendation/Run/TickContext.php`, `ProviderPhase.php`, `TickPhases.php`, `SnapshotPhase.php`, `DistillationPhase.php`, `BatchPhase.php`, `ConsolidationPhase.php`, `InvalidReplyRetry.php`
- Modify (rewritten): `src/Service/Recommendation/Run/RecommendationRunAdvancer.php`, `RecommendationProfileDistiller.php`, `RecommendationConsolidationResolver.php`, `RecommendationProviderCall.php`
- Modify: `src/Service/Recommendation/Run/RecommendationBatchWave.php` (`resolve()`'s head only; B2 rewrites it)
- Modify (docblocks naming removed advancer members, D15): `src/Entity/AiProviderSettings.php`, `src/Entity/RunTuning.php`, `src/Service/Recommendation/Run/ForYouSweep.php`, `ProfileDistillationOutcome.php`, `RecommendationRunDeferral.php`, `RecommendationWaveConcurrency.php`, `WaveBatch.php`
- Modify: `config/services_test.yaml` (one entry)
- Test: `tests/Service/Recommendation/Run/TickContextTest.php` (new)
- Test: `tests/Service/Recommendation/Run/RecommendationProfileDistillerTest.php`, `RecommendationConsolidationResolverTest.php` (calls and helpers)
- Test: `tests/Service/Worker/AdvanceRecommendationRunsHandlerTest.php` (one helper, imports)

**Interfaces:**
- Consumes: `TickDriver::retryPlan(): RetryPlan` (A1).
- Produces:
  - `final readonly class TickContext { public RecommendationRun $run; public AiProviderSettings $connection; public EffectiveRecommendationSettings $settings; public TickDriver $driver; public function userId(): int; public function model(): string; public function retryPlan(): RetryPlan }`.
  - `interface ProviderPhase { public function advance(TickContext $tick): RecommendationRunReport; }`, implemented by `DistillationPhase`, `BatchPhase`, `ConsolidationPhase`.
  - `TickPhases::advance(TickContext $tick): RecommendationRunReport`; `SnapshotPhase::advance(TickContext $tick): RecommendationRunReport`; `InvalidReplyRetry::retryOrDegrade(RecommendationRun $run, string $invalidReply, \Closure $onAttemptsExhausted): RecommendationRunReport`.
  - `BatchPhase::POLL_MAX_CONCURRENCY = 2` (moved from the advancer). `RecommendationRunAdvancer::LOCK_TTL_MARGIN_SECONDS` and `lockNameFor()` stay.
  - `RecommendationProfileDistiller::distill(TickContext $tick): ProfileDistillationOutcome`; `RecommendationConsolidationResolver::resolve(TickContext $tick): ConsolidationOutcome`; `RecommendationProviderCall::complete(TickContext $tick, CompletionRequest $request, RecordedCall $recordedCall): string`; `RecommendationBatchWave::resolve(TickContext $tick, int $waveSize): BatchWaveResult` (B2 changes it again).
  - The advancer's constructor: `(RecommendationRunRepository, LockFactory, AiProviderConfigurator, ProviderConnectionFactory, ClockInterface, EntityManagerInterface, RecommendationSettingsResolver, TickLockKeepalive, TickPhases)`.

The phases move code; the behaviour is pinned by the existing `RecommendationRunAdvancerTest` (3168 lines), `AdvanceRecommendationRunsHandlerTest`, `WorkerRunSweepTest`, `ForYouSweepTest`, `RecommendationDrainCommandTest` and `RecommendationPipelineTest`, which go through `advance()` and stay unedited apart from the handler test's hand-built advancer.

- [ ] **Step 1: Write the failing test**

`tests/Service/Recommendation/Run/TickContextTest.php`:
```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Recommendation\Run;

use App\Entity\AiProviderSettings;
use App\Entity\RecommendationRun;
use App\Entity\User;
use App\Enum\RecommendationBatchSize;
use App\Service\Recommendation\Run\TickContext;
use App\Service\Recommendation\Run\TickDriver;
use App\Service\Recommendation\Settings\EffectiveRecommendationSettings;
use App\Service\Recommendation\Settings\RecommendationPackingSettings;
use App\Tests\Support\AiProviderSettingsFactory;
use PHPUnit\Framework\TestCase;

final class TickContextTest extends TestCase
{
    private const string AT = '2026-08-07 09:00:00';

    public function testTheModelIsTheConnectionsChosenModel(): void
    {
        $connection = $this->connection();
        $connection->chooseModel('m', new \DateTimeImmutable(self::AT), 32768);

        self::assertSame('m', $this->tick($connection, TickDriver::Poll)->model());
    }

    public function testAConnectionWithoutAModelNamesNone(): void
    {
        self::assertSame('', $this->tick($this->connection(), TickDriver::Poll)->model());
    }

    public function testTheDriverDecidesTheRetryPlan(): void
    {
        self::assertTrue($this->tick($this->connection(), TickDriver::Worker)->retryPlan()->blocks());
        self::assertFalse($this->tick($this->connection(), TickDriver::Sweep)->retryPlan()->blocks());
    }

    private function tick(AiProviderSettings $connection, TickDriver $driver): TickContext
    {
        return new TickContext(
            new RecommendationRun($connection->getUser(), new \DateTimeImmutable(self::AT)),
            $connection,
            new EffectiveRecommendationSettings(
                guidancePrompt: null,
                favoritesCap: 40,
                keptCap: 40,
                viewedCap: 80,
                candidatePoolSize: 500,
                lookbackDays: 2,
                picksLimit: 50,
                packing: new RecommendationPackingSettings(
                    contextWindow: 32768,
                    contextWindowSource: 'fallback',
                    batchSize: RecommendationBatchSize::Medium,
                    maximumBatchSize: RecommendationPackingSettings::DEFAULT_MAXIMUM_BATCH_SIZE,
                ),
                debugEnabled: false,
            ),
            $driver,
        );
    }

    private function connection(): AiProviderSettings
    {
        return AiProviderSettingsFactory::build(
            new User('tick-context@example.test', new \DateTimeImmutable(self::AT)),
        );
    }
}
```

`tests/Service/Recommendation/Run/RecommendationProfileDistillerTest.php`:
```bash
perl -0pi -e 's/->distill\(\s*(\$run|\$this->runInRunningState\(\)),\s*\$this->activeAiSettings\(\),\s*\$this->userId\(\),\s*\$this->effectiveSettings\(\),\s*\$this->plan\(\),\s*\)/->distill(\$this->tick($1))/g' tests/Service/Recommendation/Run/RecommendationProfileDistillerTest.php
git grep -c -e "->distill(\$this->tick(" -- tests/Service/Recommendation/Run/RecommendationProfileDistillerTest.php
```
Expected: `6`. Then, in the same file:
- Replace
```php
    private function plan(): RetryPlan
    {
        return RetryPlan::deferring();
    }
```
with
```php
    private function tick(RecommendationRun $run): TickContext
    {
        return new TickContext($run, $this->activeAiSettings(), $this->effectiveSettings(), TickDriver::Poll);
    }
```
- Delete the whole `private function userId(): int` method and the blank line above it.
- Delete `use App\Service\Ai\Completion\RetryPlan;`. After `use App\Service\Recommendation\Run\RecommendationProfileDistiller;` add:
```php
use App\Service\Recommendation\Run\TickContext;
use App\Service\Recommendation\Run\TickDriver;
```

`tests/Service/Recommendation/Run/RecommendationConsolidationResolverTest.php`:
```bash
perl -0pi -e 's/->resolve\(\s*\$run,\s*\$this->activeAiSettings\(\),\s*\$this->userId\(\),\s*50,\s*\$this->effectiveSettings\(\),\s*\$this->plan\(\),\s*\)/->resolve(\$this->tick(\$run))/g' tests/Service/Recommendation/Run/RecommendationConsolidationResolverTest.php
git grep -c -e "->resolve(\$this->tick(\$run))" -- tests/Service/Recommendation/Run/RecommendationConsolidationResolverTest.php
```
Expected: `2`. The `50` the test passed is the default `picksLimit` the context now carries (`effectiveSettings()` resolves an account with no settings row). Then:
- Replace
```php
    private function plan(): RetryPlan
    {
        return RetryPlan::deferring();
    }
```
with
```php
    private function tick(RecommendationRun $run): TickContext
    {
        return new TickContext($run, $this->activeAiSettings(), $this->effectiveSettings(), TickDriver::Poll);
    }
```
- Delete `private function userId(): int` and the blank line above it.
- Delete `use App\Service\Ai\Completion\RetryPlan;`. After `use App\Service\Recommendation\Run\RecommendationConsolidationResolver;` add the same two imports.

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php bin/phpunit tests/Service/Recommendation/Run/TickContextTest.php tests/Service/Recommendation/Run/RecommendationProfileDistillerTest.php tests/Service/Recommendation/Run/RecommendationConsolidationResolverTest.php`
Expected: FAIL: `Class "App\Service\Recommendation\Run\TickContext" not found`.

- [ ] **Step 3: Write the context, the phases and the envelope**

`src/Service/Recommendation/Run/TickContext.php`:
```php
<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Run;

use App\Entity\AiProviderSettings;
use App\Entity\RecommendationRun;
use App\Service\Ai\Completion\RetryPlan;
use App\Service\Recommendation\Settings\EffectiveRecommendationSettings;

final readonly class TickContext
{
    /** @noinspection AutowireWrongClass Built with new, never autowired */
    public function __construct(
        public RecommendationRun $run,
        public AiProviderSettings $connection,
        public EffectiveRecommendationSettings $settings,
        public TickDriver $driver,
    ) {
    }

    public function userId(): int
    {
        return $this->run->getUser()->requireId();
    }

    public function model(): string
    {
        return $this->connection->getModel() ?? '';
    }

    public function retryPlan(): RetryPlan
    {
        return $this->driver->retryPlan();
    }
}
```

`src/Service/Recommendation/Run/ProviderPhase.php`:
```php
<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Run;

interface ProviderPhase
{
    public function advance(TickContext $tick): RecommendationRunReport;
}
```

`src/Service/Recommendation/Run/TickPhases.php`:
```php
<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Run;

use App\Entity\RecommendationRun;
use App\Service\Ai\Exception\CredentialsRejectedException;
use App\Service\Ai\Exception\ProviderRateLimitedException;
use App\Service\Ai\Exception\ProviderUnreachableException;
use App\Service\Ai\Exception\RetryableProviderException;
use Symfony\Component\Clock\ClockInterface;

final readonly class TickPhases
{
    public function __construct(
        private SnapshotPhase $snapshot,
        private DistillationPhase $distillation,
        private BatchPhase $batch,
        private ConsolidationPhase $consolidation,
        private RecommendationRunDeferral $deferral,
        private RecommendationTransportFailureRecorder $transportFailures,
        private ClockInterface $clock,
    ) {
    }

    public function advance(TickContext $tick): RecommendationRunReport
    {
        $run = $tick->run;
        if (RecommendationRun::STATUS_PENDING === $run->getStatus()) {
            return $this->snapshot->advance($tick);
        }

        if ($run->mustWaitBeforeRetry($this->clock->now())) {
            return RecommendationRunReport::fromRun($run);
        }

        return $this->advanceWithinTheEnvelope($this->providerPhaseFor($run), $tick);
    }

    private function providerPhaseFor(RecommendationRun $run): ProviderPhase
    {
        $progress = $run->progress();

        return match (true) {
            $progress->distillPending => $this->distillation,
            $progress->isConsolidationPhase => $this->consolidation,
            default => $this->batch,
        };
    }

    /** A rate limit defers the run; a transport failure strikes it once and still propagates. */
    private function advanceWithinTheEnvelope(ProviderPhase $phase, TickContext $tick): RecommendationRunReport
    {
        try {
            return $phase->advance($tick);
        } catch (ProviderRateLimitedException $e) {
            return $this->deferral->defer($tick->run, $e);
        } catch (ProviderUnreachableException | CredentialsRejectedException | RetryableProviderException $e) {
            $this->transportFailures->record($tick->run, $tick->connection, $e->getMessage());

            throw $e;
        }
    }
}
```

`src/Service/Recommendation/Run/SnapshotPhase.php`:
```php
<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Run;

use App\Service\Recommendation\Prompt\CandidatePoolRequest;
use App\Service\Recommendation\Prompt\PromptLine;
use App\Service\Recommendation\Prompt\RecommendationCandidateLoader;
use App\Service\Recommendation\Prompt\RecommendationHistoryLoader;
use App\Service\Recommendation\Prompt\RecommendationPromptBuilder;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;

/** Freezes a pending run's candidate pool into batches without a provider call; an empty pool completes at once. */
final readonly class SnapshotPhase
{
    public function __construct(
        private RecommendationCandidateLoader $candidateLoader,
        private RecommendationHistoryLoader $historyLoader,
        private RecommendationPromptBuilder $promptBuilder,
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
    ) {
    }

    public function advance(TickContext $tick): RecommendationRunReport
    {
        $run = $tick->run;
        $candidates = $this->candidatesFor($tick);

        if ([] === $candidates) {
            $run->snapshot([]);
            $run->complete($this->clock->now());
            $this->entityManager->flush();

            return RecommendationRunReport::fromRun($run);
        }

        $history = $this->historyLoader->load($tick->userId(), $tick->settings);
        $run->snapshot($this->promptBuilder->packBatches($candidates, $history, $tick->settings));
        $this->entityManager->flush();

        return RecommendationRunReport::fromRun($run);
    }

    /** @return list<PromptLine> */
    private function candidatesFor(TickContext $tick): array
    {
        $now = $this->clock->now();

        return $this->candidateLoader->load($tick->userId(), new CandidatePoolRequest(
            // P<N>D is calendar-day arithmetic: N x 24 h only because Kernel pins the process timezone to UTC.
            since: $now->sub(new \DateInterval(\sprintf('P%dD', $tick->settings->lookbackDays))),
            poolSize: $tick->settings->candidatePoolSize,
            orderSeed: (int) $now->getTimestamp(),
        ));
    }
}
```

`src/Service/Recommendation/Run/InvalidReplyRetry.php`:
```php
<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Run;

use App\Entity\RecommendationRun;
use Doctrine\ORM\EntityManagerInterface;

/** The cross-tick retry distillation and consolidation share; the batch phase retries inside its tick (#344). */
final readonly class InvalidReplyRetry
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    /** @param \Closure(): RecommendationRunReport $onAttemptsExhausted the degraded ending */
    public function retryOrDegrade(
        RecommendationRun $run,
        string $invalidReply,
        \Closure $onAttemptsExhausted,
    ): RecommendationRunReport {
        $run->recordInvalidReply($invalidReply);
        if ($run->progress()->attemptsExhausted) {
            return $onAttemptsExhausted();
        }
        $this->entityManager->flush();

        return RecommendationRunReport::fromRun($run);
    }
}
```

`src/Service/Recommendation/Run/DistillationPhase.php`:
```php
<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Run;

use App\Entity\RecommendationRun;
use Doctrine\ORM\EntityManagerInterface;

/** Records the distilled profile; an unusable reply is retried, then the run proceeds without a profile (#493). */
final readonly class DistillationPhase implements ProviderPhase
{
    public function __construct(
        private RecommendationProfileDistiller $distiller,
        private InvalidReplyRetry $invalidReplies,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function advance(TickContext $tick): RecommendationRunReport
    {
        $run = $tick->run;
        $outcome = $this->distiller->distill($tick);

        if (!$outcome->usable) {
            return $this->invalidReplies->retryOrDegrade(
                $run,
                $outcome->requireUnusableReply(),
                fn (): RecommendationRunReport => $this->recordProfile($run, null),
            );
        }

        return $this->recordProfile($run, $outcome->profileText);
    }

    private function recordProfile(RecommendationRun $run, ?string $profileText): RecommendationRunReport
    {
        $run->recordProfile($profileText);
        $this->entityManager->flush();

        return RecommendationRunReport::fromRun($run);
    }
}
```

`src/Service/Recommendation/Run/ConsolidationPhase.php`:
```php
<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Run;

/**
 * Finalizes the consolidated list; an unusable reply is retried, then the run completes with the undeduped
 * batch-score pool (#493).
 */
final readonly class ConsolidationPhase implements ProviderPhase
{
    public function __construct(
        private RecommendationConsolidationResolver $resolver,
        private RecommendationRunFinalizer $finalizer,
        private InvalidReplyRetry $invalidReplies,
    ) {
    }

    public function advance(TickContext $tick): RecommendationRunReport
    {
        $run = $tick->run;
        $outcome = $this->resolver->resolve($tick);

        if (!$outcome->usable) {
            return $this->invalidReplies->retryOrDegrade(
                $run,
                $outcome->requireUnusableReply(),
                fn (): RecommendationRunReport => $this->finalizer->finalize($run, $outcome->requireFallbackPool()),
            );
        }

        return $this->finalizer->finalize($run, $outcome->ranked);
    }
}
```

`src/Service/Recommendation/Run/BatchPhase.php`:
```php
<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Run;

use App\Entity\RecommendationRun;
use App\Service\Ai\Exception\ProviderRateLimitedException;
use App\Service\Ai\Exception\RetryableProviderException;
use Doctrine\ORM\EntityManagerInterface;

final readonly class BatchPhase implements ProviderPhase
{
    /** A poll or sweep tick is a web request: its wave stays this small whatever the connection allows (#344). */
    public const int POLL_MAX_CONCURRENCY = 2;

    public function __construct(
        private RecommendationBatchWave $batchWave,
        private RecommendationWaveConcurrency $waveConcurrency,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function advance(TickContext $tick): RecommendationRunReport
    {
        $run = $tick->run;
        $this->markFirstBatchBeforeCallingProvider($run);

        foreach ($this->resolveWave($tick)->winners as $winners) {
            $run->recordBatchWinners($winners);
        }
        $this->entityManager->flush();

        return RecommendationRunReport::fromRun($run);
    }

    /** A 429 anywhere in the wave halves the run's concurrency, whether the plan recovered or defers (#947). */
    private function resolveWave(TickContext $tick): BatchWaveResult
    {
        try {
            $result = $this->batchWave->resolve($tick, $this->waveSize($tick));
        } catch (ProviderRateLimitedException | RetryableProviderException $e) {
            $this->waveConcurrency->halve($tick->run, $tick->connection);

            throw $e;
        }

        if ($result->rateLimitObserved) {
            $this->waveConcurrency->halve($tick->run, $tick->connection);
        }

        return $result;
    }

    /** Flushed before the blocking call, so a status poll during it already sees the ETA may count down. */
    private function markFirstBatchBeforeCallingProvider(RecommendationRun $run): void
    {
        if ($run->hasFirstBatchStarted()) {
            return;
        }

        $run->markFirstBatchStarted();
        $this->entityManager->flush();
    }

    /**
     * A run's first wave is one call, which warms the provider's prompt-prefix cache before the fan-out (#495);
     * later waves take the driver's cap, the run's halved concurrency or the batches left, whichever is least.
     */
    private function waveSize(TickContext $tick): int
    {
        $progress = $tick->run->progress();
        if (0 === $progress->nextBatchIndex) {
            return 1;
        }

        return min(
            $this->effectiveCap($tick),
            $this->waveConcurrency->cap($tick->run, $tick->connection),
            \count($tick->run->getCandidateBatches()) - $progress->nextBatchIndex,
        );
    }

    /** Floored at 1: a stored batchConcurrency of 0 would otherwise wedge the run in empty ticks (#344). */
    private function effectiveCap(TickContext $tick): int
    {
        $connection = $tick->connection;
        $cap = TickDriver::Worker === $tick->driver
            ? $connection->cappedBatchConcurrency()
            : min($connection->batchConcurrency(), self::POLL_MAX_CONCURRENCY);

        return max(1, $cap);
    }
}
```

- [ ] **Step 4: Rewrite the advancer and its collaborators**

`src/Service/Recommendation/Run/RecommendationRunAdvancer.php` (rewritten in full; the removed `@SuppressWarnings` goes with the old docblock):
```php
<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Run;

use App\Entity\RecommendationRun;
use App\Entity\User;
use App\Repository\RecommendationRunRepository;
use App\Service\Ai\AiProviderConfigurator;
use App\Service\Ai\Exception\AiKeyUnreadableException;
use App\Service\Ai\Exception\AiNotConfiguredException;
use App\Service\Ai\ProviderConnectionFactory;
use App\Service\Ai\ProviderTimeouts;
use App\Service\Recommendation\Exception\RecommendationRunCancelledException;
use App\Service\Recommendation\Exception\RecommendationTickLockLostException;
use App\Service\Recommendation\Settings\RecommendationSettingsResolver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Lock\LockFactory;

/**
 * The driver-agnostic tick (#311): the worker, the poll endpoint and the cron sweep all call advance(), one tick
 * per account at a time behind a per-user lock. TickPhases decides what the tick does.
 */
final readonly class RecommendationRunAdvancer
{
    private const string LOCK_NAME_PREFIX = 'ai-recommendations-';

    /**
     * Headroom over the longest silence a live holder produces: loading and packing before a request, banking
     * between waves, the whole snapshot tick (#444). Public so the test pins the TTL against its two inputs.
     */
    public const float LOCK_TTL_MARGIN_SECONDS = 300.0;

    public function __construct(
        private RecommendationRunRepository $runs,
        private LockFactory $lockFactory,
        private AiProviderConfigurator $configurator,
        private ProviderConnectionFactory $connections,
        private ClockInterface $clock,
        private EntityManagerInterface $entityManager,
        private RecommendationSettingsResolver $settingsResolver,
        private TickLockKeepalive $keepalive,
        private TickPhases $phases,
    ) {
    }

    /** The one place the lock's name is formed; RecommendationPollDriver logs this very name (#439). */
    public static function lockNameFor(User $user): string
    {
        return self::LOCK_NAME_PREFIX . $user->requireId();
    }

    public function advance(User $user, TickDriver $driver = TickDriver::Poll): RecommendationRunReport
    {
        $lockName = self::lockNameFor($user);
        $lock = $this->lockFactory->createLock($lockName, $this->lockTtlFor($user));

        if (!$lock->acquire()) {
            // Silent: a failed acquire is the healthy, frequent case; only the poll driver can tell a stall (#439).
            return RecommendationRunReport::busy();
        }

        // A hard request kill (Strato's 240 s cap) never reaches the finally below and would strand the lock for
        // its whole TTL. The delete is token-scoped, so on the normal path this hook is a harmless no-op.
        register_shutdown_function(static function () use ($lock): void {
            try {
                $lock->release();
            } catch (\Throwable) {
                // A failed release during shutdown must not raise a second fatal; the TTL still bounds the stall.
            }
        });

        $this->keepalive->hold($lock, $lockName);

        try {
            return $this->tick($user, $driver);
        } finally {
            // Disarmed before the release, never after: a beat in between would refresh a lock on its way out.
            $this->keepalive->release();
            $lock->release();
        }
    }

    /**
     * The TTL clears the longest silence a live holder produces, one first-byte wait, not the whole tick: the
     * keepalive refreshes the lock on streamed chunks (#444). A slow-marked connection waits longer (#433).
     */
    private function lockTtlFor(User $user): float
    {
        $settings = $this->configurator->settingsFor($user);
        $timeouts = null === $settings
            ? ProviderTimeouts::standard()
            : $this->connections->timeoutsFor($settings);

        return $timeouts->firstByteSeconds + self::LOCK_TTL_MARGIN_SECONDS;
    }

    private function tick(User $user, TickDriver $driver): RecommendationRunReport
    {
        $run = $this->runs->findActiveForUser($user);

        if (null === $run) {
            $latest = $this->runs->findLatestForUser($user);

            return null === $latest ? RecommendationRunReport::none() : RecommendationRunReport::fromRun($latest);
        }

        try {
            return $this->tickActiveRun($run, $user, $driver);
        } catch (RecommendationRunCancelledException | RecommendationTickLockLostException) {
            // Stopped by the user or by a lost lock (#444): drop this tick's work, re-read the row its owner wrote.
            $this->entityManager->refresh($run);

            return RecommendationRunReport::fromRun($run);
        } catch (AiNotConfiguredException | AiKeyUnreadableException $e) {
            // Such a run can never advance again, so it fails here for every driver (#311), and the error still
            // propagates to the HTTP mapping and the worker's fault floor.
            $this->failPermanently($run, self::failureMessageFor($e));

            throw $e;
        }
    }

    private function tickActiveRun(RecommendationRun $run, User $user, TickDriver $driver): RecommendationRunReport
    {
        $connection = $this->configurator->requireConfiguration($user);
        if (!$connection->hasModel()) {
            throw new AiNotConfiguredException('No model is chosen.');
        }

        return $this->phases->advance(
            new TickContext($run, $connection, $this->settingsResolver->forUser($user), $driver),
        );
    }

    private function failPermanently(RecommendationRun $run, string $message): void
    {
        $run->fail($message, $this->clock->now());
        $this->entityManager->flush();
    }

    private static function failureMessageFor(AiNotConfiguredException | AiKeyUnreadableException $e): string
    {
        return $e instanceof AiKeyUnreadableException
            ? 'The stored API key can no longer be read.'
            : 'The AI provider is no longer configured.';
    }
}
```

`src/Service/Recommendation/Run/RecommendationProfileDistiller.php` (rewritten in full):
```php
<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Run;

use App\Entity\RecommendationRunLog;
use App\Service\Recommendation\Prompt\RecommendationCompletionRequestFactory;
use App\Service\Recommendation\Prompt\RecommendationHistoryLoader;
use App\Service\Recommendation\Prompt\RecommendationProfileParser;
use App\Service\Recommendation\Prompt\RecommendationPromptBuilder;
use App\Service\Recommendation\Prompt\RecommendationPromptText;
use App\Service\Recommendation\Prompt\RecommendationResponseSchema;
use App\Service\Recommendation\Settings\RecommendationSettingsWriter;

/**
 * The distillation phase's one provider call (#493): the reader's full history in, a short profile out, cached on
 * the settings row so a later run can skip it. It never touches the run's progress.
 */
final readonly class RecommendationProfileDistiller
{
    public function __construct(
        private RecommendationHistoryLoader $historyLoader,
        private RecommendationPromptBuilder $promptBuilder,
        private RecommendationCallRecorder $callRecorder,
        private RecommendationCompletionRequestFactory $requestFactory,
        private RecommendationProviderCall $providerCall,
        private RecommendationProfileParser $profileParser,
        private RecommendationTickCheckpoint $checkpoint,
        private RecommendationSettingsWriter $settingsWriter,
    ) {
    }

    public function distill(TickContext $tick): ProfileDistillationOutcome
    {
        $run = $tick->run;
        $history = $this->historyLoader->load($tick->userId(), $tick->settings);
        $messages = $this->promptBuilder->messagesWithCorrectiveTail(
            $this->promptBuilder->distillMessages($history, $tick->settings),
            $run->getLastInvalidReply(),
            RecommendationPromptText::DISTILL_CORRECTIVE,
        );

        $recordedCall = $this->callRecorder->begin(
            $run,
            RecommendationRunLog::PHASE_DISTILL,
            null,
            $messages,
            $tick->model(),
        );

        $content = $this->providerCall->complete(
            $tick,
            $this->requestFactory->create(
                $tick->connection,
                $messages,
                1,
                RecommendationResponseSchema::Distillation,
            ),
            $recordedCall,
        );

        $result = $this->profileParser->parse($content);
        $recordedCall->settle($content, $result->usable);
        $this->checkpoint->guard($run);

        if (!$result->usable) {
            return ProfileDistillationOutcome::unusable($content);
        }

        $profile = $result->profile
            ?? throw new \LogicException('A usable profile parse result has no profile text.');
        $this->settingsWriter->storeProfile($run->getUser(), $profile);

        return ProfileDistillationOutcome::usable($profile);
    }
}
```

`src/Service/Recommendation/Run/RecommendationConsolidationResolver.php` (rewritten in full; `stillPresent()`, `rankedFromReply()` and `picksById()` keep their code, their docblocks are trimmed):
```php
<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Run;

use App\Entity\RecommendationRunLog;
use App\Service\Recommendation\Prompt\ConsolidationParseResult;
use App\Service\Recommendation\Prompt\PromptLine;
use App\Service\Recommendation\Prompt\RecommendationCandidateLoader;
use App\Service\Recommendation\Prompt\RecommendationCompletionRequestFactory;
use App\Service\Recommendation\Prompt\RecommendationConsolidationParser;
use App\Service\Recommendation\Prompt\RecommendationHistoryLoader;
use App\Service\Recommendation\Prompt\RecommendationPick;
use App\Service\Recommendation\Prompt\RecommendationPromptBuilder;
use App\Service\Recommendation\Prompt\RecommendationPromptText;
use App\Service\Recommendation\Prompt\RecommendationResponseSchema;

/**
 * The consolidation phase's one provider call (#493): re-score, reason and dedupe the top of the pool in one pass.
 * A pool pruned to nothing finalizes free; an unusable reply comes back with the batch-score pool to degrade to.
 */
final readonly class RecommendationConsolidationResolver
{
    public function __construct(
        private RecommendationWinnerRanker $ranker,
        private RecommendationCandidateLoader $candidateLoader,
        private RecommendationHistoryLoader $historyLoader,
        private RecommendationPromptBuilder $promptBuilder,
        private RecommendationCallRecorder $callRecorder,
        private RecommendationCompletionRequestFactory $requestFactory,
        private RecommendationProviderCall $providerCall,
        private RecommendationConsolidationParser $consolidationParser,
        private RecommendationTickCheckpoint $checkpoint,
    ) {
    }

    public function resolve(TickContext $tick): ConsolidationOutcome
    {
        $run = $tick->run;
        $settings = $tick->settings;
        $history = $this->historyLoader->load($tick->userId(), $settings);
        $inputSize = $this->promptBuilder->consolidationInputSize(
            $settings->packing->contextWindow,
            $history,
            $run->getProfileText(),
            $settings->picksLimit,
            $tick->connection->suppressesReasoning(),
        );
        $pool = $this->ranker->cutForConsolidation($this->ranker->ranked($run->getWinners()), $inputSize);
        $linesById = $this->candidateLoader->linesForIds($tick->userId(), array_column($pool, 'id'));
        $pool = self::stillPresent($pool, $linesById);

        if ([] === $pool) {
            return ConsolidationOutcome::finalizeWith([]);
        }

        $messages = $this->promptBuilder->messagesWithCorrectiveTail(
            $this->promptBuilder->consolidationMessages(
                $pool,
                $linesById,
                $history,
                $settings,
                $run->getProfileText(),
            ),
            $run->getLastInvalidReply(),
            RecommendationPromptText::CONSOLIDATION_CORRECTIVE,
        );

        $recordedCall = $this->callRecorder->begin(
            $run,
            RecommendationRunLog::PHASE_CONSOLIDATE,
            null,
            $messages,
            $tick->model(),
        );

        $content = $this->providerCall->complete(
            $tick,
            $this->requestFactory->create(
                $tick->connection,
                $messages,
                \count($pool),
                RecommendationResponseSchema::Consolidation,
            ),
            $recordedCall,
        );

        $result = $this->consolidationParser->parse($content, array_column($pool, 'id'));
        $recordedCall->settle($content, $result->usable);
        $this->checkpoint->guard($run);

        if (!$result->usable) {
            return ConsolidationOutcome::unusable($content, $pool);
        }

        return ConsolidationOutcome::finalizeWith(self::rankedFromReply($result));
    }

    /**
     * @param list<array{id: int, score: int, reason: string}> $pool
     * @param array<int, PromptLine>                           $linesById entries pruned since their batch are absent
     *
     * @return list<array{id: int, score: int, reason: string}>
     */
    private static function stillPresent(array $pool, array $linesById): array
    {
        return array_values(array_filter(
            $pool,
            static fn (array $winner): bool => isset($linesById[$winner['id']]),
        ));
    }

    /**
     * Exactly the entries the reply scored, minus its named duplicates, best first: consolidation is the sole
     * authority on the final feed, so an entry it did not mention is dropped, not kept at its batch score.
     *
     * @return list<array{id: int, score: int, reason: string}>
     */
    private static function rankedFromReply(ConsolidationParseResult $result): array
    {
        $ranked = array_values(array_filter(
            self::picksById($result->picks),
            static fn (array $pick): bool => !\in_array($pick['id'], $result->duplicateIds, true),
        ));

        usort($ranked, static fn (array $left, array $right): int => $right['score'] <=> $left['score']);

        return $ranked;
    }

    /**
     * @param list<RecommendationPick> $picks
     *
     * @return array<int, array{id: int, score: int, reason: string}>
     */
    private static function picksById(array $picks): array
    {
        $picksById = [];
        foreach ($picks as $pick) {
            $picksById[$pick->entryId] = ['id' => $pick->entryId, 'score' => $pick->score, 'reason' => $pick->reason];
        }

        return $picksById;
    }
}
```

`src/Service/Recommendation/Run/RecommendationProviderCall.php` (rewritten in full):
```php
<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Run;

use App\Service\Ai\Completion\CompletionRequest;
use App\Service\Ai\Completion\RateLimitedCompletion;
use App\Service\Ai\ProviderConnectionFactory;

/**
 * One recorded provider call for the single-call phases (#493). Any failure, an unreadable key included, settles the
 * debug row before it propagates unchanged: an unsettled row reads as "still streaming" forever (#309).
 */
final readonly class RecommendationProviderCall
{
    public function __construct(
        private RateLimitedCompletion $completion,
        private ProviderConnectionFactory $connections,
    ) {
    }

    public function complete(TickContext $tick, CompletionRequest $request, RecordedCall $recordedCall): string
    {
        try {
            return $this->completion->complete(
                $this->connections->forSettings($tick->connection),
                $request,
                $recordedCall,
                $tick->retryPlan(),
            );
        } catch (\Throwable $e) {
            $recordedCall->abortAfterTransportFailure($e->getMessage());

            throw $e;
        }
    }
}
```

`src/Service/Recommendation/Run/RecommendationBatchWave.php`: replace
```php
    public function resolve(
        RecommendationRun $run,
        AiProviderSettings $settings,
        EffectiveRecommendationSettings $effectiveSettings,
        int $userId,
        int $waveSize,
        RetryPlan $plan,
    ): BatchWaveResult {
        $waveBatches = $this->waveBatches($run, $userId, $waveSize);
```
with
```php
    public function resolve(TickContext $tick, int $waveSize): BatchWaveResult
    {
        $run = $tick->run;
        $settings = $tick->connection;
        $effectiveSettings = $tick->settings;
        $userId = $tick->userId();
        $plan = $tick->retryPlan();
        $waveBatches = $this->waveBatches($run, $userId, $waveSize);
```
Nothing else in the file changes here; B2 rewrites it and removes its suppression.

- [ ] **Step 5: The hand-built advancer in the worker handler test**

`tests/Service/Worker/AdvanceRecommendationRunsHandlerTest.php`:
- Replace the method `advancerWithFlushFailingEntityManager()` together with its docblock (from `     * Every RecommendationRunAdvancer collaborator except the EntityManager` back to its `/**`, through the method's closing brace) with:
```php
    /**
     * Only the advancer's own EntityManager fails its first flush: that is the struggling run's fail() write. The
     * phases come from the container, so the healthy run banks through the real EntityManager.
     */
    private function advancerWithFlushFailingEntityManager(): RecommendationRunAdvancer
    {
        return new RecommendationRunAdvancer(
            $this->runs(),
            self::getContainer()->get(LockFactory::class),
            self::getContainer()->get(AiProviderConfigurator::class),
            $this->connectionFactory(),
            self::getContainer()->get(ClockInterface::class),
            new FlushFailingEntityManager($this->em),
            self::getContainer()->get(RecommendationSettingsResolver::class),
            self::getContainer()->get(TickLockKeepalive::class),
            self::getContainer()->get(TickPhases::class),
        );
    }
```
- Delete the imports the old helper alone used, and add `TickPhases`:
```bash
perl -ni -e 'print unless m{^use App\\(?:Repository\\EntryRepository|Service\\Recommendation\\(?:Run|Prompt)\\(?:RecommendationBatchWave|RecommendationCandidateLoader|RecommendationConsolidationResolver|RecommendationHistoryLoader|RecommendationProfileDistiller|RecommendationPromptBuilder|RecommendationRunDeferral|RecommendationRunFinalizer|RecommendationTickCheckpoint|RecommendationTransportFailureRecorder|RecommendationWaveConcurrency));$}' tests/Service/Worker/AdvanceRecommendationRunsHandlerTest.php
perl -pi -e 's/^(use App\\Service\\Recommendation\\Run\\TickLockKeepalive;\n)/$1use App\\Service\\Recommendation\\Run\\TickPhases;\n/' tests/Service/Worker/AdvanceRecommendationRunsHandlerTest.php
git grep -c "RecommendationBatchWave\|RecommendationRunFinalizer\|EntryRepository\|TickPhases" -- tests/Service/Worker/AdvanceRecommendationRunsHandlerTest.php
```
Expected: `2` (the `TickPhases` import and its use). At `89c7e17f` each deleted name appeared only as its import and once in the old helper (`RecommendationRunFinalizer` also in its comment).

`config/services_test.yaml`, directly after the `App\Service\Recommendation\Run\RecommendationRunAdvancer:` entry (its `autowire: true` and `public: true` lines), add:
```yaml

    # AdvanceRecommendationRunsHandlerTest builds an advancer by hand around the container's phases. The advancer
    # is their only user, so without this the compiler would inline them away.
    App\Service\Recommendation\Run\TickPhases:
        autowire: true
        public: true
```

- [ ] **Step 6: Bring the docblocks that name the old advancer members up to date (D15)**

Each replacement below is the whole docblock.
- `src/Entity/AiProviderSettings.php`, the docblock of `MAX_BATCH_CONCURRENCY`:
```php
    /**
     * The hard ceiling on one tick's wave of provider calls; the default stays 1. Only the worker reaches it:
     * a poll or sweep tick clamps to BatchPhase::POLL_MAX_CONCURRENCY.
     */
```
- `src/Entity/RunTuning.php`, the class docblock:
```php
/**
 * How a run drives this connection: `slowModel` picks the timeout profile and the lock TTL, `batchConcurrency`
 * sizes a tick's wave (BatchPhase::effectiveCap()), `maxBatchSize` caps a batch (null keeps the default, #445).
 */
```
- `src/Service/Recommendation/Run/ForYouSweep.php`, the class docblock:
```php
/**
 * Scheduled "For you" (#333): startDueRuns() for the worker; sweepOnce() for the cron, which also advances each
 * active run one Sweep tick. While sweeping it holds its own liveness key (#439), surrendered when the sweep ends.
 */
```
- `src/Service/Recommendation/Run/ProfileDistillationOutcome.php`: the class docblock becomes
```php
/** What a distillation call settled to: the profile text, or the unusable reply DistillationPhase retries (#493). */
```
and the docblock of `requireUnusableReply()` is deleted.
- `src/Service/Recommendation/Run/RecommendationRunDeferral.php`, the class docblock:
```php
/**
 * The ending of a 429 the tick will not wait out (#947): retry not before now plus the wait. A deferral is a wait,
 * not a failure, so it strikes nothing against the transport-failure ceiling.
 */
```
- `src/Service/Recommendation/Run/RecommendationWaveConcurrency.php`, the class docblock:
```php
/** The run's wave concurrency against its connection's ceiling: a 429 halves what BatchPhase reads back (#947). */
```
- `src/Service/Recommendation/Run/WaveBatch.php`, the class docblock:
```php
/**
 * One batch of the frozen plan in a wave (#344): its plan position, its snapshot-order ids, and the prompt lines
 * those ids still resolve to. A batch pruned to nothing resolves to no winners without a provider call.
 */
```

- [ ] **Step 7: Run the tests to verify they pass**

```bash
bin/console cache:clear && bin/console cache:warmup
php bin/phpunit tests/Service/Recommendation tests/Service/Worker tests/Command/RecommendationDrainCommandTest.php tests/Controller/Api/RecommendationRunControllerTest.php
```
Expected: PASS.

- [ ] **Step 8: Deletion checks**

Restore each by hand before the next.
1. In `TickPhases::advanceWithinTheEnvelope()`, delete the `$this->transportFailures->record(...)` line. Expected: `RecommendationRunAdvancerTest::testTransportFailureDuringDistillCallCountsTheCeilingAndKeepsRunRunning`, `testTransportFailureDuringConsolidateCallCountsTheCeilingAndKeepsRunRunning` and `testTransportFailureInWaveAdvancesNothingAndIncrementsCeilingOnce` fail.
2. In `BatchPhase::resolveWave()`, delete the `halve()` call inside the `catch`. Expected: `testAPollBatchWaveDefersAndHalvesTheConcurrencyOnA429` fails.
3. In `TickContext::model()`, return `''`. Expected: `TickContextTest::testTheModelIsTheConnectionsChosenModel` and `RecommendationProfileDistillerTest::testTheRecordedCallCarriesTheConfiguredModel` fail.
4. In `BatchPhase::effectiveCap()`, return `$cap` without the floor. Expected: `testZeroBatchConcurrencyStillAdvancesOneBatch` fails.

- [ ] **Step 9: Gates**

Run `composer check` and `composer md`. Expected: clean; `composer md | grep -c "RecommendationRunAdvancer"` prints `0`, and no file under `src/Service/Recommendation/Run` is reported. `git grep -n "SuppressWarnings" -- src/Service/Recommendation/Run/RecommendationRunAdvancer.php` prints nothing. `composer tramp`: no chain through `TickContext`. PhpStorm `lint_files` on every changed file.

- [ ] **Step 10: Commit**

```bash
git add src/Service/Recommendation/Run src/Entity/AiProviderSettings.php src/Entity/RunTuning.php config/services_test.yaml tests/Service/Recommendation/Run/TickContextTest.php tests/Service/Recommendation/Run/RecommendationProfileDistillerTest.php tests/Service/Recommendation/Run/RecommendationConsolidationResolverTest.php tests/Service/Worker/AdvanceRecommendationRunsHandlerTest.php
git commit -m "refactor(#1162): a tick builds one TickContext and runs its phase inside one catch envelope"
```

---

### Task B2: `WaveContext`

**Files:**
- Create: `src/Service/Recommendation/Run/WaveContext.php`, `src/Service/Recommendation/Run/WaveContextLoader.php`
- Modify (rewritten): `src/Service/Recommendation/Run/RecommendationBatchWave.php`
- Modify: `src/Service/Recommendation/Run/BatchPhase.php` (constructor, `resolveWave()`)
- Test: `tests/Service/Recommendation/Run/WaveContextLoaderTest.php` (new)

**Interfaces:**
- Consumes: `TickContext` (B1).
- Produces:
  - `final readonly class WaveContext { public TickContext $tick; /** list<WaveBatch> */ public array $batches; public ?CandidatePoolSummary $poolSummary; public RecommendationHistory $history; public ?string $profile }`.
  - `WaveContextLoader::load(TickContext $tick, int $waveSize): WaveContext`.
  - `RecommendationBatchWave::resolve(WaveContext $wave): BatchWaveResult`; its constructor drops the two loaders (7 parameters).

- [ ] **Step 1: Write the failing test**

`tests/Service/Recommendation/Run/WaveContextLoaderTest.php`:
```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Recommendation\Run;

use App\Entity\Entry;
use App\Entity\RecommendationRun;
use App\Entity\User;
use App\Service\Ai\Crypto\ApiKeyCipher;
use App\Service\Recommendation\Prompt\RecommendationCandidateLoader;
use App\Service\Recommendation\Prompt\RecommendationHistoryLoader;
use App\Service\Recommendation\Run\TickContext;
use App\Service\Recommendation\Run\TickDriver;
use App\Service\Recommendation\Run\WaveBatch;
use App\Service\Recommendation\Run\WaveContextLoader;
use App\Service\Recommendation\Settings\RecommendationSettingsResolver;
use App\Tests\DbTestCase;
use App\Tests\Support\RecommendationRunFixtures;
use App\Tests\Support\SeedsUsers;

final class WaveContextLoaderTest extends DbTestCase
{
    use SeedsUsers;

    private User $owner;
    private RecommendationRunFixtures $fixtures;

    protected function setUp(): void
    {
        parent::setUp();

        /** @var ApiKeyCipher $cipher */
        $cipher = self::getContainer()->get(ApiKeyCipher::class);
        $this->fixtures = new RecommendationRunFixtures($this->em, $cipher);
        $this->owner = $this->user('wave-context@example.test');
        $this->fixtures->seedReadyAiSettings($this->owner);
    }

    public function testTheWaveTakesTheNextBatchesFramedByTheWholePool(): void
    {
        $ids = $this->entryIds(6);
        $run = $this->runWithPlan([[$ids[0], $ids[1]], [$ids[2], $ids[3]], [$ids[4], $ids[5]]]);
        $run->recordProfile('Likes Rust.');
        $run->recordBatchWinners([]);
        $this->em->flush();

        $wave = $this->loader()->load($this->tick($run), 2);

        self::assertSame([1, 2], array_map(static fn (WaveBatch $batch): int => $batch->index, $wave->batches));
        self::assertSame([$ids[2], $ids[3]], $wave->batches[0]->ids);
        self::assertEqualsCanonicalizing([$ids[4], $ids[5]], $wave->batches[1]->validIds());
        self::assertSame(6, $wave->poolSummary?->total);
        self::assertSame('Likes Rust.', $wave->profile);
        self::assertSame($run, $wave->tick->run);
    }

    public function testAnEntryPrunedSinceTheSnapshotLeavesItsBatchButNotThePlan(): void
    {
        $ids = $this->entryIds(2);
        $run = $this->runWithPlan([[$ids[0], $ids[1]]]);
        $pruned = $this->em->getRepository(Entry::class)->find($ids[1]);
        self::assertNotNull($pruned);
        $this->em->remove($pruned);
        $this->em->flush();

        $wave = $this->loader()->load($this->tick($run), 1);

        self::assertSame([$ids[0], $ids[1]], $wave->batches[0]->ids);
        self::assertSame([$ids[0]], $wave->batches[0]->validIds());
        self::assertSame(1, $wave->poolSummary?->total);
    }

    /** @return list<int> */
    private function entryIds(int $count): array
    {
        return array_map(
            static fn (Entry $entry): int => $entry->requireId(),
            $this->fixtures->seedFeedWithEntries($this->owner, $count),
        );
    }

    /** @param list<list<int>> $plan */
    private function runWithPlan(array $plan): RecommendationRun
    {
        $run = $this->fixtures->createRun($this->owner);
        $run->snapshot($plan);
        $this->em->flush();

        return $run;
    }

    private function tick(RecommendationRun $run): TickContext
    {
        $connection = $this->owner->getActiveAiProviderSettings();
        self::assertNotNull($connection);
        /** @var RecommendationSettingsResolver $settings */
        $settings = self::getContainer()->get(RecommendationSettingsResolver::class);

        return new TickContext($run, $connection, $settings->forUser($this->owner), TickDriver::Worker);
    }

    private function loader(): WaveContextLoader
    {
        /** @var RecommendationCandidateLoader $candidates */
        $candidates = self::getContainer()->get(RecommendationCandidateLoader::class);
        /** @var RecommendationHistoryLoader $history */
        $history = self::getContainer()->get(RecommendationHistoryLoader::class);

        return new WaveContextLoader($candidates, $history);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php bin/phpunit tests/Service/Recommendation/Run/WaveContextLoaderTest.php`
Expected: FAIL: `Class "App\Service\Recommendation\Run\WaveContextLoader" not found`. (The loader is built by hand from the two loaders `config/services_test.yaml` already makes public; only `BatchPhase` uses it, so the container would inline it.)

- [ ] **Step 3: Implement**

`src/Service/Recommendation/Run/WaveContext.php`:
```php
<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Run;

use App\Service\Recommendation\Prompt\CandidatePoolSummary;
use App\Service\Recommendation\Prompt\RecommendationHistory;

final readonly class WaveContext
{
    /** @param list<WaveBatch> $batches the plan's next batches, in plan order */
    public function __construct(
        public TickContext $tick,
        public array $batches,
        public ?CandidatePoolSummary $poolSummary,
        public RecommendationHistory $history,
        public ?string $profile,
    ) {
    }
}
```

`src/Service/Recommendation/Run/WaveContextLoader.php`:
```php
<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Run;

use App\Service\Recommendation\Prompt\RecommendationCandidateLoader;
use App\Service\Recommendation\Prompt\RecommendationHistoryLoader;

final readonly class WaveContextLoader
{
    public function __construct(
        private RecommendationCandidateLoader $candidateLoader,
        private RecommendationHistoryLoader $historyLoader,
    ) {
    }

    /** The pool summary spans the whole frozen plan: every batch shares one frame, not its own few dates (#344). */
    public function load(TickContext $tick, int $waveSize): WaveContext
    {
        $run = $tick->run;

        return new WaveContext(
            $tick,
            $this->nextBatches($tick, $waveSize),
            $this->candidateLoader->summarize($tick->userId(), array_merge(...$run->getCandidateBatches())),
            $this->historyLoader->load($tick->userId(), $tick->settings),
            $run->getProfileText(),
        );
    }

    /**
     * One linesForIds() round trip for the whole wave, split back per batch; an entry pruned since the snapshot
     * is simply absent from its batch's lines.
     *
     * @return list<WaveBatch>
     */
    private function nextBatches(TickContext $tick, int $waveSize): array
    {
        $startIndex = $tick->run->progress()->nextBatchIndex;
        $candidateBatches = $tick->run->getCandidateBatches();

        $idsByPosition = [];
        for ($index = $startIndex; $index < $startIndex + $waveSize; $index++) {
            $idsByPosition[$index] = $candidateBatches[$index];
        }

        $linesById = $this->candidateLoader->linesForIds($tick->userId(), array_merge(...array_values($idsByPosition)));

        $batches = [];
        foreach ($idsByPosition as $index => $ids) {
            $batches[] = new WaveBatch($index, $ids, array_intersect_key($linesById, array_flip($ids)));
        }

        return $batches;
    }
}
```

`src/Service/Recommendation/Run/RecommendationBatchWave.php` (rewritten in full; the methods keep their code, `waveBatches()` and `allCandidateIds()` moved to the loader, the suppression goes):
```php
<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Run;

use App\Entity\RecommendationRun;
use App\Entity\RecommendationRunLog;
use App\Service\Ai\Completion\CompletionOutcome;
use App\Service\Ai\Completion\ConcurrentCompletion;
use App\Service\Ai\Completion\RateLimitedCompletion;
use App\Service\Ai\Completion\RateLimitedResult;
use App\Service\Ai\Exception\ProviderRateLimitedException;
use App\Service\Ai\ProviderConnectionFactory;
use App\Service\Recommendation\Prompt\PromptLine;
use App\Service\Recommendation\Prompt\RecommendationCompletionRequestFactory;
use App\Service\Recommendation\Prompt\RecommendationPick;
use App\Service\Recommendation\Prompt\RecommendationPickParser;
use App\Service\Recommendation\Prompt\RecommendationPromptBuilder;
use App\Service\Recommendation\Prompt\RecommendationPromptText;
use App\Service\Recommendation\Prompt\RecommendationResponseSchema;

/**
 * The batch phase's concurrent fan-out (#344): an unusable batch retries alone up to MAX_ATTEMPTS rounds, then
 * yields no winners. A transport failure settles every open call and banks nothing, the atomic-wave rule; a
 * deferring plan's 429 throws ProviderRateLimitedException instead (#947).
 */
final readonly class RecommendationBatchWave
{
    public function __construct(
        private RateLimitedCompletion $completion,
        private ProviderConnectionFactory $connections,
        private RecommendationCallRecorder $callRecorder,
        private RecommendationPromptBuilder $promptBuilder,
        private RecommendationPickParser $parser,
        private RecommendationCompletionRequestFactory $requestFactory,
        private RecommendationTickCheckpoint $checkpoint,
    ) {
    }

    /**
     * @throws \App\Service\Ai\Exception\ProviderUnreachableException
     * @throws \App\Service\Ai\Exception\CredentialsRejectedException
     * @throws \App\Service\Ai\Exception\RetryableProviderException
     * @throws ProviderRateLimitedException
     */
    public function resolve(WaveContext $wave): BatchWaveResult
    {
        $correctiveReply = [];
        $rateLimitObserved = false;
        [$winners, $pending] = $this->splitByPruned($wave->batches);

        for ($round = 1; [] !== $pending; $round++) {
            $roundResult = $this->sendRound($wave, $pending, $correctiveReply);
            $rateLimitObserved = $rateLimitObserved || $roundResult['observed'];
            $pending = [];
            foreach ($roundResult['replies'] as $position => $reply) {
                $result = $this->parser->parse($reply['content'], $wave->batches[$position]->validIds());
                $reply['call']->settle($reply['content'], $result->usable);
                if ($result->usable) {
                    $winners[$position] = self::asWinners($result->picks);

                    continue;
                }
                $correctiveReply[$position] = $reply['content'];
                $pending[] = $position;
            }

            $this->checkpoint->guard($wave->tick->run);
            if ([] === $pending || $round >= RecommendationRun::MAX_ATTEMPTS) {
                break;
            }
        }

        return new BatchWaveResult($this->degradeUnresolved($winners, $pending), $rateLimitObserved);
    }

    /**
     * A fully pruned batch resolves free to no winners; every other batch is a pending position.
     *
     * @param list<WaveBatch> $waveBatches
     *
     * @return array{0: array<int, list<array{id: int, score: int, reason: string}>>, 1: list<int>}
     */
    private function splitByPruned(array $waveBatches): array
    {
        $winners = [];
        $pending = [];
        foreach ($waveBatches as $position => $waveBatch) {
            if ($waveBatch->isFullyPruned()) {
                $winners[$position] = [];

                continue;
            }
            $pending[] = $position;
        }

        return [$winners, $pending];
    }

    /**
     * @param array<int, list<array{id: int, score: int, reason: string}>> $winners
     * @param list<int>                                                    $stillUnresolved
     *
     * @return list<list<array{id: int, score: int, reason: string}>>
     */
    private function degradeUnresolved(array $winners, array $stillUnresolved): array
    {
        foreach ($stillUnresolved as $position) {
            $winners[$position] = [];
        }
        ksort($winners);

        return array_values($winners);
    }

    /**
     * One round: a recorded call per pending batch, each with its own corrective tail, read concurrently.
     *
     * @param non-empty-list<int> $pending         positions into the wave still awaiting a usable reply
     * @param array<int, string>  $correctiveReply each position's own last invalid reply
     *
     * @return array{replies: array<int, array{content: string, call: RecordedCall}>, observed: bool}
     */
    private function sendRound(WaveContext $wave, array $pending, array $correctiveReply): array
    {
        $tick = $wave->tick;
        $calls = [];
        $recordedCalls = [];
        foreach ($pending as $position) {
            $waveBatch = $wave->batches[$position];
            $messages = $this->batchMessages($wave, $waveBatch, $correctiveReply[$position] ?? null);
            $recordedCall = $this->callRecorder->begin(
                $tick->run,
                RecommendationRunLog::PHASE_BATCH,
                $waveBatch->index + 1,
                $messages,
                $tick->model(),
            );
            $calls[] = new ConcurrentCompletion(
                $this->requestFactory->create(
                    $tick->connection,
                    $messages,
                    \count($waveBatch->validIds()),
                    RecommendationResponseSchema::BatchScore,
                ),
                $recordedCall,
            );
            $recordedCalls[] = $recordedCall;
        }

        $result = $this->completeRound($tick, $calls, $recordedCalls);

        if ($result->isDeferred()) {
            foreach ($recordedCalls as $recordedCall) {
                $recordedCall->abortAfterTransportFailure('Provider rate limited; deferring.');
            }

            throw new ProviderRateLimitedException($result->deferSeconds);
        }

        $outcomes = $result->outcomes;
        $this->guardWaveTransport($recordedCalls, $outcomes);

        return [
            'replies' => $this->repliesByPosition($pending, $outcomes, $recordedCalls),
            'observed' => $result->rateLimitObserved,
        ];
    }

    /**
     * A throw here means no call got a reply (an unreadable key, say): every opened row is settled first, so none
     * reads as "still streaming", then the error propagates unchanged (#344).
     *
     * @param non-empty-list<ConcurrentCompletion> $calls
     * @param list<RecordedCall>                   $recordedCalls
     */
    private function completeRound(TickContext $tick, array $calls, array $recordedCalls): RateLimitedResult
    {
        try {
            return $this->completion->completeMany(
                $this->connections->forSettings($tick->connection),
                $calls,
                $tick->retryPlan(),
            );
        } catch (\Throwable $e) {
            foreach ($recordedCalls as $recordedCall) {
                $recordedCall->abortAfterTransportFailure($e->getMessage());
            }

            throw $e;
        }
    }

    /**
     * The atomic-wave rule (#344): one transport failure settles every call of the round and banks none of it. A
     * healthy sibling's answer is discarded and re-billed next tick; that cost is accepted, not a bug.
     *
     * @param list<RecordedCall>      $recordedCalls
     * @param list<CompletionOutcome> $outcomes
     */
    private function guardWaveTransport(array $recordedCalls, array $outcomes): void
    {
        $firstFailure = $this->firstFailureIn($outcomes);
        if (null === $firstFailure) {
            return;
        }

        foreach ($outcomes as $position => $outcome) {
            $recordedCalls[$position]->abortAfterTransportFailure(self::abortDetailFor($outcome, $firstFailure));
        }

        throw $firstFailure;
    }

    /** A call with its own cause, a spoiled reply included, names it; only a bystander borrows the wave's (#437). */
    private static function abortDetailFor(CompletionOutcome $outcome, \Throwable $waveFailure): string
    {
        return $outcome->hasCause() ? $outcome->cause()->getMessage() : $waveFailure->getMessage();
    }

    /** @param list<CompletionOutcome> $outcomes */
    private function firstFailureIn(array $outcomes): ?\Throwable
    {
        foreach ($outcomes as $outcome) {
            if ($outcome->isFailure()) {
                return $outcome->cause();
            }
        }

        return null;
    }

    /**
     * @param list<int>               $pending       positions into the wave, in call order
     * @param list<CompletionOutcome> $outcomes      one per call, aligned to $pending
     * @param list<RecordedCall>      $recordedCalls one per call, aligned to $pending
     *
     * @return array<int, array{content: string, call: RecordedCall}>
     */
    private function repliesByPosition(array $pending, array $outcomes, array $recordedCalls): array
    {
        $replies = [];
        foreach ($pending as $callIndex => $position) {
            // content() covers a spoiled reply too: the partial answer the parser judges and the retry quotes back.
            $replies[$position] = [
                'content' => $outcomes[$callIndex]->content(),
                'call' => $recordedCalls[$callIndex],
            ];
        }

        return $replies;
    }

    /** @return list<array{role: string, content: string}> */
    private function batchMessages(WaveContext $wave, WaveBatch $waveBatch, ?string $lastInvalidReply): array
    {
        $messages = $this->promptBuilder->batchMessages(
            $wave->history,
            $this->linesInSnapshotOrder($waveBatch),
            $wave->tick->settings,
            $wave->profile,
            $wave->poolSummary,
        );

        return $this->promptBuilder->messagesWithCorrectiveTail(
            $messages,
            $lastInvalidReply,
            RecommendationPromptText::CORRECTIVE,
        );
    }

    /** @return list<PromptLine> */
    private function linesInSnapshotOrder(WaveBatch $waveBatch): array
    {
        $present = array_filter($waveBatch->ids, static fn (int $id): bool => isset($waveBatch->linesById[$id]));

        return array_values(array_map(static fn (int $id): PromptLine => $waveBatch->linesById[$id], $present));
    }

    /**
     * @param list<RecommendationPick> $picks
     *
     * @return list<array{id: int, score: int, reason: string}>
     */
    private static function asWinners(array $picks): array
    {
        return array_map(
            static fn (RecommendationPick $pick): array => [
                'id' => $pick->entryId,
                'score' => $pick->score,
                'reason' => $pick->reason,
            ],
            $picks,
        );
    }
}
```

`src/Service/Recommendation/Run/BatchPhase.php`:
- The constructor becomes
```php
    public function __construct(
        private WaveContextLoader $waves,
        private RecommendationBatchWave $batchWave,
        private RecommendationWaveConcurrency $waveConcurrency,
        private EntityManagerInterface $entityManager,
    ) {
    }
```
- In `resolveWave()`, replace `$result = $this->batchWave->resolve($tick, $this->waveSize($tick));` with
```php
            $result = $this->batchWave->resolve($this->waves->load($tick, $this->waveSize($tick)));
```

- [ ] **Step 4: Run the tests to verify they pass**

```bash
bin/console cache:clear && bin/console cache:warmup
php bin/phpunit tests/Service/Recommendation tests/Service/Worker tests/Command/RecommendationDrainCommandTest.php
```
Expected: PASS.

- [ ] **Step 5: Deletion checks**

Restore each by hand.
1. In `WaveContextLoader::load()`, pass `null` for the pool summary. Expected: `testTheWaveTakesTheNextBatchesFramedByTheWholePool` fails.
2. In `WaveContextLoader::nextBatches()`, loop to `$index <= $startIndex + $waveSize`. Expected: `testTheWaveTakesTheNextBatchesFramedByTheWholePool` fails (a third batch, or an undefined offset).

- [ ] **Step 6: Gates**

`composer check`, `composer md` (no finding for `RecommendationBatchWave`; `git grep -n SuppressWarnings -- src/Service/Recommendation/Run` prints nothing), `composer tramp` (no `$waveSize`/`$tick` chain), PhpStorm lint on the changed files.

- [ ] **Step 7: Commit**

```bash
git add src/Service/Recommendation/Run tests/Service/Recommendation/Run/WaveContextLoaderTest.php
git commit -m "refactor(#1162): a wave loads one WaveContext and the batch wave takes it"
```

---

### Finishing PR B

- [ ] **Step 1: Per-PR gates**

`php bin/phpunit`, `docker compose exec php composer test`, `composer check`, `composer md`, `composer infection:diff`, PhpStorm lint on all changed PHP. Expected: green; `infection:diff` at or above `minMsi` for the new and rewritten `Run` files. An escaped mutant in a moved line: write the test that kills it, do not lower `minMsi`.

- [ ] **Step 2: Real run**

Run the **Real run** procedure. This PR rewired the whole tick, so read step 6's log closely: one `distill`, one row per batch with the first wave a single call, one `consolidate`.

- [ ] **Step 3: PR**

```bash
git push -u origin refactor/1162-tick-and-wave-contexts
gh pr create --base develop --title "refactor(#1162): one tick context, one catch envelope, one wave context" --body "$(cat <<'EOF'
Refs #1162 (PR B of 4).

- `TickContext(run, connection, settings, driver)` is built once per tick; `userId()`, `model()`, `retryPlan()` derive.
- The advancer keeps the lock and the configuration ending; `TickPhases` picks `SnapshotPhase` or a `ProviderPhase` (`DistillationPhase`, `BatchPhase`, `ConsolidationPhase`) and runs it inside one catch envelope.
- `WaveContext` is loaded once per wave; `RecommendationBatchWave` methods take at most three parameters.
- Both `@SuppressWarnings("PHPMD.ExcessiveParameterList")` are gone.

Wire: none. Behaviour: none. Real run: <paste steps 3, 5, 6 and 7>.
EOF
)"
```


### Execution rulings (PR B)

- **Preflight (opus scan, dry run on a scratch clone):**
  - Tramp flagged `$driver` for 3 hops, so `tickActiveRun()` becomes `activeConnection(User): AiProviderSettings`, and `tick()` builds the `TickContext`.
  - Finishing's mutation expectation becomes "≥ `minMsi`, every escaped mutant classed as equivalent or killed". B1 adds a test showing the first batch is marked started before its provider call.
  - The planned test for the distillation request's `replyItemCount` 1 is dropped. The Distillation arm of `answerBoundTokens()` ignores the count, so 0, 1 and 2 build the same request.
  - `ConsolidationOutcome`'s docblocks get the same rewrite as their twin `ProfileDistillationOutcome` (D15). `TickContextTest` gets a deletion check on `retryPlan()`.
- **Zero-concurrency floor (B1f and the fix wave):** the old `max(1, $cap)` sat before the wave-concurrency cap, so it never applied. From wave 2 on, `cap()` still returned 0 and stalled the run. This was present before PR B, and the API cannot store 0.
  - The fix puts the floor with the owners of the numbers: `RecommendationWaveConcurrency::cap()` and `BatchPhase::effectiveCap()` each return at least 1, and `waveSize()` is a plain min.
  - The tests: a `cap()` unit test, a poll-path advancer test, and a worker two-tick regression test.
  - Behaviour change: a concurrency ≤ 0 stored directly in the database no longer stalls wave 2 and later.
- **Reviews:**
  - The `BatchPhase` flush before the provider call stays. It lets a status poll see `first_batch_started` while the wave is still loading and packing.
  - The `tick($run)` test helper had three copies and is now one trait, `BuildsTickContexts`.
  - `RateLimitedCompletion`'s docblock now describes a blocking plan and a deferring plan, in transport terms.
  - Rejected (`/simplify`):
    - Dropping `WaveContext::$profile` (D19 carries a `PromptContext` there).
    - A keyed locator for phase choice (the choice is ordered run-state predicates, not a key).
    - A shared exception list for the halve pre-catch and the envelope (the envelope must tell the two apart).
  - Carried to PR C:
    - `linesInSnapshotOrder()` moves to `WaveBatch` in C5.
    - C3 checks whether the Distillation/Consolidation retry-or-degrade skeleton still has two copies.
- **Gates:** each tick now makes one extra read-only settings read, including a deferred tick that used to skip it.
- **Real run:** run 125 as user 2 (`qwen/qwen3.7-flash`): completed, 6/6 batches, 0 transport failures, every call usable on attempt 1, dev log clean.

---

# PR C — one answer budget, recorded calls without flags (`Refs #1162`)

### Task C0: Preflight (PR B merged)

**Files:** none.

- [ ] **Step 1: PR B is on `develop`**

```bash
git fetch origin
git grep -n "SuppressWarnings" origin/develop -- src/Service/Recommendation/Run
git ls-tree --name-only origin/develop -- src/Service/Recommendation/Run/TickContext.php src/Service/Recommendation/Run/WaveContext.php
```
Expected: the grep prints nothing; both paths print.

- [ ] **Step 2: Branch and re-take the sites**

```bash
git status --short && git branch --show-current
git switch -c refactor/1162-answer-budget-and-recorded-calls origin/develop
git grep -n "FAVORITES (newest first)" -- src
git grep -n "TOKENS_PER_SCORE_PICK\|ANSWER_BOUND_PERCENT\|MINIMUM_ANSWER_TOKENS" -- src
git grep -n -e "->settle(" -- src tests
git grep -n -e "->finish(" -- src tests
git grep -n "suppressesReasoning\|->suppressReasoning" -- src/Service tests/Support tests/Service/Ai tests/Service/Recommendation
git grep -n -e "->begin(" -e "->create(" -- src/Service/Recommendation tests/Service/Recommendation
```
Expected (re-checked at `5229c947`, the same as at `89c7e17f`; paths after PR A/B):
- `FAVORITES (newest first)`: five lines, all in `Prompt/RecommendationPromptBuilder.php` (`packBatches`, `consolidationInputSize`, `batchUserSections`, `consolidationMessages`, `historySections`).
- The three constants: declared and used in `RecommendationPromptBuilder` and declared and used in `RecommendationAnswerBudget`.
- `->settle(`: `RecommendationBatchWave`, `RecommendationConsolidationResolver`, `RecommendationProfileDistiller`, and seven calls in `RecordedCallTest`.
- `->finish(`: `src/Http/BackupDownloadResponseFactory.php` (`$zip`), `src/Service/Backup/EntryPartRestorer.php` (`$loader`), `Run/RecordedCall.php` (2, `$this->finish(` into its own private method, which C3 keeps), none on a run log; in `tests`: `RecommendationDebugLogControllerTest` (2), `RecommendationRunLogTest` (1), `RecommendationRunLogRepositoryTest` (2), `RecommendationRunTimingRepositoryTest` (1), `RecommendationEtaEstimatorTest` (1), `RestoreEntryLoaderTest` (2, unrelated). This is D13's evidence: no `src` caller of `RecommendationRunLog::finish()`.
- `suppressesReasoning`/`->suppressReasoning` (C5): `Prompt/RecommendationAnswerBudget`, `Prompt/RecommendationCompletionRequestFactory`, `Prompt/RecommendationPromptBuilder`, `Run/RecommendationConsolidationResolver`, `Ai/Completion/OpenAiCompatibleChatClient`, `tests/Support/StubChatClient`, `RecommendationCompletionRequestFactoryTest`, `RecommendationPromptBuilderTest` (4 calls), `RecommendationRunAdvancerTest` (1). The admin paths (`AiSettingsJson`, `AiConfigurationEditor`, `AiProviderConfigurator`) read the entity's stored setting and stay.
- `->begin(` and `->create(` (C6): the distiller, the resolver and the batch wave once each; `RecommendationCallRecorderTest` ten `begin` calls; `RecommendationCompletionRequestFactoryTest` two `create` calls. Every other hit is a user or run factory's `->create(` (`UserFactory`, `$factory->create(`), not the request factory.

---

### Task C1: The batch reply reserve is `RecommendationAnswerBudget`'s bound

The packer re-derived the reply bound inline as `max(1024, ⌊cap × 15 × 1.5⌋)`; the provider is given `⌊max(1024, cap × 15) × 1.5⌋`. They differ below a cap of 69. The packer now reserves the provider's bound (D11).

**Files:**
- Modify: `src/Service/Recommendation/Prompt/RecommendationPromptBuilder.php` (three constants go, two lines of `packBatches()`)
- Modify: `src/Service/Recommendation/Prompt/RecommendationAnswerBudget.php` (four docblocks)
- Test: `tests/Service/Recommendation/Prompt/RecommendationPromptBuilderTest.php` (one test)

**Interfaces:**
- Consumes: `RecommendationAnswerBudget::answerBoundTokens(int $replyItemCount, RecommendationResponseSchema $schema): int` (unchanged).
- Produces: nothing new. `RecommendationPromptBuilder` holds no answer-bound constant.

- [ ] **Step 1: Write the failing test**

`tests/Service/Recommendation/Prompt/RecommendationPromptBuilderTest.php`, add after `testResponseReserveDivisorIsExactlyOneHundred()`:
```php
    /**
     * Below the 1024-token floor the provider may spend the floor plus half (RecommendationAnswerBudget), and the
     * packer reserves exactly that: 3865 - 1500 overhead - 1536 bound - 709 profile and favorites = 120 tokens.
     */
    public function testTheBatchReplyReserveIsTheProvidersAnswerBound(): void
    {
        $candidates = array_map(
            static fn (int $id): PromptLine => new PromptLine($id, 'T', 'F', 'D', null),
            range(100, 129),
        );

        $batches = $this->builder->packBatches(
            $candidates,
            $this->emptyHistory(),
            $this->settings(3865, 1, maximumBatchSize: 50),
        );

        self::assertSame([20, 10], array_map('count', $batches));
    }
```
Each candidate line (`- [100] T — F — D`) is 21 bytes, 6 tokens; 120 tokens hold exactly 20. The old reserve (1125) left 531 tokens, room for all 30.

- [ ] **Step 2: Run the test to verify it fails**

Run: `php bin/phpunit tests/Service/Recommendation/Prompt/RecommendationPromptBuilderTest.php --filter testTheBatchReplyReserveIsTheProvidersAnswerBound`
Expected: FAIL: `Failed asserting that two arrays are identical.` with `[30]` actual.

- [ ] **Step 3: Implement**

`src/Service/Recommendation/Prompt/RecommendationPromptBuilder.php`:
- Delete
```php
    /**
     * What one score-only pick costs in a batch reply: `{"id":123,"score":843}`, no prose --
     * about a fifth of a reason-bearing pick, so packBatches fits more candidates per batch
     * and the run makes fewer calls (#493). Also lives on RecommendationAnswerBudget, which
     * prices the reply bound, a different computation; coupling the two for one shared
     * integer would cost more than the duplication.
     */
    private const int TOKENS_PER_SCORE_PICK = 15;

```
- Replace
```php
    /**
     * How much room over the estimate the provider is actually given. The estimate is a
     * mean; a reply that runs long is not a runaway and must not be truncated into one that
     * cannot parse. Half again covers the spread and still leaves the ceiling an order of
     * magnitude below the 33800 tokens that let a looping model generate for an hour.
     * Duplicated on RecommendationAnswerBudget for the same reason as TOKENS_PER_SCORE_PICK
     * (#493).
     */
    private const int ANSWER_BOUND_PERCENT = 150;

    /**
     * Duplicated on RecommendationAnswerBudget for the same reason as
     * TOKENS_PER_SCORE_PICK (#493).
     */
    private const int MINIMUM_ANSWER_TOKENS = 1024;
    private const int MINIMUM_BATCH_SIZE = 10;
```
with
```php
    private const int MINIMUM_BATCH_SIZE = 10;
```
- In `packBatches()`, replace
```php
        // The reply scores one line per candidate, so its size is bounded by
        // the batch cap, not by the final list size. The batch reply is
        // score-only (id + score, no reason), so it is charged the score-only
        // rate rather than the reason-bearing pick rate (#493).
        $responseReserve = intdiv($cap * self::TOKENS_PER_SCORE_PICK * self::ANSWER_BOUND_PERCENT, 100);
        $responseReserve = max(self::MINIMUM_ANSWER_TOKENS, $responseReserve);
```
with
```php
        $responseReserve = RecommendationAnswerBudget::answerBoundTokens(
            $cap,
            RecommendationResponseSchema::BatchScore,
        );
```
Both classes share the `Prompt` namespace; no import.

`src/Service/Recommendation/Prompt/RecommendationAnswerBudget.php`:
- The class docblock becomes
```php
/**
 * What the provider may spend answering, per phase. RecommendationPromptBuilder::packBatches() reserves this same
 * bound for a batch reply, so the packer and the provider cannot disagree.
 */
```
- The docblock of `TOKENS_PER_SCORE_PICK` becomes
```php
    /** A score-only batch pick, `{"id":123,"score":843}`: about a fifth of a reasoned one (#493). */
```
- The docblock of `ANSWER_BOUND_PERCENT` becomes
```php
    /**
     * Half again over the mean estimate: a long reply is not a runaway to truncate into one that cannot parse, and
     * the bound stays an order of magnitude below the 33800 tokens that let a looping model run an hour.
     */
```
- The docblock of `MINIMUM_ANSWER_TOKENS` is deleted (the constant stays).

- [ ] **Step 4: Run the tests to verify they pass**

Run: `php bin/phpunit tests/Service/Recommendation/Prompt tests/Service/Recommendation/Run/RecommendationRunAdvancerTest.php tests/Service/Recommendation/Run/RecommendationPipelineTest.php`
Expected: PASS. Every other packing test uses a batch cap of 100 or more, where both formulas agree; the advancer tests cap batches small but pack into a 32768-token window, where the cap binds first.

- [ ] **Step 5: Deletion check**

In `packBatches()`, replace the call with `1125` (the old reserve for this cap). Expected: the new test fails with `[30]`. Restore by hand.

- [ ] **Step 6: Gates and commit**

`composer check`, `composer md`, PhpStorm lint on the three files. Then:
```bash
git add src/Service/Recommendation/Prompt/RecommendationPromptBuilder.php src/Service/Recommendation/Prompt/RecommendationAnswerBudget.php tests/Service/Recommendation/Prompt/RecommendationPromptBuilderTest.php
git commit -m "refactor(#1162): the batch packer reserves the answer budget's own bound for the reply"
```

---

### Task C2: One FAVORITES section

**Files:**
- Modify: `src/Service/Recommendation/Prompt/RecommendationPromptBuilder.php`

**Interfaces:**
- Produces: `private function favoritesSection(RecommendationHistory $history, int $descriptionLength): string`. The literal `'FAVORITES (newest first):'` appears once.

The prompt text is pinned byte for byte by `testBatchMessagesReturnsTheExactRoleContentStructure`, `testDistillMessagesReturnsTheExactRoleContentStructure` and `testConsolidationMessagesReturnsTheExactRoleContentStructure`, and the packing arithmetic by the `packBatches` tests; no new test.

- [ ] **Step 1: Replace the five calls**

```bash
perl -pi -e 's/\$this->historySection\(\x27FAVORITES \(newest first\):\x27, \$history->favorites, \$descriptionLength\)/\$this->favoritesSection(\$history, \$descriptionLength)/g' src/Service/Recommendation/Prompt/RecommendationPromptBuilder.php
git grep -c "favoritesSection(\$history, \$descriptionLength)" -- src/Service/Recommendation/Prompt/RecommendationPromptBuilder.php
git grep -c "FAVORITES (newest first)" -- src/Service/Recommendation/Prompt/RecommendationPromptBuilder.php
```
Expected: `5`, then `0` (the second grep exits 1).

- [ ] **Step 2: Add the method**

Directly above the docblock `     * @param list<PromptLine> $lines` of `historySection()`, add:
```php
    private function favoritesSection(RecommendationHistory $history, int $descriptionLength): string
    {
        return $this->historySection('FAVORITES (newest first):', $history->favorites, $descriptionLength);
    }

```

- [ ] **Step 3: Tests, deletion check, gates, commit**

Run: `php bin/phpunit tests/Service/Recommendation/Prompt/RecommendationPromptBuilderTest.php`. Expected: PASS.
Deletion check: change the literal to `'FAVOURITES (newest first):'`. Expected: the three `...ExactRoleContentStructure` tests fail. Restore by hand.
`composer check`, `composer md` (the class stays under TooManyMethods and ExcessiveClassComplexity; a finding means stop and report), PhpStorm lint.
```bash
git add src/Service/Recommendation/Prompt/RecommendationPromptBuilder.php
git commit -m "refactor(#1162): the prompt builder renders the FAVORITES section in one place"
```

---

### Task C3: `RecordedCall::settle()` is split

`settle(string $content, bool $usable)` only dispatched to `finishUsable()`/`finishUnusable()`, which already exist. It goes; each caller calls the one it means (D12). A call is settled before the checkpoint guard runs, as before: a guard that throws first would leave the row "still streaming".

**Files:**
- Modify: `src/Service/Recommendation/Run/RecordedCall.php` (`settle()` goes)
- Modify: `src/Service/Recommendation/Run/RecommendationProfileDistiller.php`, `RecommendationConsolidationResolver.php`, `RecommendationBatchWave.php`
- Test: `tests/Service/Recommendation/Run/RecordedCallTest.php` (perl)
- Test: `tests/Service/Recommendation/Run/RecommendationProfileDistillerTest.php`, `RecommendationConsolidationResolverTest.php` (two tests each, one helper each, one docblock)

**Interfaces:**
- Produces: `RecordedCall`'s public surface is `streamProgressed()`, `finishUsable(string)`, `finishUnusable(string)`, `abortAfterTransportFailure(?string)`.

- [ ] **Step 1: Pin the verdict each single-call phase settles with**

`tests/Service/Recommendation/Run/RecommendationProfileDistillerTest.php`:
- Replace the docblock of `testACancellationDuringTheProviderCallStopsBeforeWritingTheProfile()`
```php
    /**
     * A tick already inside the provider call cannot be interrupted, but the
     * checkpoint after settle() must still stop it from writing a profile for
     * a run the user cancelled while the call was in flight — otherwise a
     * cancelled run keeps quietly advancing.
     */
```
with
```php
    /**
     * A tick inside the provider call cannot be interrupted, but the checkpoint after the call settles must stop it
     * writing a profile for a run the user cancelled meanwhile.
     */
```
- Add after `testUnusableReplyReturnsUnusableAndDoesNotTouchSettings()`:
```php
    public function testAUsableReplySettlesItsCallAsUsable(): void
    {
        $this->stubChatClient()->queueContent('{"profile":"Likes Rust and homelab."}');
        $run = $this->runInRunningState();

        $this->distiller()->distill($this->tick($run));

        self::assertSame(['usable'], $this->verdictsOf($run));
    }

    public function testAnUnusableReplySettlesItsCallAsUnusable(): void
    {
        $this->stubChatClient()->queueContent('not json');
        $run = $this->runInRunningState();

        $this->distiller()->distill($this->tick($run));

        self::assertSame(['unusable'], $this->verdictsOf($run));
    }
```
- Add before `private function distiller()`:
```php
    /** @return list<?string> */
    private function verdictsOf(RecommendationRun $run): array
    {
        /** @var RecommendationRunLogRepository $logs */
        $logs = self::getContainer()->get(RecommendationRunLogRepository::class);

        return array_column($logs->listForRun($this->user, $run->requireId()), 'verdict');
    }
```

`tests/Service/Recommendation/Run/RecommendationConsolidationResolverTest.php`:
- Add after `testUnusableReplyFallsBackToBatchScorePoolWithEmptyReasons()`:
```php
    public function testAUsableReplySettlesItsCallAsUsable(): void
    {
        [$entry] = $this->fixtures->seedFeedWithEntries($this->user, 1);
        $id = $this->idOf($entry);
        $run = $this->runWithWinners([['id' => $id, 'score' => 500, 'reason' => '']]);
        $this->stubChatClient()->queueContent(json_encode([
            'recommendations' => [['id' => $id, 'score' => 600, 'reason' => 'Fits.']],
            'duplicates' => [],
        ], \JSON_THROW_ON_ERROR));

        $this->resolveConsolidation($run);

        self::assertSame(['usable'], $this->verdictsOf($run));
    }

    public function testAnUnusableReplySettlesItsCallAsUnusable(): void
    {
        [$entry] = $this->fixtures->seedFeedWithEntries($this->user, 1);
        $run = $this->runWithWinners([['id' => $this->idOf($entry), 'score' => 500, 'reason' => '']]);
        $this->stubChatClient()->queueContent('not json');

        $this->resolveConsolidation($run);

        self::assertSame(['unusable'], $this->verdictsOf($run));
    }
```
- Add before `private function resolver()`:
```php
    /** @return list<?string> */
    private function verdictsOf(RecommendationRun $run): array
    {
        /** @var RecommendationRunLogRepository $logs */
        $logs = self::getContainer()->get(RecommendationRunLogRepository::class);

        return array_column($logs->listForRun($this->user, $run->requireId()), 'verdict');
    }
```
Both files already import `RecommendationRun` and `RecommendationRunLogRepository`.

Run: `php bin/phpunit tests/Service/Recommendation/Run/RecommendationProfileDistillerTest.php tests/Service/Recommendation/Run/RecommendationConsolidationResolverTest.php`
Expected: PASS (these pin today's behaviour; the split must keep it).

- [ ] **Step 2: Move the tests off `settle()` and watch them fail**

```bash
perl -pi -e 's/->settle\(\x27\{\}\x27, true\)/->finishUsable(\x27{}\x27)/g' tests/Service/Recommendation/Run/RecordedCallTest.php
git grep -c -e "finishUsable('{}')" -- tests/Service/Recommendation/Run/RecordedCallTest.php
```
Expected: `7`.

`src/Service/Recommendation/Run/RecordedCall.php`: delete
```php
    /**
     * Settles this call with the parser's verdict on $content: usable banks
     * it as the answer, unusable records it as the invalid reply the next
     * retry corrects against.
     */
    public function settle(string $content, bool $usable): void
    {
        if ($usable) {
            $this->finishUsable($content);

            return;
        }

        $this->finishUnusable($content);
    }

```
Run: `php bin/phpunit tests/Service/Recommendation/Run`
Expected: FAIL: `Call to undefined method App\Service\Recommendation\Run\RecordedCall::settle()` from the three callers.

- [ ] **Step 3: Each caller settles with the verdict it means**

`src/Service/Recommendation/Run/RecommendationProfileDistiller.php`, replace
```php
        $result = $this->profileParser->parse($content);
        $recordedCall->settle($content, $result->usable);
        $this->checkpoint->guard($run);

        if (!$result->usable) {
            return ProfileDistillationOutcome::unusable($content);
        }

        $profile = $result->profile
```
with
```php
        $result = $this->profileParser->parse($content);
        if (!$result->usable) {
            $recordedCall->finishUnusable($content);
            $this->checkpoint->guard($run);

            return ProfileDistillationOutcome::unusable($content);
        }

        $recordedCall->finishUsable($content);
        $this->checkpoint->guard($run);
        $profile = $result->profile
```

`src/Service/Recommendation/Run/RecommendationConsolidationResolver.php`, replace
```php
        $result = $this->consolidationParser->parse($content, array_column($pool, 'id'));
        $recordedCall->settle($content, $result->usable);
        $this->checkpoint->guard($run);

        if (!$result->usable) {
            return ConsolidationOutcome::unusable($content, $pool);
        }

        return ConsolidationOutcome::finalizeWith(self::rankedFromReply($result));
```
with
```php
        $result = $this->consolidationParser->parse($content, array_column($pool, 'id'));
        if (!$result->usable) {
            $recordedCall->finishUnusable($content);
            $this->checkpoint->guard($run);

            return ConsolidationOutcome::unusable($content, $pool);
        }

        $recordedCall->finishUsable($content);
        $this->checkpoint->guard($run);

        return ConsolidationOutcome::finalizeWith(self::rankedFromReply($result));
```

`src/Service/Recommendation/Run/RecommendationBatchWave.php`, replace
```php
                $result = $this->parser->parse($reply['content'], $wave->batches[$position]->validIds());
                $reply['call']->settle($reply['content'], $result->usable);
                if ($result->usable) {
                    $winners[$position] = self::asWinners($result->picks);

                    continue;
                }
                $correctiveReply[$position] = $reply['content'];
```
with
```php
                $result = $this->parser->parse($reply['content'], $wave->batches[$position]->validIds());
                if ($result->usable) {
                    $reply['call']->finishUsable($reply['content']);
                    $winners[$position] = self::asWinners($result->picks);

                    continue;
                }
                $reply['call']->finishUnusable($reply['content']);
                $correctiveReply[$position] = $reply['content'];
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `php bin/phpunit tests/Service/Recommendation tests/Service/Worker`
Expected: PASS. `git grep -n -e "->settle(" -- src tests` prints nothing.

- [ ] **Step 5: Deletion checks**

Restore each by hand.
1. In the distiller, swap `finishUnusable` and `finishUsable`. Expected: both new distiller tests fail.
2. In the resolver, the same swap. Expected: both new resolver tests fail.
3. In the batch wave, the same swap. Expected: `RecommendationRunAdvancerTest::testACorrectiveRetryGetsItsOwnLogRowWithTheUnusableVerdict` fails.

- [ ] **Step 6: Gates and commit**

`composer check`, `composer md`, PhpStorm lint on the six changed files.
```bash
git add src/Service/Recommendation/Run/RecordedCall.php src/Service/Recommendation/Run/RecommendationProfileDistiller.php src/Service/Recommendation/Run/RecommendationConsolidationResolver.php src/Service/Recommendation/Run/RecommendationBatchWave.php tests/Service/Recommendation/Run/RecordedCallTest.php tests/Service/Recommendation/Run/RecommendationProfileDistillerTest.php tests/Service/Recommendation/Run/RecommendationConsolidationResolverTest.php
git commit -m "refactor(#1162): each phase settles its recorded call with the verdict it means"
```

---

### Task C4: `RecommendationRunLog::finish()` goes; tests settle through the production path

Production settles a call through `RecommendationCallRepository` (DBAL, on purpose: it commits at once for the status poll and never flushes the tick's dirty EntityManager). `finish()` on the entity had only test callers (C0 Step 2), so those tests exercised a path production never takes. They now settle through the production writer (D13).

**Files:**
- Modify: `src/Entity/RecommendationRunLog.php` (`finish()` goes; `use PersistedId;`)
- Modify: `tests/Support/RecommendationRunFixtures.php` (`settleLog()`, one docblock)
- Delete: `tests/Entity/RecommendationRunLogTest.php` (it tested `finish()` only)
- Test: `tests/Controller/Api/RecommendationDebugLogControllerTest.php`, `tests/Repository/RecommendationRunLogRepositoryTest.php` (perl), `tests/Repository/RecommendationRunTimingRepositoryTest.php`, `tests/Service/Recommendation/Run/RecommendationEtaEstimatorTest.php` (`finishedLog()` each)

**Interfaces:**
- Produces: `RecommendationRunLog::requireId(): int` (via `PersistedId`); `RecommendationRunFixtures::settleLog(RecommendationRunLog $log, string $responseText, CallOutcome $outcome): void` flushes, settles through `RecommendationCallRepository::settleAnswered()`, and refreshes `$log`.

- [ ] **Step 1: The fixture settles the way `RecordedCall` does**

`tests/Support/RecommendationRunFixtures.php`:
- Add these imports, each in its sorted place among the file's imports:
```php
use App\Entity\CallOutcome;
```
```php
use App\Repository\CallSettlement;
use App\Repository\RecommendationCallRepository;
```
- Replace the docblock of `createRun()`
```php
    /** Not flushed: callers batch several rows (often a `finish()` on top of
     *  each) before the one flush that makes them all visible together. */
```
with
```php
    /** Not flushed: callers batch several rows before the one flush that makes them all visible together. */
```
- Add after `log()`:
```php
    /** Settles the row the way RecordedCall does, through the DBAL writer, then re-reads the managed entity. */
    public function settleLog(RecommendationRunLog $log, string $responseText, CallOutcome $outcome): void
    {
        $this->em->flush();
        (new RecommendationCallRepository($this->em->getConnection()))->settleAnswered(
            new CallSettlement($log->requireId(), $outcome),
            $responseText,
        );
        $this->em->refresh($log);
    }
```

`src/Entity/RecommendationRunLog.php`:
- Directly after `class RecommendationRunLog` and its `{`, add
```php
    use PersistedId;

```
- Delete
```php

    /** The final decoded text replaces whatever partial state the checkpoints wrote. */
    public function finish(string $responseText, CallOutcome $outcome): void
    {
        $this->responseText = $responseText;
        $this->verdict = $outcome->verdict;
        $this->wireBytes = $outcome->wireBytes;
        $this->finishReason = $outcome->finishReason;
        $this->finishedAt = $outcome->finishedAt;
    }
```
(the blank line above it included, so the class ends on `getFinishReason()`).

```bash
git rm tests/Entity/RecommendationRunLogTest.php
```

- [ ] **Step 2: The tests call it**

```bash
perl -0pi -e 's/\$(finished|log)->finish\(\n(\s+)/\$this->fixtures()->settleLog(\n$2\$$1,\n$2/g' tests/Controller/Api/RecommendationDebugLogControllerTest.php
perl -0pi -e 's/\$(finished|done)->finish\(\n(\s+)/\$this->fixtures->settleLog(\n$2\$$1,\n$2/g' tests/Repository/RecommendationRunLogRepositoryTest.php
git grep -c "settleLog(" -- tests/Controller/Api/RecommendationDebugLogControllerTest.php tests/Repository/RecommendationRunLogRepositoryTest.php
```
Expected: `2` in each file. Each call now reads `$this->fixtures()->settleLog(` / `$this->fixtures->settleLog(`, then `$finished,` (or `$log,`, `$done,`) on its own line, then the old arguments.

`tests/Repository/RecommendationRunTimingRepositoryTest.php`, replace `finishedLog()` with:
```php
    private function finishedLog(
        RecommendationRun $run,
        string $phase,
        ?int $batchNumber,
        string $startedAt,
        string $finishedAt,
    ): void {
        $log = $this->fixtures->log(
            $run,
            $phase,
            $batchNumber,
            1,
            'req',
            new \DateTimeImmutable('2026-08-08T' . $startedAt . 'Z'),
        );
        $this->fixtures->settleLog(
            $log,
            'reply',
            new CallOutcome(
                RecommendationRunLog::VERDICT_USABLE,
                0,
                new \DateTimeImmutable('2026-08-08T' . $finishedAt . 'Z'),
                'stop',
            ),
        );
    }
```

`tests/Service/Recommendation/Run/RecommendationEtaEstimatorTest.php`, replace `finishedLog()` with:
```php
    private function finishedLog(
        RecommendationRun $run,
        string $phase,
        ?int $batchNumber,
        int $startOffset,
        int $spanSeconds,
    ): void {
        $base = new \DateTimeImmutable('2026-08-07T09:00:00Z');
        $log = $this->fixtures->log($run, $phase, $batchNumber, 1, 'req', $base->modify("+{$startOffset} seconds"));
        $this->fixtures->settleLog($log, 'reply', new CallOutcome(
            RecommendationRunLog::VERDICT_USABLE,
            0,
            $base->modify('+' . ($startOffset + $spanSeconds) . ' seconds'),
            'stop',
        ));
    }
```

- [ ] **Step 3: Run the tests to verify they pass**

```bash
git grep -n -e "function finish(" -e "->finish(" -- src/Entity tests/Controller tests/Repository tests/Service/Recommendation tests/Support
php bin/phpunit tests/Controller/Api/RecommendationDebugLogControllerTest.php tests/Repository tests/Service/Recommendation tests/Service/Account/AccountResetTest.php
```
Expected: the grep prints nothing; PASS. The controller test's list and detail payloads are unchanged byte for byte: the DBAL writer stores the same five columns `finish()` set, and `settleLog()` refreshes the entity the detail endpoint returns.

- [ ] **Step 4: Deletion check (the tests now cover the production writer)**

In `RecommendationCallRepository::settleAnswered()`, delete the `'finish_reason' => $outcome->finishReason,` line. Expected: `RecommendationRunLogRepositoryTest::testListReturnsMetadataWithByteSizesButNoBodies` and `RecommendationDebugLogControllerTest::testListReturnsEntriesWithStreamingTextOnlyForTheOpenCall` fail (`finishReason` null). Before this task they passed with that line gone. Restore by hand.

- [ ] **Step 5: Gates and commit**

`composer check` (the `EntityIdCoercionRule` accepts `$log->requireId()`), `composer md`, PhpStorm lint.
```bash
git add src/Entity/RecommendationRunLog.php tests/Support/RecommendationRunFixtures.php tests/Entity/RecommendationRunLogTest.php tests/Controller/Api/RecommendationDebugLogControllerTest.php tests/Repository/RecommendationRunLogRepositoryTest.php tests/Repository/RecommendationRunTimingRepositoryTest.php tests/Service/Recommendation/Run/RecommendationEtaEstimatorTest.php
git commit -m "refactor(#1162): a run-log row is settled only through the call repository, in tests too"
```

---

### Task C5: `Reasoning` and `PromptContext`: no reasoning flag, no five-parameter prompt methods

Planner ruling on D16. Two changes that meet in `consolidationInputSize()`, so one task:
- `bool $suppressesReasoning` (budget, prompt builder) and `CompletionRequest::$suppressReasoning` become the enum `App\Service\Ai\Completion\Reasoning` (`Allowed`, `Suppressed`), resolved from the connection by `Reasoning::preferredBy()` (D18). The budget picks its headroom with a `match`; nothing is duplicated per case.
- `batchMessages()`, `consolidationMessages()` and `consolidationInputSize()` take one `PromptContext(history, settings, profile)` instead of three to five loose values (D19). `WaveContext` carries it; the resolver builds it once.

Nothing on the wire changes and every prompt stays byte-identical: `testBatchMessagesReturnsTheExactRoleContentStructure`, `testConsolidationMessagesReturnsTheExactRoleContentStructure` and `testDistillMessagesReturnsTheExactRoleContentStructure` pin the text, the `consolidationInputSize` tests pin the sizing, `RecommendationRunAdvancerTest` pins `max_tokens` and the `suppressReasoning` hint of a real batch call, and `OpenAiCompatibleChatClientTest` pins the `reasoning: {effort: none}` body member.

**Files:**
- Create: `src/Service/Ai/Completion/Reasoning.php`, `src/Service/Recommendation/Prompt/PromptContext.php`; `tests/Service/Ai/Completion/ReasoningTest.php`, `tests/Service/Recommendation/Run/WaveBatchTest.php`
- Modify: `src/Service/Ai/Completion/CompletionRequest.php` (rewritten), `OpenAiCompatibleChatClient.php` (one line)
- Modify: `src/Service/Recommendation/Prompt/RecommendationAnswerBudget.php` (two methods), `RecommendationCompletionRequestFactory.php` (rewritten), `RecommendationPromptBuilder.php` (four methods)
- Modify: `src/Service/Recommendation/Run/TickContext.php` (`reasoning()`), `WaveContext.php` (rewritten), `WaveContextLoader.php` (`load()`), `WaveBatch.php` (`linesInSnapshotOrder()`), `RecommendationBatchWave.php` (`batchMessages()`; `linesInSnapshotOrder()` and the `PromptLine` import go), `RecommendationConsolidationResolver.php` (`resolve()`)
- Modify: `tests/Support/StubChatClient.php` (one line)
- Test: `tests/Service/Ai/Completion/OpenAiCompatibleChatClientTest.php`, `RateLimitedCompletionTest.php`; `tests/Service/Recommendation/Prompt/RecommendationCompletionRequestFactoryTest.php`, `RecommendationPromptBuilderTest.php`; `tests/Service/Recommendation/Run/RecommendationRunAdvancerTest.php`, `TickContextTest.php`, `WaveContextLoaderTest.php`

**Interfaces:**
- Produces:
  - `enum App\Service\Ai\Completion\Reasoning { case Allowed; case Suppressed; public static function preferredBy(AiProviderSettings $connection): self }`.
  - `CompletionRequest::__construct(string $model, array $messages, int $maxAnswerTokens, JsonSchema $responseSchema, Reasoning $reasoning)`; `$suppressReasoning` is gone.
  - `RecommendationAnswerBudget::outputBoundTokens(int $replyItemCount, RecommendationResponseSchema $schema, Reasoning $reasoning): int`; `reasoningHeadroomTokens()` becomes private.
  - `final readonly class App\Service\Recommendation\Prompt\PromptContext { public RecommendationHistory $history; public EffectiveRecommendationSettings $settings; public ?string $profile }`.
  - `RecommendationPromptBuilder::batchMessages(PromptContext $context, array $candidateLines, ?CandidatePoolSummary $poolSummary = null): array`, `consolidationMessages(PromptContext $context, array $rankedPool, array $linesById): array`, `consolidationInputSize(PromptContext $context, Reasoning $reasoning): int`. `distillMessages(RecommendationHistory, EffectiveRecommendationSettings)` and `packBatches()` keep their two and three parameters.
  - `TickContext::reasoning(): Reasoning`. `WaveContext(TickContext $tick, array $batches, ?CandidatePoolSummary $poolSummary, PromptContext $prompt)`: `history` and `profile` move into `$prompt`.
  - `WaveBatch::linesInSnapshotOrder(): list<PromptLine>`: the batch's prompt lines in snapshot order, pruned entries skipped. `RecommendationBatchWave::linesInSnapshotOrder(WaveBatch)` is gone.
  - `StubChatClient` still records `suppressReasoning` as a bool, so every assertion on it stays.

- [ ] **Step 1: Write the failing tests**

`tests/Service/Ai/Completion/ReasoningTest.php`:
```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Ai\Completion;

use App\Entity\AiProviderSettings;
use App\Entity\User;
use App\Service\Ai\Completion\Reasoning;
use App\Tests\Support\AiProviderSettingsFactory;
use PHPUnit\Framework\TestCase;

final class ReasoningTest extends TestCase
{
    public function testAConnectionSuppressesReasoningByDefault(): void
    {
        self::assertSame(Reasoning::Suppressed, Reasoning::preferredBy($this->connection()));
    }

    public function testAConnectionThatAllowsReasoningLetsTheCallReason(): void
    {
        $connection = $this->connection();
        $connection->setSuppressReasoning(false);

        self::assertSame(Reasoning::Allowed, Reasoning::preferredBy($connection));
    }

    private function connection(): AiProviderSettings
    {
        return AiProviderSettingsFactory::build(
            new User('reasoning@example.test', new \DateTimeImmutable('2026-08-16 09:00:00')),
        );
    }
}
```

`tests/Service/Recommendation/Run/TickContextTest.php`: add `use App\Service\Ai\Completion\Reasoning;` after `use App\Enum\RecommendationBatchSize;`, and after `testTheDriverDecidesTheRetryPlan()`:
```php
    public function testTheConnectionDecidesWhetherTheCallMayReason(): void
    {
        $connection = $this->connection();
        $connection->setSuppressReasoning(false);

        self::assertSame(Reasoning::Allowed, $this->tick($connection, TickDriver::Poll)->reasoning());
    }
```

`tests/Service/Recommendation/Prompt/RecommendationPromptBuilderTest.php`: add these two imports, each in its sorted place:
```php
use App\Service\Ai\Completion\Reasoning;
use App\Service\Recommendation\Prompt\PromptContext;
```
Then rewrite the calls. The four `consolidationInputSize` calls and the eleven multi-line `batchMessages`/`consolidationMessages` calls with one argument per line:
```bash
perl -0pi -e 's/(\$\w+) = \$this->builder\n\s+->consolidationInputSize\(([\d_]+), \$history, \x27A profile\.\x27, 50, suppressesReasoning: (true|false)\);/"$1 = \$this->builder->consolidationInputSize(\n            new PromptContext(\$history, \$this->settings($2, 50), \x27A profile.\x27),\n            Reasoning::" . ($3 eq "true" ? "Suppressed" : "Allowed") . ",\n        );"/ge' tests/Service/Recommendation/Prompt/RecommendationPromptBuilderTest.php
perl -0pi -e 's/->batchMessages\(\n(\s+)([^\n]+),\n\s+([^\n]+),\n\s+([^\n]+),\n\s+([^\n]+),\n(?:\s+([^\n]+),\n)?(\s+)\)/"->batchMessages(\n${1}new PromptContext($2, $4, $5),\n$1$3,\n" . (defined $6 ? "$1$6,\n" : "") . "$7)"/ge' tests/Service/Recommendation/Prompt/RecommendationPromptBuilderTest.php
perl -0pi -e 's/->consolidationMessages\(\n(\s+)\$pool,\n\s+\$lines,\n\s+([^\n]+),\n\s+([^\n]+),\n\s+([^\n]+),\n(\s+)\)/->consolidationMessages(\n${1}new PromptContext($2, $3, $4),\n$1\$pool,\n$1\$lines,\n$5)/g' tests/Service/Recommendation/Prompt/RecommendationPromptBuilderTest.php
git grep -c "new PromptContext(" -- tests/Service/Recommendation/Prompt/RecommendationPromptBuilderTest.php
```
Expected: `16` (4 sizing calls, 9 multi-line batch calls, 3 multi-line consolidation calls). Each multi-line call now reads, e.g.:
```php
        $messages = $this->builder->batchMessages(
            new PromptContext($this->emptyHistory(), $this->settings(32768, 100), null),
            $candidateLines,
            $summary,
        );
```
The four single-line calls, by hand:
- In `testBatchMessagesLayerFixedGuidanceAndContract()`, replace
```php
        $withGuidance = $this->builder->batchMessages($history, $candidateLines, $settingsWithGuidance, null);
```
with
```php
        $withGuidance = $this->builder->batchMessages(
            new PromptContext($history, $settingsWithGuidance, null),
            $candidateLines,
        );
```
- In `testBatchMessagesReturnsTheExactRoleContentStructure()`, replace
```php
        $messages = $this->builder->batchMessages($history, $candidateLines, $settings, 'Likes homelab.');
```
with
```php
        $messages = $this->builder->batchMessages(
            new PromptContext($history, $settings, 'Likes homelab.'),
            $candidateLines,
        );
```
- In `testDescriptionAtExactlyTheClampedLengthIsNotTruncated()`, replace
```php
        $messages = $this->builder->batchMessages($this->emptyHistory(), [
            new PromptLine(1, 'Boundary120', 'F', 'D', $exactly120),
            new PromptLine(2, 'Boundary121', 'F', 'D', $exactly121),
        ], $this->settings(8192, 10), null);
```
with
```php
        $messages = $this->builder->batchMessages(
            new PromptContext($this->emptyHistory(), $this->settings(8192, 10), null),
            [
                new PromptLine(1, 'Boundary120', 'F', 'D', $exactly120),
                new PromptLine(2, 'Boundary121', 'F', 'D', $exactly121),
            ],
        );
```
- In `testConsolidationMessagesRejectsAnEmptyPool()`, replace
```php
        $this->builder->consolidationMessages([], [], $this->emptyHistory(), $this->settings(32768, 100), null);
```
with
```php
        $this->builder->consolidationMessages(
            new PromptContext($this->emptyHistory(), $this->settings(32768, 100), null),
            [],
            [],
        );
```
Then `git grep -c "new PromptContext(" -- tests/Service/Recommendation/Prompt/RecommendationPromptBuilderTest.php` prints `20`, and `git grep -n "suppressesReasoning" -- tests/Service/Recommendation/Prompt/RecommendationPromptBuilderTest.php` prints nothing.

The other tests on the budget's third argument:
```bash
perl -pi -e 's/suppressesReasoning: true/reasoning: Reasoning::Suppressed/g; s/suppressesReasoning: false/reasoning: Reasoning::Allowed/g' tests/Service/Recommendation/Prompt/RecommendationCompletionRequestFactoryTest.php tests/Service/Recommendation/Run/RecommendationRunAdvancerTest.php
git grep -c "reasoning: Reasoning::" -- tests/Service/Recommendation/Prompt/RecommendationCompletionRequestFactoryTest.php tests/Service/Recommendation/Run/RecommendationRunAdvancerTest.php
```
Expected: `3` and `1`. Add `use App\Service\Ai\Completion\Reasoning;` to both files, in sorted place.

The transport tests:
```bash
perl -pi -e 's/(new CompletionRequest\(.*), false\);/$1, Reasoning::Allowed);/; s/(new CompletionRequest\(.*), true\);/$1, Reasoning::Suppressed);/' tests/Service/Ai/Completion/OpenAiCompatibleChatClientTest.php
perl -0pi -e 's/(new JsonSchema\(\x27s\x27, \[\x27type\x27 => \x27object\x27\]\),\n\s+)false,/$1Reasoning::Allowed,/' tests/Service/Ai/Completion/RateLimitedCompletionTest.php
git grep -c "Reasoning::" -- tests/Service/Ai/Completion/OpenAiCompatibleChatClientTest.php tests/Service/Ai/Completion/RateLimitedCompletionTest.php
```
Expected: `3` (`request()`, `suppressingRequest()`, the runaway test's request) and `1`. Add `use App\Service\Ai\Completion\Reasoning;` to both: in `OpenAiCompatibleChatClientTest` directly after `use App\Service\Ai\Completion\OpenAiCompatibleChatClient;`, in `RateLimitedCompletionTest` between `use App\Service\Ai\Completion\RateLimitedCompletion;` and `use App\Service\Ai\Completion\RetryPlan;`.

`tests/Service/Recommendation/Run/WaveContextLoaderTest.php`: `self::assertSame('Likes Rust.', $wave->profile);` becomes `self::assertSame('Likes Rust.', $wave->prompt->profile);`.

`tests/Support/StubChatClient.php`: `'suppressReasoning' => $request->suppressReasoning,` becomes `'suppressReasoning' => Reasoning::Suppressed === $request->reasoning,`; add `use App\Service\Ai\Completion\Reasoning;` in sorted place.

`tests/Service/Recommendation/Run/WaveBatchTest.php`:
```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Recommendation\Run;

use App\Service\Recommendation\Prompt\PromptLine;
use App\Service\Recommendation\Run\WaveBatch;
use PHPUnit\Framework\TestCase;

final class WaveBatchTest extends TestCase
{
    public function testItsLinesFollowTheSnapshotOrderAndSkipAPrunedEntry(): void
    {
        $three = new PromptLine(3, 'Three', 'F', 'D', null);
        $one = new PromptLine(1, 'One', 'F', 'D', null);
        $batch = new WaveBatch(0, [3, 2, 1], [1 => $one, 3 => $three]);

        self::assertSame([$three, $one], $batch->linesInSnapshotOrder());
    }
}
```
`linesById` is keyed in the loader's order, not the snapshot's, and entry 2 is pruned. So the assertion pins the snapshot order, the skip and the list keys. `assertSame` compares keys, so `[0 => $three, 2 => $one]` fails.

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php bin/phpunit tests/Service/Ai tests/Service/Recommendation`
Expected: FAIL: `Class "App\Service\Ai\Completion\Reasoning" not found`, `Class "App\Service\Recommendation\Prompt\PromptContext" not found`, and `Call to undefined method App\Service\Recommendation\Run\WaveBatch::linesInSnapshotOrder()`.
(`WaveBatchTest` is under `tests/Service/Recommendation`, so the Step 2 command already runs it.)

- [ ] **Step 3: Implement the transport side**

`src/Service/Ai/Completion/Reasoning.php`:
```php
<?php

declare(strict_types=1);

namespace App\Service\Ai\Completion;

use App\Entity\AiProviderSettings;

/** Whether a call asks the provider not to reason (#323): a hint a local model may ignore, so budgets keep room. */
enum Reasoning
{
    case Allowed;
    case Suppressed;

    public static function preferredBy(AiProviderSettings $connection): self
    {
        return $connection->suppressesReasoning() ? self::Suppressed : self::Allowed;
    }
}
```

`src/Service/Ai/Completion/CompletionRequest.php` (rewritten in full):
```php
<?php

declare(strict_types=1);

namespace App\Service\Ai\Completion;

/**
 * What to ask a provider for, as one value: the messages imply how many items the reply covers, which sizes
 * `maxAnswerTokens`, so the bound cannot drift from the prompt it belongs to.
 */
final readonly class CompletionRequest
{
    /** @param list<array{role: string, content: string}> $messages */
    public function __construct(
        public string $model,
        public array $messages,
        public int $maxAnswerTokens,
        public JsonSchema $responseSchema,
        public Reasoning $reasoning,
    ) {
    }
}
```

`src/Service/Ai/Completion/OpenAiCompatibleChatClient.php`: `if ($request->suppressReasoning) {` becomes `if (Reasoning::Suppressed === $request->reasoning) {`.

- [ ] **Step 4: Implement the budget, the factory and the prompt builder**

`src/Service/Recommendation/Prompt/RecommendationAnswerBudget.php`: replace `outputBoundTokens()` and `reasoningHeadroomTokens()`, each with its docblock, with
```php
    /**
     * The answer bound plus a reasoning headroom. Suppression only shrinks the headroom: the hint does not stop a
     * local model thinking (#493), and the headroom is a ceiling, not a reservation (#327).
     */
    public static function outputBoundTokens(
        int $replyItemCount,
        RecommendationResponseSchema $schema,
        Reasoning $reasoning,
    ): int {
        return self::answerBoundTokens($replyItemCount, $schema) + self::reasoningHeadroomTokens($reasoning);
    }

    private static function reasoningHeadroomTokens(Reasoning $reasoning): int
    {
        return match ($reasoning) {
            Reasoning::Suppressed => self::SUPPRESSED_REASONING_HEADROOM_TOKENS,
            Reasoning::Allowed => self::REASONING_HEADROOM_TOKENS,
        };
    }
```
and add `use App\Service\Ai\Completion\Reasoning;` after `namespace App\Service\Recommendation\Prompt;` (the file has no other import; one blank line on each side).

`src/Service/Recommendation/Prompt/RecommendationCompletionRequestFactory.php` (rewritten in full; C6 changes its signature):
```php
<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Prompt;

use App\Entity\AiProviderSettings;
use App\Service\Ai\Completion\CompletionRequest;
use App\Service\Ai\Completion\Reasoning;

/** Builds every phase's request, so a prompt and its output bound are always derived together. */
final readonly class RecommendationCompletionRequestFactory
{
    /** @param list<array{role: string, content: string}> $messages */
    public function create(
        AiProviderSettings $settings,
        array $messages,
        int $replyItemCount,
        RecommendationResponseSchema $responseSchema,
    ): CompletionRequest {
        $reasoning = Reasoning::preferredBy($settings);

        return new CompletionRequest(
            $settings->getModel() ?? '',
            $messages,
            RecommendationAnswerBudget::outputBoundTokens($replyItemCount, $responseSchema, $reasoning),
            $responseSchema->toJsonSchema(),
            $reasoning,
        );
    }
}
```

`src/Service/Recommendation/Prompt/PromptContext.php`:
```php
<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Prompt;

use App\Service\Recommendation\Settings\EffectiveRecommendationSettings;

final readonly class PromptContext
{
    public function __construct(
        public RecommendationHistory $history,
        public EffectiveRecommendationSettings $settings,
        public ?string $profile,
    ) {
    }
}
```

`src/Service/Recommendation/Prompt/RecommendationPromptBuilder.php`:
- Add `use App\Service\Ai\Completion\Reasoning;` in its sorted place. (`EffectiveRecommendationSettings` stays imported: `distillMessages()` and `packBatches()` still take it.)
- Replace `consolidationInputSize()` with its docblock by
```php
    /**
     * How many ranked winners the consolidation call takes: the largest shortlist whose whole call, reply and
     * reasoning headroom included, fits the context window, clamped between the floor and ceiling factors.
     */
    public function consolidationInputSize(PromptContext $context, Reasoning $reasoning): int
    {
        $contextWindow = $context->settings->packing->contextWindow;
        $picksLimit = $context->settings->picksLimit;
        $descriptionLength = $this->descriptionLength($contextWindow);
        $fixedInputTokens = self::FIXED_OVERHEAD_TOKENS
            + $this->tokens((string) $context->profile)
            + $this->tokens($this->favoritesSection($context->history, $descriptionLength));
        $lineChars = $descriptionLength + self::CANDIDATE_LINE_FRAME_CHARS;
        $perCandidateInputTokens = intdiv($lineChars, self::CHARS_PER_TOKEN) + 1;

        $floor = self::CONSOLIDATION_MIN_INPUT_FACTOR * $picksLimit;
        $ceiling = self::CONSOLIDATION_MAX_INPUT_FACTOR * $picksLimit;

        for ($size = $ceiling; $size > $floor; --$size) {
            $callTokens = $fixedInputTokens
                + $size * $perCandidateInputTokens
                + RecommendationAnswerBudget::outputBoundTokens(
                    $size,
                    RecommendationResponseSchema::Consolidation,
                    $reasoning,
                );
            if ($callTokens <= $contextWindow) {
                return $size;
            }
        }

        return $floor;
    }
```
- Replace `batchMessages()` with its docblock by
```php
    /**
     * @param list<PromptLine> $candidateLines
     *
     * @return list<array{role: string, content: string}>
     */
    public function batchMessages(
        PromptContext $context,
        array $candidateLines,
        ?CandidatePoolSummary $poolSummary = null,
    ): array {
        $system = implode("\n\n", [
            RecommendationPromptText::BATCH_SYSTEM_ROLE,
            $context->settings->guidancePrompt ?? RecommendationPromptText::DEFAULT_GUIDANCE,
            RecommendationPromptText::BATCH_OUTPUT_CONTRACT,
        ]);
        $user = implode("\n\n", $this->batchUserSections($context, $candidateLines, $poolSummary));

        return [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user', 'content' => $user],
        ];
    }
```
- Replace `batchUserSections()` with its docblock by
```php
    /**
     * The profile when there is one, FAVORITES only (KEPT and VIEWED shape the profile, #493), the whole pool's
     * frame (#344 shuffles the pool into random batches), then the candidates.
     *
     * @param list<PromptLine> $candidateLines
     *
     * @return list<string>
     */
    private function batchUserSections(
        PromptContext $context,
        array $candidateLines,
        ?CandidatePoolSummary $poolSummary,
    ): array {
        $descriptionLength = $this->descriptionLength($context->settings->packing->contextWindow);
        $sections = [];
        if ($this->hasContent($context->profile)) {
            $sections[] = "PROFILE:\n" . $context->profile;
        }
        $sections[] = $this->favoritesSection($context->history, $descriptionLength);
        $poolFrame = $this->poolFrameLine($poolSummary);
        if (null !== $poolFrame) {
            $sections[] = $poolFrame;
        }
        $sections[] = $this->candidateSection($candidateLines, $descriptionLength);

        return $sections;
    }
```
- Replace `consolidationMessages()` with its docblock by
```php
    /**
     * Profile and FAVORITES like the batch call, then the ranked shortlist rendered candidate-style, so each line
     * keeps the id a recommendation resolves back to; a winner pruned since its batch is dropped (#493).
     *
     * @param list<array{id: int, score: int, reason: string}> $rankedPool
     * @param array<int, PromptLine>                           $linesById
     *
     * @return list<array{role: string, content: string}>
     *
     * @throws \LogicException if called with an empty pool
     */
    public function consolidationMessages(PromptContext $context, array $rankedPool, array $linesById): array
    {
        if ([] === $rankedPool) {
            throw new \LogicException('The consolidation phase requires at least one ranked winner.');
        }

        $descriptionLength = $this->descriptionLength($context->settings->packing->contextWindow);
        $shortlistLines = array_values(array_filter(array_map(
            static fn (array $winner): ?PromptLine => $linesById[$winner['id']] ?? null,
            $rankedPool,
        )));

        $sections = [];
        if ($this->hasContent($context->profile)) {
            $sections[] = "PROFILE:\n" . $context->profile;
        }
        $sections[] = $this->favoritesSection($context->history, $descriptionLength);
        $sections[] = $this->candidateSection($shortlistLines, $descriptionLength);

        return [
            [
                'role' => 'system',
                'content' => RecommendationPromptText::CONSOLIDATION_ROLE
                    . "\n\n" . RecommendationPromptText::CONSOLIDATION_OUTPUT_CONTRACT,
            ],
            ['role' => 'user', 'content' => implode("\n\n", $sections)],
        ];
    }
```

- [ ] **Step 5: The run side hands the values over**

`src/Service/Recommendation/Run/TickContext.php`: add `use App\Service\Ai\Completion\Reasoning;` directly above `use App\Service\Ai\Completion\RetryPlan;`, and after `retryPlan()`:
```php
    public function reasoning(): Reasoning
    {
        return Reasoning::preferredBy($this->connection);
    }
```

`src/Service/Recommendation/Run/WaveContext.php` (rewritten in full):
```php
<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Run;

use App\Service\Recommendation\Prompt\CandidatePoolSummary;
use App\Service\Recommendation\Prompt\PromptContext;

final readonly class WaveContext
{
    /** @param list<WaveBatch> $batches the plan's next batches, in plan order */
    public function __construct(
        public TickContext $tick,
        public array $batches,
        public ?CandidatePoolSummary $poolSummary,
        public PromptContext $prompt,
    ) {
    }
}
```

`src/Service/Recommendation/Run/WaveContextLoader.php`: add `use App\Service\Recommendation\Prompt\PromptContext;` before `use App\Service\Recommendation\Prompt\RecommendationCandidateLoader;`, and in `load()` replace
```php
            $this->historyLoader->load($tick->userId(), $tick->settings),
            $run->getProfileText(),
        );
```
with
```php
            new PromptContext(
                $this->historyLoader->load($tick->userId(), $tick->settings),
                $tick->settings,
                $run->getProfileText(),
            ),
        );
```

`src/Service/Recommendation/Run/WaveBatch.php`: after `validIds()`, add
```php

    /** @return list<PromptLine> */
    public function linesInSnapshotOrder(): array
    {
        $present = array_filter($this->ids, fn (int $id): bool => isset($this->linesById[$id]));

        return array_values(array_map(fn (int $id): PromptLine => $this->linesById[$id], $present));
    }
```
(`WaveBatch` already imports `PromptLine`. The closures read `$this`, so they are not `static`.)

`src/Service/Recommendation/Run/RecommendationBatchWave.php`:
- In `batchMessages()`, replace
```php
        $messages = $this->promptBuilder->batchMessages(
            $wave->history,
            $this->linesInSnapshotOrder($waveBatch),
            $wave->tick->settings,
            $wave->profile,
            $wave->poolSummary,
        );
```
with
```php
        $messages = $this->promptBuilder->batchMessages(
            $wave->prompt,
            $waveBatch->linesInSnapshotOrder(),
            $wave->poolSummary,
        );
```
- Delete
```php
    /** @return list<PromptLine> */
    private function linesInSnapshotOrder(WaveBatch $waveBatch): array
    {
        $present = array_filter($waveBatch->ids, static fn (int $id): bool => isset($waveBatch->linesById[$id]));

        return array_values(array_map(static fn (int $id): PromptLine => $waveBatch->linesById[$id], $present));
    }

```
(the blank line after it included, so `asWinners()`'s docblock follows `batchMessages()` after one blank line).
- Delete `use App\Service\Recommendation\Prompt\PromptLine;`. The wave has no other use of it.

`src/Service/Recommendation/Run/RecommendationConsolidationResolver.php`:
- Add `use App\Service\Recommendation\Prompt\PromptContext;` before `use App\Service\Recommendation\Prompt\PromptLine;`.
- In `resolve()`, replace
```php
        $settings = $tick->settings;
        $history = $this->historyLoader->load($tick->userId(), $settings);
        $inputSize = $this->promptBuilder->consolidationInputSize(
            $settings->packing->contextWindow,
            $history,
            $run->getProfileText(),
            $settings->picksLimit,
            $tick->connection->suppressesReasoning(),
        );
```
with
```php
        $prompt = new PromptContext(
            $this->historyLoader->load($tick->userId(), $tick->settings),
            $tick->settings,
            $run->getProfileText(),
        );
        $inputSize = $this->promptBuilder->consolidationInputSize($prompt, $tick->reasoning());
```
- Replace
```php
            $this->promptBuilder->consolidationMessages(
                $pool,
                $linesById,
                $history,
                $settings,
                $run->getProfileText(),
            ),
```
with
```php
            $this->promptBuilder->consolidationMessages($prompt, $pool, $linesById),
```

- [ ] **Step 6: Run the tests to verify they pass**

```bash
bin/console cache:clear && bin/console cache:warmup
php bin/phpunit tests/Service/Ai tests/Service/Recommendation tests/Service/Worker
git grep -n "suppressesReasoning\|->suppressReasoning" -- src/Service/Ai/Completion src/Service/Recommendation tests/Support
```
Expected: PASS; the grep prints only `src/Service/Ai/Completion/Reasoning.php`'s `preferredBy()` line.

- [ ] **Step 7: Deletion checks**

Restore each by hand.
1. In `RecommendationAnswerBudget::reasoningHeadroomTokens()`, swap the two arms. Expected: `RecommendationCompletionRequestFactoryTest::testAConnectionThatMayReasonKeepsTheFullReasoningHeadroom` and `testConsolidationInputSizeFloorsOnATightContext` fail.
2. In `Reasoning::preferredBy()`, return `self::Allowed`. Expected: `ReasoningTest::testAConnectionSuppressesReasoningByDefault` and `RecommendationRunAdvancerTest::testBatchTickRecordsWinnersAndAdvances` (its batch call must carry the suppress hint) fail.
3. In `batchUserSections()`, render FAVORITES before the profile. Expected: `testBatchMessagesReturnsTheExactRoleContentStructure` fails.
4. In `WaveBatch::linesInSnapshotOrder()`, drop the `array_values(...)` wrapper. Expected: `WaveBatchTest::testItsLinesFollowTheSnapshotOrderAndSkipAPrunedEntry` fails (keys `0, 2`). Then map over `$this->linesById` instead of `$present`. Expected: the same test fails (order `[$one, $three]`).

- [ ] **Step 8: Gates and commit**

`composer check` (no method here passes three parameters; `composer tramp`: `$context` is read where it is passed), `composer md`, PhpStorm lint on every changed file.
```bash
git add src/Service/Ai/Completion src/Service/Recommendation tests/Service/Ai/Completion tests/Service/Recommendation tests/Support/StubChatClient.php
git commit -m "refactor(#1162): reasoning is an enum and the prompt builder reads one prompt context"
```

---

### Task C6: `CallPrompt` and `CallSlot`: a recorded call begins from the request it sends

Planner ruling on D16, the parameter counts. `RecommendationCallRecorder::begin(run, phase, batchNumber, messages, model)` becomes `begin(RecommendationRun, CallSlot, CompletionRequest)`: the request already carries the model and messages the log row renders, and `CallSlot` names the row (`distillation()`, `batch(int)`, `consolidation()`) instead of a phase string beside a nullable number. `RecommendationCompletionRequestFactory::create(settings, messages, count, schema)` becomes `create(AiProviderSettings, CallPrompt)` (D20). Each phase now builds the request first, then records it, then sends that same request. `TickContext::model()` loses its last caller and goes. The rendered request body is byte-identical: `['model' => …, 'messages' => …]` from the same values.

**Files:**
- Create: `src/Service/Recommendation/Prompt/CallPrompt.php`, `src/Service/Recommendation/Run/CallSlot.php`; `tests/Service/Recommendation/Run/CallSlotTest.php`
- Modify (rewritten): `src/Service/Recommendation/Prompt/RecommendationCompletionRequestFactory.php`, `src/Service/Recommendation/Run/RecommendationCallRecorder.php`
- Modify: `src/Service/Recommendation/Run/RecommendationProfileDistiller.php`, `RecommendationConsolidationResolver.php`, `RecommendationBatchWave.php` (the begin-and-send block each), `TickContext.php` (`model()` goes)
- Test: `tests/Service/Recommendation/Run/RecommendationCallRecorderTest.php`, `TickContextTest.php`; `tests/Service/Recommendation/Prompt/RecommendationCompletionRequestFactoryTest.php`

**Interfaces:**
- Consumes: `Reasoning`, `CompletionRequest(..., Reasoning $reasoning)` (C5).
- Produces:
  - `final readonly class App\Service\Recommendation\Prompt\CallPrompt { /** list<array{role: string, content: string}> */ public array $messages; public int $replyItemCount; public RecommendationResponseSchema $schema }`.
  - `final readonly class App\Service\Recommendation\Run\CallSlot { public string $phase; public ?int $batchNumber; public static function distillation(): self; public static function batch(int $batchNumber): self; public static function consolidation(): self }` (E3 types `$phase` as `CallPhase`).
  - `RecommendationCompletionRequestFactory::create(AiProviderSettings $connection, CallPrompt $prompt): CompletionRequest`.
  - `RecommendationCallRecorder::begin(RecommendationRun $run, CallSlot $slot, CompletionRequest $request): RecordedCall`.
  - `TickContext::model()` is gone.

- [ ] **Step 1: Write the failing tests**

`tests/Service/Recommendation/Run/CallSlotTest.php`:
```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Recommendation\Run;

use App\Entity\RecommendationRunLog;
use App\Service\Recommendation\Run\CallSlot;
use PHPUnit\Framework\TestCase;

final class CallSlotTest extends TestCase
{
    public function testEachPhaseNamesItsRowAndOnlyABatchCarriesANumber(): void
    {
        self::assertSame(
            [
                [RecommendationRunLog::PHASE_DISTILL, null],
                [RecommendationRunLog::PHASE_BATCH, 3],
                [RecommendationRunLog::PHASE_CONSOLIDATE, null],
            ],
            array_map(
                static fn (CallSlot $slot): array => [$slot->phase, $slot->batchNumber],
                [CallSlot::distillation(), CallSlot::batch(3), CallSlot::consolidation()],
            ),
        );
    }
}
```

`tests/Service/Recommendation/Run/RecommendationCallRecorderTest.php`:
- Replace
```php
        $this->recorder->begin(
            $this->run,
            RecommendationRunLog::PHASE_BATCH,
            2,
            [['role' => 'user', 'content' => 'hi']],
            'm',
        );
```
with
```php
        $this->recorder->begin(
            $this->run,
            CallSlot::batch(2),
            $this->request([['role' => 'user', 'content' => 'hi']]),
        );
```
- Then:
```bash
perl -pi -e 's/->begin\(\$this->run, RecommendationRunLog::PHASE_BATCH, (\d), \[\], \x27m\x27\)/->begin(\$this->run, CallSlot::batch($1), \$this->request([]))/g' tests/Service/Recommendation/Run/RecommendationCallRecorderTest.php
git grep -c "CallSlot::batch(" -- tests/Service/Recommendation/Run/RecommendationCallRecorderTest.php
```
Expected: `10`.
- Add before `private function logs()`:
```php
    /** @param list<array{role: string, content: string}> $messages */
    private function request(array $messages): CompletionRequest
    {
        return new CompletionRequest(
            'm',
            $messages,
            1024,
            new JsonSchema('test', ['type' => 'object']),
            Reasoning::Allowed,
        );
    }
```
- Add, each in sorted place: `use App\Service\Ai\Completion\CompletionRequest;`, `use App\Service\Ai\Completion\JsonSchema;`, `use App\Service\Ai\Completion\Reasoning;`, `use App\Service\Recommendation\Run\CallSlot;`.

`tests/Service/Recommendation/Prompt/RecommendationCompletionRequestFactoryTest.php`:
```bash
perl -0pi -e 's/\[\[\x27role\x27 => \x27user\x27, \x27content\x27 => \x27rank these\x27\]\],\n\s+45,\n\s+RecommendationResponseSchema::Consolidation,\n/\$this->prompt(),\n/g' tests/Service/Recommendation/Prompt/RecommendationCompletionRequestFactoryTest.php
git grep -c "\$this->prompt()," -- tests/Service/Recommendation/Prompt/RecommendationCompletionRequestFactoryTest.php
```
Expected: `2`. Add before `private function settings(`:
```php
    private function prompt(): CallPrompt
    {
        return new CallPrompt(
            [['role' => 'user', 'content' => 'rank these']],
            45,
            RecommendationResponseSchema::Consolidation,
        );
    }

```
`CallPrompt` shares the test's `Prompt` namespace under `App\Service`; add `use App\Service\Recommendation\Prompt\CallPrompt;` in sorted place.

`tests/Service/Recommendation/Run/TickContextTest.php`: delete `testTheModelIsTheConnectionsChosenModel()` and `testAConnectionWithoutAModelNamesNone()` with the blank line after each (the model now travels on the request: `RecommendationProfileDistillerTest::testTheRecordedCallCarriesTheConfiguredModel` and `RecommendationConsolidationResolverTest::testTheRecordedCallCarriesTheConfiguredModel` pin it end to end).

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php bin/phpunit tests/Service/Recommendation/Run/CallSlotTest.php tests/Service/Recommendation/Run/RecommendationCallRecorderTest.php tests/Service/Recommendation/Prompt/RecommendationCompletionRequestFactoryTest.php`
Expected: FAIL: `Class "App\Service\Recommendation\Run\CallSlot" not found`, `Class "App\Service\Recommendation\Prompt\CallPrompt" not found`.

- [ ] **Step 3: Implement**

`src/Service/Recommendation/Prompt/CallPrompt.php`:
```php
<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Prompt;

final readonly class CallPrompt
{
    /** @param list<array{role: string, content: string}> $messages */
    public function __construct(
        public array $messages,
        public int $replyItemCount,
        public RecommendationResponseSchema $schema,
    ) {
    }
}
```

`src/Service/Recommendation/Run/CallSlot.php`:
```php
<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Run;

use App\Entity\RecommendationRunLog;

/** Which run-log row a provider call writes: its phase and, in the batch phase, the 1-based batch number. */
final readonly class CallSlot
{
    private function __construct(
        public string $phase,
        public ?int $batchNumber,
    ) {
    }

    public static function distillation(): self
    {
        return new self(RecommendationRunLog::PHASE_DISTILL, null);
    }

    public static function batch(int $batchNumber): self
    {
        return new self(RecommendationRunLog::PHASE_BATCH, $batchNumber);
    }

    public static function consolidation(): self
    {
        return new self(RecommendationRunLog::PHASE_CONSOLIDATE, null);
    }
}
```

`src/Service/Recommendation/Prompt/RecommendationCompletionRequestFactory.php` (rewritten in full):
```php
<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Prompt;

use App\Entity\AiProviderSettings;
use App\Service\Ai\Completion\CompletionRequest;
use App\Service\Ai\Completion\Reasoning;

/** Builds every phase's request, so a prompt and its output bound are always derived together. */
final readonly class RecommendationCompletionRequestFactory
{
    public function create(AiProviderSettings $connection, CallPrompt $prompt): CompletionRequest
    {
        $reasoning = Reasoning::preferredBy($connection);

        return new CompletionRequest(
            $connection->getModel() ?? '',
            $prompt->messages,
            RecommendationAnswerBudget::outputBoundTokens($prompt->replyItemCount, $prompt->schema, $reasoning),
            $prompt->schema->toJsonSchema(),
            $reasoning,
        );
    }
}
```

`src/Service/Recommendation/Run/RecommendationCallRecorder.php` (rewritten in full):
```php
<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Run;

use App\Entity\RecommendationRun;
use App\Entity\RecommendationRunLog;
use App\Repository\RecommendationCallRepository;
use App\Repository\RecommendationRunLogRepository;
use App\Service\Ai\Completion\CompletionRequest;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;

/**
 * Opens the run-log row for one provider call the moment it is sent (#309) and hands back the RecordedCall that
 * watches its stream. Every run records, debug on or off: the log is the history the ETA reads (#638).
 */
final readonly class RecommendationCallRecorder
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private RecommendationRunLogRepository $logs,
        private RecommendationCallRepository $calls,
        private ClockInterface $clock,
    ) {
    }

    public function begin(RecommendationRun $run, CallSlot $slot, CompletionRequest $request): RecordedCall
    {
        $log = $this->persistedLog($run, $slot, $request);

        return new RecordedCall(
            $this->calls,
            $this->clock,
            $run->requireId(),
            $log->getId(),
        );
    }

    private function persistedLog(
        RecommendationRun $run,
        CallSlot $slot,
        CompletionRequest $request,
    ): RecommendationRunLog {
        $log = new RecommendationRunLog(
            $run,
            $slot->phase,
            $slot->batchNumber,
            $this->nextAttempt($run, $slot),
            self::renderedRequest($request),
            $this->clock->now(),
        );
        $this->entityManager->persist($log);
        $this->entityManager->flush();

        return $log;
    }

    /** Derived from the rows already recorded, so the recorder cannot disagree with its own rows. */
    private function nextAttempt(RecommendationRun $run, CallSlot $slot): int
    {
        return $this->logs->countAttempts($run, $slot->phase, $slot->batchNumber) + 1;
    }

    /** Pretty-printed for the human the debug view exists for: the payload as sent, minus transport framing. */
    private static function renderedRequest(CompletionRequest $request): string
    {
        return json_encode(
            ['model' => $request->model, 'messages' => $request->messages],
            \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR,
        );
    }
}
```

`src/Service/Recommendation/Run/RecommendationProfileDistiller.php`:
- Replace
```php
        $recordedCall = $this->callRecorder->begin(
            $run,
            RecommendationRunLog::PHASE_DISTILL,
            null,
            $messages,
            $tick->model(),
        );

        $content = $this->providerCall->complete(
            $tick,
            $this->requestFactory->create(
                $tick->connection,
                $messages,
                1,
                RecommendationResponseSchema::Distillation,
            ),
            $recordedCall,
        );
```
with
```php
        $request = $this->requestFactory->create(
            $tick->connection,
            new CallPrompt($messages, 1, RecommendationResponseSchema::Distillation),
        );
        $recordedCall = $this->callRecorder->begin($run, CallSlot::distillation(), $request);
        $content = $this->providerCall->complete($tick, $request, $recordedCall);
```
- Replace `use App\Entity\RecommendationRunLog;` with `use App\Service\Recommendation\Prompt\CallPrompt;` (moved to its sorted place, before `use App\Service\Recommendation\Prompt\RecommendationCompletionRequestFactory;`).

`src/Service/Recommendation/Run/RecommendationConsolidationResolver.php`:
- Replace
```php
        $recordedCall = $this->callRecorder->begin(
            $run,
            RecommendationRunLog::PHASE_CONSOLIDATE,
            null,
            $messages,
            $tick->model(),
        );

        $content = $this->providerCall->complete(
            $tick,
            $this->requestFactory->create(
                $tick->connection,
                $messages,
                \count($pool),
                RecommendationResponseSchema::Consolidation,
            ),
            $recordedCall,
        );
```
with
```php
        $request = $this->requestFactory->create(
            $tick->connection,
            new CallPrompt($messages, \count($pool), RecommendationResponseSchema::Consolidation),
        );
        $recordedCall = $this->callRecorder->begin($run, CallSlot::consolidation(), $request);
        $content = $this->providerCall->complete($tick, $request, $recordedCall);
```
- Delete `use App\Entity\RecommendationRunLog;`; add `use App\Service\Recommendation\Prompt\CallPrompt;` before `use App\Service\Recommendation\Prompt\ConsolidationParseResult;`.

`src/Service/Recommendation/Run/RecommendationBatchWave.php`:
- In `sendRound()`, replace
```php
            $recordedCall = $this->callRecorder->begin(
                $tick->run,
                RecommendationRunLog::PHASE_BATCH,
                $waveBatch->index + 1,
                $messages,
                $tick->model(),
            );
            $calls[] = new ConcurrentCompletion(
                $this->requestFactory->create(
                    $tick->connection,
                    $messages,
                    \count($waveBatch->validIds()),
                    RecommendationResponseSchema::BatchScore,
                ),
                $recordedCall,
            );
```
with
```php
            $request = $this->requestFactory->create(
                $tick->connection,
                new CallPrompt($messages, \count($waveBatch->validIds()), RecommendationResponseSchema::BatchScore),
            );
            $recordedCall = $this->callRecorder->begin($tick->run, CallSlot::batch($waveBatch->index + 1), $request);
            $calls[] = new ConcurrentCompletion($request, $recordedCall);
```
- Delete `use App\Entity\RecommendationRunLog;`; add `use App\Service\Recommendation\Prompt\CallPrompt;` before `use App\Service\Recommendation\Prompt\RecommendationCompletionRequestFactory;`.

`src/Service/Recommendation/Run/TickContext.php`: delete `model()` and the blank line after it.

- [ ] **Step 4: Run the tests to verify they pass**

```bash
bin/console cache:clear && bin/console cache:warmup
php bin/phpunit tests/Service/Recommendation tests/Service/Worker tests/Controller/Api/RecommendationDebugLogControllerTest.php
git grep -n -e "->model()" -e "PHASE_" -- src/Service/Recommendation/Run/RecommendationBatchWave.php src/Service/Recommendation/Run/RecommendationConsolidationResolver.php src/Service/Recommendation/Run/RecommendationProfileDistiller.php
```
Expected: PASS; the grep prints nothing. `RecommendationCallRecorderTest::testBeginPersistsTheRequestBodyImmediatelyRegardlessOfTheDebugSwitch` still finds `"model": "m"` and `"content": "hi"` in the rendered body, and the advancer's log-row tests see the same phases, batch numbers and attempts.

- [ ] **Step 5: Deletion checks**

Restore each by hand.
1. In `CallSlot::consolidation()`, pass `RecommendationRunLog::PHASE_DISTILL`. Expected: `CallSlotTest` and `RecommendationRunAdvancerTest::testDistillBatchAndConsolidateCallsAreLoggedWithVerdicts` fail.
2. In `RecommendationCallRecorder::renderedRequest()`, render `'model' => ''`. Expected: `testBeginPersistsTheRequestBodyImmediatelyRegardlessOfTheDebugSwitch` and both `testTheRecordedCallCarriesTheConfiguredModel` tests fail.
3. In `RecommendationBatchWave::sendRound()`, pass `CallSlot::batch($waveBatch->index)`. Expected: the advancer's log-row test fails (`batch` rows numbered 0 and 1).

- [ ] **Step 6: Gates and commit**

`composer check`, `composer md` (`RecommendationCallRecorder`, the factory and the three phases: no method over three parameters), PhpStorm lint on every changed file.
```bash
git add src/Service/Recommendation tests/Service/Recommendation
git commit -m "refactor(#1162): a recorded call begins from the request it sends, named by its call slot"
```

---

### Finishing PR C

- [ ] **Step 1: Per-PR gates**

`php bin/phpunit`, `docker compose exec php composer test`, `composer check`, `composer md`, `composer infection:diff`, PhpStorm lint on all changed PHP.

- [ ] **Step 2: Real run**

Run the **Real run** procedure. Step 6's rows must still carry `usable`/`unusable` verdicts and a `finish_reason`: C3 and C4 touch exactly that path.

- [ ] **Step 3: PR**

```bash
git push -u origin refactor/1162-answer-budget-and-recorded-calls
gh pr create --base develop --title "refactor(#1162): one answer budget, recorded calls settled without a flag" --body "$(cat <<'EOF'
Refs #1162 (PR C of 4).

- The batch packer reserves `RecommendationAnswerBudget::answerBoundTokens()` for the reply; its three duplicated constants and the second formula are gone.
- `'FAVORITES (newest first):'` is rendered in one place.
- `RecordedCall::settle(string, bool $usable)` is gone; each phase calls `finishUsable()` or `finishUnusable()`.
- `RecommendationRunLog::finish()` is gone: it had no production caller. Tests settle rows through `RecommendationCallRepository`, the writer production uses.
- The reasoning flag is the `Reasoning` enum (budget, prompt builder, `CompletionRequest`); the prompt builder reads one `PromptContext`; the request factory takes a `CallPrompt`; the call recorder begins from the `CompletionRequest` it records, named by a `CallSlot`. Prompts and the recorded request body are byte-identical.

Deliberate behaviour change (not wire): for a batch cap below 69 (a small `maxBatchSize` or the Small batch size) the packer now reserves 1536 tokens for the reply instead of `max(1024, cap × 22.5)`, the bound the provider is actually given; on a tight context window that can pack one more batch. The default cap (100) is unaffected.

Wire: none. Real run: <paste steps 3, 5, 6 and 7>.
EOF
)"
```


### Execution rulings (PR C)

- **Preflight:** a read-only scan against the post-A/B tree, no dry run. Every anchor still matched.
  - C5 Step 1 adds a `Reasoning` import to two tests in `Ai/Completion`.
  - C5 also moves `linesInSnapshotOrder()` to `WaveBatch`, pinned by `WaveBatchTest`. This is the B-f2 carry-forward. C6's import is re-anchored after it.
  - `PromptContext` and `CallPrompt` get no class docblocks.
- **D11 is the only behaviour change.**
  - For batch caps ≤ 68 (the Small batch size, below a ceiling of 138), the batch reply reserve rises by up to 512 tokens, to 1536. That shrinks each batch by about 10 lines, so a large pool gains several batches.
  - At the default cap of 100 the reserve is unchanged, at 2250.
  - Distillation still passes `replyItemCount` 1. The Distillation arm ignores the count, so the budget is byte-identical and its two ±1 mutants are equivalent.
- **Tasks:**
  - C3's split left the guard on the unusable branch untested, so d48da877 adds a cancellation test for it in both the distiller and the resolver.
  - The resolver's guard on the usable branch is already caught by an advancer test.
  - 03068346 adds two tests to kill `consolidationInputSize()`'s term mutants: a longer profile and more FAVORITES each shrink the shortlist.
  - C6 deletes two `TickContext::model()` tests, so the suite goes from 6370 to 6369.
- **Reviews:**
  - `TickContext::reasoning()` stays (D18). `Reasoning::preferredBy()` is the single mapping.
  - The `finishUsable()`/`finishUnusable()` branch at three call sites stays (D12). Each side does different work, and `settle(bool)` was a flag parameter.
  - The `settleLog()` fixture keeps its refresh, so the entity stays true to the row the production path wrote.
  - The fix wave corrects stale test comments and the `TOKENS_PER_PICK` docblock (its reader is `consolidationInputSize()`), and extracts one `PromptContext` test helper.
  - Carried to #1171: the remaining stale narrative in `RecommendationPromptBuilderTest` comments.
- **Watch item (C3):** the Distillation/Consolidation retry-or-degrade skeleton keeps two copies. The shared logic is `InvalidReplyRetry`, and a helper would bring back D12's bool dispatch.
- **Real run:** run 126 as user 2 completed 6/6 with 0 transport failures, and distill was usable on attempt 1. One warning: consolidate attempt 1 ran away repeating entry ids until the provider ended it (`finish_reason` error). Attempt 2 was usable. The request parameters match run 125. The confirmation run 127 was clean: 6/6, every call usable on attempt 1.
- **Gates:** the MySQL leg runs without `TEST_TOKEN`, and never two at once.

---

# PR E — run states as enums, debug-log rows as values (`Closes #1162`)

### Task E0: Preflight (PR C merged)

**Files:** none.

- [ ] **Step 1: PR C is on `develop`; branch**

```bash
git fetch origin
git grep -n "function settle\|function finish(string \$responseText" origin/develop -- src/Service/Recommendation/Run/RecordedCall.php src/Entity/RecommendationRunLog.php
git status --short && git branch --show-current
git switch -c refactor/1162-run-state-enums origin/develop
```
Expected: the grep prints nothing.

- [ ] **Step 2: Re-take the constant sites**

```bash
git grep -c "RecommendationRun::STATUS_" -- src tests
git grep -c "RecommendationRunLog::PHASE_\|RecommendationRunLog::VERDICT_" -- src tests
git grep -n "TERMINAL_STATUSES" -- src tests
```
Expected (re-checked at `5229c947`, the same as at `89c7e17f`; paths after PR A–C):
- `STATUS_`: `src`: `RecommendationItemRepository` 1, `RecommendationRunRepository` 2, `RecommendationRunTimingRepository` 1, `RetentionRepository` 1, `Run/RecommendationEtaEstimator` 1, `Feed/RecommendationForYouSummaryProvider` 1, `Run/TickPhases` 1, `Run/RecommendationRunPurger` 1, `Run/RecommendationRunStarter` 1, `Run/RecommendationTickCheckpoint` 1. `tests`: `RecommendationDrainCommandTest` 3, `RecommendationDebugLogControllerTest` 1, `RecommendationRunControllerTest` 1, `RecommendationRunTest` 5, `RecommendationRunHistoryJsonTest` 5, `RecommendationFeedTest` 20, `RecommendationRunRepositoryTest` 16, `ForYouSweepTest` 1, `RecommendationForYouSummaryProviderTest` 5, `RecommendationRunAdvancerTest` 19, `RecommendationRunStarterTest` 2, `AdvanceRecommendationRunsHandlerTest` 15, `WorkerRunSweepTest` 1.
- `PHASE_`/`VERDICT_`: `src`: `Run/CallSlot` 3, `Run/PhaseDurations` 3, `Run/RecordedCall` 3 (C6 moved the distiller's, the resolver's and the batch wave's phase into `CallSlot`). `tests`: `CallSlotTest` 3, `RecommendationDebugLogControllerTest` 11, `RecommendationRunLogRepositoryTest` 19, `RecommendationRunTimingRepositoryTest` 9, `AccountResetTest` 1, `PhaseDurationsTest` 11, `RecommendationCallRecorderTest` 6 (C6 replaced its ten `PHASE_BATCH` begin arguments), `RecommendationEtaEstimatorTest` 4, `RecommendationRunAdvancerTest` 1, `RecommendationRunPurgerTest` 2, `RecommendationRunStarterTest` 2, `RecordedCallTest` 3 (`RecommendationRunLogTest` is gone since C4).
- `TERMINAL_STATUSES`: the entity and `Http/RecommendationRunHistoryJson.php`.

---

### Task E1: `RunStatus`

**Files:**
- Create: `src/Enum/RunStatus.php`; `tests/Enum/RunStatusTest.php`
- Create (not committed): `var/refactor-1162/enum-constants.php`, `var/refactor-1162/run-status.php`
- Modify: `src/Entity/RecommendationRun.php`
- Modify (script, then the listed hand edits): the `STATUS_` files of E0 Step 2; `src/Repository/RecommendationRunRepository.php`, `RecommendationRunHistoryRepository.php`; `src/Http/RecommendationRunHistoryJson.php`, `RecommendationDebugLogJson.php`; `src/Service/Recommendation/Run/RecommendationRunReport.php`, `RecommendationRunPurger.php`, `RecommendationEtaEstimator.php`
- Test (hand edits after the script): `RecommendationRunAdvancerTest`, `RecommendationRunStarterTest`, `RecommendationDebugLogControllerTest`, `RecommendationRunHistoryJsonTest`, `RecommendationFeedTest`, `RecommendationRunRepositoryTest`, `RecommendationForYouSummaryProviderTest`

**Interfaces:**
- Produces: `enum App\Enum\RunStatus: string { Pending = 'pending'; Running = 'running'; Completed = 'completed'; Failed = 'failed'; Cancelled = 'cancelled'; public function isTerminal(): bool; public function isActive(): bool }`. `RecommendationRun::getStatus(): RunStatus`; the five `STATUS_*` constants and `TERMINAL_STATUSES` are gone. `RecommendationRunRepository::statusOf(int): ?RunStatus`, `findLatestForUser(User, ?RunStatus $status = null)`. `HistoryRow['status']` is `RunStatus`. `RecommendationRunReport::$status` stays `string` (D14): `fromRun()` writes `->value`.

Doctrine facts this task relies on (ORM 3.6.7, read in `vendor/doctrine/orm/src`): an `enumType` field bound as a parameter goes out as its `->value` (`AbstractQuery::processParameterValue`, arrays too); array hydration of a selected enum field returns the enum (`AbstractHydrator::gatherRowData`); single-scalar hydration does not (`gatherScalarRowData`), so `statusOf()` converts with `RunStatus::from()`.

- [ ] **Step 1: Write the failing test**

`tests/Enum/RunStatusTest.php`:
```php
<?php

declare(strict_types=1);

namespace App\Tests\Enum;

use App\Enum\RunStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RunStatusTest extends TestCase
{
    /** The stored `recommendation_run.status` strings: changing one needs a data migration. */
    public function testTheStoredValuesAreTheOldConstants(): void
    {
        self::assertSame(
            ['pending', 'running', 'completed', 'failed', 'cancelled'],
            array_map(static fn (RunStatus $status): string => $status->value, RunStatus::cases()),
        );
    }

    #[DataProvider('activeStatuses')]
    public function testAnActiveStatusIsNotTerminal(RunStatus $status): void
    {
        self::assertTrue($status->isActive());
        self::assertFalse($status->isTerminal());
    }

    #[DataProvider('terminalStatuses')]
    public function testATerminalStatusIsNotActive(RunStatus $status): void
    {
        self::assertTrue($status->isTerminal());
        self::assertFalse($status->isActive());
    }

    /** @return iterable<string, array{RunStatus}> */
    public static function activeStatuses(): iterable
    {
        yield 'pending' => [RunStatus::Pending];
        yield 'running' => [RunStatus::Running];
    }

    /** @return iterable<string, array{RunStatus}> */
    public static function terminalStatuses(): iterable
    {
        yield 'completed' => [RunStatus::Completed];
        yield 'failed' => [RunStatus::Failed];
        yield 'cancelled' => [RunStatus::Cancelled];
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php bin/phpunit tests/Enum/RunStatusTest.php`
Expected: FAIL: `Class "App\Enum\RunStatus" not found`.

- [ ] **Step 3: The enum**

`src/Enum/RunStatus.php`:
```php
<?php

declare(strict_types=1);

namespace App\Enum;

enum RunStatus: string
{
    case Pending = 'pending';
    case Running = 'running';
    case Completed = 'completed';
    case Failed = 'failed';

    /** Terminal, and reached only by the user stopping the run themselves. */
    case Cancelled = 'cancelled';

    /** Over for good. resume() keeps completedAt, so "has a completion time" is not this question (#409). */
    public function isTerminal(): bool
    {
        return match ($this) {
            self::Completed, self::Failed, self::Cancelled => true,
            self::Pending, self::Running => false,
        };
    }

    public function isActive(): bool
    {
        return !$this->isTerminal();
    }
}
```
Run the Step 2 command. Expected: PASS.

- [ ] **Step 4: The entity maps it**

`src/Entity/RecommendationRun.php`:
- Delete
```php
    public const string STATUS_PENDING = 'pending';
    public const string STATUS_RUNNING = 'running';
    public const string STATUS_COMPLETED = 'completed';
    public const string STATUS_FAILED = 'failed';

    /** Terminal, and reached only by the user stopping the run themselves. */
    public const string STATUS_CANCELLED = 'cancelled';

    /**
     * The statuses that mean the run is over. resume() deliberately leaves
     * completedAt standing, so "carries a completion time" and "has finished"
     * are two different questions: anything that reports a run as finished has
     * to ask this one (#409).
     *
     * @var list<string>
     */
    public const array TERMINAL_STATUSES = [
        self::STATUS_COMPLETED,
        self::STATUS_FAILED,
        self::STATUS_CANCELLED,
    ];

```
- Then:
```bash
perl -pi -e 's/self::STATUS_PENDING/RunStatus::Pending/g; s/self::STATUS_RUNNING/RunStatus::Running/g; s/self::STATUS_COMPLETED/RunStatus::Completed/g; s/self::STATUS_FAILED/RunStatus::Failed/g; s/self::STATUS_CANCELLED/RunStatus::Cancelled/g' src/Entity/RecommendationRun.php
git grep -c "RunStatus::" -- src/Entity/RecommendationRun.php
```
Expected: `18`.
- Replace
```php
    #[ORM\Column(length: 16)]
    private string $status = RunStatus::Pending;
```
with
```php
    #[ORM\Column(length: 16, enumType: RunStatus::class)]
    private RunStatus $status = RunStatus::Pending;
```
- `public function getStatus(): string` becomes `public function getStatus(): RunStatus`.
- `private function terminate(string $status, \DateTimeImmutable $when): void` becomes `private function terminate(RunStatus $status, \DateTimeImmutable $when): void`.
- `private function guardStatus(string $requiredStatus, string $transition): void` becomes `private function guardStatus(RunStatus $requiredStatus, string $transition): void`.
- In the docblock of `guardStatusOneOf()`, `@param list<string> $allowedStatuses` becomes `@param list<RunStatus> $allowedStatuses`.
- In `guardStatusOneOf()`'s `sprintf`, the argument `$this->status,` becomes `$this->status->value,` (the message text is unchanged).
- Add `use App\Enum\RunStatus;` directly above `use App\Repository\RecommendationRunRepository;`.

- [ ] **Step 5: Rewrite every other constant reference**

`var/refactor-1162/enum-constants.php` (self-contained; E3 runs it again):
```php
<?php

declare(strict_types=1);

// php var/refactor-1162/enum-constants.php var/refactor-1162/<spec>.php
// The spec returns ['enum' => FQCN, 'owner' => FQCN, 'constants' => [CONSTANT => Case]]. Every Owner::CONSTANT
// in src/ and tests/ becomes Enum::Case; the enum is imported, and the owner's import goes where nothing names it.

function shortNameOf(string $class): string
{
    $separator = strrpos($class, '\\');

    return false === $separator ? $class : substr($class, $separator + 1);
}

function namespaceOf(string $class): string
{
    $separator = strrpos($class, '\\');

    return false === $separator ? '' : substr($class, 0, $separator);
}

function declaredNamespace(string $code): string
{
    return 1 === preg_match('/^namespace ([^;]+);$/m', $code, $match) ? $match[1] : '';
}

function mentions(string $code, string $shortName): bool
{
    foreach (token_get_all($code) as $token) {
        if (!is_array($token)) {
            continue;
        }
        if (T_STRING === $token[0] && $shortName === $token[1]) {
            return true;
        }
        $tagged = '/@\S+[^\n]*(?<![\w\\\\])' . preg_quote($shortName, '/') . '(?!\w)/';
        if (T_DOC_COMMENT === $token[0] && 1 === preg_match($tagged, $token[1])) {
            return true;
        }
    }

    return false;
}

function removeImport(string $code, string $class): string
{
    $trimmed = str_replace("use {$class};\n", '', $code);
    if ($trimmed === $code) {
        return $code;
    }

    return (string) preg_replace_callback(
        '/^(namespace [^;]+;\n)\n\n+/m',
        static fn (array $match): string => $match[1] . "\n",
        $trimmed,
    );
}

function addImport(string $code, string $class): string
{
    $line = "use {$class};\n";
    if (str_contains($code, "\n{$line}")) {
        return $code;
    }
    preg_match_all(
        '/^use (?!function |const )([\w\\\\]+)(?: as \w+)?;\n/m',
        $code,
        $imports,
        PREG_SET_ORDER | PREG_OFFSET_CAPTURE,
    );
    if ([] === $imports) {
        $namespaceEnd = (int) strpos($code, ";\n", (int) strpos($code, "\nnamespace ")) + 2;

        return substr_replace($code, "\n" . $line, $namespaceEnd, 0);
    }
    foreach ($imports as $import) {
        if (strcasecmp($import[1][0], $class) > 0) {
            return substr_replace($code, $line, $import[0][1], 0);
        }
    }
    $last = $imports[array_key_last($imports)];

    return substr_replace($code, $line, $last[0][1] + strlen($last[0][0]), 0);
}

/** @var array{enum: string, owner: string, constants: array<string, string>} $spec */
$spec = require $argv[1];
$enumShort = shortNameOf($spec['enum']);
$ownerShort = shortNameOf($spec['owner']);
$names = implode('|', array_keys($spec['constants']));
$files = array_values(array_filter(explode("\n", (string) shell_exec(
    'git grep -lE ' . escapeshellarg($ownerShort . '::(' . $names . ')') . ' -- src tests',
))));

foreach ($files as $file) {
    $code = (string) preg_replace_callback(
        '/(?<![\w\\\\])' . $ownerShort . '::(' . $names . ')(?!\w)/',
        static fn (array $match): string => $enumShort . '::' . $spec['constants'][$match[1]],
        (string) file_get_contents($file),
    );
    if (namespaceOf($spec['enum']) !== declaredNamespace($code)) {
        $code = addImport($code, $spec['enum']);
    }
    if (!mentions($code, $ownerShort)) {
        $code = removeImport($code, $spec['owner']);
    }
    file_put_contents($file, $code);
    echo "Rewrote {$file}\n";
}
```

`var/refactor-1162/run-status.php`:
```php
<?php

declare(strict_types=1);

return [
    'enum' => 'App\\Enum\\RunStatus',
    'owner' => 'App\\Entity\\RecommendationRun',
    'constants' => [
        'STATUS_PENDING' => 'Pending',
        'STATUS_RUNNING' => 'Running',
        'STATUS_COMPLETED' => 'Completed',
        'STATUS_FAILED' => 'Failed',
        'STATUS_CANCELLED' => 'Cancelled',
    ],
];
```

```bash
php var/refactor-1162/enum-constants.php var/refactor-1162/run-status.php
git grep -n "STATUS_PENDING\|STATUS_RUNNING\|STATUS_COMPLETED\|STATUS_FAILED\|STATUS_CANCELLED" -- src tests
```
Expected: 23 `Rewrote …` lines (the E0 Step 2 list), and the grep prints nothing.

- [ ] **Step 6: The hand edits the script cannot make**

`src`:
- `src/Repository/RecommendationRunRepository.php`:
  - Above `ACTIVE_STATUSES`, `@var list<string>` becomes `@var list<RunStatus>`.
  - `statusOf()` becomes
```php
    public function statusOf(int $runId): ?RunStatus
    {
        /** @var string|null $status */
        $status = $this->createQueryBuilder('r')
            ->select('r.status')
            ->andWhere('r.id = :id')->setParameter('id', $runId)
            ->getQuery()
            ->getOneOrNullResult(AbstractQuery::HYDRATE_SINGLE_SCALAR);

        return null === $status ? null : RunStatus::from($status);
    }
```
  - `public function findLatestForUser(User $user, ?string $status = null): ?RecommendationRun` becomes `public function findLatestForUser(User $user, ?RunStatus $status = null): ?RecommendationRun`.
- `src/Repository/RecommendationRunHistoryRepository.php`: in the `HistoryRow` type, ` *     status: string,` becomes ` *     status: RunStatus,`; add `use App\Enum\RunStatus;` after `use App\Entity\User;`.
- `src/Http/RecommendationRunHistoryJson.php`:
  - In `row()`, `'status' => $run['status'],` becomes `'status' => $run['status']->value,`.
  - In `completionOf()`, `if (!\in_array($run['status'], RecommendationRun::TERMINAL_STATUSES, true)) {` becomes `if (!$run['status']->isTerminal()) {`.
  - Delete `use App\Entity\RecommendationRun;` (its only use was `TERMINAL_STATUSES`).
- `src/Http/RecommendationDebugLogJson.php`: both `'status' => $run->getStatus(),` become `'status' => $run->getStatus()->value,`.
- `src/Service/Recommendation/Run/RecommendationRunReport.php`, in `fromRun()`: `$run->getStatus(),` becomes `$run->getStatus()->value,`.
- `src/Service/Recommendation/Run/RecommendationRunPurger.php`: replace
```php
        $active = [RunStatus::Pending, RunStatus::Running];
        if (null !== $latest && \in_array($latest->getStatus(), $active, true)) {
```
with
```php
        if (null !== $latest && $latest->getStatus()->isActive()) {
```
and delete `use App\Enum\RunStatus;` (the script added it; nothing names it now).
- `src/Service/Recommendation/Run/RecommendationEtaEstimator.php`: replace `isInFlight()` with
```php
    private function isInFlight(RecommendationRunReport $report): bool
    {
        return RunStatus::tryFrom($report->status)?->isActive() ?? false;
    }
```
(`none` and `busy` are not run states: `tryFrom()` gives null.)

`tests`:
```bash
perl -pi -e 's/(RunStatus::\w+)(, \$report->status\);)/$1->value$2/' tests/Service/Recommendation/Run/RecommendationRunAdvancerTest.php tests/Service/Recommendation/Run/RecommendationRunStarterTest.php
perl -pi -e "s/(RunStatus::Pending)(, \\\$run\['status'\]\);)/\$1->value\$2/" tests/Controller/Api/RecommendationDebugLogControllerTest.php
perl -pi -e "s/self::assertSame\('cancelled', \\\$persisted->getStatus\(\)\);/self::assertSame(RunStatus::Cancelled, \\\$persisted->getStatus());/" tests/Service/Recommendation/Run/RecommendationRunAdvancerTest.php
perl -pi -e 's/string \$status\b/RunStatus \$status/g; s/array\{string\}/array{RunStatus}/g' tests/Repository/RecommendationFeedTest.php tests/Repository/RecommendationRunRepositoryTest.php tests/Service/Recommendation/Feed/RecommendationForYouSummaryProviderTest.php
perl -pi -e 's/(\$payload\[\x27runs\x27\]\[0\]\[\x27(?:completedAt|durationSeconds)\x27\], )\$status\)/$1\$status->value)/' tests/Http/RecommendationRunHistoryJsonTest.php
git grep -c -e "->value, \$report->status);" -- tests/Service/Recommendation/Run
git grep -c "RunStatus \$status" -- tests/Repository/RecommendationFeedTest.php tests/Repository/RecommendationRunRepositoryTest.php tests/Service/Recommendation/Feed/RecommendationForYouSummaryProviderTest.php
git grep -n "\$status->value)" -- tests/Http/RecommendationRunHistoryJsonTest.php
```
Expected: `RecommendationRunAdvancerTest.php:3` and `RecommendationRunStarterTest.php:2`; `1`, `3` and `1` (`seedRun`, the two data-provider tests plus `persistRun`, `seedRun`); two lines in `testEveryTerminalStatusReportsWhenItEnded` (the assertion message must stay a string). `RecommendationRunAdvancerTest` gains the `RunStatus` import from the script; the `'cancelled'` line is its fifth `RunStatus::` use there.

- [ ] **Step 7: Run the tests to verify they pass**

```bash
bin/console cache:clear && bin/console cache:warmup
php bin/phpunit tests/Enum tests/Entity tests/Repository tests/Http tests/Controller/Api tests/Service/Recommendation tests/Service/Worker tests/Command/RecommendationDrainCommandTest.php
```
Expected: PASS. `RecommendationRunControllerTest`, `RecommendationDebugLogControllerTest` and `RecommendationRunHistoryControllerTest` are unedited apart from the one `->value` above: the wire is unchanged.

- [ ] **Step 8: No migration**

```bash
docker compose exec php bin/console cache:clear
docker compose exec php bin/console doctrine:schema:update --dump-sql
docker compose exec php bin/console doctrine:schema:validate
```
Expected: `[OK] Nothing to update - your database is already in sync with the current entity metadata.`; `[OK] The mapping files are correct.` and `[OK] The database schema is in sync with the mapping files.` (`enumType` on a `string` column emits no DDL; CI's migrate-from-empty leg re-checks SQLite and MySQL.) Any SQL printed: stop and report; this plan assumes none.

- [ ] **Step 9: Deletion checks**

Restore each by hand.
1. In `RunStatus::isTerminal()`, move `self::Cancelled` to the `false` arm. Expected: `RunStatusTest` (cancelled), `RecommendationRunHistoryJsonTest::testEveryTerminalStatusReportsWhenItEnded` fail.
2. In `RecommendationEtaEstimator::isInFlight()`, return `false`. Expected: `RecommendationEtaEstimatorTest` fails.
3. In `RecommendationRunRepository::statusOf()`, return `null`. Expected: `RecommendationTickCheckpointTest` and `RecommendationRunAdvancerTest::testARunStoppedDuringAProviderCallDoesNotRecordThatCallsResult` fail.

- [ ] **Step 10: Gates and commit**

`composer check` (`PersistenceKnowsNoServiceRule` accepts `App\Enum` in the entity), `composer md` (the entity's existing `TooManyPublicMethods` suppression stays, D16; no new finding), PhpStorm lint on every changed file.
```bash
git add src tests
git commit -m "refactor(#1162): a recommendation run's status is the RunStatus enum"
```

---

### Task E2: The debug-log rows carry values, `RecommendationDebugLogJson` formats them

Direction 5's last leak (audit above): `RecommendationRunLogRepository::listForRun()` formats the panel's timestamps as ATOM strings. The rows now carry `\DateTimeImmutable`; the mapper formats them. The wire is pinned by `RecommendationDebugLogControllerTest`, unedited.

**Files:**
- Modify: `src/Repository/RecommendationRunLogRepository.php` (`DebugLogRow`, two lines of `listForRun()`)
- Modify: `src/Http/RecommendationDebugLogJson.php` (class docblock, `list()`, new `entry()`)
- Test: `tests/Http/RecommendationDebugLogJsonTest.php` (fixture row, one test)
- Test: `tests/Repository/RecommendationRunLogRepositoryTest.php` (one line)
- Test: `tests/Service/Recommendation/Run/RecommendationRunAdvancerTest.php`, `RecommendationRunStarterTest.php`, `RecommendationCallRecorderTest.php` (row-shape docblocks)

**Interfaces:**
- Produces: `@phpstan-type DebugLogRow array{id: int, runId: int, phase: string, batchNumber: ?int, attempt: int, verdict: ?string, requestBytes: int, responseBytes: int, wireBytes: int, createdAt: \DateTimeImmutable, finishedAt: ?\DateTimeImmutable, errorDetail: ?string, finishReason: ?string}` (E3 types `phase` and `verdict`). `RecommendationDebugLogJson::entry(DebugLogRow): array<string, mixed>` (private).

- [ ] **Step 1: Write the failing test**

`tests/Http/RecommendationDebugLogJsonTest.php`:
- In `row()`, `'createdAt' => '2026-08-09T10:00:00+00:00',` becomes `'createdAt' => new \DateTimeImmutable('2026-08-09T10:00:00Z'),`.
- Add after `testEachRowCarriesItsStreamingTextOrNull()`:
```php
    public function testEachEntryCarriesItsTimesInAtomFormat(): void
    {
        $row = self::row(7);
        $row['finishedAt'] = new \DateTimeImmutable('2026-08-09T10:00:05Z');

        $entry = RecommendationDebugLogJson::list(new RecommendationDebugLog([$row], [], null, []))['entries'][0];

        self::assertSame('2026-08-09T10:00:00+00:00', $entry['createdAt']);
        self::assertSame('2026-08-09T10:00:05+00:00', $entry['finishedAt']);
    }
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php bin/phpunit tests/Http/RecommendationDebugLogJsonTest.php`
Expected: FAIL: `testEachEntryCarriesItsTimesInAtomFormat` gets a `DateTimeImmutable`, not a string.

- [ ] **Step 3: Implement**

`src/Repository/RecommendationRunLogRepository.php`:
- In the class docblock, ` *     createdAt: string, finishedAt: ?string, errorDetail: ?string, finishReason: ?string}` becomes ` *     createdAt: \DateTimeImmutable, finishedAt: ?\DateTimeImmutable, errorDetail: ?string, finishReason: ?string}`.
- In `listForRun()`'s mapping, replace
```php
                'createdAt' => $row['createdAt']->format(\DATE_ATOM),
                'finishedAt' => $row['finishedAt']?->format(\DATE_ATOM),
```
with
```php
                'createdAt' => $row['createdAt'],
                'finishedAt' => $row['finishedAt'],
```

`src/Http/RecommendationDebugLogJson.php`:
- Add `use App\Repository\RecommendationRunLogRepository;` after `use App\Entity\RecommendationRunLog;`.
- The class docblock becomes
```php
/**
 * Response shapes for the recommendation debug log (#309): poll-cheap, bodies never ride along, only sizes, except
 * the one call still streaming, whose growing text is the live view.
 *
 * @phpstan-import-type DebugLogRow from RecommendationRunLogRepository
 */
```
- In `list()`, replace `                    ...$row,` with `                    ...self::entry($row),`.
- Add after `list()`:
```php
    /**
     * @param DebugLogRow $row
     *
     * @return array<string, mixed>
     */
    private static function entry(array $row): array
    {
        return [
            ...$row,
            'createdAt' => $row['createdAt']->format(\DATE_ATOM),
            'finishedAt' => $row['finishedAt']?->format(\DATE_ATOM),
        ];
    }
```
A key written after the spread keeps the spread's position, so the entry's key order is unchanged.

`tests/Repository/RecommendationRunLogRepositoryTest.php`: in `testListReturnsMetadataWithByteSizesButNoBodies()`, replace `        $rows = $this->logs->listForRun($this->user, $run->requireId());` with
```php
        $rows = array_map(
            static fn (array $row): array => [
                ...$row,
                'createdAt' => $row['createdAt']->format(\DATE_ATOM),
                'finishedAt' => $row['finishedAt']?->format(\DATE_ATOM),
            ],
            $this->logs->listForRun($this->user, $run->requireId()),
        );
```
The expected arrays below it stay as they are.

The row-shape docblocks:
```bash
perl -0pi -e 's/ \* \@return list<array\{id: int, runId: int, phase: string, batchNumber: \?int, attempt: int,\n\s+\*     verdict: \?string, requestBytes: int, responseBytes: int, wireBytes: int,\n\s+\*     createdAt: string, finishedAt: \?string, errorDetail: \?string, finishReason: \?string\}>/ * \@return list<DebugLogRow>/g' tests/Service/Recommendation/Run/RecommendationRunAdvancerTest.php tests/Service/Recommendation/Run/RecommendationRunStarterTest.php tests/Service/Recommendation/Run/RecommendationCallRecorderTest.php
git grep -c "@return list<DebugLogRow>" -- tests/Service/Recommendation/Run
```
Expected: `RecommendationCallRecorderTest.php:1`, `RecommendationRunAdvancerTest.php:2`, `RecommendationRunStarterTest.php:1`. Then give each class the type import:
- `RecommendationRunAdvancerTest` and `RecommendationRunStarterTest`: add ` *` and ` * @phpstan-import-type DebugLogRow from RecommendationRunLogRepository` as the last two lines of the class docblock, before its ` */`.
- `RecommendationCallRecorderTest` has no class docblock: add directly above `final class RecommendationCallRecorderTest`:
```php
/**
 * @phpstan-import-type DebugLogRow from RecommendationRunLogRepository
 */
```
All three already import `RecommendationRunLogRepository`.

- [ ] **Step 4: Run the tests to verify they pass**

Run: `php bin/phpunit tests/Http/RecommendationDebugLogJsonTest.php tests/Repository/RecommendationRunLogRepositoryTest.php tests/Controller/Api/RecommendationDebugLogControllerTest.php tests/Service/Recommendation`
Expected: PASS; the controller test's list entry (both ATOM timestamps, every key in order) is byte-identical.

- [ ] **Step 5: Deletion check**

In `entry()`, drop the `'createdAt' => …` line. Expected: the new mapper test and `RecommendationDebugLogControllerTest::testListReturnsEntriesWithStreamingTextOnlyForTheOpenCall` fail. Restore by hand.

- [ ] **Step 6: Gates and commit**

`composer check`, `composer md`, PhpStorm lint.
```bash
git add src/Repository/RecommendationRunLogRepository.php src/Http/RecommendationDebugLogJson.php tests/Http/RecommendationDebugLogJsonTest.php tests/Repository/RecommendationRunLogRepositoryTest.php tests/Service/Recommendation/Run/RecommendationRunAdvancerTest.php tests/Service/Recommendation/Run/RecommendationRunStarterTest.php tests/Service/Recommendation/Run/RecommendationCallRecorderTest.php
git commit -m "refactor(#1162): the debug-log rows carry times, the mapper formats them"
```

---

### Task E3: `CallPhase` and `CallVerdict`

**Files:**
- Create: `src/Enum/CallPhase.php`, `src/Enum/CallVerdict.php`; `tests/Enum/CallPhaseTest.php`, `tests/Enum/CallVerdictTest.php`
- Create (not committed): `var/refactor-1162/call-phase.php`, `var/refactor-1162/call-verdict.php`
- Modify: `src/Entity/RecommendationRunLog.php`, `src/Entity/CallOutcome.php` (rewritten)
- Modify: `src/Repository/RecommendationCallRepository.php`, `RecommendationRunLogRepository.php`, `RecommendationRunTimingRepository.php`
- Modify: `src/Service/Recommendation/Run/RecordedCall.php`, `CallSlot.php`, `PhaseDurations.php`; `src/Http/RecommendationDebugLogJson.php`
- Test: `tests/Http/RecommendationDebugLogJsonTest.php` (fixture row, one test); the `PHASE_`/`VERDICT_` test files of E0 Step 2 (script), plus the hand edits listed in Step 6; `tests/Support/RecommendationRunFixtures.php`

**Interfaces:**
- Consumes: `var/refactor-1162/enum-constants.php` (E1 Step 5).
- Produces: `enum App\Enum\CallPhase: string { Distill = 'distill'; Batch = 'batch'; Consolidate = 'consolidate' }`, `enum App\Enum\CallVerdict: string { Usable = 'usable'; Unusable = 'unusable'; TransportFailed = 'transport-failed' }`. `RecommendationRunLog::__construct(RecommendationRun, CallPhase, ?int, int, string, \DateTimeImmutable)`, `getPhase(): CallPhase`, `getVerdict(): ?CallVerdict`; the six constants are gone. `CallOutcome::$verdict` is `CallVerdict`. `CallSlot::$phase` is `CallPhase`; `RecommendationRunLogRepository::countAttempts(RecommendationRun, CallPhase, ?int)`. `DebugLogRow['phase']` is `CallPhase`, `['verdict']` is `?CallVerdict`; the timing spans' `phase` is `CallPhase`. `RecommendationRunFixtures::log(RecommendationRun, CallPhase, ?int, int, string, ?\DateTimeImmutable)`.

- [ ] **Step 1: Write the failing tests**

`tests/Enum/CallPhaseTest.php`:
```php
<?php

declare(strict_types=1);

namespace App\Tests\Enum;

use App\Enum\CallPhase;
use PHPUnit\Framework\TestCase;

final class CallPhaseTest extends TestCase
{
    /** The stored `recommendation_run_log.phase` strings: changing one needs a data migration. */
    public function testTheStoredValuesAreTheOldConstants(): void
    {
        self::assertSame(
            ['distill', 'batch', 'consolidate'],
            array_map(static fn (CallPhase $phase): string => $phase->value, CallPhase::cases()),
        );
    }
}
```

`tests/Enum/CallVerdictTest.php`:
```php
<?php

declare(strict_types=1);

namespace App\Tests\Enum;

use App\Enum\CallVerdict;
use PHPUnit\Framework\TestCase;

final class CallVerdictTest extends TestCase
{
    /** The stored `recommendation_run_log.verdict` strings: changing one needs a data migration. */
    public function testTheStoredValuesAreTheOldConstants(): void
    {
        self::assertSame(
            ['usable', 'unusable', 'transport-failed'],
            array_map(static fn (CallVerdict $verdict): string => $verdict->value, CallVerdict::cases()),
        );
    }
}
```

`tests/Http/RecommendationDebugLogJsonTest.php`:
- Add, directly above `use App\Http\RecommendationDebugLogJson;`:
```php
use App\Enum\CallPhase;
use App\Enum\CallVerdict;
```
- In `row()`, `'phase' => 'batch',` becomes `'phase' => CallPhase::Batch,`.
- Add after `testEachEntryCarriesItsTimesInAtomFormat()`:
```php
    public function testEachEntryCarriesItsPhaseAndVerdictAsWireStrings(): void
    {
        $row = self::row(7);
        $row['verdict'] = CallVerdict::TransportFailed;

        $entry = RecommendationDebugLogJson::list(new RecommendationDebugLog([$row], [], null, []))['entries'][0];

        self::assertSame('batch', $entry['phase']);
        self::assertSame('transport-failed', $entry['verdict']);
    }
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php bin/phpunit tests/Enum/CallPhaseTest.php tests/Enum/CallVerdictTest.php tests/Http/RecommendationDebugLogJsonTest.php`
Expected: FAIL: `Class "App\Enum\CallPhase" not found`, `Class "App\Enum\CallVerdict" not found`.

- [ ] **Step 3: The enums**

`src/Enum/CallPhase.php`:
```php
<?php

declare(strict_types=1);

namespace App\Enum;

enum CallPhase: string
{
    case Distill = 'distill';
    case Batch = 'batch';
    case Consolidate = 'consolidate';
}
```

`src/Enum/CallVerdict.php`:
```php
<?php

declare(strict_types=1);

namespace App\Enum;

enum CallVerdict: string
{
    case Usable = 'usable';
    case Unusable = 'unusable';
    case TransportFailed = 'transport-failed';
}
```
Run `php bin/phpunit tests/Enum`. Expected: PASS. (`testEachEntryCarriesItsPhaseAndVerdictAsWireStrings` still fails until Step 4's mapper edit: the entry carries the enum.)

- [ ] **Step 4: The entity, the outcome and the writers**

`src/Entity/RecommendationRunLog.php`:
- Delete
```php
    public const string PHASE_BATCH = 'batch';
    public const string PHASE_DISTILL = 'distill';
    public const string PHASE_CONSOLIDATE = 'consolidate';

    public const string VERDICT_USABLE = 'usable';
    public const string VERDICT_UNUSABLE = 'unusable';
    public const string VERDICT_TRANSPORT_FAILED = 'transport-failed';

```
- Replace
```php
    #[ORM\Column(length: 16)]
    private string $phase;
```
with
```php
    #[ORM\Column(length: 16, enumType: CallPhase::class)]
    private CallPhase $phase;
```
- Replace
```php
    #[ORM\Column(length: 24, nullable: true)]
    private ?string $verdict = null;
```
with
```php
    #[ORM\Column(length: 24, nullable: true, enumType: CallVerdict::class)]
    private ?CallVerdict $verdict = null;
```
- In the constructor, `        string $phase,` becomes `        CallPhase $phase,`.
- `public function getPhase(): string` becomes `public function getPhase(): CallPhase`; `public function getVerdict(): ?string` becomes `public function getVerdict(): ?CallVerdict`.
- Add above `use App\Repository\RecommendationRunLogRepository;`:
```php
use App\Enum\CallPhase;
use App\Enum\CallVerdict;
```

`src/Entity/CallOutcome.php` (rewritten in full):
```php
<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\CallVerdict;

final readonly class CallOutcome
{
    public function __construct(
        public CallVerdict $verdict,
        public int $wireBytes,
        public \DateTimeImmutable $finishedAt,
        public ?string $finishReason,
    ) {
    }
}
```

```bash
perl -pi -e 's/\x27verdict\x27 => \$outcome->verdict,/\x27verdict\x27 => \$outcome->verdict->value,/g' src/Repository/RecommendationCallRepository.php
git grep -c "outcome->verdict->value" -- src/Repository/RecommendationCallRepository.php
perl -pi -e 's/string \$phase\b/CallPhase \$phase/g' src/Service/Recommendation/Run/CallSlot.php src/Repository/RecommendationRunLogRepository.php
perl -pi -e 's/phase: string/phase: CallPhase/g; s/verdict: \?string/verdict: ?CallVerdict/g' src/Repository/RecommendationRunLogRepository.php src/Repository/RecommendationRunTimingRepository.php src/Service/Recommendation/Run/PhaseDurations.php
git grep -c "CallPhase \$phase" -- src/Service/Recommendation/Run/CallSlot.php src/Repository/RecommendationRunLogRepository.php
git grep -c "phase: CallPhase" -- src/Repository/RecommendationRunLogRepository.php src/Repository/RecommendationRunTimingRepository.php src/Service/Recommendation/Run/PhaseDurations.php
```
Expected: `2` (the DBAL writes, answered and transport-failed); `1` (`CallSlot`'s promoted property) and `1` (`countAttempts`); `2`, `2`, `2` (`DebugLogRow` and the raw `@var`; the raw `@var` and `@return`; `fromCompletedRunSpans()` and `groupByRun()`).

Imports these hand edits need (each in its sorted place):
- `src/Service/Recommendation/Run/CallSlot.php` gets `use App\Enum\CallPhase;` from the Step 5 script (it names the `PHASE_` constants), which also drops its `RecommendationRunLog` import. `RecommendationCallRecorder` needs no edit: it passes `$slot->phase` through.
- `src/Repository/RecommendationRunLogRepository.php`: `use App\Enum\CallPhase;` and `use App\Enum\CallVerdict;` after `use App\Entity\User;`.
- `src/Repository/RecommendationRunTimingRepository.php`: `use App\Enum\CallPhase;` after `use App\Entity\User;`.

`src/Service/Recommendation/Run/RecordedCall.php`:
- `private function finish(string $content, string $verdict): void` becomes `private function finish(string $content, CallVerdict $verdict): void`.
- `private function settlement(int $logId, string $verdict): CallSettlement` becomes `private function settlement(int $logId, CallVerdict $verdict): CallSettlement`.
(The script in Step 5 rewrites its three constants and swaps its `RecommendationRunLog` import for `CallVerdict`.)

`src/Http/RecommendationDebugLogJson.php`:
- In `entry()`, after `...$row,` add
```php
            'phase' => $row['phase']->value,
            'verdict' => $row['verdict']?->value,
```
- In `detail()`, `'phase' => $log->getPhase(),` becomes `'phase' => $log->getPhase()->value,` and `'verdict' => $log->getVerdict(),` becomes `'verdict' => $log->getVerdict()?->value,`.

- [ ] **Step 5: Rewrite every constant reference**

`var/refactor-1162/call-phase.php`:
```php
<?php

declare(strict_types=1);

return [
    'enum' => 'App\\Enum\\CallPhase',
    'owner' => 'App\\Entity\\RecommendationRunLog',
    'constants' => [
        'PHASE_DISTILL' => 'Distill',
        'PHASE_BATCH' => 'Batch',
        'PHASE_CONSOLIDATE' => 'Consolidate',
    ],
];
```
`var/refactor-1162/call-verdict.php`:
```php
<?php

declare(strict_types=1);

return [
    'enum' => 'App\\Enum\\CallVerdict',
    'owner' => 'App\\Entity\\RecommendationRunLog',
    'constants' => [
        'VERDICT_USABLE' => 'Usable',
        'VERDICT_UNUSABLE' => 'Unusable',
        'VERDICT_TRANSPORT_FAILED' => 'TransportFailed',
    ],
];
```
```bash
php var/refactor-1162/enum-constants.php var/refactor-1162/call-phase.php
php var/refactor-1162/enum-constants.php var/refactor-1162/call-verdict.php
git grep -n "PHASE_DISTILL\|PHASE_BATCH\|PHASE_CONSOLIDATE\|VERDICT_USABLE\|VERDICT_UNUSABLE\|VERDICT_TRANSPORT_FAILED" -- src tests
```
Expected: the `Rewrote …` lines for the E0 Step 2 files, and the grep prints nothing.

`src/Service/Recommendation/Run/PhaseDurations.php` keys its per-run map by string:
```bash
perl -pi -e 's/\$phases\[CallPhase::(\w+)\]/\$phases[CallPhase::$1->value]/g; s/\[\$span\[\x27phase\x27\]\]/[\$span[\x27phase\x27]->value]/' src/Service/Recommendation/Run/PhaseDurations.php
git grep -c -e "->value\]" -- src/Service/Recommendation/Run/PhaseDurations.php
```
Expected: `4`.

- [ ] **Step 6: The test edits the script cannot make**

```bash
perl -pi -e 's/\[\x27transport-failed\x27\]/[CallVerdict::TransportFailed]/g; s/\[\x27usable\x27\]/[CallVerdict::Usable]/g; s/\[\x27unusable\x27\]/[CallVerdict::Unusable]/g; s/\[\x27unusable\x27, \x27usable\x27\]/[CallVerdict::Unusable, CallVerdict::Usable]/g' tests/Service/Recommendation/Run/RecommendationRunAdvancerTest.php tests/Service/Recommendation/Run/RecommendationProfileDistillerTest.php tests/Service/Recommendation/Run/RecommendationConsolidationResolverTest.php
perl -pi -e 's/list<\?string>/list<?CallVerdict>/g' tests/Service/Recommendation/Run/RecommendationProfileDistillerTest.php tests/Service/Recommendation/Run/RecommendationConsolidationResolverTest.php
perl -pi -e 's/\[\x27(distill|batch|consolidate)\x27, (null|\d), \x27usable\x27\]/"[CallPhase::" . ucfirst($1) . ", $2, CallVerdict::Usable]"/e' tests/Service/Recommendation/Run/RecommendationRunAdvancerTest.php
perl -pi -e 's/self::assertSame\(\x27batch\x27, \$rows\[0\]\[\x27phase\x27\]\);/self::assertSame(CallPhase::Batch, \$rows[0][\x27phase\x27]);/; s/(\x27verdict\x27 => CallVerdict::Usable)\]/$1->value]/' tests/Service/Recommendation/Run/RecommendationCallRecorderTest.php
perl -pi -e 's/\x27phase\x27 => \x27batch\x27/\x27phase\x27 => CallPhase::Batch/g; s/\x27phase\x27 => \x27consolidate\x27/\x27phase\x27 => CallPhase::Consolidate/g; s/\x27verdict\x27 => \x27usable\x27/\x27verdict\x27 => CallVerdict::Usable/g' tests/Repository/RecommendationRunLogRepositoryTest.php
perl -pi -e 's/string \$phase\b/CallPhase \$phase/g; s/phase: string/phase: CallPhase/g' tests/Support/RecommendationRunFixtures.php tests/Repository/RecommendationRunTimingRepositoryTest.php tests/Service/Recommendation/Run/RecommendationEtaEstimatorTest.php tests/Service/Recommendation/Run/PhaseDurationsTest.php
git grep -n "\x27transport-failed\x27\|\x27usable\x27\|\x27unusable\x27" -- tests/Service tests/Repository/RecommendationRunLogRepositoryTest.php tests/Http/RecommendationDebugLogJsonTest.php
```
Expected: the last grep prints nothing (the controller test, which pins the wire strings, is not in its paths).

Then by hand:
- `tests/Repository/RecommendationRunTimingRepositoryTest.php`, in `testReturnsEachPhaseWallSpanWithBatchCount()`, replace `        $spans = $this->timings->completedRunPhaseSpans($this->user, 10);` with
```php
        $spans = array_map(
            static fn (array $span): array => [...$span, 'phase' => $span['phase']->value],
            $this->timings->completedRunPhaseSpans($this->user, 10),
        );
```
(the canonicalising comparison sorts, and enum cases do not order; the expected rows keep their strings).
- Imports the script did not add, in their sorted place after the last `use App\Entity\…;` line:
  - `use App\Enum\CallPhase;` in `tests/Support/RecommendationRunFixtures.php`;
  - `use App\Enum\CallVerdict;` in `tests/Service/Recommendation/Run/RecommendationRunAdvancerTest.php` (after its `use App\Enum\CallPhase;`), `RecommendationProfileDistillerTest.php`, `RecommendationConsolidationResolverTest.php`;

- [ ] **Step 7: Run the tests to verify they pass**

```bash
bin/console cache:clear && bin/console cache:warmup
php bin/phpunit
```
Expected: PASS. `RecommendationDebugLogControllerTest` (list and detail) is unedited by this task: `'phase' => 'batch'`, `'verdict' => 'usable'` still come back.

- [ ] **Step 8: No migration**

```bash
docker compose exec php bin/console cache:clear
docker compose exec php bin/console doctrine:schema:update --dump-sql
docker compose exec php bin/console doctrine:schema:validate
```
Expected: `[OK] Nothing to update - your database is already in sync with the current entity metadata.`; `[OK] The mapping files are correct.` and `[OK] The database schema is in sync with the mapping files.` Any SQL printed: stop and report; this plan assumes none.

- [ ] **Step 9: Deletion checks**

Restore each by hand.
1. In `RecommendationCallRepository::settleAnswered()`, write `$outcome->verdict` without `->value`. Expected: every test that settles a call errors (DBAL cannot bind the enum), e.g. `RecordedCallTest::testFinishUsableWritesTheFinishedAtTimestamp`.
2. In `RecommendationDebugLogJson::entry()`, delete the `'verdict' => $row['verdict']?->value,` line. Expected: `RecommendationDebugLogJsonTest::testEachEntryCarriesItsPhaseAndVerdictAsWireStrings` fails. (The controller tests cannot catch a missing `->value`: `json_encode` writes a backed enum as its value. The mapper test is the pin.)
3. In `PhaseDurations::runDurations()`, key the distill lookup by `CallPhase::Batch->value`. Expected: `PhaseDurationsTest` fails.

- [ ] **Step 10: Gates and commit**

`composer check`, `composer md`, PhpStorm lint.
```bash
git add src tests
git commit -m "refactor(#1162): a run-log row's phase and verdict are the CallPhase and CallVerdict enums"
```

---

### Finishing PR E

- [ ] **Step 1: Per-PR gates**

`php bin/phpunit`, `docker compose exec php composer test`, `composer check`, `composer md`, `composer infection:diff`, PhpStorm lint on all changed PHP. CI's migrate-from-empty leg must pass on SQLite and MySQL with `doctrine:schema:validate` clean.

- [ ] **Step 2: Real run**

Run the **Real run** procedure. Step 5 reads `status = 'completed'` and step 6 reads `phase`/`verdict` straight from the tables: the stored bytes must be the old strings.

- [ ] **Step 3: Close-out check**

```bash
git grep -n "ExcessiveParameterList" -- src/Service/Recommendation/Run
git grep -n -e "->settle(" -e "->finish(" -- src/Service/Recommendation src/Entity
git grep -c "FAVORITES (newest first)" -- src
git grep -n "TOKENS_PER_SCORE_PICK" -- src/Service/Recommendation/Prompt/RecommendationPromptBuilder.php
git grep -n "STATUS_PENDING\|PHASE_BATCH\|VERDICT_USABLE" -- src tests
ls src/Service/Recommendation src/Service/Ai/Completion | head -n 30
```
Expected: nothing, nothing, `src/Service/Recommendation/Prompt/RecommendationPromptBuilder.php:1`, nothing, nothing; the module layout of PR A.

- [ ] **Step 4: PR**

```bash
git push -u origin refactor/1162-run-state-enums
gh pr create --base develop --title "refactor(#1162): run status, call phase and verdict are enums; the debug-log rows carry values" --body "$(cat <<'EOF'
Closes #1162 (PR E, the last of four; A, B and C are merged).

- `RecommendationRun::$status` is `App\Enum\RunStatus` (`isActive()`, `isTerminal()` replace `TERMINAL_STATUSES` and the ad-hoc active lists).
- `RecommendationRunLog::$phase` and `$verdict` are `App\Enum\CallPhase` and `App\Enum\CallVerdict`; `CallOutcome` carries the verdict enum.
- Mapped with `enumType` on the existing string columns: the stored bytes are the old constant values, no migration (schema:update prints nothing; CI's migrate-from-empty leg validates SQLite and MySQL).
- The debug-log rows carry `\DateTimeImmutable` and enums; `RecommendationDebugLogJson` formats them (the last presentation leak Direction 5 names; #1158 and #1182 moved the rest).

Wire: none; the run report, debug-log and history controller tests pin every string. Real run: <paste steps 3, 5, 6 and 7>.
EOF
)"
```
After the merge, confirm `gh issue view 1162 --json state --jq .state` prints `CLOSED`; do not close it by hand.

- [ ] **Step 5: Hand the follow-ups to the planner**

The final report reminds the planner of what #1162 closing opens (carry-forward; the implementer opens no issue):
- the D16 follow-up issue: the PHPMD suppressions on `EffectiveRecommendationSettings` (ExcessiveParameterList), `App\Entity\RecommendationSettingsValues` (ExcessiveParameterList, D-reconcile-2) and `RecommendationRun` (TooManyPublicMethods);
- #1171: the comments of the classes PR A moved (D6);
- #1169: moving `TickDriver` to `App\Enum` would take `retryPlan()` off it (D-reconcile-1).

### Execution rulings (PR E)

- **Preflight:** a light read-only scan at 8a3413e1. Every E1–E3 anchor still matched.
- **E4 (planner, from PR C's M10):** `RecordedCall`'s log id is an `int`, read with `requireId()` where the row is persisted, and the null guards are gone.
  - `testBanksTheUsageWithTheDebugSwitchOff` is deleted. The recorder has always passed an id since #638.
  - `testLeavesTheCostNullWhenTheProviderReportedNone` keeps its assertion with a real id.
- **E5, a stored-data change:** "no migration" did not hold. Releases v0.6.0-dev.1 … v0.6.2-dev.1 wrote `recommendation_run_log.phase = 'dedup'`, which `CallPhase` cannot hydrate. The ETA read would throw, so the status poll would 500 once a run's first batch started, and so would the debug log.
  - `Version20260925090000` deletes those rows. `down()` throws `IrreversibleMigration`, as the repository's other irreversible migration does.
  - A test drives the ETA read before and after the migration.
  - Git history shows `'dedup'` is the only retired status, phase or verdict value.
  - Lars approved the production delete (2026-09-28); it runs at the next deploy.
- **Reviews:**
  - The active-status set has one owner, `RunStatus::active()`, which the entity guards and the repository query use.
  - `settlement()` loses its leftover id parameter.
  - Stale "dedup" and `STATUS_PENDING` prose is gone.
  - The null-verdict test catches the nullsafe mutant through the warning it would raise.
- **Real run:** run 128 as user 2 completed 6/6 with 0 transport failures, every call usable on attempt 1, and a clean dev log. The debug-log, history and current endpoints returned 200 with unchanged field shapes.

---

## Reconcile changes (5229c947)

- Status: added "Reconciled against 5229c947 (2026-09-27)".
- Spec: #1182 and #1159 described as landed (merge SHAs), with the `DomainKnowsNoHttpRule`/`App\Dto` change; CLAUDE.md's shared-values bullet and `docs/architecture.md` §8 added to the references.
- Direction 5 audit: re-taken at `5229c947`; the two `toArray()` methods are recorded as gone (moved by #1182), the layering grep now covers `App\Dto`, HttpFoundation and `Service/Ai`.
- Wire changes: `RecommendationRunStatusJsonTest` (new pins from #1182) added to the run-report pins.
- "Depends on #1159 and #1182" rewritten as "Landed: #1159 and #1182 (checked at 5229c947)", fact by fact.
- Decisions: D-reconcile-1 (`TickDriver::retryPlan()` vs §8's open question) and D-reconcile-2 (the D16 follow-up also names `RecommendationSettingsValues`) added.
- Planner rulings: D18–D21 recorded as accepted (carry-forward).
- Global Constraints: §8 / `PersistenceKnowsNoServiceRule` / `NoToArrayInServicesRule` bullet added; `AiProviderSettingsFactory` added to the reuse list.
- A0 Step 4: notes that the 94 + 46 file lists held name for name at `5229c947`.
- A2 script: `trackedFiles()` skips `tests/PhpStan`, so no rule or fixture is ever rewritten.
- A2 Step 4: checks `tests/PhpStan` stays untouched.
- A3 Files: names the #1182-added or edited files the script rewrites; states the two #1182 moves are not in the map.
- A3 Step 3: adds a persistence-layer grep and the `tests/PhpStan` check.
- B0 Step 3: the `->distill(`/`->resolve(` grep is scoped to the recommendation and worker paths (the repo-wide grep matched ~60 unrelated `->resolve(` calls); expectations marked re-checked at `5229c947`.
- B1 `TickContextTest`: the connection comes from `AiProviderSettingsFactory::build()`; the `SealedSecret` import goes.
- B1: `RecommendationRunAdvancerTest` is 3168 lines (was 3169).
- C0 Step 2: expectations marked re-checked; `->finish(` now lists `RecordedCall`'s two calls to its own private `finish()`; the `->create(` note names the unrelated factory hits.
- C5 `ReasoningTest`: the connection comes from `AiProviderSettingsFactory::build()`; the `SealedSecret` import goes.
- E0 Step 2: expectations marked re-checked at `5229c947` (all counts unchanged).
- Goal: the module count is 94 classes at `5229c947` (was "96-file"); the decision range reads D1–D21 plus D-reconcile-1/-2.
- Finishing PR E: Step 5 added, handing the D16 follow-up (with D-reconcile-2), the #1171 comment sweep and the D-reconcile-1 note to the planner.
