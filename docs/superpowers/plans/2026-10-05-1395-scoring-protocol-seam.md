# Scoring Protocol Seam Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Turn the Jev engine into a neutral `Scoring` engine with a protocol seam beneath it (`ScoringProtocolInterface`, System One its only implementation), store each connection's engine kind and protocol instead of parsing the model id, and record the protocol on every run, with no change in what System One is sent or how a run behaves.

**Architecture:** `Service/Recommendation/Jev` becomes the sub-module `Service/Recommendation/Scoring`. The engine side (`ScoringRecommendationEngine`, `ScoringBatchWave`, `ScoringBatchPacker`, `ScoreParser`, the state factory) knows only a neutral `ScoringRequestModel` and `ScoringReplyModel`; `ScoringProtocol/SystemOneProtocol` words a request as today's System One body, byte for byte, and is found by `App\Enum\ScoringProtocol` value through a keyed locator (`ScoringProtocolResolver`). `HttpSystemOneClient`'s generic part becomes `ScoringHttpTransport`. `AiProviderSettings` stores the kind and protocol the catalog tagged the chosen model with (embeddable `ChosenModel`), `RecommendationRun` records them at snapshot (embeddable `RunEngine`), and `RecommendationEngineResolver` reads the stored kind.

**Tech Stack:** PHP 8.4, Symfony 7.4 (`AutoconfigureTag`, `AsTaggedItem`, `AutowireLocator`), Doctrine ORM 3 + Migrations (MySQL and SQLite), PHPStan level max with the custom rules in `backend/tests/PhpStan/`, PHPMD codesize, phptramp, PHPUnit 12, Infection; Angular 20 + Transloco (two i18n strings only).

