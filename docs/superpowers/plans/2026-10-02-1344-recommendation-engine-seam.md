# Recommendation Engine Seam Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make `Service/Recommendation` engine-neutral and move every LLM-specific class into a new module `Service/Ai/Llm`, behind a `RecommendationEngineInterface` that one resolver picks, with the engine's capabilities reported to the client. No behaviour change.

**Architecture:** `Recommendation` owns the seam (`Engine/`: interface, kind, capabilities, resolver over a keyed locator) and the engine-neutral run lifecycle; `TickPhases` and `SnapshotPhase` reach the engine only through `RecommendationEngineResolver`. `Ai/Llm` (a module of its own by a scoped amendment to the #1161 module rule) holds the chat-completion transport, the prompts and parsers, the distill/batch/consolidate phases, the call recorder and `LlmRecommendationEngine`. Dependencies point `Ai\Llm → Recommendation → Ai`. The API reports `capabilities` (`reasons`, `tuningFields`) per configuration and for the active connection; the Angular settings render from them.

**Tech Stack:** PHP 8.4, Symfony 7.4 (DI attributes `AutoconfigureTag`, `AsTaggedItem`, `AutowireLocator`), PHPStan level max with the custom rules in `backend/tests/PhpStan/`, PHPUnit 12, Infection; Angular 20 (standalone, signals), Jest in the Docker `frontend` container.

**Spec:** GitHub issue #1344 (`gh issue view 1344`) plus the planner's settled design (below, "Settled design"). Follow-up #1345 (Jev) is context only and is not planned here.

## Status

| Task | Title | Status | Commit |
|---|---|---|---|
| 0 | Preflight: branch, stack current, baselines | ☐ | — |
| 1 | `Ai\Llm` is a module of its own (rule amendment) | ☐ | — |
| 2 | The engine seam inside `Recommendation` | ☐ | — |
| 3 | The scripted move into `Ai/Llm`, `Recommendation/Pool`, `ProviderCallHeartbeat` | ☐ | — |
| 4 | Capabilities in `/api/me` and `/api/me/ai` | ☐ | — |
| 5 | The frontend renders from capabilities | ☐ | — |
| 6 | Docs, full gates, a real run, the PR | ☐ | — |

## Scope

| Issue bullet | Where |
|---|---|
| `Service/Ai` keeps connections, `ModelCatalogInterface`, provider exceptions | Task 3 (nothing of these moves; `RetryPlanModel` joins `Ai/Model`) |
| `Service/Ai` gets "a composite catalog over a tagged iterator" | **Deferred to #1345** (deviation from the issue text, settled by the planner): one catalog exists today, so a composite over one member is ceremony; #1345 adds the second catalog and the composite together. `ModelCatalogInterface` stays bound to `OpenAiCompatibleCatalog`. |
| `Service/Ai/Llm` its own module: transport, OpenAI-shaped catalog, `Recommendation/Prompt/*`, the three phases, the call recorder, `LlmRecommendationEngine` | Task 2 (engine), Task 3 (move) |
| `Service/Recommendation` engine-neutral; owns `RecommendationEngineInterface`, the heartbeat interface, the resolver | Task 2 (interface, resolver), Task 3 (heartbeat, neutral loaders into `Pool/`) |
| Dependencies one way, keyed locator, `Recommendation` never names `Ai/Llm` | Task 2 (locator), Task 3 (cycle rule green on the real tree, plus a deliberate break) |
| Module rule amendment, one sentence in architecture §9 | Task 1 |
| §9 heartbeat example updated | Task 3 |
| Capabilities in the API (`reasons`, tuning fields), native-iOS friendly, no migration | Task 4 |
| Frontend renders settings from capabilities, never learns the engine name | Task 5 |
| Done when: `composer check`, `md`, `tramp`, both test legs, `infection:diff`, `npm run check` | Task 6 |
| Done when: cycle rule passes with `Ai/Llm`, a deliberate `Recommendation → Ai/Llm` fails it | Task 1 (fixtures), Task 3 Step 9 (real tree) |
| Done when: a real LLM run on the dev stack yields a list with reasons | Task 6 |
| Out of scope: Jev; moving LLM-only columns off `RecommendationRun`/`RecommendationSettings`; recording the engine on a run | not planned |

## Global Constraints

- **No behaviour change.** `RecommendationPipelineTest` stays byte-identical (it imports no moved class; verify with `git diff --stat origin/develop -- backend/tests/Service/Recommendation/Run/RecommendationPipelineTest.php` printing nothing at the end).
- Rule: every class is either model-specific or knows nothing about the model; **one** place (`RecommendationEngineResolver`) decides the engine. `App\Service\Recommendation` never names `App\Service\Ai\Llm` (comments are not names, but keep them clean too).
- Module rule: `App\Service\Ai\Llm` is module `Ai\Llm` through an explicit constant list (`['Ai\\Llm']`); everything else under `Ai` stays module `Ai`. No general nesting mechanism.
- CLAUDE.md house style: `final readonly class`, no abbreviations, no comment that restates code, comments one line where possible (three at most), guard clauses, interfaces in a folder named after them, `…Model` in `Model/`.
- Native-iOS rule (architecture §6): the new JSON is plain JSON, camelCase, no browser coupling, no new endpoint.
- Frontend: standalone components and signals, styles stay in sibling `.scss` (none needed here), Jest runs only inside the Docker `frontend` container, one Jest process at a time (OOM otherwise).
- Branch `refactor/1344-recommendation-engine-seam` off `develop`. Commits `type(#1344): lower-case summary`, no attribution lines. The PR body says `Closes #1344` (this PR is the whole issue).
- Work from `backend/` for backend commands, from the repository root for `docker compose` and `git -C ..`-free commands as written.
- **Deletion checks are binding** for every new pin in Tasks 1, 2, 4 and 5: break the covered code, run, quote the FAIL output verbatim in the task report, restore. Restore by copying the file aside first (`cp <file> "$TMPDIR/<name>.orig"` … `mv` it back), **never** `git checkout -- <file>`.
- An implementer reports to the planner, who amends this plan in-branch when the code says otherwise. Every **Assumption (verify):** below is cheap to check; check it, and report the outcome.

## Settled design (from the planner; not up for re-litigation)

1. Module rule amendment as above; the collector and every other place that derives a module agree.
2. Target layout: `Service/Ai` (shared: connections, catalog interface, provider exceptions and models, `RetryPlanModel`); `Service/Ai/Llm` (`Completion/`, `Prompt/`, `Run/`, the engine and the OpenAI catalog); `Service/Recommendation` (run lifecycle, `Pool/`, `Engine/`, `Feed`, `Settings`).
3. Seam in `Recommendation/Engine/`: `RecommendationEngine/RecommendationEngineInterface` (`packBatches`, `advance`, `capabilities`), `Model/RecommendationEngineKind`, `Model/RecommendationEngineCapabilitiesModel`, `Model/RecommendationTuningField`, `RecommendationEngineResolver` (`kindFor`, `engineFor`) over a keyed locator. `TickContext::reasoning()` goes.
4. Heartbeat moves to `Recommendation/Run/ProviderCallHeartbeat/`, renamed `ProviderCallHeartbeatInterface` / `CompositeProviderCallHeartbeat`.
5. Capabilities in `/api/me` (`ai` block) and per configuration in `/api/me/ai`.
6. Frontend renders from capabilities; nothing visible changes for the LLM.
7. Out of scope: Jev, the composite catalog, LLM columns off the entities, engine recording on the run.

## Decisions (judgement calls made while planning, with the reason)

- **D1 — One home for the sub-module list.** A new `tests/PhpStan/ServiceModules.php` holds `SUB_MODULES = ['Ai\\Llm']` and `of(string $className): string`. `ServiceModuleDependencyCollector::moduleOf()` and `ServiceRoleNames::moduleOf()` both call it. Why `ServiceRoleNames` too: `InterfacePlacement` asks "same module?" through it; left at "first segment", it would call `Ai\Llm\OpenAiCompatibleCatalog` a same-module implementation of `Ai\ModelCatalog\ModelCatalogInterface` and demand it sit in `Ai/ModelCatalog/`, and it would treat every `Ai\Llm` class implementing an `Ai` interface the same way. `ServiceModuleBoundaryRule` and `ServiceModuleGraph` derive no module (they take prefixes and the collector's names), so they need no change.
- **D2 — The sub-module match needs the separator** (`Ai\LlmTools\X` stays module `Ai`), and an exact `Ai\Llm` (a namespace alias import) is the sub-module. Each has a fixture.
- **D3 — `TickLockKeepalive` and `SweepStreamHeartbeat` move into `Recommendation/Run/ProviderCallHeartbeat/` with the interface.** *Contradicts the brief, which kept them in `Run/`:* once the interface is in the same module, `InterfacePlacement` requires same-module implementations to sit in the interface's folder (`ServiceRoleRule` would fail). Names unchanged.
- **D4 — Only `RetryPlanModel` leaves `Ai/Completion` for the `Ai` root (`Ai/Model/`).** It is the only `Ai/Completion` class the neutral side needs (`TickDriver::retryPlan()`, `TickContext::retryPlan()`). Every other `Ai/Completion` class encodes chat completions, streaming, reasoning or JSON schema and goes to `Ai/Llm/Completion/`. `CompletionUsageModel` goes too (its users are `RecordedCall`, the transport, and `RecommendationCallRepository`, which may name any `Model/`); whether Jev shares a usage model is #1345's call.
- **D5 — `OpenAiCompatibleCatalog` lands in the `Ai/Llm` root.** It implements another module's interface; §10 says such an implementation stays in its own module, and no `ModelCatalog/` interface folder exists in `Ai/Llm` to put it in.
- **D6 — `Recommendation/Pool/`** holds `RecommendationCandidateLoader`, `RecommendationHistoryLoader`, `ArticleLineModel` (was `PromptLineModel`), `CandidatePoolRequestModel`, `CandidatePoolSummaryModel`, `RecommendationHistoryModel`. `CandidatePoolSummaryModel` is neutral data (pool size and date span) that the neutral loader returns; only its docblock spoke of prompts. `RecommendationPickModel` goes to `Ai/Llm/Prompt/Model/`: only the parsers, the salvager, the batch wave and the consolidation resolver use it.
- **D7 — `RecommendationWinnerRanker` stays in `Recommendation` unchanged**, as the brief says. Flag: its `cutForConsolidation()` is named for, and used only by, the LLM consolidation resolver; `ranked()` is neutral. Not changed here (a no-behaviour-change PR); raise with the planner if a reviewer objects.
- **D8 — `RecommendationEtaEstimator` and `PhaseDurationsModel` stay in `Recommendation`.** Flag: they encode the distill/batch/consolidate shape (`TAIL_PHASE_COUNT = 2`, "runs that carry all three phases"), as `RecommendationRun`'s progress model does; the entity is shared persistence and out of scope, and the estimator only mirrors it. #1345's Jev runs (batch phase only) will get no ETA from `PhaseDurationsModel::fromCompletedRunSpans()` as written; noted for #1345.
- **D9 — Registration follows `FeedBodyParser`:** `#[AutoconfigureTag('app.recommendation_engine')]` on the interface, `#[AsTaggedItem(index: RecommendationEngineKind::Llm->value)]` on the engine, `#[AutowireLocator('app.recommendation_engine')]` on the resolver. The tag string appears twice, like `app.feed_body_parser`. **Assumption (verify):** `AsTaggedItem`'s index keys an autoconfigured tag's locator, and `RecommendationEngineKind::Llm->value` is a valid attribute argument (PHP ≥ 8.2 allows enum property fetch in constant expressions). `RecommendationEngineWiringTest` proves both.
- **D10 — Task 2 puts `LlmRecommendationEngine` in `Recommendation/Engine/RecommendationEngine/`** (the interface folder, as `InterfacePlacement` demands for a same-module implementation); Task 3 moves it to `Ai/Llm/`.
- **D11 — `TickPhases` keeps the provider-failure envelope** (429 → deferral, transport failure → strike): the exceptions live in `Ai/Exception` and mean the same for any engine that calls a provider. Only the phase choice moves into the engine.
- **D12 — Wire shape.** `capabilities: {"reasons": bool, "tuningFields": [string]}` on every configuration of `/api/me/ai` (and its write answers), and in `/api/me`'s `ai` block; `null` there when no connection is active. Field values are the JSON names the settings already use: `contextWindow`, `batchSize` (`RecommendationSettingsJson`), `suppressReasoning`, `slowModel`, `maxBatchSize`, `batchConcurrency` (`AiSettingsJson`).
- **D13 — Mappers.** The resolver is a service, the mappers are static. So: a new injected `Http/RecommendationCapabilitiesJson` (calls the resolver); `AiSettingsJson` becomes an injected `final readonly` service (instance methods, every one, so the controller has one calling style); `MeJson::profile()` keeps its three parameters and loses its `ai` block to a new injected `Http/ActiveAiJson`, which `MeProfileJson` appends. The `/api/me` wire shape is unchanged apart from the new key (`JwtAccessTest`'s key list stays green). *Deviation in letter from "in MeJson's `ai` block":* a fourth `MeJson::profile()` parameter would break the three-parameter rule.
- **D14 — Frontend source of capabilities.** `AiAvailabilityService` gains a `capabilities` signal fed by `/api/me` and by every AI-settings write (as `ready`/`model` are); the recommendation card reads it. Each connection row reads its own `config.capabilities`. Types are required, not optional; spec fixtures gain `capabilities: null`. The recommendation strip needs no change: it already renders score and reason under separate conditions.
- **D15 — `kindFor()` ignores its argument in this PR** (always `Llm`). phptramp will warn (3 hops: `RecommendationCapabilitiesJson::of → engineFor → kindFor` forwarding a connection none reads). 3 hops warn and do not fail the build; #1345 reads the model there. Do not "fix" the warning. **Assumption (verify):** PhpStorm's unused-parameter inspection on `kindFor()` is a weak warning; if `lint_files` reports it as WARNING, add `/** @noinspection PhpUnusedParameterInspection #1345 reads the model id */` above the method and say so in the report.
- **D16 — Docblocks of neutral classes that spoke of prompts are reworded in Task 3** (comment-only, so `compare-moves.php` still reads "0 differ"): `ArticleLineModel` (docblock dropped), `CandidatePoolSummaryModel`, `RecommendationHistoryModel`, both loaders, `ProviderCallHeartbeatInterface`, and the `services.yaml` heartbeat comment.
- **D17 — The move tooling is copied unchanged** from `docs/superpowers/plans/2026-09-28-1202-scripts/` (`class-names.php`, `move-classes.php`, `compare-moves.php`, `stale-names.php`) into `docs/superpowers/plans/2026-10-02-1344-scripts/`, with this plan's `moves.php` map and a new `psr4-namespaces.php` sweep (the #1202 hazard: a namespace corruption the comparison script once skipped). `compare-moves.php` already checks every moved file's namespace; the sweep covers every file in `src` and `tests`.
- **D18 — CLAUDE.md's module sentence is updated** in Task 1 ("A module is the first directory under `src/Service`" is no longer the whole truth). Lars may veto in review.
- **D19 — `RecommendationSettingsJson` keeps exposing the LLM's fixed prompt** (`RecommendationPromptText`), and the card keeps showing it and the distilled profile. `Http` may name `Ai\Llm`; whether a non-LLM engine hides them is #1345's decision.
- **D20 — Test double `tests/Support/ScriptedRecommendationEngine`** stands in for "an engine that is not the LLM" in `TickPhasesTest`, `SnapshotPhaseTest` and the Http tests. It registers under `llm` because `kindFor()` maps every connection there today.
- **D21 — New unit tests for `TickPhases` and `SnapshotPhase`.** *The brief said "updated"; none exist today* (both are covered only through `RecommendationRunAdvancerTest`). They are new, DB-backed, and drive the seam with the scripted engine.

## File map

Created (src): `Service/Recommendation/Engine/RecommendationEngine/RecommendationEngineInterface.php`, `Service/Recommendation/Engine/RecommendationEngineResolver.php`, `Service/Recommendation/Engine/Model/{RecommendationEngineKind,RecommendationEngineCapabilitiesModel,RecommendationTuningField}.php`, `Service/Recommendation/Engine/RecommendationEngine/LlmRecommendationEngine.php` (moves to `Service/Ai/Llm/` in Task 3), `Http/RecommendationCapabilitiesJson.php`, `Http/ActiveAiJson.php`.

Created (tests): `tests/PhpStan/ServiceModules.php`, four cycle fixtures and one role fixture under `tests/PhpStan/data/`, `tests/Support/ScriptedRecommendationEngine.php`, `tests/Service/Recommendation/Engine/{RecommendationEngineResolverTest,RecommendationEngineWiringTest}.php`, `tests/Service/Recommendation/Engine/RecommendationEngine/LlmRecommendationEngineTest.php` (moves), `tests/Service/Recommendation/Run/{TickPhasesTest,SnapshotPhaseTest}.php`, `tests/Http/{RecommendationCapabilitiesJsonTest,ActiveAiJsonTest}.php`.

Moved: 66 src classes and 37 test classes (Task 3 map).

Modified: `TickPhases`, `SnapshotPhase`, `TickContext`, `RecommendationConsolidationResolver`, `AiSettingsJson`, `MeJson`, `MeProfileJson`, `AiSettingsController`, `config/services.yaml` (comment), the two `ServiceModule*`/`ServiceRole*` helpers, `docs/architecture.md` §9, `docs/recommendations-runs.md`, `CLAUDE.md`; frontend: `core/ai-availability.service.ts`, `core/auth/auth.service.ts`, `settings/ai/ai-settings.service.ts`, `settings/ai/ai-section.component.{ts,html}`, `settings/recommendations/recommendation-settings-card.component.{ts,html}`, their specs, ten spec fixtures, `e2e/ai-config-rejected.spec.ts`, new `src/testing/recommendation-capabilities.ts`.

---

### Task 0: Preflight

**Files:** none changed except the plan and its scripts directory (committed).

**Interfaces:** none.

- [ ] **Step 1: Check the checkout** (concurrent sessions share it)

Run: `git -C .. status --short && git -C .. branch --show-current`
Expected: clean, on `develop`. If dirty or on another session's branch: stop and ask Lars.

- [ ] **Step 2: Branch off the latest develop**

```bash
git fetch origin develop
git switch -c refactor/1344-recommendation-engine-seam origin/develop
```

- [ ] **Step 3: Copy the move tooling and write the map**

```bash
mkdir -p ../docs/superpowers/plans/2026-10-02-1344-scripts
cp ../docs/superpowers/plans/2026-09-28-1202-scripts/{class-names,move-classes,compare-moves,stale-names}.php \
   ../docs/superpowers/plans/2026-10-02-1344-scripts/
```

Write `docs/superpowers/plans/2026-10-02-1344-scripts/moves.php` with the full map from Task 3 Step 1, and `docs/superpowers/plans/2026-10-02-1344-scripts/psr4-namespaces.php`:

```php
<?php

declare(strict_types=1);

// php ../docs/superpowers/plans/2026-10-02-1344-scripts/psr4-namespaces.php   (from backend/)
// Every PHP file in src and tests declares the namespace its path implies (PSR-4: App\ => src/, App\Tests\ => tests/).
// Prints each file that does not, then the count. PHPStan rule fixtures declare made-up namespaces on purpose.

$offPath = 0;
foreach (['src' => 'App', 'tests' => 'App\\Tests'] as $root => $prefix) {
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
    foreach ($files as $file) {
        $path = $file->getPathname();
        if (!str_ends_with($path, '.php') || str_starts_with($path, 'tests/PhpStan/data/')) {
            continue;
        }
        if (1 !== preg_match('/^namespace ([^;{]+);$/m', (string) file_get_contents($path), $match)) {
            continue;
        }
        $relativeDirectory = substr(dirname($path), strlen($root) + 1);
        $expected = '' === $relativeDirectory ? $prefix : $prefix . '\\' . str_replace('/', '\\', $relativeDirectory);
        if ($match[1] !== $expected) {
            printf("%s declares %s, expected %s\n", $path, $match[1], $expected);
            ++$offPath;
        }
    }
}
printf("%d files off their PSR-4 namespace.\n", $offPath);
```

- [ ] **Step 4: Commit the plan and the tooling**

```bash
git add ../docs/superpowers/plans/2026-10-02-1344-recommendation-engine-seam.md ../docs/superpowers/plans/2026-10-02-1344-scripts
git commit -m "docs(#1344): plan the recommendation engine seam"
```

- [ ] **Step 5: Make sure the Docker stack serves the current tree** (standing rule)

```bash
docker compose ps
docker compose exec php printenv APP_CACHE_DIR      # expect /app/var/cache-docker
docker compose exec php bin/console cache:clear
docker compose restart worker
docker compose up -d frontend
```
Expected: `php`, `worker`, `nginx`, `frontend`, `mysql` up (worker healthy). If `APP_CACHE_DIR` is empty: `docker compose up -d php worker && docker compose restart nginx`.

- [ ] **Step 6: Baselines** (record each number in the report; later tasks compare against them)

```bash
bin/console cache:warmup
composer check                 # expect green; note phptramp's warning count
composer md                    # expect no output
php ../docs/superpowers/plans/2026-10-02-1344-scripts/psr4-namespaces.php   # expect "0 files off …"; record N if not 0
composer test:parallel         # expect green; record the test count
docker compose exec -T frontend npm run check   # alone, no other Jest process; capture EXIT=$? and the "Test Suites:" line
```
If any baseline is red, stop and report: a red baseline must be proven pre-existing on `origin/develop` before anything builds on it.

---

### Task 1: `Ai\Llm` is a module of its own

The rule changes before anything moves into the sub-module; with no `Ai\Llm` class in the tree yet, `composer stan` is unaffected.

**Files:**
- Create: `backend/tests/PhpStan/ServiceModules.php`
- Modify: `backend/tests/PhpStan/ServiceModuleDependencyCollector.php` (constant and `moduleOf()`)
- Modify: `backend/tests/PhpStan/ServiceRoleNames.php` (`moduleOf()`)
- Create: `backend/tests/PhpStan/data/service-module-cycle/{ai-sub-module-acyclic,ai-sub-module-cycle,ai-sibling-of-sub-module,ai-sub-module-alias}.php`
- Create: `backend/tests/PhpStan/data/service-role-sub-module-fixture.php`
- Modify: `backend/tests/PhpStan/ServiceModuleCycleRuleTest.php`, `backend/tests/PhpStan/ServiceRoleRuleTest.php`
- Modify: `docs/architecture.md` §9, `CLAUDE.md` (D18)

**Interfaces:**
- Produces: `App\Tests\PhpStan\ServiceModules::SERVICE_NAMESPACE = 'App\\Service\\'`; `ServiceModules::of(string $className): string` (`$className` starts with `App\Service\`; returns `Ai\Llm` for that sub-module, else the first segment below `App\Service`). `ServiceRoleNames::moduleOf()` returns `App\Service\Ai\Llm` for any name in the sub-module.

- [ ] **Step 1: Write the cycle fixtures**

`backend/tests/PhpStan/data/service-module-cycle/ai-sub-module-acyclic.php` (line numbers matter for the deletion check; keep the layout exactly):

```php
<?php

declare(strict_types=1);

// Fixtures for ServiceModuleCycleRuleTest, excluded from `composer stan` like everything in this directory.
/** @noinspection PhpIllegalPsrClassPathInspection */

namespace App\Service\Ai {
    final class FixtureConnection
    {
    }
}

namespace App\Service\Recommendation {
    use App\Service\Ai\FixtureConnection;

    interface FixtureEngineInterface
    {
        public function advance(FixtureConnection $connection): void;
    }
}

namespace App\Service\Ai\Llm {
    use App\Service\Ai\FixtureConnection;
    use App\Service\Recommendation\FixtureEngineInterface;

    final class FixtureLlmEngine implements FixtureEngineInterface
    {
        public function advance(FixtureConnection $connection): void
        {
        }
    }
}
```

`backend/tests/PhpStan/data/service-module-cycle/ai-sub-module-cycle.php` (the reference that closes the cycle is on line 25):

```php
<?php

declare(strict_types=1);

// Fixtures for ServiceModuleCycleRuleTest, excluded from `composer stan` like everything in this directory.
/** @noinspection PhpIllegalPsrClassPathInspection */

namespace App\Service\Ai {
    final class FixtureConnection
    {
    }
}

namespace App\Service\Recommendation {
    use App\Service\Ai\FixtureConnection;

    final class FixtureTickPhases
    {
        public function __construct(public FixtureConnection $connection)
        {
        }

        public function engine(): string
        {
            return \App\Service\Ai\Llm\FixtureLlmEngine::class;
        }
    }
}

namespace App\Service\Ai\Llm {
    use App\Service\Recommendation\FixtureTickPhases;

    final class FixtureLlmEngine
    {
        public function __construct(public FixtureTickPhases $phases)
        {
        }
    }
}
```

`backend/tests/PhpStan/data/service-module-cycle/ai-sibling-of-sub-module.php` (`Ai\LlmTools` is module `Ai`, not `Ai\Llm`):

```php
<?php

declare(strict_types=1);

// Fixtures for ServiceModuleCycleRuleTest, excluded from `composer stan` like everything in this directory.
/** @noinspection PhpIllegalPsrClassPathInspection */

namespace App\Service\Ai\LlmTools {
    final class FixtureTokenCounter
    {
    }
}

namespace App\Service\Recommendation {
    use App\Service\Ai\LlmTools\FixtureTokenCounter;

    final class FixtureBudget
    {
        public function __construct(public FixtureTokenCounter $counter)
        {
        }
    }
}

namespace App\Service\Ai\Llm {
    use App\Service\Recommendation\FixtureBudget;

    final class FixtureLlmEngine
    {
        public function __construct(public FixtureBudget $budget)
        {
        }
    }
}
```

`backend/tests/PhpStan/data/service-module-cycle/ai-sub-module-alias.php` (an alias import of the sub-module's namespace, line 9, is the sub-module):

```php
<?php

declare(strict_types=1);

// Fixtures for ServiceModuleCycleRuleTest, excluded from `composer stan` like everything in this directory.
/** @noinspection PhpIllegalPsrClassPathInspection */

namespace App\Service\Recommendation {
    use App\Service\Ai\Llm as LlmModule;

    final class FixtureTickPhases
    {
        public function engine(): string
        {
            return LlmModule\FixtureLlmEngine::class;
        }
    }
}

namespace App\Service\Ai\Llm {
    use App\Service\Recommendation\FixtureTickPhases;

    final class FixtureLlmEngine
    {
        public function __construct(public FixtureTickPhases $phases)
        {
        }
    }
}
```

- [ ] **Step 2: Add the cycle tests**

In `backend/tests/PhpStan/ServiceModuleCycleRuleTest.php`, after `testAnEdgeSeenInTwoFilesReportsTheSiteInTheFileFirstByName()`:

```php
    public function testAnLlmEngineMayDependOnRecommendationWhichDependsOnTheRestOfAi(): void
    {
        $this->analyse([self::fixture('ai-sub-module-acyclic')], []);
    }

    public function testRecommendationNamingTheLlmSubModuleClosesACycle(): void
    {
        $this->analyse(
            [self::fixture('ai-sub-module-cycle')],
            [[self::message('Ai\Llm -> Recommendation -> Ai\Llm'), 25]],
        );
    }

    public function testASiblingWhoseNameStartsLikeTheSubModuleStaysInAi(): void
    {
        $this->analyse([self::fixture('ai-sibling-of-sub-module')], []);
    }

    public function testAnAliasImportOfTheSubModulesNamespaceNamesTheSubModule(): void
    {
        $this->analyse(
            [self::fixture('ai-sub-module-alias')],
            [[self::message('Ai\Llm -> Recommendation -> Ai\Llm'), 9]],
        );
    }
```

- [ ] **Step 3: Run them to see them fail**

Run: `php bin/phpunit tests/PhpStan/ServiceModuleCycleRuleTest.php`
Expected: the four new tests FAIL (acyclic and sibling report `Ai -> Recommendation -> Ai`; cycle and alias report it instead of `Ai\Llm -> …`). The eight existing tests pass.

- [ ] **Step 4: Write the role fixture and test**

`backend/tests/PhpStan/data/service-role-sub-module-fixture.php`:

```php
<?php

declare(strict_types=1);

// A fixture for ServiceRoleRuleTest, excluded from `composer stan` like everything in this directory.
/** @noinspection PhpIllegalPsrClassPathInspection */

namespace App\Service\Ai\Probe\ProbeCatalog {
    /** @noinspection AutoloadingIssuesInspection */
    interface ProbeCatalogInterface
    {
        public function models(): string;
    }
}

namespace App\Service\Ai\Llm {
    use App\Service\Ai\Probe\ProbeCatalog\ProbeCatalogInterface;

    /** @noinspection AutoloadingIssuesInspection */
    final readonly class LlmProbeCatalog implements ProbeCatalogInterface
    {
        public function models(): string
        {
            return 'llm';
        }
    }
}
```

In `backend/tests/PhpStan/ServiceRoleRuleTest.php` add the constant next to `INSTANCE_PROPERTY_FIXTURE`:

```php
    private const string SUB_MODULE_FIXTURE = __DIR__ . '/data/service-role-sub-module-fixture.php';
```

and, after the test that analyses `INSTANCE_PROPERTY_FIXTURE`:

```php
    public function testAnAiLlmClassImplementingAnAiInterfaceIsAnotherModulesImplementationAndStaysPut(): void
    {
        require_once self::SUB_MODULE_FIXTURE;

        $this->analyse([self::SUB_MODULE_FIXTURE], []);
    }
```

Run: `php bin/phpunit tests/PhpStan/ServiceRoleRuleTest.php --filter AnAiLlmClass`
Expected: FAIL with one `Service role "interfaceFolder": App\Service\Ai\Llm\LlmProbeCatalog implements App\Service\Ai\Probe\ProbeCatalog\ProbeCatalogInterface, so it sits in that interface's folder. Its home is …` error. **Assumption (verify):** no other check reports on the fixture; if one does (a shape finding unrelated to modules), fix the fixture's shape, not the expectation.

- [ ] **Step 5: Write `ServiceModules`**

`backend/tests/PhpStan/ServiceModules.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\PhpStan;

/** Which App\Service module a class belongs to (docs/architecture.md §9). */
final class ServiceModules
{
    public const string SERVICE_NAMESPACE = 'App\\Service\\';

    /** Directories below Service/ that are modules of their own; the rest of their parent stays the parent's (#1344). */
    private const array SUB_MODULES = ['Ai\\Llm'];

    private function __construct()
    {
    }

    /** The sub-module the class sits in, else its first segment below App\Service (a loose class's own name). */
    public static function of(string $className): string
    {
        $relativeName = substr($className, \strlen(self::SERVICE_NAMESPACE));

        return array_find(
            self::SUB_MODULES,
            static fn (string $subModule): bool => $relativeName === $subModule
                || str_starts_with($relativeName, $subModule . '\\'),
        ) ?? explode('\\', $relativeName)[0];
    }
}
```

- [ ] **Step 6: Use it in the collector**

In `backend/tests/PhpStan/ServiceModuleDependencyCollector.php`:
- delete `private const string SERVICE_NAMESPACE = 'App\\Service\\';`
- replace both uses of `self::SERVICE_NAMESPACE` with `ServiceModules::SERVICE_NAMESPACE`
- replace the class docblock's second sentence `A module is the first segment under App\Service; a class loose in the root is a module of its own, named by its file (PSR-4).` with `ServiceModules names the module.`
- replace `moduleOf()` with:

```php
    private static function moduleOf(string $className): string
    {
        return ServiceModules::of($className);
    }
```

- [ ] **Step 7: Use it in `ServiceRoleNames`**

In `backend/tests/PhpStan/ServiceRoleNames.php` replace `moduleOf()` and its docblock with:

```php
    /** `App\Service\<Module>` for a service, a sub-module included (ServiceModules), `App\<Layer>` for anything else. */
    public static function moduleOf(string $name): string
    {
        if (self::isService($name)) {
            return ServiceModules::SERVICE_NAMESPACE . ServiceModules::of($name);
        }

        return implode('\\', \array_slice(explode('\\', $name), 0, 2));
    }
```

- [ ] **Step 8: Run the rule tests**

Run: `php bin/phpunit tests/PhpStan`
Expected: all PASS.

- [ ] **Step 9: Deletion checks** (quote each FAIL verbatim in the report)

Copy `tests/PhpStan/ServiceModules.php` and `tests/PhpStan/ServiceRoleNames.php` to `$TMPDIR` first.
1. In `ServiceModules::of()`, return `explode('\\', $relativeName)[0];` only → `php bin/phpunit tests/PhpStan/ServiceModuleCycleRuleTest.php` → acyclic, cycle, alias FAIL; restore.
2. Replace `$subModule . '\\'` with `$subModule` → `testASiblingWhoseNameStartsLikeTheSubModuleStaysInAi` FAILS with `Ai\Llm -> Recommendation -> Ai\Llm` at line 15; restore.
3. Delete `$relativeName === $subModule ||` → `testAnAliasImportOfTheSubModulesNamespaceNamesTheSubModule` FAILS (the cycle is reported on line 15, or not at all); restore.
4. In `ServiceRoleNames::moduleOf()`, restore the old body (`implode('\\', \array_slice(explode('\\', $name), 0, self::isService($name) ? 3 : 2))`) → `testAnAiLlmClassImplementing…` FAILS with the `interfaceFolder` error; restore.

- [ ] **Step 10: Document the sub-module**

In `docs/architecture.md` §9, after the paragraph's first sentence (`A \`Service/*\` module is the first directory under \`backend/src/Service\`: \`Recommendation\` with all its subdirectories is one module.`) insert:

```markdown
The one exception is a directory listed in `ServiceModules::SUB_MODULES` (`backend/tests/PhpStan/`): `Service/Ai/Llm`
is a module of its own, so the LLM engine there may depend on `Recommendation` while `Recommendation` depends on the
rest of `Ai` (#1344).
```

In `CLAUDE.md`, in the bullet **Service modules form no cycle.**, replace `A module is the first directory under \`src/Service\`, and every service belongs to one.` with `A module is the first directory under \`src/Service\` (\`Service/Ai/Llm\` is a module of its own, #1344), and every service belongs to one.`

- [ ] **Step 11: Gates and commit**

```bash
composer check
php bin/phpunit tests/PhpStan
git add tests/PhpStan ../docs/architecture.md ../CLAUDE.md
git commit -m "refactor(#1344): service/ai/llm is a module of its own"
```
Expected: `composer check` green (no `Ai\Llm` class exists yet, so the real tree is unchanged).

Reviewer: yes (new logic and tests). The reviewer re-runs deletion check 2.

---

### Task 2: The engine seam inside `Recommendation`

Nothing moves yet. The seam goes in, `TickPhases` and `SnapshotPhase` call through it, and `LlmRecommendationEngine` holds the LLM phase choice and the LLM packing.

**Files:**
- Create: `src/Service/Recommendation/Engine/Model/RecommendationEngineKind.php`, `RecommendationTuningField.php`, `RecommendationEngineCapabilitiesModel.php`
- Create: `src/Service/Recommendation/Engine/RecommendationEngine/RecommendationEngineInterface.php`, `LlmRecommendationEngine.php`
- Create: `src/Service/Recommendation/Engine/RecommendationEngineResolver.php`
- Modify: `src/Service/Recommendation/Run/TickPhases.php`, `SnapshotPhase.php`, `Pass/TickContext.php`, `RecommendationConsolidationResolver.php:51`
- Create: `tests/Support/ScriptedRecommendationEngine.php`
- Create: `tests/Service/Recommendation/Engine/RecommendationEngineResolverTest.php`, `RecommendationEngineWiringTest.php`, `tests/Service/Recommendation/Engine/RecommendationEngine/LlmRecommendationEngineTest.php`, `tests/Service/Recommendation/Run/TickPhasesTest.php`, `SnapshotPhaseTest.php`
- Modify: `tests/Service/Recommendation/Run/Pass/TickContextTest.php`

**Interfaces:**
- Produces (namespace `App\Service\Recommendation\Engine`):
  - `Model\RecommendationEngineKind: string { case Llm = 'llm'; }`
  - `Model\RecommendationTuningField: string` with cases `ContextWindow='contextWindow'`, `BatchSize='batchSize'`, `SuppressReasoning='suppressReasoning'`, `SlowModel='slowModel'`, `MaxBatchSize='maxBatchSize'`, `BatchConcurrency='batchConcurrency'`
  - `Model\RecommendationEngineCapabilitiesModel(bool $writesReasons, list<RecommendationTuningField> $tuningFields)`, public readonly properties of those names
  - `RecommendationEngine\RecommendationEngineInterface`: `packBatches(list<PromptLineModel> $candidates, TickContext $tick): list<list<int>>`, `advance(TickContext $tick): RecommendationRunReportModel`, `capabilities(): RecommendationEngineCapabilitiesModel` (`PromptLineModel` becomes `ArticleLineModel` in Task 3)
  - `RecommendationEngineResolver`: `kindFor(AiProviderSettings $connection): RecommendationEngineKind`, `engineFor(AiProviderSettings $connection): RecommendationEngineInterface`; locator tag `app.recommendation_engine`
- Produces (tests): `App\Tests\Support\ScriptedRecommendationEngine::packing(list<list<int>>)`, `::failingWith(\Throwable)`, `::reporting(RecommendationEngineCapabilitiesModel)`, `->resolver(): RecommendationEngineResolver`, public `$packedCandidates`, `$advancedTicks`
- `TickContext::reasoning()` is deleted; callers use `Reasoning::preferredBy($tick->connection)`.

- [ ] **Step 1: The models**

`src/Service/Recommendation/Engine/Model/RecommendationEngineKind.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Engine\Model;

/** Which engine turns a connection's runs into a list; the value keys the engine in the resolver's locator. */
enum RecommendationEngineKind: string
{
    case Llm = 'llm';
}
```

`src/Service/Recommendation/Engine/Model/RecommendationTuningField.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Engine\Model;

/** A setting only some engines read; the value is that setting's field name in the settings responses. */
enum RecommendationTuningField: string
{
    case ContextWindow = 'contextWindow';
    case BatchSize = 'batchSize';
    case SuppressReasoning = 'suppressReasoning';
    case SlowModel = 'slowModel';
    case MaxBatchSize = 'maxBatchSize';
    case BatchConcurrency = 'batchConcurrency';
}
```

`src/Service/Recommendation/Engine/Model/RecommendationEngineCapabilitiesModel.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Engine\Model;

/** What an engine can do, so a client offers only the settings that apply to it. */
final readonly class RecommendationEngineCapabilitiesModel
{
    /** @param list<RecommendationTuningField> $tuningFields */
    public function __construct(
        public bool $writesReasons,
        public array $tuningFields,
    ) {
    }
}
```

- [ ] **Step 2: The interface**

`src/Service/Recommendation/Engine/RecommendationEngine/RecommendationEngineInterface.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Engine\RecommendationEngine;

use App\Service\Recommendation\Engine\Model\RecommendationEngineCapabilitiesModel;
use App\Service\Recommendation\Prompt\Model\PromptLineModel;
use App\Service\Recommendation\Run\Model\RecommendationRunReportModel;
use App\Service\Recommendation\Run\Pass\TickContext;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * One way to turn a run's candidates into a ranked list, keyed in RecommendationEngineResolver's locator by its
 * RecommendationEngineKind value (#[AsTaggedItem]).
 */
#[AutoconfigureTag('app.recommendation_engine')]
interface RecommendationEngineInterface
{
    /**
     * The frozen plan the run advances through, batch by batch.
     *
     * @param list<PromptLineModel> $candidates
     *
     * @return list<list<int>>
     */
    public function packBatches(array $candidates, TickContext $tick): array;

    /** One tick of a running run that is not waiting out a rate limit. */
    public function advance(TickContext $tick): RecommendationRunReportModel;

    public function capabilities(): RecommendationEngineCapabilitiesModel;
}
```

- [ ] **Step 3: The test double**

`tests/Support/ScriptedRecommendationEngine.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Service\Recommendation\Engine\Model\RecommendationEngineCapabilitiesModel;
use App\Service\Recommendation\Engine\Model\RecommendationEngineKind;
use App\Service\Recommendation\Engine\RecommendationEngine\RecommendationEngineInterface;
use App\Service\Recommendation\Engine\RecommendationEngineResolver;
use App\Service\Recommendation\Prompt\Model\PromptLineModel;
use App\Service\Recommendation\Run\Model\RecommendationRunReportModel;
use App\Service\Recommendation\Run\Pass\TickContext;
use Symfony\Component\DependencyInjection\ServiceLocator;

/** An engine that is not the LLM: it packs and advances as scripted and records what it was asked. */
final class ScriptedRecommendationEngine implements RecommendationEngineInterface
{
    /** @var list<list<PromptLineModel>> */
    public array $packedCandidates = [];

    /** @var list<TickContext> */
    public array $advancedTicks = [];

    /** @param list<list<int>> $batches */
    private function __construct(
        private readonly array $batches,
        private readonly ?\Throwable $advanceFailure,
        private readonly RecommendationEngineCapabilitiesModel $capabilities,
    ) {
    }

    /** @param list<list<int>> $batches */
    public static function packing(array $batches): self
    {
        return new self($batches, null, new RecommendationEngineCapabilitiesModel(false, []));
    }

    public static function failingWith(\Throwable $advanceFailure): self
    {
        return new self([], $advanceFailure, new RecommendationEngineCapabilitiesModel(false, []));
    }

    public static function reporting(RecommendationEngineCapabilitiesModel $capabilities): self
    {
        return new self([], null, $capabilities);
    }

    /** Registered under the only kind there is, which kindFor() gives every connection today. */
    public function resolver(): RecommendationEngineResolver
    {
        return new RecommendationEngineResolver(new ServiceLocator([
            RecommendationEngineKind::Llm->value => fn (): self => $this,
        ]));
    }

    public function packBatches(array $candidates, TickContext $tick): array
    {
        $this->packedCandidates[] = $candidates;

        return $this->batches;
    }

    public function advance(TickContext $tick): RecommendationRunReportModel
    {
        $this->advancedTicks[] = $tick;
        if (null !== $this->advanceFailure) {
            throw $this->advanceFailure;
        }

        return RecommendationRunReportModel::fromRun($tick->run);
    }

    public function capabilities(): RecommendationEngineCapabilitiesModel
    {
        return $this->capabilities;
    }
}
```

- [ ] **Step 4: The resolver test (fails: no class)**

`tests/Service/Recommendation/Engine/RecommendationEngineResolverTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Recommendation\Engine;

use App\Entity\AiProviderSettings;
use App\Entity\User;
use App\Service\Recommendation\Engine\Model\RecommendationEngineKind;
use App\Service\Recommendation\Engine\RecommendationEngineResolver;
use App\Tests\Support\AiProviderSettingsFactory;
use App\Tests\Support\ScriptedRecommendationEngine;
use PHPUnit\Framework\TestCase;
use Psr\Container\NotFoundExceptionInterface;
use Symfony\Component\DependencyInjection\ServiceLocator;

final class RecommendationEngineResolverTest extends TestCase
{
    /** `typesafe/jev-router` is a chat-completions router, so it stays an LLM connection after #1345 too. */
    public function testEveryConnectionIsAnLlmConnection(): void
    {
        $resolver = new RecommendationEngineResolver(new ServiceLocator([]));

        self::assertSame(RecommendationEngineKind::Llm, $resolver->kindFor($this->connection('gpt-4o-mini')));
        self::assertSame(RecommendationEngineKind::Llm, $resolver->kindFor($this->connection('typesafe/jev-router')));
    }

    public function testTheEngineComesFromTheLocatorUnderItsKindsValue(): void
    {
        $engine = ScriptedRecommendationEngine::packing([]);
        $resolver = new RecommendationEngineResolver(new ServiceLocator(['llm' => static fn () => $engine]));

        self::assertSame($engine, $resolver->engineFor($this->connection('gpt-4o-mini')));
    }

    public function testAKindWithoutAnEngineIsAWiringError(): void
    {
        $resolver = new RecommendationEngineResolver(new ServiceLocator([]));

        try {
            $resolver->engineFor($this->connection('gpt-4o-mini'));
            self::fail('A missing engine must not resolve.');
        } catch (\LogicException $exception) {
            self::assertSame('No recommendation engine is wired for "llm".', $exception->getMessage());
            self::assertInstanceOf(NotFoundExceptionInterface::class, $exception->getPrevious());
        }
    }

    private function connection(string $model): AiProviderSettings
    {
        $connection = AiProviderSettingsFactory::build(
            new User('engine-resolver@example.test', new \DateTimeImmutable('2026-10-02 09:00:00')),
        );
        $connection->chooseModel($model, new \DateTimeImmutable('2026-10-02 09:05:00'), null);

        return $connection;
    }
}
```

Run: `php bin/phpunit tests/Service/Recommendation/Engine/RecommendationEngineResolverTest.php` → Expected: error, class `RecommendationEngineResolver` not found.

- [ ] **Step 5: The resolver**

`src/Service/Recommendation/Engine/RecommendationEngineResolver.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Engine;

use App\Entity\AiProviderSettings;
use App\Service\Recommendation\Engine\Model\RecommendationEngineKind;
use App\Service\Recommendation\Engine\RecommendationEngine\RecommendationEngineInterface;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireLocator;

/** The one place that decides which engine runs a connection's recommendations. */
final readonly class RecommendationEngineResolver
{
    public function __construct(
        #[AutowireLocator('app.recommendation_engine')]
        private ContainerInterface $engines,
    ) {
    }

    public function kindFor(AiProviderSettings $connection): RecommendationEngineKind
    {
        return RecommendationEngineKind::Llm;
    }

    public function engineFor(AiProviderSettings $connection): RecommendationEngineInterface
    {
        $kind = $this->kindFor($connection);
        try {
            $engine = $this->engines->get($kind->value);
        } catch (ContainerExceptionInterface $exception) {
            throw new \LogicException(
                sprintf('No recommendation engine is wired for "%s".', $kind->value),
                previous: $exception,
            );
        }
        \assert($engine instanceof RecommendationEngineInterface);

        return $engine;
    }
}
```

Run the resolver test → Expected: PASS (3 tests).

- [ ] **Step 6: The LLM engine test (fails: no class)**

`tests/Service/Recommendation/Engine/RecommendationEngine/LlmRecommendationEngineTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Recommendation\Engine\RecommendationEngine;

use App\Entity\User;
use App\Service\Ai\Crypto\ApiKeyCipher;
use App\Service\Recommendation\Engine\Model\RecommendationTuningField;
use App\Service\Recommendation\Engine\RecommendationEngine\LlmRecommendationEngine;
use App\Service\Recommendation\Prompt\Model\PromptLineModel;
use App\Tests\DbTestCase;
use App\Tests\Support\BuildsTickContexts;
use App\Tests\Support\RecommendationRunFixtures;
use App\Tests\Support\SeedsUsers;

final class LlmRecommendationEngineTest extends DbTestCase
{
    use BuildsTickContexts;
    use SeedsUsers;

    private User $owner;
    private RecommendationRunFixtures $fixtures;

    protected function setUp(): void
    {
        parent::setUp();

        /** @var ApiKeyCipher $cipher */
        $cipher = self::getContainer()->get(ApiKeyCipher::class);
        $this->fixtures = new RecommendationRunFixtures($this->entityManager, $cipher);
        $this->owner = $this->user('llm-engine@example.test');
        $this->fixtures->seedReadyAiSettings($this->owner);
    }

    public function testTheLlmWritesReasonsAndReadsEveryTuningField(): void
    {
        $capabilities = $this->engine()->capabilities();

        self::assertTrue($capabilities->writesReasons);
        self::assertSame(
            [
                RecommendationTuningField::ContextWindow,
                RecommendationTuningField::BatchSize,
                RecommendationTuningField::SuppressReasoning,
                RecommendationTuningField::SlowModel,
                RecommendationTuningField::MaxBatchSize,
                RecommendationTuningField::BatchConcurrency,
            ],
            $capabilities->tuningFields,
        );
    }

    /** A ceiling of 7 splits 17 candidates 7/7/3; the 32k window is far from the budget limit. */
    public function testThePoolIsPackedUpToTheConnectionsBatchCeilingInPoolOrder(): void
    {
        $this->fixtures->capBatchesAt($this->owner, 7);
        $run = $this->fixtures->createRun($this->owner);
        $this->entityManager->flush();
        $candidates = array_map(
            static fn (int $entryId): PromptLineModel
                => new PromptLineModel($entryId, 'Article ' . $entryId, 'Example', '2026-10-01', null),
            range(101, 117),
        );

        $batches = $this->engine()->packBatches($candidates, $this->tick($run));

        self::assertSame([range(101, 107), range(108, 114), range(115, 117)], $batches);
    }

    private function engine(): LlmRecommendationEngine
    {
        $engine = self::getContainer()->get(LlmRecommendationEngine::class);
        self::assertInstanceOf(LlmRecommendationEngine::class, $engine);

        return $engine;
    }
}
```

**Assumption (verify):** `LlmRecommendationEngine` is reachable from the test container (it is a private service referenced by the locator, so not inlined away). If `get()` reports it removed, build it by hand from the container's `RecommendationHistoryLoader`, `RecommendationPromptBuilder` and the three phases, or add a `public: true` alias in `services_test.yaml` with a comment saying why.

Run → Expected: error, class `LlmRecommendationEngine` not found.

- [ ] **Step 7: The LLM engine**

`src/Service/Recommendation/Engine/RecommendationEngine/LlmRecommendationEngine.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Engine\RecommendationEngine;

use App\Entity\RecommendationRun;
use App\Service\Recommendation\Engine\Model\RecommendationEngineCapabilitiesModel;
use App\Service\Recommendation\Engine\Model\RecommendationEngineKind;
use App\Service\Recommendation\Engine\Model\RecommendationTuningField;
use App\Service\Recommendation\Prompt\RecommendationHistoryLoader;
use App\Service\Recommendation\Prompt\RecommendationPromptBuilder;
use App\Service\Recommendation\Run\Model\RecommendationRunReportModel;
use App\Service\Recommendation\Run\Pass\TickContext;
use App\Service\Recommendation\Run\ProviderPhase\BatchPhase;
use App\Service\Recommendation\Run\ProviderPhase\ConsolidationPhase;
use App\Service\Recommendation\Run\ProviderPhase\DistillationPhase;
use App\Service\Recommendation\Run\ProviderPhase\ProviderPhaseInterface;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

/** The chat-completion engine: packs by the context window, then distills, scores in batches and consolidates. */
#[AsTaggedItem(index: RecommendationEngineKind::Llm->value)]
final readonly class LlmRecommendationEngine implements RecommendationEngineInterface
{
    public function __construct(
        private RecommendationHistoryLoader $historyLoader,
        private RecommendationPromptBuilder $promptBuilder,
        private DistillationPhase $distillation,
        private BatchPhase $batch,
        private ConsolidationPhase $consolidation,
    ) {
    }

    public function packBatches(array $candidates, TickContext $tick): array
    {
        return $this->promptBuilder->packBatches(
            $candidates,
            $this->historyLoader->load($tick->userId(), $tick->settings),
            $tick->settings,
        );
    }

    public function advance(TickContext $tick): RecommendationRunReportModel
    {
        return $this->providerPhaseFor($tick->run)->advance($tick);
    }

    public function capabilities(): RecommendationEngineCapabilitiesModel
    {
        return new RecommendationEngineCapabilitiesModel(true, RecommendationTuningField::cases());
    }

    private function providerPhaseFor(RecommendationRun $run): ProviderPhaseInterface
    {
        $progress = $run->getProgress();

        return match (true) {
            $progress->distillPending => $this->distillation,
            $progress->isConsolidationPhase => $this->consolidation,
            default => $this->batch,
        };
    }
}
```

Run the engine test → Expected: PASS (2 tests).

- [ ] **Step 8: The wiring test**

`tests/Service/Recommendation/Engine/RecommendationEngineWiringTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Recommendation\Engine;

use App\Entity\User;
use App\Service\Recommendation\Engine\RecommendationEngine\LlmRecommendationEngine;
use App\Service\Recommendation\Engine\RecommendationEngineResolver;
use App\Tests\Support\AiProviderSettingsFactory;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/** The resolver's unit tests hand-build the locator; only the compiled container proves the tag and the index. */
final class RecommendationEngineWiringTest extends KernelTestCase
{
    public function testTheContainerResolvesAConnectionToTheLlmEngine(): void
    {
        self::bootKernel();
        $resolver = self::getContainer()->get(RecommendationEngineResolver::class);
        self::assertInstanceOf(RecommendationEngineResolver::class, $resolver);
        $connection = AiProviderSettingsFactory::build(
            new User('engine-wiring@example.test', new \DateTimeImmutable('2026-10-02 09:00:00')),
        );
        $connection->chooseModel('gpt-4o-mini', new \DateTimeImmutable('2026-10-02 09:05:00'), null);

        self::assertInstanceOf(LlmRecommendationEngine::class, $resolver->engineFor($connection));
    }
}
```

Run → Expected: PASS once Step 9 lands (the resolver has no consumer yet, so the test container may report it removed; if this run errors with "removed or inlined", continue: Step 9 gives it two consumers).

- [ ] **Step 9: `TickPhases` and `SnapshotPhase` call through the resolver**

Replace `src/Service/Recommendation/Run/TickPhases.php` with:

```php
<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Run;

use App\Enum\RunStatus;
use App\Service\Ai\Exception\CredentialsRejectedException;
use App\Service\Ai\Exception\ProviderRateLimitedException;
use App\Service\Ai\Exception\ProviderUnreachableException;
use App\Service\Ai\Exception\RetryableProviderException;
use App\Service\Recommendation\Engine\RecommendationEngine\RecommendationEngineInterface;
use App\Service\Recommendation\Engine\RecommendationEngineResolver;
use App\Service\Recommendation\Run\Model\RecommendationRunReportModel;
use App\Service\Recommendation\Run\Pass\TickContext;
use Symfony\Component\Clock\ClockInterface;

final readonly class TickPhases
{
    public function __construct(
        private SnapshotPhase $snapshot,
        private RecommendationEngineResolver $engines,
        private RecommendationRunDeferral $deferral,
        private RecommendationTransportFailureRecorder $transportFailures,
        private ClockInterface $clock,
    ) {
    }

    public function advance(TickContext $tick): RecommendationRunReportModel
    {
        $run = $tick->run;
        if (RunStatus::Pending === $run->getStatus()) {
            return $this->snapshot->advance($tick);
        }

        if ($run->isRetryDeferredAt($this->clock->now())) {
            return RecommendationRunReportModel::fromRun($run);
        }

        return $this->advanceWithinTheEnvelope($this->engines->engineFor($tick->connection), $tick);
    }

    private function advanceWithinTheEnvelope(
        RecommendationEngineInterface $engine,
        TickContext $tick,
    ): RecommendationRunReportModel {
        try {
            return $engine->advance($tick);
        } catch (ProviderRateLimitedException $exception) {
            return $this->deferral->defer($tick->run, $exception);
        } catch (ProviderUnreachableException | CredentialsRejectedException | RetryableProviderException $exception) {
            $this->transportFailures->record($tick->run, $tick->connection, $exception->getMessage());

            throw $exception;
        }
    }
}
```

In `src/Service/Recommendation/Run/SnapshotPhase.php`:
- imports: delete `use App\Service\Recommendation\Prompt\RecommendationHistoryLoader;` and `use App\Service\Recommendation\Prompt\RecommendationPromptBuilder;`; add `use App\Service\Recommendation\Engine\RecommendationEngineResolver;` (sorted, before `App\Service\Recommendation\Prompt\…`)
- constructor becomes:

```php
    public function __construct(
        private RecommendationCandidateLoader $candidateLoader,
        private RecommendationEngineResolver $engines,
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
    ) {
    }
```

- in `advance()`, replace

```php
        $history = $this->historyLoader->load($tick->userId(), $tick->settings);
        $run->snapshot($this->promptBuilder->packBatches($candidates, $history, $tick->settings));
```

with

```php
        $run->snapshot($this->engines->engineFor($tick->connection)->packBatches($candidates, $tick));
```

- class docblock: `/** Freezes a pending run's candidate pool into its engine's batches without a provider call; an empty pool completes at once. */` — keep it ≤ 120 columns; split onto two docblock lines if needed.

- [ ] **Step 10: `TickContext::reasoning()` goes**

In `src/Service/Recommendation/Run/Pass/TickContext.php` delete `use App\Service\Ai\Completion\Model\Reasoning;` and the whole `reasoning()` method.

In `src/Service/Recommendation/Run/RecommendationConsolidationResolver.php` add `use App\Service\Ai\Completion\Model\Reasoning;` (sorted, first `App\Service\…` import) and replace

```php
        $inputSize = $this->promptBuilder->consolidationInputSize($prompt, $tick->reasoning());
```

with

```php
        $inputSize = $this->promptBuilder->consolidationInputSize($prompt, Reasoning::preferredBy($tick->connection));
```

In `tests/Service/Recommendation/Run/Pass/TickContextTest.php` delete `use App\Service\Ai\Completion\Model\Reasoning;` and the method `testTheConnectionDecidesWhetherTheCallMayReason()` (`ReasoningTest` already pins `Reasoning::preferredBy()`).

- [ ] **Step 11: `TickPhasesTest`**

`tests/Service/Recommendation/Run/TickPhasesTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Recommendation\Run;

use App\Entity\RecommendationRun;
use App\Entity\User;
use App\Service\Ai\Crypto\ApiKeyCipher;
use App\Service\Ai\Exception\ProviderRateLimitedException;
use App\Service\Ai\Exception\ProviderUnreachableException;
use App\Service\Recommendation\Prompt\RecommendationCandidateLoader;
use App\Service\Recommendation\Run\RecommendationRunDeferral;
use App\Service\Recommendation\Run\RecommendationTickCheckpoint;
use App\Service\Recommendation\Run\RecommendationTransportFailureRecorder;
use App\Service\Recommendation\Run\SnapshotPhase;
use App\Service\Recommendation\Run\TickPhases;
use App\Tests\DbTestCase;
use App\Tests\Support\BuildsTickContexts;
use App\Tests\Support\RecommendationRunFixtures;
use App\Tests\Support\ScriptedRecommendationEngine;
use App\Tests\Support\SeedsUsers;
use Symfony\Component\Clock\MockClock;

/** TickPhases knows no engine: whatever the resolver hands it advances a running run, inside the provider envelope. */
final class TickPhasesTest extends DbTestCase
{
    use BuildsTickContexts;
    use SeedsUsers;

    private User $owner;
    private RecommendationRunFixtures $fixtures;
    private MockClock $clock;

    protected function setUp(): void
    {
        parent::setUp();

        /** @var ApiKeyCipher $cipher */
        $cipher = self::getContainer()->get(ApiKeyCipher::class);
        $this->fixtures = new RecommendationRunFixtures($this->entityManager, $cipher);
        $this->owner = $this->user('tick-phases@example.test');
        $this->fixtures->seedReadyAiSettings($this->owner);
        $this->clock = new MockClock('2026-08-08 10:05:00');
    }

    public function testARunningRunIsAdvancedByTheResolvedEngine(): void
    {
        $engine = ScriptedRecommendationEngine::packing([]);
        $tick = $this->tick($this->runningRun());

        $report = $this->phases($engine)->advance($tick);

        self::assertSame([$tick], $engine->advancedTicks);
        self::assertSame('running', $report->status);
    }

    public function testAPendingRunIsSnapshottedNotAdvanced(): void
    {
        $this->fixtures->seedFeedWithEntries($this->owner, 3);
        $engine = ScriptedRecommendationEngine::packing([[1]]);
        $run = $this->fixtures->createRun($this->owner);
        $this->entityManager->flush();

        $this->phases($engine)->advance($this->tick($run));

        self::assertCount(1, $engine->packedCandidates);
        self::assertSame([], $engine->advancedTicks);
    }

    public function testARunWaitingOutARateLimitIsLeftAloneUntilTheWaitIsOver(): void
    {
        $engine = ScriptedRecommendationEngine::packing([]);
        $run = $this->runningRun();
        $run->getRunningThrottle()->deferUntil(new \DateTimeImmutable('2026-08-08 10:10:00'));
        $this->entityManager->flush();
        $phases = $this->phases($engine);

        $phases->advance($this->tick($run));
        self::assertSame([], $engine->advancedTicks);

        $this->clock->modify('2026-08-08 10:11:00');
        $phases->advance($this->tick($run));
        self::assertCount(1, $engine->advancedTicks);
    }

    public function testARateLimitedEngineDefersTheRunInsteadOfFailingIt(): void
    {
        $engine = ScriptedRecommendationEngine::failingWith(new ProviderRateLimitedException(90.0));
        $run = $this->runningRun();

        $report = $this->phases($engine)->advance($this->tick($run));

        self::assertEquals(new \DateTimeImmutable('2026-08-08 10:06:30'), $run->getRetryNotBefore());
        self::assertSame('running', $report->status);
        self::assertSame(0, $run->getTransportFailures());
    }

    public function testAnUnreachableProviderStrikesTheRunAndPropagates(): void
    {
        $engine = ScriptedRecommendationEngine::failingWith(
            new ProviderUnreachableException('The provider at api.example.test answered 502.'),
        );
        $run = $this->runningRun();

        try {
            $this->phases($engine)->advance($this->tick($run));
            self::fail('A transport failure must propagate.');
        } catch (ProviderUnreachableException $exception) {
            self::assertSame('The provider at api.example.test answered 502.', $exception->getMessage());
        }
        self::assertSame(1, $run->getTransportFailures());
    }

    private function runningRun(): RecommendationRun
    {
        $run = $this->fixtures->createRun($this->owner);
        $run->snapshot([[101, 102]]);
        $this->entityManager->flush();

        return $run;
    }

    private function phases(ScriptedRecommendationEngine $engine): TickPhases
    {
        /** @var RecommendationCandidateLoader $candidates */
        $candidates = self::getContainer()->get(RecommendationCandidateLoader::class);
        /** @var RecommendationTickCheckpoint $checkpoint */
        $checkpoint = self::getContainer()->get(RecommendationTickCheckpoint::class);
        $resolver = $engine->resolver();

        return new TickPhases(
            new SnapshotPhase($candidates, $resolver, $this->entityManager, $this->clock),
            $resolver,
            new RecommendationRunDeferral($checkpoint, $this->entityManager, $this->clock),
            new RecommendationTransportFailureRecorder($checkpoint, $this->entityManager, $this->clock),
            $this->clock,
        );
    }
}
```

**Assumption (verify):** `RecommendationRunReportModel` exposes a public `status` string (`RecommendationRunStatusJson` reads `$report->status`). The pending test's entries are dated against the real clock and the snapshot's look-back against the mock clock (August 2026); entries in its future still match `since`. If the loader also caps at "now", seed the entries with the mock date or move the mock clock to the real date and say so.

- [ ] **Step 12: `SnapshotPhaseTest`**

`tests/Service/Recommendation/Run/SnapshotPhaseTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Recommendation\Run;

use App\Entity\Entry;
use App\Entity\RecommendationRun;
use App\Entity\User;
use App\Enum\RunStatus;
use App\Service\Ai\Crypto\ApiKeyCipher;
use App\Service\Recommendation\Prompt\Model\PromptLineModel;
use App\Service\Recommendation\Prompt\RecommendationCandidateLoader;
use App\Service\Recommendation\Run\SnapshotPhase;
use App\Tests\DbTestCase;
use App\Tests\Support\BuildsTickContexts;
use App\Tests\Support\RecommendationRunFixtures;
use App\Tests\Support\ScriptedRecommendationEngine;
use App\Tests\Support\SeedsUsers;
use Symfony\Component\Clock\ClockInterface;

final class SnapshotPhaseTest extends DbTestCase
{
    use BuildsTickContexts;
    use SeedsUsers;

    private User $owner;
    private RecommendationRunFixtures $fixtures;

    protected function setUp(): void
    {
        parent::setUp();

        /** @var ApiKeyCipher $cipher */
        $cipher = self::getContainer()->get(ApiKeyCipher::class);
        $this->fixtures = new RecommendationRunFixtures($this->entityManager, $cipher);
        $this->owner = $this->user('snapshot-phase@example.test');
        $this->fixtures->seedReadyAiSettings($this->owner);
    }

    /** One entry alone, then the other two: a plan the LLM packer, which fills batches in pool order, never makes. */
    public function testTheResolvedEnginesBatchesBecomeTheRunsPlan(): void
    {
        $ids = array_map(
            static fn (Entry $entry): int => $entry->requireId(),
            $this->fixtures->seedFeedWithEntries($this->owner, 3),
        );
        $plan = [[$ids[2]], [$ids[0], $ids[1]]];
        $engine = ScriptedRecommendationEngine::packing($plan);
        $run = $this->pendingRun();

        $this->snapshot($engine)->advance($this->tick($run));
        $this->entityManager->refresh($run);

        self::assertSame($plan, $run->getCandidateBatches());
        self::assertSame(RunStatus::Running, $run->getStatus());
        self::assertCount(1, $engine->packedCandidates);
        self::assertEqualsCanonicalizing(
            $ids,
            array_map(static fn (PromptLineModel $line): int => $line->entryId, $engine->packedCandidates[0]),
        );
    }

    public function testAnEmptyPoolCompletesWithoutAskingTheEngine(): void
    {
        $engine = ScriptedRecommendationEngine::packing([[999]]);
        $run = $this->pendingRun();

        $this->snapshot($engine)->advance($this->tick($run));
        $this->entityManager->refresh($run);

        self::assertSame(RunStatus::Completed, $run->getStatus());
        self::assertSame([], $run->getCandidateBatches());
        self::assertSame([], $engine->packedCandidates);
    }

    private function pendingRun(): RecommendationRun
    {
        $run = $this->fixtures->createRun($this->owner);
        $this->entityManager->flush();

        return $run;
    }

    private function snapshot(ScriptedRecommendationEngine $engine): SnapshotPhase
    {
        /** @var RecommendationCandidateLoader $candidates */
        $candidates = self::getContainer()->get(RecommendationCandidateLoader::class);
        /** @var ClockInterface $clock */
        $clock = self::getContainer()->get(ClockInterface::class);

        return new SnapshotPhase($candidates, $engine->resolver(), $this->entityManager, $clock);
    }
}
```

- [ ] **Step 13: Run the new and the covering tests**

```bash
bin/console cache:warmup
php bin/phpunit tests/Service/Recommendation/Engine tests/Service/Recommendation/Run/TickPhasesTest.php \
  tests/Service/Recommendation/Run/SnapshotPhaseTest.php tests/Service/Recommendation/Run/Pass/TickContextTest.php
php bin/phpunit tests/Service/Recommendation tests/Service/Worker tests/DependencyInjection
```
Expected: all PASS; `RecommendationPipelineTest` and `RecommendationRunAdvancerTest` unchanged and green.

- [ ] **Step 14: Deletion checks** (quote each FAIL)

Copy each file to `$TMPDIR` before breaking it.
1. Resolver: `get($kind->value)` → `get($kind->name)` → `testTheEngineComesFromTheLocatorUnderItsKindsValue` FAILS (`No recommendation engine is wired for "llm".`).
2. Resolver: drop `previous: $exception,` → `testAKindWithoutAnEngineIsAWiringError` FAILS.
3. Engine: remove `#[AsTaggedItem(…)]` → `RecommendationEngineWiringTest` FAILS with `No recommendation engine is wired for "llm".`
4. Engine: `capabilities()` returns `new RecommendationEngineCapabilitiesModel(false, …)` → the capabilities test FAILS; then `cases()` → `[]` → FAILS.
5. Engine: `packBatches()` returns `[array_map(static fn ($line) => $line->entryId, $candidates)]` → the packing test FAILS.
6. Engine: swap the `distillPending` and `isConsolidationPhase` arms → `php bin/phpunit tests/Service/Recommendation/Run/RecommendationRunAdvancerTest.php` FAILS (the existing suite pins the phase order).
7. `TickPhases`: delete the `isRetryDeferredAt` guard → `testARunWaitingOutARateLimit…` FAILS.
8. `TickPhases`: delete the `ProviderRateLimitedException` catch → `testARateLimitedEngineDefers…` errors with the exception.
9. `TickPhases`: delete the `$this->transportFailures->record(…)` line → `testAnUnreachableProviderStrikes…` FAILS (`0` vs `1`).
10. `SnapshotPhase`: replace the engine call with `$run->snapshot([array_map(static fn ($line) => $line->entryId, $candidates)]);` → `testTheResolvedEnginesBatchesBecomeTheRunsPlan` FAILS.
11. `SnapshotPhase`: delete the empty-pool early return → `testAnEmptyPoolCompletesWithoutAskingTheEngine` FAILS.

- [ ] **Step 15: Gates and commit**

```bash
composer check      # expect green; phptramp may add the D15 warning only after Task 4
composer md
git add src tests
git commit -m "refactor(#1344): recommendations reach their engine through a resolver"
```

Reviewer: yes. The reviewer re-runs checks 3 and 10 and hunts for default-valued expectations.

---

### Task 3: The scripted move

Pure move plus the comment rewording of D16. Lean testing (survey count, namespace checks, `composer check`, the suite), but the cycle rule must end green here and the deliberate break must fail it.

**Files:** every class in the map below (src and tests), `config/services.yaml` (comment), `docs/architecture.md` §9 example. The script rewrites `config/services.yaml` and `config/services_test.yaml` FQCNs itself.

**Interfaces:**
- Produces: the new FQCNs in the map. Notably `App\Service\Ai\Llm\LlmRecommendationEngine`, `App\Service\Ai\Llm\Completion\…`, `App\Service\Ai\Llm\Prompt\…`, `App\Service\Ai\Llm\Run\…`, `App\Service\Ai\Llm\OpenAiCompatibleCatalog`, `App\Service\Ai\Model\RetryPlanModel`, `App\Service\Recommendation\Pool\{RecommendationCandidateLoader,RecommendationHistoryLoader}`, `App\Service\Recommendation\Pool\Model\{ArticleLineModel,CandidatePoolRequestModel,CandidatePoolSummaryModel,RecommendationHistoryModel}`, `App\Service\Recommendation\Run\ProviderCallHeartbeat\{ProviderCallHeartbeatInterface,CompositeProviderCallHeartbeat,TickLockKeepalive,SweepStreamHeartbeat}`, `App\Tests\Support\{CountingProviderCallHeartbeat,NullProviderCallHeartbeat}`.
- `RecommendationEngineInterface::packBatches()`'s `@param` reads `list<ArticleLineModel>`.

- [ ] **Step 1: The map** (`docs/superpowers/plans/2026-10-02-1344-scripts/moves.php`, written in Task 0; verify it reads exactly this)

```php
<?php

declare(strict_types=1);

// #1344 Task 3: old FQCN => new FQCN, for move-classes.php, compare-moves.php and stale-names.php (run from backend/).

return [
    // The chat-completion transport: module Ai\Llm.
    'App\Service\Ai\Completion\ChatCompletionClient\ChatCompletionClientInterface'
        => 'App\Service\Ai\Llm\Completion\ChatCompletionClient\ChatCompletionClientInterface',
    'App\Service\Ai\Completion\ChatCompletionClient\OpenAiCompatibleChatClient'
        => 'App\Service\Ai\Llm\Completion\ChatCompletionClient\OpenAiCompatibleChatClient',
    'App\Service\Ai\Completion\CompletionBodyDecoder' => 'App\Service\Ai\Llm\Completion\CompletionBodyDecoder',
    'App\Service\Ai\Completion\CompletionStreamObserver\CompletionStreamObserverInterface'
        => 'App\Service\Ai\Llm\Completion\CompletionStreamObserver\CompletionStreamObserverInterface',
    'App\Service\Ai\Completion\CompletionStreamObserver\NullCompletionStreamObserver'
        => 'App\Service\Ai\Llm\Completion\CompletionStreamObserver\NullCompletionStreamObserver',
    'App\Service\Ai\Completion\Model\CompletionOutcomeModel' => 'App\Service\Ai\Llm\Completion\Model\CompletionOutcomeModel',
    'App\Service\Ai\Completion\Model\CompletionRequestModel' => 'App\Service\Ai\Llm\Completion\Model\CompletionRequestModel',
    'App\Service\Ai\Completion\Model\CompletionStreamProgressModel'
        => 'App\Service\Ai\Llm\Completion\Model\CompletionStreamProgressModel',
    'App\Service\Ai\Completion\Model\CompletionUsageModel' => 'App\Service\Ai\Llm\Completion\Model\CompletionUsageModel',
    'App\Service\Ai\Completion\Model\JsonSchemaModel' => 'App\Service\Ai\Llm\Completion\Model\JsonSchemaModel',
    'App\Service\Ai\Completion\Model\RateLimitedResultModel' => 'App\Service\Ai\Llm\Completion\Model\RateLimitedResultModel',
    'App\Service\Ai\Completion\Model\Reasoning' => 'App\Service\Ai\Llm\Completion\Model\Reasoning',
    'App\Service\Ai\Completion\Pass\CompletionCallSlot' => 'App\Service\Ai\Llm\Completion\Pass\CompletionCallSlot',
    'App\Service\Ai\Completion\Pass\CompletionStreamReader' => 'App\Service\Ai\Llm\Completion\Pass\CompletionStreamReader',
    'App\Service\Ai\Completion\Pass\ConcurrentCompletion' => 'App\Service\Ai\Llm\Completion\Pass\ConcurrentCompletion',
    'App\Service\Ai\Completion\RateLimitedCompletion' => 'App\Service\Ai\Llm\Completion\RateLimitedCompletion',
    'App\Service\Ai\Completion\Support\CompletionFinishReason' => 'App\Service\Ai\Llm\Completion\Support\CompletionFinishReason',
    'App\Service\Ai\ModelCatalog\OpenAiCompatibleCatalog' => 'App\Service\Ai\Llm\OpenAiCompatibleCatalog',

    // Engine-neutral: the retry plan joins the Ai models; the heartbeat becomes Recommendation's.
    'App\Service\Ai\Completion\Model\RetryPlanModel' => 'App\Service\Ai\Model\RetryPlanModel',
    'App\Service\Ai\Completion\CompletionStreamHeartbeat\CompletionStreamHeartbeatInterface'
        => 'App\Service\Recommendation\Run\ProviderCallHeartbeat\ProviderCallHeartbeatInterface',
    'App\Service\Ai\Completion\CompletionStreamHeartbeat\CompositeCompletionStreamHeartbeat'
        => 'App\Service\Recommendation\Run\ProviderCallHeartbeat\CompositeProviderCallHeartbeat',
    'App\Service\Recommendation\Run\TickLockKeepalive'
        => 'App\Service\Recommendation\Run\ProviderCallHeartbeat\TickLockKeepalive',
    'App\Service\Recommendation\Run\SweepStreamHeartbeat'
        => 'App\Service\Recommendation\Run\ProviderCallHeartbeat\SweepStreamHeartbeat',

    // The candidate pool and the reading history: engine-neutral, Recommendation\Pool.
    'App\Service\Recommendation\Prompt\RecommendationCandidateLoader'
        => 'App\Service\Recommendation\Pool\RecommendationCandidateLoader',
    'App\Service\Recommendation\Prompt\RecommendationHistoryLoader'
        => 'App\Service\Recommendation\Pool\RecommendationHistoryLoader',
    'App\Service\Recommendation\Prompt\Model\PromptLineModel' => 'App\Service\Recommendation\Pool\Model\ArticleLineModel',
    'App\Service\Recommendation\Prompt\Model\CandidatePoolRequestModel'
        => 'App\Service\Recommendation\Pool\Model\CandidatePoolRequestModel',
    'App\Service\Recommendation\Prompt\Model\CandidatePoolSummaryModel'
        => 'App\Service\Recommendation\Pool\Model\CandidatePoolSummaryModel',
    'App\Service\Recommendation\Prompt\Model\RecommendationHistoryModel'
        => 'App\Service\Recommendation\Pool\Model\RecommendationHistoryModel',

    // Prompts, budgets and reply parsing: Ai\Llm\Prompt.
    'App\Service\Recommendation\Prompt\Factory\RecommendationCompletionRequestFactory'
        => 'App\Service\Ai\Llm\Prompt\Factory\RecommendationCompletionRequestFactory',
    'App\Service\Recommendation\Prompt\Model\CallPromptModel' => 'App\Service\Ai\Llm\Prompt\Model\CallPromptModel',
    'App\Service\Recommendation\Prompt\Model\ConsolidationParseResultModel'
        => 'App\Service\Ai\Llm\Prompt\Model\ConsolidationParseResultModel',
    'App\Service\Recommendation\Prompt\Model\PickParseResultModel' => 'App\Service\Ai\Llm\Prompt\Model\PickParseResultModel',
    'App\Service\Recommendation\Prompt\Model\ProfileParseResultModel'
        => 'App\Service\Ai\Llm\Prompt\Model\ProfileParseResultModel',
    'App\Service\Recommendation\Prompt\Model\RecommendationPickModel'
        => 'App\Service\Ai\Llm\Prompt\Model\RecommendationPickModel',
    'App\Service\Recommendation\Prompt\Model\RecommendationResponseSchema'
        => 'App\Service\Ai\Llm\Prompt\Model\RecommendationResponseSchema',
    'App\Service\Recommendation\Prompt\ModelReplyJsonDecoder' => 'App\Service\Ai\Llm\Prompt\ModelReplyJsonDecoder',
    'App\Service\Recommendation\Prompt\Pass\PromptContext' => 'App\Service\Ai\Llm\Prompt\Pass\PromptContext',
    'App\Service\Recommendation\Prompt\PlausibleDuplicateShare' => 'App\Service\Ai\Llm\Prompt\PlausibleDuplicateShare',
    'App\Service\Recommendation\Prompt\RecommendationAnswerBudget' => 'App\Service\Ai\Llm\Prompt\RecommendationAnswerBudget',
    'App\Service\Recommendation\Prompt\RecommendationConsolidationParser'
        => 'App\Service\Ai\Llm\Prompt\RecommendationConsolidationParser',
    'App\Service\Recommendation\Prompt\RecommendationPickParser' => 'App\Service\Ai\Llm\Prompt\RecommendationPickParser',
    'App\Service\Recommendation\Prompt\RecommendationPickSalvager' => 'App\Service\Ai\Llm\Prompt\RecommendationPickSalvager',
    'App\Service\Recommendation\Prompt\RecommendationProfileParser'
        => 'App\Service\Ai\Llm\Prompt\RecommendationProfileParser',
    'App\Service\Recommendation\Prompt\RecommendationPromptBuilder'
        => 'App\Service\Ai\Llm\Prompt\RecommendationPromptBuilder',
    'App\Service\Recommendation\Prompt\Support\RecommendationPromptText'
        => 'App\Service\Ai\Llm\Prompt\Support\RecommendationPromptText',

    // The distill / batch / consolidate run: Ai\Llm\Run.
    'App\Service\Recommendation\Run\ProviderPhase\BatchPhase' => 'App\Service\Ai\Llm\Run\ProviderPhase\BatchPhase',
    'App\Service\Recommendation\Run\ProviderPhase\ConsolidationPhase'
        => 'App\Service\Ai\Llm\Run\ProviderPhase\ConsolidationPhase',
    'App\Service\Recommendation\Run\ProviderPhase\DistillationPhase'
        => 'App\Service\Ai\Llm\Run\ProviderPhase\DistillationPhase',
    'App\Service\Recommendation\Run\ProviderPhase\ProviderPhaseInterface'
        => 'App\Service\Ai\Llm\Run\ProviderPhase\ProviderPhaseInterface',
    'App\Service\Recommendation\Run\RecommendationBatchWave' => 'App\Service\Ai\Llm\Run\RecommendationBatchWave',
    'App\Service\Recommendation\Run\RecommendationCallRecorder' => 'App\Service\Ai\Llm\Run\RecommendationCallRecorder',
    'App\Service\Recommendation\Run\RecommendationConsolidationResolver'
        => 'App\Service\Ai\Llm\Run\RecommendationConsolidationResolver',
    'App\Service\Recommendation\Run\RecommendationProfileDistiller'
        => 'App\Service\Ai\Llm\Run\RecommendationProfileDistiller',
    'App\Service\Recommendation\Run\RecommendationProviderCall' => 'App\Service\Ai\Llm\Run\RecommendationProviderCall',
    'App\Service\Recommendation\Run\InvalidReplyRetry' => 'App\Service\Ai\Llm\Run\InvalidReplyRetry',
    'App\Service\Recommendation\Run\WaveContextLoader' => 'App\Service\Ai\Llm\Run\WaveContextLoader',
    'App\Service\Recommendation\Run\Pass\RecordedCall' => 'App\Service\Ai\Llm\Run\Pass\RecordedCall',
    'App\Service\Recommendation\Run\Pass\WaveContext' => 'App\Service\Ai\Llm\Run\Pass\WaveContext',
    'App\Service\Recommendation\Run\Model\BatchWaveResultModel' => 'App\Service\Ai\Llm\Run\Model\BatchWaveResultModel',
    'App\Service\Recommendation\Run\Model\CallSlotModel' => 'App\Service\Ai\Llm\Run\Model\CallSlotModel',
    'App\Service\Recommendation\Run\Model\ConsolidationOutcomeModel'
        => 'App\Service\Ai\Llm\Run\Model\ConsolidationOutcomeModel',
    'App\Service\Recommendation\Run\Model\ProfileDistillationOutcomeModel'
        => 'App\Service\Ai\Llm\Run\Model\ProfileDistillationOutcomeModel',
    'App\Service\Recommendation\Run\Model\WaveBatchModel' => 'App\Service\Ai\Llm\Run\Model\WaveBatchModel',
    'App\Service\Recommendation\Run\Factory\RecommendationRunLogFactory'
        => 'App\Service\Ai\Llm\Run\Factory\RecommendationRunLogFactory',

    // The engine itself.
    'App\Service\Recommendation\Engine\RecommendationEngine\LlmRecommendationEngine'
        => 'App\Service\Ai\Llm\LlmRecommendationEngine',

    // Tests follow their classes.
    'App\Tests\Service\Ai\Completion\ChatCompletionClient\OpenAiCompatibleChatClientTest'
        => 'App\Tests\Service\Ai\Llm\Completion\ChatCompletionClient\OpenAiCompatibleChatClientTest',
    'App\Tests\Service\Ai\Completion\CompletionBodyDecoderTest'
        => 'App\Tests\Service\Ai\Llm\Completion\CompletionBodyDecoderTest',
    'App\Tests\Service\Ai\Completion\CompletionStreamHeartbeat\CompletionStreamHeartbeatWiringTest'
        => 'App\Tests\Service\Recommendation\Run\ProviderCallHeartbeat\ProviderCallHeartbeatWiringTest',
    'App\Tests\Service\Ai\Completion\CompletionStreamHeartbeat\CompositeCompletionStreamHeartbeatTest'
        => 'App\Tests\Service\Recommendation\Run\ProviderCallHeartbeat\CompositeProviderCallHeartbeatTest',
    'App\Tests\Service\Ai\Completion\Model\CompletionOutcomeModelTest'
        => 'App\Tests\Service\Ai\Llm\Completion\Model\CompletionOutcomeModelTest',
    'App\Tests\Service\Ai\Completion\Model\CompletionUsageModelTest'
        => 'App\Tests\Service\Ai\Llm\Completion\Model\CompletionUsageModelTest',
    'App\Tests\Service\Ai\Completion\Model\ReasoningTest' => 'App\Tests\Service\Ai\Llm\Completion\Model\ReasoningTest',
    'App\Tests\Service\Ai\Completion\Model\RetryPlanModelTest' => 'App\Tests\Service\Ai\Model\RetryPlanModelTest',
    'App\Tests\Service\Ai\Completion\Pass\CompletionStreamReaderTest'
        => 'App\Tests\Service\Ai\Llm\Completion\Pass\CompletionStreamReaderTest',
    'App\Tests\Service\Ai\Completion\RateLimitedCompletionTest'
        => 'App\Tests\Service\Ai\Llm\Completion\RateLimitedCompletionTest',
    'App\Tests\Service\Ai\Completion\Support\CompletionFinishReasonTest'
        => 'App\Tests\Service\Ai\Llm\Completion\Support\CompletionFinishReasonTest',
    'App\Tests\Service\Ai\ModelCatalog\OpenAiCompatibleCatalogTest'
        => 'App\Tests\Service\Ai\Llm\OpenAiCompatibleCatalogTest',
    'App\Tests\Service\Recommendation\Prompt\Factory\RecommendationCompletionRequestFactoryTest'
        => 'App\Tests\Service\Ai\Llm\Prompt\Factory\RecommendationCompletionRequestFactoryTest',
    'App\Tests\Service\Recommendation\Prompt\Model\RecommendationResponseSchemaTest'
        => 'App\Tests\Service\Ai\Llm\Prompt\Model\RecommendationResponseSchemaTest',
    'App\Tests\Service\Recommendation\Prompt\ModelReplyJsonDecoderTest'
        => 'App\Tests\Service\Ai\Llm\Prompt\ModelReplyJsonDecoderTest',
    'App\Tests\Service\Recommendation\Prompt\RecommendationAnswerBudgetTest'
        => 'App\Tests\Service\Ai\Llm\Prompt\RecommendationAnswerBudgetTest',
    'App\Tests\Service\Recommendation\Prompt\RecommendationCandidateLoaderTest'
        => 'App\Tests\Service\Recommendation\Pool\RecommendationCandidateLoaderTest',
    'App\Tests\Service\Recommendation\Prompt\RecommendationConsolidationParserTest'
        => 'App\Tests\Service\Ai\Llm\Prompt\RecommendationConsolidationParserTest',
    'App\Tests\Service\Recommendation\Prompt\RecommendationHistoryLoaderTest'
        => 'App\Tests\Service\Recommendation\Pool\RecommendationHistoryLoaderTest',
    'App\Tests\Service\Recommendation\Prompt\RecommendationPickParserTest'
        => 'App\Tests\Service\Ai\Llm\Prompt\RecommendationPickParserTest',
    'App\Tests\Service\Recommendation\Prompt\RecommendationProfileParserTest'
        => 'App\Tests\Service\Ai\Llm\Prompt\RecommendationProfileParserTest',
    'App\Tests\Service\Recommendation\Prompt\RecommendationPromptBuilderTest'
        => 'App\Tests\Service\Ai\Llm\Prompt\RecommendationPromptBuilderTest',
    'App\Tests\Service\Recommendation\Run\Factory\RecommendationRunLogFactoryTest'
        => 'App\Tests\Service\Ai\Llm\Run\Factory\RecommendationRunLogFactoryTest',
    'App\Tests\Service\Recommendation\Run\Model\CallSlotModelTest' => 'App\Tests\Service\Ai\Llm\Run\Model\CallSlotModelTest',
    'App\Tests\Service\Recommendation\Run\Model\ConsolidationOutcomeModelTest'
        => 'App\Tests\Service\Ai\Llm\Run\Model\ConsolidationOutcomeModelTest',
    'App\Tests\Service\Recommendation\Run\Model\ProfileDistillationOutcomeModelTest'
        => 'App\Tests\Service\Ai\Llm\Run\Model\ProfileDistillationOutcomeModelTest',
    'App\Tests\Service\Recommendation\Run\Model\WaveBatchModelTest'
        => 'App\Tests\Service\Ai\Llm\Run\Model\WaveBatchModelTest',
    'App\Tests\Service\Recommendation\Run\Pass\RecordedCallTest' => 'App\Tests\Service\Ai\Llm\Run\Pass\RecordedCallTest',
    'App\Tests\Service\Recommendation\Run\RecommendationCallRecorderTest'
        => 'App\Tests\Service\Ai\Llm\Run\RecommendationCallRecorderTest',
    'App\Tests\Service\Recommendation\Run\RecommendationConsolidationResolverTest'
        => 'App\Tests\Service\Ai\Llm\Run\RecommendationConsolidationResolverTest',
    'App\Tests\Service\Recommendation\Run\RecommendationProfileDistillerTest'
        => 'App\Tests\Service\Ai\Llm\Run\RecommendationProfileDistillerTest',
    'App\Tests\Service\Recommendation\Run\WaveContextLoaderTest' => 'App\Tests\Service\Ai\Llm\Run\WaveContextLoaderTest',
    'App\Tests\Service\Recommendation\Run\SweepStreamHeartbeatTest'
        => 'App\Tests\Service\Recommendation\Run\ProviderCallHeartbeat\SweepStreamHeartbeatTest',
    'App\Tests\Service\Recommendation\Run\TickLockKeepaliveTest'
        => 'App\Tests\Service\Recommendation\Run\ProviderCallHeartbeat\TickLockKeepaliveTest',
    'App\Tests\Service\Recommendation\Engine\RecommendationEngine\LlmRecommendationEngineTest'
        => 'App\Tests\Service\Ai\Llm\LlmRecommendationEngineTest',
    'App\Tests\Support\CountingCompletionStreamHeartbeat' => 'App\Tests\Support\CountingProviderCallHeartbeat',
    'App\Tests\Support\NullCompletionStreamHeartbeat' => 'App\Tests\Support\NullProviderCallHeartbeat',
];
```

Some lines exceed 120 columns; the file is not linted (docs/). Keep it as is.

- [ ] **Step 2: Survey** (record both)

```bash
find src/Service/Ai/Completion src/Service/Recommendation/Prompt -name '*.php' | wc -l   # expect 43
php -r '$m = require "../docs/superpowers/plans/2026-10-02-1344-scripts/moves.php"; echo count($m), "\n";'   # expect 103
```
And every key exists: `php -r 'require "../docs/superpowers/plans/2026-10-02-1344-scripts/class-names.php"; foreach (array_keys(require "../docs/superpowers/plans/2026-10-02-1344-scripts/moves.php") as $c) { if (!is_file(pathOf($c))) echo "missing $c\n"; }'` → expect no output.

- [ ] **Step 3: Move**

Run (from `backend/`, on a clean tree after Task 2's commit):

```bash
php ../docs/superpowers/plans/2026-10-02-1344-scripts/move-classes.php ../docs/superpowers/plans/2026-10-02-1344-scripts/moves.php
```
Expected: `Moved 103 classes (7 renamed); …` and exit 0. A `Failed:` collision line stops the script before any change: report it to the planner.

- [ ] **Step 4: Prove nothing but names moved**

```bash
php ../docs/superpowers/plans/2026-10-02-1344-scripts/compare-moves.php ../docs/superpowers/plans/2026-10-02-1344-scripts/moves.php HEAD
php ../docs/superpowers/plans/2026-10-02-1344-scripts/psr4-namespaces.php
find src/Service/Ai/Llm -name '*.php' | wc -l                 # expect 55
find src/Service/Recommendation/Pool -name '*.php' | wc -l     # expect 6
find src/Service/Recommendation/Run/ProviderCallHeartbeat -name '*.php' | wc -l   # expect 4
ls src/Service/Ai/Completion src/Service/Recommendation/Prompt 2>&1 | head -2      # expect "No such file or directory"
```
Expected: `0 of 103 moved files differ in code.`, `0 of 103 moved files declare the wrong namespace.`, and the PSR-4 sweep at its Task 0 baseline. Base is `HEAD` (Task 2's commit), not `origin/develop`, because Task 2 edited files this move carries.

- [ ] **Step 5: Reword the neutral docblocks (D16), comments only**

- `src/Service/Recommendation/Pool/Model/ArticleLineModel.php`: delete the class docblock (`/** One entry in a prompt; only candidate lines print their id, … */`); the prompt invariant lives in `RecommendationPromptBuilder::historyLine()`/`candidateLine()`.
- `src/Service/Recommendation/Pool/Model/CandidatePoolSummaryModel.php`: replace the docblock with `/** The whole snapshot pool's size and date span (\`Y-m-d\`), scoped by the same subscription gate as its lines. */`
- `src/Service/Recommendation/Pool/Model/RecommendationHistoryModel.php`: replace `The weighted reading history in its three prompt sections.` with `The weighted reading history in three sections.` (keep the second sentence).
- `src/Service/Recommendation/Pool/RecommendationCandidateLoader.php`: replace the class docblock with `/** Loads the candidate pool a run picks from, and re-resolves a checkpointed batch of ids. */`
- `src/Service/Recommendation/Pool/RecommendationHistoryLoader.php`: replace the class docblock with `/** The reader's weighted history: three capped, newest-first sections. */`
- `src/Service/Recommendation/Run/ProviderCallHeartbeat/ProviderCallHeartbeatInterface.php`: replace the docblock with `/** Told while a provider call is still alive, so a process others watch for liveness stays alive while it waits. */`
- `config/services.yaml`, the three comment lines above the heartbeat alias, become:

```yaml
    # A provider call pings one heartbeat while it runs and must not know who listens: a composite of the run-lock
    # keepalive and the sweep's liveness beat. Both stay injectable by class, as the advancer and the sweep arm them
    # by name. Which beats first is CompositeProviderCallHeartbeat::beat()'s rule.
```

- `docs/architecture.md` §9, replace `(\`Ai\Completion\CompletionStreamHeartbeat\CompletionStreamHeartbeatInterface\`, implemented in \`Recommendation\Run\`).` with:

```markdown
  (`Recommendation\Engine\RecommendationEngine\RecommendationEngineInterface`, implemented in `Ai\Llm`; and
  `Recommendation\Run\ProviderCallHeartbeat\ProviderCallHeartbeatInterface`, which the `Ai\Llm` transport calls).
```

Re-run `compare-moves.php … HEAD` → still `0 of 103 … differ`.

- [ ] **Step 6: Nothing stale is left**

```bash
php ../docs/superpowers/plans/2026-10-02-1344-scripts/stale-names.php ../docs/superpowers/plans/2026-10-02-1344-scripts/moves.php > var/moves-1344.stale
git -C .. grep -n -E \( -f backend/var/moves-1344.stale \) --and --not -e '^namespace ' -- . ':!docs/superpowers' ':!backend/tests/PhpStan'
git -C .. grep -n -E 'CompletionStreamHeartbeat|PromptLineModel|Recommendation\\Prompt|Ai\\Completion|Recommendation/Prompt|Ai/Completion' -- . ':!docs/superpowers'
```
Expected: both print nothing. The second grep also catches old short names in comments, YAML, Markdown and variable names (`$…CompletionStreamHeartbeat`); rename each hit by hand to its new name and re-run.

- [ ] **Step 7: `composer check`**

```bash
bin/console cache:warmup
composer check
```
Expected: green, `ServiceModuleCycleRule` and `ServiceRoleRule` included. A role finding names the class's expected home: that is a map error; fix the map entry (planner's call), not the rule.

- [ ] **Step 8: The suite**

The move touches about 60 test files across `Ai`, `Recommendation`, `Worker`, `Http`, `Command` and `Support`; the parallel run is the cheap way to cover them all.

Run: `composer test:parallel` → Expected: green, same test count as the Task 0 baseline plus Task 1 and Task 2's new tests.

- [ ] **Step 9: A deliberate `Recommendation → Ai\Llm` reference fails the cycle rule** (issue "done when"; quote the output)

```bash
cp src/Service/Recommendation/Run/TickPhases.php "$TMPDIR/TickPhases.php.orig"
```
Add `use App\Service\Ai\Llm\LlmRecommendationEngine;` to its imports and `\assert(LlmRecommendationEngine::class !== '');` as the first line of `advance()`. Run `composer stan`.
Expected: FAIL with `Service modules must not depend on each other in a cycle: Ai\Llm -> Recommendation -> Ai\Llm. …` (or a longer cycle through `Ai\Llm`), reported in `TickPhases.php`. Restore: `mv "$TMPDIR/TickPhases.php.orig" src/Service/Recommendation/Run/TickPhases.php`, re-run `composer stan` → green.

- [ ] **Step 10: Commit**

```bash
git add -A src tests config ../docs/architecture.md
git status --short | grep -v '^R ' | head -40    # review: only the reworded files, services.yaml and architecture.md are M
git commit -m "refactor(#1344): move the llm engine into service/ai/llm"
```

Reviewer: none per the lean-move rule; the planner checks Steps 4, 6 and 9's quoted output.

---

### Task 4: Capabilities in `/api/me` and `/api/me/ai`

**Files:**
- Create: `src/Http/RecommendationCapabilitiesJson.php`, `src/Http/ActiveAiJson.php`
- Modify: `src/Http/AiSettingsJson.php` (instance service), `src/Http/MeJson.php` (loses `ai`), `src/Http/MeProfileJson.php` (appends `ai`), `src/Controller/Api/AiSettingsController.php`
- Create: `tests/Http/RecommendationCapabilitiesJsonTest.php`, `tests/Http/ActiveAiJsonTest.php`
- Modify: `tests/Http/AiSettingsJsonTest.php`, `tests/Http/MeJsonTest.php`, `tests/Http/MeProfileJsonTest.php`, `tests/Controller/Api/AiSettingsControllerTest.php`

**Interfaces:**
- Consumes: `RecommendationEngineResolver::engineFor()`, `RecommendationEngineCapabilitiesModel`, `RecommendationTuningField` (Task 2); `ScriptedRecommendationEngine::reporting()` (Task 2).
- Produces: `RecommendationCapabilitiesJson::of(AiProviderSettings $connection): array{reasons: bool, tuningFields: list<string>}`; `ActiveAiJson::of(User $user): array{ready: bool, model: ?string, capabilities: array{reasons: bool, tuningFields: list<string>}|null}`; `AiSettingsJson` instance methods `configuration()`, `configurationFor()`, `list()`, `added()`, `models()` with unchanged parameters; every configuration shape gains `capabilities`. Wire (D12): `{"capabilities": {"reasons": true, "tuningFields": ["contextWindow", "batchSize", "suppressReasoning", "slowModel", "maxBatchSize", "batchConcurrency"]}}`.

- [ ] **Step 1: `RecommendationCapabilitiesJsonTest` (fails: no class)**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Entity\AiProviderSettings;
use App\Entity\User;
use App\Http\RecommendationCapabilitiesJson;
use App\Service\Recommendation\Engine\Model\RecommendationEngineCapabilitiesModel;
use App\Service\Recommendation\Engine\Model\RecommendationTuningField;
use App\Tests\Support\AiProviderSettingsFactory;
use App\Tests\Support\ScriptedRecommendationEngine;
use PHPUnit\Framework\TestCase;

final class RecommendationCapabilitiesJsonTest extends TestCase
{
    public function testItNamesTheEnginesTuningFieldsByTheirWireNamesInTheEnginesOrder(): void
    {
        $json = self::json(new RecommendationEngineCapabilitiesModel(
            false,
            [RecommendationTuningField::SlowModel, RecommendationTuningField::ContextWindow],
        ));

        self::assertSame(
            ['reasons' => false, 'tuningFields' => ['slowModel', 'contextWindow']],
            $json->of($this->connection()),
        );
    }

    public function testAnEngineThatWritesReasonsButReadsNoTuningSaysSo(): void
    {
        $json = self::json(new RecommendationEngineCapabilitiesModel(true, []));

        self::assertSame(['reasons' => true, 'tuningFields' => []], $json->of($this->connection()));
    }

    private static function json(RecommendationEngineCapabilitiesModel $capabilities): RecommendationCapabilitiesJson
    {
        return new RecommendationCapabilitiesJson(ScriptedRecommendationEngine::reporting($capabilities)->resolver());
    }

    private function connection(): AiProviderSettings
    {
        return AiProviderSettingsFactory::build(
            new User('capabilities-json@example.test', new \DateTimeImmutable('2026-10-02 09:00:00')),
        );
    }
}
```

Run → error, class not found.

- [ ] **Step 2: `RecommendationCapabilitiesJson`**

```php
<?php

declare(strict_types=1);

namespace App\Http;

use App\Entity\AiProviderSettings;
use App\Service\Recommendation\Engine\Model\RecommendationTuningField;
use App\Service\Recommendation\Engine\RecommendationEngineResolver;

/** What a connection's engine can do, so a client offers only the settings that apply and never names the engine. */
final readonly class RecommendationCapabilitiesJson
{
    public function __construct(private RecommendationEngineResolver $engines)
    {
    }

    /** @return array{reasons: bool, tuningFields: list<string>} */
    public function of(AiProviderSettings $connection): array
    {
        $capabilities = $this->engines->engineFor($connection)->capabilities();

        return [
            'reasons' => $capabilities->writesReasons,
            'tuningFields' => array_map(
                static fn (RecommendationTuningField $field): string => $field->value,
                $capabilities->tuningFields,
            ),
        ];
    }
}
```

Run → PASS (2 tests).

- [ ] **Step 3: `AiSettingsJson` becomes a service, every configuration carries its capabilities**

Replace `src/Http/AiSettingsJson.php` with:

```php
<?php

declare(strict_types=1);

namespace App\Http;

use App\Entity\AiProviderSettings;
use App\Entity\User;
use App\Service\Ai\Support\AiReadiness;
use App\Service\Recommendation\Settings\Model\RecommendationPackingSettingsModel;

/**
 * The client's view of the account's AI provider configurations. Hand-built,
 * not serialised, so a sealed key never reaches the wire.
 */
final readonly class AiSettingsJson
{
    public function __construct(private RecommendationCapabilitiesJson $capabilities)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function configuration(AiProviderSettings $settings, ?int $activeId): array
    {
        return [
            'id' => $settings->getId(),
            'name' => $settings->getName(),
            'baseUrl' => $settings->getBaseUrl(),
            'apiKeyHint' => $settings->getApiKeyHint(),
            'model' => $settings->getModel(),
            'suppressReasoning' => $settings->suppressesReasoning(),
            'batchConcurrency' => $settings->batchConcurrency(),
            'slowModel' => $settings->isSlowModel(),
            'maxBatchSize' => $settings->maxBatchSize(),
            'ready' => AiReadiness::of($settings),
            'active' => $settings->getId() === $activeId,
            'capabilities' => $this->capabilities->of($settings),
        ];
    }

    /** @return array<string, mixed> */
    public function configurationFor(AiProviderSettings $settings, User $owner): array
    {
        return $this->configuration($settings, $owner->getActiveAiProviderSettings()?->getId());
    }

    /**
     * @param list<AiProviderSettings> $configurations
     *
     * @return array<string, mixed>
     */
    public function list(array $configurations, ?int $activeId): array
    {
        return [
            'configs' => array_map(
                fn (AiProviderSettings $each): array => $this->configuration($each, $activeId),
                $configurations,
            ),
            'activeId' => $activeId,
            // The ceiling the packer applies when a connection leaves its cap
            // empty. Sent so the settings form shows the same number the run
            // would use, from its one definition rather than a copy that drifts.
            'defaultMaxBatchSize' => RecommendationPackingSettingsModel::DEFAULT_MAXIMUM_BATCH_SIZE,
        ];
    }

    /**
     * @param list<string> $models
     *
     * @return array<string, mixed>
     */
    public function added(AiProviderSettings $settings, array $models): array
    {
        return $this->configuration($settings, null) + ['models' => $models];
    }

    /**
     * @param list<string> $models
     *
     * @return array<string, mixed>
     */
    public function models(array $models): array
    {
        return ['models' => $models];
    }
}
```

In `src/Controller/Api/AiSettingsController.php`: add `private AiSettingsJson $settingsJson,` as the last constructor parameter, and replace every `AiSettingsJson::` call (11 of them: `list`, `added`, `configurationFor` ×8, `models`) with `$this->settingsJson->`. Run `grep -c 'AiSettingsJson::' src/Controller/Api/AiSettingsController.php` → expect `0`.

In `tests/Http/AiSettingsJsonTest.php`:
- add imports `App\Http\RecommendationCapabilitiesJson`, `App\Service\Recommendation\Engine\Model\RecommendationEngineCapabilitiesModel`, `App\Service\Recommendation\Engine\Model\RecommendationTuningField`, `App\Tests\Support\ScriptedRecommendationEngine`
- add the helper:

```php
    private function json(): AiSettingsJson
    {
        $engine = ScriptedRecommendationEngine::reporting(
            new RecommendationEngineCapabilitiesModel(true, [RecommendationTuningField::BatchConcurrency]),
        );

        return new AiSettingsJson(new RecommendationCapabilitiesJson($engine->resolver()));
    }
```

- replace every `AiSettingsJson::` with `$this->json()->`
- add:

```php
    public function testTheConfigurationShapeCarriesItsEnginesCapabilities(): void
    {
        $shape = $this->json()->configuration($this->settings('gpt-4o'), null);

        self::assertSame(['reasons' => true, 'tuningFields' => ['batchConcurrency']], $shape['capabilities']);
    }
```

Run: `php bin/phpunit tests/Http/AiSettingsJsonTest.php tests/Controller/Api/AiSettingsControllerTest.php` → PASS.

- [ ] **Step 4: `ActiveAiJsonTest` (fails: no class)**

`tests/Http/ActiveAiJsonTest.php` (the three `ai` cases move here from `MeJsonTest`, with capabilities):

```php
<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Entity\User;
use App\Http\ActiveAiJson;
use App\Http\RecommendationCapabilitiesJson;
use App\Service\Recommendation\Engine\Model\RecommendationEngineCapabilitiesModel;
use App\Service\Recommendation\Engine\Model\RecommendationTuningField;
use App\Tests\Support\AiProviderSettingsFactory;
use App\Tests\Support\ScriptedRecommendationEngine;
use PHPUnit\Framework\TestCase;

/** The `ai` block reads an association, not a column: it must report the ACTIVE configuration, not just any. */
final class ActiveAiJsonTest extends TestCase
{
    private const array SCRIPTED_CAPABILITIES = ['reasons' => false, 'tuningFields' => ['slowModel', 'contextWindow']];

    public function testAnAccountWithNoActiveConfigurationIsNotReadyAndHasNoCapabilities(): void
    {
        self::assertSame(
            ['ready' => false, 'model' => null, 'capabilities' => null],
            $this->json()->of($this->user()),
        );
    }

    public function testTheActiveConfigurationReportsItsModelAndItsEnginesCapabilities(): void
    {
        $user = $this->user();
        $this->activate($user, 'Work OpenAI', 'gpt-4o-mini');

        self::assertSame(
            ['ready' => true, 'model' => 'gpt-4o-mini', 'capabilities' => self::SCRIPTED_CAPABILITIES],
            $this->json()->of($user),
        );
    }

    /** Not ready, yet an engine: readiness is the model, capabilities are the connection's. */
    public function testAnActiveConfigurationWithoutAModelIsNotReadyButHasCapabilities(): void
    {
        $user = $this->user();
        $user->setActiveAiProviderSettings(AiProviderSettingsFactory::build($user, 'Fresh'));

        self::assertSame(
            ['ready' => false, 'model' => null, 'capabilities' => self::SCRIPTED_CAPABILITIES],
            $this->json()->of($user),
        );
    }

    /** A verified configuration that never became active must not change the reported state. */
    public function testASecondNonActiveConfigurationDoesNotChangeTheReportedState(): void
    {
        $user = $this->user();
        $this->activate($user, 'Work OpenAI', 'gpt-4o-mini');
        $other = AiProviderSettingsFactory::build($user, 'Personal OpenRouter');
        $other->chooseModel('claude-3-haiku', new \DateTimeImmutable('2026-08-09T09:06:00Z'), null);

        self::assertSame(
            ['ready' => true, 'model' => 'gpt-4o-mini', 'capabilities' => self::SCRIPTED_CAPABILITIES],
            $this->json()->of($user),
        );
    }

    private function activate(User $user, string $name, string $model): void
    {
        $active = AiProviderSettingsFactory::build($user, $name);
        $active->chooseModel($model, new \DateTimeImmutable('2026-08-09T09:05:00Z'), null);
        $user->setActiveAiProviderSettings($active);
    }

    private function json(): ActiveAiJson
    {
        $engine = ScriptedRecommendationEngine::reporting(new RecommendationEngineCapabilitiesModel(
            false,
            [RecommendationTuningField::SlowModel, RecommendationTuningField::ContextWindow],
        ));

        return new ActiveAiJson(new RecommendationCapabilitiesJson($engine->resolver()));
    }

    private function user(): User
    {
        return new User('reader@example.test', new \DateTimeImmutable('2026-08-09 09:00:00'));
    }
}
```

Run → error, class not found.

- [ ] **Step 5: `ActiveAiJson`, `MeJson`, `MeProfileJson`**

`src/Http/ActiveAiJson.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http;

use App\Entity\User;
use App\Service\Ai\Support\AiReadiness;

/** The `ai` block of /api/me: the active connection's readiness, model and engine capabilities. */
final readonly class ActiveAiJson
{
    public function __construct(private RecommendationCapabilitiesJson $capabilities)
    {
    }

    /** @return array{ready: bool, model: ?string, capabilities: array{reasons: bool, tuningFields: list<string>}|null} */
    public function of(User $user): array
    {
        $connection = $user->getActiveAiProviderSettings();

        return [
            'ready' => AiReadiness::of($connection),
            'model' => $connection?->getModel(),
            'capabilities' => null === $connection ? null : $this->capabilities->of($connection),
        ];
    }
}
```

In `src/Http/MeJson.php`: delete the `'ai' => [ … ],` block (the last entry) and `use App\Service\Ai\Support\AiReadiness;`, and the now-unused `$aiSettings = $user->getActiveAiProviderSettings();` line.

Replace `src/Http/MeProfileJson.php` with:

```php
<?php

declare(strict_types=1);

namespace App\Http;

use App\Entity\User;
use App\Service\Mail\MailCapability;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/** MeJson::profile plus what /api/me needs a service for: whether mail is on, the timezone, the active AI connection. */
final readonly class MeProfileJson
{
    public function __construct(
        private MailCapability $mail,
        private ActiveAiJson $activeAi,
        #[Autowire('%env(string:APP_TIMEZONE)%')]
        private string $instanceTimezone,
    ) {
    }

    /** @return array<string, mixed> */
    public function of(User $user): array
    {
        return [
            ...MeJson::profile($user, $this->mail->isEnabled(), $this->instanceTimezone),
            'ai' => $this->activeAi->of($user),
        ];
    }
}
```

In `src/Entity/User.php`'s `setActiveAiProviderSettings()` docblock, replace `MeJson and other readers` with `ActiveAiJson and other readers`.

In `tests/Http/MeJsonTest.php`: delete the three `ai` tests, the `configuration()` helper, the now-unused imports (`AiProviderSettings`, `AiProviderSettingsFactory`), and the class docblock (it moved to `ActiveAiJsonTest`).

In `tests/Http/MeProfileJsonTest.php`:
- imports: add `App\Http\ActiveAiJson`, `App\Http\RecommendationCapabilitiesJson`, `App\Service\Recommendation\Engine\RecommendationEngineResolver`
- add `private const array NO_ACTIVE_CONNECTION = ['ready' => false, 'model' => null, 'capabilities' => null];`
- the two expectations become `[...MeJson::profile($user, false, 'Europe/Berlin'), 'ai' => self::NO_ACTIVE_CONNECTION]` and `[...MeJson::profile($user, true, 'UTC'), 'ai' => self::NO_ACTIVE_CONNECTION]`
- `profileJson()` becomes:

```php
    private function profileJson(string $instanceTimezone): MeProfileJson
    {
        $mail = self::getContainer()->get(MailCapability::class);
        self::assertInstanceOf(MailCapability::class, $mail);
        $engines = self::getContainer()->get(RecommendationEngineResolver::class);
        self::assertInstanceOf(RecommendationEngineResolver::class, $engines);

        return new MeProfileJson($mail, new ActiveAiJson(new RecommendationCapabilitiesJson($engines)), $instanceTimezone);
    }
```

Run: `php bin/phpunit tests/Http` → PASS.

- [ ] **Step 6: The endpoints report the LLM's capabilities**

In `tests/Controller/Api/AiSettingsControllerTest.php` add the constant after `PROVIDER_BUDGET`:

```php
    private const array LLM_CAPABILITIES = [
        'reasons' => true,
        'tuningFields' => [
            'contextWindow',
            'batchSize',
            'suppressReasoning',
            'slowModel',
            'maxBatchSize',
            'batchConcurrency',
        ],
    ];
```

and, after `testActivatingASecondConfigurationSwitchesTheActiveOne()`:

```php
    public function testEveryConfigurationAndTheProfileReportTheLlmEnginesCapabilities(): void
    {
        $client = $this->clientAnswering(['gpt-4o', 'gpt-4o-mini']);
        $this->accountOn($client, 'ai-capabilities@example.test');
        $this->addAndReadyConfiguration($client, 'gpt-4o-mini');

        $client->request('GET', '/api/me/ai');
        $payload = $this->payload($client);
        self::assertIsArray($payload['configs']);
        self::assertIsArray($payload['configs'][0]);
        self::assertSame(self::LLM_CAPABILITIES, $payload['configs'][0]['capabilities']);

        $client->request('GET', '/api/me');
        $me = $this->payload($client);
        self::assertIsArray($me['ai']);
        self::assertSame(self::LLM_CAPABILITIES, $me['ai']['capabilities']);
    }

    public function testAnAccountWithoutAnActiveConfigurationReportsNoCapabilities(): void
    {
        $client = $this->clientAnswering(['gpt-4o']);
        $this->accountOn($client, 'ai-no-capabilities@example.test');

        $client->request('GET', '/api/me');
        $me = $this->payload($client);
        self::assertIsArray($me['ai']);
        self::assertArrayHasKey('capabilities', $me['ai']);
        self::assertNull($me['ai']['capabilities']);
    }
```

Run: `php bin/phpunit tests/Controller/Api/AiSettingsControllerTest.php tests/Controller/Api/JwtAccessTest.php tests/Controller/Api/MeControllerTest.php` (skip a path that does not exist) → PASS. `JwtAccessTest::testMeExposesExactlyTheIntendedFields` stays green: the top-level keys are unchanged.

- [ ] **Step 7: Deletion checks** (quote each FAIL)

1. `RecommendationCapabilitiesJson`: `'reasons' => true` → first test FAILS; `$field->name` for `$field->value` → first test FAILS (`SlowModel` vs `slowModel`).
2. `AiSettingsJson::configuration()`: delete the `capabilities` entry → `testTheConfigurationShapeCarriesItsEnginesCapabilities` errors (undefined key) and the controller test FAILS.
3. `ActiveAiJson`: `'capabilities' => null` always → `testTheActiveConfigurationReports…` FAILS; `null === $connection` → `!AiReadiness::of($connection)` → `testAnActiveConfigurationWithoutAModel…` FAILS.
4. `MeProfileJson`: drop the `'ai' =>` entry → both `MeProfileJsonTest` tests FAIL and `JwtAccessTest` FAILS (missing `ai`).

- [ ] **Step 8: Gates and commit**

```bash
bin/console cache:warmup
composer check      # phptramp: expect the D15 warning (RecommendationCapabilitiesJson::of → engineFor → kindFor), no failure
composer md
git add src tests
git commit -m "feat(#1344): report the engine's capabilities with each connection"
```
Also run PhpStorm `lint_files` on the touched `src/Http/*.php`, the resolver and the controller; block on ERROR/WARNING (D15 covers `kindFor()`).

Reviewer: yes; checks the native-iOS checklist (architecture §6): no new endpoint, plain JSON, no browser input.

---

### Task 5: The frontend renders from capabilities

**Files:**
- Modify: `frontend/src/app/core/ai-availability.service.ts`, `frontend/src/app/core/auth/auth.service.ts:39`
- Modify: `frontend/src/app/settings/ai/ai-settings.service.ts`, `ai-section.component.ts`, `ai-section.component.html`
- Modify: `frontend/src/app/settings/recommendations/recommendation-settings-card.component.ts`, `.html`
- Create: `frontend/src/testing/recommendation-capabilities.ts`
- Modify (specs): `core/ai-availability.service.spec.ts`, `settings/ai/ai-settings.service.spec.ts`, `settings/ai/ai-section.component.spec.ts`, `settings/recommendations/recommendation-settings-card.component.spec.ts`, and the fixtures listed in Step 7
- Modify: `frontend/e2e/ai-config-rejected.spec.ts` (stub gains `capabilities`)

**Interfaces:**
- Consumes: the Task 4 wire shape.
- Produces (in `core/ai-availability.service.ts`): `type RecommendationTuningField = 'contextWindow' | 'batchSize' | 'suppressReasoning' | 'slowModel' | 'maxBatchSize' | 'batchConcurrency'`; `interface RecommendationCapabilities { readonly reasons: boolean; readonly tuningFields: readonly RecommendationTuningField[] }`; `const NO_RECOMMENDATION_CAPABILITIES`; `AiAvailability.capabilities: RecommendationCapabilities | null`; `AiAvailabilityService.capabilities: Signal<RecommendationCapabilities>`. `AiConfig.capabilities: RecommendationCapabilities`. `RecommendationSettingsCardComponent.offersReasons` (computed) and `.offersTuning(field)`; `AiSectionComponent.offersTuning(config, field)`.

- [ ] **Step 1: Types and the availability signal**

In `frontend/src/app/core/ai-availability.service.ts`, replace the `AiAvailability` interface (and its docblock) with:

```ts
/** A recommendation setting only some engines read; each value is that setting's field name on the wire. */
export type RecommendationTuningField =
  | 'contextWindow'
  | 'batchSize'
  | 'suppressReasoning'
  | 'slowModel'
  | 'maxBatchSize'
  | 'batchConcurrency';

/** What a connection's recommendation engine can do; the client renders from it and never learns the engine. */
export interface RecommendationCapabilities {
  readonly reasons: boolean;
  readonly tuningFields: readonly RecommendationTuningField[];
}

export const NO_RECOMMENDATION_CAPABILITIES: RecommendationCapabilities = {
  reasons: false,
  tuningFields: [],
};

/**
 * The whole of what this service tracks — and so the whole of what any caller
 * has to hand it. `/api/me` reports exactly these fields under `ai`;
 * `capabilities` is null while no connection is active.
 */
export interface AiAvailability {
  readonly model: string | null;
  readonly ready: boolean;
  readonly capabilities: RecommendationCapabilities | null;
}
```

In the class, after `modelSignal`:

```ts
  private readonly capabilitiesSignal = signal<RecommendationCapabilities>(
    NO_RECOMMENDATION_CAPABILITIES,
  );
```

after `readonly model = …`:

```ts
  readonly capabilities = this.capabilitiesSignal.asReadonly();
```

`reset()` sets `{ ready: false, model: null, capabilities: null }`, and `set()` gains

```ts
    this.capabilitiesSignal.set(availability.capabilities ?? NO_RECOMMENDATION_CAPABILITIES);
```

In `frontend/src/app/core/auth/auth.service.ts`, `CurrentUser.ai` becomes `ai: AiAvailability;` and the existing `AiAvailabilityService` import gains `AiAvailability`.

`frontend/src/testing/recommendation-capabilities.ts`:

```ts
import { RecommendationCapabilities } from '../app/core/ai-availability.service';

/** What the LLM engine reports: reasons, and every tuning field. */
export const EVERY_RECOMMENDATION_CAPABILITY: RecommendationCapabilities = {
  reasons: true,
  tuningFields: [
    'contextWindow',
    'batchSize',
    'suppressReasoning',
    'slowModel',
    'maxBatchSize',
    'batchConcurrency',
  ],
};
```

- [ ] **Step 2: The availability spec**

In `core/ai-availability.service.spec.ts`: import `NO_RECOMMENDATION_CAPABILITIES`, `RecommendationCapabilities` and `EVERY_RECOMMENDATION_CAPABILITY` (from `../../testing/recommendation-capabilities`); the helpers become

```ts
  const user = (
    ready: boolean,
    model: string | null,
    capabilities: RecommendationCapabilities | null = null,
  ): CurrentUser => ({ ai: { ready, model, capabilities } }) as CurrentUser;

  const availability = (over: Partial<AiAvailability>): AiAvailability => ({
    model: null,
    ready: false,
    capabilities: null,
    ...over,
  });
```

and these tests join:

```ts
  it('offers no recommendation capability before an account is adopted', () => {
    expect(service().capabilities()).toEqual(NO_RECOMMENDATION_CAPABILITIES);
  });

  it("adopts the active connection's capabilities", () => {
    const ai = service();
    ai.adopt(user(true, 'gpt-4o', { reasons: true, tuningFields: ['slowModel'] }));
    expect(ai.capabilities()).toEqual({ reasons: true, tuningFields: ['slowModel'] });
  });

  it('drops the capabilities with the signed-out account', () => {
    const ai = service();
    ai.adopt(user(true, 'gpt-4o', EVERY_RECOMMENDATION_CAPABILITY));
    ai.reset();
    expect(ai.capabilities()).toEqual(NO_RECOMMENDATION_CAPABILITIES);
  });
```

- [ ] **Step 3: The AI settings service carries capabilities**

In `settings/ai/ai-settings.service.ts`: import `RecommendationCapabilities` with `AiAvailabilityService`; `AiConfig` gains `readonly capabilities: RecommendationCapabilities;` after `maxBatchSize`; `applyAvailability()` becomes

```ts
  private applyAvailability(): void {
    const active = this.configs().find((each) => each.active);
    this.availability.apply({
      ready: active?.ready ?? false,
      model: active?.model ?? null,
      capabilities: active?.capabilities ?? null,
    });
  }
```

In `settings/ai/ai-settings.service.spec.ts`: the `config()` builder gains `capabilities: EVERY_RECOMMENDATION_CAPABILITY,` before `...over`; import it and `NO_RECOMMENDATION_CAPABILITIES`, `RecommendationCapabilities`; add:

```ts
  it("hands the active configuration's capabilities to the availability, and none once it is gone", () => {
    const capabilities: RecommendationCapabilities = { reasons: false, tuningFields: ['batchSize'] };
    service.load();
    ctrl.expectOne(`${base}/api/me/ai`).flush({
      configs: [config({ id: 1, active: true, ready: true, model: 'gpt-4o', capabilities })],
      activeId: 1,
      defaultMaxBatchSize: 140,
    });
    expect(availability.capabilities()).toEqual(capabilities);

    service.remove(1);
    ctrl.expectOne(`${base}/api/me/ai/configs/1`).flush(null);
    expect(availability.capabilities()).toEqual(NO_RECOMMENDATION_CAPABILITIES);
  });
```

- [ ] **Step 4: The recommendation card**

In `recommendation-settings-card.component.ts`: import `AiAvailabilityService, RecommendationTuningField` from `'../../core/ai-availability.service'`; after `private readonly language = inject(LanguageService);` add

```ts
  private readonly availability = inject(AiAvailabilityService);

  /** Only an engine that writes reasons offers the switch that shows them. */
  readonly offersReasons = computed(() => this.availability.capabilities().reasons);
```

and after `onLookbackDays()`:

```ts
  /** Whether the active engine reads this setting; one it ignores is not offered. */
  offersTuning(field: RecommendationTuningField): boolean {
    return this.availability.capabilities().tuningFields.includes(field);
  }
```

In `recommendation-settings-card.component.html`:
- wrap the first `<app-settings-row>` (show reasons) in `@if (offersReasons()) { … }` and give the row `data-testid="show-reasons"`:

```html
      @if (offersReasons()) {
        <app-settings-row
          data-testid="show-reasons"
          [title]="'settings.ai.recommendations.showReasons' | transloco"
          [description]="'settings.ai.recommendations.showReasonsDesc' | transloco"
        >
          <app-info-tip
            rowTitleTip
            [text]="'settings.ai.info.showReasons' | transloco"
            [label]="'settings.ai.recommendations.showReasons' | transloco"
          />
          <app-toggle
            [label]="'settings.ai.recommendations.showReasons' | transloco"
            [checked]="showReasons()"
            (toggled)="onShowReasons($event)"
          />
        </app-settings-row>
      }
```

- wrap the batch-size `<app-field>` (the one holding `select[data-testid="batch-size"]`, the reserved `numeric-range` line and its comment included) in `@if (offersTuning('batchSize')) { … }`
- wrap the context-window `<app-field>` in `@if (offersTuning('contextWindow')) { … }` and give its `<input>` `data-testid="context-window"`

Then format: `docker compose exec -T frontend npx prettier --write src/app/settings/recommendations/recommendation-settings-card.component.html src/app/settings/recommendations/recommendation-settings-card.component.ts`.

- [ ] **Step 5: The card spec**

In `recommendation-settings-card.component.spec.ts`:
- imports: `AiAvailabilityService, NO_RECOMMENDATION_CAPABILITIES, RecommendationCapabilities` from `'../../core/ai-availability.service'`, `EVERY_RECOMMENDATION_CAPABILITY` from `'../../../testing/recommendation-capabilities'`
- `mount` takes the engine's capabilities and applies them before the component is created:

```ts
  function mount(
    initial: RecommendationSettingsState = STATE,
    capabilities: RecommendationCapabilities = EVERY_RECOMMENDATION_CAPABILITY,
  ): ComponentFixture<RecommendationSettingsCardComponent> {
    TestBed.resetTestingModule();
    TestBed.configureTestingModule({
      imports: [provideTranslocoTesting()],
      providers: [
        provideHttpClient(),
        provideHttpClientTesting(),
        { provide: API_BASE_URL, useValue: '' },
        { provide: Dialog, useValue: dialogStub },
        { provide: ToastService, useValue: toastStub },
      ],
    });
    TestBed.inject(AiAvailabilityService).apply({ ready: true, model: 'gpt-4o', capabilities });
    http = TestBed.inject(HttpTestingController);
    const fixture = TestBed.createComponent(RecommendationSettingsCardComponent);
    fixture.detectChanges();
    http.expectOne('/api/me/ai/recommendations').flush(initial);
    fixture.detectChanges();
    return fixture;
  }
```

- `showReasonsToggle` selects `'[data-testid="show-reasons"] app-toggle input[type="checkbox"]'` (the debug toggle is also an `app-settings-row app-toggle`, so the old selector would find it once the switch is hidden); add

```ts
  const batchSizeSelect = (
    fixture: ComponentFixture<RecommendationSettingsCardComponent>,
  ): HTMLSelectElement | null =>
    fixture.nativeElement.querySelector('select[data-testid="batch-size"]');

  const contextWindowInput = (
    fixture: ComponentFixture<RecommendationSettingsCardComponent>,
  ): HTMLInputElement | null =>
    fixture.nativeElement.querySelector('input[data-testid="context-window"]');
```

- add:

```ts
  describe("rendering from the engine's capabilities", () => {
    it('offers the reasons switch, the batch size and the context window to an engine that reads them all', () => {
      const fixture = mount();

      expect(showReasonsToggle(fixture)).not.toBeNull();
      expect(batchSizeSelect(fixture)).not.toBeNull();
      expect(contextWindowInput(fixture)).not.toBeNull();
    });

    it('offers none of them to an engine without reasons or tuning, and keeps the shared settings', () => {
      const fixture = mount(STATE, NO_RECOMMENDATION_CAPABILITIES);

      expect(showReasonsToggle(fixture)).toBeNull();
      expect(batchSizeSelect(fixture)).toBeNull();
      expect(contextWindowInput(fixture)).toBeNull();
      expect(picksInput(fixture)).not.toBeNull();
      expect(cadenceSelect(fixture)).not.toBeNull();
      expect(debugToggle(fixture)).not.toBeNull();
    });

    it('offers each tuning control by its own field', () => {
      const fixture = mount(STATE, { reasons: false, tuningFields: ['contextWindow'] });

      expect(contextWindowInput(fixture)).not.toBeNull();
      expect(batchSizeSelect(fixture)).toBeNull();
      expect(showReasonsToggle(fixture)).toBeNull();
    });

    it('offers the reasons switch to an engine that writes reasons but reads no tuning', () => {
      const fixture = mount(STATE, { reasons: true, tuningFields: [] });

      expect(showReasonsToggle(fixture)).not.toBeNull();
      expect(batchSizeSelect(fixture)).toBeNull();
      expect(contextWindowInput(fixture)).toBeNull();
    });
  });
```

**Assumption (verify):** `contextWindow: null` in the draft of a hidden field never trips `validateExpertFields()` (it skips `null`) and a saved value is always in range; the existing save tests run with every capability, so they are unaffected.

- [ ] **Step 6: The AI section rows**

In `ai-section.component.ts`: import `RecommendationTuningField` from `'../../core/ai-availability.service'`; after `toggleSlowModel()` add

```ts
  /** Whether this connection's engine reads the setting; one it ignores is not offered. */
  offersTuning(config: AiConfig, field: RecommendationTuningField): boolean {
    return config.capabilities.tuningFields.includes(field);
  }
```

In `ai-section.component.html`, replace the block from `<div class="reasoning-toggle">` through the `@if (ai.savedConcurrencyId() === config.id) { … }` that follows the batch-concurrency field (inside the rename `} @else {` branch) with:

```html
                      @if (
                        offersTuning(config, 'suppressReasoning') ||
                        offersTuning(config, 'slowModel') ||
                        offersTuning(config, 'maxBatchSize')
                      ) {
                        <div class="reasoning-toggle">
                          @if (offersTuning(config, 'suppressReasoning')) {
                            <span class="reasoning-toggle-row">
                              <label class="reasoning-check">
                                <input
                                  type="checkbox"
                                  [checked]="config.suppressReasoning"
                                  [disabled]="ai.busy()"
                                  (change)="toggleReasoning(config, $event)"
                                />
                                <span>{{ 'settings.ai.configs.reasoning' | transloco }}</span>
                              </label>
                              <app-info-tip
                                [text]="'settings.ai.info.reasoning' | transloco"
                                [label]="'settings.ai.configs.reasoning' | transloco"
                              />
                            </span>
                          }
                          @if (offersTuning(config, 'slowModel')) {
                            <span class="reasoning-toggle-row">
                              <label class="reasoning-check">
                                <input
                                  type="checkbox"
                                  [checked]="config.slowModel"
                                  [disabled]="ai.busy()"
                                  (change)="toggleSlowModel(config, $event)"
                                />
                                <span>{{ 'settings.ai.configs.slowModel' | transloco }}</span>
                              </label>
                              <app-info-tip
                                [text]="'settings.ai.info.slowModel' | transloco"
                                [label]="'settings.ai.configs.slowModel' | transloco"
                              />
                            </span>
                          }
                          @if (offersTuning(config, 'maxBatchSize')) {
                            <span class="reasoning-toggle-row">
                              <label class="batch-cap-label">
                                <span>{{ 'settings.ai.configs.maxBatchSize' | transloco }}</span>
                                <input
                                  type="number"
                                  min="5"
                                  max="200"
                                  class="batch-cap-input"
                                  [placeholder]="defaultMaxBatchSize()"
                                  [value]="config.maxBatchSize ?? ''"
                                  [disabled]="ai.busy()"
                                  (change)="setMaxBatchSize(config, $event)"
                                />
                              </label>
                              <app-info-tip
                                [text]="
                                  'settings.ai.info.maxBatchSize'
                                    | transloco: { default: ai.defaultMaxBatchSize() }
                                "
                                [label]="'settings.ai.configs.maxBatchSize' | transloco"
                              />
                            </span>
                          }
                        </div>
                      }
                      @if (offersTuning(config, 'batchConcurrency')) {
                        <app-field
                          [label]="'settings.ai.configs.batchConcurrency' | transloco"
                          [info]="'settings.ai.info.batchConcurrency' | transloco"
                        >
                          <select
                            [disabled]="ai.busy()"
                            (change)="setBatchConcurrency(config, $event)"
                          >
                            @for (option of concurrencyOptions; track option) {
                              <option
                                [value]="option"
                                [selected]="config.batchConcurrency === option"
                              >
                                {{ option }}
                              </option>
                            }
                          </select>
                        </app-field>
                        @if (ai.savedConcurrencyId() === config.id) {
                          <span class="saved">{{ 'settings.ai.configs.saved' | transloco }}</span>
                        }
                      }
```

Then `docker compose exec -T frontend npx prettier --write src/app/settings/ai/ai-section.component.html src/app/settings/ai/ai-section.component.ts`.

In `ai-section.component.spec.ts`: `config()` gains `capabilities: EVERY_RECOMMENDATION_CAPABILITY,` before `...over` (import it, and `NO_RECOMMENDATION_CAPABILITIES`); add:

```ts
  it('offers a row no tuning control when its engine reads none, and keeps its actions', () => {
    const fixture = mountWithConfigs([config({ id: 7, capabilities: NO_RECOMMENDATION_CAPABILITIES })]);

    expect(row(fixture, 0).querySelector('.reasoning-toggle')).toBeNull();
    expect(row(fixture, 0).querySelector('app-field select')).toBeNull();
    expect(row(fixture, 0).querySelector('.activate')).not.toBeNull();
  });

  it('offers each row control by its own field', () => {
    const fixture = mountWithConfigs([
      config({
        id: 7,
        slowModel: false,
        capabilities: { reasons: false, tuningFields: ['slowModel', 'batchConcurrency'] },
      }),
    ]);
    const setSlowModel = jest.spyOn(ai, 'setSlowModel').mockImplementation(() => undefined);

    const boxes = row(fixture, 0).querySelectorAll('.reasoning-toggle input[type=checkbox]');
    expect(boxes).toHaveLength(1);
    expect(row(fixture, 0).querySelector('.reasoning-toggle input[type="number"]')).toBeNull();
    expect(row(fixture, 0).querySelector('app-field select')).not.toBeNull();

    (boxes[0] as HTMLInputElement).checked = true;
    boxes[0].dispatchEvent(new Event('change'));
    expect(setSlowModel).toHaveBeenCalledWith(7, true);
  });
```

The existing row tests (reasoning, slow model, batch cap, concurrency) keep running with every capability, which is the "full set renders as before" proof.

- [ ] **Step 7: Spec fixtures and the e2e stub**

Each of these builds a `CurrentUser` or an `AiAvailability` and must now name `capabilities` (`typecheck:spec` fails otherwise). Add `capabilities: null` to the `ai` object at:
- `src/app/reader/shell/sidebar/sidebar.component.spec.ts:61`
- `src/app/reader/shell/sidebar/sidebar-foot.component.spec.ts:35`
- `src/app/settings/account/account-section.component.spec.ts:33`
- `src/app/settings/account/email-section.component.spec.ts:56`
- `src/app/core/preferences/digest.service.spec.ts:38`
- `src/app/core/auth/admin.guard.spec.ts:30`
- `src/app/core/preferences/preferences.service.spec.ts:76`
- `src/app/core/auth/auth.service.spec.ts:66` and `:100`
- `src/app/reader/shell/reader-shell.component.spec.ts:2025` (`apply({ ready: true, model: 'gpt', capabilities: null })`)

Survey after: `grep -rn "ai: { ready" frontend/src | grep -v capabilities` → expect no output. The `as CurrentUser` casts in `auth.interceptor.spec.ts:152` and `auth.service.spec.ts:185` compile unchanged.

In `frontend/e2e/ai-config-rejected.spec.ts`, `EXISTING` gains

```ts
  capabilities: {
    reasons: true,
    tuningFields: [
      'contextWindow',
      'batchSize',
      'suppressReasoning',
      'slowModel',
      'maxBatchSize',
      'batchConcurrency',
    ],
  },
```
(the row template reads `config.capabilities.tuningFields`; a stub without it would throw in the template).

- [ ] **Step 8: Run the gate** (alone in the container)

```bash
docker compose exec -T frontend npm run check; echo "EXIT=$?"
```
Expected: `EXIT=0`, and a `Test Suites:` line with no failures. If memory is tight, run the steps of `check` by hand with `npm test -- --maxWorkers=2`.

- [ ] **Step 9: Deletion checks** (quote each FAIL; copy each file aside first)

1. `ai-availability.service.ts` `set()`: drop the `capabilitiesSignal.set(…)` line → "adopts the active connection's capabilities" FAILS.
2. `ai-settings.service.ts`: `capabilities: null` in `applyAvailability()` → the service's capabilities test FAILS.
3. Card template: remove the `@if (offersReasons())` wrapper (keep the row) → "offers none of them…" FAILS on the switch.
4. Card template: wrap the batch-size field in `offersTuning('contextWindow')` instead → "offers each tuning control by its own field" FAILS.
5. Section template: wrap the suppress-reasoning span in `offersTuning(config, 'slowModel')` → "offers each row control by its own field" FAILS (two checkboxes).
6. Section template: drop the outer `@if` around `.reasoning-toggle` → "offers a row no tuning control…" FAILS (an empty `.reasoning-toggle` exists).

- [ ] **Step 10: Commit**

```bash
git -C .. add frontend
git commit -m "feat(#1344): render recommendation settings from the engine's capabilities"
```

Reviewer: yes; compares the rendered markup for the full capability set against `develop` (nothing visible changes for the LLM).

---

### Task 6: Docs, full gates, a real run, the PR

**Files:**
- Modify: `docs/recommendations-runs.md` (new "Engines" subsection)

**Interfaces:** none.

- [ ] **Step 1: Document the seam**

In `docs/recommendations-runs.md`, directly under `## For developers`, insert:

```markdown
### Engines

A run does not know which engine scores it. `RecommendationEngineResolver` is the one place that maps a connection to
an engine (today every connection is an LLM connection); `SnapshotPhase` asks that engine to pack the candidate pool
into batches, and `TickPhases` hands it every later tick of a running run. The lock, the deferral after a rate limit,
the transport-failure strikes, cancelling and finalising stay with the run and are the same for every engine.

The LLM engine (`Service/Ai/Llm`) packs by the connection's context window, then distills a profile, scores the
batches in waves and consolidates the best of them into the final list with reasons. Each engine reports its
capabilities (`reasons`, and which tuning fields it reads); the API passes them to the client, which shows only the
settings that apply.
```

- [ ] **Step 2: Backend gates**

```bash
bin/console cache:warmup
composer check
composer md
docker compose exec php sh -c 'rm -rf var/cache/test*'      # MySQL prep; wait for it to finish
composer test:parallel & docker compose exec php composer test; wait
composer infection:diff
```
Expected: all green; phptramp shows the D15 warning and no failure. `infection:diff` at or above `minMsi`. Escaped mutants on lines the move only renamed are pre-existing: prove each one on `origin/develop` before calling it so, and report it.

Run PhpStorm `lint_files` on every `src` file this branch modified (not the pure moves): block on ERROR/WARNING.

Repo-wide stale names once more (Task 3 Step 6, both greps): no output.

`git diff --stat origin/develop -- backend/tests/Service/Recommendation/Run/RecommendationPipelineTest.php` → no output.

- [ ] **Step 3: Frontend gate**

`docker compose exec -T frontend npm run check; echo "EXIT=$?"` → `EXIT=0`.

- [ ] **Step 4: The stack serves this branch**

```bash
docker compose exec php bin/console cache:clear
docker compose restart worker
docker compose ps                     # worker healthy
docker compose up -d frontend         # then confirm :4200 serves the new chunk (hard reload)
```

- [ ] **Step 5: A real recommendation run with the LLM** (costs a real provider call; Lars asked for it in the issue)

1. Find the account with an active LLM connection (read only):
   `docker compose exec php bin/console dbal:run-sql "SELECT u.id, u.email, s.base_url, s.model FROM app_user u JOIN user_ai_settings s ON s.id = u.active_ai_config_id"`
   **Assumption (verify):** column names `base_url` and `model` (Doctrine's underscore naming); check with `DESCRIBE user_ai_settings` if the query fails.
2. Mint a token and start the run:
   `TOKEN=$(docker compose exec -T php bin/console lexik:jwt:generate-token <email> | tail -1)` then
   `curl -sk -X POST https://localhost:8443/api/recommendations/runs -H "Authorization: Bearer $TOKEN"`.
   If the token command does not exist or prints something else, start the run from the UI instead: "For you" → Refresh, on `http://localhost:4200`.
3. Poll until the run leaves `pending`/`running` (one request a minute, not a loop in a background agent):
   `curl -sk https://localhost:8443/api/recommendations/runs/current -H "Authorization: Bearer $TOKEN"`.
4. Verify (read only): `SELECT id, status, error FROM recommendation_run ORDER BY id DESC LIMIT 1` → `completed`, no error; `SELECT COUNT(*) AS items, SUM(CASE WHEN reason <> '' THEN 1 ELSE 0 END) AS with_reason FROM recommendation_item WHERE recommendation_run_id = <id>` → `items > 0` and `with_reason = items`; read that run's `recommendation_run_log` rows (phases distill/batch/consolidate, no transport failures).
5. In the browser at `http://localhost:4200/settings/ai`: the provider row, the "show reasons" switch, batch size and context window are all there, as on `develop`.
6. Scan the dev log: `ls -t var/log/dev-*.log | head -1 | xargs tail -n 400 | jq -c 'select(.level >= 300)'` → no new warning or error from this run (a deprecation is a finding).

Report the run id, item count, reason count and the log verdict.

- [ ] **Step 6: Commit the docs and open the PR**

```bash
git add ../docs/recommendations-runs.md
git commit -m "docs(#1344): document the recommendation engine seam"
git push -u origin refactor/1344-recommendation-engine-seam
gh pr create --base develop --title "refactor(#1344): recommendation engine seam" --body "$(cat <<'EOF'
Closes #1344

Recommendations become engine-neutral; every LLM-specific class moves to the new module `Service/Ai/Llm`.

- `Ai\Llm` is a module of its own (`ServiceModules::SUB_MODULES`, #1161 amendment); `Ai\Llm → Recommendation → Ai`.
- `Recommendation/Engine`: `RecommendationEngineInterface`, `RecommendationEngineResolver` (the one place that picks the engine, keyed locator), kind, capabilities, tuning fields. `TickPhases` and `SnapshotPhase` reach the engine only through it.
- Moved: the chat-completion transport, the OpenAI catalog, prompts and parsers, the distill/batch/consolidate phases and the call recorder into `Ai/Llm`; the candidate and history loaders into `Recommendation/Pool` (`PromptLineModel` → `ArticleLineModel`); the heartbeat into `Recommendation/Run/ProviderCallHeartbeat`; `RetryPlanModel` into `Ai/Model`.
- `/api/me` (`ai.capabilities`) and `/api/me/ai` (per configuration) report `{reasons, tuningFields}`; the settings render from them. Nothing visible changes for the LLM.
- Deferred to #1345: the composite model catalog (one catalog exists today).

Gates: composer check / md / tramp (one expected 3-hop warning, see plan D15), both test legs, infection:diff, npm run check. Real run on the dev stack: <run id, items, reasons>.

Plan: docs/superpowers/plans/2026-10-02-1344-recommendation-engine-seam.md
EOF
)"
```

Do not merge: Lars merges. After the merge, verify #1344 closed on its own.

---

## Self-review notes (planner)

- Spec coverage: every row of the Scope table points at a task; the composite catalog is explicitly deferred.
- Types are consistent across tasks: `RecommendationEngineCapabilitiesModel::$writesReasons` / `$tuningFields`; wire `reasons` / `tuningFields`; `ScriptedRecommendationEngine::{packing,failingWith,reporting,resolver}`; `offersTuning` in both components.
- Values chosen to fail under a named change: `[[id2],[id0,id1]]` (a pool-order packer would give one batch of 3), `7/7/3` from a ceiling of 7 over 17 candidates, `SlowModel, ContextWindow` (reversed from declaration order, reasons `false` against an LLM `true`), `90 s` → `10:06:30`, the 10:10 deferral checked at 10:05 and 10:11.
