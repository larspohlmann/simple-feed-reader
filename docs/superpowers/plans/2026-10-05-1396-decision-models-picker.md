# Decision Models and the Kind-First Picker Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** List every decision model OpenRouter offers as a System One scoring model (tagged by the catalog, not by its id), size each scoring request by the protocol's item cap and the model's own context window, expose `kind`/`family` in the API, and let Settings → AI ask "LLM or scoring model?" before listing that kind's models.

**Architecture:** `OpenAiCompatibleCatalog` reads `GET {base}/models?output_modalities=all` and maps each entry's `architecture.output_modalities` to an LLM, a System One model (`["decisions"]` with a positive window) or nothing. `CompositeModelCatalog` asks `SystemOneCatalog`'s probe only when no listing named a System One model (a per-call `Pass/ModelListing` collects the answers). The budget becomes the protocol's (`ScoringProtocolInterface::budget(int $contextWindowTokens)`, built by `ScoringBudgetModel::forWindow()`), fed the connection's stored window through `TickContext::requireScoringContextWindow()`; System One asks at most 64 questions. `AiSettingsJson` adds `kind` and `family` (`ScoringProtocol::family()` → `App\Enum\ScoringFamily`). The SPA's picker puts the shared `<app-segmented-choice>` (now with disabled options) above the model select and filters by kind.

**Tech Stack:** PHP 8.4, Symfony 7.4 (`Autowire`, `AutowireIterator`), PHPUnit 12, PHPStan level max with the custom rules in `backend/tests/PhpStan/`, PHPMD, phptramp, Infection; Angular 20 (signals, `linkedSignal`, standalone components), Transloco, Jest (in the Docker frontend container), Playwright.