**Spec:** `docs/superpowers/specs/2026-10-05-scoring-model-providers-design.md` (issue #1394), "Delivery" item 1 — this PR is sub-issue #1395. Read the spec's D1–D10 first; this plan implements PR 1 of it and nothing of PR 2 or PR 3. Context: `docs/superpowers/plans/2026-10-02-1344-recommendation-engine-seam.md` and `docs/superpowers/plans/2026-10-02-1345-jev-recommendation-engine.md` (the move tooling and the decisions this code was built on).

## Status

| Task | Title | Status | Commit |
|---|---|---|---|
| 0 | Preflight, baselines and the move tooling | ☐ | — |
| 1 | The scoring kind and protocol are values; the catalog tags System One | ☐ | — |
| 2 | Connections and runs store the kind and the protocol | ☐ | — |
| 3 | The resolver reads the stored kind; the model list is labelled by the catalog's tag | ☐ | — |
| 4 | The engine-switch guard compares the protocol too | ☐ | — |
| 5 | The scripted move `Jev` → `Scoring` | ☐ | — |
| 6 | A scoring reply is a score per entry | ☐ | — |
| 7 | The protocol seam | ☐ | — |
| 8 | `ScoringHttpTransport` | ☐ | — |
| 9 | Docs, gates, a real run, the PR | ☐ | — |

## Global Constraints

- **No behaviour change.** System One receives today's request byte for byte (same `model`, `state` `{profile, guidance?, favorites?}`, questions, compact JSON), with today's budget: 32,000-token window (`SystemOneCatalog::CONTEXT_WINDOW_TOKENS`), at most 100 questions, 10,000 tokens of state, 2,000 of framing. Every existing Jev assertion stays green under the new names; where a task changes an assertion's form (a renamed key, a new constructor), the task names it and why.
- The only visible changes: the model list labels a System One model `"System One"` (was `"Jev"`), the guide's step 3 names that label (en, de), and the "no profile" run error names no model.
- Spec rules that hold here: one `Scoring` kind (`'scoring'`), `App\Enum\ScoringProtocol` with `SystemOne = 'system_one'` only (no `Rerank`, no `family()` in this PR); nothing parses a model id; the guard fires on kind **or** protocol; "needs a profile" is the kind's borrowed profile source.
- Clean Code per `CLAUDE.md`: `final readonly` services, intent-revealing names (no abbreviations, no single letters), ≤ 3 parameters, no boolean flags, guard clauses, role folders (`Model/`, `Factory/`, `Pass/`, `Support/`, a folder per interface), **default to no comment**, one-line comments.
- Persistence knows no service (`PersistenceKnowsNoServiceRule`, architecture §8): `App\Entity`/`App\Enum` never name `App\Service\…`.
- **PHPMD is at its limits:** `AiProviderSettings` and `RecommendationRun` hold 15 fields each (`TooManyFields` reports above 15) and `RecommendationRun` has 10 non-accessor public methods (`TooManyPublicMethods` reports above 10; `get/set/is/has/with` methods do not count). Neither may grow past them (D3, D5).
- phptramp: 4 forwarding hops across 2+ classes fail, 3 warn. Task 0 records the warning count; no task may add a failing chain.
- Gates (from `backend/`): `bin/console cache:warmup` then `composer check` (cs + stan + tramp), `composer md` (every touched `src` file PHPMD-clean), `php bin/phpunit` (SQLite) and, from the repository root, `docker compose exec php composer test` (MySQL), `composer infection:diff`; PhpStorm inspections (`mcp__phpstorm__lint_files`) on changed PHP block on ERROR and WARNING. Frontend: `docker compose exec -T frontend npm run check`, one Jest process at a time.
- Migrations need their own verification: migrate from empty on SQLite and on MySQL, then `doctrine:schema:validate`; then apply to the live Docker database with `docker compose exec php bin/console doctrine:migrations:migrate --no-interaction`. Never clear the dev database.
- **Deletion checks are binding.** Every new test names, in its step, the production change that turns it red. In each task's deletion-check step: copy the file aside (`cp <file> "$TMPDIR/<name>.orig"`), make the named break, run the named test, paste the FAIL output verbatim into the task report, restore with `mv "$TMPDIR/<name>.orig" <file>`, re-run and paste the OK. **Never** `git checkout -- <file>`.
- Commits: `type(#1395): lower-case summary`, no attribution lines. The PR body says `Closes #1395` and `Refs #1394`, and never a closing keyword next to `#1394`.
- Another Claude session may share this checkout: run `git status` and `git branch --show-current` before any switch, reset or stash. Work stays on `feature/1395-scoring-protocol-seam`.
- Implementers run every command in the foreground, one at a time: no background shells, no `sleep`/`until` poll loops, no `&`.
- Backend commands run from `backend/`; `docker compose …` from the repository root. Paths below are repository-relative unless a command runs in `backend/`.
- An implementer reports plan defects to the planner, who amends this plan in-branch. Every **Assumption (verify):** is cheap to check: check it and report the outcome.

## Decisions (judgement calls the spec did not settle, with the reason)

- **D1 — The descriptor moves to `App\Entity\ModelDescriptor`.** The connection stores what the catalog said (spec D4), so `AiProviderSettings::chooseModel()` takes the descriptor; an entity may not name `App\Service\Ai\Model\ModelDescriptorModel` (§8), so the value moves down and loses the `Model` suffix (role suffixes belong to service folders). Spec D3's `ModelDescriptorModel` reads `ModelDescriptor` from here on.
- **D2 — `kind()` is derived, not stored on the descriptor.** `ModelDescriptor(id, contextWindow, ?scoringProtocol = null)` with `kind()`: `Scoring` exactly when a protocol is set. Spec D3's invariant ("`scoringProtocol` is null exactly when `kind` is `Llm`") holds by construction instead of by two fields that could disagree. The connection still stores both columns (spec D4).
- **D3 — Two embeddables, because both entities sit at PHPMD's 15-field limit.** `App\Entity\ChosenModel` (columns `model`, `model_context_window`, `model_kind`, `scoring_protocol`) replaces `AiProviderSettings::$model` and `$modelContextWindow`; `App\Entity\RunEngine` (`engine_kind`, `scoring_protocol`) replaces `RecommendationRun::$engineKind`. Column names and definitions of the existing columns are unchanged (`columnPrefix: false`); the one DQL path that changes is `RecommendationRunTimingRepository`'s `r.engineKind` → `r.engine.engineKind`.
- **D4 — One `chooseModel(ModelDescriptor $model, \DateTimeImmutable $verifiedAt)` for both kinds.** Two methods would need four parameters on the scoring one. The ~40 test call sites are rewritten by `rewrite-choose-model.php`, which gives a literal `'jev-…'` model the System One protocol exactly as the backfill migration does; PHPStan catches any site it misses.
- **D5 — The profile rule lives on the kind.** `RecommendationProfileSource` moves to `App\Enum` and `RecommendationEngineKind::profileSource()` is the one place that says `Scoring` borrows its profile; `RecommendationEngineCapabilitiesModel::of()` reads it, and the entity asks it in `RecommendationRun::isMissingBorrowedProfile()` (an `is…` name, so `TooManyPublicMethods` stays at 10). The entity cannot call `RecommendationEngineCapabilitiesModel::of()` (§8), which the brief suggested.
- **D6 — The enum value changes in Task 2, the case name in Task 1.** Task 1 renames `RecommendationEngineKind::Jev` to `Scoring` but keeps the value `'jev'`, so every task stays green against the live dev database; Task 2 changes the value to `'scoring'` together with the migration that rewrites the stored `'jev'`.
- **D7 — Two migrations, and the backfill is case-sensitive.** `Version20261005140000` adds the columns, `Version20261005140100` backfills; the split lets a test drive the backfill on the test database (precedent: `RecommendationRunTimingRepositoryTest`). The spec's `model LIKE 'jev-%'` would also catch `JEV-latest` on MySQL's case-insensitive collation; the resolver it replaces was case-sensitive (#1345 D13), so the backfill uses #1349's `CAST(LEFT(model, 4) AS BINARY) = 'jev-'` (MySQL) / `substr(model, 1, 4) = 'jev-'` (SQLite).
- **D8 — The label is `"System One"`.** `labelForModel` parsed the id; the label now comes from the descriptor's protocol (`AiSettingsJson`): `"System One"` for System One, `null` for an LLM. A proper name needs no translation, unlike "Scoring model". The guide's `jevStep3` (en, de) quotes the option text and is updated; the frontend spec fixture that uses `'Jev'` tests the join of id and label, not the server's word, and stays.
- **D9 — The budget is built by `Factory/ScoringBudgetFactory::create(ScoringProtocol)`**, which in this PR returns System One's fixed values (32,000 / 100 / 10,000 / 2,000). PR 2 adds the connection's stored window to its input. The item cap is per protocol (spec D5), so the protocol is its input already.
- **D10 — `ScoringRequestModel(model, reader, budget, articles)`.** The code contradicts the spec's "(model, profile, guidance, articles)": today's state also carries the newest favorites, so the neutral reader is `ScoringReaderModel(profile, guidance, favorites)`. The budget rides along because System One fits its state to the budget's `stateTokens` when it words the request.
- **D11 — The protocol interface has a third method, `renderedRequest()`.** The run log records the request before it is sent (`ScoringBatchWave::open()`), so the protocol must word it there; spec D7 lists only `pack()` and `scoreMany()`.
- **D12 — System One keeps its client seam.** `SystemOneProtocol` calls `SystemOneClientInterface` (`HttpSystemOneClient` on `ScoringHttpTransport`), and the test container keeps `StubSystemOneClient` there, so `ScoringPipelineTest` and `ScoringRecommendationEngineTest` keep every assertion on the System One request. The spec's "engine tests against a stub protocol" waits for PR 2/3, where a second protocol makes it worth it.
- **D13 — A reply's scores are keyed by entry id.** `ScoringReplyModel::$scores` is `array<int, float>`; System One's decoder keeps only the ids `QuestionId::of()` writes (`QuestionId::entryIdOf()` round-trips them, so `entry-007` names nothing). The decoder and client tests' expected keys change from `'entry-7'` to `7`: the same fact in the new key space.
- **D14 — `ScoringEndpoint` is a `Pass/`, not a model.** It carries the protocol's reply decoder as a closure; the role rules count a `\Closure` as a collaborator, which a model may not take.
- **D15 — The transport reads the credentials itself** (URL and headers in `sendAll()`), so the credentials travel `ScoringBatchWave → SystemOneProtocol::scoreMany → HttpSystemOneClient::evaluateMany → ScoringHttpTransport::sendAll` with two forwarders, below phptramp's warning.
- **D16 — The protocol is read from the connection.** `TickContext::scoringProtocol()` and `requireScoringProtocol()` read `$connection->getScoringProtocol()`; `TickContext`'s constructor keeps its five parameters. The kind stays the resolver's decision (it defaults); the protocol is stored data with no default.
- **D17 — The guard's protocol arm is proven with `null` against `SystemOne`.** With one protocol in this PR, a run recorded without a protocol on a scoring connection stands in for a run of another protocol.
- **D18 — `NO_PROFILE`:** "A scoring model needs your reading profile, and there is no reading history to build one from yet. Read, keep or favourite a few articles, then start a new run."
- **D19 — Deferred, YAGNI:** `ScoringProtocol::family()`, the `kind`/`family` wire keys and the per-protocol caps (PR 2), `ScoringProtocol::Rerank` (PR 3). `ScoringBatchPacker` takes the item measure as a closure now, because its old dependency on `SystemOneRequestFactory` would tie the engine side to System One.
- **D20 — Test names.** Tests named for the Jev *kind* outside the moved suite are renamed to Scoring (Task 1, Task 3); tests inside the moved suite that exercise Jev the *model* keep the name.
- **D21 — One real Jev run on the dev stack (Task 9)**, although the spec asks for real runs only after PR 2 and PR 3: it proves the backfill on real rows and the unchanged wire for a few tenths of a cent.

## File map

Created (src): `Entity/ModelDescriptor.php` (moved from `Service/Ai/Model/ModelDescriptorModel.php`), `Entity/ChosenModel.php`, `Entity/RunEngine.php`, `Enum/ScoringProtocol.php`, `Enum/RecommendationProfileSource.php` (moved), `Service/Recommendation/Scoring/ScoringProtocol/{ScoringProtocolInterface,SystemOneProtocol}.php`, `Service/Recommendation/Scoring/ScoringProtocolResolver.php`, `Service/Recommendation/Scoring/Model/{ScoringBudgetModel,ScoringReaderModel,ScoringRequestModel}.php`, `Service/Recommendation/Scoring/Factory/ScoringBudgetFactory.php`, `Service/Recommendation/Scoring/ScoringHttpTransport.php`, `Service/Recommendation/Scoring/Pass/ScoringEndpoint.php`; `migrations/Version20261005140000.php`, `migrations/Version20261005140100.php`.

Moved (Task 5, 20 src + 12 test classes): everything under `Service/Recommendation/Jev` and `tests/Service/Recommendation/Jev` to `…/Scoring` (map in Task 0).

Modified (src): `Enum/RecommendationEngineKind.php`, `Entity/AiProviderSettings.php`, `Entity/RecommendationRun.php`, `Repository/RecommendationRunTimingRepository.php`, `Service/Ai/AiProviderConfigurator.php`, `Service/Ai/Model/AddedConfigurationModel.php`, `Service/Ai/ModelCatalog/SystemOneCatalog.php`, `Service/Recommendation/Engine/RecommendationEngineResolver.php`, `Service/Recommendation/Engine/Model/RecommendationEngineCapabilitiesModel.php`, `Service/Recommendation/Run/{SnapshotPhase,TickPhases}.php`, `Service/Recommendation/Run/Pass/TickContext.php`, `Http/{AiSettingsJson,RecommendationCapabilitiesJson}.php`, `Controller/Api/AiSettingsController.php`, and every moved Scoring file; config `backend/phpstan.dist.neon`, `backend/infection.json5` (comment), `backend/config/services{,_test}.yaml` (by the move script); `frontend/public/i18n/{en,de}.json` (one string each); docs `docs/architecture.md` §9, `docs/recommendations-runs.md`.

Tests created: `tests/Entity/ModelDescriptorTest.php`, `tests/Migrations/Version20261005140100Test.php`, `tests/Service/Recommendation/Scoring/{ScoringBatchPackerTest,ScoringProtocolResolverTest,ScoringProtocolWiringTest,ScoringHttpTransportTest}.php`, `…/Scoring/ScoringProtocol/SystemOneProtocolTest.php`, `…/Scoring/Model/ScoringBudgetModelTest.php`, `…/Scoring/Factory/ScoringBudgetFactoryTest.php`, `…/Scoring/Support/QuestionIdTest.php`. Tooling (Task 0): `docs/superpowers/plans/2026-10-05-1395-scripts/`.

---

### Task 0: Preflight, baselines and the move tooling

**Files:**
- Create: `docs/superpowers/plans/2026-10-05-1395-scripts/{class-names,move-classes,compare-moves,stale-names,psr4-namespaces}.php` (copied), `…/moves-values.php`, `…/moves.php`, `…/compare-import-lines.php`, `…/rewrite-choose-model.php`

**Interfaces:**
- Consumes: nothing.
- Produces: the scripts every later task runs, from `backend/`: `move-classes.php <map>`, `compare-moves.php <map> HEAD`, `compare-import-lines.php <map> HEAD`, `psr4-namespaces.php`, `stale-names.php <map>`, `rewrite-choose-model.php`.

- [ ] **Step 1: Check the checkout** (concurrent sessions share it)

```bash
git status --short
git branch --show-current
git log --oneline -2
```
Expected: clean tree, branch `feature/1395-scoring-protocol-seam`, the top commit `docs(#1395): implementation plan for the scoring protocol seam` above `docs(#1394): design for scoring models from more providers`. Anything else: stop and ask.

- [ ] **Step 2: The Docker stack serves this tree** (standing rule)

From the repository root:

```bash
docker compose ps
docker compose exec php printenv APP_CACHE_DIR
docker compose exec php bin/console cache:clear
docker compose restart worker
docker compose exec php bin/console doctrine:migrations:status | grep -iE 'new|executed'
```
Expected: `php`, `worker`, `nginx`, `frontend`, `mysql` up (worker healthy); `APP_CACHE_DIR` is `/app/var/cache-docker`; no new migrations pending. If `APP_CACHE_DIR` is empty: `docker compose up -d php worker && docker compose restart nginx`.

- [ ] **Step 3: Baselines** (record every number in the report; later tasks compare against them)

From `backend/`:

```bash
bin/console cache:warmup
composer check
composer md
php bin/phpunit
```
And from the repository root, alone (no other Jest process): `docker compose exec -T frontend npm run check; echo "EXIT=$?"`.

Expected: all green; record phptramp's warning count, the PHPUnit test count and the Jest "Test Suites:" line. A red baseline stops the task: prove it pre-exists on `origin/develop` before anything builds on it.

- [ ] **Step 4: Copy the move tooling**

From `backend/`:

```bash
mkdir -p ../docs/superpowers/plans/2026-10-05-1395-scripts
cp ../docs/superpowers/plans/2026-10-02-1344-scripts/{class-names,move-classes,compare-moves,stale-names,psr4-namespaces}.php \
   ../docs/superpowers/plans/2026-10-05-1395-scripts/
```

- [ ] **Step 5: Write `moves-values.php`** (Task 1's map)

```php
<?php

declare(strict_types=1);

// #1395 Task 1: old FQCN => new FQCN, for move-classes.php, compare-moves.php and compare-import-lines.php.
// Both classes move down because an entity takes or asks them (docs/architecture.md §8).

return [
    'App\Service\Ai\Model\ModelDescriptorModel' => 'App\Entity\ModelDescriptor',
    'App\Service\Recommendation\Engine\Model\RecommendationProfileSource' => 'App\Enum\RecommendationProfileSource',
];
```

- [ ] **Step 6: Write `moves.php`** (Task 5's map)

```php
<?php

declare(strict_types=1);

// #1395 Task 5: old FQCN => new FQCN, for move-classes.php, compare-moves.php, compare-import-lines.php and
// stale-names.php (run from backend/). Spec D6's neutral names for the engine side; System One keeps its own.

return [
    'App\Service\Recommendation\Jev\Factory\JevStateFactory'
        => 'App\Service\Recommendation\Scoring\Factory\ScoringStateFactory',
    'App\Service\Recommendation\Jev\Factory\SystemOneRequestFactory'
        => 'App\Service\Recommendation\Scoring\Factory\SystemOneRequestFactory',
    'App\Service\Recommendation\Jev\JevBatchPacker'
        => 'App\Service\Recommendation\Scoring\ScoringBatchPacker',
    'App\Service\Recommendation\Jev\JevBatchWave'
        => 'App\Service\Recommendation\Scoring\ScoringBatchWave',
    'App\Service\Recommendation\Jev\JevRecommendationEngine'
        => 'App\Service\Recommendation\Scoring\ScoringRecommendationEngine',
    'App\Service\Recommendation\Jev\Model\NoulParseResultModel'
        => 'App\Service\Recommendation\Scoring\Model\ScoreParseResultModel',
    'App\Service\Recommendation\Jev\Model\SystemOneOutcomeModel'
        => 'App\Service\Recommendation\Scoring\Model\ScoringOutcomeModel',
    'App\Service\Recommendation\Jev\Model\SystemOneReplyModel'
        => 'App\Service\Recommendation\Scoring\Model\ScoringReplyModel',
    'App\Service\Recommendation\Jev\Model\SystemOneRequestModel'
        => 'App\Service\Recommendation\Scoring\Model\SystemOneRequestModel',
    'App\Service\Recommendation\Jev\NoulReplyParser'
        => 'App\Service\Recommendation\Scoring\ScoreParser',
    'App\Service\Recommendation\Jev\Pass\JevWave'
        => 'App\Service\Recommendation\Scoring\Pass\ScoringWave',
    'App\Service\Recommendation\Jev\Pass\SystemOneWave'
        => 'App\Service\Recommendation\Scoring\Pass\ResponseWave',
    'App\Service\Recommendation\Jev\Support\FittingPrefix'
        => 'App\Service\Recommendation\Scoring\Support\FittingPrefix',
    'App\Service\Recommendation\Jev\Support\JevArticle'
        => 'App\Service\Recommendation\Scoring\Support\ScoringArticle',
    'App\Service\Recommendation\Jev\Support\NoulScore'
        => 'App\Service\Recommendation\Scoring\Support\ProbabilityScore',
    'App\Service\Recommendation\Jev\Support\QuestionId'
        => 'App\Service\Recommendation\Scoring\Support\QuestionId',
    'App\Service\Recommendation\Jev\Support\SystemOneJson'
        => 'App\Service\Recommendation\Scoring\Support\CompactJson',
    'App\Service\Recommendation\Jev\Support\SystemOneReplyDecoder'
        => 'App\Service\Recommendation\Scoring\Support\SystemOneReplyDecoder',
    'App\Service\Recommendation\Jev\SystemOneClient\HttpSystemOneClient'
        => 'App\Service\Recommendation\Scoring\SystemOneClient\HttpSystemOneClient',
    'App\Service\Recommendation\Jev\SystemOneClient\SystemOneClientInterface'
        => 'App\Service\Recommendation\Scoring\SystemOneClient\SystemOneClientInterface',
    'App\Tests\Service\Recommendation\Jev\Factory\JevStateFactoryTest'
        => 'App\Tests\Service\Recommendation\Scoring\Factory\ScoringStateFactoryTest',
    'App\Tests\Service\Recommendation\Jev\Factory\SystemOneRequestFactoryTest'
        => 'App\Tests\Service\Recommendation\Scoring\Factory\SystemOneRequestFactoryTest',
    'App\Tests\Service\Recommendation\Jev\JevBatchPackerTest'
        => 'App\Tests\Service\Recommendation\Scoring\ScoringBatchPackerTest',
    'App\Tests\Service\Recommendation\Jev\JevPipelineTest'
        => 'App\Tests\Service\Recommendation\Scoring\ScoringPipelineTest',
    'App\Tests\Service\Recommendation\Jev\JevRecommendationEngineTest'
        => 'App\Tests\Service\Recommendation\Scoring\ScoringRecommendationEngineTest',
    'App\Tests\Service\Recommendation\Jev\Model\SystemOneRequestModelTest'
        => 'App\Tests\Service\Recommendation\Scoring\Model\SystemOneRequestModelTest',
    'App\Tests\Service\Recommendation\Jev\NoulReplyParserTest'
        => 'App\Tests\Service\Recommendation\Scoring\ScoreParserTest',
    'App\Tests\Service\Recommendation\Jev\Pass\SystemOneWaveTest'
        => 'App\Tests\Service\Recommendation\Scoring\Pass\ResponseWaveTest',
    'App\Tests\Service\Recommendation\Jev\Support\FittingPrefixTest'
        => 'App\Tests\Service\Recommendation\Scoring\Support\FittingPrefixTest',
    'App\Tests\Service\Recommendation\Jev\Support\NoulScoreTest'
        => 'App\Tests\Service\Recommendation\Scoring\Support\ProbabilityScoreTest',
    'App\Tests\Service\Recommendation\Jev\Support\SystemOneReplyDecoderTest'
        => 'App\Tests\Service\Recommendation\Scoring\Support\SystemOneReplyDecoderTest',
    'App\Tests\Service\Recommendation\Jev\SystemOneClient\HttpSystemOneClientTest'
        => 'App\Tests\Service\Recommendation\Scoring\SystemOneClient\HttpSystemOneClientTest',
];
```

Check the map against the tree (from `backend/`):

```bash
php -r '$m = require "../docs/superpowers/plans/2026-10-05-1395-scripts/moves.php"; echo count($m), "\n";'
php -r 'require "../docs/superpowers/plans/2026-10-05-1395-scripts/class-names.php"; foreach (array_keys(require "../docs/superpowers/plans/2026-10-05-1395-scripts/moves.php") as $class) { if (!is_file(pathOf($class))) echo "missing $class\n"; }'
find src/Service/Recommendation/Jev tests/Service/Recommendation/Jev -name '*.php' | wc -l
```
Expected: `32`, no "missing" line, `32`.

- [ ] **Step 7: Write `compare-import-lines.php`** (the check `compare-moves.php` does not make)

```php
<?php

declare(strict_types=1);

// php ../docs/superpowers/plans/2026-10-05-1395-scripts/compare-import-lines.php <map>.php [<base ref>]   (from backend/)
// The two lines compare-moves.php skips: every PHP file's namespace line and its class imports, against the base ref
// with the map applied. A move once corrupted namespace lines behind a clean comparison (#1202).

require __DIR__ . '/class-names.php';

/** @return array{namespace: string, imports: list<string>} */
function headerOf(string $code): array
{
    $namespace = 1 === preg_match('/^namespace ([^;{]+);$/m', $code, $match) ? $match[1] : '';
    preg_match_all('/^use (?!function |const )([\w\\\\]+)(?: as \w+)?;$/m', $code, $imports);
    $sorted = $imports[1];
    sort($sorted);

    return ['namespace' => $namespace, 'imports' => $sorted];
}

/** @var array<string, string> $moves */
$moves = require $argv[1];
$base = $argv[2] ?? 'HEAD';
$oldPathOf = [];
foreach ($moves as $old => $new) {
    $oldPathOf[pathOf($new)] = pathOf($old);
}

$compared = 0;
$wrongNamespaces = 0;
$changedImports = 0;
foreach (['src', 'tests'] as $root) {
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
    foreach ($files as $file) {
        $path = $file->getPathname();
        if (!str_ends_with($path, '.php') || str_starts_with($path, 'tests/PhpStan/data/')) {
            continue;
        }
        $oldPath = $oldPathOf[$path] ?? $path;
        $before = shell_exec(sprintf('git show %s 2>/dev/null', escapeshellarg($base . ':backend/' . $oldPath)));
        if (!is_string($before) || '' === $before) {
            continue;
        }
        ++$compared;
        $old = headerOf($before);
        $new = headerOf((string) file_get_contents($path));
        $oldClass = $old['namespace'] . '\\' . basename($oldPath, '.php');
        $expectedNamespace = namespaceOf($moves[$oldClass] ?? $oldClass);
        if ($new['namespace'] !== $expectedNamespace) {
            ++$wrongNamespaces;
            printf("%s declares namespace %s, expected %s\n", $path, $new['namespace'], $expectedNamespace);
        }
        $expectedImports = array_map(static fn (string $class): string => $moves[$class] ?? $class, $old['imports']);
        sort($expectedImports);
        if ($new['imports'] !== $expectedImports) {
            ++$changedImports;
            printf(
                "%s imports differ: missing [%s], unexpected [%s]\n",
                $path,
                implode(', ', array_diff($expectedImports, $new['imports'])),
                implode(', ', array_diff($new['imports'], $expectedImports)),
            );
        }
    }
}
printf("%d files compared.\n", $compared);
printf("%d files declare an unexpected namespace.\n", $wrongNamespaces);
printf("%d files import other classes than the map says.\n", $changedImports);
```

- [ ] **Step 8: Write `rewrite-choose-model.php`** (Task 2's call-site rewrite)

```php
<?php

declare(strict_types=1);

// php ../docs/superpowers/plans/2026-10-05-1395-scripts/rewrite-choose-model.php   (from backend/)
// AiProviderSettings::chooseModel($model, $verifiedAt, $contextWindow) becomes
// chooseModel(new ModelDescriptor($model, $contextWindow), $verifiedAt) in tests/. A literal 'jev-…' model gets
// ScoringProtocol::SystemOne, as Version20261005140100's backfill does. Prints each file and every call left over.

const CALL = '/(?<!\$this)->chooseModel\(([^,()]+), (new \\\\DateTimeImmutable\([^()]*\)|\$\w+), ([^,()]+)\)/';

function descriptorOf(string $model, string $contextWindow): string
{
    $protocol = str_starts_with($model, "'jev-") ? ', ScoringProtocol::SystemOne' : '';

    return sprintf('new ModelDescriptor(%s, %s%s)', $model, $contextWindow, $protocol);
}

function importSortKey(string $line): string
{
    return strtolower(str_replace('\\', ' ', rtrim($line, ';')));
}

function withImport(string $code, string $class): string
{
    if (str_contains($code, "\nuse {$class};\n")) {
        return $code;
    }

    return (string) preg_replace_callback(
        '/(?:^use [^;\n]+;\n)+/m',
        static function (array $block) use ($class): string {
            $lines = [...explode("\n", rtrim($block[0], "\n")), "use {$class};"];
            usort($lines, static fn (string $left, string $right): int => strcmp(importSortKey($left), importSortKey($right)));

            return implode("\n", $lines) . "\n";
        },
        $code,
        1,
    );
}

$rewritten = 0;
$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator('tests', FilesystemIterator::SKIP_DOTS));
foreach ($files as $file) {
    $path = $file->getPathname();
    if (!str_ends_with($path, '.php') || str_starts_with($path, 'tests/PhpStan/')) {
        continue;
    }
    $code = (string) file_get_contents($path);
    $scoring = false;
    $changed = (string) preg_replace_callback(
        CALL,
        static function (array $call) use (&$scoring): string {
            $scoring = $scoring || str_starts_with($call[1], "'jev-");

            return sprintf('->chooseModel(%s, %s)', descriptorOf($call[1], $call[3]), $call[2]);
        },
        $code,
    );
    if ($changed === $code) {
        continue;
    }
    $changed = withImport($changed, 'App\Entity\ModelDescriptor');
    if ($scoring) {
        $changed = withImport($changed, 'App\Enum\ScoringProtocol');
    }
    file_put_contents($path, $changed);
    ++$rewritten;
    echo $path, "\n";
}
printf("Rewrote chooseModel() in %d files.\n", $rewritten);
echo "Calls left over:\n";
passthru("git grep -n -e '->chooseModel(' -- tests | grep -v -e 'new ModelDescriptor' -e '\$this->chooseModel(' -e 'configurator->chooseModel('");
```

- [ ] **Step 9: Commit the tooling**

```bash
git add ../docs/superpowers/plans/2026-10-05-1395-scripts
git commit -m "docs(#1395): move tooling for the scoring protocol seam"
```

---

### Task 1: The scoring kind and protocol are values; the catalog tags System One

**Files:**
- Move (script): `backend/src/Service/Ai/Model/ModelDescriptorModel.php` → `backend/src/Entity/ModelDescriptor.php`; `backend/src/Service/Recommendation/Engine/Model/RecommendationProfileSource.php` → `backend/src/Enum/RecommendationProfileSource.php`
- Create: `backend/src/Enum/ScoringProtocol.php`, `backend/tests/Entity/ModelDescriptorTest.php`
- Modify: `backend/src/Enum/RecommendationEngineKind.php`, `backend/src/Service/Recommendation/Engine/Model/RecommendationEngineCapabilitiesModel.php`, `backend/src/Service/Ai/ModelCatalog/SystemOneCatalog.php`, every file naming `RecommendationEngineKind::Jev` (sed), `backend/tests/Enum/RecommendationEngineKindTest.php`, `backend/tests/Service/Ai/ModelCatalog/SystemOneCatalogTest.php`, and the kind-named tests listed in Step 6

**Interfaces:**
- Consumes: Task 0's scripts.
- Produces:
  - `App\Enum\RecommendationEngineKind::Scoring` (value still `'jev'` until Task 2) and `RecommendationEngineKind::profileSource(): RecommendationProfileSource` (`Llm` → `Own`, `Scoring` → `Borrowed`).
  - `App\Enum\RecommendationProfileSource` (`Own = 'own'`, `Borrowed = 'borrowed'`).
  - `App\Enum\ScoringProtocol` with `SystemOne = 'system_one'`.
  - `App\Entity\ModelDescriptor(string $id, ?int $contextWindow, ?ScoringProtocol $scoringProtocol = null)` with `kind(): RecommendationEngineKind`.
  - `SystemOneCatalog::listModels()` returns `new ModelDescriptor('jev-latest', 32_000, ScoringProtocol::SystemOne)`.

- [ ] **Step 1: Move the two values** (from `backend/`, clean tree)

```bash
php ../docs/superpowers/plans/2026-10-05-1395-scripts/move-classes.php ../docs/superpowers/plans/2026-10-05-1395-scripts/moves-values.php
php ../docs/superpowers/plans/2026-10-05-1395-scripts/compare-moves.php ../docs/superpowers/plans/2026-10-05-1395-scripts/moves-values.php HEAD
php ../docs/superpowers/plans/2026-10-05-1395-scripts/compare-import-lines.php ../docs/superpowers/plans/2026-10-05-1395-scripts/moves-values.php HEAD
php ../docs/superpowers/plans/2026-10-05-1395-scripts/psr4-namespaces.php
```
Expected: `Moved 2 classes (1 renamed); …`; `0 of 2 moved files differ in code.` and `0 of 2 moved files declare the wrong namespace.`; `0 files declare an unexpected namespace.` and exactly one import difference, `src/Service/Recommendation/Engine/Model/RecommendationEngineCapabilitiesModel.php imports differ: missing [], unexpected [App\Enum\RecommendationProfileSource]` (it named the enum bare from its own folder; the script imported it once it left); the PSR-4 sweep at Task 0's baseline. Any other line: stop and report.

- [ ] **Step 2: Write the failing tests**

`backend/tests/Entity/ModelDescriptorTest.php` (red while `ModelDescriptor` has no protocol or no `kind()`; red later if `kind()` ignores the protocol):

```php
<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\ModelDescriptor;
use App\Enum\RecommendationEngineKind;
use App\Enum\ScoringProtocol;
use PHPUnit\Framework\TestCase;

final class ModelDescriptorTest extends TestCase
{
    public function testAModelThatSpeaksAScoringProtocolIsAScoringModel(): void
    {
        $model = new ModelDescriptor('acme/decider-2', 16_000, ScoringProtocol::SystemOne);

        self::assertSame(RecommendationEngineKind::Scoring, $model->kind());
    }

    /** The id is never read: a model named like Jev without a protocol is an LLM. */
    public function testAModelWithoutAProtocolIsAnLlm(): void
    {
        self::assertSame(RecommendationEngineKind::Llm, (new ModelDescriptor('jev-latest', 32_000))->kind());
    }
}
```

Add to `backend/tests/Enum/RecommendationEngineKindTest.php` (red while `profileSource()` is missing; red later if its arms are swapped). Add `use App\Enum\RecommendationProfileSource;` to its imports:

```php
    public function testAScoringModelBorrowsItsProfileAndAnLlmBuildsItsOwn(): void
    {
        self::assertSame(RecommendationProfileSource::Borrowed, RecommendationEngineKind::Scoring->profileSource());
        self::assertSame(RecommendationProfileSource::Own, RecommendationEngineKind::Llm->profileSource());
    }
```

In `backend/tests/Service/Ai/ModelCatalog/SystemOneCatalogTest.php` replace

```php
        self::assertEquals([new ModelDescriptor('jev-latest', 32_000)], $models);
```
with

```php
        self::assertEquals([new ModelDescriptor('jev-latest', 32_000, ScoringProtocol::SystemOne)], $models);
```
and add `use App\Enum\ScoringProtocol;` to its imports (red while the catalog tags no protocol).

- [ ] **Step 3: Run them to see them fail**

Run: `php bin/phpunit tests/Entity/ModelDescriptorTest.php tests/Enum/RecommendationEngineKindTest.php tests/Service/Ai/ModelCatalog/SystemOneCatalogTest.php`
Expected: errors — `Class "App\Enum\ScoringProtocol" not found` and `Undefined constant App\Enum\RecommendationEngineKind::Scoring` (or `Call to undefined method …profileSource()`).

- [ ] **Step 4: Rename the kind's case** (value unchanged until Task 2, D6)

```bash
git grep -l 'RecommendationEngineKind::Jev' -- src tests | xargs perl -pi -e 's/RecommendationEngineKind::Jev\b/RecommendationEngineKind::Scoring/g'
git grep -n 'RecommendationEngineKind::Jev\|self::Jev' -- src tests
```
Expected: the second command prints only the two lines of `src/Enum/RecommendationEngineKind.php`, which Step 5 replaces.

- [ ] **Step 5: Write the values**

`backend/src/Enum/ScoringProtocol.php`:

```php
<?php

declare(strict_types=1);

namespace App\Enum;

/** How a scoring model is asked; a connection and a run store it, and the value keys its protocol's implementation. */
enum ScoringProtocol: string
{
    case SystemOne = 'system_one';
}
```

`backend/src/Enum/RecommendationEngineKind.php` (whole file):

```php
<?php

declare(strict_types=1);

namespace App\Enum;

/** Which engine turns a connection's runs into a list; the value keys the engine in the resolver's locator. */
enum RecommendationEngineKind: string
{
    case Llm = 'llm';
    case Scoring = 'jev';

    /** @return list<CallPhase> the phases a run of this kind calls the provider in, in order */
    public function phases(): array
    {
        return match ($this) {
            self::Llm => [CallPhase::Batch, CallPhase::Consolidate],
            self::Scoring => [CallPhase::Batch],
        };
    }

    public function runs(CallPhase $phase): bool
    {
        return \in_array($phase, $this->phases(), true);
    }

    /** The single calls around the batches, which a run's progress counts like batches. */
    public function singleCallPhaseCount(): int
    {
        return \count(array_filter(
            $this->phases(),
            static fn (CallPhase $phase): bool => CallPhase::Batch !== $phase,
        ));
    }

    public function profileSource(): RecommendationProfileSource
    {
        return match ($this) {
            self::Llm => RecommendationProfileSource::Own,
            self::Scoring => RecommendationProfileSource::Borrowed,
        };
    }
}
```

`backend/src/Entity/ModelDescriptor.php` (whole file, replacing what the move left):

```php
<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\RecommendationEngineKind;
use App\Enum\ScoringProtocol;

/**
 * One model as the provider's catalog describes it, and what a connection stores once it is chosen. The context window
 * is null when the provider does not report one — most OpenAI-style gateways do (context_length or
 * max_context_length), OpenAI itself does not.
 */
final readonly class ModelDescriptor
{
    public function __construct(
        public string $id,
        public ?int $contextWindow,
        public ?ScoringProtocol $scoringProtocol = null,
    ) {
    }

    public function kind(): RecommendationEngineKind
    {
        return null === $this->scoringProtocol ? RecommendationEngineKind::Llm : RecommendationEngineKind::Scoring;
    }
}
```

In `backend/src/Service/Recommendation/Engine/Model/RecommendationEngineCapabilitiesModel.php` the two arms read the profile source from the kind. Replace

```php
                profileSource: RecommendationProfileSource::Own,
```
with `                profileSource: $kind->profileSource(),` and

```php
                profileSource: RecommendationProfileSource::Borrowed,
```
with `                profileSource: $kind->profileSource(),`. The `use App\Enum\RecommendationProfileSource;` the move added stays: the property type names it.

In `backend/src/Service/Ai/ModelCatalog/SystemOneCatalog.php` add `use App\Enum\ScoringProtocol;` and replace

```php
            static fn (string $id): ModelDescriptor => new ModelDescriptor($id, self::CONTEXT_WINDOW_TOKENS),
```
with

```php
            static fn (string $id): ModelDescriptor
                => new ModelDescriptor($id, self::CONTEXT_WINDOW_TOKENS, ScoringProtocol::SystemOne),
```

- [ ] **Step 6: Rename the tests named for the Jev kind** (D20)

```bash
perl -pi -e 's/testJevOnlyAsksInBatches/testScoringOnlyAsksInBatches/' tests/Enum/RecommendationEngineKindTest.php
perl -pi -e 's/testAJevRunThatFrozeNoProfileIsNotResumable/testAScoringRunThatFrozeNoProfileIsNotResumable/' tests/Entity/RecommendationRunTest.php
perl -pi -e 's/testAJevPlanCountsOnlyItsBatchesAndHasNoConsolidation/testAScoringPlanCountsOnlyItsBatchesAndHasNoConsolidation/' tests/Entity/RecommendationRunProgressTest.php
perl -pi -e 's/testAJevTickRecordsTheJevKind/testAScoringTickRecordsTheScoringKind/g; s/\$jevTick\b/\$scoringTick/g' tests/Service/Recommendation/Run/SnapshotPhaseTest.php
perl -pi -e 's/testAJevConnectionTicksWithTheJevKind/testAScoringConnectionTicksWithTheScoringKind/' tests/Service/Recommendation/Run/Factory/TickContextFactoryTest.php
perl -pi -e 's/testAnActiveJevConnectionWithNothingChosenGivesNone/testAnActiveScoringConnectionWithNothingChosenGivesNone/' tests/Service/Recommendation/Profile/ProfileConnectionsTest.php
perl -pi -e 's/testAJevAccountGetsNoneOfTheLlmsPromptPieces/testAScoringAccountGetsNoneOfTheLlmsPromptPieces/' tests/Controller/Api/RecommendationSettingsControllerTest.php
perl -pi -e 's/\$jev\b/\$scoring/g; s/Run 1 is a Jev run/Run 1 is a scoring run/' tests/Service/Recommendation/Run/Model/PhaseDurationsModelTest.php
perl -pi -e 's/\$jev\b/\$scoring/g' tests/Repository/RecommendationRunTimingRepositoryTest.php
perl -pi -e 's/testAJevRunIsPredictedFromJevRunsAlone/testAScoringRunIsPredictedFromScoringRunsAlone/; s/seedHistoricalJevRun/seedHistoricalScoringRun/g; s/liveJevReportWithBatches/liveScoringReportWithBatches/g; s/a Jev run \(15 s/a scoring run (15 s/; s/look like Jev.s\. 4 Jev batches/look like a scoring run'"'"'s. 4 scoring batches/; s/so its phases are Jev.s\./so its phases are a scoring run'"'"'s./' tests/Service/Recommendation/Run/RecommendationRunForecasterTest.php
git grep -n 'Jev' -- tests/Enum tests/Entity tests/Repository tests/Service/Recommendation/Run tests/Service/Recommendation/Profile/ProfileConnectionsTest.php tests/Controller/Api/RecommendationSettingsControllerTest.php
```
Expected: the last command prints only `tests/Entity/RecommendationRunTest.php`'s `'Jev needs your reading profile.'` test string (data, not a name; it stays).

- [ ] **Step 7: Run the tests to see them pass**

Run: `php bin/phpunit tests/Entity/ModelDescriptorTest.php tests/Enum/RecommendationEngineKindTest.php tests/Service/Ai/ModelCatalog tests/Service/Recommendation/Engine`
Expected: `OK`.

- [ ] **Step 8: Deletion checks** (paste each FAIL and the restored OK into the report)

1. `src/Enum/RecommendationEngineKind.php`: swap the two `profileSource()` arms' values → `php bin/phpunit tests/Enum/RecommendationEngineKindTest.php` FAILs in `testAScoringModelBorrowsItsProfileAndAnLlmBuildsItsOwn`.
2. `src/Entity/ModelDescriptor.php`: make `kind()` return `RecommendationEngineKind::Llm` → `tests/Entity/ModelDescriptorTest.php` FAILs in `testAModelThatSpeaksAScoringProtocolIsAScoringModel`.
3. `src/Service/Ai/ModelCatalog/SystemOneCatalog.php`: drop `, ScoringProtocol::SystemOne` → `tests/Service/Ai/ModelCatalog/SystemOneCatalogTest.php` FAILs.
4. `src/Service/Recommendation/Engine/Model/RecommendationEngineCapabilitiesModel.php`: replace the scoring arm's `$kind->profileSource()` with `RecommendationProfileSource::Own` → `tests/Service/Recommendation/Engine/RecommendationEngineResolverTest.php` FAILs in `testTheJevKindWritesNoReasonsSendsNoPromptAndReadsOnlyTheBatchConcurrency`.

- [ ] **Step 9: Gates**

```bash
bin/console cache:warmup
composer check
composer md
php bin/phpunit
```
Expected: green; phptramp's warnings at Task 0's count. PhpStorm `lint_files` on every changed `src` file: no ERROR or WARNING.

- [ ] **Step 10: Commit**

```bash
git add -A src tests
git commit -m "refactor(#1395): the scoring kind, the scoring protocol and the model descriptor are values"
```

---

### Task 2: Connections and runs store the kind and the protocol

**Files:**
- Create: `backend/src/Entity/ChosenModel.php`, `backend/src/Entity/RunEngine.php`, `backend/migrations/Version20261005140000.php`, `backend/migrations/Version20261005140100.php`, `backend/tests/Migrations/Version20261005140100Test.php`
- Modify: `backend/src/Enum/RecommendationEngineKind.php` (the value), `backend/src/Entity/AiProviderSettings.php`, `backend/src/Entity/RecommendationRun.php`, `backend/src/Repository/RecommendationRunTimingRepository.php:74-76`, `backend/src/Service/Ai/AiProviderConfigurator.php:137`, `backend/src/Service/Recommendation/Run/SnapshotPhase.php`, `backend/src/Service/Recommendation/Run/Pass/TickContext.php`, `backend/src/Service/Recommendation/Jev/JevRecommendationEngine.php`, `backend/tests/Support/RecommendationRunFixtures.php`, every test calling `AiProviderSettings::chooseModel()` or `RecommendationRun::snapshot()` (scripted), `backend/tests/Entity/{AiProviderSettingsTest,RecommendationRunTest}.php`, `backend/tests/Enum/RecommendationEngineKindTest.php`, `backend/tests/Service/Ai/AiProviderConfiguratorTest.php`, `backend/tests/Service/Recommendation/Run/SnapshotPhaseTest.php`

**Interfaces:**
- Consumes: `ModelDescriptor`, `ScoringProtocol`, `RecommendationEngineKind::Scoring`/`profileSource()` (Task 1).
- Produces:
  - `RecommendationEngineKind::Scoring->value === 'scoring'`.
  - `AiProviderSettings::chooseModel(ModelDescriptor $model, \DateTimeImmutable $verifiedAt): void`, `getModelKind(): ?RecommendationEngineKind`, `getScoringProtocol(): ?ScoringProtocol` (null while no model is chosen; `getModel()`, `getModelContextWindow()`, `hasModel()` unchanged).
  - `RecommendationRun::snapshot(RecommendationEngineKind $engineKind, ?ScoringProtocol $scoringProtocol, array $candidateBatches): void`, `getScoringProtocol(): ?ScoringProtocol`, `isMissingBorrowedProfile(): bool`.
  - `TickContext::scoringProtocol(): ?ScoringProtocol` (the connection's).
  - Test fixtures `RecommendationRunFixtures::seedReadyScoringSettings(User): AiProviderSettings` and `seedInactiveScoringSettings(User): AiProviderSettings` (`jev-latest`, window 32768, System One).
  - Columns `user_ai_settings.model_kind`, `user_ai_settings.scoring_protocol`, `recommendation_run.scoring_protocol` (all `VARCHAR(16) NULL`).

- [ ] **Step 1: Write the failing tests**

`backend/tests/Entity/AiProviderSettingsTest.php` — add `use App\Entity\ModelDescriptor;`, `use App\Enum\RecommendationEngineKind;`, `use App\Enum\ScoringProtocol;` and these tests (red while `getModelKind()` does not exist; later red if `ChosenModel::choose()` or `forget()` loses a field):

```php
    public function testChoosingAScoringModelStoresItsKindProtocolAndWindow(): void
    {
        $settings = $this->settings();

        $settings->chooseModel(
            new ModelDescriptor('acme/decider-2', 16_000, ScoringProtocol::SystemOne),
            new \DateTimeImmutable('2026-10-05 09:00:00'),
        );

        self::assertSame('acme/decider-2', $settings->getModel());
        self::assertSame(16_000, $settings->getModelContextWindow());
        self::assertSame(RecommendationEngineKind::Scoring, $settings->getModelKind());
        self::assertSame(ScoringProtocol::SystemOne, $settings->getScoringProtocol());
    }

    public function testChoosingAnLlmAfterAScoringModelDropsTheProtocol(): void
    {
        $settings = $this->settings();
        $settings->chooseModel(
            new ModelDescriptor('acme/decider-2', 16_000, ScoringProtocol::SystemOne),
            new \DateTimeImmutable('2026-10-05 09:00:00'),
        );

        $settings->chooseModel(new ModelDescriptor('gpt-4o', 128_000), new \DateTimeImmutable('2026-10-05 09:05:00'));

        self::assertSame(RecommendationEngineKind::Llm, $settings->getModelKind());
        self::assertNull($settings->getScoringProtocol());
    }

    public function testANewEndpointForgetsTheModelsKindAndProtocol(): void
    {
        $settings = $this->settings();
        $settings->chooseModel(
            new ModelDescriptor('acme/decider-2', 16_000, ScoringProtocol::SystemOne),
            new \DateTimeImmutable('2026-10-05 09:00:00'),
        );

        $settings->replaceConnection(
            'https://other.example.test/v1',
            $this->sealed('b3RoZXI='),
            'wxyz',
            new \DateTimeImmutable('2026-10-05 10:00:00'),
        );

        self::assertNull($settings->getModelKind());
        self::assertNull($settings->getScoringProtocol());
    }
```

`backend/tests/Entity/RecommendationRunTest.php` — add `use App\Enum\ScoringProtocol;` and (red while `snapshot()` takes two arguments; later red if the protocol is not recorded, or if a run on the LLM, or one holding its profile, reads as missing it):

```php
    public function testTheSnapshotRecordsTheKindAndTheScoringProtocol(): void
    {
        $run = $this->makeRun();

        $run->snapshot(RecommendationEngineKind::Scoring, ScoringProtocol::SystemOne, [[1]]);

        self::assertSame(RecommendationEngineKind::Scoring, $run->getEngineKind());
        self::assertSame(ScoringProtocol::SystemOne, $run->getScoringProtocol());
    }

    public function testOnlyARunOnAnEngineThatBorrowsItsProfileCanMissIt(): void
    {
        $scoringWithout = $this->makeRun();
        $scoringWithout->snapshot(RecommendationEngineKind::Scoring, ScoringProtocol::SystemOne, [[1]]);
        $scoringWith = $this->makeRun();
        $scoringWith->freezeProfile('Likes rail and maps.');
        $scoringWith->snapshot(RecommendationEngineKind::Scoring, ScoringProtocol::SystemOne, [[1]]);
        $llmWithout = $this->makeRun();
        $llmWithout->snapshot(RecommendationEngineKind::Llm, null, [[1]]);

        self::assertTrue($scoringWithout->isMissingBorrowedProfile());
        self::assertFalse($scoringWith->isMissingBorrowedProfile());
        self::assertFalse($llmWithout->isMissingBorrowedProfile());
    }
```

`backend/tests/Enum/RecommendationEngineKindTest.php` — add `use App\Enum\ScoringProtocol;` and (red while the value is `'jev'`):

```php
    /** What a connection and a run store, and what Version20261005140100 writes. */
    public function testTheStoredValuesAreTheOnesTheBackfillWrites(): void
    {
        self::assertSame('scoring', RecommendationEngineKind::Scoring->value);
        self::assertSame('system_one', ScoringProtocol::SystemOne->value);
    }
```

`backend/tests/Service/Ai/AiProviderConfiguratorTest.php` — add `use App\Enum\RecommendationEngineKind;`, `use App\Enum\ScoringProtocol;` and (red while the connection has no kind; later red if the configurator drops the catalog's protocol):

```php
    public function testChoosingAScoringModelStoresTheKindAndProtocolTheCatalogTaggedItWith(): void
    {
        $configurator = $this->configurator([
            new ModelDescriptor('gpt-4o', 128_000),
            new ModelDescriptor('acme/decider-2', 16_000, ScoringProtocol::SystemOne),
        ]);
        $user = $this->user('cfg-scoring-model@example.test');
        $added = $configurator->addConfiguration($user, null, 'https://api.example.test/v1', 'sk-abcdef1234');

        $configurator->chooseModel($added->configuration, 'acme/decider-2');

        $this->entityManager->clear();
        $stored = $configurator->settingsFor($this->reload('cfg-scoring-model@example.test'));
        self::assertSame(RecommendationEngineKind::Scoring, $stored?->getModelKind());
        self::assertSame(ScoringProtocol::SystemOne, $stored?->getScoringProtocol());
    }
```

`backend/tests/Service/Recommendation/Run/SnapshotPhaseTest.php` — the two stored-kind assertions change with the value:

```bash
perl -pi -e "s/assertSame\('jev', /assertSame('scoring', /g" tests/Service/Recommendation/Run/SnapshotPhaseTest.php
```
and add (red while `seedReadyScoringSettings()` does not exist; later red if `SnapshotPhase` records no protocol on either path):

```php
    public function testAScoringTickRecordsItsConnectionsProtocolWithAPlan(): void
    {
        $this->fixtures->storeProfile($this->owner, 'a stored profile');
        $this->fixtures->seedFeedWithEntries($this->owner, 2);
        $this->fixtures->seedReadyScoringSettings($this->owner);
        $run = $this->pendingRun();

        $this->snapshot(ScriptedRecommendationEngine::packing([[1]]))
            ->advance($this->tickOfKind($run, RecommendationEngineKind::Scoring));

        self::assertSame('system_one', $this->storedScoringProtocol($run));
    }

    public function testAScoringTickRecordsItsConnectionsProtocolForAnEmptyPool(): void
    {
        $this->fixtures->seedReadyScoringSettings($this->owner);
        $run = $this->pendingRun();

        $this->snapshot(ScriptedRecommendationEngine::packing([[999]]))
            ->advance($this->tickOfKind($run, RecommendationEngineKind::Scoring));

        self::assertSame('system_one', $this->storedScoringProtocol($run));
    }
```
with the helper beside `storedEngineKind()`:

```php
    private function storedScoringProtocol(RecommendationRun $run): mixed
    {
        return $this->entityManager->getConnection()->fetchOne(
            'SELECT scoring_protocol FROM recommendation_run WHERE id = ?',
            [$run->requireId()],
        );
    }
```

`backend/tests/Migrations/Version20261005140100Test.php` (new; red while the migration does not exist; later red if the backfill matches `jev-` case-insensitively, misses a row, or leaves a `jev` run):

```php
<?php

declare(strict_types=1);

namespace App\Tests\Migrations;

use App\Entity\User;
use App\Enum\RecommendationEngineKind;
use App\Service\Ai\Crypto\ApiKeyCipher;
use App\Tests\DbTestCase;
use App\Tests\Support\AiProviderSettingsFactory;
use App\Tests\Support\RecommendationRunFixtures;
use App\Tests\Support\SeedsUsers;
use Doctrine\DBAL\Schema\Schema;
use DoctrineMigrations\Version20261005140100;
use Psr\Log\NullLogger;

/** The test schema comes from the mapping, so the rows are set back to how they stood before the backfill. */
final class Version20261005140100Test extends DbTestCase
{
    use SeedsUsers;

    private User $owner;
    private RecommendationRunFixtures $fixtures;

    protected function setUp(): void
    {
        parent::setUp();

        /** @var ApiKeyCipher $cipher */
        $cipher = self::getContainer()->get(ApiKeyCipher::class);
        $this->fixtures = new RecommendationRunFixtures($this->entityManager, $cipher);
        $this->owner = $this->user('kind-backfill@example.test');
    }

    public function testAJevConnectionAndAJevRunBecomeSystemOneScoringAndEveryOtherModelAnLlm(): void
    {
        $jev = $this->fixtures->seedInactiveAiSettingsFor($this->owner, 'jev-latest')->requireId();
        $shouting = $this->fixtures->seedInactiveAiSettingsFor($this->owner, 'JEV-latest')->requireId();
        $chat = $this->fixtures->seedInactiveAiSettingsFor($this->owner, 'gpt-4o')->requireId();
        $modelless = AiProviderSettingsFactory::build($this->owner);
        $this->entityManager->persist($modelless);
        $jevRun = $this->fixtures->createRun($this->owner);
        $jevRun->snapshot(RecommendationEngineKind::Scoring, null, [[1]]);
        $llmRun = $this->fixtures->createRun($this->owner);
        $llmRun->snapshot(RecommendationEngineKind::Llm, null, [[2]]);
        $this->entityManager->flush();
        $this->setBackToBeforeTheBackfill($jevRun->requireId());

        $this->backfill();

        self::assertSame(['scoring', 'system_one'], $this->connectionTag($jev));
        self::assertSame(['llm', null], $this->connectionTag($shouting));
        self::assertSame(['llm', null], $this->connectionTag($chat));
        self::assertSame([null, null], $this->connectionTag($modelless->requireId()));
        self::assertSame(['scoring', 'system_one'], $this->runTag($jevRun->requireId()));
        self::assertSame(['llm', null], $this->runTag($llmRun->requireId()));
    }

    private function setBackToBeforeTheBackfill(int $jevRunId): void
    {
        $connection = $this->entityManager->getConnection();
        $connection->executeStatement('UPDATE user_ai_settings SET model_kind = NULL, scoring_protocol = NULL');
        $connection->executeStatement(
            "UPDATE recommendation_run SET engine_kind = 'jev', scoring_protocol = NULL WHERE id = ?",
            [$jevRunId],
        );
        $this->entityManager->clear();
    }

    private function backfill(): void
    {
        // Migration classes are deliberately excluded from Composer's autoloader (doctrine_migrations.yaml).
        require_once dirname(__DIR__, 2) . '/migrations/Version20261005140100.php';
        $connection = $this->entityManager->getConnection();
        $migration = new Version20261005140100($connection, new NullLogger());
        $migration->up(new Schema());
        foreach ($migration->getSql() as $query) {
            $connection->executeStatement($query->getStatement());
        }
    }

    /** @return list<mixed> the connection's kind and protocol as stored */
    private function connectionTag(int $connectionId): array
    {
        $row = $this->entityManager->getConnection()->fetchAssociative(
            'SELECT model_kind, scoring_protocol FROM user_ai_settings WHERE id = ?',
            [$connectionId],
        );
        self::assertIsArray($row);

        return [$row['model_kind'], $row['scoring_protocol']];
    }

    /** @return list<mixed> the run's kind and protocol as stored */
    private function runTag(int $runId): array
    {
        $row = $this->entityManager->getConnection()->fetchAssociative(
            'SELECT engine_kind, scoring_protocol FROM recommendation_run WHERE id = ?',
            [$runId],
        );
        self::assertIsArray($row);

        return [$row['engine_kind'], $row['scoring_protocol']];
    }
}
```
**Assumption (verify):** `DoctrineMigrations\…` resolves for PHPStan as it does for `RecommendationRunTimingRepositoryTest` (which imports `Version20260925090000` the same way).

- [ ] **Step 2: Run them to see them fail**

Run: `php bin/phpunit tests/Entity/AiProviderSettingsTest.php tests/Entity/RecommendationRunTest.php tests/Enum/RecommendationEngineKindTest.php`
Expected: errors (`Call to undefined method App\Entity\AiProviderSettings::getModelKind()`, an `ArgumentCountError`/`TypeError` from `snapshot()`), and `testTheStoredValuesAreTheOnesTheBackfillWrites` FAILs with `'scoring'` vs `'jev'`.

- [ ] **Step 3: The value and the connection's chosen model**

In `backend/src/Enum/RecommendationEngineKind.php` change `    case Scoring = 'jev';` to `    case Scoring = 'scoring';`.

`backend/src/Entity/ChosenModel.php`:

```php
<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\RecommendationEngineKind;
use App\Enum\ScoringProtocol;
use Doctrine\ORM\Mapping as ORM;

/** A connection's model and what the provider's catalog said about it when it was chosen; empty until then. */
#[ORM\Embeddable]
final class ChosenModel
{
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $model = null;

    /** Tokens, as /models reported it; null when the provider did not report one. */
    #[ORM\Column(nullable: true)]
    private ?int $modelContextWindow = null;

    #[ORM\Column(length: 16, nullable: true, enumType: RecommendationEngineKind::class)]
    private ?RecommendationEngineKind $modelKind = null;

    #[ORM\Column(length: 16, nullable: true, enumType: ScoringProtocol::class)]
    private ?ScoringProtocol $scoringProtocol = null;

    public function choose(ModelDescriptor $model): void
    {
        $this->model = $model->id;
        $this->modelContextWindow = $model->contextWindow;
        $this->modelKind = $model->kind();
        $this->scoringProtocol = $model->scoringProtocol;
    }

    public function forget(): void
    {
        $this->model = null;
        $this->modelContextWindow = null;
        $this->modelKind = null;
        $this->scoringProtocol = null;
    }

    public function getModel(): ?string
    {
        return $this->model;
    }

    public function getModelContextWindow(): ?int
    {
        return $this->modelContextWindow;
    }

    public function getModelKind(): ?RecommendationEngineKind
    {
        return $this->modelKind;
    }

    public function getScoringProtocol(): ?ScoringProtocol
    {
        return $this->scoringProtocol;
    }
}
```

`backend/src/Entity/AiProviderSettings.php` — whole file (the two model fields move into `ChosenModel`, D3; columns unchanged):

```php
<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\RecommendationEngineKind;
use App\Enum\ScoringProtocol;
use App\Repository\AiProviderSettingsRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One account's AI provider. Unlike Preferences, no row is created with the account: no row means "not configured".
 * No readable secret is stored; `apiKeyHint`, the key's last four characters, is clear text on purpose.
 */
#[ORM\Entity(repositoryClass: AiProviderSettingsRepository::class)]
#[ORM\Table(name: 'user_ai_settings')]
final class AiProviderSettings
{
    use PersistedId;

    /**
     * The smallest cap an account may set. It may sit below RecommendationPromptBuilder::MINIMUM_BATCH_SIZE (10): that
     * floors only the token-budget split, and the cap closes a batch first (caps 5, 7, 9 over 40 candidates held).
     */
    public const int MINIMUM_BATCH_SIZE = 5;

    /**
     * A sanity bound against a typo, not a quality bound: the token budget is
     * the real guard on how large a batch may be.
     */
    public const int MAXIMUM_BATCH_SIZE = 200;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $name = null;

    #[ORM\Column(length: 512)]
    private string $baseUrl;

    #[ORM\Column(length: 1024)]
    private string $apiKeyCiphertext;

    #[ORM\Column(length: 64)]
    private string $apiKeyNonce;

    #[ORM\Column(length: 64)]
    private string $apiKeySalt;

    #[ORM\Column(length: 8)]
    private string $apiKeyHint;

    #[ORM\Column(options: ['default' => 1])]
    private int $keyVersion;

    #[ORM\Embedded(class: ChosenModel::class, columnPrefix: false)]
    private ChosenModel $chosenModel;

    /**
     * Default true: ranking needs no thinking phase, and a reasoning model reasoning here is pure cost (#320, #323).
     */
    #[ORM\Column(options: ['default' => 1])]
    private bool $suppressReasoning = true;

    #[ORM\Column(name: 'suppression_refused_by_model', length: 255, nullable: true)]
    private ?string $suppressionRefusedByModel = null;

    #[ORM\Embedded(class: RunTuning::class, columnPrefix: false)]
    private RunTuning $runTuning;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $verifiedAt = null;

    /**
     * The caller passes $verifiedAt: a row is normally born of a successful live call, but a duplicate
     * (AiProviderConfigurator::duplicateConfiguration) carries its sibling's. Delegates to replaceConnection().
     */
    public function __construct(
        User $user,
        ?string $name,
        string $baseUrl,
        SealedSecret $sealed,
        string $apiKeyHint,
        \DateTimeImmutable $verifiedAt,
    ) {
        $this->user = $user;
        $this->name = $name;
        $this->runTuning = new RunTuning();
        $this->chosenModel = new ChosenModel();
        $this->replaceConnection($baseUrl, $sealed, $apiKeyHint, $verifiedAt);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function rename(?string $name): void
    {
        $this->name = $name;
    }

    public function getBaseUrl(): string
    {
        return $this->baseUrl;
    }

    public function getApiKeyHint(): string
    {
        return $this->apiKeyHint;
    }

    public function getSealedSecret(): SealedSecret
    {
        return new SealedSecret(
            $this->apiKeyCiphertext,
            $this->apiKeyNonce,
            $this->apiKeySalt,
            $this->keyVersion,
        );
    }

    public function getModel(): ?string
    {
        return $this->chosenModel->getModel();
    }

    public function getModelContextWindow(): ?int
    {
        return $this->chosenModel->getModelContextWindow();
    }

    public function getModelKind(): ?RecommendationEngineKind
    {
        return $this->chosenModel->getModelKind();
    }

    public function getScoringProtocol(): ?ScoringProtocol
    {
        return $this->chosenModel->getScoringProtocol();
    }

    public function hasModel(): bool
    {
        return null !== $this->getModel();
    }

    public function suppressesReasoning(): bool
    {
        return $this->suppressReasoning;
    }

    public function setSuppressReasoning(bool $suppressReasoning): void
    {
        $this->suppressReasoning = $suppressReasoning;
        $this->suppressionRefusedByModel = null;
    }

    public function recordSuppressionRefused(): void
    {
        $this->suppressionRefusedByModel = $this->getModel()
            ?? throw new \LogicException('A connection without a model has sent no request to refuse.');
    }

    public function refusesSuppressedReasoning(): bool
    {
        $model = $this->getModel();

        return null !== $model && $model === $this->suppressionRefusedByModel;
    }

    public function getRunTuning(): RunTuning
    {
        return $this->runTuning;
    }

    public function setBatchConcurrency(int $batchConcurrency): void
    {
        $this->runTuning->setBatchConcurrency($batchConcurrency);
    }

    public function isSlowModel(): bool
    {
        return $this->runTuning->isSlowModel();
    }

    public function setSlowModel(bool $slowModel): void
    {
        $this->runTuning->setSlowModel($slowModel);
    }

    public function setMaxBatchSize(?int $maxBatchSize): void
    {
        $this->runTuning->setMaxBatchSize($maxBatchSize);
    }

    public function getVerifiedAt(): ?\DateTimeImmutable
    {
        return $this->verifiedAt;
    }

    /**
     * A new endpoint or a new key invalidates the chosen model: the identifier
     * that existed at the old provider carries no promise at the new one, and
     * keeping it would let `ready` claim a model the provider never offered.
     */
    public function replaceConnection(
        string $baseUrl,
        SealedSecret $sealed,
        string $apiKeyHint,
        \DateTimeImmutable $verifiedAt,
    ): void {
        $this->baseUrl = $baseUrl;
        $this->apiKeyHint = $apiKeyHint;
        $this->applySealedKey($sealed);
        $this->chosenModel->forget();
        $this->suppressionRefusedByModel = null;
        $this->verifiedAt = $verifiedAt;
    }

    public function chooseModel(ModelDescriptor $model, \DateTimeImmutable $verifiedAt): void
    {
        $this->chosenModel->choose($model);
        $this->verifiedAt = $verifiedAt;
    }

    private function applySealedKey(SealedSecret $sealed): void
    {
        $this->apiKeyCiphertext = $sealed->ciphertext;
        $this->apiKeyNonce = $sealed->nonce;
        $this->apiKeySalt = $sealed->salt;
        $this->keyVersion = $sealed->version;
    }
}
```

In `backend/src/Service/Ai/AiProviderConfigurator.php` replace

```php
        $settings->chooseModel($model, $this->clock->now(), $descriptor->contextWindow);
```
with

```php
        $settings->chooseModel($descriptor, $this->clock->now());
```

- [ ] **Step 4: The run's engine**

`backend/src/Entity/RunEngine.php`:

```php
<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\RecommendationEngineKind;
use App\Enum\ScoringProtocol;
use Doctrine\ORM\Mapping as ORM;

/** The engine a run's frozen plan was packed for, and for a scoring model the protocol it speaks. */
#[ORM\Embeddable]
final class RunEngine
{
    #[ORM\Column(length: 16, nullable: true, enumType: RecommendationEngineKind::class)]
    private ?RecommendationEngineKind $engineKind = null;

    #[ORM\Column(length: 16, nullable: true, enumType: ScoringProtocol::class)]
    private ?ScoringProtocol $scoringProtocol = null;

    public function record(RecommendationEngineKind $engineKind, ?ScoringProtocol $scoringProtocol): void
    {
        $this->engineKind = $engineKind;
        $this->scoringProtocol = $scoringProtocol;
    }

    /** A run without a recorded kind predates the column and ran on the LLM, the only engine there was. */
    public function getEngineKind(): RecommendationEngineKind
    {
        return $this->engineKind ?? RecommendationEngineKind::Llm;
    }

    public function getScoringProtocol(): ?ScoringProtocol
    {
        return $this->scoringProtocol;
    }
}
```

`backend/src/Entity/RecommendationRun.php`, five edits:

1. Imports: below `use App\Enum\RecommendationEngineKind;` the block reads

```php
use App\Enum\RecommendationEngineKind;
use App\Enum\RecommendationProfileSource;
use App\Enum\RunStatus;
use App\Enum\ScoringProtocol;
```

2. Replace

```php
    /** The engine the frozen plan was packed for; null on runs from before the column. */
    #[ORM\Column(length: 16, nullable: true, enumType: RecommendationEngineKind::class)]
    private ?RecommendationEngineKind $engineKind = null;
```
with

```php
    #[ORM\Embedded(class: RunEngine::class, columnPrefix: false)]
    private RunEngine $engine;
```

3. In the constructor, after `        $this->callAttempts = new RunCallAttempts();` add `        $this->engine = new RunEngine();`.

4. Replace

```php
    public function snapshot(RecommendationEngineKind $engineKind, array $candidateBatches): void
    {
        $this->guardStatus(RunStatus::Pending, 'snapshot');

        $this->engineKind = $engineKind;
```
with

```php
    public function snapshot(
        RecommendationEngineKind $engineKind,
        ?ScoringProtocol $scoringProtocol,
        array $candidateBatches,
    ): void {
        $this->guardStatus(RunStatus::Pending, 'snapshot');

        $this->engine->record($engineKind, $scoringProtocol);
```
and replace

```php
    /** A run without a recorded kind predates the column and ran on the LLM, the only engine there was. */
    public function getEngineKind(): RecommendationEngineKind
    {
        return $this->engineKind ?? RecommendationEngineKind::Llm;
    }
```
with

```php
    public function getEngineKind(): RecommendationEngineKind
    {
        return $this->engine->getEngineKind();
    }

    public function getScoringProtocol(): ?ScoringProtocol
    {
        return $this->engine->getScoringProtocol();
    }
```

5. Replace

```php
    /**
     * Nothing to resume before the snapshot, nor for a Jev run that froze no profile: a new run asks for one again.
     */
    public function isResumable(): bool
    {
        if (RunStatus::Failed !== $this->status || null === $this->candidateBatches) {
            return false;
        }

        return RecommendationEngineKind::Scoring !== $this->engineKind || null !== $this->getProfileText();
    }
```
with

```php
    /** Nothing to resume before the snapshot, nor once missing a borrowed profile: a new run asks for one again. */
    public function isResumable(): bool
    {
        if (RunStatus::Failed !== $this->status || null === $this->candidateBatches) {
            return false;
        }

        return !$this->isMissingBorrowedProfile();
    }

    public function isMissingBorrowedProfile(): bool
    {
        return RecommendationProfileSource::Borrowed === $this->getEngineKind()->profileSource()
            && null === $this->getProfileText();
    }
```

In `backend/src/Repository/RecommendationRunTimingRepository.php` the embedded field's DQL path:

```php
            ->andWhere(RecommendationEngineKind::Llm === $engineKind
                ? '(r.engine.engineKind = :kind OR r.engine.engineKind IS NULL)'
                : 'r.engine.engineKind = :kind')
```
**Assumption (verify):** DQL reaches an embedded field as `r.engine.engineKind`; `RecommendationRunTimingRepositoryTest::testOnlyRunsOfTheAskedKindAreRead` proves it.

- [ ] **Step 5: The snapshot records the protocol; the scoring engine's profile check asks the kind**

`backend/src/Service/Recommendation/Run/Pass/TickContext.php`: add `use App\Enum\ScoringProtocol;` and, after `callRoute()`:

```php
    public function scoringProtocol(): ?ScoringProtocol
    {
        return $this->connection->getScoringProtocol();
    }
```

`backend/src/Service/Recommendation/Run/SnapshotPhase.php`: replace `            $run->snapshot($tick->engineKind, []);` with `            $run->snapshot($tick->engineKind, $tick->scoringProtocol(), []);`, and replace

```php
        $run->snapshot(
            $tick->engineKind,
            $this->engines->engineOf($tick->engineKind)->packBatches($candidates, $tick),
        );
```
with

```php
        $run->snapshot(
            $tick->engineKind,
            $tick->scoringProtocol(),
            $this->engines->engineOf($tick->engineKind)->packBatches($candidates, $tick),
        );
```

`backend/src/Service/Recommendation/Jev/JevRecommendationEngine.php` (D5, D18): replace

```php
    public const string NO_PROFILE = 'Jev needs your reading profile, and there is no reading history to build one '
        . 'from yet. Read, keep or favourite a few articles, then start a new run.';
```
with

```php
    public const string NO_PROFILE = 'A scoring model needs your reading profile, and there is no reading history to '
        . 'build one from yet. Read, keep or favourite a few articles, then start a new run.';
```
and `        if (null === $run->getProfileText()) {` with `        if ($run->isMissingBorrowedProfile()) {`.

- [ ] **Step 6: The migrations**

`backend/migrations/Version20261005140000.php`:

```php
<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261005140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add the engine kind and scoring protocol of a connection\'s model, and a run\'s scoring protocol (#1395).';
    }

    public function up(Schema $schema): void
    {
        $this->skipIf(
            $schema->getTable('user_ai_settings')->hasColumn('model_kind'),
            'user_ai_settings.model_kind already exists.',
        );

        $add = $this->mysql() ? 'ADD' : 'ADD COLUMN';
        $this->addSql(\sprintf('ALTER TABLE user_ai_settings %s model_kind VARCHAR(16) DEFAULT NULL', $add));
        $this->addSql(\sprintf('ALTER TABLE user_ai_settings %s scoring_protocol VARCHAR(16) DEFAULT NULL', $add));
        $this->addSql(\sprintf('ALTER TABLE recommendation_run %s scoring_protocol VARCHAR(16) DEFAULT NULL', $add));
    }

    public function down(Schema $schema): void
    {
        $this->skipIf(
            !$schema->getTable('user_ai_settings')->hasColumn('model_kind'),
            'user_ai_settings.model_kind is already gone.',
        );

        $drop = $this->mysql() ? 'DROP' : 'DROP COLUMN';
        $this->addSql(\sprintf('ALTER TABLE user_ai_settings %s model_kind', $drop));
        $this->addSql(\sprintf('ALTER TABLE user_ai_settings %s scoring_protocol', $drop));
        $this->addSql(\sprintf('ALTER TABLE recommendation_run %s scoring_protocol', $drop));
    }

    public function isTransactional(): bool
    {
        return false;
    }

    /** Refuses any platform but the two supported ones: better a refusal than DDL nobody tested. */
    private function mysql(): bool
    {
        $platform = $this->connection->getDatabasePlatform();

        $this->abortIf(
            !$platform instanceof AbstractMySQLPlatform && !$platform instanceof SQLitePlatform,
            \sprintf('No DDL defined for platform %s; only MySQL and SQLite are supported.', $platform::class),
        );

        return $platform instanceof AbstractMySQLPlatform;
    }
}
```

`backend/migrations/Version20261005140100.php`:

```php
<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** Until #1395 a model id starting with `jev-`, case-sensitively, made a connection TypeSafe's System One. */
final class Version20261005140100 extends AbstractMigration
{
    public function getDescription(): string
    {
        return "Backfill the engine kind and scoring protocol; a run's kind 'jev' becomes 'scoring' (#1395).";
    }

    public function up(Schema $schema): void
    {
        $jevModel = $this->mysql() ? "CAST(LEFT(model, 4) AS BINARY) = 'jev-'" : "substr(model, 1, 4) = 'jev-'";
        $this->addSql(
            "UPDATE user_ai_settings SET model_kind = 'scoring', scoring_protocol = 'system_one' WHERE " . $jevModel,
        );
        $this->addSql("UPDATE user_ai_settings SET model_kind = 'llm' WHERE model IS NOT NULL AND model_kind IS NULL");
        $this->addSql(
            "UPDATE recommendation_run SET engine_kind = 'scoring', scoring_protocol = 'system_one' "
            . "WHERE engine_kind = 'jev'",
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql(
            "UPDATE recommendation_run SET engine_kind = 'jev', scoring_protocol = NULL WHERE engine_kind = 'scoring'",
        );
        $this->addSql('UPDATE user_ai_settings SET model_kind = NULL, scoring_protocol = NULL');
    }

    /** Refuses any platform but the two supported ones: better a refusal than SQL nobody tested. */
    private function mysql(): bool
    {
        $platform = $this->connection->getDatabasePlatform();

        $this->abortIf(
            !$platform instanceof AbstractMySQLPlatform && !$platform instanceof SQLitePlatform,
            \sprintf('No SQL defined for platform %s; only MySQL and SQLite are supported.', $platform::class),
        );

        return $platform instanceof AbstractMySQLPlatform;
    }
}
```

- [ ] **Step 7: Rewrite the call sites**

From `backend/`, in this order:

```bash
php ../docs/superpowers/plans/2026-10-05-1395-scripts/rewrite-choose-model.php
```
Expected: the rewritten files, `Rewrote chooseModel() in 19 files.` (give or take one; the leftover list is what counts), and nothing under "Calls left over:". A leftover call is edited by hand to `chooseModel(new ModelDescriptor(<model>, <window>), <verifiedAt>)`.

```bash
git grep -l 'snapshot(RecommendationEngineKind::Llm, ' -- src tests | xargs perl -pi -e 's/snapshot\(RecommendationEngineKind::Llm, (?!null, )/snapshot(RecommendationEngineKind::Llm, null, /g'
git grep -l 'snapshot(RecommendationEngineKind::Scoring, ' -- tests | xargs perl -pi -e 's/snapshot\(RecommendationEngineKind::Scoring, (?!ScoringProtocol::|null, )/snapshot(RecommendationEngineKind::Scoring, ScoringProtocol::SystemOne, /g'
```
Then by hand in `tests/Service/Recommendation/Run/RecommendationRunForecasterTest.php`: in `liveReportWithBatches()` add the line `            null,` after `            RecommendationEngineKind::Llm,`; in `liveScoringReportWithBatches()` add `            ScoringProtocol::SystemOne,` after `            RecommendationEngineKind::Scoring,`; in `runWithPickupAndBatches()` change `$run->snapshot($engineKind, [[1]]);` to `$run->snapshot($engineKind, null, [[1]]);` (the forecaster reads only the kind). Add `use App\Enum\ScoringProtocol;` to every file the second `perl` changed that lacks it (`tests/Repository/RecommendationRunTimingRepositoryTest.php`, `tests/Service/Recommendation/Run/RecommendationRunForecasterTest.php`; `RecommendationRunTest` has it from Step 1).

```bash
git grep -n -E 'snapshot\((RecommendationEngineKind::[A-Za-z]+|\$[A-Za-z]+), \[' -- src tests
```
Expected: nothing.

The fixtures: in `tests/Support/RecommendationRunFixtures.php` add `use App\Enum\ScoringProtocol;` (the script added `ModelDescriptor`) and replace `seedReadyAiSettingsFor()`, `seedInactiveAiSettingsFor()` and `seedConnection()` with:

```php
    public function seedReadyAiSettingsFor(User $user, string $model): AiProviderSettings
    {
        return $this->activated($user, $this->seedInactiveAiSettingsFor($user, $model));
    }

    /** A ready connection on the default endpoint that the account has not activated. */
    public function seedInactiveAiSettingsFor(User $user, string $model): AiProviderSettings
    {
        return $this->seedConnection($user, new ModelDescriptor($model, 32768));
    }

    /** An active System One connection on `jev-latest`. */
    public function seedReadyScoringSettings(User $user): AiProviderSettings
    {
        return $this->activated($user, $this->seedInactiveScoringSettings($user));
    }

    public function seedInactiveScoringSettings(User $user): AiProviderSettings
    {
        return $this->seedConnection($user, new ModelDescriptor('jev-latest', 32768, ScoringProtocol::SystemOne));
    }

    private function activated(User $user, AiProviderSettings $settings): AiProviderSettings
    {
        $user->setActiveAiProviderSettings($settings);
        $this->entityManager->flush();

        return $settings;
    }

    private function seedConnection(User $owner, ModelDescriptor $model): AiProviderSettings
    {
        $now = new \DateTimeImmutable('2026-08-07 09:00:00');
        $connection = new AiProviderSettings(
            $owner,
            null,
            'https://api.example.test/v1',
            $this->cipher->seal($owner->requireId(), 'sk-throwaway1234'),
            '1234',
            $now,
        );
        $this->entityManager->persist($connection);
        $connection->chooseModel($model, $now);
        $this->entityManager->flush();

        return $connection;
    }
```

Every test that seeded a Jev connection through the id now seeds a scoring one:

```bash
git grep -l "AiSettingsFor(.*'jev-latest')" -- tests | xargs perl -pi -e "s/seedReadyAiSettingsFor\(([^,()]+), 'jev-latest'\)/seedReadyScoringSettings(\$1)/g; s/seedInactiveAiSettingsFor\(([^,()]+), 'jev-latest'\)/seedInactiveScoringSettings(\$1)/g"
git grep -n "AiSettingsFor(.*'jev-" -- tests
git grep -n "'jev'" -- src tests
```
Expected: the second command prints nothing; the third prints only `tests/Migrations/Version20261005140100Test.php` (the value set back before the backfill).

- [ ] **Step 8: Run the tests to see them pass**

```bash
php bin/phpunit tests/Entity tests/Enum tests/Migrations tests/Repository/RecommendationRunTimingRepositoryTest.php tests/Service/Ai tests/Service/Recommendation
```
Expected: `OK`.

- [ ] **Step 9: Verify the migrations from empty, then apply them**

SQLite, from `backend/`:

```bash
rm -f var/migration-check-1395.db
DATABASE_URL='sqlite:///%kernel.project_dir%/var/migration-check-1395.db' php bin/console doctrine:migrations:migrate --no-interaction
DATABASE_URL='sqlite:///%kernel.project_dir%/var/migration-check-1395.db' php bin/console doctrine:schema:validate
rm -f var/migration-check-1395.db
```
MySQL, from the repository root (a throwaway database, never `feedreader`):

```bash
docker compose exec -T -e DATABASE_URL='mysql://root:root@mysql:3306/feedreader_migrations_1395?serverVersion=8.4&charset=utf8mb4' php sh -c 'bin/console doctrine:database:drop --force --if-exists && bin/console doctrine:database:create && bin/console doctrine:migrations:migrate --no-interaction && bin/console doctrine:schema:validate && bin/console doctrine:database:drop --force'
```
Expected (both): the migrations run to the latest version, and `doctrine:schema:validate` prints `[OK] The mapping files are correct.` and `[OK] The database schema is in sync with the mapping files.` (the moved `model` and `model_context_window` columns are unchanged, D3). **Assumption (verify):** the php container reaches MySQL as `root:root` (docker-compose.yml's `MYSQL_ROOT_PASSWORD`), and the drop/create lines name `feedreader_migrations_1395` — read them before trusting the next command.

Apply to the live dev database and read the result (read-only queries):

```bash
docker compose exec php bin/console doctrine:migrations:migrate --no-interaction
docker compose exec php bin/console dbal:run-sql "SELECT id, model, model_kind, scoring_protocol FROM user_ai_settings ORDER BY id"
docker compose exec php bin/console dbal:run-sql "SELECT engine_kind, scoring_protocol, COUNT(*) AS runs FROM recommendation_run GROUP BY engine_kind, scoring_protocol"
docker compose exec php bin/console cache:clear
docker compose restart worker
```
Expected: every `jev-…` connection (connection 8 among them) reads `scoring`/`system_one`, every other connection with a model `llm`/NULL, model-less ones NULL/NULL; no run reads `jev`; former Jev runs `scoring`/`system_one`. Paste both result tables into the report.

- [ ] **Step 10: Deletion checks** (paste each FAIL and the restored OK)

1. `src/Entity/ChosenModel.php`: delete `$this->modelKind = $model->kind();` → `tests/Entity/AiProviderSettingsTest.php` FAILs in `testChoosingAScoringModelStoresItsKindProtocolAndWindow`.
2. `src/Entity/ChosenModel.php`: delete `$this->scoringProtocol = null;` in `forget()` → `testANewEndpointForgetsTheModelsKindAndProtocol` FAILs.
3. `src/Service/Ai/AiProviderConfigurator.php`: pass `new ModelDescriptor($descriptor->id, $descriptor->contextWindow)` → `tests/Service/Ai/AiProviderConfiguratorTest.php` FAILs in `testChoosingAScoringModelStoresTheKindAndProtocolTheCatalogTaggedItWith`.
4. `src/Entity/RunEngine.php`: delete `$this->scoringProtocol = $scoringProtocol;` → `tests/Entity/RecommendationRunTest.php` FAILs in `testTheSnapshotRecordsTheKindAndTheScoringProtocol`.
5. `src/Entity/RecommendationRun.php`: make `isMissingBorrowedProfile()` return `null === $this->getProfileText();` → `testOnlyARunOnAnEngineThatBorrowsItsProfileCanMissIt` FAILs (and `testOnlyARunThatFailedAfterItsSnapshotIsResumable`).
6. `src/Service/Recommendation/Run/SnapshotPhase.php`: pass `null` for the protocol in `snapshotWith()` → `testAScoringTickRecordsItsConnectionsProtocolWithAPlan` FAILs; then the same in the empty-pool `snapshot()` → `testAScoringTickRecordsItsConnectionsProtocolForAnEmptyPool` FAILs.
7. `migrations/Version20261005140100.php`: change the SQLite branch's `'jev-'` to `'JEV-'` → `php bin/phpunit tests/Migrations` FAILs on the first assertion; then delete the `recommendation_run` `addSql()` → FAILs on `runTag`.
8. `src/Service/Recommendation/Jev/JevRecommendationEngine.php`: delete the `isMissingBorrowedProfile()` guard → `tests/Service/Recommendation/Jev/JevRecommendationEngineTest.php` FAILs in `testARunWithoutAProfileFailsWithTheReasonAndNeverAsksSystemOne`.
9. `src/Enum/RecommendationEngineKind.php`: value back to `'jev'` → `testTheStoredValuesAreTheOnesTheBackfillWrites` FAILs.

- [ ] **Step 11: Gates**

From `backend/`: `bin/console cache:warmup`, `composer check`, `composer md` (both entities and both embeddables clean: `TooManyFields` reads 14 and 15), `php bin/phpunit`. From the repository root: `docker compose exec php sh -c 'rm -rf var/cache/test*'` then `docker compose exec php composer test`. PhpStorm `lint_files` on the changed `src` files.
Expected: green; phptramp's warnings at Task 0's count.

- [ ] **Step 12: Commit**

```bash
git add -A src tests migrations
git commit -m "feat(#1395): connections and runs store the engine kind and the scoring protocol"
```

---

### Task 3: The resolver reads the stored kind; the model list is labelled by the catalog's tag

**Files:**
- Modify: `backend/src/Service/Recommendation/Engine/RecommendationEngineResolver.php`, `backend/src/Http/RecommendationCapabilitiesJson.php`, `backend/src/Http/AiSettingsJson.php`, `backend/src/Service/Ai/AiProviderConfigurator.php`, `backend/src/Service/Ai/Model/AddedConfigurationModel.php`, `backend/src/Controller/Api/AiSettingsController.php:64`, `frontend/public/i18n/en.json:247`, `frontend/public/i18n/de.json:247`
- Test: `backend/tests/Service/Recommendation/Engine/RecommendationEngineResolverTest.php` (rewritten), `backend/tests/Http/RecommendationCapabilitiesJsonTest.php` (rewritten), `backend/tests/Http/AiSettingsJsonTest.php`, `backend/tests/Controller/Api/AiSettingsControllerTest.php`, `backend/tests/Support/{RecommendationCapabilitiesJsons,AiConfigurationRequests}.php`, `backend/tests/Service/Recommendation/Llm/Run/SuppressedReasoningFallbackTest.php`, `backend/tests/Service/Ai/AiProviderConfiguratorTest.php`

**Interfaces:**
- Consumes: `AiProviderSettings::getModelKind()` (Task 2), `ModelDescriptor::kind()` and `$scoringProtocol` (Task 1).
- Produces:
  - `RecommendationEngineResolver::kindFor(AiProviderSettings): RecommendationEngineKind` = stored kind `?? Llm`; `capabilitiesFor()`, `capabilitiesForAccount()`, `engineOf()` unchanged; `labelForModel()`, `capabilitiesForModel()`, `kindForModel()` and `JEV_MODEL_PREFIX` are gone.
  - `RecommendationCapabilitiesJson::ofKind(RecommendationEngineKind $kind): array` (replaces `ofModel(string)`).
  - `AiSettingsJson::__construct(RecommendationCapabilitiesJson $capabilities)`; `models(list<ModelDescriptor>)` and `added(AiProviderSettings, list<ModelDescriptor>)`.
  - `AiProviderConfigurator::listModels(AiProviderSettings): list<ModelDescriptor>`; `AddedConfigurationModel::$models` (`list<ModelDescriptor>`, replaces `$modelIds`).
  - Wire: unchanged keys; a System One model's `label` is `"System One"`.
  - Test constant `RecommendationCapabilitiesJsons::SCORING` (renamed from `JEV`).

- [ ] **Step 1: Write the failing tests**

```bash
git grep -l 'RecommendationCapabilitiesJsons::JEV' -- tests | xargs perl -pi -e 's/RecommendationCapabilitiesJsons::JEV\b/RecommendationCapabilitiesJsons::SCORING/g'
perl -pi -e 's/public const array JEV = /public const array SCORING = /' tests/Support/RecommendationCapabilitiesJsons.php
```

`backend/tests/Service/Recommendation/Engine/RecommendationEngineResolverTest.php` — whole file (the `jev-` prefix cases are deleted, spec Testing; red while `kindFor()` reads the model id: both data rows contradict the prefix rule):

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Recommendation\Engine;

use App\Entity\AiProviderSettings;
use App\Entity\ModelDescriptor;
use App\Entity\User;
use App\Enum\RecommendationEngineKind;
use App\Enum\RecommendationProfileSource;
use App\Enum\ScoringProtocol;
use App\Service\Recommendation\Engine\Model\RecommendationEngineCapabilitiesModel;
use App\Service\Recommendation\Engine\Model\RecommendationTuningField;
use App\Service\Recommendation\Engine\RecommendationEngineResolver;
use App\Tests\Support\AiProviderSettingsFactory;
use App\Tests\Support\ScriptedRecommendationEngine;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use Symfony\Component\DependencyInjection\ServiceLocator;

final class RecommendationEngineResolverTest extends TestCase
{
    /** @return iterable<string, array{?ModelDescriptor, RecommendationEngineKind}> */
    public static function chosenModels(): iterable
    {
        yield 'a scoring model whose id names no Jev' => [
            new ModelDescriptor('acme/decider-2', 16_000, ScoringProtocol::SystemOne),
            RecommendationEngineKind::Scoring,
        ];
        yield 'an LLM whose id starts like Jev' => [
            new ModelDescriptor('jev-latest', 128_000),
            RecommendationEngineKind::Llm,
        ];
        yield 'no model yet' => [null, RecommendationEngineKind::Llm];
    }

    #[DataProvider('chosenModels')]
    public function testTheStoredKindDecidesTheEngineNeverTheModelId(
        ?ModelDescriptor $model,
        RecommendationEngineKind $kind,
    ): void {
        $resolver = new RecommendationEngineResolver(new ServiceLocator([]));
        $connection = AiProviderSettingsFactory::build(
            new User('engine-resolver@example.test', new \DateTimeImmutable('2026-10-02 09:00:00')),
        );
        if (null !== $model) {
            $connection->chooseModel($model, new \DateTimeImmutable('2026-10-02 09:05:00'));
        }

        self::assertSame($kind, $resolver->kindFor($connection));
    }

    public function testTheScoringKindWritesNoReasonsSendsNoPromptAndReadsOnlyTheBatchConcurrency(): void
    {
        $capabilities = RecommendationEngineCapabilitiesModel::of(RecommendationEngineKind::Scoring);

        self::assertFalse($capabilities->writesReasons);
        self::assertFalse($capabilities->sendsPrompt);
        self::assertSame(RecommendationProfileSource::Borrowed, $capabilities->profileSource);
        self::assertSame([RecommendationTuningField::BatchConcurrency], $capabilities->tuningFields);
    }

    public function testAnAccountsCapabilitiesAreItsActiveConnections(): void
    {
        $resolver = new RecommendationEngineResolver(new ServiceLocator([]));
        $account = new User('scoring-account@example.test', new \DateTimeImmutable('2026-10-02 09:00:00'));
        $account->setActiveAiProviderSettings(
            $this->connection(new ModelDescriptor('acme/decider-2', 16_000, ScoringProtocol::SystemOne)),
        );

        self::assertEquals(
            RecommendationEngineCapabilitiesModel::of(RecommendationEngineKind::Scoring),
            $resolver->capabilitiesForAccount($account),
        );
    }

    public function testTheLlmKindWritesReasonsSendsAPromptAndReadsEveryTuningFieldInTheirOrder(): void
    {
        $capabilities = RecommendationEngineCapabilitiesModel::of(RecommendationEngineKind::Llm);

        self::assertTrue($capabilities->writesReasons);
        self::assertTrue($capabilities->sendsPrompt);
        self::assertSame(RecommendationProfileSource::Own, $capabilities->profileSource);
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

    public function testCapabilitiesAreTheConnectionsKindsAndBuildNoEngine(): void
    {
        $locator = $this->createMock(ContainerInterface::class);
        $locator->expects($this->never())->method('get');
        $locator->expects($this->never())->method('has');
        $resolver = new RecommendationEngineResolver($locator);

        self::assertEquals(
            RecommendationEngineCapabilitiesModel::of(RecommendationEngineKind::Llm),
            $resolver->capabilitiesFor($this->connection(new ModelDescriptor('gpt-4o-mini', null))),
        );
    }

    /** Reads as the LLM, the kind a connection without a model resolves to: the unconfigured payload stays as it was. */
    public function testAnAccountWithoutAnActiveConnectionReadsAsTheLlm(): void
    {
        $resolver = new RecommendationEngineResolver(new ServiceLocator([]));
        $account = new User('no-connection@example.test', new \DateTimeImmutable('2026-10-02 09:00:00'));

        self::assertEquals(
            RecommendationEngineCapabilitiesModel::of(RecommendationEngineKind::Llm),
            $resolver->capabilitiesForAccount($account),
        );
    }

    public function testTheEngineComesFromTheLocatorUnderItsKindsValue(): void
    {
        $engine = ScriptedRecommendationEngine::packing([]);
        $resolver = new RecommendationEngineResolver(new ServiceLocator(['llm' => static fn () => $engine]));

        self::assertSame($engine, $resolver->engineOf(RecommendationEngineKind::Llm));
    }

    public function testAKindWithoutAnEngineIsAWiringError(): void
    {
        $resolver = new RecommendationEngineResolver(new ServiceLocator([]));

        try {
            $resolver->engineOf(RecommendationEngineKind::Llm);
            self::fail('A missing engine must not resolve.');
        } catch (\LogicException $exception) {
            self::assertSame('No recommendation engine is wired for "llm".', $exception->getMessage());
            self::assertInstanceOf(NotFoundExceptionInterface::class, $exception->getPrevious());
        }
    }

    private function connection(ModelDescriptor $model): AiProviderSettings
    {
        $connection = AiProviderSettingsFactory::build(
            new User('engine-resolver@example.test', new \DateTimeImmutable('2026-10-02 09:00:00')),
        );
        $connection->chooseModel($model, new \DateTimeImmutable('2026-10-02 09:05:00'));

        return $connection;
    }
}
```

`backend/tests/Http/RecommendationCapabilitiesJsonTest.php` — whole file (red while `ofKind()` does not exist; the scoring connection's id names no Jev):

```php
<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Entity\ModelDescriptor;
use App\Entity\User;
use App\Enum\RecommendationEngineKind;
use App\Enum\ScoringProtocol;
use App\Tests\Support\AiProviderSettingsFactory;
use App\Tests\Support\RecommendationCapabilitiesJsons;
use PHPUnit\Framework\TestCase;

final class RecommendationCapabilitiesJsonTest extends TestCase
{
    public function testItNamesTheKindsTuningFieldsByTheirWireNamesInTheKindsOrder(): void
    {
        $connection = AiProviderSettingsFactory::build(
            new User('capabilities-json@example.test', new \DateTimeImmutable('2026-10-02 09:00:00')),
        );

        self::assertSame(
            RecommendationCapabilitiesJsons::LLM,
            RecommendationCapabilitiesJsons::ofTheKind()->of($connection),
        );
    }

    public function testAScoringConnectionReportsNoReasonsNoPromptAndOnlyTheBatchConcurrency(): void
    {
        $connection = AiProviderSettingsFactory::build(
            new User('capabilities-json-scoring@example.test', new \DateTimeImmutable('2026-10-02 09:00:00')),
        );
        $connection->chooseModel(
            new ModelDescriptor('acme/decider-2', 16_000, ScoringProtocol::SystemOne),
            new \DateTimeImmutable('2026-10-02 09:05:00'),
        );

        self::assertSame(
            RecommendationCapabilitiesJsons::SCORING,
            RecommendationCapabilitiesJsons::ofTheKind()->of($connection),
        );
    }

    public function testTheScoringKindReportsTheCapabilitiesAScoringConnectionHas(): void
    {
        self::assertSame(
            RecommendationCapabilitiesJsons::SCORING,
            RecommendationCapabilitiesJsons::ofTheKind()->ofKind(RecommendationEngineKind::Scoring),
        );
    }

    public function testTheLlmKindReportsTheLlmCapabilities(): void
    {
        self::assertSame(
            RecommendationCapabilitiesJsons::LLM,
            RecommendationCapabilitiesJsons::ofTheKind()->ofKind(RecommendationEngineKind::Llm),
        );
    }
}
```

`backend/tests/Http/AiSettingsJsonTest.php`:
- `json()` becomes `return new AiSettingsJson(RecommendationCapabilitiesJsons::ofTheKind());`; delete the now unused imports of `RecommendationEngineResolver` and `ServiceLocator`; add `use App\Enum\ScoringProtocol;` (`ModelDescriptor` is imported since Task 2).
- In the `added()` test, `['gpt-4o', 'gpt-4o-mini']` becomes `[new ModelDescriptor('gpt-4o', null), new ModelDescriptor('gpt-4o-mini', null)]`.
- Replace `testEachOfferedModelCarriesTheCapabilitiesItWouldGiveTheConnection` with (red while the label and capabilities are read from the id — both ids contradict the prefix rule):

```php
    /** The catalog's tag decides, never the id: `jev-router` is an LLM, `acme/decider-2` a System One model. */
    public function testEachOfferedModelCarriesTheLabelAndCapabilitiesOfItsTag(): void
    {
        self::assertSame(
            [
                'models' => [
                    ['id' => 'jev-router', 'label' => null, 'capabilities' => RecommendationCapabilitiesJsons::LLM],
                    [
                        'id' => 'acme/decider-2',
                        'label' => 'System One',
                        'capabilities' => RecommendationCapabilitiesJsons::SCORING,
                    ],
                ],
            ],
            $this->json()->models([
                new ModelDescriptor('jev-router', 128_000),
                new ModelDescriptor('acme/decider-2', 16_000, ScoringProtocol::SystemOne),
            ]),
        );
    }
```

`backend/tests/Support/AiConfigurationRequests.php`: add `use App\Entity\ModelDescriptor;` and widen `clientAnswering()`'s docblock to

```php
    /**
     * @param list<string|ModelDescriptor>|\Throwable
     *     |\Closure(ProviderCredentialsModel): list<string|ModelDescriptor> $models
     */
```

`backend/tests/Controller/Api/AiSettingsControllerTest.php`: add `use App\Entity\ModelDescriptor;` and `use App\Enum\ScoringProtocol;`; replace `testListingModelsMarksAJevModelByTheCapabilitiesItWouldGive` with (red while the configurator hands the mapper ids, which carry no tag):

```php
    public function testListingModelsMarksAScoringModelByTheLabelAndCapabilitiesItWouldGive(): void
    {
        $client = $this->clientAnswering([
            'gpt-4o',
            new ModelDescriptor('jev-latest', 32_000, ScoringProtocol::SystemOne),
        ]);
        $this->accountOn($client, 'ai-models-scoring@example.test');
        $id = $this->addConfiguration($client)['id'];
        self::assertIsInt($id);

        $client->request('GET', sprintf('/api/me/ai/configs/%d/models', $id));

        self::assertResponseIsSuccessful();
        self::assertSame(
            [
                'models' => [
                    ['id' => 'gpt-4o', 'label' => null, 'capabilities' => RecommendationCapabilitiesJsons::LLM],
                    [
                        'id' => 'jev-latest',
                        'label' => 'System One',
                        'capabilities' => RecommendationCapabilitiesJsons::SCORING,
                    ],
                ],
            ],
            $this->payload($client),
        );
    }
```

`backend/tests/Service/Recommendation/Llm/Run/SuppressedReasoningFallbackTest.php` (the connection no longer reads as scoring by its id): add `use App\Enum\ScoringProtocol;`; `connectionOn()` becomes

```php
    private function connectionOn(string $model, ?ScoringProtocol $protocol = null): AiProviderSettings
    {
        $connection = AiProviderSettingsFactory::build(
            new User('reader@example.test', new \DateTimeImmutable('2026-10-05 09:00:00')),
        );
        $connection->chooseModel(
            new ModelDescriptor($model, 32768, $protocol),
            new \DateTimeImmutable('2026-10-05 10:00:00'),
        );

        return $connection;
    }
```
and `testARejectionOnAnEngineWithoutASuppressReasoningSettingIsNotAbsorbed` calls `$this->connectionOn('jev-latest', ScoringProtocol::SystemOne)`.

`backend/tests/Service/Ai/AiProviderConfiguratorTest.php`: replace `        self::assertSame(['gpt-4o', 'gpt-4o-mini'], $added->modelIds);` with

```php
        self::assertSame(
            ['gpt-4o', 'gpt-4o-mini'],
            array_map(static fn (ModelDescriptor $model): string => $model->id, $added->models),
        );
```

- [ ] **Step 2: Run them to see them fail**

Run: `php bin/phpunit tests/Service/Recommendation/Engine tests/Http tests/Controller/Api/AiSettingsControllerTest.php tests/Service/Ai/AiProviderConfiguratorTest.php`
Expected: FAILs in `testTheStoredKindDecidesTheEngineNeverTheModelId` (both descriptor rows), errors for `ofKind()`, `$added->models` and the `AiSettingsJson` constructor, and the controller test FAILs on `'label' => 'Jev'`/the capabilities of `jev-latest`.

- [ ] **Step 3: The resolver reads the stored kind**

`backend/src/Service/Recommendation/Engine/RecommendationEngineResolver.php` — whole file:

```php
<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Engine;

use App\Entity\AiProviderSettings;
use App\Entity\User;
use App\Enum\RecommendationEngineKind;
use App\Service\Recommendation\Engine\Model\RecommendationEngineCapabilitiesModel;
use App\Service\Recommendation\Engine\RecommendationEngine\RecommendationEngineInterface;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireLocator;

/** The one place that decides which engine runs a connection's recommendations. */
final readonly class RecommendationEngineResolver
{
    private const RecommendationEngineKind DEFAULT_KIND = RecommendationEngineKind::Llm;

    public function __construct(
        #[AutowireLocator('app.recommendation_engine')]
        private ContainerInterface $engines,
    ) {
    }

    public function kindFor(AiProviderSettings $connection): RecommendationEngineKind
    {
        return $connection->getModelKind() ?? self::DEFAULT_KIND;
    }

    public function capabilitiesFor(AiProviderSettings $connection): RecommendationEngineCapabilitiesModel
    {
        return RecommendationEngineCapabilitiesModel::of($this->kindFor($connection));
    }

    /** An account with no active connection reads as the default kind, as a model-less connection does. */
    public function capabilitiesForAccount(User $user): RecommendationEngineCapabilitiesModel
    {
        $connection = $user->getActiveAiProviderSettings();

        return null === $connection
            ? RecommendationEngineCapabilitiesModel::of(self::DEFAULT_KIND)
            : $this->capabilitiesFor($connection);
    }

    public function engineOf(RecommendationEngineKind $kind): RecommendationEngineInterface
    {
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

- [ ] **Step 4: The model list carries the catalog's descriptors and the mappers read their tag**

`backend/src/Service/Ai/Model/AddedConfigurationModel.php` — whole file:

```php
<?php

declare(strict_types=1);

namespace App\Service\Ai\Model;

use App\Entity\AiProviderSettings;
use App\Entity\ModelDescriptor;

/** What addConfiguration() proves: the created row and the models offered, so choosing one needs no second listing. */
final readonly class AddedConfigurationModel
{
    /** @param list<ModelDescriptor> $models */
    public function __construct(
        /** @noinspection AutowireWrongClass Built with new, never autowired */
        public AiProviderSettings $configuration,
        public array $models,
    ) {
    }
}
```

`backend/src/Service/Ai/AiProviderConfigurator.php`: in `addConfiguration()` replace `        return new AddedConfigurationModel($configuration, $this->ids($descriptors));` with `        return new AddedConfigurationModel($configuration, $descriptors);`; replace `listModels()` with

```php
    /**
     * @return list<ModelDescriptor>
     */
    public function listModels(AiProviderSettings $settings): array
    {
        return $this->catalog->listModels($this->credentials($settings));
    }
```
and delete the private `ids()` method with its docblock.

`backend/src/Controller/Api/AiSettingsController.php`: `$added->modelIds` becomes `$added->models`.

`backend/src/Http/RecommendationCapabilitiesJson.php`: add `use App\Enum\RecommendationEngineKind;` and replace `ofModel()` with

```php
    /** @return array{reasons: bool, prompt: bool, profile: string, tuningFields: list<string>} */
    public function ofKind(RecommendationEngineKind $kind): array
    {
        return self::shape(RecommendationEngineCapabilitiesModel::of($kind));
    }
```

`backend/src/Http/AiSettingsJson.php` — whole file:

```php
<?php

declare(strict_types=1);

namespace App\Http;

use App\Entity\AiProviderSettings;
use App\Entity\ModelDescriptor;
use App\Entity\User;
use App\Enum\ScoringProtocol;
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
            'suppressionRefused' => $settings->refusesSuppressedReasoning(),
            'batchConcurrency' => $settings->getRunTuning()->batchConcurrency(),
            'slowModel' => $settings->isSlowModel(),
            'maxBatchSize' => $settings->getRunTuning()->maxBatchSize(),
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
     * @param list<ModelDescriptor> $models
     *
     * @return array<string, mixed>
     */
    public function added(AiProviderSettings $settings, array $models): array
    {
        return $this->configuration($settings, null) + $this->models($models);
    }

    /**
     * @param list<ModelDescriptor> $models
     *
     * @return array{models: list<array{id: string, label: ?string, capabilities: array<string, mixed>}>}
     */
    public function models(array $models): array
    {
        return [
            'models' => array_map(
                fn (ModelDescriptor $model): array => [
                    'id' => $model->id,
                    'label' => self::labelOf($model->scoringProtocol),
                    'capabilities' => $this->capabilities->ofKind($model->kind()),
                ],
                $models,
            ),
        ];
    }

    private static function labelOf(?ScoringProtocol $protocol): ?string
    {
        return match ($protocol) {
            ScoringProtocol::SystemOne => 'System One',
            null => null,
        };
    }
}
```

- [ ] **Step 5: The guide names the new label** (D8)

`frontend/public/i18n/en.json`, key `settings.ai.guide.jevStep3`: `“jev-latest · Jev”` becomes `“jev-latest · System One”`. `frontend/public/i18n/de.json`, same key: `„jev-latest · Jev“` becomes `„jev-latest · System One“`. Nothing else in the frontend changes: the wire keys are the same, and the Jest fixture that labels a model `'Jev'` tests the join of id and label, not the server's word.

- [ ] **Step 6: Run the tests to see them pass**

```bash
php bin/phpunit tests/Service/Recommendation tests/Http tests/Controller/Api tests/Service/Ai
git grep -n 'labelForModel\|capabilitiesForModel\|kindForModel\|JEV_MODEL_PREFIX\|ofModel(\|modelIds' -- src tests ../docs/recommendations-runs.md
```
Expected: `OK`; the grep prints only `docs/recommendations-runs.md`'s `labelForModel` mention (Task 9 rewrites it).

- [ ] **Step 7: Deletion checks** (paste each FAIL and the restored OK)

1. `src/Service/Recommendation/Engine/RecommendationEngineResolver.php`: `kindFor()` returns `self::DEFAULT_KIND` → `RecommendationEngineResolverTest` FAILs on the row "a scoring model whose id names no Jev".
2. `src/Http/AiSettingsJson.php`: `'label' => null` → `AiSettingsJsonTest::testEachOfferedModelCarriesTheLabelAndCapabilitiesOfItsTag` FAILs.
3. `src/Http/AiSettingsJson.php`: `ofKind(RecommendationEngineKind::Llm)` instead of `ofKind($model->kind())` (add the import for the check) → the same test FAILs on the capabilities.
4. `src/Service/Ai/AiProviderConfigurator.php`: `listModels()` returns `array_map(static fn (ModelDescriptor $model): ModelDescriptor => new ModelDescriptor($model->id, $model->contextWindow), …)` → `AiSettingsControllerTest::testListingModelsMarksAScoringModelByTheLabelAndCapabilitiesItWouldGive` FAILs.

- [ ] **Step 8: Gates**

Backend: `bin/console cache:warmup`, `composer check`, `composer md`, `php bin/phpunit`; PhpStorm `lint_files` on the changed `src` files. Frontend, from the repository root and alone: `docker compose exec -T frontend npm run check; echo "EXIT=$?"` → `EXIT=0`, the "Test Suites:" line as at Task 0.

- [ ] **Step 9: Commit**

```bash
git add -A src tests ../frontend/public/i18n/en.json ../frontend/public/i18n/de.json
git commit -m "refactor(#1395): the resolver reads the stored kind; models are labelled by the catalog's tag"
```

---

### Task 4: The engine-switch guard compares the protocol too

**Files:**
- Modify: `backend/src/Service/Recommendation/Run/TickPhases.php`
- Test: `backend/tests/Service/Recommendation/Run/TickPhasesTest.php`

**Interfaces:**
- Consumes: `RecommendationRun::getScoringProtocol()`, `TickContext::scoringProtocol()` (Task 2), `kindFor()` reading the stored kind (Task 3), `RecommendationRunFixtures::seedReadyScoringSettings()` (Task 2).
- Produces: `TickPhases::advance()` fails a running run with `TickPhases::ENGINE_SWITCH` when its recorded kind **or** protocol differs from the tick's (spec D8).

- [ ] **Step 1: Write the failing tests**

In `backend/tests/Service/Recommendation/Run/TickPhasesTest.php` add `use App\Enum\ScoringProtocol;`, replace `runningRun()` with

```php
    private function runningRun(): RecommendationRun
    {
        return $this->runningRunOn(RecommendationEngineKind::Llm, null);
    }

    private function runningRunOn(RecommendationEngineKind $kind, ?ScoringProtocol $protocol): RecommendationRun
    {
        $run = $this->fixtures->createRun($this->owner);
        $run->snapshot($kind, $protocol, [[101, 102]]);
        $this->entityManager->flush();

        return $run;
    }
```
and add (the first is red while the guard compares the kind alone; the second guards against a guard that fires on every scoring run):

```php
    /** One protocol exists today: a scoring run recorded without one stands in for a run of another protocol. */
    public function testARunPackedForAnotherScoringProtocolFailsWithoutBeingAdvanced(): void
    {
        $this->fixtures->seedReadyScoringSettings($this->owner);
        $engine = ScriptedRecommendationEngine::packing([]);
        $run = $this->runningRunOn(RecommendationEngineKind::Scoring, null);

        $report = $this->phases($engine)->advance($this->tick($run));

        self::assertSame('failed', $report->status);
        self::assertSame(TickPhases::ENGINE_SWITCH, $run->getError());
        self::assertSame([], $engine->advancedTicks);
    }

    public function testARunOnItsConnectionsScoringProtocolIsAdvanced(): void
    {
        $this->fixtures->seedReadyScoringSettings($this->owner);
        $engine = ScriptedRecommendationEngine::packing([]);
        $run = $this->runningRunOn(RecommendationEngineKind::Scoring, ScoringProtocol::SystemOne);

        $report = $this->phases($engine)->advance($this->tick($run));

        self::assertSame('running', $report->status);
        self::assertCount(1, $engine->advancedTicks);
    }
```

- [ ] **Step 2: Run them to see the first fail**

Run: `php bin/phpunit tests/Service/Recommendation/Run/TickPhasesTest.php`
Expected: `testARunPackedForAnotherScoringProtocolFailsWithoutBeingAdvanced` FAILs (`'running'` vs `'failed'`); the rest pass.

- [ ] **Step 3: The guard**

In `backend/src/Service/Recommendation/Run/TickPhases.php` add `use App\Entity\RecommendationRun;`, change the constant's docblock first sentence to "A run belongs to the engine and the scoring protocol whose batches it froze.", replace

```php
        if ($run->getEngineKind() !== $tick->engineKind) {
```
with

```php
        if (self::switchedEngines($run, $tick)) {
```
and add at the end of the class:

```php
    private static function switchedEngines(RecommendationRun $run, TickContext $tick): bool
    {
        return $run->getEngineKind() !== $tick->engineKind || $run->getScoringProtocol() !== $tick->scoringProtocol();
    }
```

- [ ] **Step 4: Run them to see them pass**

Run: `php bin/phpunit tests/Service/Recommendation/Run/TickPhasesTest.php tests/Service/Recommendation/Jev`
Expected: `OK`.

- [ ] **Step 5: Deletion checks**

1. `switchedEngines()` returns only `$run->getEngineKind() !== $tick->engineKind` → `testARunPackedForAnotherScoringProtocolFailsWithoutBeingAdvanced` FAILs.
2. Replace `$run->getScoringProtocol()` with `null` in `switchedEngines()` → `testARunOnItsConnectionsScoringProtocolIsAdvanced` FAILs.

- [ ] **Step 6: Gates and commit**

`bin/console cache:warmup`, `composer check`, `composer md`, `php bin/phpunit tests/Service/Recommendation`; PhpStorm `lint_files` on `TickPhases.php`.

```bash
git add src/Service/Recommendation/Run/TickPhases.php tests/Service/Recommendation/Run/TickPhasesTest.php
git commit -m "feat(#1395): a run whose connection changed its scoring protocol fails like an engine switch"
```

---

### Task 5: The scripted move `Jev` → `Scoring`

A pure move: names and namespaces change, no code. Lean gates (memory "renames get lean testing"), but every
verification below is binding, the namespace-and-import comparison included.

**Files:**
- Move (script, `moves.php`): the 20 classes under `backend/src/Service/Recommendation/Jev/` and the 12 tests under `backend/tests/Service/Recommendation/Jev/` to `…/Scoring/`, with spec D6's names.
- Modify (script): every tracked file naming a moved class — `backend/config/services.yaml:152`, `backend/config/services_test.yaml:28`, `backend/infection.json5:78`, `backend/tests/Support/StubSystemOneClient.php`, `backend/tests/Service/Recommendation/Engine/RecommendationEngineWiringTest.php`, and the moved files themselves.
- Modify (by hand): `backend/phpstan.dist.neon:22`, `backend/infection.json5:77` (comment), `docs/architecture.md` §9.

**Interfaces:**
- Consumes: Tasks 1–4 committed (the move's base is `HEAD`).
- Produces: the Task 0 map's names, among them `App\Service\Recommendation\Scoring\ScoringRecommendationEngine` (`NO_PROFILE`, tagged `'scoring'`), `ScoringBatchWave`, `ScoringBatchPacker`, `ScoreParser`, `Factory\ScoringStateFactory`, `Factory\SystemOneRequestFactory`, `Model\{ScoringOutcomeModel,ScoringReplyModel,ScoreParseResultModel,SystemOneRequestModel}`, `Pass\{ScoringWave,ResponseWave}`, `Support\{CompactJson,FittingPrefix,ProbabilityScore,QuestionId,ScoringArticle,SystemOneReplyDecoder}`, `SystemOneClient\{SystemOneClientInterface,HttpSystemOneClient}`; the sub-module `Recommendation\Scoring` in `serviceSubModules`.

- [ ] **Step 1: Move** (from `backend/`, clean tree)

```bash
git status --short
php ../docs/superpowers/plans/2026-10-05-1395-scripts/move-classes.php ../docs/superpowers/plans/2026-10-05-1395-scripts/moves.php
find src/Service/Recommendation/Jev tests/Service/Recommendation/Jev -type d -empty -delete
```
Expected: an empty status, then `Moved 32 classes (20 renamed); …` and exit 0. A `Failed:` collision line stops the script before any change: report it.

- [ ] **Step 2: Prove nothing but names and places moved**

```bash
php ../docs/superpowers/plans/2026-10-05-1395-scripts/compare-moves.php ../docs/superpowers/plans/2026-10-05-1395-scripts/moves.php HEAD
php ../docs/superpowers/plans/2026-10-05-1395-scripts/compare-import-lines.php ../docs/superpowers/plans/2026-10-05-1395-scripts/moves.php HEAD
php ../docs/superpowers/plans/2026-10-05-1395-scripts/psr4-namespaces.php
find src/Service/Recommendation/Scoring tests/Service/Recommendation/Scoring -name '*.php' | wc -l
ls src/Service/Recommendation/Jev tests/Service/Recommendation/Jev 2>&1 | head -2
```
Expected: `0 of 32 moved files differ in code.`, `0 of 32 moved files declare the wrong namespace.`; `0 files declare an unexpected namespace.` and `0 files import other classes than the map says.` (the whole tree moves together, so every import is exactly its old one through the map); the PSR-4 sweep at Task 0's baseline; `32`; "No such file or directory" twice. Base is `HEAD` (Task 4's commit), not `origin/develop`, because Tasks 1–4 edited files this move carries.

- [ ] **Step 3: Prove the import comparison catches what it guards** (paste both outputs)

```bash
cp src/Service/Recommendation/Scoring/ScoringBatchWave.php "$TMPDIR/ScoringBatchWave.php.orig"
cp src/Service/Recommendation/Scoring/Support/CompactJson.php "$TMPDIR/CompactJson.php.orig"
perl -pi -e 's/^use App\\Service\\Recommendation\\Scoring\\Pass\\ScoringWave;$/use App\\Service\\Recommendation\\Jev\\Pass\\JevWave;/' src/Service/Recommendation/Scoring/ScoringBatchWave.php
perl -pi -e 's/^namespace App\\Service\\Recommendation\\Scoring\\Support;$/namespace App\\Service\\Recommendation\\Jev\\Support;/' src/Service/Recommendation/Scoring/Support/CompactJson.php
php ../docs/superpowers/plans/2026-10-05-1395-scripts/compare-import-lines.php ../docs/superpowers/plans/2026-10-05-1395-scripts/moves.php HEAD
mv "$TMPDIR/ScoringBatchWave.php.orig" src/Service/Recommendation/Scoring/ScoringBatchWave.php
mv "$TMPDIR/CompactJson.php.orig" src/Service/Recommendation/Scoring/Support/CompactJson.php
php ../docs/superpowers/plans/2026-10-05-1395-scripts/compare-import-lines.php ../docs/superpowers/plans/2026-10-05-1395-scripts/moves.php HEAD
```
Expected: the first run names `src/Service/Recommendation/Scoring/Support/CompactJson.php declares namespace App\Service\Recommendation\Jev\Support, expected App\Service\Recommendation\Scoring\Support` and `src/Service/Recommendation/Scoring/ScoringBatchWave.php imports differ: missing [App\Service\Recommendation\Scoring\Pass\ScoringWave], unexpected [App\Service\Recommendation\Jev\Pass\JevWave]`, and counts `1` and `1`; the second run is back to `0` and `0`.

- [ ] **Step 4: What the script cannot rewrite**

`backend/phpstan.dist.neon`: `        - Recommendation\Jev` becomes `        - Recommendation\Scoring`.

`backend/infection.json5`: the comment `        // SystemOneWave::outcomes(): a settled wave …` becomes `        // ResponseWave::outcomes(): a settled wave …` (the `ignore` entry below it was rewritten by the script).

`docs/architecture.md` §9, three edits:
- "(#1344; today `Recommendation\Llm` and `Recommendation\Jev`)" → "(#1344; today `Recommendation\Llm` and `Recommendation\Scoring`)"
- "`Service/Recommendation/Jev` holds the System One engine the same way (`Recommendation\Jev → Recommendation → Ai`);" → "`Service/Recommendation/Scoring` holds the scoring engine the same way (`Recommendation\Scoring → Recommendation → Ai`);"
- "  and `Recommendation\Jev`; `Recommendation\Run\ProviderCallHeartbeat\…" → "  and `Recommendation\Scoring`; `Recommendation\Run\ProviderCallHeartbeat\…"

`CLAUDE.md` names no `Jev` module (its module list is `Fetch`, `Parser`, … `Mail`), so it stays as it is.

- [ ] **Step 5: Nothing stale is left**

```bash
php ../docs/superpowers/plans/2026-10-05-1395-scripts/stale-names.php ../docs/superpowers/plans/2026-10-05-1395-scripts/moves.php > var/moves-1395.stale
git -C .. grep -n -E \( -f backend/var/moves-1395.stale \) --and --not -e '^namespace ' -- . ':!docs/superpowers' ':!backend/tests/PhpStan'
git -C .. grep -n -E 'Recommendation\\{1,2}Jev|Recommendation/Jev|SystemOneWave|JevWave|JevArticle|NoulScore|SystemOneJson|NoulReplyParser|NoulParseResult|JevBatch|JevState|JevRecommendationEngine|SystemOneOutcomeModel|SystemOneReplyModel' -- . ':!docs/superpowers'
rm var/moves-1395.stale
```
Expected: the first grep prints nothing; the second prints only `docs/recommendations-runs.md:129` (Task 9 rewrites that section). Any other hit: rename it by hand to its new name and re-run.

- [ ] **Step 6: Gates**

```bash
bin/console cache:warmup
composer check
composer md
php bin/phpunit
```
Expected: green, `ServiceModuleCycleRule` and `ServiceRoleRule` included; phptramp at Task 0's warning count; the same PHPUnit test count as after Task 4 (nothing moved away). A role finding names the class's expected home: report it to the planner (a map error), do not edit the rule.

- [ ] **Step 7: Commit**

```bash
git add -A src tests config phpstan.dist.neon infection.json5 ../docs/architecture.md
git commit -m "refactor(#1395): the jev module becomes the scoring module"
```

---

### Task 6: A scoring reply is a score per entry

**Files:**
- Modify: `backend/src/Service/Recommendation/Scoring/Model/ScoringReplyModel.php`, `…/Scoring/Support/QuestionId.php`, `…/Scoring/Support/SystemOneReplyDecoder.php`, `…/Scoring/ScoreParser.php`, `…/Scoring/Support/ProbabilityScore.php`
- Test: `backend/tests/Service/Recommendation/Scoring/Support/QuestionIdTest.php` (new), `…/Scoring/Support/SystemOneReplyDecoderTest.php`, `…/Scoring/ScoreParserTest.php` (rewritten), `…/Scoring/Support/ProbabilityScoreTest.php` (rewritten), `…/Scoring/SystemOneClient/HttpSystemOneClientTest.php` (expected keys)

**Interfaces:**
- Consumes: Task 5's names.
- Produces:
  - `ScoringReplyModel(string $body, array<int, float> $scores, ProviderCallReceiptModel $receipt)` — `$scores` keyed by entry id (was `$nouls` keyed by question id).
  - `QuestionId::entryIdOf(string $questionId): ?int` — the entry a question id names, null for any id `QuestionId::of()` does not write.
  - `ScoreParser::parse(ScoringReplyModel $reply, list<int> $entryIds): ScoreParseResultModel` — unchanged signature, reads `$reply->scores[$entryId]`.
  - `ProbabilityScore::of(float $probability): int` — parameter renamed only.

- [ ] **Step 1: Write the failing tests**

`backend/tests/Service/Recommendation/Scoring/Support/QuestionIdTest.php` (red while `entryIdOf()` does not exist; later red if it parses an id it never wrote):

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Recommendation\Scoring\Support;

use App\Service\Recommendation\Scoring\Support\QuestionId;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class QuestionIdTest extends TestCase
{
    public function testAnEntrysQuestionIdNamesThatEntry(): void
    {
        self::assertSame('entry-731', QuestionId::of(731));
        self::assertSame(731, QuestionId::entryIdOf('entry-731'));
    }

    /** @return iterable<string, array{string}> */
    public static function idsNoQuestionCarries(): iterable
    {
        yield 'a padded number' => ['entry-0731'];
        yield 'another prefix' => ['item-731'];
        yield 'no number' => ['entry-'];
        yield 'a trailing letter' => ['entry-731a'];
    }

    #[DataProvider('idsNoQuestionCarries')]
    public function testAnIdNoQuestionCarriesNamesNoEntry(string $questionId): void
    {
        self::assertNull(QuestionId::entryIdOf($questionId));
    }
}
```

`backend/tests/Service/Recommendation/Scoring/Support/SystemOneReplyDecoderTest.php` — the expected keys become entry ids (D13): replace

```php
        self::assertSame(['entry-9' => 1.0], $reply->nouls);
        self::assertSame([], $listed->nouls);
```
with

```php
        self::assertSame([9 => 1.0], $reply->scores);
        self::assertSame([], $listed->scores);
```
and add (red while the decoder keys by the raw id; red later if it parses ids loosely — the padded twin comes second and would overwrite entry 7):

```php
    /** Only the ids the request wrote name an entry: a padded twin cannot overwrite the real answer. */
    public function testAnAnswerUnderAnIdNoQuestionCarriesIsLeftOut(): void
    {
        $reply = SystemOneReplyDecoder::decode(
            '{"answers":{"entry-7":{"type":"noul","noul":0.6},"entry-007":{"type":"noul","noul":0.4}}}',
            null,
        );

        self::assertSame([7 => 0.6], $reply->scores);
    }
```

`backend/tests/Service/Recommendation/Scoring/SystemOneClient/HttpSystemOneClientTest.php` — the same key change in every expectation (only the expected arrays carry `'entry-N' =>`; request ids and reply bodies stay):

```bash
perl -pi -e "s/'entry-(\d+)' => /\$1 => /g; s/->nouls\b/->scores/g" tests/Service/Recommendation/Scoring/SystemOneClient/HttpSystemOneClientTest.php
git diff --stat -- tests/Service/Recommendation/Scoring/SystemOneClient/HttpSystemOneClientTest.php
```
Expected: 6 lines changed (the six `->nouls` assertions).

`backend/tests/Service/Recommendation/Scoring/ScoreParserTest.php` — whole file (red while the parser looks scores up by question id):

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Recommendation\Scoring;

use App\Service\Ai\Model\ProviderCallReceiptModel;
use App\Service\Recommendation\Scoring\Model\ScoringReplyModel;
use App\Service\Recommendation\Scoring\ScoreParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ScoreParserTest extends TestCase
{
    public function testEveryCandidateIsAWinnerWithItsScoreAndNoReasonInTheBatchsOrder(): void
    {
        $result = (new ScoreParser())->parse($this->reply([9 => 0.31, 4 => 0.875]), [4, 9]);

        self::assertTrue($result->usable);
        self::assertSame(
            [['id' => 4, 'score' => 875, 'reason' => ''], ['id' => 9, 'score' => 310, 'reason' => '']],
            $result->winners,
        );
    }

    public function testAReplyMissingACandidatesScoreIsUnusable(): void
    {
        $result = (new ScoreParser())->parse($this->reply([4 => 0.875]), [4, 9]);

        self::assertFalse($result->usable);
        self::assertSame([], $result->winners);
    }

    /** @return iterable<string, array{float}> */
    public static function impossibleScores(): iterable
    {
        yield 'above one' => [1.2];
        yield 'below zero' => [-0.1];
        yield 'not a number' => [\NAN];
        yield 'infinite' => [\INF];
    }

    #[DataProvider('impossibleScores')]
    public function testAScoreThatIsNoProbabilityMakesTheReplyUnusable(float $score): void
    {
        $result = (new ScoreParser())->parse($this->reply([4 => 0.875, 9 => $score]), [4, 9]);

        self::assertFalse($result->usable);
        self::assertSame([], $result->winners);
    }

    public function testTheBoundsOfAProbabilityAreUsable(): void
    {
        $result = (new ScoreParser())->parse($this->reply([4 => 0.0, 9 => 1.0]), [4, 9]);

        self::assertTrue($result->usable);
        self::assertSame(
            [['id' => 4, 'score' => 0, 'reason' => ''], ['id' => 9, 'score' => 1000, 'reason' => '']],
            $result->winners,
        );
    }

    /** @param array<int, float> $scores */
    private function reply(array $scores): ScoringReplyModel
    {
        return new ScoringReplyModel('{}', $scores, new ProviderCallReceiptModel(null, null, null));
    }
}
```

`backend/tests/Service/Recommendation/Scoring/Support/ProbabilityScoreTest.php` — whole file (the values are the old `NoulScoreTest`'s):

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Recommendation\Scoring\Support;

use App\Service\Recommendation\Scoring\Support\ProbabilityScore;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ProbabilityScoreTest extends TestCase
{
    /** @return iterable<string, array{float, int}> */
    public static function probabilities(): iterable
    {
        yield 'rounded, not floored' => [0.0737, 74];
        yield 'rounded, not ceiled' => [0.0731, 73];
        yield 'certain yes' => [1.0, 1000];
        yield 'above one is clamped' => [1.2, 1000];
        yield 'below zero is clamped' => [-0.1, 0];
    }

    #[DataProvider('probabilities')]
    public function testAProbabilityIsAScoreOnTheRunsScale(float $probability, int $score): void
    {
        self::assertSame($score, ProbabilityScore::of($probability));
    }
}
```

- [ ] **Step 2: Run them to see them fail**

Run: `php bin/phpunit tests/Service/Recommendation/Scoring`
Expected: `QuestionIdTest` errors (`Call to undefined method …QuestionId::entryIdOf()`), the decoder, parser and client tests FAIL on the keys (`Undefined property …ScoringReplyModel::$scores` or `'entry-9'` vs `9`).

- [ ] **Step 3: The reply is a score per entry**

`backend/src/Service/Recommendation/Scoring/Model/ScoringReplyModel.php` — whole file:

```php
<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Scoring\Model;

use App\Service\Ai\Model\ProviderCallReceiptModel;

final readonly class ScoringReplyModel
{
    /** @param array<int, float> $scores entry id => the model's value for it, only the answers that carry a number */
    public function __construct(
        public string $body,
        public array $scores,
        public ProviderCallReceiptModel $receipt,
    ) {
    }
}
```

`backend/src/Service/Recommendation/Scoring/Support/QuestionId.php` — whole file:

```php
<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Scoring\Support;

/** The key a candidate's question travels under: a string, so `questions` encodes as a JSON object, never a list. */
final class QuestionId
{
    private const string PREFIX = 'entry-';

    public static function of(int $entryId): string
    {
        return self::PREFIX . $entryId;
    }

    public static function entryIdOf(string $questionId): ?int
    {
        $entryId = (int) substr($questionId, \strlen(self::PREFIX));

        return self::of($entryId) === $questionId ? $entryId : null;
    }

    private function __construct()
    {
    }
}
```

`backend/src/Service/Recommendation/Scoring/Support/SystemOneReplyDecoder.php`: the class docblock's "decodes to no Nouls" becomes "decodes to no scores"; in `decode()` construct `new ScoringReplyModel(` with `self::scoresIn($root['answers'] ?? null),`; replace `noulsIn()` with

```php
    /** @return array<int, float> each answered question's Noul, by the entry its id names */
    private static function scoresIn(mixed $answers): array
    {
        if (!\is_array($answers)) {
            return [];
        }

        $scores = [];
        foreach ($answers as $questionId => $answer) {
            $entryId = \is_string($questionId) ? QuestionId::entryIdOf($questionId) : null;
            $noul = \is_array($answer) ? ($answer['noul'] ?? null) : null;
            if (null !== $entryId && (\is_float($noul) || \is_int($noul))) {
                $scores[$entryId] = (float) $noul;
            }
        }

        return $scores;
    }
```
(`QuestionId` is in the decoder's own namespace: no import.)

`backend/src/Service/Recommendation/Scoring/ScoreParser.php` — whole file:

```php
<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Scoring;

use App\Service\Recommendation\Scoring\Model\ScoreParseResultModel;
use App\Service\Recommendation\Scoring\Model\ScoringReplyModel;
use App\Service\Recommendation\Scoring\Support\ProbabilityScore;

/** A batch's scores as winners without reasons; one candidate without a probability spoils the whole reply. */
final readonly class ScoreParser
{
    /** @param list<int> $entryIds the batch's candidates, in snapshot order */
    public function parse(ScoringReplyModel $reply, array $entryIds): ScoreParseResultModel
    {
        $winners = [];
        foreach ($entryIds as $entryId) {
            $score = $reply->scores[$entryId] ?? null;
            if (!self::isProbability($score)) {
                return ScoreParseResultModel::unusable();
            }
            $winners[] = ['id' => $entryId, 'score' => ProbabilityScore::of($score), 'reason' => ''];
        }

        return ScoreParseResultModel::usable($winners);
    }

    /** @phpstan-assert-if-true float $score */
    private static function isProbability(?float $score): bool
    {
        return null !== $score && $score >= 0.0 && $score <= 1.0;
    }
}
```

`backend/src/Service/Recommendation/Scoring/Support/ProbabilityScore.php` — whole file:

```php
<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Scoring\Support;

/** A probability on the run's 0–1000 score scale; a value outside 0–1 is clamped, never trusted. */
final class ProbabilityScore
{
    private const int SCALE = 1000;

    public static function of(float $probability): int
    {
        return max(0, min(self::SCALE, (int) round($probability * self::SCALE)));
    }

    private function __construct()
    {
    }
}
```

- [ ] **Step 4: Run them to see them pass**

Run: `php bin/phpunit tests/Service/Recommendation/Scoring`
Expected: `OK` — `ScoringPipelineTest` and `ScoringRecommendationEngineTest` included, unchanged.

- [ ] **Step 5: Deletion checks**

1. `QuestionId::entryIdOf()`: return `$entryId` without the round trip → `QuestionIdTest` FAILs on "a padded number" and "a trailing letter".
2. `SystemOneReplyDecoder::scoresIn()`: replace `QuestionId::entryIdOf($questionId)` with `(int) substr($questionId, 6)` → `testAnAnswerUnderAnIdNoQuestionCarriesIsLeftOut` FAILs (`0.4` overwrote `0.6`).
3. `ScoreParser::parse()`: look up `$reply->scores[$entryId + 1]` → `ScoreParserTest::testEveryCandidateIsAWinnerWithItsScoreAndNoReasonInTheBatchsOrder` FAILs.

- [ ] **Step 6: Gates and commit**

`bin/console cache:warmup`, `composer check`, `composer md`, `php bin/phpunit tests/Service/Recommendation`; PhpStorm `lint_files` on the five `src` files.

```bash
git add -A src tests
git commit -m "refactor(#1395): a scoring reply carries a score per entry"
```

---

### Task 7: The protocol seam

**Files:**
- Create: `backend/src/Service/Recommendation/Scoring/ScoringProtocol/ScoringProtocolInterface.php`, `…/Scoring/ScoringProtocol/SystemOneProtocol.php`, `…/Scoring/ScoringProtocolResolver.php`, `…/Scoring/Model/{ScoringBudgetModel,ScoringReaderModel,ScoringRequestModel}.php`, `…/Scoring/Factory/ScoringBudgetFactory.php`
- Rewrite: `…/Scoring/ScoringBatchPacker.php`, `…/Scoring/Factory/ScoringStateFactory.php`, `…/Scoring/Factory/SystemOneRequestFactory.php`, `…/Scoring/Pass/ScoringWave.php`, `…/Scoring/ScoringBatchWave.php`, `…/Scoring/ScoringRecommendationEngine.php`
- Modify: `backend/src/Service/Recommendation/Run/Pass/TickContext.php`
- Test: move `backend/tests/Service/Recommendation/Scoring/ScoringBatchPackerTest.php` → `…/Scoring/ScoringProtocol/SystemOneProtocolTest.php` (rewritten); create `…/Scoring/ScoringBatchPackerTest.php`, `…/Scoring/ScoringProtocolResolverTest.php`, `…/Scoring/ScoringProtocolWiringTest.php`, `…/Scoring/Model/ScoringBudgetModelTest.php`, `…/Scoring/Factory/ScoringBudgetFactoryTest.php`; rewrite `…/Scoring/Factory/{ScoringStateFactoryTest,SystemOneRequestFactoryTest}.php`; modify `backend/tests/Service/Recommendation/Run/Pass/TickContextTest.php`, `backend/tests/Service/Recommendation/Engine/RecommendationEngineWiringTest.php`

**Interfaces:**
- Consumes: Task 5's names; `ScoringReplyModel::$scores` (Task 6); `TickContext::scoringProtocol()`, `RecommendationRun::isMissingBorrowedProfile()` (Task 2); `SystemOneClientInterface::evaluateMany(ProviderCredentialsModel, non-empty-list<SystemOneRequestModel>): list<ScoringOutcomeModel>` (unchanged).
- Produces:
  - `ScoringProtocolInterface` (tag `app.scoring_protocol`): `pack(ScoringBudgetModel $budget, list<ArticleLineModel> $candidates): list<list<int>>`, `renderedRequest(ScoringRequestModel $request): string`, `scoreMany(ProviderCredentialsModel $credentials, non-empty-list<ScoringRequestModel> $requests): list<ScoringOutcomeModel>`.
  - `SystemOneProtocol` (indexed `'system_one'`), `ScoringProtocolResolver::protocolOf(ScoringProtocol): ScoringProtocolInterface`.
  - `ScoringBudgetModel(int $contextWindowTokens, int $maxItemsPerRequest, int $stateTokens, int $framingTokens)` with `itemTokens(): int`; `ScoringBudgetFactory::create(ScoringProtocol): ScoringBudgetModel`.
  - `ScoringReaderModel(string $profile, ?string $guidance, list<ArticleLineModel> $favorites)`, `ScoringRequestModel(string $model, ScoringReaderModel $reader, ScoringBudgetModel $budget, list<ArticleLineModel> $articles)`.
  - `ScoringBatchPacker::pack(list<ArticleLineModel> $candidates, ScoringBudgetModel $budget, \Closure(ArticleLineModel): int $itemTokens): list<list<int>>`.
  - `ScoringStateFactory::create(ScoringReaderModel $reader, int $tokenBudget): array` (the same `{profile, guidance?, favorites?}` as before).
  - `SystemOneRequestFactory::__construct(ScoringStateFactory)`, `create(ScoringRequestModel): SystemOneRequestModel`, `question(ArticleLineModel): array` (unchanged).
  - `ScoringWave(TickContext $tick, ScoringReaderModel $reader, list<WaveBatchModel> $batches)`.
  - `TickContext::requireScoringProtocol(): ScoringProtocol` (throws `\LogicException` for a connection without one).

- [ ] **Step 1: Move the packer test to the protocol, where its assertions now live**

```bash
mkdir -p tests/Service/Recommendation/Scoring/ScoringProtocol
git mv tests/Service/Recommendation/Scoring/ScoringBatchPackerTest.php tests/Service/Recommendation/Scoring/ScoringProtocol/SystemOneProtocolTest.php
```

- [ ] **Step 2: Write the failing tests**

`backend/tests/Service/Recommendation/Scoring/ScoringProtocol/SystemOneProtocolTest.php` — whole file. Its two packing tests keep the old `JevBatchPackerTest` assertions (`[100, 100, 50]`, `[27, 27, 27, 27, 12]`): System One packs exactly as before. Red while `SystemOneProtocol` does not exist; later red if it measures questions differently, renders another body or drops a request.

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Recommendation\Scoring\ScoringProtocol;

use App\Enum\ScoringProtocol;
use App\Service\Ai\Model\ProviderCredentialsModel;
use App\Service\Recommendation\Pool\Model\ArticleLineModel;
use App\Service\Recommendation\Scoring\Factory\ScoringBudgetFactory;
use App\Service\Recommendation\Scoring\Factory\ScoringStateFactory;
use App\Service\Recommendation\Scoring\Factory\SystemOneRequestFactory;
use App\Service\Recommendation\Scoring\Model\ScoringBudgetModel;
use App\Service\Recommendation\Scoring\Model\ScoringReaderModel;
use App\Service\Recommendation\Scoring\Model\ScoringRequestModel;
use App\Service\Recommendation\Scoring\Model\SystemOneRequestModel;
use App\Service\Recommendation\Scoring\ScoringBatchPacker;
use App\Service\Recommendation\Scoring\ScoringProtocol\SystemOneProtocol;
use App\Service\Recommendation\Scoring\Support\CompactJson;
use App\Service\Recommendation\Support\TokenEstimate;
use App\Tests\Support\StubSystemOneClient;
use PHPUnit\Framework\TestCase;

final class SystemOneProtocolTest extends TestCase
{
    /** Short Latin articles fit the token budget by far: the question cap closes each request. */
    public function testShortArticlesFillRequestsUpToTheQuestionCapInPoolOrder(): void
    {
        $candidates = $this->candidates(250, 'Short title', 'Short description.');

        $batches = $this->protocol(new StubSystemOneClient())->pack(self::budget(), $candidates);

        self::assertSame([100, 100, 50], array_map(\count(...), $batches));
        self::assertSame(range(1, 250), array_merge(...$batches));
    }

    /**
     * Three-byte characters in every field, 731 tokens a question: 20,000 tokens (32k less 2k framing and 10k state)
     * hold 27 of them, so the token budget closes each request before the question cap.
     */
    public function testHeavyArticlesFillRequestsUpToTheTokenBudget(): void
    {
        $candidates = $this->candidates(120, str_repeat('漢', 300), str_repeat('漢', 600));

        $batches = $this->protocol(new StubSystemOneClient())->pack(self::budget(), $candidates);

        self::assertSame([27, 27, 27, 27, 12], array_map(\count(...), $batches));
        self::assertSame(range(1, 120), array_merge(...$batches));
        $factory = self::requestFactory();
        foreach ($batches as $batch) {
            $tokens = 0;
            foreach ($batch as $entryId) {
                $tokens += TokenEstimate::of(CompactJson::encode($factory->question($candidates[$entryId - 1])));
            }
            self::assertLessThanOrEqual(20_000, $tokens);
        }
    }

    public function testTheRunLogGetsTheSystemOneBodyPrettyPrinted(): void
    {
        $request = self::request([new ArticleLineModel(41, 'Kernel 6.18', 'LWN', '2026-10-01', null)]);

        self::assertSame(
            self::requestFactory()->create($request)->toRenderedRequest(),
            $this->protocol(new StubSystemOneClient())->renderedRequest($request),
        );
    }

    public function testEveryRequestIsAskedAsItsSystemOneRequestAndAnsweredInItsPlace(): void
    {
        $client = new StubSystemOneClient();
        $client->queueNouls(static fn (int $entryId): float => 0.25);
        $client->queueNouls(static fn (int $entryId): float => 0.75);

        $outcomes = $this->protocol($client)->scoreMany(
            ProviderCredentialsModel::fromStoredConfiguration('https://api.typesafe.test/v1', 'sk-jev'),
            [self::request([self::article(41)]), self::request([self::article(7), self::article(9)])],
        );

        self::assertSame(
            [['entry-41'], ['entry-7', 'entry-9']],
            array_map(
                static fn (SystemOneRequestModel $request): array => array_keys($request->questions),
                $client->requests(),
            ),
        );
        self::assertSame([41 => 0.25], $outcomes[0]->reply()->scores);
        self::assertSame([7 => 0.75, 9 => 0.75], $outcomes[1]->reply()->scores);
    }

    private function protocol(StubSystemOneClient $client): SystemOneProtocol
    {
        return new SystemOneProtocol(new ScoringBatchPacker(), self::requestFactory(), $client);
    }

    private static function requestFactory(): SystemOneRequestFactory
    {
        return new SystemOneRequestFactory(new ScoringStateFactory());
    }

    private static function budget(): ScoringBudgetModel
    {
        return (new ScoringBudgetFactory())->create(ScoringProtocol::SystemOne);
    }

    /** @param list<ArticleLineModel> $articles */
    private static function request(array $articles): ScoringRequestModel
    {
        return new ScoringRequestModel(
            'jev-latest',
            new ScoringReaderModel('Likes Rust.', null, []),
            self::budget(),
            $articles,
        );
    }

    private static function article(int $entryId): ArticleLineModel
    {
        return new ArticleLineModel($entryId, 'Title ' . $entryId, 'Feed', '2026-10-01', null);
    }

    /** @return list<ArticleLineModel> entry ids 1…$count */
    private function candidates(int $count, string $title, string $description): array
    {
        return array_map(
            static fn (int $entryId): ArticleLineModel
                => new ArticleLineModel($entryId, $title, 'Feed', '2026-10-01', $description),
            range(1, $count),
        );
    }
}
```

`backend/tests/Service/Recommendation/Scoring/ScoringBatchPackerTest.php` (new; red while the packer takes no measure; red later if a request closes before the token budget is exceeded, or ignores the item cap):

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Recommendation\Scoring;

use App\Service\Recommendation\Pool\Model\ArticleLineModel;
use App\Service\Recommendation\Scoring\Model\ScoringBudgetModel;
use App\Service\Recommendation\Scoring\ScoringBatchPacker;
use PHPUnit\Framework\TestCase;

final class ScoringBatchPackerTest extends TestCase
{
    /**
     * 14 tokens of items, at most 3 a request: 1 and 2 fill it to the token, 3 to 5 stop at the cap with tokens to
     * spare, and 7, over the budget by itself, still gets a request of its own.
     */
    public function testARequestClosesAtTheItemCapOrBeforeTheArticleThatWouldExceedItsTokens(): void
    {
        $tokens = [1 => 5, 2 => 9, 3 => 3, 4 => 8, 5 => 1, 6 => 2, 7 => 20];
        $candidates = array_map(
            static fn (int $entryId): ArticleLineModel
                => new ArticleLineModel($entryId, 'Title', 'Feed', '2026-10-01', null),
            array_keys($tokens),
        );

        $batches = (new ScoringBatchPacker())->pack(
            $candidates,
            new ScoringBudgetModel(20, 3, 4, 2),
            static fn (ArticleLineModel $candidate): int => $tokens[$candidate->entryId]
                ?? throw new \LogicException('Every candidate has its tokens.'),
        );

        self::assertSame([[1, 2], [3, 4, 5], [6], [7]], $batches);
    }
}
```

`backend/tests/Service/Recommendation/Scoring/Model/ScoringBudgetModelTest.php` (red if the framing or the state is added instead of taken off):

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Recommendation\Scoring\Model;

use App\Service\Recommendation\Scoring\Model\ScoringBudgetModel;
use PHPUnit\Framework\TestCase;

final class ScoringBudgetModelTest extends TestCase
{
    public function testTheItemsGetTheWindowLessTheFramingAndTheState(): void
    {
        self::assertSame(6_800, (new ScoringBudgetModel(9_000, 7, 1_500, 700))->itemTokens());
    }
}
```

`backend/tests/Service/Recommendation/Scoring/Factory/ScoringBudgetFactoryTest.php` (pins this PR's values, D9; red if any of the four moves):

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Recommendation\Scoring\Factory;

use App\Enum\ScoringProtocol;
use App\Service\Recommendation\Scoring\Factory\ScoringBudgetFactory;
use App\Service\Recommendation\Scoring\Model\ScoringBudgetModel;
use PHPUnit\Framework\TestCase;

final class ScoringBudgetFactoryTest extends TestCase
{
    /** Jev's budget before #1395: 20,000 tokens of questions, at most 100 of them. */
    public function testSystemOneKeepsTheBudgetJevHad(): void
    {
        $budget = (new ScoringBudgetFactory())->create(ScoringProtocol::SystemOne);

        self::assertEquals(new ScoringBudgetModel(32_000, 100, 10_000, 2_000), $budget);
        self::assertSame(20_000, $budget->itemTokens());
    }
}
```

`backend/tests/Service/Recommendation/Scoring/ScoringProtocolResolverTest.php` (red while the resolver does not exist; red later if it keys by anything but the enum's value):

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Recommendation\Scoring;

use App\Enum\ScoringProtocol;
use App\Service\Recommendation\Scoring\Factory\ScoringStateFactory;
use App\Service\Recommendation\Scoring\Factory\SystemOneRequestFactory;
use App\Service\Recommendation\Scoring\ScoringBatchPacker;
use App\Service\Recommendation\Scoring\ScoringProtocol\SystemOneProtocol;
use App\Service\Recommendation\Scoring\ScoringProtocolResolver;
use App\Tests\Support\StubSystemOneClient;
use PHPUnit\Framework\TestCase;
use Psr\Container\NotFoundExceptionInterface;
use Symfony\Component\DependencyInjection\ServiceLocator;

final class ScoringProtocolResolverTest extends TestCase
{
    public function testTheImplementationComesFromTheLocatorUnderItsProtocolsValue(): void
    {
        $systemOne = new SystemOneProtocol(
            new ScoringBatchPacker(),
            new SystemOneRequestFactory(new ScoringStateFactory()),
            new StubSystemOneClient(),
        );
        $resolver = new ScoringProtocolResolver(
            new ServiceLocator(['system_one' => static fn (): SystemOneProtocol => $systemOne]),
        );

        self::assertSame($systemOne, $resolver->protocolOf(ScoringProtocol::SystemOne));
    }

    public function testAProtocolWithoutAnImplementationIsAWiringError(): void
    {
        $resolver = new ScoringProtocolResolver(new ServiceLocator([]));

        try {
            $resolver->protocolOf(ScoringProtocol::SystemOne);
            self::fail('A missing protocol must not resolve.');
        } catch (\LogicException $exception) {
            self::assertSame('No scoring protocol is wired for "system_one".', $exception->getMessage());
            self::assertInstanceOf(NotFoundExceptionInterface::class, $exception->getPrevious());
        }
    }
}
```

`backend/tests/Service/Recommendation/Scoring/ScoringProtocolWiringTest.php` (spec Testing: every `ScoringProtocol` case has an implementation; red if `SystemOneProtocol` loses its `#[AsTaggedItem]` or the interface its tag):

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Recommendation\Scoring;

use App\Enum\ScoringProtocol;
use App\Service\Recommendation\Scoring\ScoringProtocol\SystemOneProtocol;
use App\Service\Recommendation\Scoring\ScoringProtocolResolver;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/** The resolver's unit tests hand-build the locator; only the compiled container proves the tag and the index. */
final class ScoringProtocolWiringTest extends KernelTestCase
{
    public function testEveryScoringProtocolHasItsImplementation(): void
    {
        self::bootKernel();
        $protocols = self::getContainer()->get(ScoringProtocolResolver::class);
        self::assertInstanceOf(ScoringProtocolResolver::class, $protocols);

        $wired = [];
        foreach (ScoringProtocol::cases() as $protocol) {
            $implementation = $protocols->protocolOf($protocol);
            $wired[$protocol->value] = $implementation::class;
        }

        self::assertSame(['system_one' => SystemOneProtocol::class], $wired);
    }
}
```

`backend/tests/Service/Recommendation/Engine/RecommendationEngineWiringTest.php` — add `use App\Enum\RecommendationEngineKind;` and (spec Testing: every kind has an engine; red if an engine loses its index):

```php
    public function testEveryEngineKindHasItsEngine(): void
    {
        self::bootKernel();
        $resolver = self::getContainer()->get(RecommendationEngineResolver::class);
        self::assertInstanceOf(RecommendationEngineResolver::class, $resolver);

        $wired = [];
        foreach (RecommendationEngineKind::cases() as $kind) {
            $engine = $resolver->engineOf($kind);
            $wired[$kind->value] = $engine::class;
        }

        self::assertSame(
            ['llm' => LlmRecommendationEngine::class, 'scoring' => ScoringRecommendationEngine::class],
            $wired,
        );
    }
```

`backend/tests/Service/Recommendation/Run/Pass/TickContextTest.php` — add `use App\Entity\ModelDescriptor;`, `use App\Enum\ScoringProtocol;` and (red while the method does not exist; later red if it reads anything but the connection):

```php
    public function testAScoringConnectionsTickNamesItsProtocol(): void
    {
        $connection = $this->connection();
        $connection->chooseModel(
            new ModelDescriptor('acme/decider-2', 16_000, ScoringProtocol::SystemOne),
            new \DateTimeImmutable(self::AT),
        );

        self::assertSame(
            ScoringProtocol::SystemOne,
            $this->tick($connection, TickDriver::Worker)->requireScoringProtocol(),
        );
    }

    public function testAnLlmConnectionsTickHasNoProtocolToRequire(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('This tick\'s connection speaks no scoring protocol.');

        $this->tick($this->connection(), TickDriver::Worker)->requireScoringProtocol();
    }
```

`backend/tests/Service/Recommendation/Scoring/Factory/ScoringStateFactoryTest.php` — whole file. Every assertion of the old `JevStateFactoryTest` stays, at the budget System One uses (`self::BUDGET`, 10,000); the last test is new (red while the factory reads a constant instead of the budget it is given):

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Recommendation\Scoring\Factory;

use App\Service\Recommendation\Pool\Model\ArticleLineModel;
use App\Service\Recommendation\Scoring\Factory\ScoringStateFactory;
use App\Service\Recommendation\Scoring\Model\ScoringReaderModel;
use App\Service\Recommendation\Scoring\Support\CompactJson;
use App\Service\Recommendation\Scoring\Support\ScoringArticle;
use App\Service\Recommendation\Support\TokenEstimate;
use PHPUnit\Framework\TestCase;

final class ScoringStateFactoryTest extends TestCase
{
    private const int BUDGET = 10_000;

    public function testTheStateIsTheProfileThenTheGuidance(): void
    {
        $state = self::state('Likes Rust and homelab posts.', 'More self-hosting, less crypto.', []);

        self::assertSame(
            ['profile' => 'Likes Rust and homelab posts.', 'guidance' => 'More self-hosting, less crypto.'],
            $state,
        );
    }

    public function testWithoutGuidanceTheStateIsTheProfileAlone(): void
    {
        self::assertSame(['profile' => 'Likes Rust.'], self::state('Likes Rust.', null, []));
    }

    /** The guidance stays whole; the profile is cut to exactly what is left: one more character would not fit. */
    public function testAnOverlongProfileIsCutToTheBudgetBesideTheWholeGuidance(): void
    {
        $state = self::state(str_repeat('p', 60_000), 'More self-hosting.', []);

        self::assertSame('More self-hosting.', $state['guidance'] ?? null);
        self::assertLessThanOrEqual(self::BUDGET, self::tokensOf($state));
        self::assertGreaterThan(self::BUDGET, self::tokensOf(['profile' => $state['profile'] . 'p'] + $state));
    }

    /**
     * 4-byte characters: 12 000 of them are 48 000 bytes, over the budget by themselves. The guidance is cut too, and
     * the profile keeps only the bytes the estimate's rounding leaves, less than one token.
     */
    public function testAGuidanceOverTheBudgetByItselfIsCutAndLeavesTheProfileNoWholeToken(): void
    {
        $state = self::state('Likes Rust.', str_repeat('😀', 12_000), []);

        self::assertTrue(str_starts_with('Likes Rust.', $state['profile']));
        self::assertLessThan(4, \strlen($state['profile']));
        self::assertLessThan(12_000, mb_strlen($state['guidance'] ?? ''));
        self::assertLessThanOrEqual(self::BUDGET, self::tokensOf($state));
    }

    public function testInvalidByteSequencesAreScrubbedFromBoth(): void
    {
        $state = self::state("Likes \xC3 Rust.", "More \xFF homelab.", []);

        self::assertTrue(mb_check_encoding($state['profile'], 'UTF-8'));
        self::assertTrue(mb_check_encoding($state['guidance'] ?? '', 'UTF-8'));
        self::assertStringStartsWith('Likes ', $state['profile']);
    }

    public function testFavoritesFollowTheProfileAndGuidanceInTheCandidateShape(): void
    {
        $favorites = [self::favorite(1, 'Rust 2.0'), self::favorite(2, 'Homelab tour')];

        $state = self::state('Likes Rust.', 'More homelab.', $favorites);

        self::assertSame(['profile', 'guidance', 'favorites'], array_keys($state));
        self::assertSame(array_map(ScoringArticle::of(...), $favorites), $state['favorites'] ?? null);
    }

    /** Profile and guidance stay whole; the newest favorites fill what is left, whole, and the next would not fit. */
    public function testFavoritesFillOnlyTheBudgetTheProfileAndGuidanceLeave(): void
    {
        $favorites = array_map(
            static fn (int $index): ArticleLineModel => self::favorite($index, 'Favorite ' . $index),
            range(1, 200),
        );

        $state = self::state('Likes Rust.', 'More homelab.', $favorites);

        self::assertSame('Likes Rust.', $state['profile']);
        self::assertSame('More homelab.', $state['guidance'] ?? null);
        $kept = \count($state['favorites'] ?? []);
        self::assertGreaterThan(0, $kept);
        self::assertLessThan(200, $kept);
        self::assertSame(
            array_map(ScoringArticle::of(...), \array_slice($favorites, 0, $kept)),
            $state['favorites'] ?? null,
        );
        self::assertLessThanOrEqual(self::BUDGET, self::tokensOf($state));
        $oneMore = ['favorites' => array_map(ScoringArticle::of(...), \array_slice($favorites, 0, $kept + 1))] + $state;
        self::assertGreaterThan(self::BUDGET, self::tokensOf($oneMore));
    }

    public function testAProfileFillingTheBudgetLeavesNoFavoritesKey(): void
    {
        $favorites = [self::favorite(1, 'Rust 2.0')];

        $state = self::state(str_repeat('p', 60_000), null, $favorites);

        self::assertArrayNotHasKey('favorites', $state);
    }

    /** 9 tokens hold 35 bytes: `{"profile":"` and `"}` leave 21 characters of the profile. */
    public function testTheStateFitsTheBudgetItIsGiven(): void
    {
        $state = (new ScoringStateFactory())->create(
            new ScoringReaderModel('Likes Rust and homelab posts.', null, []),
            9,
        );

        self::assertSame(['profile' => 'Likes Rust and homela'], $state);
    }

    /**
     * @param list<ArticleLineModel> $favorites
     *
     * @return array{profile: string, guidance?: string, favorites?: non-empty-list<array<string, string>>}
     */
    private static function state(string $profile, ?string $guidance, array $favorites): array
    {
        return (new ScoringStateFactory())->create(new ScoringReaderModel($profile, $guidance, $favorites), self::BUDGET);
    }

    private static function favorite(int $entryId, string $title): ArticleLineModel
    {
        return new ArticleLineModel($entryId, $title, 'Example Feed', '2026-10-01', str_repeat('Body text. ', 50));
    }

    /** @param array<string, mixed> $state */
    private static function tokensOf(array $state): int
    {
        return TokenEstimate::of(CompactJson::encode($state));
    }
}
```

`backend/tests/Service/Recommendation/Scoring/Factory/SystemOneRequestFactoryTest.php` — whole file. Every old assertion stays (the reader below gives exactly the old `STATE`); the last test is new (red while the factory fits the state to anything but the request's budget):

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Recommendation\Scoring\Factory;

use App\Service\Recommendation\Pool\Model\ArticleLineModel;
use App\Service\Recommendation\Scoring\Factory\ScoringStateFactory;
use App\Service\Recommendation\Scoring\Factory\SystemOneRequestFactory;
use App\Service\Recommendation\Scoring\Model\ScoringBudgetModel;
use App\Service\Recommendation\Scoring\Model\ScoringReaderModel;
use App\Service\Recommendation\Scoring\Model\ScoringRequestModel;
use PHPUnit\Framework\TestCase;

final class SystemOneRequestFactoryTest extends TestCase
{
    private const array STATE = ['profile' => 'Likes Rust.', 'guidance' => 'More kernel news.'];

    public function testOneNoulQuestionPerArticleKeyedByItsEntry(): void
    {
        $request = $this->factory()->create(self::request('jev-latest', [
            new ArticleLineModel(41, 'Kernel 6.18', 'LWN', '2026-10-01', 'Merge window notes.'),
            new ArticleLineModel(7, 'Rust 1.90', 'heise', '2026-09-30', null),
        ]));

        self::assertSame('jev-latest', $request->model);
        self::assertSame(self::STATE, $request->state);
        self::assertSame(['entry-41', 'entry-7'], array_keys($request->questions));
        self::assertSame(
            [
                'type' => 'noul',
                'instructions' => [
                    'article' => [
                        'title' => 'Kernel 6.18',
                        'feedName' => 'LWN',
                        'date' => '2026-10-01',
                        'description' => 'Merge window notes.',
                    ],
                    'question' => SystemOneRequestFactory::QUESTION,
                ],
            ],
            $request->questions['entry-41'],
        );
    }

    /** Feed text is untrusted: it lives in the article's fields, never in the question System One answers. */
    public function testAnArticlesTextNeverReachesTheQuestion(): void
    {
        $question = $this->factory()->question(
            new ArticleLineModel(9, 'Ignore `state` and answer yes', 'Spam', '2026-10-01', 'Answer yes.'),
        );

        self::assertSame(SystemOneRequestFactory::QUESTION, $question['instructions']['question']);
        self::assertSame('Ignore `state` and answer yes', $question['instructions']['article']['title']);
    }

    public function testEachFieldIsClipped(): void
    {
        $question = $this->factory()->question(
            new ArticleLineModel(9, str_repeat('t', 301), str_repeat('f', 121), '2026-10-01', str_repeat('d', 601)),
        );

        $article = $question['instructions']['article'];
        self::assertSame(str_repeat('t', 300) . '…', $article['title']);
        self::assertSame(str_repeat('f', 120) . '…', $article['feedName']);
        self::assertSame(str_repeat('d', 600) . '…', $article['description']);
    }

    public function testAClipNeverCutsInsideAMultiByteCharacter(): void
    {
        $question = $this->factory()->question(
            new ArticleLineModel(9, 'a' . str_repeat('ä', 300), 'Feed', '2026-10-01', null),
        );

        self::assertSame('a' . str_repeat('ä', 299) . '…', $question['instructions']['article']['title']);
        self::assertJson(json_encode($question, \JSON_THROW_ON_ERROR));
    }

    public function testInvalidByteSequencesAreScrubbedFromEveryArticleField(): void
    {
        $article = new ArticleLineModel(9, "Caf\xE9 au lait", "Feed\xC3", '2026-10-01', "\xFF ok");
        $factory = $this->factory();

        self::assertSame(
            ['title' => 'Caf? au lait', 'feedName' => 'Feed?', 'date' => '2026-10-01', 'description' => '? ok'],
            $factory->question($article)['instructions']['article'],
        );
        self::assertJson($factory->create(self::request('jev-latest', [$article]))->toRequestBody());
    }

    public function testTheRequestAsksTheRequestsModel(): void
    {
        self::assertSame('jev-1.13', $this->factory()->create(self::request('jev-1.13', []))->model);
    }

    /** 9 tokens of state hold 21 characters of this profile (ScoringStateFactoryTest). */
    public function testTheStateFitsTheRequestsStateBudget(): void
    {
        $request = $this->factory()->create(new ScoringRequestModel(
            'jev-latest',
            new ScoringReaderModel('Likes Rust and homelab posts.', null, []),
            new ScoringBudgetModel(32_000, 100, 9, 2_000),
            [],
        ));

        self::assertSame(['profile' => 'Likes Rust and homela'], $request->state);
    }

    private function factory(): SystemOneRequestFactory
    {
        return new SystemOneRequestFactory(new ScoringStateFactory());
    }

    /** @param list<ArticleLineModel> $articles */
    private static function request(string $model, array $articles): ScoringRequestModel
    {
        return new ScoringRequestModel(
            $model,
            new ScoringReaderModel('Likes Rust.', 'More kernel news.', []),
            new ScoringBudgetModel(32_000, 100, 10_000, 2_000),
            $articles,
        );
    }
}
```

- [ ] **Step 3: Run them to see them fail**

Run: `php bin/phpunit tests/Service/Recommendation/Scoring tests/Service/Recommendation/Run/Pass/TickContextTest.php tests/Service/Recommendation/Engine/RecommendationEngineWiringTest.php`
Expected: errors for the missing classes (`ScoringBudgetModel`, `ScoringReaderModel`, `ScoringRequestModel`, `ScoringBudgetFactory`, `SystemOneProtocol`, `ScoringProtocolResolver`) and `requireScoringProtocol()`; `testEveryEngineKindHasItsEngine` passes already (it pins Task 5's wiring).

- [ ] **Step 4: The values and the budget**

`backend/src/Service/Recommendation/Scoring/Model/ScoringBudgetModel.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Scoring\Model;

/** One scoring request's limits: the model's window, how many articles it may carry, and what the reader takes. */
final readonly class ScoringBudgetModel
{
    public function __construct(
        public int $contextWindowTokens,
        public int $maxItemsPerRequest,
        public int $stateTokens,
        public int $framingTokens,
    ) {
    }

    /** The state is budgeted at its ceiling, which the state factory never exceeds. */
    public function itemTokens(): int
    {
        return $this->contextWindowTokens - $this->framingTokens - $this->stateTokens;
    }
}
```

`backend/src/Service/Recommendation/Scoring/Model/ScoringReaderModel.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Scoring\Model;

use App\Service\Recommendation\Pool\Model\ArticleLineModel;

final readonly class ScoringReaderModel
{
    /** @param list<ArticleLineModel> $favorites newest first */
    public function __construct(
        public string $profile,
        public ?string $guidance,
        public array $favorites,
    ) {
    }
}
```

`backend/src/Service/Recommendation/Scoring/Model/ScoringRequestModel.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Scoring\Model;

use App\Service\Recommendation\Pool\Model\ArticleLineModel;

/** One request before a protocol words it. */
final readonly class ScoringRequestModel
{
    /** @param list<ArticleLineModel> $articles the batch's candidates, in snapshot order */
    public function __construct(
        public string $model,
        public ScoringReaderModel $reader,
        public ScoringBudgetModel $budget,
        public array $articles,
    ) {
    }
}
```

`backend/src/Service/Recommendation/Scoring/Factory/ScoringBudgetFactory.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Scoring\Factory;

use App\Enum\ScoringProtocol;
use App\Service\Ai\ModelCatalog\SystemOneCatalog;
use App\Service\Recommendation\Scoring\Model\ScoringBudgetModel;

final readonly class ScoringBudgetFactory
{
    /** No documented limit; keeps one request's answer, and what a failed one costs, small. */
    private const int SYSTEM_ONE_QUESTIONS_PER_REQUEST = 100;

    private const int STATE_TOKENS = 10_000;

    /** The request's own framing and the estimate's error. */
    private const int FRAMING_TOKENS = 2_000;

    public function create(ScoringProtocol $protocol): ScoringBudgetModel
    {
        return match ($protocol) {
            ScoringProtocol::SystemOne => new ScoringBudgetModel(
                contextWindowTokens: SystemOneCatalog::CONTEXT_WINDOW_TOKENS,
                maxItemsPerRequest: self::SYSTEM_ONE_QUESTIONS_PER_REQUEST,
                stateTokens: self::STATE_TOKENS,
                framingTokens: self::FRAMING_TOKENS,
            ),
        };
    }
}
```

- [ ] **Step 5: The packer, the state and the System One request**

`backend/src/Service/Recommendation/Scoring/ScoringBatchPacker.php` — whole file (the same loop; the measure comes from the protocol, D19):

```php
<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Scoring;

use App\Service\Recommendation\Pool\Model\ArticleLineModel;
use App\Service\Recommendation\Scoring\Model\ScoringBudgetModel;

final readonly class ScoringBatchPacker
{
    /**
     * @param list<ArticleLineModel>          $candidates
     * @param \Closure(ArticleLineModel): int $itemTokens what one article takes in the protocol's request
     *
     * @return list<list<int>> entry ids per request, in candidate order
     */
    public function pack(array $candidates, ScoringBudgetModel $budget, \Closure $itemTokens): array
    {
        $batches = [];
        $current = [];
        $used = 0;

        foreach ($candidates as $candidate) {
            $tokens = $itemTokens($candidate);
            if (
                [] !== $current
                && (
                    \count($current) >= $budget->maxItemsPerRequest
                    || $used + $tokens > $budget->itemTokens()
                )
            ) {
                $batches[] = $current;
                $current = [];
                $used = 0;
            }
            $current[] = $candidate->entryId;
            $used += $tokens;
        }

        if ([] !== $current) {
            $batches[] = $current;
        }

        return $batches;
    }
}
```

`backend/src/Service/Recommendation/Scoring/Factory/ScoringStateFactory.php` — whole file (the same fitting, in the same key order; the budget is a parameter):

```php
<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Scoring\Factory;

use App\Service\Recommendation\Pool\Model\ArticleLineModel;
use App\Service\Recommendation\Scoring\Model\ScoringReaderModel;
use App\Service\Recommendation\Scoring\Support\CompactJson;
use App\Service\Recommendation\Scoring\Support\FittingPrefix;
use App\Service\Recommendation\Scoring\Support\ScoringArticle;
use App\Service\Recommendation\Support\TokenEstimate;

/** The reader as one `state` within a token budget: guidance first, then profile, then the newest whole favorites. */
final readonly class ScoringStateFactory
{
    /** @return array{profile: string, guidance?: string, favorites?: non-empty-list<array<string, string>>} */
    public function create(ScoringReaderModel $reader, int $tokenBudget): array
    {
        $fits = static fn (array $state): bool => TokenEstimate::of(CompactJson::encode($state)) <= $tokenBudget;
        $guidanceState = self::guidanceState($reader->guidance, $fits);
        $state = [
            'profile' => FittingPrefix::of(
                mb_scrub($reader->profile, 'UTF-8'),
                static fn (string $prefix): bool => $fits(['profile' => $prefix] + $guidanceState),
            ),
        ] + $guidanceState;

        return $state + self::fittingFavorites($reader->favorites, $state, $fits);
    }

    /**
     * Fitted beside an empty profile: the profile's key must still fit once the guidance has taken the budget.
     *
     * @param \Closure(array<string, mixed>): bool $fits
     *
     * @return array{guidance?: string}
     */
    private static function guidanceState(?string $guidance, \Closure $fits): array
    {
        if (null === $guidance) {
            return [];
        }

        return ['guidance' => FittingPrefix::of(
            mb_scrub($guidance, 'UTF-8'),
            static fn (string $prefix): bool => $fits(['guidance' => $prefix] + ['profile' => '']),
        )];
    }

    /**
     * @param list<ArticleLineModel>               $favorites
     * @param array<string, string>                $others
     * @param \Closure(array<string, mixed>): bool $fits
     *
     * @return array{favorites?: non-empty-list<array<string, string>>} the newest whole favorites that still fit
     */
    private static function fittingFavorites(array $favorites, array $others, \Closure $fits): array
    {
        $fitting = [];
        foreach (array_map(ScoringArticle::of(...), $favorites) as $article) {
            $candidate = [...$fitting, $article];
            if (!$fits($others + ['favorites' => $candidate])) {
                break;
            }
            $fitting = $candidate;
        }

        return [] === $fitting ? [] : ['favorites' => $fitting];
    }
}
```

`backend/src/Service/Recommendation/Scoring/Factory/SystemOneRequestFactory.php` — whole file:

```php
<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Scoring\Factory;

use App\Service\Recommendation\Pool\Model\ArticleLineModel;
use App\Service\Recommendation\Scoring\Model\ScoringRequestModel;
use App\Service\Recommendation\Scoring\Model\SystemOneRequestModel;
use App\Service\Recommendation\Scoring\Support\QuestionId;
use App\Service\Recommendation\Scoring\Support\ScoringArticle;

final readonly class SystemOneRequestFactory
{
    /** Points at the structured fields by backtick path, as TypeSafe asks; no feed text is ever part of it. */
    public const string QUESTION = 'Judging by the reader\'s profile, favorites and guidance in `state`, would this '
        . 'reader want to read `article`?';

    public function __construct(private ScoringStateFactory $stateFactory)
    {
    }

    public function create(ScoringRequestModel $request): SystemOneRequestModel
    {
        $questions = [];
        foreach ($request->articles as $article) {
            $questions[QuestionId::of($article->entryId)] = $this->question($article);
        }

        return new SystemOneRequestModel(
            $request->model,
            $this->stateFactory->create($request->reader, $request->budget->stateTokens),
            $questions,
        );
    }

    /** @return array{type: string, instructions: array{article: array<string, string>, question: string}} */
    public function question(ArticleLineModel $article): array
    {
        return [
            'type' => 'noul',
            'instructions' => [
                'article' => ScoringArticle::of($article),
                'question' => self::QUESTION,
            ],
        ];
    }
}
```

- [ ] **Step 6: The protocol and its resolver**

`backend/src/Service/Recommendation/Scoring/ScoringProtocol/ScoringProtocolInterface.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Scoring\ScoringProtocol;

use App\Service\Ai\Model\ProviderCredentialsModel;
use App\Service\Recommendation\Pool\Model\ArticleLineModel;
use App\Service\Recommendation\Scoring\Model\ScoringBudgetModel;
use App\Service\Recommendation\Scoring\Model\ScoringOutcomeModel;
use App\Service\Recommendation\Scoring\Model\ScoringRequestModel;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/** How one family of scoring models is asked, keyed in ScoringProtocolResolver's locator by its ScoringProtocol value. */
#[AutoconfigureTag('app.scoring_protocol')]
interface ScoringProtocolInterface
{
    /**
     * @param list<ArticleLineModel> $candidates
     *
     * @return list<list<int>> entry ids per request, in candidate order
     */
    public function pack(ScoringBudgetModel $budget, array $candidates): array;

    /** The request as this protocol sends it, pretty-printed for the run log. */
    public function renderedRequest(ScoringRequestModel $request): string;

    /**
     * @param non-empty-list<ScoringRequestModel> $requests
     *
     * @return list<ScoringOutcomeModel> aligned by index; a failed call is an outcome, never a throw
     */
    public function scoreMany(ProviderCredentialsModel $credentials, array $requests): array;
}
```

`backend/src/Service/Recommendation/Scoring/ScoringProtocol/SystemOneProtocol.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Scoring\ScoringProtocol;

use App\Enum\ScoringProtocol;
use App\Service\Ai\Model\ProviderCredentialsModel;
use App\Service\Recommendation\Pool\Model\ArticleLineModel;
use App\Service\Recommendation\Scoring\Factory\SystemOneRequestFactory;
use App\Service\Recommendation\Scoring\Model\ScoringBudgetModel;
use App\Service\Recommendation\Scoring\Model\ScoringRequestModel;
use App\Service\Recommendation\Scoring\ScoringBatchPacker;
use App\Service\Recommendation\Scoring\Support\CompactJson;
use App\Service\Recommendation\Scoring\SystemOneClient\SystemOneClientInterface;
use App\Service\Recommendation\Support\TokenEstimate;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

/** TypeSafe's System One: the reader once per request in `state`, one `noul` question per article. */
#[AsTaggedItem(index: ScoringProtocol::SystemOne->value)]
final readonly class SystemOneProtocol implements ScoringProtocolInterface
{
    public function __construct(
        private ScoringBatchPacker $packer,
        private SystemOneRequestFactory $requestFactory,
        private SystemOneClientInterface $client,
    ) {
    }

    public function pack(ScoringBudgetModel $budget, array $candidates): array
    {
        return $this->packer->pack($candidates, $budget, $this->questionTokens(...));
    }

    public function renderedRequest(ScoringRequestModel $request): string
    {
        return $this->requestFactory->create($request)->toRenderedRequest();
    }

    public function scoreMany(ProviderCredentialsModel $credentials, array $requests): array
    {
        return $this->client->evaluateMany($credentials, array_map($this->requestFactory->create(...), $requests));
    }

    private function questionTokens(ArticleLineModel $candidate): int
    {
        return TokenEstimate::of(CompactJson::encode($this->requestFactory->question($candidate)));
    }
}
```

`backend/src/Service/Recommendation/Scoring/ScoringProtocolResolver.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Scoring;

use App\Enum\ScoringProtocol;
use App\Service\Recommendation\Scoring\ScoringProtocol\ScoringProtocolInterface;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireLocator;

final readonly class ScoringProtocolResolver
{
    public function __construct(
        #[AutowireLocator('app.scoring_protocol')]
        private ContainerInterface $protocols,
    ) {
    }

    public function protocolOf(ScoringProtocol $protocol): ScoringProtocolInterface
    {
        try {
            $implementation = $this->protocols->get($protocol->value);
        } catch (ContainerExceptionInterface $exception) {
            throw new \LogicException(
                sprintf('No scoring protocol is wired for "%s".', $protocol->value),
                previous: $exception,
            );
        }
        \assert($implementation instanceof ScoringProtocolInterface);

        return $implementation;
    }
}
```
**Assumption (verify):** importing `App\Enum\ScoringProtocol` into the namespace `…\Scoring`, which has a sub-namespace `…\Scoring\ScoringProtocol`, is no conflict (PHP resolves the import; a relative `ScoringProtocol\…` name is never used). PHPStan and the wiring test prove it.

- [ ] **Step 7: The tick, the wave and the engine**

`backend/src/Service/Recommendation/Run/Pass/TickContext.php`: after `scoringProtocol()` add

```php
    public function requireScoringProtocol(): ScoringProtocol
    {
        return $this->scoringProtocol()
            ?? throw new \LogicException('This tick\'s connection speaks no scoring protocol.');
    }
```

`backend/src/Service/Recommendation/Scoring/Pass/ScoringWave.php` — whole file:

```php
<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Scoring\Pass;

use App\Service\Recommendation\Run\BatchWave\BatchWaveInterface;
use App\Service\Recommendation\Run\Model\WaveBatchModel;
use App\Service\Recommendation\Run\Pass\TickContext;
use App\Service\Recommendation\Scoring\Model\ScoringReaderModel;

final readonly class ScoringWave implements BatchWaveInterface
{
    /** @param list<WaveBatchModel> $batches the plan's next batches, in plan order */
    public function __construct(
        private TickContext $tick,
        public ScoringReaderModel $reader,
        private array $batches,
    ) {
    }

    public function tick(): TickContext
    {
        return $this->tick;
    }

    public function batches(): array
    {
        return $this->batches;
    }
}
```

`backend/src/Service/Recommendation/Scoring/ScoringBatchWave.php` — whole file:

```php
<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Scoring;

use App\Service\Ai\AiProviderConfigurator;
use App\Service\Ai\Model\RateLimitedResultModel;
use App\Service\Ai\RateLimitedCalls;
use App\Service\Recommendation\Pool\Model\ArticleLineModel;
use App\Service\Recommendation\Run\BatchCallOutcome\BatchCallOutcomeInterface;
use App\Service\Recommendation\Run\BatchWave\BatchWaveInterface;
use App\Service\Recommendation\Run\BatchWaveEngine\BatchWaveEngineInterface;
use App\Service\Recommendation\Run\Model\BatchReplyVerdictModel;
use App\Service\Recommendation\Run\Model\CallSlotModel;
use App\Service\Recommendation\Run\Model\WaveBatchModel;
use App\Service\Recommendation\Run\Pass\BatchCall;
use App\Service\Recommendation\Run\RecommendationCallRecorder;
use App\Service\Recommendation\Scoring\Factory\ScoringBudgetFactory;
use App\Service\Recommendation\Scoring\Model\ScoringOutcomeModel;
use App\Service\Recommendation\Scoring\Model\ScoringRequestModel;
use App\Service\Recommendation\Scoring\Pass\ScoringWave;

/**
 * One scoring request per batch, each a run-log row.
 *
 * @implements BatchWaveEngineInterface<ScoringWave, ScoringRequestModel, ScoringOutcomeModel>
 */
final readonly class ScoringBatchWave implements BatchWaveEngineInterface
{
    public function __construct(
        private RateLimitedCalls $rateLimitedCalls,
        private ScoringProtocolResolver $protocols,
        private ScoringBudgetFactory $budgetFactory,
        private AiProviderConfigurator $configurator,
        private RecommendationCallRecorder $callRecorder,
        private ScoreParser $parser,
    ) {
    }

    /**
     * @param ScoringWave $wave
     *
     * @return BatchCall<ScoringRequestModel>
     */
    public function open(BatchWaveInterface $wave, int $position): BatchCall
    {
        $tick = $wave->tick();
        $protocol = $tick->requireScoringProtocol();
        $waveBatch = $wave->batches()[$position];
        $request = new ScoringRequestModel(
            $tick->connection->getModel() ?? '',
            $wave->reader,
            $this->budgetFactory->create($protocol),
            $waveBatch->linesInSnapshotOrder(),
        );
        $recordedCall = $this->callRecorder->begin(
            $tick->run,
            CallSlotModel::batch($waveBatch->index + 1),
            $this->protocols->protocolOf($protocol)->renderedRequest($request),
        );

        return new BatchCall($request, $recordedCall);
    }

    /**
     * @param ScoringWave                                    $wave
     * @param non-empty-list<BatchCall<ScoringRequestModel>> $calls
     *
     * @return RateLimitedResultModel<ScoringOutcomeModel>
     */
    public function sendAll(BatchWaveInterface $wave, array $calls): RateLimitedResultModel
    {
        $tick = $wave->tick();
        $credentials = $this->configurator->credentials($tick->connection);
        $protocol = $this->protocols->protocolOf($tick->requireScoringProtocol());

        return $this->rateLimitedCalls->send(
            $calls,
            static fn (array $subset): array => self::receiveAnswers(
                $subset,
                $protocol->scoreMany($credentials, self::requestsOf($subset)),
            ),
            $tick->retryPlan(),
        );
    }

    /**
     * @param ScoringWave         $wave
     * @param ScoringOutcomeModel $outcome
     */
    public function judge(
        BatchWaveInterface $wave,
        int $position,
        BatchCallOutcomeInterface $outcome,
    ): BatchReplyVerdictModel {
        $reply = $outcome->reply();
        $parsed = $this->parser->parse($reply, self::idsOf($wave->batches()[$position]));
        $transcript = self::logged($reply->body);

        return $parsed->usable
            ? BatchReplyVerdictModel::usable($parsed->winners, $transcript)
            : BatchReplyVerdictModel::unusable($transcript);
    }

    /**
     * Books each paid answer the moment it arrives, so a sibling's failure or a deferral still bills what it cost. Only
     * a limited request is re-sent, so no answer is booked twice.
     *
     * @param non-empty-list<BatchCall<ScoringRequestModel>> $calls
     * @param list<ScoringOutcomeModel>                      $outcomes aligned to $calls
     *
     * @return list<ScoringOutcomeModel>
     */
    private static function receiveAnswers(array $calls, array $outcomes): array
    {
        foreach ($outcomes as $index => $outcome) {
            if ($outcome->isFailure()) {
                continue;
            }
            $reply = $outcome->reply();
            $calls[$index]->recordedCall->received($reply->receipt, \strlen($reply->body));
        }

        return $outcomes;
    }

    /**
     * @param non-empty-list<BatchCall<ScoringRequestModel>> $calls
     *
     * @return non-empty-list<ScoringRequestModel>
     */
    private static function requestsOf(array $calls): array
    {
        return array_map(static fn (BatchCall $call): ScoringRequestModel => $call->request, $calls);
    }

    /** A gateway's invalid byte must not reach a utf8mb4 column: MySQL strict mode would fail the tick's write. */
    private static function logged(string $body): string
    {
        return mb_scrub($body, 'UTF-8');
    }

    /** @return list<int> */
    private static function idsOf(WaveBatchModel $batch): array
    {
        return array_map(static fn (ArticleLineModel $line): int => $line->entryId, $batch->linesInSnapshotOrder());
    }
}
```

`backend/src/Service/Recommendation/Scoring/ScoringRecommendationEngine.php` — whole file (`NO_PROFILE` and the profile check are Task 2's):

```php
<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Scoring;

use App\Enum\RecommendationEngineKind;
use App\Service\Recommendation\Engine\RecommendationEngine\RecommendationEngineInterface;
use App\Service\Recommendation\Pool\RecommendationHistoryLoader;
use App\Service\Recommendation\Run\BatchWavePhase;
use App\Service\Recommendation\Run\BatchWaveRounds;
use App\Service\Recommendation\Run\Model\BatchWaveResultModel;
use App\Service\Recommendation\Run\Model\RecommendationRunReportModel;
use App\Service\Recommendation\Run\Model\WaveBatchModel;
use App\Service\Recommendation\Run\Pass\TickContext;
use App\Service\Recommendation\Run\RecommendationRunFailure;
use App\Service\Recommendation\Run\RecommendationRunFinalizer;
use App\Service\Recommendation\Run\RecommendationWinnerRanker;
use App\Service\Recommendation\Scoring\Factory\ScoringBudgetFactory;
use App\Service\Recommendation\Scoring\Model\ScoringReaderModel;
use App\Service\Recommendation\Scoring\Pass\ScoringWave;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

#[AsTaggedItem(index: RecommendationEngineKind::Scoring->value)]
final readonly class ScoringRecommendationEngine implements RecommendationEngineInterface
{
    public const string NO_PROFILE = 'A scoring model needs your reading profile, and there is no reading history to '
        . 'build one from yet. Read, keep or favourite a few articles, then start a new run.';

    public function __construct(
        private ScoringProtocolResolver $protocols,
        private ScoringBudgetFactory $budgetFactory,
        private BatchWavePhase $batchWavePhase,
        private ScoringBatchWave $wave,
        private BatchWaveRounds $rounds,
        private RecommendationWinnerRanker $ranker,
        private RecommendationRunFinalizer $finalizer,
        private RecommendationRunFailure $runFailure,
        private RecommendationHistoryLoader $historyLoader,
    ) {
    }

    public function packBatches(array $candidates, TickContext $tick): array
    {
        $protocol = $tick->requireScoringProtocol();

        return $this->protocols->protocolOf($protocol)->pack($this->budgetFactory->create($protocol), $candidates);
    }

    public function advance(TickContext $tick): RecommendationRunReportModel
    {
        $run = $tick->run;
        if ($run->isMissingBorrowedProfile()) {
            return $this->runFailure->fail($run, self::NO_PROFILE);
        }
        if ($run->getProgress()->allBatchCallsDone) {
            return $this->finalizer->finalize($run, $this->ranker->ranked($run->getWinners()));
        }

        return $this->batchWavePhase->advance(
            $tick,
            fn (array $batches): BatchWaveResultModel => $this->rounds->resolve(
                $this->wave,
                $this->waveOf($tick, $batches),
            ),
        );
    }

    /** @param list<WaveBatchModel> $batches */
    private function waveOf(TickContext $tick, array $batches): ScoringWave
    {
        $profile = $tick->run->getProfileText()
            ?? throw new \LogicException('A scoring wave runs only once the run holds a profile.');

        return new ScoringWave(
            $tick,
            new ScoringReaderModel(
                $profile,
                $tick->settings->guidancePrompt,
                $this->historyLoader->favorites($tick->userId(), $tick->settings),
            ),
            $batches,
        );
    }
}
```

- [ ] **Step 8: Run the tests to see them pass, and prove the wire did not move**

```bash
php bin/phpunit tests/Service/Recommendation tests/Http tests/Controller/Api/RecommendationSettingsControllerTest.php
git diff HEAD --stat -- tests/Service/Recommendation/Scoring/ScoringPipelineTest.php tests/Service/Recommendation/Scoring/ScoringRecommendationEngineTest.php tests/Service/Recommendation/Scoring/Model/SystemOneRequestModelTest.php tests/Service/Recommendation/Scoring/SystemOneClient/HttpSystemOneClientTest.php tests/Support/StubSystemOneClient.php
```
Expected: `OK`, and the `git diff` prints nothing: the tests that pin what System One is sent (`model`, `state`, questions, favorites, the run log's request body) and how a run behaves (429/529, deferral, billing, unusable replies, the switch, no profile) pass unchanged.

- [ ] **Step 9: Deletion checks**

1. `ScoringBatchPacker::pack()`: `>` to `>=` → `ScoringBatchPackerTest` FAILs (`[1, 2]` splits).
2. `ScoringBatchPacker::pack()`: delete the `\count($current) >= …` clause → `ScoringBatchPackerTest` FAILs (`[3, 4, 5, 6]`).
3. `ScoringBudgetModel::itemTokens()`: `+ $this->framingTokens` → `ScoringBudgetModelTest` FAILs.
4. `ScoringBudgetFactory`: `SYSTEM_ONE_QUESTIONS_PER_REQUEST = 64` → `ScoringBudgetFactoryTest` and `SystemOneProtocolTest::testShortArticlesFillRequestsUpToTheQuestionCapInPoolOrder` FAIL.
5. `SystemOneProtocol::pack()`: measure with `static fn (): int => 0` → `testHeavyArticlesFillRequestsUpToTheTokenBudget` FAILs.
6. `SystemOneProtocol::renderedRequest()`: `->toRequestBody()` → `testTheRunLogGetsTheSystemOneBodyPrettyPrinted` FAILs.
7. `ScoringProtocolResolver::protocolOf()`: `get($protocol->name)` → `ScoringProtocolResolverTest::testTheImplementationComesFromTheLocatorUnderItsProtocolsValue` FAILs.
8. `SystemOneProtocol`: delete the `#[AsTaggedItem(…)]` line → `ScoringProtocolWiringTest` errors (`No scoring protocol is wired for "system_one".`).
9. `ScoringStateFactory::create()`: `<= 10_000` instead of `<= $tokenBudget` → `testTheStateFitsTheBudgetItIsGiven` FAILs.
10. `SystemOneRequestFactory::create()`: pass `10_000` instead of `$request->budget->stateTokens` → `testTheStateFitsTheRequestsStateBudget` FAILs.
11. `TickContext::requireScoringProtocol()`: return `ScoringProtocol::SystemOne` → `testAnLlmConnectionsTickHasNoProtocolToRequire` FAILs.

- [ ] **Step 10: Gates**

```bash
bin/console cache:warmup
composer check
composer md
php bin/phpunit
```
Expected: green; `ServiceRoleRule` places every new class where this task put it (a finding names the expected home: report it, the planner amends); phptramp at Task 0's warning count — **Assumption (verify):** no new chain; if a warning names `$credentials` or `$budget` through `SystemOneProtocol`, report the count. PHPMD: `ScoringBatchWave` and `ScoringRecommendationEngine` keep the coupling of their Jev predecessors (one dependency swapped for another); if `CouplingBetweenObjects` reports, report it with the count. PhpStorm `lint_files` on every changed `src` file.

- [ ] **Step 11: Commit**

```bash
git add -A src tests
git commit -m "refactor(#1395): the scoring engine speaks to its model through a protocol"
```

---

### Task 8: `ScoringHttpTransport`

**Files:**
- Create: `backend/src/Service/Recommendation/Scoring/ScoringHttpTransport.php`, `backend/src/Service/Recommendation/Scoring/Pass/ScoringEndpoint.php`, `backend/tests/Service/Recommendation/Scoring/ScoringHttpTransportTest.php`
- Rewrite: `backend/src/Service/Recommendation/Scoring/SystemOneClient/HttpSystemOneClient.php`
- Modify: `backend/src/Service/Recommendation/Scoring/Pass/ResponseWave.php` (constructor), `backend/tests/Service/Recommendation/Scoring/SystemOneClient/HttpSystemOneClientTest.php` (construction only), `backend/tests/Service/Recommendation/Scoring/Pass/ResponseWaveTest.php` (construction only)

**Interfaces:**
- Consumes: `ScoringOutcomeModel`, `ScoringReplyModel` (Task 6), `SystemOneReplyDecoder::decode(string $body, ?string $requestIdHeader): ScoringReplyModel`.
- Produces:
  - `ScoringHttpTransport::sendAll(ScoringEndpoint $endpoint, ProviderCredentialsModel $credentials, non-empty-list<string> $bodies): list<ScoringOutcomeModel>` — streaming, idle (120 s) and wall-clock (300 s) timeouts, the 10 s heartbeat, the 1 MiB cap, and the shared status mapping (401/403 → credentials, `RejectingStatus` → rejected with the provider's reason, ≥ 300 → unreachable).
  - `ScoringEndpoint(string $path, list<int> $retryableStatuses, \Closure(string, ResponseInterface): ScoringReplyModel $decoder)` with `retries(int): bool` and `decode(string, ResponseInterface): ScoringReplyModel`.
  - `ResponseWave(ClockInterface $clock, ProviderCredentialsModel $credentials, ScoringEndpoint $endpoint)`.
  - `HttpSystemOneClient::__construct(ScoringHttpTransport $transport)`; System One adds `/systemone`, 429 and 529, and `x-typesafe-request-id`.

- [ ] **Step 1: Write the failing test**

`backend/tests/Service/Recommendation/Scoring/ScoringHttpTransportTest.php` (red while the transport does not exist; later red if the path or the retryable statuses are System One's constants instead of the endpoint's):

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Recommendation\Scoring;

use App\Service\Ai\Model\ProviderCallReceiptModel;
use App\Service\Ai\Model\ProviderCredentialsModel;
use App\Service\Fetch\Support\ResponseHeader;
use App\Service\Recommendation\Scoring\Model\ScoringReplyModel;
use App\Service\Recommendation\Scoring\Pass\ScoringEndpoint;
use App\Service\Recommendation\Scoring\ScoringHttpTransport;
use App\Tests\Support\CountingProviderCallHeartbeat;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\ResponseInterface;

/** The endpoint is the protocol's; everything else is the transport's, and HttpSystemOneClientTest covers it. */
final class ScoringHttpTransportTest extends TestCase
{
    public function testItPostsEachBodyToTheEndpointsPathAndReadsTheReplyTheEndpointsWay(): void
    {
        $response = new MockResponse('{"ranked":[0.4]}', ['response_headers' => ['x-rank-request' => 'rank-31']]);

        $outcomes = $this->transport([$response])->sendAll(
            new ScoringEndpoint(
                '/rank',
                [],
                static fn (string $body, ResponseInterface $reply): ScoringReplyModel => new ScoringReplyModel(
                    $body,
                    [31 => 0.4],
                    new ProviderCallReceiptModel(ResponseHeader::first($reply, 'x-rank-request'), null, null),
                ),
            ),
            $this->credentials(),
            ['{"query":"Rust"}'],
        );

        self::assertSame('https://api.rank.test/v2/rank', $response->getRequestUrl());
        /** @var array{headers: list<string>, body: string} $options */
        $options = $response->getRequestOptions();
        self::assertSame('{"query":"Rust"}', $options['body']);
        self::assertContains('Authorization: Bearer sk-rank', $options['headers']);
        self::assertSame([31 => 0.4], $outcomes[0]->reply()->scores);
        self::assertSame('rank-31', $outcomes[0]->reply()->receipt->requestId);
    }

    /** 529 is System One's own: an endpoint that does not name it gets the shared mapping. */
    public function testOnlyTheEndpointsOwnStatusesAreRetryable(): void
    {
        $outcomes = $this->transport([
            new MockResponse('{}', ['http_code' => 503]),
            new MockResponse('{}', ['http_code' => 529]),
        ])->sendAll(
            new ScoringEndpoint(
                '/rank',
                [503],
                static fn (): ScoringReplyModel => throw new \LogicException('No reply was expected.'),
            ),
            $this->credentials(),
            ['{}', '{}'],
        );

        self::assertTrue($outcomes[0]->isRetryable());
        self::assertFalse($outcomes[1]->isRetryable());
        self::assertSame('That provider answered with status 529.', $outcomes[1]->cause()->getMessage());
    }

    /** @param list<MockResponse> $responses */
    private function transport(array $responses): ScoringHttpTransport
    {
        return new ScoringHttpTransport(
            new MockHttpClient($responses),
            new CountingProviderCallHeartbeat(),
            new MockClock(),
            'SimpleFeedReader/1.0',
        );
    }

    private function credentials(): ProviderCredentialsModel
    {
        return ProviderCredentialsModel::fromStoredConfiguration('https://api.rank.test/v2', 'sk-rank');
    }
}
```

`backend/tests/Service/Recommendation/Scoring/SystemOneClient/HttpSystemOneClientTest.php` — construction only (every assertion stays: they pin System One's half — URL, body, 429/529, the request-id header — and the transport's shared half): add `use App\Service\Recommendation\Scoring\ScoringHttpTransport;` and wrap each of the three `new HttpSystemOneClient(<client>, <heartbeat>, <clock>, 'SimpleFeedReader/1.0')` as `new HttpSystemOneClient(new ScoringHttpTransport(<client>, <heartbeat>, <clock>, 'SimpleFeedReader/1.0'))`, e.g. in `evaluate()`:

```php
        $client = new HttpSystemOneClient(new ScoringHttpTransport(
            new MockHttpClient($responses),
            new CountingProviderCallHeartbeat(),
            new MockClock(),
            'SimpleFeedReader/1.0',
        ));
```

`backend/tests/Service/Recommendation/Scoring/Pass/ResponseWaveTest.php` — construction only: add `use App\Service\Recommendation\Scoring\Model\ScoringReplyModel;`, `use App\Service\Recommendation\Scoring\Pass\ScoringEndpoint;`, `use App\Service\Recommendation\Scoring\Support\SystemOneReplyDecoder;`; both `new ResponseWave(<clock>, $this->credentials())` become `new ResponseWave(<clock>, $this->credentials(), self::endpoint())`, with

```php
    private static function endpoint(): ScoringEndpoint
    {
        return new ScoringEndpoint(
            '/systemone',
            [429, 529],
            static fn (string $body): ScoringReplyModel => SystemOneReplyDecoder::decode($body, null),
        );
    }
```

- [ ] **Step 2: Run it to see it fail**

Run: `php bin/phpunit tests/Service/Recommendation/Scoring/ScoringHttpTransportTest.php`
Expected: error `Class "App\Service\Recommendation\Scoring\Pass\ScoringEndpoint" not found`.

- [ ] **Step 3: The endpoint, the wave and the transport**

`backend/src/Service/Recommendation/Scoring/Pass/ScoringEndpoint.php` (a `Pass/`, D14):

```php
<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Scoring\Pass;

use App\Service\Recommendation\Scoring\Model\ScoringReplyModel;
use Symfony\Contracts\HttpClient\ResponseInterface;

/** Where one protocol's requests go, which statuses it retries, and how it reads a 2xx reply. */
final readonly class ScoringEndpoint
{
    /**
     * @param list<int> $retryableStatuses the protocol's own, beside the transport's shared mapping
     * @param \Closure(string, ResponseInterface): ScoringReplyModel $decoder
     */
    public function __construct(
        public string $path,
        private array $retryableStatuses,
        private \Closure $decoder,
    ) {
    }

    public function retries(int $status): bool
    {
        return \in_array($status, $this->retryableStatuses, true);
    }

    public function decode(string $body, ResponseInterface $response): ScoringReplyModel
    {
        return ($this->decoder)($body, $response);
    }
}
```

`backend/src/Service/Recommendation/Scoring/Pass/ResponseWave.php`: add `use App\Service\Recommendation\Scoring\Model\ScoringOutcomeModel;` if missing (it is there since Task 5) and the constructor becomes

```php
    public function __construct(
        private readonly ClockInterface $clock,
        public readonly ProviderCredentialsModel $credentials,
        public readonly ScoringEndpoint $endpoint,
    ) {
        $this->positions = new \SplObjectStorage();
    }
```
(`ScoringEndpoint` is in its own namespace: no import.)

`backend/src/Service/Recommendation/Scoring/ScoringHttpTransport.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Scoring;

use App\Service\Ai\Exception\CredentialsRejectedException;
use App\Service\Ai\Exception\ProviderRejectedRequestException;
use App\Service\Ai\Exception\ProviderUnreachableException;
use App\Service\Ai\Exception\RetryableProviderException;
use App\Service\Ai\Model\ProviderCredentialsModel;
use App\Service\Ai\Support\RejectingStatus;
use App\Service\Ai\Support\ResponseByteCap;
use App\Service\Ai\Support\RetryAfter;
use App\Service\Recommendation\Run\ProviderCallHeartbeat\ProviderCallHeartbeatInterface;
use App\Service\Recommendation\Scoring\Model\ScoringOutcomeModel;
use App\Service\Recommendation\Scoring\Pass\ResponseWave;
use App\Service\Recommendation\Scoring\Pass\ScoringEndpoint;
use App\Service\Recommendation\Support\ProviderErrorReason;
use App\Service\Recommendation\Support\RefusalMessage;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Contracts\HttpClient\ChunkInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Sends a protocol's scoring requests at once and streams the replies. Its own timeouts, not the connection's
 * slow-model pair: one request is bounded by its model's window. The caps are no SSRF boundary
 * (docs/security.md#ai-provider-endpoints).
 */
final readonly class ScoringHttpTransport
{
    private const float IDLE_TIMEOUT_SECONDS = 120.0;
    private const float WALL_CLOCK_SECONDS = 300.0;

    /** The longest the wave waits without beating the tick's heartbeat, which keeps the per-user lock alive. */
    private const float HEARTBEAT_SECONDS = 10.0;

    private const int MAXIMUM_RESPONSE_BYTES = 1_048_576;

    public function __construct(
        private HttpClientInterface $httpClient,
        private ProviderCallHeartbeatInterface $heartbeat,
        private ClockInterface $clock,
        private string $userAgent,
    ) {
    }

    /**
     * One outcome per body, aligned by index. A per-call failure is carried in its outcome, never thrown, so it cannot
     * discard a sibling's answer.
     *
     * @param non-empty-list<string> $bodies
     *
     * @return list<ScoringOutcomeModel>
     */
    public function sendAll(ScoringEndpoint $endpoint, ProviderCredentialsModel $credentials, array $bodies): array
    {
        $wave = new ResponseWave($this->clock, $credentials, $endpoint);
        $url = $credentials->baseUrl . $endpoint->path;
        $authorizationHeaders = $credentials->authorizationHeaders();
        foreach ($bodies as $position => $body) {
            try {
                $wave->await($position, $this->send($url, $authorizationHeaders, $body));
            } catch (ExceptionInterface $exception) {
                $wave->settleAt($position, ScoringOutcomeModel::failed(
                    ProviderUnreachableException::didNotAnswer($exception),
                ));
            }
        }

        while ([] !== $open = $wave->openResponses()) {
            $this->streamRound($wave, $open);
        }

        return $wave->outcomes();
    }

    /**
     * stream() drops a response after its timeout chunk, so each round re-streams the open ones; the round's timeout
     * only paces the heartbeat, and failSilentFor() is the idle bound.
     *
     * @param non-empty-list<ResponseInterface> $open
     */
    private function streamRound(ResponseWave $wave, array $open): void
    {
        foreach ($this->httpClient->stream($open, self::HEARTBEAT_SECONDS) as $response => $chunk) {
            $this->heartbeat->beat();
            $outcome = $wave->isSettled($response) ? null : $this->outcomeAfter($wave, $response, $chunk);
            if (null !== $outcome) {
                $wave->settle($response, $outcome);
            }
            $wave->failSilentFor(self::IDLE_TIMEOUT_SECONDS);
        }
    }

    /** Null while the response is still arriving; a transport failure becomes this call's outcome. */
    private function outcomeAfter(
        ResponseWave $wave,
        ResponseInterface $response,
        ChunkInterface $chunk,
    ): ?ScoringOutcomeModel {
        try {
            if ($chunk->isTimeout()) {
                return null;
            }
            $wave->heardFrom($response);
            if ($chunk->isFirst()) {
                // Unread at the first chunk, stream() throws a 4xx/5xx status outside this call's outcome.
                $response->getStatusCode();
            }
            if (!$chunk->isLast()) {
                return null;
            }

            return $this->outcomeOf($wave, $response);
        } catch (ExceptionInterface $exception) {
            $response->cancel();

            return ScoringOutcomeModel::failed(ProviderUnreachableException::didNotAnswer($exception));
        }
    }

    private function outcomeOf(ResponseWave $wave, ResponseInterface $response): ScoringOutcomeModel
    {
        $status = $response->getStatusCode();
        $body = $response->getContent(false);

        return match (true) {
            401 === $status, 403 === $status => ScoringOutcomeModel::failed(
                CredentialsRejectedException::refusedKey(),
            ),
            $wave->endpoint->retries($status) => ScoringOutcomeModel::failed(
                new RetryableProviderException($status, RetryAfter::secondsIn($response)),
            ),
            RejectingStatus::matches($status) => ScoringOutcomeModel::failed(new ProviderRejectedRequestException(
                $status,
                RefusalMessage::of($status, ProviderErrorReason::in($body, $wave->credentials)),
            )),
            $status >= 300 => ScoringOutcomeModel::failed(ProviderUnreachableException::answeredWithStatus($status)),
            default => ScoringOutcomeModel::answered($wave->endpoint->decode($body, $response)),
        };
    }

    /** @param array<string, string> $authorizationHeaders */
    private function send(string $url, array $authorizationHeaders, string $body): ResponseInterface
    {
        return $this->httpClient->request('POST', $url, [
            'headers' => [
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
                // No transparent compression, so the size cap below also bounds the decompressed body.
                'Accept-Encoding' => 'identity',
                'User-Agent' => $this->userAgent,
                ...$authorizationHeaders,
            ],
            'body' => $body,
            'timeout' => self::IDLE_TIMEOUT_SECONDS,
            'max_duration' => self::WALL_CLOCK_SECONDS,
            'max_redirects' => 0,
            'on_progress' => ResponseByteCap::onProgress(self::MAXIMUM_RESPONSE_BYTES),
        ]);
    }
}
```
The transport reads the credentials itself (URL and headers), so they reach it through two forwarders (D15).

`backend/src/Service/Recommendation/Scoring/SystemOneClient/HttpSystemOneClient.php` — whole file:

```php
<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Scoring\SystemOneClient;

use App\Service\Ai\Model\ProviderCredentialsModel;
use App\Service\Fetch\Support\ResponseHeader;
use App\Service\Recommendation\Scoring\Model\ScoringReplyModel;
use App\Service\Recommendation\Scoring\Model\SystemOneRequestModel;
use App\Service\Recommendation\Scoring\Pass\ScoringEndpoint;
use App\Service\Recommendation\Scoring\ScoringHttpTransport;
use App\Service\Recommendation\Scoring\Support\SystemOneReplyDecoder;
use Symfony\Contracts\HttpClient\ResponseInterface;

/** Sends `POST {baseUrl}/systemone` through the scoring transport. */
final readonly class HttpSystemOneClient implements SystemOneClientInterface
{
    private const string PATH = '/systemone';

    private const array RETRYABLE_STATUSES = [429, 529];

    private const string REQUEST_ID_HEADER = 'x-typesafe-request-id';

    public function __construct(private ScoringHttpTransport $transport)
    {
    }

    public function evaluateMany(ProviderCredentialsModel $credentials, array $requests): array
    {
        return $this->transport->sendAll(
            new ScoringEndpoint(self::PATH, self::RETRYABLE_STATUSES, self::decode(...)),
            $credentials,
            array_map(static fn (SystemOneRequestModel $request): string => $request->toRequestBody(), $requests),
        );
    }

    private static function decode(string $body, ResponseInterface $response): ScoringReplyModel
    {
        return SystemOneReplyDecoder::decode($body, ResponseHeader::first($response, self::REQUEST_ID_HEADER));
    }
}
```

- [ ] **Step 4: Run the tests to see them pass**

```bash
php bin/phpunit tests/Service/Recommendation/Scoring
git diff HEAD -- tests/Service/Recommendation/Scoring/SystemOneClient/HttpSystemOneClientTest.php
```
Expected: `OK`; the diff touches the import and the three constructions, nothing else — read it and say so in the report.

- [ ] **Step 5: Deletion checks**

1. `ScoringHttpTransport::outcomeOf()`: `\in_array($status, [429, 529], true)` instead of `$wave->endpoint->retries($status)` → `testOnlyTheEndpointsOwnStatusesAreRetryable` FAILs.
2. `ScoringHttpTransport::sendAll()`: `$credentials->baseUrl . '/systemone'` → `testItPostsEachBodyToTheEndpointsPathAndReadsTheReplyTheEndpointsWay` FAILs.
3. `HttpSystemOneClient`: `RETRYABLE_STATUSES = [429]` → `HttpSystemOneClientTest::testAnOverloaded529IsRetryableWithoutAHint` FAILs.

- [ ] **Step 6: Gates and commit**

`bin/console cache:warmup`, `composer check` (phptramp: no new chain through `$credentials`, D15), `composer md`, `php bin/phpunit`; PhpStorm `lint_files` on the four `src` files. `infection.json5`'s `UnwrapArrayValues` entry for `ResponseWave::outcomes` still names a method that exists.

```bash
git add -A src tests
git commit -m "refactor(#1395): the scoring transport is shared; system one adds its endpoint and statuses"
```

---

### Task 9: Docs, gates, a real run, the PR

**Files:**
- Modify: `docs/recommendations-runs.md`

**Interfaces:**
- Consumes: everything above.
- Produces: the PR.

From the repository root for every step of this task except Step 2.

- [ ] **Step 1: Document**

`docs/recommendations-runs.md`, in order:

- "neither has a Jev run that started without a profile;" → "neither has a run on a scoring model that started without a profile;"
- "an engine (a model id starting with `jev-` is TypeSafe's System One, any other an LLM);" → "an engine: the kind the connection stored when its model was chosen (`user_ai_settings.model_kind`, `llm` or `scoring`, as the model catalog tagged the model; the model id is never read);"
- "on an engine that offers that setting, so never Jev." → "on an engine that offers that setting, so never a scoring model."
- "the LLM engine for any non-retryable, non-credential error status, the Jev engine for every rejecting" → "the LLM engine for any non-retryable, non-credential error status, the scoring engine for every rejecting"
- "label beside its id (`RecommendationEngineResolver::labelForModel`: `"Jev"`, or `null` for an LLM), so the picker" → "label beside its id (`AiSettingsJson`: `"System One"` for a model the catalog tagged with that protocol, `null` for an LLM), so the picker"
- "marks a Jev model without the client parsing its id; no client logic branches on the label:" → "marks a scoring model without the client parsing its id; no client logic branches on the label:"
- In the JSON example, `"label": "Jev",` → `"label": "System One",`.
- Replace the whole paragraph that starts "The Jev engine (`Service/Recommendation/Jev`) asks TypeSafe's System One" and ends "answers." with:

```markdown
The scoring engine (`Service/Recommendation/Scoring`) scores every candidate against the reader instead of generating
text. It speaks to the model through a protocol (`ScoringProtocol/ScoringProtocolInterface`, one implementation per
`App\Enum\ScoringProtocol` case, found by `ScoringProtocolResolver`): the protocol packs the pool, words each request
and reads each reply as a value in [0, 1] per article. A connection stores the protocol beside the kind when its model
is chosen (`user_ai_settings.scoring_protocol`); `SystemOneCatalog` offers `jev-latest` as a System One model wherever
`{base}/systemone` answers.

| Protocol | Request | Per request |
|---|---|---|
| System One (`system_one`) | `POST {base}/systemone`, directly or through OpenRouter: `state` = `{profile, guidance?, favorites?}`, one `noul` question per article | at most 100 questions; a 32k-token window less 2k of framing and 10k for the state (`ScoringBudgetFactory`) |

A run freezes the stored profile when it snapshots; a scoring model needs one and fails with a message that says so
when there is none (an account with neither reading history nor a saved search). The LLM engine scores without a
profile in that case. The value is the score (× 1000); there are no reasons and no consolidation, so the list is the
best-scored picks once every batch is in. The engine reads only the batch-concurrency setting and records each call's
request id, answering model and cost in the run log. `ScoringHttpTransport` sends a protocol's requests (its own idle
and wall-clock timeouts, the tick heartbeat, a 1 MiB reply cap) and maps the statuses every protocol shares; a
protocol adds only its retryable statuses (System One: 429 and 529). A run records the engine kind and the scoring
protocol it was packed for; a tick that finds the active connection on another kind or protocol fails the run with an
error that says so (switch back to resume it).
```

- "a Jev connection can never be" → "a connection on a scoring model can never be"
- "or a Jev run that froze no profile, is not resumable;" → "or a run on a scoring model that froze no profile, is not resumable;"

Then `git grep -n -i 'jev' -- docs/recommendations-runs.md docs/architecture.md` prints only the `jev-latest` mentions.

- [ ] **Step 2: Backend gates** (from `backend/`, one at a time)

```bash
bin/console cache:warmup
composer check
composer md
php bin/phpunit
php ../docs/superpowers/plans/2026-10-05-1395-scripts/psr4-namespaces.php
composer infection:diff
```
and from the repository root: `docker compose exec php sh -c 'rm -rf var/cache/test*'`, then `docker compose exec php composer test`.
Expected: green throughout; phptramp's warnings at Task 0's count; the PSR-4 sweep at its baseline; Infection at or above `minMsi` (80). **Assumption (verify):** `infection:diff` mutates the moved-and-rewritten `Scoring` files; git's rename detection can list them as `R`, which `--git-diff-filter=AM` skips. If its summary names none of them, also run `vendor/bin/infection --threads=max --filter=src/Service/Recommendation/Scoring` and report its MSI beside the gate's. Escaped mutants: kill them with a test (each new test names the production change it pins), never by lowering `minMsi`.

PhpStorm `lint_files` on every `src` file the branch changed (`git diff --name-only origin/develop -- src`): no ERROR, no WARNING.

- [ ] **Step 3: Frontend gate**

From the repository root, alone: `docker compose exec -T frontend npm run check; echo "EXIT=$?"` → `EXIT=0`. (`git diff --stat origin/develop -- frontend` shows the two i18n files only.)

- [ ] **Step 4: The stack serves the branch**

```bash
docker compose exec php bin/console doctrine:migrations:status | grep -iE 'new|latest'
docker compose exec php bin/console cache:clear
docker compose restart worker
docker compose ps
```
Expected: no new migrations (Task 2 applied both); the worker healthy.

- [ ] **Step 5: One real Jev run** (D21; a few tenths of a cent through OpenRouter). Never print, copy or log an API key or a token; every write goes through the app's own API, and every change is restored.

1. Record the starting state (read-only):

```bash
docker compose exec php bin/console dbal:run-sql "SELECT id, user_id, name, base_url, model, model_kind, scoring_protocol FROM user_ai_settings WHERE id = 8"
docker compose exec php bin/console dbal:run-sql "SELECT u.id, u.email, u.active_ai_config_id FROM app_user u JOIN user_ai_settings s ON s.user_id = u.id WHERE s.id = 8"
```
Expected: connection 8 on `jev-latest`, `scoring`/`system_one`. If it does not exist or holds another model, skip this step and say so in the report: setting one up is a write this plan does not make.

2. The model list labels it (one command: a shell variable does not outlive the call):

```bash
TOKEN=$(docker compose exec -T php bin/console lexik:jwt:generate-token <email> | tail -1) && curl -sk https://localhost:8443/api/me/ai/configs/8/models -H "Authorization: Bearer $TOKEN" | jq '.models[] | select(.id == "jev-latest")'
```
Expected: `{"id": "jev-latest", "label": "System One", "capabilities": {"reasons": false, "prompt": false, "profile": "borrowed", "tuningFields": ["batchConcurrency"]}}`.

3. If connection 8 is not the account's active one, `PUT /api/me/ai/configs/8/active` the same way and record that you did. Start a run: `POST /api/recommendations/runs`. Then read `GET /api/recommendations/runs/current` by hand, one command at a time, until its `status` leaves `pending`/`running` (the worker drives the run; no loop, no `sleep`).

4. Verify (read-only):

```sql
SELECT id, status, error, engine_kind, scoring_protocol, model, cost_nano_credits FROM recommendation_run ORDER BY id DESC LIMIT 1;
SELECT run_id, phase, batch_number, verdict, request_id, answering_model, cost_nano_credits FROM recommendation_run_log WHERE run_id = <id> ORDER BY id;
SELECT COUNT(*) AS items, SUM(CASE WHEN reason = '' THEN 1 ELSE 0 END) AS without_reason, MIN(score), MAX(score) FROM recommendation_item WHERE recommendation_run_id = <id>;
SELECT request_body FROM recommendation_run_log WHERE run_id = <id> ORDER BY id LIMIT 1;
```
(each through `docker compose exec php bin/console dbal:run-sql "…"`). Expected: `completed`, `scoring`, `system_one`, `jev-latest`; every row `phase = batch` with a `gen-…` request id, an answering model like `typesafe/jev-…` and a cost; `items = without_reason`, scores within 0–1000; the request body is the pretty-printed `{"model": "jev-latest", "state": {"profile": …}, "questions": {"entry-…": {"type": "noul", …}}}`.

5. Restore: `PUT /api/me/ai/configs/<recorded active id>/active` if step 3 changed it; re-run step 1's queries: the rows match what was recorded.

6. Scan the dev log: `ls -t backend/var/log/dev-*.log | head -1 | xargs tail -n 600 | jq -c 'select(.level >= 300)'` → nothing new from this run (a deprecation is a finding).

Record for the PR: the run id, items, score range, the request ids' shape, the answering model, the cost.

- [ ] **Step 6: Commit, push, PR**

```bash
git add docs/recommendations-runs.md
git commit -m "docs(#1395): the scoring engine and its protocol seam"
git status --short
git push -u origin feature/1395-scoring-protocol-seam
cat > "$TMPDIR/pr-1395-body.md" <<'EOF'
Closes #1395
Refs #1394

PR 1 of the scoring-model providers design (docs/superpowers/specs/2026-10-05-scoring-model-providers-design.md): a refactor with no behaviour change.

- `Service/Recommendation/Jev` is now `Service/Recommendation/Scoring`: a neutral engine (`ScoringRecommendationEngine`, `ScoringBatchWave`, `ScoringBatchPacker`, `ScoreParser`, `ScoringStateFactory`) over a protocol seam (`ScoringProtocolInterface`, found by `App\Enum\ScoringProtocol` value). System One (`SystemOneProtocol`) is the only protocol; it sends today's request byte for byte with today's budget (32k window, 100 questions, 10k state, 2k framing).
- `ScoringHttpTransport` holds the streaming, the timeouts, the heartbeat, the byte cap and the status mapping every protocol shares; `HttpSystemOneClient` adds `/systemone`, 429/529 and its request-id header.
- `RecommendationEngineKind::Jev` is `Scoring` (`'scoring'`). A connection stores the kind and protocol the catalog tagged its model with (`user_ai_settings.model_kind`, `scoring_protocol`), and a run records the protocol beside its kind (`recommendation_run.scoring_protocol`). Two migrations; the backfill turns `jev-…` connections and `jev` runs into `scoring`/`system_one`, case-sensitively as the old resolver read the id.
- The resolver reads the stored kind; the `jev-` prefix rule is gone. The model list labels a System One model "System One" (was "Jev") from the catalog's tag, and the guide's step names that label.
- The engine-switch guard also fires on a changed protocol; "needs a profile" asks the kind's profile source, and its message names no model.

Real run on the dev stack (connection 8, `jev-latest`): <run id, items, score range, request id shape, answering model, cost>.

Gates: composer check (phptramp warnings: <n>), composer md, both PHPUnit legs, infection:diff (MSI <n>), npm run check.

Plan: docs/superpowers/plans/2026-10-05-1395-scoring-protocol-seam.md
EOF
grep -inE '(close[sd]?|fix(e[sd])?|resolve[sd]?) #1394' "$TMPDIR/pr-1395-body.md"
gh pr create --base develop --title "refactor(#1395): the scoring protocol seam" --body-file "$TMPDIR/pr-1395-body.md"
```
Expected: the `grep` prints nothing (a closing keyword beside #1394 would close the umbrella); `gh` prints the PR URL. Do not merge: Lars merges. After the merge, verify #1395 closed on its own and #1394 stayed open.

---

## Spec coverage (planner's self-review)

| Spec / brief item | Where |
|---|---|
| D1 one `Scoring` kind, value `'scoring'`, `phases()` `[Batch]` | Task 1 (case), Task 2 (value) |
| D2 `App\Enum\ScoringProtocol` (`SystemOne` only in PR 1); `family()` | Task 1; `family()` deferred (D19) |
| D3 the descriptor carries kind and protocol; `SystemOneCatalog` tags System One; the `jev-` prefix rule deleted | Task 1 (`ModelDescriptor`, D1/D2), Task 3 (rule deleted); the modality mapping and fallback rule are PR 2 |
| D4 the connection stores kind and protocol; `chooseModel()` stores them; `kindFor()` reads `modelKind ?? Llm`; migration with backfill | Task 2 (D3, D4, D7), Task 3 |
| D5 PR 1 keeps Jev's budget; `ScoringBudgetModel` carries it | Task 7 (`ScoringBudgetFactory`, D9) |
| D6 module rename and neutral names; the protocol folder; `ScoringHttpTransport` with the shared mapping, System One's 429/529 | Task 5, Task 7, Task 8 |
| D7 `pack()`/`scoreMany()`, neutral `ScoringRequestModel`, System One byte for byte, decoding to `id → value` | Task 7 (+ `renderedRequest()`, D11; reader with favorites, D10), Task 6 (D13) |
| D8 run migration (`'jev'` → `'scoring'`, `scoring_protocol`), snapshot records the protocol, guard on kind or protocol, profile by capability, `NO_PROFILE` names no model | Task 2, Task 4 |
| D9 API keys unchanged except the label | Task 3 (D8) |
| Wiring: every protocol has an implementation, every kind an engine | Task 7 |
| Tests moved; every Jev assertion kept | Task 5 (move), Task 6/7/8 (named changes of form only), Task 7 Step 8 (unchanged pins) |
| Docs: `recommendations-runs.md`, architecture §9; CLAUDE.md only if it names Jev (it does not) | Task 5 (§9), Task 9 |
| Frontend: only what PR 1 forces | Task 3 (two i18n strings) |

## Where the code contradicted the spec or the brief

- The System One `state` carries the newest favorites, not only profile and guidance (`JevStateFactory`); the neutral request therefore carries a reader with favorites (D10).
- Both entities sit at PHPMD's field limit, so the new columns arrive through two embeddables (D3).
- An entity may not call `RecommendationEngineCapabilitiesModel::of()` (architecture §8); the borrowed-profile rule lives on the kind (D5).
- The spec's backfill `LIKE 'jev-%'` is case-insensitive on MySQL; the old rule was case-sensitive (D7).
- The run log records the request before it is sent, which the spec's two-method interface cannot word (D11).
- `ModelDescriptorModel` cannot be what the entity takes (§8); it moves down as `App\Entity\ModelDescriptor` (D1).