**Spec:** `docs/superpowers/specs/2026-10-05-scoring-model-providers-design.md` (umbrella #1394), "Delivery" item 2 — this PR is sub-issue #1396. Read the spec's D3, D5, D9, D10 and the spike findings first. PR 1's plan (`docs/superpowers/plans/2026-10-05-1395-scoring-protocol-seam.md`) is merged; its Decisions D1–D21 are settled and this plan builds on them (`App\Entity\ModelDescriptor` with a derived `kind()`, `ChosenModel` storing kind and protocol, `ScoringProtocolInterface` with `pack()`/`renderedRequest()`/`scoreMany()`, `SystemOneProtocol` on `SystemOneClientInterface`). Carry-forward notes from PR 1: `.superpowers/sdd/1394-carry-forward.md` (each item is a decision below).

## Status

| Task | Title | Status | Commit |
|---|---|---|---|
| 0 | Preflight and baselines | ☑ | — (no files changed) |
| 1 | A scoring model has a family and always a window | ☑ | d8752c077 |
| 2 | The listing says what each model is | ☑ | 514e85f4c, f067af351 |
| 3 | The System One probe is the fallback | ☑ | d8b7e303a, d5a1cabce, d4b4bb145, 4a283b27d |
| 4 | The budget is the protocol's and scales with the window | ☑ | cca034480, 0dbbb91bb |
| 5 | The API names each model's kind and family | ☑ | 233031e88 |
| 6 | `<app-segmented-choice>` can disable an option | ☑ | d86f48b5b |
| 7 | The picker asks for the kind first | ☑ | 28e8a84b1, 226af75f0 |
| 8 | General scoring-model copy | ☑ | 583e9e913 |
| 9 | Playwright smoke of the picker | ☑ | e24ef18de |
| 10 | Docs, gates, real runs, the PR | ☑ | afd83e98c; final-review fixes 94c37427d 5c71b0b96 2797221ff 06e7ab179 |

## Global Constraints

- **What changes for a user:** OpenRouter connections list its decision models (`~typesafe/jev-latest`, `jaredpalmer/kev-4b`, `cloudflare/clef-flash`, …) as scoring models; Respan's `span-01*` (no window), rerankers, image, embedding, video, speech and transcription models stay out; the bare `jev-latest` alias is offered only where no listing names a System One model (TypeSafe direct). Every System One request carries at most **64** questions (was 100), and its state budget is `min(10_000, 30 % of the window)` tokens (Jev at 32k: 9,600, was 10,000), framing 2,000, questions the rest. The API gains `kind` and `family`. The picker asks LLM / Scoring model first. The Jev copy becomes general scoring-model copy (en, de).
- **Out of this PR:** `ScoringProtocol::Rerank`, `RerankProtocol`, the `["rerank"]` mapping, the `reranker` family and its copy (PR 3). No migration: PR 1 added every column this PR reads.
- Spec values copied verbatim: catalog request `GET {base}/models?output_modalities=all`; System One cap 64 questions; state `min(10_000, 30 % of the window)`; framing 2,000; `kind` `'llm'|'scoring'`; `family` `'decision'|'reranker'|null` (only `'decision'` occurs before PR 3); option tag "Decision model"; disabled option text "This provider offers no scoring models" (or LLMs).
- Clean Code per `CLAUDE.md`: `final readonly` services, intent-revealing names (no abbreviations, no single letters), ≤ 3 parameters, no boolean flags, guard clauses, role folders (`Model/`, `Pass/`, `Support/`, a folder per interface), **default to no comment**, one-line comments.
- Persistence knows no service, domain knows no HTTP (architecture §8): `App\Entity`/`App\Enum` never name `App\Service\…` or `App\Http\…`.
- No entity gains a field (`AiProviderSettings` and `RecommendationRun` sit at PHPMD's 15-field limit).
- phptramp: 4 forwarding hops across 2+ classes fail, 3 warn. Task 0 records the warning count; no task may raise it.
- API changes are additive JSON keys on existing bearer-authenticated, stateless endpoints (architecture §6 checklist; Task 10 records it in the PR).
- Frontend: standalone components and signals; component styles in the sibling `.scss`; no hex colours, raw `px` spacing or media-query literals outside the theme; Prettier at 100 columns; every new string in `frontend/public/i18n/en.json` **and** `de.json` under the same key. Read `docs/design-language.md` §2 and §3 "Settings" before Task 6.
- Gates (from `backend/`): `bin/console cache:warmup` then `composer check` (cs + stan + tramp), `composer md` (every touched `src` file PHPMD-clean), `php bin/phpunit` (SQLite) and, from the repository root, `docker compose exec php sh -c 'rm -rf var/cache-docker/test*'` then `docker compose exec php composer test` (MySQL), `composer infection:diff`; PhpStorm inspections on changed PHP (load `mcp__phpstorm__lint_files` through ToolSearch first) block on ERROR and WARNING. Frontend, from the repository root and **one Jest process at a time**: `docker compose exec -T frontend npm run check; echo "EXIT=$?"`; a focused spec is `docker compose exec -T frontend npx jest <path>`. Never run two Jest processes or Jest beside Playwright: the container OOMs.
- **Deletion checks are binding.** Every new test names, in its step, the production change that turns it red. In each task's deletion-check step: copy the file aside (`cp <file> "$TMPDIR/<name>.orig"`), make the named break, run the named test, paste the FAIL output verbatim into the task report, restore with `mv "$TMPDIR/<name>.orig" <file>`, re-run and paste the OK. **Never** `git checkout -- <file>`. After a break of an attribute or enum, `rm -rf var/cache/test` before the re-run, and `touch` the restored file so the cached test container sees it.
- Commits: `type(#1396): lower-case summary`, no attribution lines. The PR body says `Closes #1396` and `Refs #1394`, and never a closing keyword next to `#1394`.
- Another Claude session may share this checkout: run `git status` and `git branch --show-current` before any switch, reset or stash. Work stays on `feature/1396-decision-models-picker`.
- Implementers run every command in the foreground, one at a time: no background shells, no `sleep`/`until` poll loops, no `&`.
- Backend commands run from `backend/`; `docker compose …` from the repository root; frontend `npx prettier`/`npx playwright` from `frontend/`. Paths below are repository-relative unless a command runs in `backend/` or `frontend/`.
- An implementer reports plan defects to the planner, who amends this plan in-branch. Every **Assumption (verify):** is cheap to check: check it and report the outcome.

## Decisions (judgement calls the spec did not settle, or where the code contradicted it, with the reason)

- **D1 — A model whose outputs include `text` is an LLM, not only `["text"]`.** Contradicts spec D3's "`["text"]` → `Llm`; anything else → left out". OpenRouter's catalog read on 2026-10-05 (public `GET /api/v1/models`, no key) lists 15 models in its *plain* text listing whose outputs are `["image","text"]`, `["text","image"]` or `["text","audio"]` (e.g. `google/gemini-3.1-flash-lite-image`, `openrouter/auto-beta`); the strict rule would drop models the picker offers today and refuse a connection that stores one. An entry with no `architecture.output_modalities` stays an LLM (spec). A scoring protocol is read only from a single-modality output (`["decisions"]`).
- **D2 — No retry without the query parameter until a server refuses it.** Spec D3 makes this an assumption to verify. Both dev LM Studio connections (3, 5) were unreachable while this plan was written, so Task 10 checks them through the app's API. If one answers the new request with an error status, the implementer stops and reports to the planner, who amends the plan with the retry.
- **D3 — The probe is a constructor collaborator, not a tagged member.** `CompositeModelCatalog` takes its listing members by tag and `SystemOneCatalog` as `#[Autowire(service: SystemOneCatalog::class)] ModelCatalogInterface $systemOneFallback`, consulted only when no member listed a `SystemOne` model (spec D3; on OpenRouter it also saves the probe request). A per-call `Service/Ai/Pass/ModelListing` holds the descriptors by id, the failures and the credentials (a field, so no tramp chain).
- **D4 — The probe's description wins an id the listing named untagged.** Settles carry-forward item 1 (first-descriptor-wins would store a bare `jev-latest` from an OpenAI-shaped `/models` as an LLM): the probe proved `{base}/systemone`, so its System One tag is kept. A listing's own duplicates still keep their first description.
- **D5 — An existing OpenRouter connection on the bare `jev-latest` can no longer be re-chosen or re-activated.** Spec D4 foresaw the re-choose ("the picker will offer it as `~typesafe/jev-latest`"); the code adds activation: `AiProviderConfigurator::activate()` re-verifies the stored model against the catalog (`assertModelStillOffered`, #334), and after D3 OpenRouter's catalog no longer offers `jev-latest`. While such a connection stays active its runs are unaffected (a run reads the stored model, never the catalog). Not worked around: a host-specific data migration would bind persistence to OpenRouter's naming, and loosening activation's re-verify is a product change. The PR body says so; dev connection 8 (active for user 2) is such a connection, which shapes Task 10's real runs (D17).
- **D6 — A scoring descriptor always carries a positive window.** `ModelDescriptor`'s constructor throws `\InvalidArgumentException` for a scoring protocol without one: the budget scales with the window (spec D5), and the catalog's zero-window rule (finding 3) then holds wherever a descriptor is made. One test fixture changes (`ProfileControllerTest`'s `null` → `32_000`).
- **D7 — The budget is the protocol's; `ScoringBudgetFactory` is deleted.** Carry-forward item 2. `ScoringProtocolInterface::budget(int $contextWindowTokens): ScoringBudgetModel`; System One implements it with `ScoringBudgetModel::forWindow($window, self::QUESTIONS_PER_REQUEST)`, the static constructor that holds the shared shares (state `min(10_000, 30 %)`, framing 2,000). PR 1's D9 ("PR 2 adds the stored window to the factory's input") is superseded: with the cap on the protocol, the factory would only forward.
- **D8 — The window comes from the connection.** `TickContext::requireScoringContextWindow()` reads `$connection->getModelContextWindow()` (spec D5), not `EffectiveRecommendationSettingsModel::$packing->contextWindow`, which carries the LLM-only override and fallback. A scoring connection without a stored window is a `\LogicException`, as `requireScoringProtocol()` is; every scoring connection chosen since #1345 stored 32,000 (`SystemOneCatalog`), and D6 keeps it so.
- **D9 — Jev's budget follows the formula.** At its 32,000-token window the state gets 9,600 tokens (was 10,000) and the questions 20,400 (was 20,000); the cap is 64 (was 100). `SystemOneProtocol::QUESTIONS_PER_REQUEST` is public so the engine tests derive their candidate counts from it (`64 + 1`, `3 × 64 + 1` keep each test's batch shape).
- **D10 — `App\Enum\ScoringFamily` with `Decision = 'decision'` only.** `ScoringProtocol::family()` returns it (spec D2); `Reranker` arrives with PR 3's protocol. In `App\Enum` because `ScoringProtocol` (an enum there) returns it; the client words it.
- **D11 — The configuration's `kind` is the resolver's.** `AiSettingsJson` takes `RecommendationEngineResolver` and sends `kindFor($settings)` (a connection without a model reads `llm`, as its capabilities already do); `family` is the stored protocol's, `null` for an LLM. Model rows: `id, label, kind, family, capabilities`, in that order (the tests compare with `assertSame`).
- **D12 — The picker shows the family tag, not the server label.** Each scoring option reads `<id> · Decision model` (translated). The wire keeps `label` (spec D9: "`label` and `capabilities` stay"; other clients may use it); the SPA's `AiModel` type drops it, as nothing reads it, and `AiConfig` gains only `kind` (the SPA does not read a configuration's family).
- **D13 — The kind control is `<app-segmented-choice>`, not wrapped in `<app-field>`.** The shared control gains `disabledOptions`. `<app-field>` wraps its control in a `<label>`, and a click on a label's text presses its first labelable descendant: the "LLM" button. The group is named by `ariaLabelKey` ("Model type"); the hints sit in paragraphs under it. The component gets a catalog entry in `docs/design-language.md` (it had none).
- **D14 — The picked kind is a `linkedSignal` over the model list.** It opens on the row's current kind, or on the only kind offered; it reads the configurations `untracked`, so a write to any row keeps the reader's pick. The chosen model resets when the row or the kind changes, so a stale LLM pick is never saved from the scoring list.
- **D15 — PR 2's copy names decision models only.** Spec D9's hint ("a reranker's score only ranks articles within a run, a decision model's is a probability") gets its reranker half, and the `Reranker` tag, with PR 3; until then the picker offers no reranker to explain.
- **D16 — Unchanged on purpose:** the `ENGINE_SWITCH` wording (carry-forward item 4: with one protocol, a protocol switch cannot happen in this PR; PR 3's plan words it with the reranker family), and the per-request state rebuild (carry-forward item 3: not measured; still deterministic and cheap).
- **D17 — Real runs on connection 8 need Lars's yes.** The spec allows real runs from PR 2 on. Kev (`jaredpalmer/kev-4b`, 8,192 tokens) proves the window-scaled budget, Clef (`cloudflare/clef-flash`, the model that refuses > 64) proves the cap. Every route back to `jev-latest` is closed by D5, so the runs end with connection 8 on `~typesafe/jev-latest` (the path spec D4 names). That is a lasting change to dev data, so Task 10 asks first and skips the runs without a yes.
- **D18 — Test fixtures named for Jev's capabilities become `SCORING_RECOMMENDATION_CAPABILITIES`** (frontend `src/testing/recommendation-capabilities.ts` and its two importers), with the general copy.
- **D19 — No guard for windows below the framing and state shares.** The smallest decision model listed has 8,192 tokens (3,735 left for questions); a smaller one would pack one article per request and the provider would refuse it, failing the run as #1387 does. YAGNI until a listed model needs it.
- **D20 — The decision-model hint shows only while "Scoring model" is picked.** Spec D9 puts the hint under the kind control unconditionally; with no reranker yet it explains only the scoring list, so it shows with that list.

## File map

Created (src): `backend/src/Enum/ScoringFamily.php`, `backend/src/Service/Ai/Pass/ModelListing.php`.

Deleted: `backend/src/Service/Recommendation/Scoring/Factory/ScoringBudgetFactory.php`, `backend/tests/Service/Recommendation/Scoring/Factory/ScoringBudgetFactoryTest.php`.

Modified (src): `backend/src/Enum/ScoringProtocol.php`, `backend/src/Entity/ModelDescriptor.php`, `backend/src/Service/Ai/ModelCatalog/{OpenAiCompatibleCatalog,CompositeModelCatalog,SystemOneCatalog}.php`, `backend/src/Service/Recommendation/Scoring/Model/ScoringBudgetModel.php`, `backend/src/Service/Recommendation/Scoring/ScoringProtocol/{ScoringProtocolInterface,SystemOneProtocol}.php`, `backend/src/Service/Recommendation/Scoring/{ScoringRecommendationEngine,ScoringBatchWave}.php`, `backend/src/Service/Recommendation/Run/Pass/TickContext.php`, `backend/src/Http/AiSettingsJson.php`.

Tests created: `backend/tests/Enum/ScoringProtocolTest.php`, `backend/tests/Service/Recommendation/Scoring/ScoringRecommendationEnginePackingTest.php`, `frontend/e2e/ai-model-kind-picker.spec.ts`. Tests modified: `backend/tests/Entity/ModelDescriptorTest.php`, `backend/tests/Controller/Api/{ProfileControllerTest,AiSettingsControllerTest}.php`, `backend/tests/Service/Ai/ModelCatalog/{OpenAiCompatibleCatalogTest,CompositeModelCatalogTest,ModelCatalogWiringTest}.php`, `backend/tests/Service/Recommendation/Scoring/ScoringRecommendationEngineTest.php`, `…/Scoring/Model/ScoringBudgetModelTest.php`, `…/Scoring/ScoringProtocol/SystemOneProtocolTest.php`, `backend/tests/Service/Recommendation/Run/Pass/TickContextTest.php`, `backend/tests/Http/AiSettingsJsonTest.php`.

Frontend modified: `frontend/src/app/shared/segmented-choice/segmented-choice.component.{ts,html,scss,spec.ts}`, `frontend/src/app/settings/ai/ai-settings.service{,.spec}.ts`, `frontend/src/app/settings/ai/ai-section.component.{ts,html,scss,spec.ts}`, `frontend/src/testing/recommendation-capabilities.ts`, `frontend/src/app/settings/recommendations/recommendation-settings-card.component.spec.ts`, `frontend/public/i18n/{en,de}.json`, `frontend/e2e/ai-config-rejected.spec.ts` (stub shape).

Docs: `docs/recommendations-runs.md`, `docs/design-language.md`.

---

### Task 0: Preflight and baselines

**Files:** none.

**Interfaces:**
- Consumes: nothing.
- Produces: the baseline numbers later tasks compare against (phptramp warnings, PHPUnit test count, Jest "Test Suites:" line).

- [ ] **Step 1: Check the checkout** (concurrent sessions share it)

```bash
git status --short
git branch --show-current
git log --oneline -2
```
Expected: clean tree, branch `feature/1396-decision-models-picker`, the top commit `docs(#1396): implementation plan for decision models and the kind-first picker` above `6f6784159 Merge pull request #1398 …`. Anything else: stop and ask.

- [ ] **Step 2: The Docker stack serves this tree** (standing rule)

From the repository root:

```bash
docker compose ps
docker compose exec php printenv APP_CACHE_DIR
docker compose exec php bin/console cache:clear
docker compose restart worker
docker compose exec php bin/console doctrine:migrations:status | grep -iE 'new|executed'
```
Expected: `php`, `worker`, `nginx`, `frontend`, `mysql` up (worker healthy); `APP_CACHE_DIR` is `/app/var/cache-docker`; no new migrations. If `APP_CACHE_DIR` is empty: `docker compose up -d php worker && docker compose restart nginx`.

- [ ] **Step 3: Baselines** (record every number in the report)

From `backend/`:

```bash
bin/console cache:warmup
composer check
composer md
php bin/phpunit
```
And from the repository root, alone: `docker compose exec -T frontend npm run check; echo "EXIT=$?"`.

Expected: all green; record phptramp's warning count, the PHPUnit test count and the Jest "Test Suites:" line. A red baseline stops the task: prove it pre-exists on `origin/develop` before anything builds on it.

---

### Task 1: A scoring model has a family and always a window

**Files:**
- Create: `backend/src/Enum/ScoringFamily.php`, `backend/tests/Enum/ScoringProtocolTest.php`
- Modify: `backend/src/Enum/ScoringProtocol.php`, `backend/src/Entity/ModelDescriptor.php`, `backend/tests/Entity/ModelDescriptorTest.php`, `backend/tests/Controller/Api/ProfileControllerTest.php:125`

**Interfaces:**
- Consumes: PR 1's `App\Entity\ModelDescriptor(string $id, ?int $contextWindow, ?ScoringProtocol $scoringProtocol = null)`.
- Produces: `App\Enum\ScoringFamily` (`Decision = 'decision'`); `ScoringProtocol::family(): ScoringFamily`; `new ModelDescriptor($id, $window, $protocol)` throws `\InvalidArgumentException('The scoring model "<id>" needs a positive context window.')` when `$protocol` is set and `$window` is null or ≤ 0.

- [ ] **Step 1: Write the failing tests**

`backend/tests/Enum/ScoringProtocolTest.php` (red while `family()` is missing; red later if System One names another family):

```php
<?php

declare(strict_types=1);

namespace App\Tests\Enum;

use App\Enum\ScoringFamily;
use App\Enum\ScoringProtocol;
use PHPUnit\Framework\TestCase;

final class ScoringProtocolTest extends TestCase
{
    public function testASystemOneModelIsADecisionModel(): void
    {
        self::assertSame(ScoringFamily::Decision, ScoringProtocol::SystemOne->family());
    }
}
```

`backend/tests/Entity/ModelDescriptorTest.php` (whole file; the two new tests are red while the constructor accepts a scoring model without a window, and red later if the guard drops `null` or uses `< 0`):

```php
<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\ModelDescriptor;
use App\Enum\RecommendationEngineKind;
use App\Enum\ScoringProtocol;
use PHPUnit\Framework\Attributes\DataProvider;
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

    /** @return iterable<string, array{?int}> */
    public static function missingWindows(): iterable
    {
        yield 'none reported' => [null];
        yield 'reported as zero (Respan)' => [0];
    }

    /** A scoring request is budgeted from the window, so a scoring model without one cannot be described. */
    #[DataProvider('missingWindows')]
    public function testAScoringModelNeedsAPositiveContextWindow(?int $contextWindow): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('The scoring model "respan/span-01" needs a positive context window.');

        new ModelDescriptor('respan/span-01', $contextWindow, ScoringProtocol::SystemOne);
    }

    public function testAnLlmMayLeaveItsWindowUnreported(): void
    {
        self::assertNull((new ModelDescriptor('gpt-4o', null))->contextWindow);
    }
}
```

- [ ] **Step 2: Run them to see them fail**

Run: `php bin/phpunit tests/Enum/ScoringProtocolTest.php tests/Entity/ModelDescriptorTest.php`
Expected: an error `Class "App\Enum\ScoringFamily" not found` (or `Call to undefined method …family()`), and two failures `Failed asserting that exception of type "InvalidArgumentException" is thrown.`

- [ ] **Step 3: Write the values**

`backend/src/Enum/ScoringFamily.php`:

```php
<?php

declare(strict_types=1);

namespace App\Enum;

/** What the user is told a scoring model is; the client words it. */
enum ScoringFamily: string
{
    case Decision = 'decision';
}
```

`backend/src/Enum/ScoringProtocol.php` (whole file):

```php
<?php

declare(strict_types=1);

namespace App\Enum;

/** How a scoring model is asked; a connection and a run store it, and the value keys its protocol's implementation. */
enum ScoringProtocol: string
{
    case SystemOne = 'system_one';

    public function family(): ScoringFamily
    {
        return match ($this) {
            self::SystemOne => ScoringFamily::Decision,
        };
    }
}
```

In `backend/src/Entity/ModelDescriptor.php` replace

```php
    public function __construct(
        public string $id,
        public ?int $contextWindow,
        public ?ScoringProtocol $scoringProtocol = null,
    ) {
    }
```
with

```php
    public function __construct(
        public string $id,
        public ?int $contextWindow,
        public ?ScoringProtocol $scoringProtocol = null,
    ) {
        if (null !== $scoringProtocol && ($contextWindow ?? 0) <= 0) {
            throw new \InvalidArgumentException(
                sprintf('The scoring model "%s" needs a positive context window.', $id),
            );
        }
    }
```

In `backend/tests/Controller/Api/ProfileControllerTest.php` (line 125) replace

```php
            new ModelDescriptor('jev-latest', null, ScoringProtocol::SystemOne),
```
with

```php
            new ModelDescriptor('jev-latest', 32_000, ScoringProtocol::SystemOne),
```
(The test is about a stale profile connection; the window is incidental.)

- [ ] **Step 4: Run the tests to see them pass**

Run: `php bin/phpunit tests/Enum tests/Entity/ModelDescriptorTest.php tests/Controller/Api/ProfileControllerTest.php`
Expected: `OK`.

Then `git grep -n 'ScoringProtocol::SystemOne' -- tests | grep -n 'null, ScoringProtocol'` prints nothing (no other fixture builds a scoring descriptor without a window). If it prints a line, give that fixture `32_000` and say so in the report.

- [ ] **Step 5: Deletion checks** (paste each FAIL and the restored OK into the report)

1. `src/Enum/ScoringFamily.php` and `src/Enum/ScoringProtocol.php` (copy both aside): add `case Reserved = 'reserved';` to `ScoringFamily` and make System One's arm return `ScoringFamily::Reserved` → `tests/Enum/ScoringProtocolTest.php` FAILs. Restore both files (`rm -rf var/cache/test` after).
2. `src/Entity/ModelDescriptor.php`: change `($contextWindow ?? 0) <= 0` to `$contextWindow < 0` → `tests/Entity/ModelDescriptorTest.php` FAILs in `testAScoringModelNeedsAPositiveContextWindow` for both data sets.

- [ ] **Step 6: Gates**

```bash
bin/console cache:warmup
composer check
composer md
php bin/phpunit
```
Expected: green; phptramp's warnings at Task 0's count. PhpStorm `lint_files` on both changed `src` files: no ERROR or WARNING.

- [ ] **Step 7: Commit**

```bash
git add src/Enum src/Entity/ModelDescriptor.php tests/Enum/ScoringProtocolTest.php tests/Entity/ModelDescriptorTest.php tests/Controller/Api/ProfileControllerTest.php
git commit -m "feat(#1396): a scoring model has a family and always a context window"
```

---

### Task 2: The listing says what each model is

**Files:**
- Modify: `backend/src/Service/Ai/ModelCatalog/OpenAiCompatibleCatalog.php`, `backend/tests/Service/Ai/ModelCatalog/OpenAiCompatibleCatalogTest.php`

**Interfaces:**
- Consumes: Task 1's `ModelDescriptor` (a scoring descriptor needs a positive window).
- Produces: `OpenAiCompatibleCatalog::listModels()` requests `{base}/models?output_modalities=all` and returns `ModelDescriptor(id, window, null)` for an LLM, `ModelDescriptor(id, window, ScoringProtocol::SystemOne)` for a decision model with a positive window, and nothing for any other entry.

- [ ] **Step 1: Write the failing tests**

In `backend/tests/Service/Ai/ModelCatalog/OpenAiCompatibleCatalogTest.php`:

Add `use App\Enum\ScoringProtocol;` and `use PHPUnit\Framework\Attributes\DataProvider;` to the imports.

In `testItSendsTheKeyAsABearerToken()` replace

```php
        self::assertSame('https://api.example.test/v1/models', $seen['url']);
```
with

```php
        self::assertSame('https://api.example.test/v1/models?output_modalities=all', $seen['url']);
```
(red while the catalog asks the plain listing, which on OpenRouter holds text models only).

Add after `testItReturnsTheOfferedModelsSorted()` (each data set is an entry of OpenRouter's catalog of 2026-10-05, trimmed to the fields read, except the two `acme/*` edge cases; red while the catalog ignores `architecture.output_modalities`, and each row names what it pins):

```php
    /** @return iterable<string, array{array<string, mixed>, ?ModelDescriptor}> */
    public static function listedEntries(): iterable
    {
        yield 'no architecture (LM Studio, OpenAI)' => [
            ['id' => 'qwen3-14b', 'context_length' => 32_768],
            new ModelDescriptor('qwen3-14b', 32_768),
        ];
        yield 'a text model' => [
            self::entry('inclusionai/ling-3.1-flash', 262_144, ['text']),
            new ModelDescriptor('inclusionai/ling-3.1-flash', 262_144),
        ];
        yield 'a model that writes images beside text' => [
            self::entry('google/gemini-3.1-flash-lite-image', 65_536, ['image', 'text']),
            new ModelDescriptor('google/gemini-3.1-flash-lite-image', 65_536),
        ];
        yield 'a decision model' => [
            self::entry('~typesafe/jev-latest', 32_000, ['decisions']),
            new ModelDescriptor('~typesafe/jev-latest', 32_000, ScoringProtocol::SystemOne),
        ];
        yield 'a decision model without a window (Respan)' => [
            self::entry('respan/span-01', 0, ['decisions']),
            null,
        ];
        yield 'a reranker, until its protocol exists' => [
            self::entry('cohere/rerank-4-fast', 32_768, ['rerank']),
            null,
        ];
        yield 'an image model' => [self::entry('bytedance-seed/seedream-5-0-flash', 0, ['image']), null];
        yield 'an embedding model' => [self::entry('liquid/lfm-2.5-embedding-350m:free', 512, ['embeddings']), null];
        yield 'decisions beside another output' => [self::entry('acme/mixed-1', 32_000, ['decisions', 'rerank']), null];
        yield 'no output at all' => [self::entry('acme/silent-1', 32_000, []), null];
    }

    /**
     * A second, plain entry keeps the listing from coming back empty when the entry under test is left out.
     *
     * @param array<string, mixed> $entry
     */
    #[DataProvider('listedEntries')]
    public function testTheListedOutputsDecideWhatAModelIs(array $entry, ?ModelDescriptor $expected): void
    {
        $catalog = $this->catalogAnswering(new MockResponse(json_encode(
            ['data' => [$entry, ['id' => 'anchor-model']]],
            JSON_THROW_ON_ERROR,
        )));

        $models = array_values(array_filter(
            $catalog->listModels($this->credentials()),
            static fn (ModelDescriptor $model): bool => 'anchor-model' !== $model->id,
        ));

        self::assertEquals(null === $expected ? [] : [$expected], $models);
    }

    /**
     * @param list<string> $outputs
     *
     * @return array<string, mixed>
     */
    private static function entry(string $id, int $contextLength, array $outputs): array
    {
        return ['id' => $id, 'context_length' => $contextLength, 'architecture' => ['output_modalities' => $outputs]];
    }
```

- [ ] **Step 2: Run them to see them fail**

Run: `php bin/phpunit tests/Service/Ai/ModelCatalog/OpenAiCompatibleCatalogTest.php`
Expected: FAIL in `testItSendsTheKeyAsABearerToken` (the URL) and in `testTheListedOutputsDecideWhatAModelIs` for the seven sets after the first three: today every entry with an id is listed as an LLM, so "a decision model" lacks its protocol and every left-out set comes back listed. The three LLM sets pass already.

- [ ] **Step 3: Write the catalog**

`backend/src/Service/Ai/ModelCatalog/OpenAiCompatibleCatalog.php` (whole file):

```php
<?php

declare(strict_types=1);

namespace App\Service\Ai\ModelCatalog;

use App\Entity\ModelDescriptor;
use App\Enum\ScoringProtocol;
use App\Service\Ai\Exception\CredentialsRejectedException;
use App\Service\Ai\Exception\ProviderUnreachableException;
use App\Service\Ai\Model\ProviderCredentialsModel;
use App\Service\Ai\Support\ResponseByteCap;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Reads `GET {baseUrl}/models?output_modalities=all`, which every OpenAI-compatible provider answers alike; the
 * parameter makes OpenRouter list its decision models beside the text ones. The caps are no SSRF boundary
 * (docs/security.md#ai-provider-endpoints); they stop one endpoint holding a request open or filling memory.
 */
#[AutoconfigureTag(CompositeModelCatalog::MEMBER_TAG, ['priority' => 10])]
final readonly class OpenAiCompatibleCatalog implements ModelCatalogInterface
{
    private const float TIMEOUT_SECONDS = 10.0;
    private const int MAXIMUM_RESPONSE_BYTES = 1_048_576;
    private const string TEXT_OUTPUT = 'text';
    private const array SCORING_OUTPUTS = ['decisions' => ScoringProtocol::SystemOne];

    public function __construct(
        private HttpClientInterface $httpClient,
        private string $userAgent,
    ) {
    }

    public function listModels(ProviderCredentialsModel $credentials): array
    {
        $body = $this->readBody($credentials);
        $decoded = json_decode($body, true);

        if (!\is_array($decoded) || !isset($decoded['data']) || !\is_array($decoded['data'])) {
            throw new ProviderUnreachableException('That address answered, but not with a model list.');
        }

        $models = $this->descriptors($decoded['data']);

        if ([] === $models) {
            throw new ProviderUnreachableException('That provider offers no models.');
        }

        return $models;
    }

    private function readBody(ProviderCredentialsModel $credentials): string
    {
        try {
            $response = $this->request($credentials);
            $status = $response->getStatusCode();

            if (401 === $status || 403 === $status) {
                throw CredentialsRejectedException::refusedKey();
            }

            if ($status >= 300) {
                throw ProviderUnreachableException::answeredWithStatus($status);
            }

            return $response->getContent();
        } catch (ExceptionInterface $exception) {
            throw ProviderUnreachableException::didNotAnswer($exception);
        }
    }

    private function request(ProviderCredentialsModel $credentials): ResponseInterface
    {
        return $this->httpClient->request('GET', $credentials->baseUrl . '/models?output_modalities=all', [
            'headers' => [
                'Accept' => 'application/json',
                // No transparent compression, so the wire cap below also bounds the decompressed body.
                'Accept-Encoding' => 'identity',
                'User-Agent' => $this->userAgent,
                ...$credentials->authorizationHeaders(),
            ],
            'timeout' => self::TIMEOUT_SECONDS,
            'max_duration' => self::TIMEOUT_SECONDS,
            'max_redirects' => 0,
            'on_progress' => ResponseByteCap::onProgress(self::MAXIMUM_RESPONSE_BYTES),
        ]);
    }

    /**
     * One entry per id: an aggregating proxy (LiteLLM, a gateway) lists a model once per backend, and the frontend
     * keys its dropdown options by id.
     *
     * @param array<mixed> $entries
     *
     * @return list<ModelDescriptor> sorted by id, one entry per id
     */
    private function descriptors(array $entries): array
    {
        $byId = [];

        foreach ($entries as $entry) {
            if (!\is_array($entry) || !isset($entry['id']) || !\is_string($entry['id']) || '' === $entry['id']) {
                continue;
            }
            if (!self::isUsableModel($entry)) {
                continue;
            }
            $byId[$entry['id']] ??= new ModelDescriptor(
                $entry['id'],
                self::reportedContextWindow($entry),
                self::scoringProtocolOf($entry),
            );
        }

        ksort($byId, SORT_STRING);

        return array_values($byId);
    }

    /**
     * A scoring model without a window is left out: its requests could not be budgeted.
     *
     * @param array<mixed> $entry
     */
    private static function isUsableModel(array $entry): bool
    {
        if (self::isTextModel($entry)) {
            return true;
        }

        return null !== self::scoringProtocolOf($entry) && null !== self::reportedContextWindow($entry);
    }

    /**
     * Text among the outputs, or no outputs reported at all (LM Studio, Ollama, OpenAI): what the plain listing offers.
     *
     * @param array<mixed> $entry
     */
    private static function isTextModel(array $entry): bool
    {
        $outputs = self::outputModalities($entry);

        return null === $outputs || \in_array(self::TEXT_OUTPUT, $outputs, true);
    }

    /** @param array<mixed> $entry */
    private static function scoringProtocolOf(array $entry): ?ScoringProtocol
    {
        $outputs = self::outputModalities($entry);
        if (null === $outputs || 1 !== \count($outputs) || !\is_string($outputs[0])) {
            return null;
        }

        return self::SCORING_OUTPUTS[$outputs[0]] ?? null;
    }

    /**
     * @param array<mixed> $entry
     *
     * @return list<mixed>|null null when the entry reports none
     */
    private static function outputModalities(array $entry): ?array
    {
        $architecture = $entry['architecture'] ?? null;
        $outputs = \is_array($architecture) ? ($architecture['output_modalities'] ?? null) : null;

        return \is_array($outputs) ? array_values($outputs) : null;
    }

    /** @param array<mixed> $entry */
    private static function reportedContextWindow(array $entry): ?int
    {
        foreach (['context_length', 'max_context_length'] as $field) {
            if (isset($entry[$field]) && \is_int($entry[$field]) && $entry[$field] > 0) {
                return $entry[$field];
            }
        }

        return null;
    }
}
```

- [ ] **Step 4: Run the tests to see them pass**

Run: `php bin/phpunit tests/Service/Ai/ModelCatalog`
Expected: `OK` (every existing test of this class keeps its assertions: the plain-id and window tests carry no `architecture`).

- [ ] **Step 5: Deletion checks** (paste each FAIL and the restored OK)

1. `request()`: drop `?output_modalities=all` → `testItSendsTheKeyAsABearerToken` FAILs.
2. `isTextModel()`: return `null === $outputs;` → the sets "a text model" and "a model that writes images beside text" FAIL.
3. `isUsableModel()`: drop `&& null !== self::reportedContextWindow($entry)` → `ModelDescriptor`'s guard throws: the set "a decision model without a window (Respan)" errors with `needs a positive context window`.
4. `scoringProtocolOf()`: drop `1 !== \count($outputs) ||` → the set "decisions beside another output" FAILs.

- [ ] **Step 6: Gates** — as Task 1 Step 6, PhpStorm `lint_files` on the catalog.

- [ ] **Step 7: Commit**

```bash
git add src/Service/Ai/ModelCatalog/OpenAiCompatibleCatalog.php tests/Service/Ai/ModelCatalog/OpenAiCompatibleCatalogTest.php
git commit -m "feat(#1396): the model listing tags decision models and leaves out what the app cannot use"
```

---

### Task 3: The System One probe is the fallback

**Files:**
- Create: `backend/src/Service/Ai/Pass/ModelListing.php`
- Modify: `backend/src/Service/Ai/ModelCatalog/CompositeModelCatalog.php`, `backend/src/Service/Ai/ModelCatalog/SystemOneCatalog.php`, `backend/tests/Service/Ai/ModelCatalog/CompositeModelCatalogTest.php`, `backend/tests/Service/Ai/ModelCatalog/ModelCatalogWiringTest.php`

**Interfaces:**
- Consumes: Task 2's tagged listing.
- Produces: `new CompositeModelCatalog(iterable $catalogs, ModelCatalogInterface $systemOneFallback)`; `App\Service\Ai\Pass\ModelListing(ProviderCredentialsModel $credentials)` with `add(ModelCatalogInterface)`, `addOverriding(ModelCatalogInterface)`, `offers(ScoringProtocol): bool`, `models(): list<ModelDescriptor>`. `SystemOneCatalog` is no longer tagged.

- [ ] **Step 1: Write the failing tests**

`backend/tests/Service/Ai/ModelCatalog/CompositeModelCatalogTest.php` (whole file; each test's docblock or name says the case, and Step 5 names the break for each new rule):

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Ai\ModelCatalog;

use App\Entity\ModelDescriptor;
use App\Enum\ScoringProtocol;
use App\Service\Ai\Exception\CredentialsRejectedException;
use App\Service\Ai\Exception\ProviderUnreachableException;
use App\Service\Ai\Model\ProviderCredentialsModel;
use App\Service\Ai\ModelCatalog\CompositeModelCatalog;
use App\Tests\Support\StubModelCatalog;
use PHPUnit\Framework\TestCase;

final class CompositeModelCatalogTest extends TestCase
{
    /** OpenRouter: its listing names decision models, so the probe is never asked and Jev is listed once. */
    public function testAListingThatNamesASystemOneModelIsNeverProbed(): void
    {
        $probes = new \ArrayObject();
        $catalog = new CompositeModelCatalog(
            [new StubModelCatalog([
                new ModelDescriptor('qwen/qwen3.7-flash', 1_000_000),
                new ModelDescriptor('~typesafe/jev-latest', 32_000, ScoringProtocol::SystemOne),
            ])],
            new StubModelCatalog(static function () use ($probes): array {
                $probes->append('probed');

                return [new ModelDescriptor('jev-latest', 32_000, ScoringProtocol::SystemOne)];
            }),
        );

        self::assertSame(
            ['qwen/qwen3.7-flash', '~typesafe/jev-latest'],
            self::ids($catalog->listModels(self::credentials())),
        );
        self::assertCount(0, $probes);
    }

    /** TypeSafe direct: its `/models` is not OpenAI-shaped, and the probe finds the endpoint. */
    public function testWithoutAListedSystemOneModelTheProbeAddsItsAlias(): void
    {
        $catalog = new CompositeModelCatalog(
            [new StubModelCatalog(new ProviderUnreachableException('That address answered, but not with a model list.'))],
            new StubModelCatalog([new ModelDescriptor('jev-latest', 32_000, ScoringProtocol::SystemOne)]),
        );

        self::assertEquals(
            [new ModelDescriptor('jev-latest', 32_000, ScoringProtocol::SystemOne)],
            $catalog->listModels(self::credentials()),
        );
    }

    /** A gateway that lists the alias as a plain model: the probe proved the endpoint, so its tag is kept. */
    public function testTheProbesDescriptionWinsAnIdTheListingNamedUntagged(): void
    {
        $catalog = new CompositeModelCatalog(
            [new StubModelCatalog([new ModelDescriptor('gpt-4o', 128_000), new ModelDescriptor('jev-latest', null)])],
            new StubModelCatalog([new ModelDescriptor('jev-latest', 32_000, ScoringProtocol::SystemOne)]),
        );

        self::assertEquals(
            [
                new ModelDescriptor('gpt-4o', 128_000),
                new ModelDescriptor('jev-latest', 32_000, ScoringProtocol::SystemOne),
            ],
            $catalog->listModels(self::credentials()),
        );
    }

    /** LM Studio: the listing answers, the probe finds no endpoint, and the LLMs are the answer. */
    public function testAProbeThatFindsNoEndpointLeavesTheListingAlone(): void
    {
        $catalog = new CompositeModelCatalog(
            [new StubModelCatalog(['qwen3-14b'])],
            self::noSystemOneEndpoint(),
        );

        self::assertSame(['qwen3-14b'], self::ids($catalog->listModels(self::credentials())));
    }

    public function testItUnitesTheMembersListsSortedAndTheFirstMemberWinsADuplicate(): void
    {
        $catalog = new CompositeModelCatalog(
            [
                new StubModelCatalog([new ModelDescriptor('qwen/qwen3.7', 131_072), new ModelDescriptor('gpt-4o', 1_000)]),
                new StubModelCatalog([
                    new ModelDescriptor('gpt-4o', 128_000),
                    new ModelDescriptor('anthropic/claude-sonnet', 200_000),
                ]),
            ],
            self::noSystemOneEndpoint(),
        );

        self::assertEquals(
            [
                new ModelDescriptor('anthropic/claude-sonnet', 200_000),
                new ModelDescriptor('gpt-4o', 1_000),
                new ModelDescriptor('qwen/qwen3.7', 131_072),
            ],
            $catalog->listModels(self::credentials()),
        );
    }

    public function testAMembersRefusedKeyIsIgnoredWhenTheProbeListsItsAlias(): void
    {
        $catalog = new CompositeModelCatalog(
            [new StubModelCatalog(new CredentialsRejectedException('That provider refused the API key.'))],
            new StubModelCatalog([new ModelDescriptor('jev-latest', 32_000, ScoringProtocol::SystemOne)]),
        );

        self::assertCount(1, $catalog->listModels(self::credentials()));
    }

    /** A bad key on TypeSafe direct: the listing and the probe both fail, and the listing's verdict is the answer. */
    public function testWhenNothingRecognisesTheProviderTheFirstFailureIsTheAnswer(): void
    {
        $catalog = new CompositeModelCatalog(
            [new StubModelCatalog(new CredentialsRejectedException('That provider refused the API key.'))],
            self::noSystemOneEndpoint(),
        );

        $this->expectException(CredentialsRejectedException::class);
        $this->expectExceptionMessage('That provider refused the API key.');

        $catalog->listModels(self::credentials());
    }

    public function testWithoutMembersOrAProbeAnswerThereAreNoModels(): void
    {
        $this->expectExceptionMessage('That provider offers no models.');

        (new CompositeModelCatalog([], new StubModelCatalog([])))->listModels(self::credentials());
    }

    private static function noSystemOneEndpoint(): StubModelCatalog
    {
        return new StubModelCatalog(new ProviderUnreachableException('That address offers no System One endpoint.'));
    }

    /**
     * @param list<ModelDescriptor> $models
     *
     * @return list<string>
     */
    private static function ids(array $models): array
    {
        return array_map(static fn (ModelDescriptor $model): string => $model->id, $models);
    }

    private static function credentials(): ProviderCredentialsModel
    {
        return ProviderCredentialsModel::fromStoredConfiguration('https://api.example.test/v1', 'sk-test');
    }
}
```

`backend/tests/Service/Ai/ModelCatalog/ModelCatalogWiringTest.php` (whole file; red while `SystemOneCatalog` is still a tagged member):

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Ai\ModelCatalog;

use App\Service\Ai\ModelCatalog\CompositeModelCatalog;
use App\Service\Ai\ModelCatalog\ModelCatalogInterface;
use App\Service\Ai\ModelCatalog\OpenAiCompatibleCatalog;
use App\Service\Ai\ModelCatalog\SystemOneCatalog;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/** Only the compiled container proves the tag and the fallback: the listing is a member, the probe is not. */
final class ModelCatalogWiringTest extends KernelTestCase
{
    public function testEveryCallerGetsTheCompositeOverTheListingWithTheProbeAsItsFallback(): void
    {
        self::bootKernel();
        $catalog = self::getContainer()->get(ModelCatalogInterface::class);
        self::assertInstanceOf(CompositeModelCatalog::class, $catalog);

        /** @var iterable<object> $members */
        $members = (new \ReflectionProperty(CompositeModelCatalog::class, 'catalogs'))->getValue($catalog);
        $classes = [];
        foreach ($members as $member) {
            $classes[] = $member::class;
        }

        self::assertSame([OpenAiCompatibleCatalog::class], $classes);
        self::assertInstanceOf(
            SystemOneCatalog::class,
            (new \ReflectionProperty(CompositeModelCatalog::class, 'systemOneFallback'))->getValue($catalog),
        );
    }
}
```

- [ ] **Step 2: Run them to see them fail**

Run: `php bin/phpunit tests/Service/Ai/ModelCatalog/CompositeModelCatalogTest.php tests/Service/Ai/ModelCatalog/ModelCatalogWiringTest.php`
Expected: PHP ignores the second constructor argument of today's one-argument composite, so the probe stub is never asked: `testWithoutAListedSystemOneModelTheProbeAddsItsAlias`, `testTheProbesDescriptionWinsAnIdTheListingNamedUntagged` and `testAMembersRefusedKeyIsIgnoredWhenTheProbeListsItsAlias` FAIL; the wiring test FAILs on `[OpenAiCompatibleCatalog, SystemOneCatalog]`.

- [ ] **Step 3: Write the composite and its listing**

`backend/src/Service/Ai/Pass/ModelListing.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Ai\Pass;

use App\Entity\ModelDescriptor;
use App\Enum\ScoringProtocol;
use App\Service\Ai\Exception\CredentialsRejectedException;
use App\Service\Ai\Exception\ProviderUnreachableException;
use App\Service\Ai\Model\ProviderCredentialsModel;
use App\Service\Ai\ModelCatalog\ModelCatalogInterface;

/** One composite listing: the models by id, and the failures of the catalogs that did not recognise the provider. */
final class ModelListing
{
    /** @var array<string, ModelDescriptor> */
    private array $byId = [];

    /** @var list<CredentialsRejectedException|ProviderUnreachableException> */
    private array $failures = [];

    public function __construct(private readonly ProviderCredentialsModel $credentials)
    {
    }

    public function add(ModelCatalogInterface $catalog): void
    {
        $this->byId += $this->answerOf($catalog);
    }

    /** The catalog's description wins an id this listing holds already. */
    public function addOverriding(ModelCatalogInterface $catalog): void
    {
        $this->byId = $this->answerOf($catalog) + $this->byId;
    }

    public function offers(ScoringProtocol $protocol): bool
    {
        foreach ($this->byId as $model) {
            if ($protocol === $model->scoringProtocol) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<ModelDescriptor> sorted by id
     *
     * @throws CredentialsRejectedException|ProviderUnreachableException the first failure, when no catalog answered
     */
    public function models(): array
    {
        if ([] === $this->byId) {
            throw $this->failures[0] ?? new ProviderUnreachableException('That provider offers no models.');
        }
        $byId = $this->byId;
        ksort($byId, \SORT_STRING);

        return array_values($byId);
    }

    /** @return array<string, ModelDescriptor> the catalog's first description per id; none when it failed */
    private function answerOf(ModelCatalogInterface $catalog): array
    {
        try {
            $byId = [];
            foreach ($catalog->listModels($this->credentials) as $model) {
                $byId[$model->id] ??= $model;
            }

            return $byId;
        } catch (CredentialsRejectedException | ProviderUnreachableException $failure) {
            $this->failures[] = $failure;

            return [];
        }
    }
}
```

`backend/src/Service/Ai/ModelCatalog/CompositeModelCatalog.php` (whole file):

```php
<?php

declare(strict_types=1);

namespace App\Service\Ai\ModelCatalog;

use App\Enum\ScoringProtocol;
use App\Service\Ai\Model\ProviderCredentialsModel;
use App\Service\Ai\Pass\ModelListing;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * The catalog every caller sees: each member that recognises the provider adds its models, and the System One probe
 * adds its alias only when no member listed a System One model, so OpenRouter lists Jev once. When nothing answers,
 * the first failure is the answer.
 */
final readonly class CompositeModelCatalog implements ModelCatalogInterface
{
    public const string MEMBER_TAG = 'app.model_catalog';

    /** @param iterable<ModelCatalogInterface> $catalogs by descending priority */
    public function __construct(
        #[AutowireIterator(self::MEMBER_TAG)]
        private iterable $catalogs,
        #[Autowire(service: SystemOneCatalog::class)]
        private ModelCatalogInterface $systemOneFallback,
    ) {
    }

    public function listModels(ProviderCredentialsModel $credentials): array
    {
        $listing = new ModelListing($credentials);
        foreach ($this->catalogs as $catalog) {
            $listing->add($catalog);
        }
        if (!$listing->offers(ScoringProtocol::SystemOne)) {
            $listing->addOverriding($this->systemOneFallback);
        }

        return $listing->models();
    }
}
```

In `backend/src/Service/Ai/ModelCatalog/SystemOneCatalog.php` remove `use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;` and the attribute line `#[AutoconfigureTag(CompositeModelCatalog::MEMBER_TAG, ['priority' => 0])]`, and replace the class docblock

```php
/**
 * Offers TypeSafe's `jev-latest` alias wherever `{base}/systemone` exists: OpenRouter's `/models` does not list it and
 * TypeSafe's own is not OpenAI-shaped. An alias, never a pinned version; the run log records which one answered.
 */
```
with

```php
/**
 * Offers TypeSafe's `jev-latest` alias wherever `{base}/systemone` exists, for a provider whose listing names no System
 * One model (TypeSafe's own `/models` is not OpenAI-shaped). An alias, never a pinned version; the run log records
 * which one answered.
 */
```

- [ ] **Step 4: Run the tests to see them pass**

Run: `rm -rf var/cache/test && php bin/phpunit tests/Service/Ai tests/Controller/Api/AiSettingsControllerTest.php`
Expected: `OK`.

- [ ] **Step 5: Deletion checks** (paste each FAIL and the restored OK)

1. `CompositeModelCatalog::listModels()`: drop the `if (!$listing->offers(...))` guard so the probe is always added → `testAListingThatNamesASystemOneModelIsNeverProbed` FAILs.
2. `ModelListing::addOverriding()`: make it `$this->byId += $this->answerOf($catalog);` → `testTheProbesDescriptionWinsAnIdTheListingNamedUntagged` FAILs.
3. `ModelListing::offers()`: return `true` → `testWithoutAListedSystemOneModelTheProbeAddsItsAlias` FAILs.
4. `SystemOneCatalog`: put the `#[AutoconfigureTag(...)]` back → `ModelCatalogWiringTest` FAILs (`rm -rf var/cache/test` before and after; `touch` the restored file).

- [ ] **Step 6: Gates** — as Task 1 Step 6; phptramp's warnings at Task 0's count (the credentials end in `ModelListing`'s field). PhpStorm `lint_files` on the three `src` files.

- [ ] **Step 7: Commit**

```bash
git add src/Service/Ai tests/Service/Ai/ModelCatalog
git commit -m "feat(#1396): the system one probe answers only when no listing names a system one model"
```

---

### Task 4: The budget is the protocol's and scales with the window

**Files:**
- Delete: `backend/src/Service/Recommendation/Scoring/Factory/ScoringBudgetFactory.php`, `backend/tests/Service/Recommendation/Scoring/Factory/ScoringBudgetFactoryTest.php`
- Create: `backend/tests/Service/Recommendation/Scoring/ScoringRecommendationEnginePackingTest.php`
- Modify: `backend/src/Service/Recommendation/Scoring/Model/ScoringBudgetModel.php`, `…/Scoring/ScoringProtocol/{ScoringProtocolInterface,SystemOneProtocol}.php`, `…/Scoring/{ScoringRecommendationEngine,ScoringBatchWave}.php`, `backend/src/Service/Recommendation/Run/Pass/TickContext.php`, `backend/src/Service/Ai/ModelCatalog/SystemOneCatalog.php:24`, `backend/tests/Service/Recommendation/Scoring/Model/ScoringBudgetModelTest.php`, `…/Scoring/ScoringProtocol/SystemOneProtocolTest.php`, `…/Scoring/ScoringRecommendationEngineTest.php`, `backend/tests/Service/Recommendation/Run/Pass/TickContextTest.php`

**Interfaces:**
- Consumes: PR 1's `ScoringBudgetModel(int $contextWindowTokens, int $maxItemsPerRequest, int $stateTokens, int $framingTokens)` with `itemTokens()`; `TickContext::requireScoringProtocol()`.
- Produces: `ScoringBudgetModel::forWindow(int $contextWindowTokens, int $maxItemsPerRequest): self`; `ScoringProtocolInterface::budget(int $contextWindowTokens): ScoringBudgetModel`; `SystemOneProtocol::QUESTIONS_PER_REQUEST = 64` (public); `TickContext::requireScoringContextWindow(): int`.

- [ ] **Step 1: Write the failing tests**

`backend/tests/Service/Recommendation/Scoring/Model/ScoringBudgetModelTest.php` (whole file; red while `forWindow()` is missing; the boundary pair pins the 10,000 cap and the integer division):

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

    /** Kev's 8k window: the reader gets 30 %, the framing 2,000, the articles the rest. */
    public function testASmallWindowGivesTheReaderThirtyPercent(): void
    {
        $budget = ScoringBudgetModel::forWindow(8_192, 7);

        self::assertEquals(new ScoringBudgetModel(8_192, 7, 2_457, 2_000), $budget);
        self::assertSame(3_735, $budget->itemTokens());
    }

    public function testTheReadersShareStopsAtTenThousandTokens(): void
    {
        self::assertSame(9_999, ScoringBudgetModel::forWindow(33_333, 7)->stateTokens);
        self::assertSame(10_000, ScoringBudgetModel::forWindow(33_334, 7)->stateTokens);
        self::assertSame(10_000, ScoringBudgetModel::forWindow(65_536, 7)->stateTokens);
    }
}
```

`backend/tests/Service/Recommendation/Scoring/ScoringProtocol/SystemOneProtocolTest.php` (whole file; red while `budget()` is missing; the cap test is red while the cap is 100). **Assumption (verify):** the heavy articles still pack 27 to a request at 20,400 tokens — the docblock's 731 tokens a question gives 27 × 731 = 19,737 ≤ 20,400 < 28 × 731; if the run shows 28, the per-question figure in the docblock is off: report it and correct both numbers from the run.

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Recommendation\Scoring\ScoringProtocol;

use App\Service\Ai\Model\ProviderCredentialsModel;
use App\Service\Recommendation\Pool\Model\ArticleLineModel;
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
    /** Clef refuses more than 64 questions; the reader's share scales with the model's window. */
    public function testARequestCarriesAtMost64QuestionsAndTheReaderAShareOfTheWindow(): void
    {
        $protocol = self::protocol(new StubSystemOneClient());

        self::assertEquals(new ScoringBudgetModel(32_000, 64, 9_600, 2_000), $protocol->budget(32_000));
        self::assertEquals(new ScoringBudgetModel(8_192, 64, 2_457, 2_000), $protocol->budget(8_192));
    }

    /** Short Latin articles fit the token budget by far: the question cap closes each request. */
    public function testShortArticlesFillRequestsUpToTheQuestionCapInPoolOrder(): void
    {
        $candidates = self::candidates(250, 'Short title', 'Short description.');

        $batches = self::protocol(new StubSystemOneClient())->pack(self::budget(), $candidates);

        self::assertSame([64, 64, 64, 58], array_map(\count(...), $batches));
        self::assertSame(range(1, 250), array_merge(...$batches));
    }

    /**
     * Three-byte characters in every field, 731 tokens a question: 20,400 tokens (32k less 2k framing and 9.6k state)
     * hold 27 of them, so the token budget closes each request before the question cap.
     */
    public function testHeavyArticlesFillRequestsUpToTheTokenBudget(): void
    {
        $candidates = self::candidates(120, str_repeat('漢', 300), str_repeat('漢', 600));

        $batches = self::protocol(new StubSystemOneClient())->pack(self::budget(), $candidates);

        self::assertSame([27, 27, 27, 27, 12], array_map(\count(...), $batches));
        self::assertSame(range(1, 120), array_merge(...$batches));
        $factory = self::requestFactory();
        foreach ($batches as $batch) {
            $tokens = 0;
            foreach ($batch as $entryId) {
                $tokens += TokenEstimate::of(CompactJson::encode($factory->question($candidates[$entryId - 1])));
            }
            self::assertLessThanOrEqual(20_400, $tokens);
        }
    }

    public function testTheRunLogGetsTheSystemOneBodyPrettyPrinted(): void
    {
        $request = self::request([new ArticleLineModel(41, 'Kernel 6.18', 'LWN', '2026-10-01', null)]);

        self::assertSame(
            self::requestFactory()->create($request)->toRenderedRequest(),
            self::protocol(new StubSystemOneClient())->renderedRequest($request),
        );
    }

    public function testEveryRequestIsAskedAsItsSystemOneRequestAndAnsweredInItsPlace(): void
    {
        $client = new StubSystemOneClient();
        $client->queueNouls(static fn (int $entryId): float => 0.25);
        $client->queueNouls(static fn (int $entryId): float => 0.75);

        $outcomes = self::protocol($client)->scoreMany(
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

    private static function protocol(StubSystemOneClient $client): SystemOneProtocol
    {
        return new SystemOneProtocol(new ScoringBatchPacker(), self::requestFactory(), $client);
    }

    private static function requestFactory(): SystemOneRequestFactory
    {
        return new SystemOneRequestFactory(new ScoringStateFactory());
    }

    /** Jev's window. */
    private static function budget(): ScoringBudgetModel
    {
        return self::protocol(new StubSystemOneClient())->budget(32_000);
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
    private static function candidates(int $count, string $title, string $description): array
    {
        return array_map(
            static fn (int $entryId): ArticleLineModel
                => new ArticleLineModel($entryId, $title, 'Feed', '2026-10-01', $description),
            range(1, $count),
        );
    }
}
```

In `backend/tests/Service/Recommendation/Run/Pass/TickContextTest.php` add after `testAnLlmConnectionsTickHasNoProtocolToRequire()` (red while `requireScoringContextWindow()` is missing):

```php
    public function testAScoringConnectionsTickNamesItsModelsWindow(): void
    {
        $connection = $this->connection();
        $connection->chooseModel(
            new ModelDescriptor('acme/decider-2', 16_000, ScoringProtocol::SystemOne),
            new \DateTimeImmutable(self::AT),
        );

        self::assertSame(16_000, $this->tick($connection, TickDriver::Worker)->requireScoringContextWindow());
    }

    public function testATickWhoseConnectionStoresNoWindowHasNoneToRequire(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('This tick\'s connection stores no context window for its scoring model.');

        $this->tick($this->connection(), TickDriver::Worker)->requireScoringContextWindow();
    }
```

`backend/tests/Service/Recommendation/Scoring/ScoringRecommendationEnginePackingTest.php` (red while the engine packs against a fixed 32k window; the engine comes from the compiled container through the resolver, the tick is built in memory and never persisted). **Assumption (verify):** 731-token questions pack `[5, 5, 2]` into 3,735 tokens (5 × 731 = 3,655; 6 × 731 = 4,386) and `[12]` into 20,400.

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Recommendation\Scoring;

use App\Entity\ModelDescriptor;
use App\Entity\RecommendationHistoryCaps;
use App\Entity\RecommendationPoolLimits;
use App\Entity\RecommendationRun;
use App\Entity\User;
use App\Enum\RecommendationBatchSize;
use App\Enum\RecommendationEngineKind;
use App\Enum\ScoringProtocol;
use App\Service\Recommendation\Engine\RecommendationEngineResolver;
use App\Service\Recommendation\Pool\Model\ArticleLineModel;
use App\Service\Recommendation\Run\Model\TickDriver;
use App\Service\Recommendation\Run\Pass\TickContext;
use App\Service\Recommendation\Settings\Model\EffectiveRecommendationSettingsModel;
use App\Service\Recommendation\Settings\Model\RecommendationPackingSettingsModel;
use App\Tests\Support\AiProviderSettingsFactory;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ScoringRecommendationEnginePackingTest extends KernelTestCase
{
    private const string AT = '2026-10-05 09:00:00';

    /** 731-token questions (SystemOneProtocolTest): Kev's 8k window takes five a request, Jev's 32k all twelve. */
    public function testEachRequestIsBoundedByTheConnectionsWindow(): void
    {
        self::assertSame([5, 5, 2], $this->requestSizes(8_192));
        self::assertSame([12], $this->requestSizes(32_000));
    }

    /** @return list<int> */
    private function requestSizes(int $contextWindowTokens): array
    {
        $engines = self::getContainer()->get(RecommendationEngineResolver::class);
        self::assertInstanceOf(RecommendationEngineResolver::class, $engines);

        $batches = $engines->engineOf(RecommendationEngineKind::Scoring)->packBatches(
            self::heavyCandidates(12),
            $this->tick($contextWindowTokens),
        );

        return array_map(\count(...), $batches);
    }

    private function tick(int $contextWindowTokens): TickContext
    {
        $connection = AiProviderSettingsFactory::build(new User('packing@example.test', new \DateTimeImmutable(self::AT)));
        $connection->chooseModel(
            new ModelDescriptor('acme/decider-2', $contextWindowTokens, ScoringProtocol::SystemOne),
            new \DateTimeImmutable(self::AT),
        );

        return new TickContext(
            new RecommendationRun($connection->getUser(), new \DateTimeImmutable(self::AT)),
            $connection,
            RecommendationEngineKind::Scoring,
            new EffectiveRecommendationSettingsModel(
                guidancePrompt: null,
                historyCaps: RecommendationHistoryCaps::defaults(),
                poolLimits: RecommendationPoolLimits::defaults(),
                packing: new RecommendationPackingSettingsModel(
                    contextWindow: 32768,
                    contextWindowSource: 'fallback',
                    batchSize: RecommendationBatchSize::Medium,
                    maximumBatchSize: RecommendationPackingSettingsModel::DEFAULT_MAXIMUM_BATCH_SIZE,
                ),
                debugEnabled: false,
                autoGenerateIntervalHours: null,
                showScoreAndReasons: false,
            ),
            TickDriver::Worker,
        );
    }

    /** @return list<ArticleLineModel> entry ids 1…$count, three-byte characters in every field */
    private static function heavyCandidates(int $count): array
    {
        return array_map(
            static fn (int $entryId): ArticleLineModel => new ArticleLineModel(
                $entryId,
                str_repeat('漢', 300),
                'Feed',
                '2026-10-01',
                str_repeat('漢', 600),
            ),
            range(1, $count),
        );
    }
}
```

In `backend/tests/Service/Recommendation/Scoring/ScoringRecommendationEngineTest.php`:

- Add to the imports: `use App\Service\Recommendation\Scoring\ScoringProtocol\SystemOneProtocol;`, `use App\Service\Recommendation\Scoring\Support\CompactJson;`, `use App\Service\Recommendation\Support\TokenEstimate;`.
- Replace every `$this->startRunAfterTheWarmUp(101, ` with `$this->startRunAfterTheWarmUp(SystemOneProtocol::QUESTIONS_PER_REQUEST + 1, ` and every `$this->startRunAfterTheWarmUp(301, ` with `$this->startRunAfterTheWarmUp(3 * SystemOneProtocol::QUESTIONS_PER_REQUEST + 1, ` (D9: each test keeps its batch shape — one full batch and one more, or three full and one):

```bash
perl -pi -e 's/startRunAfterTheWarmUp\(101, /startRunAfterTheWarmUp(SystemOneProtocol::QUESTIONS_PER_REQUEST + 1, /g; s/startRunAfterTheWarmUp\(301, /startRunAfterTheWarmUp(3 * SystemOneProtocol::QUESTIONS_PER_REQUEST + 1, /g; s/banks the first 100-question batch/banks the first full batch/' tests/Service/Recommendation/Scoring/ScoringRecommendationEngineTest.php
grep -n 'startRunAfterTheWarmUp(\|first full batch' tests/Service/Recommendation/Scoring/ScoringRecommendationEngineTest.php
```
Expected: no `101`/`301` left; the helper's docblock reads "banks the first full batch".

- Add after `testEveryWaveSendsTheReadersFavorites()` (red while the wave words its request with a fixed 32k budget; a 24,000-byte profile is 6,001 tokens, which a 9,600-token state would carry whole):

```php
    /** Kev's 8k window gives the reader 2,457 tokens: a longer profile is cut to fit. */
    public function testTheStateIsFittedToTheConnectionsWindow(): void
    {
        $this->fixtures->seedFeedWithEntries($this->owner, 5);
        $connection = $this->owner->getActiveAiProviderSettings();
        self::assertNotNull($connection);
        $connection->chooseModel(
            new ModelDescriptor('jaredpalmer/kev-4b', 8_192, ScoringProtocol::SystemOne),
            new \DateTimeImmutable('2026-10-05 09:00:00'),
        );
        $this->entityManager->flush();
        $this->fixtures->storeProfile($this->owner, str_repeat('Likes Rust and homelab. ', 1_000));
        $this->starter()->start($this->owner);
        $this->advancer()->advance($this->owner);   // the snapshot
        $this->systemOne()->queueNouls(static fn (int $entryId): float => 0.5);

        $this->advancer()->advance($this->owner);

        $requests = $this->systemOne()->requests();
        self::assertCount(1, $requests);
        $stateTokens = TokenEstimate::of(CompactJson::encode($requests[0]->state));
        self::assertLessThanOrEqual(2_457, $stateTokens);
        self::assertGreaterThan(2_000, $stateTokens);
    }
```

- [ ] **Step 2: Run them to see them fail**

Run: `php bin/phpunit tests/Service/Recommendation/Scoring tests/Service/Recommendation/Run/Pass/TickContextTest.php`
Expected: errors `Call to undefined method …ScoringBudgetModel::forWindow()`, `…SystemOneProtocol::budget()`, `…TickContext::requireScoringContextWindow()`, `Undefined constant …SystemOneProtocol::QUESTIONS_PER_REQUEST`.

- [ ] **Step 3: Write the budget**

`backend/src/Service/Recommendation/Scoring/Model/ScoringBudgetModel.php` (whole file):

```php
<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Scoring\Model;

/** One scoring request's limits: the model's window, how many articles it may carry, and what the reader takes. */
final readonly class ScoringBudgetModel
{
    private const int READER_SHARE_PERCENT = 30;
    private const int MAXIMUM_READER_TOKENS = 10_000;

    /** The request's own framing and the estimate's error. */
    private const int FRAMING_TOKENS = 2_000;

    public function __construct(
        public int $contextWindowTokens,
        public int $maxItemsPerRequest,
        public int $stateTokens,
        public int $framingTokens,
    ) {
    }

    public static function forWindow(int $contextWindowTokens, int $maxItemsPerRequest): self
    {
        return new self(
            $contextWindowTokens,
            $maxItemsPerRequest,
            min(self::MAXIMUM_READER_TOKENS, intdiv($contextWindowTokens * self::READER_SHARE_PERCENT, 100)),
            self::FRAMING_TOKENS,
        );
    }

    /** The state is budgeted at its ceiling, which the state factory never exceeds. */
    public function itemTokens(): int
    {
        return $this->contextWindowTokens - $this->framingTokens - $this->stateTokens;
    }
}
```

`backend/src/Service/Recommendation/Scoring/ScoringProtocol/ScoringProtocolInterface.php` (whole file):

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
    /** What one request may carry for a model with this context window. */
    public function budget(int $contextWindowTokens): ScoringBudgetModel;

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

In `backend/src/Service/Recommendation/Scoring/ScoringProtocol/SystemOneProtocol.php` replace

```php
final readonly class SystemOneProtocol implements ScoringProtocolInterface
{
    public function __construct(
```
with

```php
final readonly class SystemOneProtocol implements ScoringProtocolInterface
{
    /** Cloudflare's Clef refuses more, the smallest cap measured (#1394); every System One model takes 64. */
    public const int QUESTIONS_PER_REQUEST = 64;

    public function __construct(
```
and replace

```php
    public function pack(ScoringBudgetModel $budget, array $candidates): array
```
with

```php
    public function budget(int $contextWindowTokens): ScoringBudgetModel
    {
        return ScoringBudgetModel::forWindow($contextWindowTokens, self::QUESTIONS_PER_REQUEST);
    }

    public function pack(ScoringBudgetModel $budget, array $candidates): array
```

In `backend/src/Service/Recommendation/Run/Pass/TickContext.php` add after `requireScoringProtocol()`:

```php

    public function requireScoringContextWindow(): int
    {
        return $this->connection->getModelContextWindow()
            ?? throw new \LogicException('This tick\'s connection stores no context window for its scoring model.');
    }
```

In `backend/src/Service/Recommendation/Scoring/ScoringRecommendationEngine.php` remove `use App\Service\Recommendation\Scoring\Factory\ScoringBudgetFactory;` and the constructor line `        private ScoringBudgetFactory $budgetFactory,`, and replace

```php
    public function packBatches(array $candidates, TickContext $tick): array
    {
        $protocol = $tick->requireScoringProtocol();

        return $this->protocols->protocolOf($protocol)->pack($this->budgetFactory->create($protocol), $candidates);
    }
```
with

```php
    public function packBatches(array $candidates, TickContext $tick): array
    {
        $protocol = $this->protocols->protocolOf($tick->requireScoringProtocol());

        return $protocol->pack($protocol->budget($tick->requireScoringContextWindow()), $candidates);
    }
```

In `backend/src/Service/Recommendation/Scoring/ScoringBatchWave.php` remove `use App\Service\Recommendation\Scoring\Factory\ScoringBudgetFactory;` and the constructor line `        private ScoringBudgetFactory $budgetFactory,`, and replace

```php
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
```
with

```php
        $tick = $wave->tick();
        $protocol = $this->protocols->protocolOf($tick->requireScoringProtocol());
        $waveBatch = $wave->batches()[$position];
        $request = new ScoringRequestModel(
            $tick->connection->getModel() ?? '',
            $wave->reader,
            $protocol->budget($tick->requireScoringContextWindow()),
            $waveBatch->linesInSnapshotOrder(),
        );
        $recordedCall = $this->callRecorder->begin(
            $tick->run,
            CallSlotModel::batch($waveBatch->index + 1),
            $protocol->renderedRequest($request),
        );
```

Delete the factory and its test, and close the constant it read:

```bash
git rm src/Service/Recommendation/Scoring/Factory/ScoringBudgetFactory.php tests/Service/Recommendation/Scoring/Factory/ScoringBudgetFactoryTest.php
perl -pi -e 's/    public const int CONTEXT_WINDOW_TOKENS = 32_000;/    private const int CONTEXT_WINDOW_TOKENS = 32_000;/' src/Service/Ai/ModelCatalog/SystemOneCatalog.php
git grep -n 'ScoringBudgetFactory\|budgetFactory\|CONTEXT_WINDOW_TOKENS' -- src tests
```
Expected: the last command prints only `SystemOneCatalog.php`'s own two lines.

- [ ] **Step 4: Run the tests to see them pass**

Run: `rm -rf var/cache/test && php bin/phpunit tests/Service/Recommendation tests/Service/Ai`
Expected: `OK`. Report the two **Assumption (verify)** outcomes (27 per heavy request; `[5, 5, 2]`).

- [ ] **Step 5: Deletion checks** (paste each FAIL and the restored OK)

1. `ScoringBudgetModel::forWindow()`: replace `min(` with `max(` → `ScoringBudgetModelTest` FAILs in both share tests.
2. `SystemOneProtocol`: `QUESTIONS_PER_REQUEST = 100` → `SystemOneProtocolTest` FAILs in the budget and short-articles tests.
3. `ScoringRecommendationEngine::packBatches()`: `$protocol->budget(32_000)` → `ScoringRecommendationEnginePackingTest` FAILs (`[12]` for 8,192).
4. `ScoringBatchWave::open()`: `$protocol->budget(32_000)` → `ScoringRecommendationEngineTest::testTheStateIsFittedToTheConnectionsWindow` FAILs.
5. `TickContext::requireScoringContextWindow()`: return `32_000` → `TickContextTest::testAScoringConnectionsTickNamesItsModelsWindow` FAILs.

- [ ] **Step 6: Gates** — as Task 1 Step 6, plus the MySQL leg (`docker compose exec php sh -c 'rm -rf var/cache-docker/test*'`, then `docker compose exec php composer test`), since the engine tests run against the database. PhpStorm `lint_files` on every changed `src` file.

- [ ] **Step 7: Commit**

```bash
git add -A src/Service/Recommendation src/Service/Ai/ModelCatalog/SystemOneCatalog.php tests/Service/Recommendation
git commit -m "feat(#1396): a scoring request is sized by its protocol's cap and its model's window"
```

---

### Task 5: The API names each model's kind and family

**Files:**
- Modify: `backend/src/Http/AiSettingsJson.php`, `backend/tests/Http/AiSettingsJsonTest.php`, `backend/tests/Controller/Api/AiSettingsControllerTest.php`

**Interfaces:**
- Consumes: Task 1's `ScoringProtocol::family()`; `RecommendationEngineResolver::kindFor(AiProviderSettings): RecommendationEngineKind`.
- Produces: `new AiSettingsJson(RecommendationCapabilitiesJson $capabilities, RecommendationEngineResolver $engines)`; configuration JSON gains `kind` (`'llm'|'scoring'`, after `model`) and `family` (`'decision'|null`); each model row is `{id, label, kind, family, capabilities}`.

- [ ] **Step 1: Write the failing tests**

In `backend/tests/Http/AiSettingsJsonTest.php`:

Add to the imports `use App\Service\Recommendation\Engine\RecommendationEngineResolver;` and `use Symfony\Component\DependencyInjection\ServiceLocator;`, and replace

```php
        return new AiSettingsJson(RecommendationCapabilitiesJsons::ofTheKind());
```
with

```php
        return new AiSettingsJson(
            RecommendationCapabilitiesJsons::ofTheKind(),
            new RecommendationEngineResolver(new ServiceLocator([])),
        );
```

Add after `testTheConfigurationShapeCarriesItsKindsCapabilities()` (red while the shape has no `kind`/`family`; the second is red later if the kind skips the resolver's default):

```php
    public function testAConfigurationNamesTheKindAndFamilyOfItsModel(): void
    {
        $settings = $this->settings(null);
        $settings->chooseModel(
            new ModelDescriptor('acme/decider-2', 16_000, ScoringProtocol::SystemOne),
            new \DateTimeImmutable('2026-08-06 10:00:00'),
        );

        $shape = $this->json()->configuration($settings, null);

        self::assertSame('scoring', $shape['kind']);
        self::assertSame('decision', $shape['family']);
    }

    /** No model yet: the default kind, as its capabilities already say, and no family. */
    public function testAConfigurationWithoutAModelIsAnLlmWithoutAFamily(): void
    {
        $shape = $this->json()->configuration($this->settings(null), null);

        self::assertSame('llm', $shape['kind']);
        self::assertNull($shape['family']);
    }
```

In `testAddedCarriesTheOfferedModelsAlongsideTheConfiguration()` replace

```php
                ['id' => 'gpt-4o', 'label' => null, 'capabilities' => RecommendationCapabilitiesJsons::LLM],
                ['id' => 'gpt-4o-mini', 'label' => null, 'capabilities' => RecommendationCapabilitiesJsons::LLM],
```
with

```php
                [
                    'id' => 'gpt-4o',
                    'label' => null,
                    'kind' => 'llm',
                    'family' => null,
                    'capabilities' => RecommendationCapabilitiesJsons::LLM,
                ],
                [
                    'id' => 'gpt-4o-mini',
                    'label' => null,
                    'kind' => 'llm',
                    'family' => null,
                    'capabilities' => RecommendationCapabilitiesJsons::LLM,
                ],
```

Replace the whole `testEachOfferedModelCarriesTheLabelAndCapabilitiesOfItsTag()` with:

```php
    /** The catalog's tag decides, never the id: `jev-router` is an LLM, `acme/decider-2` a decision model. */
    public function testEachOfferedModelCarriesTheLabelKindFamilyAndCapabilitiesOfItsTag(): void
    {
        self::assertSame(
            [
                'models' => [
                    [
                        'id' => 'jev-router',
                        'label' => null,
                        'kind' => 'llm',
                        'family' => null,
                        'capabilities' => RecommendationCapabilitiesJsons::LLM,
                    ],
                    [
                        'id' => 'acme/decider-2',
                        'label' => 'System One',
                        'kind' => 'scoring',
                        'family' => 'decision',
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

In `backend/tests/Controller/Api/AiSettingsControllerTest.php` (the wire through the controller), in `testAddingAConfigurationReturnsItWithTheOfferedModels()` replace

```php
                ['id' => 'gpt-4o', 'label' => null, 'capabilities' => RecommendationCapabilitiesJsons::LLM],
                ['id' => 'gpt-4o-mini', 'label' => null, 'capabilities' => RecommendationCapabilitiesJsons::LLM],
```
with the same eight-key-wide rows as above (`'kind' => 'llm', 'family' => null` between `label` and `capabilities`), and in `testListingModelsMarksAScoringModelByTheLabelAndCapabilitiesItWouldGive()` replace

```php
                    ['id' => 'gpt-4o', 'label' => null, 'capabilities' => RecommendationCapabilitiesJsons::LLM],
                    [
                        'id' => 'jev-latest',
                        'label' => 'System One',
                        'capabilities' => RecommendationCapabilitiesJsons::SCORING,
                    ],
```
with

```php
                    [
                        'id' => 'gpt-4o',
                        'label' => null,
                        'kind' => 'llm',
                        'family' => null,
                        'capabilities' => RecommendationCapabilitiesJsons::LLM,
                    ],
                    [
                        'id' => 'jev-latest',
                        'label' => 'System One',
                        'kind' => 'scoring',
                        'family' => 'decision',
                        'capabilities' => RecommendationCapabilitiesJsons::SCORING,
                    ],
```
and rename that test to `testListingModelsMarksAScoringModelByItsKindFamilyAndTheCapabilitiesItWouldGive`.

- [ ] **Step 2: Run them to see them fail**

Run: `php bin/phpunit tests/Http/AiSettingsJsonTest.php tests/Controller/Api/AiSettingsControllerTest.php`
Expected: FAIL — `Undefined array key "kind"` and the model-row comparisons.

- [ ] **Step 3: Write the mapper**

In `backend/src/Http/AiSettingsJson.php`:

Add `use App\Service\Recommendation\Engine\RecommendationEngineResolver;` to the imports, and replace

```php
    public function __construct(private RecommendationCapabilitiesJson $capabilities)
    {
    }
```
with

```php
    public function __construct(
        private RecommendationCapabilitiesJson $capabilities,
        private RecommendationEngineResolver $engines,
    ) {
    }
```

In `configuration()` replace

```php
            'model' => $settings->getModel(),
```
with

```php
            'model' => $settings->getModel(),
            'kind' => $this->engines->kindFor($settings)->value,
            'family' => self::familyOf($settings->getScoringProtocol()),
```

Replace the whole `models()` method with

```php
    /**
     * @param list<ModelDescriptor> $models
     *
     * @return array{models: list<array{
     *     id: string, label: ?string, kind: string, family: ?string, capabilities: array<string, mixed>
     * }>}
     */
    public function models(array $models): array
    {
        return [
            'models' => array_map(
                fn (ModelDescriptor $model): array => [
                    'id' => $model->id,
                    'label' => self::labelOf($model->scoringProtocol),
                    'kind' => $model->kind()->value,
                    'family' => self::familyOf($model->scoringProtocol),
                    'capabilities' => $this->capabilities->ofKind($model->kind()),
                ],
                $models,
            ),
        ];
    }
```
and add after `labelOf()`:

```php

    private static function familyOf(?ScoringProtocol $protocol): ?string
    {
        return $protocol?->family()->value;
    }
```

- [ ] **Step 4: Run the tests to see them pass**

Run: `rm -rf var/cache/test && php bin/phpunit tests/Http tests/Controller/Api/AiSettingsControllerTest.php tests/Controller/Api/MeControllerTest.php`
Expected: `OK`. No other test compares a whole configuration shape (`git grep -n "'suppressionRefused' =>" -- tests` printed nothing at planning time); if one now does, add the two keys there and name it in the report.

- [ ] **Step 5: Deletion checks** (paste each FAIL and the restored OK)

1. `configuration()`: `'family' => null,` → `testAConfigurationNamesTheKindAndFamilyOfItsModel` FAILs.
2. `configuration()`: `'kind' => $settings->getModelKind()?->value,` → `testAConfigurationWithoutAModelIsAnLlmWithoutAFamily` FAILs.
3. `models()`: `'kind' => 'llm',` → `testEachOfferedModelCarriesTheLabelKindFamilyAndCapabilitiesOfItsTag` FAILs.

- [ ] **Step 6: Gates** — as Task 1 Step 6; PhpStorm `lint_files` on `AiSettingsJson.php`.

- [ ] **Step 7: Commit**

```bash
git add src/Http/AiSettingsJson.php tests/Http/AiSettingsJsonTest.php tests/Controller/Api/AiSettingsControllerTest.php
git commit -m "feat(#1396): the model list and each configuration name the kind and family of their model"
```

---

### Task 6: `<app-segmented-choice>` can disable an option

**Files:**
- Modify: `frontend/src/app/shared/segmented-choice/segmented-choice.component.{ts,html,scss,spec.ts}`, `docs/design-language.md`

**Interfaces:**
- Consumes: nothing.
- Produces: `SegmentedChoiceComponent<T>` input `disabledOptions: readonly T[]` (default `[]`); a disabled option renders as a disabled `<button>`.

- [ ] **Step 1: Write the failing test**

In `frontend/src/app/shared/segmented-choice/segmented-choice.component.spec.ts` replace the host

```ts
@Component({
  imports: [SegmentedChoiceComponent],
  template: `<app-segmented-choice
    [options]="['en', 'de']"
    [selected]="selected()"
    ariaLabelKey="lang.label"
    labelPrefix="lang."
    (pick)="picked = $event"
  />`,
})
class HostComponent {
  readonly selected = signal<'en' | 'de'>('en');
  picked: string | null = null;
}
```
with

```ts
@Component({
  imports: [SegmentedChoiceComponent],
  template: `<app-segmented-choice
    [options]="['en', 'de']"
    [selected]="selected()"
    [disabledOptions]="disabled()"
    ariaLabelKey="lang.label"
    labelPrefix="lang."
    (pick)="picked = $event"
  />`,
})
class HostComponent {
  readonly selected = signal<'en' | 'de'>('en');
  readonly disabled = signal<readonly ('en' | 'de')[]>([]);
  picked: string | null = null;
}
```
and add at the end of the `describe` (red while the input does not exist — the template binding fails to compile — and red later if the button ignores it):

```ts
  it('disables only the options it is told to, and a disabled one emits nothing', () => {
    const fixture = create();
    expect(buttons(fixture).map((button) => button.disabled)).toEqual([false, false]);

    fixture.componentInstance.disabled.set(['de']);
    fixture.detectChanges();
    buttons(fixture)[1].click();

    expect(buttons(fixture).map((button) => button.disabled)).toEqual([false, true]);
    expect(fixture.componentInstance.picked).toBeNull();
  });
```

- [ ] **Step 2: Run it to see it fail**

From the repository root, alone: `docker compose exec -T frontend npx jest src/app/shared/segmented-choice`
Expected: FAIL — `Can't bind to 'disabledOptions' since it isn't a known property of 'app-segmented-choice'` (or the `disabled` assertion).

- [ ] **Step 3: Write the input**

`segmented-choice.component.ts`: replace

```ts
/**
 * A small segmented control over a fixed set of string options, labelled by
 * `<labelPrefix><option>` translation keys.
 */
```
with

```ts
/**
 * A small segmented control over a fixed set of string options, labelled by
 * `<labelPrefix><option>` translation keys. A disabled option stays visible;
 * the consumer says beside the control why it cannot be picked.
 */
```
and replace

```ts
  readonly labelPrefix = input.required<string>();
```
with

```ts
  readonly labelPrefix = input.required<string>();
  readonly disabledOptions = input<readonly T[]>([]);
```

`segmented-choice.component.html` (whole file):

```html
<div class="seg" role="group" [attr.aria-label]="ariaLabelKey() | transloco">
  @for (option of options(); track option) {
    <button
      type="button"
      [class.on]="selected() === option"
      [attr.aria-pressed]="selected() === option"
      [disabled]="disabledOptions().includes(option)"
      (click)="pick.emit(option)"
    >
      {{ labelPrefix() + option | transloco }}
    </button>
  }
</div>
```

`segmented-choice.component.scss`, append (the disabled look every control already has, `styles/_controls.scss` and `app-button`):

```scss

.seg button:disabled {
  cursor: default;
  opacity: 0.7;
}
```

- [ ] **Step 4: Run it to see it pass**

From the repository root, alone: `docker compose exec -T frontend npx jest src/app/shared/segmented-choice src/app/settings/preferences`
Expected: PASS (the preferences section keeps its two segmented controls).

- [ ] **Step 5: Document the component** (it has no catalog entry; D13)

In `docs/design-language.md`, after the `<app-info-tip>` section's last paragraph

```markdown
**Not for:** validation or state messages (that is `app-field`'s `error`/
`hint`), or anything that must be visible without interaction — a danger
zone keeps its always-visible note.
```
insert:

````markdown

### `<app-segmented-choice>`

A small segmented control over a fixed set of string options, each labelled
by the translation key `<labelPrefix><option>`. It emits `pick` with the
clicked option; the consumer owns the selection and passes it back as
`selected`.

| Input | Type | Default |
|---|---|---|
| `options` | `readonly T[]` (required) | — |
| `selected` | `T` (required) | — |
| `ariaLabelKey` | `string` (required) | — the group's accessible name, as a translation key |
| `labelPrefix` | `string` (required) | — |
| `disabledOptions` | `readonly T[]` | `[]` — options that cannot be picked now |

```html
<app-segmented-choice
  [options]="languages"
  [selected]="language.lang()"
  ariaLabelKey="lang.label"
  labelPrefix="lang."
  (pick)="language.set($event)"
/>
```

A disabled option stays visible at the shared disabled look; say why in a
hint beside the control (the AI model picker's "This provider offers no
LLMs.").

**Not inside `<app-field>`.** The field wraps its control in a `<label>`, and a
click on the label's text presses the first button of the group. Name the group
with `ariaLabelKey` and put a hint in a paragraph under it.
````

- [ ] **Step 6: Format and gate**

From `frontend/`: `npx prettier --write src/app/shared/segmented-choice`. From the repository root, alone: `docker compose exec -T frontend npm run check; echo "EXIT=$?"` → `EXIT=0`; the "Test Suites:" line one test above Task 0's.

- [ ] **Step 7: Deletion check** (paste the FAIL and the restored PASS)

`segmented-choice.component.html`: drop the `[disabled]` binding → the new spec FAILs.

- [ ] **Step 8: Commit**

```bash
git add frontend/src/app/shared/segmented-choice docs/design-language.md
git commit -m "feat(#1396): a segmented choice can disable an option"
```

---

### Task 7: The picker asks for the kind first

**Files:**
- Modify: `frontend/src/app/settings/ai/ai-settings.service.ts`, `frontend/src/app/settings/ai/ai-settings.service.spec.ts`, `frontend/src/app/settings/ai/ai-section.component.{ts,html,scss,spec.ts}`, `frontend/src/testing/recommendation-capabilities.ts`, `frontend/src/app/settings/recommendations/recommendation-settings-card.component.spec.ts`, `frontend/public/i18n/{en,de}.json`

**Interfaces:**
- Consumes: Task 5's wire (`kind`, `family` on configurations and model rows); Task 6's `disabledOptions`.
- Produces: `export type ModelKind = 'llm' | 'scoring'`, `export const MODEL_KINDS: readonly ModelKind[]`, `export type ScoringFamily = 'decision'` (all in `ai-settings.service.ts`); `AiConfig.kind: ModelKind`; `AiModel = { id, kind, family, capabilities }`; `AiSectionComponent.modelKind` (`WritableSignal<ModelKind>`), `.unofferedKinds` (`Signal<readonly ModelKind[]>`); i18n keys `settings.ai.modelKind.{label,llm,scoring,scoringHint,noneOffered.llm,noneOffered.scoring}`, `settings.ai.modelFamily.decision`; test fixture `SCORING_RECOMMENDATION_CAPABILITIES`.

- [ ] **Step 1: Rename the scoring capabilities fixture** (D18)

From `frontend/`:

```bash
perl -pi -e 's/JEV_RECOMMENDATION_CAPABILITIES/SCORING_RECOMMENDATION_CAPABILITIES/g; s{/\*\* What Jev reports:}{/** What a scoring model reports:}' src/testing/recommendation-capabilities.ts src/app/settings/ai/ai-section.component.spec.ts src/app/settings/recommendations/recommendation-settings-card.component.spec.ts
grep -rn 'JEV_RECOMMENDATION' src
```
Expected: the grep prints nothing.

- [ ] **Step 2: Write the types**

In `frontend/src/app/settings/ai/ai-settings.service.ts` replace

```ts
/**
 * One saved provider connection, as the multi-config endpoints report it.
```
with

```ts
/** An LLM writes a list with reasons; a scoring model rates each article. */
export type ModelKind = 'llm' | 'scoring';

export const MODEL_KINDS: readonly ModelKind[] = ['llm', 'scoring'];

export type ScoringFamily = 'decision';

/**
 * One saved provider connection, as the multi-config endpoints report it.
```
replace

```ts
  readonly model: string | null;
  readonly ready: boolean;
```
with

```ts
  readonly model: string | null;
  /** The kind of the saved model; `llm` while none is saved. */
  readonly kind: ModelKind;
  readonly ready: boolean;
```
and replace

```ts
export interface AiModel {
  readonly id: string;
  /** Shown beside the id as the server words it; null for an LLM. */
  readonly label: string | null;
  readonly capabilities: RecommendationCapabilities;
}
```
with

```ts
export interface AiModel {
  readonly id: string;
  readonly kind: ModelKind;
  /** Null for an LLM. */
  readonly family: ScoringFamily | null;
  readonly capabilities: RecommendationCapabilities;
}
```

In `frontend/src/app/settings/ai/ai-settings.service.spec.ts` replace

```ts
  model: null,
  ready: false,
```
with

```ts
  model: null,
  kind: 'llm',
  ready: false,
```
and

```ts
const offered = (id: string): AiModel => ({
  id,
  label: null,
  capabilities: EVERY_RECOMMENDATION_CAPABILITY,
});
```
with

```ts
const offered = (id: string): AiModel => ({
  id,
  kind: 'llm',
  family: null,
  capabilities: EVERY_RECOMMENDATION_CAPABILITY,
});
```

- [ ] **Step 3: Add the strings** (en, de — Task 8 rewrites the Jev copy; these are new keys only)

`frontend/public/i18n/en.json`: replace

```json
      "modelHint": {
        "noReasons": "scores articles, writes no reasons",
        "borrowedProfile": "takes its profile from Settings → AI"
      },
```
with

```json
      "modelHint": {
        "noReasons": "scores articles, writes no reasons",
        "borrowedProfile": "takes its profile from Settings → AI"
      },
      "modelKind": {
        "label": "Model type",
        "llm": "LLM",
        "scoring": "Scoring model",
        "scoringHint": "A scoring model rates every article for you instead of writing a list with reasons. A decision model's score is the probability that you want to read the article.",
        "noneOffered": {
          "llm": "This provider offers no LLMs.",
          "scoring": "This provider offers no scoring models."
        }
      },
      "modelFamily": {
        "decision": "Decision model"
      },
```

`frontend/public/i18n/de.json`: replace

```json
      "modelHint": {
        "noReasons": "bewertet Artikel, schreibt keine Begründungen",
        "borrowedProfile": "übernimmt das Profil aus Einstellungen → KI"
      },
```
with

```json
      "modelHint": {
        "noReasons": "bewertet Artikel, schreibt keine Begründungen",
        "borrowedProfile": "übernimmt das Profil aus Einstellungen → KI"
      },
      "modelKind": {
        "label": "Modelltyp",
        "llm": "LLM",
        "scoring": "Bewertungsmodell",
        "scoringHint": "Ein Bewertungsmodell bewertet jeden Artikel für dich, statt eine Liste mit Begründungen zu schreiben. Die Bewertung eines Entscheidungsmodells ist die Wahrscheinlichkeit, dass du den Artikel lesen willst.",
        "noneOffered": {
          "llm": "Dieser Anbieter bietet keine LLMs an.",
          "scoring": "Dieser Anbieter bietet keine Bewertungsmodelle an."
        }
      },
      "modelFamily": {
        "decision": "Entscheidungsmodell"
      },
```

- [ ] **Step 4: Write the failing component tests**

In `frontend/src/app/settings/ai/ai-section.component.spec.ts`:

Replace

```ts
  model: null,
  ready: false,
```
(the `config` fixture) with

```ts
  model: null,
  kind: 'llm',
  ready: false,
```
and

```ts
const offered = (
  id: string,
  capabilities = EVERY_RECOMMENDATION_CAPABILITY,
  label: string | null = null,
): AiModel => ({ id, label, capabilities });
```
with

```ts
const offered = (id: string, over: Partial<AiModel> = {}): AiModel => ({
  id,
  kind: 'llm',
  family: null,
  capabilities: EVERY_RECOMMENDATION_CAPABILITY,
  ...over,
});

const decisionModel = (id: string): AiModel =>
  offered(id, {
    kind: 'scoring',
    family: 'decision',
    capabilities: SCORING_RECOMMENDATION_CAPABILITIES,
  });
```

Inside `describe('AiSectionComponent', …)`, after `expandRow`, add:

```ts
  /** Opens the row's picker on these models; the row's own model decides the kind it opens on. */
  const openPicker = (
    fixture: ComponentFixture<AiSectionComponent>,
    rowConfig: AiConfig,
    models: readonly AiModel[],
  ): HTMLElement => {
    ai.configs.set([rowConfig]);
    ai.choosingModelFor.set(rowConfig.id);
    ai.models.set(models);
    fixture.detectChanges();
    expandRow(fixture, 0);
    return row(fixture, 0).querySelector('.model-picker') as HTMLElement;
  };

  const kindButtons = (picker: HTMLElement): HTMLButtonElement[] =>
    Array.from(picker.querySelectorAll('.model-kind button'));

  const optionLabels = (fixture: ComponentFixture<AiSectionComponent>): string[] =>
    fixture.componentInstance.modelOptions().map((option) => option.label);
```

Replace the two tests `it('marks a model that would borrow its profile and write no reasons, and leaves an LLM bare', …)` and `it("shows the server's label beside a model's id, and keeps it and the hint once chosen", …)` (lines 359–404 before this task) with:

```ts
  it('marks a scoring model that would borrow its profile and write no reasons, and leaves an LLM bare', () => {
    const fixture = mount();
    const picker = openPicker(fixture, config({ id: 1 }), [
      offered('gpt-4o'),
      decisionModel('jev-latest'),
    ]);
    const hints = (): (string | undefined)[] =>
      fixture.componentInstance.modelOptions().map((option) => option.hint);

    expect(hints()).toEqual([undefined]);

    kindButtons(picker)[1].click();
    fixture.detectChanges();

    expect(hints()).toEqual([
      'scores articles, writes no reasons · takes its profile from Settings → AI',
    ]);
  });

  it("shows the family beside a scoring model's id, and keeps it and the hint once chosen", () => {
    const fixture = mount();
    const picker = openPicker(fixture, config({ id: 1, model: 'jev-latest', kind: 'scoring' }), [
      offered('gpt-4o'),
      decisionModel('jev-latest'),
    ]);

    (picker.querySelector('app-searchable-select .trigger') as HTMLButtonElement).click();
    fixture.detectChanges();
    (picker.querySelectorAll('[role="option"]')[0] as HTMLElement).click();
    fixture.detectChanges();

    expect(picker.querySelector('app-searchable-select .current')?.textContent?.trim()).toBe(
      'jev-latest · Decision model',
    );
    expect(picker.querySelector('app-field .hint')?.textContent?.trim()).toBe(
      'scores articles, writes no reasons · takes its profile from Settings → AI',
    );

    kindButtons(picker)[0].click();
    fixture.detectChanges();
    fixture.componentInstance.chosenModel.set('gpt-4o');
    fixture.detectChanges();
    expect(picker.querySelector('app-searchable-select .current')?.textContent?.trim()).toBe(
      'gpt-4o',
    );
    expect(picker.querySelector('app-field .hint')).toBeNull();
  });

  it("opens on the kind of the row's model and lists only that kind", () => {
    const fixture = mount();
    const picker = openPicker(fixture, config({ id: 1, model: 'jev-latest', kind: 'scoring' }), [
      offered('gpt-4o'),
      decisionModel('~typesafe/jev-latest'),
    ]);

    expect(kindButtons(picker).map((button) => button.textContent?.trim())).toEqual([
      'LLM',
      'Scoring model',
    ]);
    expect(kindButtons(picker).map((button) => button.getAttribute('aria-pressed'))).toEqual([
      'false',
      'true',
    ]);
    expect(optionLabels(fixture)).toEqual(['~typesafe/jev-latest · Decision model']);
  });

  it('lists the other kind once it is picked, and drops the model picked under the first', () => {
    const fixture = mount();
    const picker = openPicker(fixture, config({ id: 1 }), [
      offered('gpt-4o'),
      decisionModel('~typesafe/jev-latest'),
    ]);
    expect(optionLabels(fixture)).toEqual(['gpt-4o']);
    fixture.componentInstance.chosenModel.set('gpt-4o');

    kindButtons(picker)[1].click();
    fixture.detectChanges();

    expect(optionLabels(fixture)).toEqual(['~typesafe/jev-latest · Decision model']);
    expect(fixture.componentInstance.chosenModel()).toBeNull();
  });

  it('disables a kind the provider offers none of, says so, and opens on the kind it offers', () => {
    const fixture = mount();
    const picker = openPicker(fixture, config({ id: 1 }), [decisionModel('~typesafe/jev-latest')]);

    expect(kindButtons(picker).map((button) => button.disabled)).toEqual([true, false]);
    expect(kindButtons(picker).map((button) => button.getAttribute('aria-pressed'))).toEqual([
      'false',
      'true',
    ]);
    expect(picker.querySelector('.model-kind')?.textContent).toContain(
      'This provider offers no LLMs.',
    );
  });

  it("explains a scoring model's score only while scoring models are listed", () => {
    const fixture = mount();
    const picker = openPicker(fixture, config({ id: 1 }), [
      offered('gpt-4o'),
      decisionModel('~typesafe/jev-latest'),
    ]);
    expect(picker.querySelector('.scoring-hint')).toBeNull();

    kindButtons(picker)[1].click();
    fixture.detectChanges();

    expect(picker.querySelector('.scoring-hint')?.textContent).toContain('probability');
  });

  it("keeps the reader's kind while a row is written", () => {
    const fixture = mount();
    const picker = openPicker(fixture, config({ id: 1 }), [
      offered('gpt-4o'),
      decisionModel('~typesafe/jev-latest'),
    ]);
    kindButtons(picker)[1].click();
    fixture.detectChanges();

    ai.configs.set([config({ id: 1 }), config({ id: 2, name: 'Sibling' })]);
    fixture.detectChanges();

    expect(fixture.componentInstance.modelKind()).toBe('scoring');
  });
```

In `it('offers no profile picker on a Jev connection', …)` rename it to `'offers no profile picker on a scoring connection'` and add `kind: 'scoring',` after `model: 'jev-latest',`.

Each new test is red until Step 6: the picker has no `.model-kind`, no `modelKind` and no filter.

- [ ] **Step 5: Run them to see them fail**

From the repository root, alone: `docker compose exec -T frontend npx jest src/app/settings/ai`
Expected: FAIL in the seven tests above (`Cannot read properties of undefined` on `kindButtons(…)[1]`, `modelKind is not a function`, unfiltered labels).

- [ ] **Step 6: Write the picker**

`frontend/src/app/settings/ai/ai-section.component.ts`:

Replace

```ts
import {
  ChangeDetectionStrategy,
  Component,
  Signal,
  computed,
  inject,
  linkedSignal,
  signal,
} from '@angular/core';
```
with

```ts
import {
  ChangeDetectionStrategy,
  Component,
  Signal,
  computed,
  inject,
  linkedSignal,
  signal,
  untracked,
} from '@angular/core';
```
add `import { SegmentedChoiceComponent } from '../../shared/segmented-choice/segmented-choice.component';` after the `SearchableSelectComponent` import block, replace

```ts
import { AiConfig, AiSettingsService } from './ai-settings.service';
```
with

```ts
import { AiConfig, AiModel, AiSettingsService, MODEL_KINDS, ModelKind } from './ai-settings.service';
```
add `SegmentedChoiceComponent,` to the `imports` array after `SearchableSelectComponent,`, and add above `@Component`:

```ts
function offeredKinds(models: readonly AiModel[]): readonly ModelKind[] {
  return MODEL_KINDS.filter((kind) => models.some((model) => model.kind === kind));
}

```

Replace

```ts
  /** Whichever row is fetching or showing a model list; unset once a
   *  different row starts, so a stale pick from one row can never be sent
   *  for another. */
  readonly chosenModel = linkedSignal<number | null, string | null>({
    source: () => this.ai.choosingModelFor(),
    computation: () => null,
  });
```
with

```ts
  readonly modelKinds = MODEL_KINDS;

  /** The kinds the open model list holds none of: their option is disabled and says so. */
  readonly unofferedKinds = computed(() => {
    const offered = offeredKinds(this.ai.models());
    return MODEL_KINDS.filter((kind) => !offered.includes(kind));
  });

  /** Opens on the kind of the row's saved model, or on the only kind offered. The rows
   *  are read untracked, so a write to any row keeps the reader's pick. */
  readonly modelKind = linkedSignal<readonly AiModel[], ModelKind>({
    source: () => this.ai.models(),
    computation: (models) => untracked(() => this.openingKind(models)),
  });

  /** The pick in whichever row and kind is showing a model list; unset once
   *  another row starts or the kind changes, so a stale pick can never be
   *  sent for another row or from the other kind's list. */
  readonly chosenModel = linkedSignal<{ row: number | null; kind: ModelKind }, string | null>({
    source: () => ({ row: this.ai.choosingModelFor(), kind: this.modelKind() }),
    computation: () => null,
  });
```

Replace

```ts
  readonly modelOptions = computed<SelectOption[]>(() =>
    this.ai.models().map((model) => ({
      value: model.id,
      label: [model.id, model.label].filter(Boolean).join(' · '),
      hint: this.modelHint(model.capabilities),
    })),
  );
```
with

```ts
  readonly modelOptions = computed<SelectOption[]>(() =>
    this.ai
      .models()
      .filter((model) => model.kind === this.modelKind())
      .map((model) => ({
        value: model.id,
        label: [model.id, this.familyTag(model)].filter(Boolean).join(' · '),
        hint: this.modelHint(model.capabilities),
      })),
  );
```

Add after `modelHint()`:

```ts
  private familyTag(model: AiModel): string | null {
    return model.family ? this.i18n.translate(`settings.ai.modelFamily.${model.family}`) : null;
  }

  private openingKind(models: readonly AiModel[]): ModelKind {
    const row = this.ai.configs().find((config) => config.id === this.ai.choosingModelFor());
    const current = row?.kind ?? 'llm';
    const offered = offeredKinds(models);

    return offered.includes(current) ? current : (offered[0] ?? current);
  }
```

`frontend/src/app/settings/ai/ai-section.component.html`: replace

```html
                    @if (ai.choosingModelFor() === config.id && ai.models().length) {
                      <div class="model-picker">
                        <app-field
```
with

```html
                    @if (ai.choosingModelFor() === config.id && ai.models().length) {
                      <div class="model-picker">
                        <div class="model-kind">
                          <app-segmented-choice
                            [options]="modelKinds"
                            [selected]="modelKind()"
                            [disabledOptions]="unofferedKinds()"
                            ariaLabelKey="settings.ai.modelKind.label"
                            labelPrefix="settings.ai.modelKind."
                            (pick)="modelKind.set($event)"
                          />
                          @for (kind of unofferedKinds(); track kind) {
                            <p class="hint">
                              {{ 'settings.ai.modelKind.noneOffered.' + kind | transloco }}
                            </p>
                          }
                          @if (modelKind() === 'scoring') {
                            <p class="hint scoring-hint">
                              {{ 'settings.ai.modelKind.scoringHint' | transloco }}
                            </p>
                          }
                        </div>
                        <app-field
```

`frontend/src/app/settings/ai/ai-section.component.scss`: after the `.model-picker` rule add

```scss

.model-kind {
  display: flex;
  flex-direction: column;
  gap: var(--space-1);
}

.model-kind .hint {
  margin: 0;
}
```

- [ ] **Step 7: Run them to see them pass**

From `frontend/`: `npx prettier --write src/app/settings/ai src/testing public/i18n`. From the repository root, alone: `docker compose exec -T frontend npx jest src/app/settings`
Expected: PASS.

- [ ] **Step 8: Deletion checks** (paste each FAIL and the restored PASS)

1. `modelOptions`: drop `.filter((model) => model.kind === this.modelKind())` → "opens on the kind of the row's model and lists only that kind" FAILs.
2. `modelKind`: `computation: (models) => this.openingKind(models)` (no `untracked`) → "keeps the reader's kind while a row is written" FAILs.
3. `chosenModel`: `source: () => this.ai.choosingModelFor()` → "lists the other kind once it is picked, and drops the model picked under the first" FAILs.
4. Template: drop `[disabledOptions]="unofferedKinds()"` → "disables a kind the provider offers none of …" FAILs.
5. `openingKind()`: return `current` → "disables a kind …" FAILs on `aria-pressed`.

- [ ] **Step 9: Gate** — from the repository root, alone: `docker compose exec -T frontend npm run check; echo "EXIT=$?"` → `EXIT=0`.

- [ ] **Step 10: Commit**

```bash
git add frontend/src frontend/public/i18n
git commit -m "feat(#1396): the model picker asks for llm or scoring model first and tags each scoring model"
```

---

### Task 8: General scoring-model copy

**Files:**
- Modify: `frontend/public/i18n/{en,de}.json`, `frontend/src/app/settings/ai/ai-section.component.{html,spec.ts}`

**Interfaces:**
- Consumes: Task 7's picker (the guide names its option and tag).
- Produces: keys `settings.ai.guide.scoringTitle`, `settings.ai.guide.scoringStep1`…`scoringStep5` (replacing `jevTitle`, `jevStep1`…`jevStep5`); the guide list class `guide-scoring` (was `guide-jev`).

- [ ] **Step 1: Write the failing tests**

In `frontend/src/app/settings/ai/ai-section.component.spec.ts` replace the two tests `it('walks through setting up Jev in the guide', …)` and `it('says in the add form that Jev models are supported alongside LLMs', …)` with:

```ts
  it('walks through setting up a scoring model in the guide', () => {
    const fixture = mountWithConfigs([]);

    const guide = fixture.nativeElement.querySelector('.guide') as HTMLElement;
    const titles = Array.from(guide.querySelectorAll('h3')).map((title) =>
      title.textContent?.trim(),
    );
    expect(titles).toContain('Use a scoring model');

    const scoringSteps = Array.from(guide.querySelectorAll('.guide-scoring li')).map((step) =>
      step.textContent?.trim(),
    );
    expect(scoringSteps).toHaveLength(5);
    expect(scoringSteps[1]).toContain('https://openrouter.ai/api/v1');
    expect(scoringSteps[2]).toContain('“Scoring model”');
    expect(scoringSteps[2]).toContain('“Decision model”');
    expect(scoringSteps[3]).toContain('Open Settings → AI');
  });

  it('says in the add form that scoring models are supported alongside LLMs', () => {
    const fixture = mountWithConfigs([]);

    const intro = fixture.nativeElement.querySelector('.add-group .add-intro') as HTMLElement;
    expect(intro.textContent).toContain('OpenAI-compatible LLM endpoint and with scoring models');
    expect(intro.textContent).not.toContain('jev-latest');
  });

  it('names no single scoring model in its German copy', () => {
    const fixture = mountWithConfigs([]);
    TestBed.inject(TranslocoService).setActiveLang('de');
    fixture.detectChanges();

    const guide = fixture.nativeElement.querySelector('.guide') as HTMLElement;
    expect(guide.textContent).toContain('Ein Bewertungsmodell verwenden');
    expect(guide.textContent).not.toContain('Jev verwenden');
  });
```
and add `import { TranslocoService } from '@jsverse/transloco';` to the spec's imports.

- [ ] **Step 2: Run them to see them fail**

From the repository root, alone: `docker compose exec -T frontend npx jest src/app/settings/ai/ai-section.component.spec.ts`
Expected: FAIL in the three tests (Jev titles and the old intro).

- [ ] **Step 3: Write the copy**

`frontend/src/app/settings/ai/ai-section.component.html`: replace

```html
          <h3>{{ 'settings.ai.guide.jevTitle' | transloco }}</h3>
          <ol class="guide-jev">
            <li>{{ 'settings.ai.guide.jevStep1' | transloco }}</li>
            <li>{{ 'settings.ai.guide.jevStep2' | transloco }}</li>
            <li>{{ 'settings.ai.guide.jevStep3' | transloco }}</li>
            <li>{{ 'settings.ai.guide.jevStep4' | transloco }}</li>
            <li>{{ 'settings.ai.guide.jevStep5' | transloco }}</li>
          </ol>
```
with

```html
          <h3>{{ 'settings.ai.guide.scoringTitle' | transloco }}</h3>
          <ol class="guide-scoring">
            <li>{{ 'settings.ai.guide.scoringStep1' | transloco }}</li>
            <li>{{ 'settings.ai.guide.scoringStep2' | transloco }}</li>
            <li>{{ 'settings.ai.guide.scoringStep3' | transloco }}</li>
            <li>{{ 'settings.ai.guide.scoringStep4' | transloco }}</li>
            <li>{{ 'settings.ai.guide.scoringStep5' | transloco }}</li>
          </ol>
```

`frontend/public/i18n/en.json` — replace these values (keys unchanged unless named):

- `settings.ai.addIntro` → `"Works with any OpenAI-compatible LLM endpoint and with scoring models, which rate each article instead of writing a list: add OpenRouter (https://openrouter.ai/api/v1) for both, or TypeSafe's own endpoint for its Jev."`
- `settings.ai.info.modelPicker` → `"The list is fetched live from this configuration's endpoint. Choose LLM or scoring model first, then the model AI features should use — the configuration is ready once a model is saved. A scoring model rates each article without writing reasons and takes your reading profile from Settings → AI."`
- `settings.ai.guide.intro` → `"Three short walkthroughs: connect an AI endpoint, tune the “For you” recommendations, and optionally let a scoring model rate them."`
- Replace the six lines `"jevTitle"` … `"jevStep5"` with:

```json
        "scoringTitle": "Use a scoring model",
        "scoringStep1": "A scoring model rates every article for you instead of writing a list with reasons. It needs an LLM connection for your reading profile, so set one up first as above.",
        "scoringStep2": "Open “Add a configuration” and enter https://openrouter.ai/api/v1 with your OpenRouter key, or TypeSafe's own endpoint with a TypeSafe key.",
        "scoringStep3": "Press “Add configuration”, choose “Scoring model” above the model list that opens, pick a model tagged “Decision model” — for example ~typesafe/jev-latest — and save it.",
        "scoringStep4": "Open Settings → AI and choose your LLM connection under Profile.",
        "scoringStep5": "Press “Use this one”. The picks then carry a score but no reasons."
```

`frontend/public/i18n/de.json` — the same keys:

- `settings.ai.addIntro` → `"Funktioniert mit jedem OpenAI-kompatiblen LLM-Endpunkt und mit Bewertungsmodellen, die jeden Artikel bewerten, statt eine Liste zu schreiben: Füge OpenRouter (https://openrouter.ai/api/v1) für beides hinzu oder den eigenen Endpunkt von TypeSafe für dessen Jev."`
- `settings.ai.info.modelPicker` → `"Die Liste wird live vom Endpunkt dieser Konfiguration geladen. Wähle zuerst LLM oder Bewertungsmodell, dann das Modell, das die KI-Funktionen nutzen sollen — die Konfiguration ist einsatzbereit, sobald ein Modell gespeichert ist. Ein Bewertungsmodell bewertet jeden Artikel, ohne Begründungen zu schreiben, und übernimmt dein Leseprofil aus Einstellungen → KI."`
- `settings.ai.guide.intro` → `"Drei kurze Anleitungen: einen KI-Endpunkt verbinden, die „Für dich“-Empfehlungen einstellen und sie optional von einem Bewertungsmodell bewerten lassen."`
- Replace `"jevTitle"` … `"jevStep5"` with:

```json
        "scoringTitle": "Ein Bewertungsmodell verwenden",
        "scoringStep1": "Ein Bewertungsmodell bewertet jeden Artikel für dich, statt eine Liste mit Begründungen zu schreiben. Für dein Leseprofil braucht es eine LLM-Verbindung — richte also zuerst wie oben eine ein.",
        "scoringStep2": "Öffne „Konfiguration hinzufügen“ und trage https://openrouter.ai/api/v1 mit deinem OpenRouter-Schlüssel ein oder den eigenen Endpunkt von TypeSafe mit einem TypeSafe-Schlüssel.",
        "scoringStep3": "Drücke „Konfiguration hinzufügen“, wähle über der Modellliste, die sich öffnet, „Bewertungsmodell“, wähle ein Modell mit dem Hinweis „Entscheidungsmodell“ — zum Beispiel ~typesafe/jev-latest — und speichere es.",
        "scoringStep4": "Öffne Einstellungen → KI und wähle unter Profil deine LLM-Verbindung.",
        "scoringStep5": "Drücke „Diesen verwenden“. Die Empfehlungen tragen dann eine Bewertung, aber keine Begründung."
```

Then: `grep -n -i 'jev' frontend/public/i18n/en.json frontend/public/i18n/de.json frontend/src/app/settings/ai/ai-section.component.html` prints only the `scoringStep3` examples and the two `addIntro` "Jev" mentions.

- [ ] **Step 4: Run them to see them pass**

From `frontend/`: `npx prettier --write src/app/settings/ai public/i18n`. From the repository root, alone: `docker compose exec -T frontend npx jest src/app/settings/ai`
Expected: PASS (the guide still has 15 steps).

- [ ] **Step 5: Deletion check** (paste the FAIL and the restored PASS)

`de.json`: restore `"scoringTitle": "Jev verwenden (TypeSafe System One)"` → "names no single scoring model in its German copy" FAILs.

- [ ] **Step 6: Gate** — `docker compose exec -T frontend npm run check; echo "EXIT=$?"` → `EXIT=0`.

- [ ] **Step 7: Commit**

```bash
git add frontend/src/app/settings/ai frontend/public/i18n
git commit -m "feat(#1396): the ai settings explain scoring models in general, not jev alone"
```

---

### Task 9: Playwright smoke of the picker

**Files:**
- Create: `frontend/e2e/ai-model-kind-picker.spec.ts`
- Modify: `frontend/e2e/ai-config-rejected.spec.ts` (its stub gains the new keys)

**Interfaces:**
- Consumes: Tasks 5, 7 and 8 (wire shape, picker, strings).
- Produces: one smoke that owns all its data (spec Testing: "one Playwright smoke with a stubbed model list").

- [ ] **Step 1: Write the smoke**

`frontend/e2e/ai-model-kind-picker.spec.ts`:

```ts
import { test, expect, Page } from '@playwright/test';
import { signInAsAdmin } from './support/auth';

/**
 * The kind-first model picker over a stubbed configuration and model list, so the
 * spec owns every value it asserts on: nothing is saved, and no outbound call
 * reaches a real provider. Matched on the pathname, so `/api/me/ai` and
 * `/api/me/ai/configs/1/models` do not catch each other.
 */
const LLM_CAPABILITIES = {
  reasons: true,
  prompt: true,
  profile: 'own',
  tuningFields: [
    'contextWindow',
    'batchSize',
    'suppressReasoning',
    'slowModel',
    'maxBatchSize',
    'batchConcurrency',
  ],
};

const SCORING_CAPABILITIES = {
  reasons: false,
  prompt: false,
  profile: 'borrowed',
  tuningFields: ['batchConcurrency'],
};

const CONFIG = {
  id: 1,
  name: 'Stubbed provider',
  baseUrl: 'https://stubbed.example.test/v1',
  apiKeyHint: '9876',
  model: 'acme/chat-1',
  kind: 'llm',
  family: null,
  ready: true,
  active: true,
  suppressReasoning: true,
  suppressionRefused: false,
  batchConcurrency: 1,
  slowModel: false,
  maxBatchSize: null,
  capabilities: LLM_CAPABILITIES,
};

const MODELS = [
  { id: 'acme/chat-1', label: null, kind: 'llm', family: null, capabilities: LLM_CAPABILITIES },
  {
    id: 'acme/decider-1',
    label: 'System One',
    kind: 'scoring',
    family: 'decision',
    capabilities: SCORING_CAPABILITIES,
  },
];

async function stubAi(page: Page): Promise<void> {
  await page.route(
    (url) => url.pathname === '/api/me/ai',
    (route) =>
      route.fulfill({
        status: 200,
        json: { configs: [CONFIG], activeId: CONFIG.id, defaultMaxBatchSize: 50 },
      }),
  );
  await page.route(
    (url) => url.pathname === `/api/me/ai/configs/${CONFIG.id}/models`,
    (route) => route.fulfill({ status: 200, json: { models: MODELS } }),
  );
}

test('the model picker asks for the kind first and lists only that kind', async ({ page }) => {
  await stubAi(page);
  const signedIn = await signInAsAdmin(page);
  test.skip(!signedIn, 'seeded admin login unavailable (run app:e2e:seed-admin against the stack)');

  await page.goto('/settings/ai');
  await page.getByRole('button', { name: 'Manage' }).click();
  const row = page.locator('.config-row').first();
  await row.locator('summary').click();
  await row.locator('.change-model button').click();

  const kinds = row.getByRole('group', { name: 'Model type' });
  await expect(kinds.getByRole('button', { name: 'LLM', exact: true })).toHaveAttribute(
    'aria-pressed',
    'true',
  );
  const select = row.locator('app-searchable-select');
  await select.locator('.trigger').click();
  await expect(select.getByRole('option')).toHaveText(['acme/chat-1']);
  await select.locator('.trigger').click();

  await kinds.getByRole('button', { name: 'Scoring model', exact: true }).click();

  await expect(row.locator('.scoring-hint')).toContainText('probability');
  await select.locator('.trigger').click();
  await expect(select.getByRole('option')).toHaveCount(1);
  await expect(select.getByRole('option')).toContainText(['acme/decider-1 · Decision model']);
});
```

In `frontend/e2e/ai-config-rejected.spec.ts` replace

```ts
  model: 'some-model',
  ready: true,
```
with

```ts
  model: 'some-model',
  kind: 'llm',
  family: null,
  ready: true,
```

- [ ] **Step 2: Run it** (the Docker stack and the dev server on :4200 up; nothing else in the frontend container running)

From `frontend/`: `npx tsc -p tsconfig.e2e.json --noEmit && npx playwright test e2e/ai-model-kind-picker.spec.ts e2e/ai-config-rejected.spec.ts`
Expected: `2 passed`. A skip ("seeded admin login unavailable") is not a pass: run `docker compose exec php bin/console app:e2e:seed-admin` and repeat. If :4200 serves an old chunk (the picker missing), restart the dev server (`docker compose restart frontend`) and repeat.

- [ ] **Step 3: Deletion check** (paste the FAIL and the restored pass)

`ai-section.component.ts` `modelOptions`: drop the kind filter → the smoke FAILs on `toHaveText(['acme/chat-1'])`. Restore, `docker compose restart frontend` if the dev server holds the broken chunk, and re-run.

- [ ] **Step 4: Commit**

```bash
git add frontend/e2e
git commit -m "test(#1396): a playwright smoke of the kind-first model picker"
```

---

### Task 10: Docs, gates, real runs, the PR

**Files:**
- Modify: `docs/recommendations-runs.md`

**Interfaces:**
- Consumes: everything above.
- Produces: the PR.

From the repository root for every step of this task except Step 2.

- [ ] **Step 1: Document**

`docs/recommendations-runs.md`, in order:

- Replace "label beside its id (`AiSettingsJson`: `"System One"` for a model the catalog tagged with that protocol, `null` for an LLM), so the picker" … "marks a scoring model without the client parsing its id; no client logic branches on the label:" with:

  "label beside its id (`"System One"` for a model the catalog tagged with that protocol, `null` for an LLM), its `kind` (`llm` or `scoring`) and its `family` (`decision` for System One, `null` for an LLM); a configuration carries the `kind` and `family` of its saved model. The picker asks for the kind first, lists only that kind's models and tags each scoring model by its family, so no client parses an id or branches on the label:"

- In the JSON example replace `{"models": [{"id": "jev-latest", "label": "System One",` with `{"models": [{"id": "~typesafe/jev-latest", "label": "System One", "kind": "scoring", "family": "decision",`.
- Replace "is chosen (`user_ai_settings.scoring_protocol`); `SystemOneCatalog` offers `jev-latest` as a System One model wherever `{base}/systemone` answers." with:

  "is chosen (`user_ai_settings.scoring_protocol`). The model catalog says what each model is: `OpenAiCompatibleCatalog` reads `GET {base}/models?output_modalities=all`; an entry whose `architecture.output_modalities` holds `text`, or that reports none (LM Studio, Ollama, OpenAI), is an LLM; `["decisions"]` is a System One model, listed only with a positive `context_length` (its requests are budgeted from it, which leaves out Respan's `span-01*`); every other output (rerank, image, embeddings, …) is left out. `SystemOneCatalog` probes `{base}/systemone` and offers `jev-latest` (32k) only when no listing named a System One model (TypeSafe direct, whose `/models` is not OpenAI-shaped); OpenRouter lists Jev itself as `~typesafe/jev-latest`."

- Replace the System One table row's last cell "at most 100 questions; a 32k-token window less 2k of framing and 10k for the state (`ScoringBudgetFactory`)" with "at most 64 questions (Cloudflare's Clef refuses more); of the model's stored window, 2k for framing, `min(10k, 30 %)` for the state and the rest for questions (`SystemOneProtocol::budget()`, `ScoringBudgetModel::forWindow()`)".

Then `git grep -n 'ScoringBudgetFactory\|100 questions' -- docs/recommendations-runs.md docs/architecture.md` prints nothing.

- [ ] **Step 2: Backend gates** (from `backend/`, one at a time)

```bash
bin/console cache:warmup
composer check
composer md
php bin/phpunit
composer infection:diff
```
and from the repository root: `docker compose exec php sh -c 'rm -rf var/cache-docker/test*'`, then `docker compose exec php composer test`.
Expected: green throughout; phptramp's warnings at Task 0's count; Infection at or above `minMsi` (80). Escaped mutants: kill them with a test that names the production change it pins, never by lowering `minMsi`.

PhpStorm `lint_files` on every `src` file the branch changed (`git diff --name-only origin/develop -- backend/src`): no ERROR, no WARNING.

- [ ] **Step 3: Frontend gate**

From the repository root, alone: `docker compose exec -T frontend npm run check; echo "EXIT=$?"` → `EXIT=0`. Then, with no Jest running, from `frontend/`: `npx playwright test e2e/ai-model-kind-picker.spec.ts e2e/ai-config-rejected.spec.ts` → `2 passed`.

- [ ] **Step 4: The stack serves the branch**

```bash
docker compose exec php bin/console cache:clear
docker compose restart worker
docker compose ps
```
Expected: the worker healthy (no migration in this PR).

- [ ] **Step 5: The catalog through the app** (read-only; never print, copy or log an API key or a token)

1. Record the starting state:

```bash
docker compose exec php bin/console dbal:run-sql "SELECT id, user_id, base_url, model, model_context_window, model_kind, scoring_protocol FROM user_ai_settings WHERE user_id = 2"
docker compose exec php bin/console dbal:run-sql "SELECT id, email, active_ai_config_id FROM app_user WHERE id = 2"
```
At planning time (2026-10-05): connection 8 on OpenRouter with `jev-latest`, 32000, `scoring`/`system_one`, active for user 2; connections 3 and 5 are LM Studio servers on the LAN. Use the email in place of `<email>` below.

2. OpenRouter's list (one command each: a shell variable does not outlive the call):

```bash
TOKEN=$(docker compose exec -T php bin/console lexik:jwt:generate-token <email> | tail -1) && curl -sk https://localhost:8443/api/me/ai/configs/8/models -H "Authorization: Bearer $TOKEN" | jq -c '[.models[] | {kind, family}] | group_by(.kind, .family) | map({kind: .[0].kind, family: .[0].family, count: length})'
TOKEN=$(docker compose exec -T php bin/console lexik:jwt:generate-token <email> | tail -1) && curl -sk https://localhost:8443/api/me/ai/configs/8/models -H "Authorization: Bearer $TOKEN" | jq -c '[.models[] | select(.id == "~typesafe/jev-latest" or .id == "jev-latest" or .id == "jaredpalmer/kev-4b" or .id == "cloudflare/clef-flash" or (.id | startswith("respan/")) or .id == "cohere/rerank-4-fast" or .id == "google/gemini-3.1-flash-lite-image") | {id, kind, family}]'
```
Expected: an `llm` group and a `scoring`/`decision` group (counts recorded); the second list holds `~typesafe/jev-latest`, `jaredpalmer/kev-4b`, `cloudflare/clef-flash` as `scoring`/`decision` and `google/gemini-3.1-flash-lite-image` as `llm`/`null`, and none of `jev-latest`, `respan/*`, `cohere/rerank-4-fast` (the catalog is OpenRouter's live one; a model it dropped since 2026-10-05 is a note, not a failure).

3. The query parameter against LM Studio (spec D3's assumption, D2): the same `curl` against `/api/me/ai/configs/5/models` and `/api/me/ai/configs/3/models`, piped to `jq -c '{count: (.models | length), kinds: ([.models[].kind] | unique), status: .status}'`. Expected: models listed, all `llm`. A `problem+json` with status 502/422 naming "answered with status" is a refusal: stop and report it to the planner (D2). If both servers are off (`That address did not answer.`), record that the assumption stays unverified.

- [ ] **Step 6: Real runs with two decision models** (D17)

**Ask Lars first, and wait for a clear yes.** The question, verbatim: "Task 10 wants two real runs on dev connection 8 (Kev for the window, Clef for the 64-question cap; a fraction of a cent through OpenRouter). Since #1396, `jev-latest` is no longer offered on OpenRouter, so connection 8 cannot be put back on it: the runs would end with connection 8 on `~typesafe/jev-latest` (same model, OpenRouter's id; spec D4's path). Go ahead?" Without a yes, skip to Step 7 and say in the report and the PR that the real runs were not made, and why.

With a yes, every write through the app's own API (`TOKEN=…` as in Step 5, one command per call):

1. `PUT /api/me/ai/configs/8/model` with `{"model":"jaredpalmer/kev-4b"}`; expect the configuration back with `"kind":"scoring","family":"decision"`.
2. `POST /api/recommendations/runs`; then read `GET /api/recommendations/runs/current` by hand, one command at a time, until its `status` leaves `pending`/`running` (the worker drives the run; no loop, no `sleep`).
3. Verify (read-only, each through `docker compose exec php bin/console dbal:run-sql "…"`):

```sql
SELECT id, status, error, engine_kind, scoring_protocol, model, cost_nano_credits FROM recommendation_run ORDER BY id DESC LIMIT 1;
SELECT id, phase, verdict, request_id, answering_model, cost_nano_credits, (LENGTH(request_body) - LENGTH(REPLACE(request_body, '"noul"', ''))) / 6 AS questions FROM recommendation_run_log WHERE run_id = <id> ORDER BY id;
SELECT COUNT(*) AS items, MIN(score), MAX(score) FROM recommendation_item WHERE recommendation_run_id = <id>;
```
Expected: `completed`, `scoring`, `system_one`, `jaredpalmer/kev-4b`; every log row a usable `batch` with a request id and a cost, each carrying well under 64 questions (the 3,735-token budget binds; record the counts); scores within 0–1000.

4. `PUT …/configs/8/model` with `{"model":"cloudflare/clef-flash"}`, then run and verify as in 2–3. Expected: `completed` (a 429 defers the run; keep reading `current` by hand), every row but the last carrying exactly 64 questions, and no row with a 422.
5. Restore as far as D5 allows: `PUT …/configs/8/model` with `{"model":"~typesafe/jev-latest"}`; re-run Step 5.1's queries: connection 8 holds `~typesafe/jev-latest`, 32000, `scoring`, `system_one`, and is still active for user 2; everything else matches what was recorded.
6. Scan the dev log: `ls -t backend/var/log/dev-*.log | head -1 | xargs tail -n 800 | jq -c 'select(.level >= 300)'` → nothing new from these runs (a deprecation is a finding).

Record for the PR: both run ids, items, score ranges, questions per request, the answering models, the costs.

- [ ] **Step 7: Commit, push, PR**

```bash
git add docs/recommendations-runs.md
git commit -m "docs(#1396): the kind-tagged catalog, the per-protocol caps and the window-scaled budget"
git status --short
git push -u origin feature/1396-decision-models-picker
cat > "$TMPDIR/pr-1396-body.md" <<'EOF'
Closes #1396
Refs #1394

PR 2 of the scoring-model providers design (docs/superpowers/specs/2026-10-05-scoring-model-providers-design.md).

- The model catalog reads `GET {base}/models?output_modalities=all` and tags each model: text among the outputs (or none reported) is an LLM, `["decisions"]` with a positive window a System One decision model, anything else is left out (Respan's zero-window models, rerankers until PR 3, image, embeddings, …).
- The System One probe (`jev-latest`) answers only when no listing named a System One model, so OpenRouter lists Jev once, as `~typesafe/jev-latest`; the probe's tag wins an id a listing named untagged.
- A scoring request is sized by its protocol and its model: at most 64 questions for System One (Clef refuses more; was 100), and a budget scaled to the connection's stored window — 2k framing, min(10k, 30 %) for the state, the rest for questions. `ScoringBudgetFactory` is gone; `ScoringProtocolInterface::budget()` replaces it.
- The model list and each configuration carry `kind` (`llm`/`scoring`) and `family` (`decision`/null); `label` stays. Additive JSON on existing bearer-authenticated, stateless endpoints (architecture §6: every box checked).
- Settings → AI asks "LLM / Scoring model" first (the shared segmented choice, which can now disable an option), lists only that kind, tags each scoring model "Decision model", explains a decision model's score, and says when a provider offers none of a kind. The Jev copy is general scoring-model copy (en, de).

Known consequence (plan D5): an existing OpenRouter connection on the bare `jev-latest` keeps running while active, but can no longer be re-chosen or re-activated — the catalog now offers it as `~typesafe/jev-latest`, and activation re-verifies the model. Re-choosing `~typesafe/jev-latest` fixes it.

Real runs on the dev stack: <Kev run id, items, score range, questions per request, cost; Clef run id, …> — or: not made, <reason>.
LM Studio and the query parameter: <result of Task 10 Step 5.3>.

Gates: composer check (phptramp warnings: <n>), composer md, both PHPUnit legs, infection:diff (MSI <n>), npm run check, the Playwright smoke.

Plan: docs/superpowers/plans/2026-10-05-1396-decision-models-picker.md
EOF
grep -inE '(close[sd]?|fix(e[sd])?|resolve[sd]?) #1394' "$TMPDIR/pr-1396-body.md"
gh pr create --base develop --title "feat(#1396): decision models from the catalog and a kind-first model picker" --body-file "$TMPDIR/pr-1396-body.md"
```
Expected: the `grep` prints nothing (a closing keyword beside #1394 would close the umbrella); `gh` prints the PR URL. Do not merge: Lars merges. After the merge, verify #1396 closed on its own and #1394 stayed open.

---

## Spec coverage (planner's self-review)

| Spec / issue item | Where |
|---|---|
| D2 `family()` (SystemOne → decision model) | Task 1 (D10) |
| D3 `?output_modalities=all`; no architecture → LLM; `["text"]` → LLM; `["decisions"]` → Scoring/SystemOne; anything else left out | Task 2 (text among outputs, D1) |
| D3 a Scoring entry without a positive window is left out (Respan) | Task 2 (catalog), Task 1 (descriptor guard, D6) |
| D3 assumption: servers ignore the parameter; retry if one refuses | Task 10 Step 5.3 (D2) |
| D3 probe is a fallback, consulted only without a listed SystemOne model; its descriptor carries Scoring/SystemOne at 32k | Task 3 (D3, D4) |
| D5 item cap per protocol: SystemOne 64 (Rerank 100 is PR 3) | Task 4 (D9) |
| D5 budget scales with the stored window: state `min(10_000, 30 %)`, framing 2,000, items the rest | Task 4 (D7, D8) |
| D9 model list and configuration JSON gain `kind` and `family`, camelCase; `label` and `capabilities` stay; no new endpoint | Task 5 (D11), Task 10 (§6 in the PR) |
| D9 two-option control above the dropdown, preselected from the connection's model; dropdown lists only that kind | Task 6, Task 7 (D13, D14) |
| D9 scoring options tagged "Decision model" ("Reranker" in PR 3) | Task 7 (D12, D15) |
| D9 hint: a decision model's score is a probability (the reranker half in PR 3) | Task 7 (D15) |
| D9 a kind with no models is disabled with "This provider offers no scoring models" (or LLMs) | Task 6, Task 7 |
| D9 Jev copy (`addIntro`, `modelPicker`, `guide.jev*`, `guide-jev`) becomes general, en and de | Task 8 |
| D10 no new exception types | No task adds one |
| Testing: catalog mapping table and fallback rule | Task 2, Task 3 |
| Testing: Jest for the kind control, filtering, family tag and hint; one Playwright smoke with a stubbed model list | Task 7, Task 9 |
| Testing: a real run with a decision model other than Jev | Task 10 Step 6 (D17, gated) |
| Docs: `recommendations-runs.md` (catalog, protocol table, caps and budgets) | Task 10 Step 1; `design-language.md` (Task 6) |
| Carry-forward 1 (first descriptor wins) | D4, Task 3 |
| Carry-forward 2 (budget placement) | D7, Task 4 |
| Carry-forward 3 (state rebuild per request) | D16 (unchanged) |
| Carry-forward 4 (`ENGINE_SWITCH` wording) | D16 (PR 3) |
| Carry-forward 5–6 (Infection on renames; implementer gotchas) | Global Constraints (cache clears, `touch`, MySQL cache, PhpStorm via ToolSearch); this PR renames no file |

## Where the code contradicted the spec

- OpenRouter's plain listing offers text-and-image and text-and-audio models; a strict `["text"]` rule would drop them (D1).
- `AiProviderConfigurator::activate()` re-verifies the model against the catalog, so an OpenRouter connection on the bare `jev-latest` cannot be re-activated after the fallback rule, not only not re-chosen as spec D4 said (D5). This also closes the real run's route back to `jev-latest` (D17).
- With the cap on the protocol, PR 1's planned `ScoringBudgetFactory` input would only forward the window; the budget moves to the protocol (D7).
- `<app-field>` cannot hold the two-option control: its `<label>` would press the first button on a label click (D13).
- Spec D5's Kev example mixed 8,000 and 8,192: at Kev's 8,192-token window the state gets 2,457, framing 2,000 and the questions 3,735 (spec corrected).
