# AI-Powered Recommendation Feed ("For you") Implementation Plan (#308)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** An AI-selected recommendation feed: the app sends the user's weighted reading history plus a candidate pool of unread posts to the configured OpenAI-compatible provider, receives a ranked pick list, persists it as a run, and displays it like a tag feed under a sidebar row "For you".

**Architecture:** A poll-driven run state machine (like the refresh loop): `POST /api/recommendations/runs` creates or resumes a run, and each `POST /api/recommendations/runs/tick` performs **at most one provider call** behind a per-user Symfony lock. The tick is a **driver-agnostic service method** (`RecommendationRunAdvancer::advance()`) — #311 later adds a worker container that calls the same method; nothing in this plan may assume the HTTP request is the only caller. Runs checkpoint per batch in JSON columns on `recommendation_run`; final picks land in `recommendation_item` rows that the existing `/api/entries` endpoint serves under a new `for-you` view with its own `(runId, position)` cursor. Prompt sizing (description length, batch partition) is derived from the model's context window, which a widened `ModelCatalog` now captures from `/models`.

**Tech Stack:** Symfony 7.4 / PHP 8.4, Doctrine (platform-aware MySQL + SQLite migration), Symfony Lock + RateLimiter, Symfony HttpClient against an OpenAI-compatible `/chat/completions`, Angular 20 signals, CDK overlay (toast), Jest, Transloco.

## Global Constraints

- `declare(strict_types=1)` in every PHP file; PSR-12 (`composer cs`, autofix with `composer cs:fix`); PHPStan level max (`composer stan`; warm the dev cache first: `bin/console cache:warmup`).
- Every touched `src` file must be **PHPMD-clean** (`composer md`), not merely free of new findings. The one sanctioned suppression style is `RefreshRunner`'s `@SuppressWarnings("PHPMD.ExcessiveParameterList")` on a pipeline composition root, with a comment saying why.
- Controllers hold no private methods that carry responsibility (`ThinControllerRule`; its allow-list is empty and stays empty).
- Datetimes are stored as **naive UTC**; `ClockInterface` (Symfony `Clock`) is the only time source in services.
- Migrations are never executed by the test suite (schema is built from ORM metadata). DDL must be **platform-aware** (MySQL + SQLite branches, `Version20260806120000.php` is the new-table pattern). CI's migrate-from-empty leg is the only runtime check — verify both dialects by hand in Task 4.
- Errors are typed exceptions; API errors extend `ApiException` and become `application/problem+json` via `ApiExceptionListener`. Reuse the existing AI mappings (`ai_provider_rejected` 422, `ai_not_configured` 404, `ai_key_unreadable` 422, `rate_limited` 429).
- Outbound HTTP to the provider copies the `OpenAiCompatibleCatalog` idiom: `Accept-Encoding: identity`, `on_progress` byte cap, `max_redirects: 0`, explicit `timeout`/`max_duration`, `User-Agent` from `%outbound_user_agent%`. Provider base URLs deliberately bypass `UrlGuard` (decided in #305) — do not "fix" that, and do not copy it anywhere else.
- Mutation testing gates changed files (`composer infection:diff`); every guard branch added here needs a test that kills its mutant.
- Frontend: Prettier 100-col; Stylelint bans hex colours and raw `px` spacing outside `theme/`; component styles live in sibling `.scss` files; standalone components + signals. `npm run check` from `frontend/` is the gate.
- `en.json` and `de.json` must change together — `i18n-dictionaries.spec.ts` fails on key-set drift or empty values.
- Shared components receive **already-translated strings**, never keys.
- New services that tests fetch from the container must be listed in `backend/config/services_test.yaml` with `autowire: true, public: true` (the file has no `_defaults`).
- The toast renders through the **CDK overlay** (`Dialog` + global position strategy, `hasBackdrop: false`, `autoFocus: false`), never `position: fixed` (#100/#85 transform trap).
- Token-level streaming to the browser is **out of scope** (#308); streamed provider reads are #312; the worker container is #311. Scheduled runs are out of scope — the run starts from a manual button only.
- Commit messages follow the house pattern: `type(#308): imperative summary`.

## File Structure

| File | Responsibility |
|---|---|
| `backend/src/Service/Ai/ModelDescriptor.php` (new) | `{id, contextWindow}` from `/models` |
| `backend/src/Service/Ai/ModelCatalog.php` + `OpenAiCompatibleCatalog.php` | return descriptors; parse `context_length`/`max_context_length` |
| `backend/src/Service/Ai/AiProviderConfigurator.php` | store context window on model choice; public `credentials()` |
| `backend/src/Entity/AiProviderSettings.php` | nullable `model_context_window` column |
| `backend/src/Entity/RecommendationSettings.php` (new) + `Repository/RecommendationSettingsRepository.php` (new) | one-row-per-user tuning values ("no row = defaults") |
| `backend/src/Service/Recommendation/RecommendationSettingsValues.php` (new) | write-side value object (8 fields) |
| `backend/src/Service/Recommendation/EffectiveRecommendationSettings.php` (new) + `RecommendationSettingsResolver.php` (new) | defaults + context-window fallback chain (user → provider → 32768) |
| `backend/src/Entity/RecommendationRun.php` (new) + `RecommendationItem.php` (new) + repositories (new) | run state machine rows + final picks |
| `backend/migrations/Version20260807130000.php` (new) | all DDL: 3 new tables + 1 new column, both dialects |
| `backend/src/Service/Recommendation/PromptLine.php`, `RecommendationHistory.php`, `RecommendationHistoryLoader.php`, `RecommendationCandidateLoader.php` (new) | history sections + candidate pool queries |
| `backend/src/Repository/UnreadDql.php` (new) | the one unread predicate, shared |
| `backend/src/Service/Recommendation/RecommendationPromptText.php` + `RecommendationPromptBuilder.php` (new) | fixed prompt layers, guidance, sizing, batch packing |
| `backend/src/Service/Recommendation/ChatCompletionClient.php` + `OpenAiCompatibleChatClient.php` (new) | one JSON-mode chat completion |
| `backend/src/Service/Recommendation/RecommendationPick.php`, `PickParseResult.php`, `RecommendationPickParser.php` (new) | defensive parse + salvage |
| `backend/src/Service/Recommendation/RecommendationRunReport.php`, `RecommendationRunStarter.php`, `RecommendationRunAdvancer.php` (new) | the driver-agnostic tick |
| `backend/src/Controller/Api/RecommendationRunController.php` (new) | start / tick / current |
| `backend/src/Controller/Api/RecommendationSettingsController.php` (new) + `Dto/Recommendation/SaveRecommendationSettingsRequest.php` (new) + `Http/RecommendationSettingsJson.php` (new) | settings API |
| `backend/src/Service/Recommendation/RecommendationSettingsWriter.php` (new) | upsert the settings row |
| `backend/src/Http/RecommendationCursor.php` (new), `Repository/RecommendationFeedRow.php` (new), `Repository/RecommendationItemRepository.php` | `(runId, position)` keyset feed query with newest-run dedup |
| `backend/src/Repository/EffectiveReadState.php` (new) | explicit-flag-or-watermark read state, shared |
| `backend/src/Service/Recommendation/RecommendationFeedPager.php` (new) + `Http/RecommendationFeedJson.php` (new) | page assembly for the `for-you` view |
| `backend/src/Controller/Api/EntryController.php` + `Repository/EntryQuery.php` + `EntryRepository.php` | accept view `for-you`, delegate |
| `backend/src/Service/EntryPruner.php` | delete completed runs with no items |
| `backend/config/packages/rate_limiter.yaml` | `ai_recommendations` limiter |
| `backend/tests/Support/StubChatClient.php` (new) | scripted provider double |
| `frontend/src/app/reader/models.ts`, `query.ts`, `reader-api.ts` | `for-you` view plumbing + run endpoints |
| `frontend/src/app/reader/recommendations.service.ts` (new) | poll loop (modeled on `RefreshService`) |
| `frontend/src/app/shared/toast/toast.service.ts` + `toast.component.{ts,html,scss}` (new) | the app's one toast |
| `frontend/src/app/reader/sidebar/sidebar.component.{ts,html,scss}` | "For you" row, `ai.ready`-gated, pulsing while running |
| `frontend/src/app/reader/reader-shell.component.{ts,html,scss}` | resume on boot, title, run button + determinate progress |
| `frontend/src/app/reader/entry-row/entry-row.component.{ts,html,scss}` | muted reason line |
| `frontend/src/app/settings/recommendation-settings.service.ts` + `recommendation-settings-card.component.{ts,html,scss}` (new), `ai-section.component.html` | settings card |
| `frontend/public/i18n/en.json` + `de.json` | all new keys |

---

### Task 1: Model catalog reports context windows

**Files:**
- Create: `backend/src/Service/Ai/ModelDescriptor.php`
- Modify: `backend/src/Service/Ai/ModelCatalog.php`, `backend/src/Service/Ai/OpenAiCompatibleCatalog.php`, `backend/src/Service/Ai/AiProviderConfigurator.php`, `backend/src/Entity/AiProviderSettings.php`, `backend/tests/Support/StubModelCatalog.php`
- Test: `backend/tests/Service/Ai/OpenAiCompatibleCatalogTest.php`, `backend/tests/Service/Ai/AiProviderConfiguratorTest.php`

**Interfaces:**
- Consumes: existing `ProviderCredentials`, `AiProviderSettings`.
- Produces: `ModelDescriptor` readonly `{public string $id, public ?int $contextWindow}`; `ModelCatalog::listModels(ProviderCredentials $credentials): array` now returns `list<ModelDescriptor>` (sorted by id, unique, never empty); `AiProviderSettings::getModelContextWindow(): ?int`; `AiProviderSettings::chooseModel(string $model, \DateTimeImmutable $verifiedAt, ?int $contextWindow): void`; `AiProviderConfigurator::credentials(AiProviderSettings $settings): ProviderCredentials` (public; throws `ApiKeyUnreadableException`). `AiProviderConfigurator::saveConnection()` and `listModels()` keep returning `list<string>` of ids so `AiSettingsJson::models()` and the frontend dropdown are untouched.

- [ ] **Step 1: Write the failing catalog test**

Add to `backend/tests/Service/Ai/OpenAiCompatibleCatalogTest.php`:

```php
public function testCapturesContextLengthWhenTheProviderReportsOne(): void
{
    $catalog = $this->catalogAnswering(json_encode(['data' => [
        ['id' => 'small', 'context_length' => 8192],
        ['id' => 'big', 'max_context_length' => 200000],
        ['id' => 'silent'],
    ]], JSON_THROW_ON_ERROR));

    $models = $catalog->listModels($this->credentials());

    self::assertSame(['big', 'silent', 'small'], array_map(static fn ($m) => $m->id, $models));
    self::assertSame([200000, null, 8192], array_map(static fn ($m) => $m->contextWindow, $models));
}
```

(`catalogAnswering()` / `credentials()` already exist in that test class as MockHttpClient helpers; reuse them. If the helper names differ, adapt to the existing ones — do not add a second MockHttpClient setup.)

- [ ] **Step 2: Run it to verify it fails**

Run: `cd backend && php bin/phpunit tests/Service/Ai/OpenAiCompatibleCatalogTest.php`
Expected: FAIL — descriptors don't exist yet (array of strings).

- [ ] **Step 3: Implement the descriptor and widen the catalog**

Create `backend/src/Service/Ai/ModelDescriptor.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Ai;

/**
 * One model as the provider's /models endpoint describes it. The context
 * window is null when the provider does not report one — most OpenAI-style
 * gateways do (context_length or max_context_length), OpenAI itself does not.
 */
final readonly class ModelDescriptor
{
    public function __construct(
        public string $id,
        public ?int $contextWindow,
    ) {
    }
}
```

In `ModelCatalog.php`, change the return contract:

```php
/**
 * @return list<ModelDescriptor> sorted by id, unique ids, never empty
 *
 * @throws CredentialsRejectedException
 * @throws ProviderUnreachableException
 */
public function listModels(ProviderCredentials $credentials): array;
```

In `OpenAiCompatibleCatalog::identifiers()` (rename to `descriptors()`), build descriptors instead of strings:

```php
/**
 * @param list<mixed> $entries
 *
 * @return list<ModelDescriptor>
 */
private function descriptors(array $entries): array
{
    $byId = [];

    foreach ($entries as $entry) {
        if (!\is_array($entry) || !isset($entry['id']) || !\is_string($entry['id']) || '' === $entry['id']) {
            continue;
        }
        $byId[$entry['id']] ??= new ModelDescriptor($entry['id'], $this->reportedContextWindow($entry));
    }

    ksort($byId, SORT_STRING);

    return array_values($byId);
}

/** @param array<mixed> $entry */
private function reportedContextWindow(array $entry): ?int
{
    foreach (['context_length', 'max_context_length'] as $field) {
        if (isset($entry[$field]) && \is_int($entry[$field]) && $entry[$field] > 0) {
            return $entry[$field];
        }
    }

    return null;
}
```

- [ ] **Step 4: Run the catalog tests**

Run: `php bin/phpunit tests/Service/Ai/OpenAiCompatibleCatalogTest.php`
Expected: the new test passes; the pre-existing tests fail on the return type — update their assertions to `array_map(static fn ($m) => $m->id, $models)` where they asserted id lists. All green afterwards.

- [ ] **Step 5: Write the failing configurator test**

Add to `backend/tests/Service/Ai/AiProviderConfiguratorTest.php`:

```php
public function testChoosingAModelStoresItsReportedContextWindow(): void
{
    $user = $this->savedUser();
    $this->configurator()->saveConnection($user, 'https://api.example.test/v1', 'sk-test-key');
    $settings = $this->configurator()->requireConfiguration($user);

    $this->configurator()->chooseModel($settings, 'big');

    self::assertSame(200000, $settings->getModelContextWindow());
}

public function testReplacingTheConnectionClearsTheContextWindow(): void
{
    $user = $this->savedUser();
    $this->configurator()->saveConnection($user, 'https://api.example.test/v1', 'sk-test-key');
    $settings = $this->configurator()->requireConfiguration($user);
    $this->configurator()->chooseModel($settings, 'big');

    $this->configurator()->saveConnection($user, 'https://api.example.test/v1', 'sk-other-key');

    self::assertNull($settings->getModelContextWindow());
}
```

Configure the test's `StubModelCatalog` to answer `[new ModelDescriptor('big', 200000), new ModelDescriptor('small', null)]` — update `tests/Support/StubModelCatalog.php` to return descriptors (its closure contract stays, only the return payload type widens). Adapt helper names to the existing test class.

- [ ] **Step 6: Run it to verify it fails**

Run: `php bin/phpunit tests/Service/Ai/AiProviderConfiguratorTest.php`
Expected: FAIL — `getModelContextWindow()` undefined.

- [ ] **Step 7: Implement entity column + configurator changes**

In `AiProviderSettings.php` add below `$model`:

```php
/**
 * The chosen model's context window as /models reported it at choose time,
 * tokens. Null when the provider did not report one. Cleared with the model
 * on replaceConnection() — a new endpoint may be a different gateway.
 */
#[ORM\Column(nullable: true)]
private ?int $modelContextWindow = null;
```

`chooseModel(string $model, \DateTimeImmutable $verifiedAt, ?int $contextWindow): void` sets both; `replaceConnection()` nulls `$modelContextWindow` alongside `$model`; add `getModelContextWindow(): ?int`.

In `AiProviderConfigurator`:
- `saveConnection()` / `listModels()` map descriptors to ids before returning (`array_map(static fn (ModelDescriptor $m) => $m->id, $descriptors)`), so their public contract is unchanged.
- `chooseModel()` finds the chosen descriptor in the freshly listed set (it already lists to validate the model is offered) and passes `$descriptor->contextWindow` through to the entity.
- Rename private `credentialsFor()` to public `credentials()` (same body); keep all internal callers.

- [ ] **Step 8: Run the whole Ai test directory**

Run: `php bin/phpunit tests/Service/Ai tests/Controller/Api/AiSettingsControllerTest.php tests/Http/AiSettingsJsonTest.php`
Expected: PASS (the JSON layer never sees descriptors).

- [ ] **Step 9: Lint and commit**

```bash
cd backend && composer cs:fix && composer check && composer md
git add -A && git commit -m "feat(#308): model catalog captures the reported context window"
```

---

### Task 2: RecommendationSettings entity + effective defaults

**Files:**
- Create: `backend/src/Entity/RecommendationSettings.php`, `backend/src/Repository/RecommendationSettingsRepository.php`, `backend/src/Service/Recommendation/RecommendationSettingsValues.php`, `backend/src/Service/Recommendation/EffectiveRecommendationSettings.php`, `backend/src/Service/Recommendation/RecommendationSettingsResolver.php`
- Modify: `backend/src/Entity/User.php` (inverse OneToOne, `cascade: ['remove']`, like `aiProviderSettings`), `backend/config/services_test.yaml` (resolver public)
- Test: `backend/tests/Service/Recommendation/RecommendationSettingsResolverTest.php` (create)

**Interfaces:**
- Consumes: `AiProviderSettingsRepository::findForUser()`, `AiProviderSettings::getModelContextWindow()` (Task 1).
- Produces:
  - `RecommendationSettingsValues` readonly: `{?string $guidancePrompt, int $favoritesCap, int $keptCap, int $viewedCap, int $candidatePoolSize, int $picksLimit, ?int $contextWindow, bool $debugEnabled}`.
  - `RecommendationSettings` (table `user_recommendation_settings`, "no row = defaults" like `AiProviderSettings`): `__construct(User $user)`, `update(RecommendationSettingsValues $values): void`, `values(): RecommendationSettingsValues`, `getUser(): User`.
  - `EffectiveRecommendationSettings` readonly: `{?string $guidancePrompt, int $favoritesCap, int $keptCap, int $viewedCap, int $candidatePoolSize, int $picksLimit, int $contextWindow, string $contextWindowSource /* 'user'|'provider'|'fallback' */, bool $debugEnabled}` with consts `DEFAULT_FAVORITES_CAP = 40`, `DEFAULT_KEPT_CAP = 40`, `DEFAULT_VIEWED_CAP = 80`, `DEFAULT_CANDIDATE_POOL_SIZE = 1000`, `DEFAULT_PICKS_LIMIT = 100`, `FALLBACK_CONTEXT_WINDOW = 32768`.
  - `RecommendationSettingsResolver::forUser(User $user): EffectiveRecommendationSettings`.

- [ ] **Step 1: Write the failing resolver test**

Create `backend/tests/Service/Recommendation/RecommendationSettingsResolverTest.php` extending `DbTestCase`; seed a user via `UserFactory` (grab the hasher from the container as `EntryControllerTest` does, or persist a `User` directly like `EntryListTest`):

```php
public function testAllDefaultsWhenNoRowAndNoProviderWindow(): void
{
    $effective = $this->resolver()->forUser($this->user);

    self::assertNull($effective->guidancePrompt);
    self::assertSame(40, $effective->favoritesCap);
    self::assertSame(40, $effective->keptCap);
    self::assertSame(80, $effective->viewedCap);
    self::assertSame(1000, $effective->candidatePoolSize);
    self::assertSame(100, $effective->picksLimit);
    self::assertSame(32768, $effective->contextWindow);
    self::assertSame('fallback', $effective->contextWindowSource);
    self::assertFalse($effective->debugEnabled);
}

public function testProviderReportedWindowBeatsTheFallback(): void
{
    $this->seedAiSettingsWithModel($this->user, contextWindow: 200000);

    $effective = $this->resolver()->forUser($this->user);

    self::assertSame(200000, $effective->contextWindow);
    self::assertSame('provider', $effective->contextWindowSource);
}

public function testUserOverrideBeatsTheProviderWindow(): void
{
    $this->seedAiSettingsWithModel($this->user, contextWindow: 200000);
    $row = new RecommendationSettings($this->user);
    $row->update(new RecommendationSettingsValues(
        guidancePrompt: 'Only cats.',
        favoritesCap: 10,
        keptCap: 20,
        viewedCap: 30,
        candidatePoolSize: 500,
        picksLimit: 50,
        contextWindow: 65536,
        debugEnabled: true,
    ));
    $this->em->persist($row);
    $this->em->flush();

    $effective = $this->resolver()->forUser($this->user);

    self::assertSame('Only cats.', $effective->guidancePrompt);
    self::assertSame(10, $effective->favoritesCap);
    self::assertSame(65536, $effective->contextWindow);
    self::assertSame('user', $effective->contextWindowSource);
    self::assertTrue($effective->debugEnabled);
}
```

`seedAiSettingsWithModel()` persists an `AiProviderSettings` (use `ApiKeyCipher` from the container to seal a throwaway key, mirroring `AiProviderConfiguratorTest` seeding) and calls `chooseModel('m', $now, $contextWindow)`. `resolver()` fetches `RecommendationSettingsResolver::class` from the container.

- [ ] **Step 2: Run it to verify it fails**

Run: `php bin/phpunit tests/Service/Recommendation/RecommendationSettingsResolverTest.php`
Expected: FAIL — classes don't exist.

- [ ] **Step 3: Implement**

`RecommendationSettings.php` — follow the `AiProviderSettings` shape exactly (id, `OneToOne(inversedBy: 'recommendationSettings')` with `JoinColumn(nullable: false, onDelete: 'CASCADE')`, `UniqueConstraint(name: 'uniq_recommendation_settings_user', columns: ['user_id'])`, table `user_recommendation_settings`). Columns: `guidancePrompt` `Types::TEXT` nullable; `favoritesCap`/`keptCap`/`viewedCap`/`candidatePoolSize`/`picksLimit` int with `options: ['default' => …]` matching the defaults; `contextWindow` nullable int; `debugEnabled` bool `options: ['default' => false]`. Class docblock: "No row = all defaults (see EffectiveRecommendationSettings); the row exists only once the user saves the settings form." `update()` assigns all eight from the values object; `values()` reconstructs one (used by the settings API to echo state back).

`RecommendationSettingsRepository` — `final class … extends ServiceEntityRepository<RecommendationSettings>` with `findForUser(User $user): ?RecommendationSettings`.

`User.php` — add the inverse `#[ORM\OneToOne(mappedBy: 'user', targetEntity: RecommendationSettings::class, cascade: ['remove'])] private ?RecommendationSettings $recommendationSettings = null;` (no getter needed yet; the cascade is what matters for account deletion).

`RecommendationSettingsResolver`:

```php
final readonly class RecommendationSettingsResolver
{
    public function __construct(
        private RecommendationSettingsRepository $settings,
        private AiProviderSettingsRepository $providerSettings,
    ) {
    }

    public function forUser(User $user): EffectiveRecommendationSettings
    {
        $row = $this->settings->findForUser($user);
        $providerWindow = $this->providerSettings->findForUser($user)?->getModelContextWindow();

        [$window, $source] = match (true) {
            null !== $row?->values()->contextWindow => [$row->values()->contextWindow, 'user'],
            null !== $providerWindow => [$providerWindow, 'provider'],
            default => [EffectiveRecommendationSettings::FALLBACK_CONTEXT_WINDOW, 'fallback'],
        };

        return new EffectiveRecommendationSettings(
            guidancePrompt: $row?->values()->guidancePrompt,
            favoritesCap: $row?->values()->favoritesCap ?? EffectiveRecommendationSettings::DEFAULT_FAVORITES_CAP,
            keptCap: $row?->values()->keptCap ?? EffectiveRecommendationSettings::DEFAULT_KEPT_CAP,
            viewedCap: $row?->values()->viewedCap ?? EffectiveRecommendationSettings::DEFAULT_VIEWED_CAP,
            candidatePoolSize: $row?->values()->candidatePoolSize
                ?? EffectiveRecommendationSettings::DEFAULT_CANDIDATE_POOL_SIZE,
            picksLimit: $row?->values()->picksLimit ?? EffectiveRecommendationSettings::DEFAULT_PICKS_LIMIT,
            contextWindow: $window,
            contextWindowSource: $source,
            debugEnabled: $row?->values()->debugEnabled ?? false,
        );
    }
}
```

Register the resolver in `services_test.yaml` (`autowire: true, public: true`).

- [ ] **Step 4: Run to verify pass**

Run: `php bin/phpunit tests/Service/Recommendation/`
Expected: PASS.

- [ ] **Step 5: Lint and commit**

```bash
composer cs:fix && composer check && composer md
git add -A && git commit -m "feat(#308): per-user recommendation settings with effective defaults"
```

---

### Task 3: Run + item entities

**Files:**
- Create: `backend/src/Entity/RecommendationRun.php`, `backend/src/Entity/RecommendationItem.php`, `backend/src/Repository/RecommendationRunRepository.php`, `backend/src/Repository/RecommendationItemRepository.php`
- Test: `backend/tests/Entity/RecommendationRunTest.php` (create), `backend/tests/Repository/RecommendationRunRepositoryTest.php` (create)

**Interfaces:**
- Produces:
  - `RecommendationRun` (table `recommendation_run`): consts `STATUS_PENDING = 'pending'`, `STATUS_RUNNING = 'running'`, `STATUS_COMPLETED = 'completed'`, `STATUS_FAILED = 'failed'`, `MAX_ATTEMPTS = 3` (first call + the spec's 2 retries). API: `__construct(User $user, \DateTimeImmutable $createdAt)`; `getId(): ?int`; `getUser(): User`; `getStatus(): string`; `getError(): ?string`; `getCreatedAt()/getCompletedAt(): ?\DateTimeImmutable`; `snapshot(array $candidateBatches): void` (pending → running; `list<list<int>>`; throws `\LogicException` otherwise); `candidateBatches(): array`; `batchesDone(): int`; `batchesTotal(): ?int` (null while pending; `count(batches) + (count > 1 ? 1 : 0)` after — the merge call is a batch for progress purposes); `needsMerge(): bool`; `isMergePhase(): bool` (`batchesDone === count(batches) && needsMerge()`); `allBatchCallsDone(): bool`; `nextBatchIndex(): int`; `recordBatchWinners(array $picks): void` (`list<array{id:int,reason:string}>`; appends to winners, increments `batchesDone`, resets attempts + invalid reply); `winners(): array` (`list<list<array{id:int,reason:string}>>`); `recordInvalidReply(string $reply): void`; `attemptsExhausted(): bool`; `getLastInvalidReply(): ?string`; `complete(\DateTimeImmutable $when): void` (→ completed, stamps `completedAt`, sets `batchesDone` to `batchesTotal()`); `fail(string $error, \DateTimeImmutable $when): void`; `resume(): void` (failed → running, clears error/attempts/invalid reply; throws `\LogicException` on any other status).
  - `RecommendationItem` (table `recommendation_item`): `__construct(RecommendationRun $run, Entry $entry, int $position, string $reason)` + getters; unique `(recommendation_run_id, position)`.
  - `RecommendationRunRepository::findActiveForUser(User): ?RecommendationRun` (status pending or running), `findLatestForUser(User): ?RecommendationRun` (newest by id).

- [ ] **Step 1: Write the failing entity test**

Create `backend/tests/Entity/RecommendationRunTest.php` (plain `TestCase`, like `EntryStateTest`) covering the state machine:

```php
public function testSnapshotMovesPendingToRunningAndFixesTheBatchPlan(): void
{
    $run = $this->run();

    $run->snapshot([[1, 2], [3]]);

    self::assertSame(RecommendationRun::STATUS_RUNNING, $run->getStatus());
    self::assertSame([[1, 2], [3]], $run->candidateBatches());
    self::assertSame(3, $run->batchesTotal()); // 2 batches + 1 merge
    self::assertTrue($run->needsMerge());
}

public function testASingleBatchNeedsNoMerge(): void
{
    $run = $this->run();
    $run->snapshot([[1, 2, 3]]);

    self::assertSame(1, $run->batchesTotal());
    self::assertFalse($run->needsMerge());
}

public function testRecordingWinnersAdvancesAndClearsRetryState(): void
{
    $run = $this->run();
    $run->snapshot([[1, 2], [3]]);
    $run->recordInvalidReply('garbage');

    $run->recordBatchWinners([['id' => 2, 'reason' => 'fresh']]);

    self::assertSame(1, $run->batchesDone());
    self::assertSame([[['id' => 2, 'reason' => 'fresh']]], $run->winners());
    self::assertNull($run->getLastInvalidReply());
    self::assertFalse($run->attemptsExhausted());
    self::assertSame(1, $run->nextBatchIndex());
    self::assertFalse($run->isMergePhase());
}

public function testThirdInvalidReplyExhaustsAttempts(): void
{
    $run = $this->run();
    $run->snapshot([[1]]);
    $run->recordInvalidReply('a');
    $run->recordInvalidReply('b');
    self::assertFalse($run->attemptsExhausted());

    $run->recordInvalidReply('c');

    self::assertTrue($run->attemptsExhausted());
    self::assertSame('c', $run->getLastInvalidReply());
}

public function testMergePhaseAfterAllBatchCalls(): void
{
    $run = $this->run();
    $run->snapshot([[1], [2]]);
    $run->recordBatchWinners([['id' => 1, 'reason' => 'r']]);
    $run->recordBatchWinners([['id' => 2, 'reason' => 'r']]);

    self::assertTrue($run->isMergePhase());
}

public function testResumeIsOnlyLegalFromFailed(): void
{
    $run = $this->run();
    $run->snapshot([[1]]);
    $run->fail('boom', new \DateTimeImmutable('2026-08-07T10:00:00Z'));

    $run->resume();

    self::assertSame(RecommendationRun::STATUS_RUNNING, $run->getStatus());
    self::assertNull($run->getError());
    self::assertSame([[1]], $run->candidateBatches()); // checkpoints survive

    $this->expectException(\LogicException::class);
    $run->resume();
}

public function testCompleteStampsAndFillsProgress(): void
{
    $run = $this->run();
    $run->snapshot([[1], [2]]);
    $when = new \DateTimeImmutable('2026-08-07T10:00:00Z');

    $run->complete($when);

    self::assertSame(RecommendationRun::STATUS_COMPLETED, $run->getStatus());
    self::assertSame($when, $run->getCompletedAt());
    self::assertSame(3, $run->batchesDone());
}
```

`run()` builds `new RecommendationRun(new User(...), new \DateTimeImmutable('2026-08-07T09:00:00Z'))` — construct `User` the way `EntryStateTest` does.

- [ ] **Step 2: Run to verify failure** — `php bin/phpunit tests/Entity/RecommendationRunTest.php` → FAIL (class missing).

- [ ] **Step 3: Implement the entities**

`RecommendationRun.php` — mapping notes: `user` is `ManyToOne(targetEntity: User::class)` + `JoinColumn(nullable: false, onDelete: 'CASCADE')`; `status` `Column(length: 16)`; `createdAt`/`completedAt` `DATETIME_IMMUTABLE` (completedAt nullable); `error` TEXT nullable; `candidateBatches` `Column(type: Types::JSON, nullable: true)` (`null` while pending — the docblock explains snapshot semantics: "The candidate pool is frozen at snapshot time so that a resumed run retries the exact failed batch (#308); history is deliberately NOT frozen — it only shades the prompt."); `batchWinners` `Column(type: Types::JSON)` default `[]`; `batchesDone` int `options: ['default' => 0]`; `attempts` int `options: ['default' => 0]`; `lastInvalidReply` TEXT nullable. Add an index `#[ORM\Index(name: 'idx_recommendation_run_user_status', columns: ['user_id', 'status'])]`. Guard every transition with `\LogicException` on an illegal source status. `attemptsExhausted(): bool` is `$this->attempts >= self::MAX_ATTEMPTS`.

`RecommendationItem.php`:

```php
#[ORM\Entity(repositoryClass: RecommendationItemRepository::class)]
#[ORM\Table(name: 'recommendation_item')]
#[ORM\UniqueConstraint(name: 'uniq_recommendation_item_run_position', columns: ['recommendation_run_id', 'position'])]
final class RecommendationItem
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: RecommendationRun::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private RecommendationRun $run;

    // DB-level cascade, not ORM cascade: EntryPruner bulk-deletes entries via
    // DQL, which never runs ORM cascades (same reasoning as entry_state).
    #[ORM\ManyToOne(targetEntity: Entry::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Entry $entry;

    #[ORM\Column]
    private int $position;

    #[ORM\Column(type: Types::TEXT)]
    private string $reason;
    // __construct assigns all four; getters only. No setters — items are
    // written once at run completion and never edited.
}
```

`RecommendationRunRepository` with the two finders (order `findLatestForUser` by `id DESC`, `setMaxResults(1)`; `findActiveForUser` filters `status IN (:active)` with `[STATUS_PENDING, STATUS_RUNNING]`). `RecommendationItemRepository` is an empty shell for now (Task 13 fills it).

- [ ] **Step 4: Write + run the repository test**

`backend/tests/Repository/RecommendationRunRepositoryTest.php` extends `DbTestCase`: persist two users; a completed run and a running run for user A, a failed run for user B. Assert `findActiveForUser(A)` returns the running one, `findActiveForUser(B)` is null, `findLatestForUser(B)` returns the failed one. Run: `php bin/phpunit tests/Entity/RecommendationRunTest.php tests/Repository/RecommendationRunRepositoryTest.php` → PASS.

- [ ] **Step 5: Lint and commit**

```bash
composer cs:fix && composer check && composer md
git add -A && git commit -m "feat(#308): recommendation run state machine and item entities"
```

---

### Task 4: Migration (all DDL) + both-dialect verification

**Files:**
- Create: `backend/migrations/Version20260807130000.php`

**Interfaces:**
- Consumes: the ORM metadata from Tasks 1–3.
- Produces: tables `user_recommendation_settings`, `recommendation_run`, `recommendation_item`; column `user_ai_settings.model_context_window`.

- [ ] **Step 1: Generate the canonical DDL**

Run: `cd backend && bin/console cache:warmup && bin/console doctrine:migrations:diff`
This emits a migration against the configured dev database with Doctrine's generated FK/index hash names. **Copy the emitted statements** (both the CREATE TABLEs and the ALTER) into a new hand-written `Version20260807130000.php` and delete the generated file — the diff output is single-dialect, so it becomes the branch for whichever platform dev runs; write the other branch by hand mirroring `Version20260806120000.php` (MySQL: `id INT AUTO_INCREMENT NOT NULL` + separate `ALTER TABLE … ADD CONSTRAINT FK_… FOREIGN KEY … ON DELETE CASCADE` + `DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB`; SQLite: `id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL` with inline FKs, JSON columns as `CLOB`). Structure of the file:

```php
final class Version20260807130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Recommendation feed (#308): settings, run and item tables; '
            . 'context window on user_ai_settings.';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->skipIf($schema->hasTable('recommendation_run'), 'recommendation tables already exist; nothing to do.');

        $platform = $this->connection->getDatabasePlatform();

        if ($platform instanceof AbstractMySQLPlatform) {
            // … the four MySQL statements from the diff …
            return;
        }

        if ($platform instanceof SQLitePlatform) {
            // … the SQLite equivalents …
            return;
        }

        $this->abortIf(true, \sprintf(
            'No DDL defined for platform %s; only MySQL and SQLite are supported.',
            $platform::class,
        ));
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE recommendation_item');
        $this->addSql('DROP TABLE recommendation_run');
        $this->addSql('DROP TABLE user_recommendation_settings');
        $this->addSql('ALTER TABLE user_ai_settings DROP COLUMN model_context_window');
    }
}
```

The docblock must carry the house warning: "PLATFORM-AWARE DDL: tests build their schema from ORM metadata and never execute a migration; CI's migrate-from-empty leg is the only runtime check."

- [ ] **Step 2: Verify the SQLite leg from empty**

```bash
cd backend && DATABASE_URL="sqlite:///$PWD/var/migration-check.db" bin/console doctrine:migrations:migrate --no-interaction && DATABASE_URL="sqlite:///$PWD/var/migration-check.db" bin/console doctrine:schema:validate && rm var/migration-check.db
```
Expected: migrations run clean; schema validate reports the mapping and database are in sync.

- [ ] **Step 3: Verify the MySQL leg in Docker**

```bash
docker compose up -d && docker compose exec php bin/console doctrine:migrations:migrate --no-interaction && docker compose exec php bin/console doctrine:schema:validate
```
Expected: same. If `schema:validate` complains about FK/index names, replace yours with the names it expects.

- [ ] **Step 4: Run the full native suite** — `php bin/phpunit` → PASS (metadata-built schema already includes the new tables).

- [ ] **Step 5: Lint and commit**

```bash
composer cs:fix && composer check && composer md
git add -A && git commit -m "feat(#308): recommendation tables and context window migration"
```

---

### Task 5: History + candidate loaders (and the shared unread predicate)

**Files:**
- Create: `backend/src/Service/Recommendation/PromptLine.php`, `backend/src/Service/Recommendation/RecommendationHistory.php`, `backend/src/Service/Recommendation/RecommendationHistoryLoader.php`, `backend/src/Service/Recommendation/RecommendationCandidateLoader.php`, `backend/src/Repository/UnreadDql.php`
- Modify: `backend/src/Repository/EntryRepository.php` (`applyView` uses `UnreadDql`), `backend/src/Repository/EntryStateRepository.php` (`unreadCountsForUser` uses `UnreadDql`), `backend/config/services_test.yaml`
- Test: `backend/tests/Service/Recommendation/RecommendationHistoryLoaderTest.php`, `backend/tests/Service/Recommendation/RecommendationCandidateLoaderTest.php` (create both); existing `tests/Repository/EntryListTest.php` guards the refactor

**Interfaces:**
- Consumes: `PlainText::from()`, `EffectiveRecommendationSettings` (Task 2), the entities.
- Produces:
  - `PromptLine` readonly: `{?int $entryId, string $title, string $feedName, string $date /* Y-m-d */, ?string $description}` — history lines carry `entryId: null` (ids would only invite the model to pick them).
  - `RecommendationHistory` readonly: `{array $favorites, array $kept, array $viewed}` — each `list<PromptLine>`.
  - `RecommendationHistoryLoader::load(int $userId, EffectiveRecommendationSettings $settings): RecommendationHistory`.
  - `RecommendationCandidateLoader::load(int $userId, int $poolSize): array` — `list<PromptLine>` with non-null `entryId`, unread only, subscribed feeds only, newest first.
  - `RecommendationCandidateLoader::linesForIds(array $entryIds): array` — `array<int, PromptLine>` keyed by entry id, silently dropping ids whose entries were pruned since the snapshot.
  - `UnreadDql::predicate(): string` — the exact string currently duplicated in `EntryRepository::applyView` and `EntryStateRepository::unreadCountsForUser` (aliases `e`, `es`, `s`; parameter `:readFalse` normalized across all three callers).

- [ ] **Step 1: Write the failing history-loader test**

`RecommendationHistoryLoaderTest` extends `DbTestCase`. Seed one user, one feed + subscription, five entries; entry states: A favorite+kept+viewed, B kept+viewed, C viewed only, D no state, E favorite. Then:

```php
public function testEachEntryAppearsOnlyInItsHighestSection(): void
{
    $history = $this->loader()->load($this->userId(), $this->settings());

    self::assertSame(['E', 'A'], array_map(static fn ($l) => $l->title, $history->favorites));
    self::assertSame(['B'], array_map(static fn ($l) => $l->title, $history->kept));
    self::assertSame(['C'], array_map(static fn ($l) => $l->title, $history->viewed));
    self::assertNull($history->favorites[0]->entryId);
}

public function testCapsApplyNewestFirst(): void
{
    // settings with viewedCap: 1; C viewed at 10:00, B viewed at 11:00 but kept.
    // Add F viewed at 12:00 → viewed section is exactly [F].
}

public function testViewedOrdersByViewedAtNotEffectiveDate(): void
{
    // F published before C but viewed after it → F first.
}
```

Sections order: favorites and kept by `e.effectiveDate DESC` (no favorited-at timestamp exists — record that in the loader's docblock), viewed by `es.viewedAt DESC`. Write the second and third tests out fully with explicit `markViewed()` timestamps.

- [ ] **Step 2: Run to verify failure** — FAIL (classes missing).

- [ ] **Step 3: Implement the loaders and the predicate extraction**

`UnreadDql`:

```php
/**
 * The single definition of "unread" in DQL, previously duplicated between
 * EntryRepository::applyView and EntryStateRepository::unreadCountsForUser
 * (and now needed a third time by the recommendation candidate pool).
 * Aliases are fixed: e = Entry, es = EntryState, s = Subscription.
 * Callers must bind :readFalse to false with Types::BOOLEAN.
 */
final class UnreadDql
{
    public static function predicate(): string
    {
        return 'es.isRead = :readFalse '
            . 'OR (es.isRead IS NULL AND (s.markedReadUntil IS NULL '
            . 'OR e.effectiveDate > s.markedReadUntil))';
    }
}
```

Refactor both existing call sites to use it (`unreadCountsForUser` renames its parameter to `:readFalse`). Run `php bin/phpunit tests/Repository/` after the refactor — the existing tests are the safety net.

`RecommendationHistoryLoader` — three queries from `EntryState` root, each `join('es.entry', 'e')`, `join('e.feed', 'f')`, inner-join `Subscription` (`WITH s.feed = e.feed AND s.user = :user` — an unsubscribed feed's history is gone by design), select `e`, `f`, `s.customTitle AS customTitle`:

- favorites: `es.isFavorite = :true`, order `e.effectiveDate DESC, e.id DESC`, limit `favoritesCap`;
- kept: `es.isKept = :true AND es.isFavorite = :false`, same order, limit `keptCap`;
- viewed: `es.isViewed = :true AND es.isFavorite = :false AND es.isKept = :false`, order `es.viewedAt DESC`, limit `viewedCap`.

Each row → `PromptLine(null, $entry->getTitle(), $customTitle ?? $feed->getTitle() ?? $feed->getUrl(), $entry->getEffectiveDate()->format('Y-m-d'), PlainText::from($entry->getSummary() ?? $entry->getContentHtml()))`. Bind booleans with `Types::BOOLEAN`.

`RecommendationCandidateLoader::load()` — from `Entry` root, the same subscription/state joins as `EntryRepository::rowQueryBuilder`, `andWhere(UnreadDql::predicate())`, order `e.effectiveDate DESC, e.id DESC`, `setMaxResults($poolSize)`; map to `PromptLine` with `entryId`. `linesForIds()` — `WHERE e.id IN (:ids)` with the same joins minus the unread filter (a checkpointed batch must be retryable even after its entries were read), returned keyed by id.

Register both loaders in `services_test.yaml`.

- [ ] **Step 4: Write + run the candidate-loader test**

Cases: only unread entries are returned (seed one read-by-flag, one read-by-watermark, one unread → only the unread one); newest first; pool size caps the list; `linesForIds([id1, 999999])` returns only `id1`. Run both new test files + `tests/Repository/` → PASS.

- [ ] **Step 5: Lint and commit**

```bash
composer cs:fix && composer check && composer md
git add -A && git commit -m "feat(#308): history sections and candidate pool with one shared unread predicate"
```

---

### Task 6: Prompt text, sizing, and batch packing

**Files:**
- Create: `backend/src/Service/Recommendation/RecommendationPromptText.php`, `backend/src/Service/Recommendation/RecommendationPromptBuilder.php`
- Test: `backend/tests/Service/Recommendation/RecommendationPromptBuilderTest.php` (create)

**Interfaces:**
- Consumes: `PromptLine`, `RecommendationHistory`, `EffectiveRecommendationSettings`.
- Produces:
  - `RecommendationPromptText` — final class, consts only:
    - `SYSTEM_ROLE` = `"You rank candidate posts for one reader of an RSS reader. The user message holds four sections. FAVORITES, KEPT and VIEWED list posts from the reader's history, newest first. FAVORITES weighs strongest, KEPT next, VIEWED least. CANDIDATES lists unread posts; each line starts with the candidate id in square brackets. Prefer recent posts. When several candidates cover the same story, pick exactly one of them — the best source."`
    - `MERGE_ROLE` = `"You merge ranked shortlists from earlier rounds into one final ranking for the same reader. The user message lists WINNERS; each line starts with the candidate id in square brackets, followed by title, source, date and the reason it was shortlisted. Prefer recent posts. When several entries cover the same story, keep exactly one of them."`
    - `OUTPUT_CONTRACT` = `"Reply with JSON only, no prose: {\"recommendations\": [{\"id\": <candidate id>, \"reason\": \"<one short sentence>\"}]}. Order the array best first. Include at most %d picks. Use only ids that appear in the candidate lines."`
    - `CORRECTIVE` = `"Your previous reply was not usable. Reply again with JSON only, exactly in the required shape, using only candidate ids."`
    - `DEFAULT_GUIDANCE` = `"Recommend the posts this reader is most likely to open next, judged by how strongly they match the interests visible in the reading history."`
  - `RecommendationPromptBuilder` — pure (no constructor deps); consts `CHARS_PER_TOKEN = 4`, `FIXED_OVERHEAD_TOKENS = 1500`, `TOKENS_PER_PICK = 40`, `MINIMUM_BATCH_SIZE = 10`, `DESCRIPTION_MIN_CHARS = 120`, `DESCRIPTION_MAX_CHARS = 480`, `DESCRIPTION_WINDOW_DIVISOR = 137`, `MERGE_WINNERS_PER_BATCH_FACTOR = 2`. Methods:
    - `descriptionLength(int $contextWindow): int` — `min(480, max(120, intdiv($contextWindow, 137)))` (≈240 chars at the 32k fallback, scaling linearly, clamped).
    - `packBatches(array $candidates, RecommendationHistory $history, EffectiveRecommendationSettings $settings): array` — `list<list<int>>` of entry ids, greedy by measured line length.
    - `batchMessages(RecommendationHistory $history, array $candidateLines, EffectiveRecommendationSettings $settings): array` — `list<array{role: string, content: string}>`: one system message (`SYSTEM_ROLE` + blank line + guidance (user's or `DEFAULT_GUIDANCE`) + blank line + `sprintf(OUTPUT_CONTRACT, picksLimit)`), one user message (the four sections).
    - `mergeMessages(array $winners, array $linesById, EffectiveRecommendationSettings $settings): array` — winners is `list<list<array{id:int,reason:string}>>` (per batch); applies the per-batch cap `max(1, intdiv(2 * picksLimit, count($winners)))` before rendering, so the merge input stays bounded; system = `MERGE_ROLE` + guidance + contract, user = `WINNERS:` lines `- [id] title — feed — date — reason`.
    - `correctiveTail(string $invalidReply): array` — `[['role' => 'assistant', 'content' => $invalidReply], ['role' => 'user', 'content' => RecommendationPromptText::CORRECTIVE]]`.

Token estimate: `private function tokens(string $text): int { return intdiv(\strlen($text), self::CHARS_PER_TOKEN) + 1; }` — byte-length over 4, deliberately crude; the packing only needs to be conservative and deterministic.

Packing algorithm (write exactly this):

```php
public function packBatches(array $candidates, RecommendationHistory $history, EffectiveRecommendationSettings $settings): array
{
    $descriptionLength = $this->descriptionLength($settings->contextWindow);
    $historyTokens = $this->tokens($this->historySections($history, $descriptionLength));
    $responseReserve = min($settings->picksLimit * self::TOKENS_PER_PICK, intdiv($settings->contextWindow, 4));
    $budget = $settings->contextWindow - self::FIXED_OVERHEAD_TOKENS - $responseReserve - $historyTokens;

    $batches = [];
    $current = [];
    $used = 0;

    foreach ($candidates as $candidate) {
        $lineTokens = $this->tokens($this->candidateLine($candidate, $descriptionLength));
        if ([] !== $current && $used + $lineTokens > $budget && \count($current) >= self::MINIMUM_BATCH_SIZE) {
            $batches[] = $current;
            $current = [];
            $used = 0;
        }
        $current[] = $candidate->entryId ?? 0;
        $used += $lineTokens;
    }

    if ([] !== $current) {
        $batches[] = $current;
    }

    return $batches;
}
```

(The `>= MINIMUM_BATCH_SIZE` guard means a degenerate context window still yields batches of at least 10 — an overflowing prompt is the provider's problem to refuse, an infinite batch list would be ours.)

Line renderers: candidate `- [%d] %s — %s — %s — %s` (id, title, feedName, date, truncated description; omit the trailing ` — description` when null); history the same without the id bracket. Truncation: `mb_substr($description, 0, $length) . '…'` only when longer than `$length`. Sections render as `FAVORITES (newest first):` etc., `- none` for an empty section, `CANDIDATES:` last.

- [ ] **Step 1: Write the failing test** — cases (write each out with real fixture builders; a helper `line(int $id, string $title, int $descriptionChars)` making `PromptLine`s with `str_repeat('x', $n)` descriptions keeps them short):
  - `testDescriptionLengthScalesAndClamps` — 8192 → 120; 32768 → 239; 200000 → 480.
  - `testEverythingFitsInOneBatchWhenSmall` — 20 candidates, 32k window → exactly 1 batch, all ids in order.
  - `testPackingSplitsWhenTheBudgetOverflows` — window 8192, picksLimit 10, 60 candidates with 400-char descriptions → more than one batch; every candidate id appears exactly once across batches, order preserved.
  - `testTinyWindowStillMakesProgress` — window 4096, picksLimit 100 → batches are non-empty and each holds ≥ 10 ids.
  - `testBatchMessagesLayerFixedGuidanceAndContract` — system content contains `SYSTEM_ROLE`, the user's guidance text (when set) or `DEFAULT_GUIDANCE` (when null), and `Include at most 100 picks`; user content contains `FAVORITES (newest first):`, `- none` for an empty kept section, and `- [7] `.
  - `testMergeMessagesCapPerBatch` — 3 batches of 10 winners each, picksLimit 6 → per-batch cap `max(1, intdiv(12, 3)) = 4` → user content holds 12 winner lines.
  - `testCorrectiveTailEchoesTheInvalidReply`.
- [ ] **Step 2: Run to verify failure.**
- [ ] **Step 3: Implement as specified above.**
- [ ] **Step 4: Run to verify pass** — `php bin/phpunit tests/Service/Recommendation/RecommendationPromptBuilderTest.php`.
- [ ] **Step 5: Lint and commit**

```bash
composer cs:fix && composer check && composer md
git add -A && git commit -m "feat(#308): prompt layers and context-window batch packing"
```

---

### Task 7: Chat completion client

**Files:**
- Create: `backend/src/Service/Recommendation/ChatCompletionClient.php`, `backend/src/Service/Recommendation/OpenAiCompatibleChatClient.php`
- Modify: `backend/config/services.yaml` (interface alias, `$userAgent` binding — mirror the `OpenAiCompatibleCatalog` entries)
- Test: `backend/tests/Service/Recommendation/OpenAiCompatibleChatClientTest.php` (create)

**Interfaces:**
- Consumes: `ProviderCredentials`, existing Ai exceptions.
- Produces:

```php
interface ChatCompletionClient
{
    /**
     * One JSON-mode chat completion; returns the assistant message content.
     *
     * @param list<array{role: string, content: string}> $messages
     *
     * @throws CredentialsRejectedException
     * @throws ProviderUnreachableException
     */
    public function complete(ProviderCredentials $credentials, string $model, array $messages): string;
}
```

- [ ] **Step 1: Write the failing test** (MockHttpClient, mirroring `OpenAiCompatibleCatalogTest`):
  - `testReturnsTheAssistantContent` — respond `{"choices":[{"message":{"content":"{\"recommendations\":[]}"}}]}` → returns that content string; also capture the outgoing request and assert: URL is `…/chat/completions`, method POST, body decodes to `{'model': 'm', 'messages': […], 'response_format': {'type': 'json_object'}}`, headers carry `Authorization: Bearer …`, `Accept-Encoding: identity`.
  - `testRejectedCredentialsBecomeTheTypedException` — 401 → `CredentialsRejectedException`.
  - `testNonJsonEnvelopeIsUnreachable` — body `not json` → `ProviderUnreachableException`.
  - `testEnvelopeWithoutContentIsUnreachable` — `{"choices":[]}` → `ProviderUnreachableException`.
  - `testTransportErrorsAreUnreachable` — `MockResponse` with `error` → `ProviderUnreachableException`.
- [ ] **Step 2: Run to verify failure.**
- [ ] **Step 3: Implement**

`OpenAiCompatibleChatClient` — `final readonly class`, consts `TIMEOUT_SECONDS = 120.0` (a ranking over a large batch can legitimately generate for minutes; this is also why the tick endpoint performs exactly one call — the whole tick must fit one FastCGI request. #312 will add streamed reads with stall detection under this same interface) and `MAXIMUM_RESPONSE_BYTES = 2_097_152`. Constructor `(HttpClientInterface $httpClient, string $userAgent)`. `complete()`:

```php
public function complete(ProviderCredentials $credentials, string $model, array $messages): string
{
    $body = $this->readBody($credentials, $model, $messages);
    $decoded = json_decode($body, true);
    $content = $decoded['choices'][0]['message']['content'] ?? null;

    if (!\is_array($decoded) || !\is_string($content)) {
        throw new ProviderUnreachableException('That provider answered without a completion.');
    }

    return $content;
}
```

`readBody()` and the private `request()` copy the catalog's status handling (401/403 → `CredentialsRejectedException`, ≥300 → `ProviderUnreachableException` with the status, `ExceptionInterface` → `ProviderUnreachableException`), with `'json' => ['model' => $model, 'messages' => $messages, 'response_format' => ['type' => 'json_object']]`, both `timeout` and `max_duration` at `TIMEOUT_SECONDS`, `max_redirects: 0`, the `on_progress` byte cap, and the same header set.

`services.yaml`: alias `App\Service\Recommendation\ChatCompletionClient: '@App\Service\Recommendation\OpenAiCompatibleChatClient'` and bind `$userAgent` the same way the catalog's entry does.

- [ ] **Step 4: Run to verify pass.**
- [ ] **Step 5: Lint and commit**

```bash
composer cs:fix && composer check && composer md
git add -A && git commit -m "feat(#308): JSON-mode chat completion client"
```

---

### Task 8: Pick parser with salvage

**Files:**
- Create: `backend/src/Service/Recommendation/RecommendationPick.php`, `backend/src/Service/Recommendation/PickParseResult.php`, `backend/src/Service/Recommendation/RecommendationPickParser.php`
- Test: `backend/tests/Service/Recommendation/RecommendationPickParserTest.php` (create)

**Interfaces:**
- Produces: `RecommendationPick` readonly `{public int $entryId, public string $reason}`; `PickParseResult` readonly `{public array $picks /* list<RecommendationPick> */, public bool $usable}` with `static usable(array $picks): self` and `static unusable(): self`; `RecommendationPickParser::parse(string $content, array $validIds, int $limit): PickParseResult` — pure, no deps.

Salvage rules (from the issue, verbatim intent): a parseable reply keeps its valid picks even when some ids are invalid or duplicated; **unusable** (→ retry) only when the JSON does not parse, the shape is wrong (`recommendations` missing or not a list), or **zero** picks survive validation. Order is preserved; duplicates keep the first occurrence; picks beyond `$limit` are dropped; a missing/blank/non-string `reason` salvages as `''` (a bad reason is not worth a retry). The model sometimes wraps JSON in code fences — strip a leading/trailing ```` ```json ```` / ```` ``` ```` fence before decoding.

- [ ] **Step 1: Write the failing test** — cases, each a real method:
  - clean reply → usable, order preserved, reasons carried;
  - invalid ids and duplicates dropped, valid remainder kept, still usable;
  - all ids invalid → unusable; empty array → unusable; `{"recommendations": "x"}` → unusable; `not json` → unusable;
  - fenced ```` ```json {...} ``` ```` reply parses;
  - picks beyond `$limit` dropped;
  - id arriving as `"42"` (numeric string) is accepted as 42 — gateways do this.
- [ ] **Step 2: Run to verify failure.**
- [ ] **Step 3: Implement** — decode defensively (`json_decode(..., true)`), normalize each entry (`is_array`, id `is_int` or ctype-digit string, `in_array((int) $id, $validIds, true)`, dedupe via a seen-set), build `RecommendationPick`s.
- [ ] **Step 4: Run to verify pass.**
- [ ] **Step 5: Lint and commit**

```bash
composer cs:fix && composer check && composer md
git add -A && git commit -m "feat(#308): defensive pick parsing with salvage"
```

---

### Task 9: Run starter and the snapshot tick

**Files:**
- Create: `backend/src/Service/Recommendation/RecommendationRunReport.php`, `backend/src/Service/Recommendation/RecommendationRunStarter.php`, `backend/src/Service/Recommendation/RecommendationRunAdvancer.php` (snapshot phase only), `backend/tests/Support/StubChatClient.php`
- Modify: `backend/config/services_test.yaml`
- Test: `backend/tests/Service/Recommendation/RecommendationRunStarterTest.php`, `backend/tests/Service/Recommendation/RecommendationRunAdvancerTest.php` (create both)

**Interfaces:**
- Consumes: everything from Tasks 1–8; `LockFactory`, `ClockInterface`, `AiProviderConfigurator`, `AiSettingsJson::isReady()`.
- Produces:
  - `RecommendationRunReport` readonly `{public string $status /* none|pending|running|completed|failed|busy */, public ?int $batchesTotal, public int $batchesDone, public ?string $error}` with `static none(): self`, `static busy(): self`, `static fromRun(RecommendationRun $run): self`, `toArray(): array` (exactly those four keys).
  - `RecommendationRunStarter::start(User $user): RecommendationRunReport` — throws `AiNotConfiguredException` unless `AiSettingsJson::isReady()`; an active run is returned as-is (idempotent); the latest run being failed → `resume()` it (the issue's "a re-run resumes at the failed batch"); otherwise persist a new pending run.
  - `RecommendationRunAdvancer::advance(User $user): RecommendationRunReport` — **the driver-agnostic tick** (#311 will call this exact method from a worker; keep it free of any request/HTTP types). Lock `'ai-recommendations-' . userId`, TTL `300.0`, non-blocking; busy → `RecommendationRunReport::busy()`. No active run → report of the latest run or `none()`. Pending run → snapshot tick (no provider call): resolve settings, load candidates, pack batches, `$run->snapshot($batches)`; **zero candidates → `complete()` immediately** (an empty feed, not an error). Class carries `@SuppressWarnings("PHPMD.ExcessiveParameterList")` with the RefreshRunner-style justification (pipeline composition root).

- [ ] **Step 1: Create the stub double**

`backend/tests/Support/StubChatClient.php` — implements `ChatCompletionClient`; `queueContent(string $content)`, `queueFailure(\RuntimeException $e)`, records `$this->calls` as `list<array{model: string, messages: list<array{role: string, content: string}>}>` with a public `calls(): array`; throws `\LogicException` when the queue is empty. Register in `services_test.yaml`: the stub as `autowire: true, public: true`, plus `App\Service\Recommendation\ChatCompletionClient: '@App\Tests\Support\StubChatClient'` so the whole container speaks to the stub in tests. Also register `RecommendationRunStarter` and `RecommendationRunAdvancer` as public.

- [ ] **Step 2: Write the failing starter tests** (`DbTestCase`; seed user + ready AI settings via the Task 2 helper — extract that seeding into a small shared private helper per test class, not a base class):
  - not configured → `AiNotConfiguredException`;
  - first start → pending report, run row persisted;
  - second start while active → same run id (assert via `findActiveForUser`), still one row;
  - latest failed → resumed: status running, checkpoints intact, error null.
- [ ] **Step 3: Run to verify failure; implement `RecommendationRunReport` + `RecommendationRunStarter`; run to verify pass.**
- [ ] **Step 4: Write the failing snapshot-tick tests** in `RecommendationRunAdvancerTest`:
  - `testTickWithoutAnyRunReportsNone`;
  - `testSnapshotTickPartitionsCandidatesAndReportsRunning` — seed 5 unread entries, start, one `advance()` → status running, `batchesTotal` 1, `batchesDone` 0, **no** provider call recorded on the stub;
  - `testSnapshotWithZeroCandidatesCompletesEmpty` — no unread entries → completed, `batchesTotal` 0;
  - `testBusyWhenTheLockIsHeld` — acquire `'ai-recommendations-' . $userId` via the container's `LockFactory` first → `advance()` returns busy.
- [ ] **Step 5: Implement the advancer skeleton**

```php
public function advance(User $user): RecommendationRunReport
{
    $lock = $this->lockFactory->createLock(
        'ai-recommendations-' . ($user->getId() ?? 0),
        self::LOCK_TTL_SECONDS,
    );

    if (!$lock->acquire()) {
        return RecommendationRunReport::busy();
    }

    try {
        return $this->tick($user);
    } finally {
        $lock->release();
    }
}

private function tick(User $user): RecommendationRunReport
{
    $run = $this->runs->findActiveForUser($user);

    if (null === $run) {
        $latest = $this->runs->findLatestForUser($user);

        return null === $latest ? RecommendationRunReport::none() : RecommendationRunReport::fromRun($latest);
    }

    $settings = $this->configurator->requireConfiguration($user);
    if (!$settings->hasModel()) {
        throw new AiNotConfiguredException('No model is chosen.');
    }

    if (RecommendationRun::STATUS_PENDING === $run->getStatus()) {
        return $this->snapshotTick($run, $user);
    }

    return $this->providerTick($run, $user, $settings); // Task 10
}
```

`snapshotTick()`: resolver → candidates → `packBatches` → `snapshot()`; when the candidate list is empty, `complete($this->clock->now())` instead; `flush()`; `fromRun()`. For this task, `providerTick()` throws `\LogicException('not yet implemented')`.

- [ ] **Step 6: Run to verify pass** — `php bin/phpunit tests/Service/Recommendation/`.
- [ ] **Step 7: Lint and commit**

```bash
composer cs:fix && composer check && composer md
git add -A && git commit -m "feat(#308): run starter and snapshot tick behind a per-user lock"
```

---

### Task 10: Batch tick, retries, failure

**Files:**
- Modify: `backend/src/Service/Recommendation/RecommendationRunAdvancer.php`
- Test: `backend/tests/Service/Recommendation/RecommendationRunAdvancerTest.php`

**Interfaces:**
- Consumes: Task 9's skeleton; `RecommendationCandidateLoader::linesForIds()`, `RecommendationPromptBuilder`, `RecommendationPickParser`, `ChatCompletionClient`, `AiProviderConfigurator::credentials()`.
- Produces: `providerTick()` handles the batch phase. One provider call per tick. Retry bookkeeping lives on the run entity (Task 3).

Behavior to implement, exactly:

1. `$ids = $run->candidateBatches()[$run->nextBatchIndex()]`; `$linesById = $this->candidates->linesForIds($ids)`; `$validIds = array_keys($linesById)`. Entries pruned since the snapshot simply drop out. If **all** of a batch's entries are gone, record it as an empty winner set (`recordBatchWinners([])`) without a provider call and return — progress, not failure.
2. Load history fresh (`historyLoader->load()`), build `batchMessages(...)` with the batch's lines in snapshot order; when `$run->getLastInvalidReply()` is non-null, append `correctiveTail(...)`.
3. `$content = $this->chat->complete($this->configurator->credentials($settings), $settings->getModel() ?? '', $messages)` — provider exceptions bubble out of `advance()` (the run stays running and untouched; the next tick retries the same batch; the controller maps them in Task 12). The lock's `finally` still releases.
4. `$result = $this->parser->parse($content, $validIds, $effective->picksLimit)`.
5. Usable → `recordBatchWinners(array_map(fn ($p) => ['id' => $p->entryId, 'reason' => $p->reason], $result->picks))`; if the run has one single batch and is now done, finalize (Task 11 — for now, only when `needsMerge()` is false call `$this->finalize($run, $run->winners()[0])`, implemented in Task 11; in this task make `finalize()` a private stub that throws, and only test multi-batch paths).

Actually — keep the tasks honestly independent: in **this** task implement the full non-finalizing path and let the single-batch completion test live in Task 11 where `finalize()` exists. Multi-batch fixtures (two batches) avoid touching finalize here.

6. Unusable → `recordInvalidReply($content)`; when `attemptsExhausted()` → `fail('The model did not return a usable ranking.', $this->clock->now())`. `flush()` always; return `fromRun()`.

- [ ] **Step 1: Write the failing tests** (force two batches: settings row with `contextWindow: 4096` and 60+ seeded unread entries with long summaries, or — simpler and faster — seed ~25 entries and a settings row with `candidatePoolSize: 20`, `contextWindow: 4096`, whose packing yields ≥ 2 batches of ≥ 10; assert `batchesTotal >= 3` after snapshot to pin the fixture):
  - `testBatchTickRecordsWinnersAndAdvances` — queue a clean reply with 2 valid ids → after the tick: `batchesDone` 1, winners recorded, stub saw a system message containing `SYSTEM_ROLE`'s first sentence and a user message containing `- [` + first batch's first id;
  - `testInvalidReplyTriggersCorrectiveRetryNextTick` — queue `not json`, tick; queue clean, tick → second stub call's messages end with an assistant message `not json` + the corrective user message; `batchesDone` 1;
  - `testThreeUnusableRepliesFailTheRun` — queue three garbage replies, three ticks → status failed, error set, completed batches (none here) preserved, `attemptsExhausted()` true;
  - `testResumeAfterFailureRetriesTheFailedBatchNotTheFirst` — run one good batch tick, then three garbage ticks → failed; `starter->start()` → running; queue a good reply, tick → `batchesDone` 2 and the stub's last call contains an id from batch **2**;
  - `testProviderExceptionLeavesTheRunUntouched` — `queueFailure(new ProviderUnreachableException('down'))` → `advance()` throws, run still running, `batchesDone` unchanged, attempts unchanged;
  - `testPrunedBatchSkipsWithoutAProviderCall` — delete every entry of batch 1 (`$em->remove` + flush + `$em->clear()` — remember the bulk-DQL identity-map trap) → tick: `batchesDone` 1, zero new stub calls.
- [ ] **Step 2: Run to verify failure.**
- [ ] **Step 3: Implement `providerTick()` as specified.**
- [ ] **Step 4: Run to verify pass** — whole `tests/Service/Recommendation/` directory.
- [ ] **Step 5: Lint and commit**

```bash
composer cs:fix && composer check && composer md
git add -A && git commit -m "feat(#308): batch ticks with corrective retries and checkpointed failure"
```

---

### Task 11: Merge tick and finalization

**Files:**
- Modify: `backend/src/Service/Recommendation/RecommendationRunAdvancer.php`
- Test: `backend/tests/Service/Recommendation/RecommendationRunAdvancerTest.php`

**Interfaces:**
- Produces: the merge phase (`isMergePhase()` → one provider call over the batch winners via `mergeMessages()`; valid ids = the winner ids that still exist) and `finalize(RecommendationRun $run, array $picks): void` — re-checks existence (`SELECT e.id FROM Entry e WHERE e.id IN (:ids)` through the EntityManager), creates `RecommendationItem`s at positions 1..n with `$this->em->getReference(Entry::class, $id)`, calls `$run->complete($this->clock->now())`. Single-batch runs finalize straight from their one winner list, no merge call (wire the branch left open in Task 10). Merge replies get the same salvage/retry/fail treatment as batch replies (same `recordInvalidReply` path — the entity does not care which phase the attempt belonged to).

- [ ] **Step 1: Write the failing tests:**
  - `testSingleBatchRunFinalizesWithoutAMergeCall` — small fixture (one batch), one clean reply → after 2 ticks (snapshot + batch): completed, items rows exist with positions 1..n and the reply's reasons, exactly **one** stub call;
  - `testMergeTickRanksTheWinners` — two-batch fixture, two clean batch replies, then a merge reply picking across both → completed; item order follows the merge reply; the merge call's user message contains `WINNERS:` and reasons from both batches;
  - `testMergeRespectsThePerBatchCap` — picksLimit 4, two batches with 10 winners each → merge user message holds `max(1, intdiv(8, 2)) = 4` lines per batch;
  - `testUnusableMergeReplyRetriesThenFails` — three garbage merge replies → failed, batch winners intact;
  - `testFinalizeSkipsEntriesPrunedMidRun` — delete one winner entry before the merge tick (+ `clear()`), positions stay dense 1..n-1.
- [ ] **Step 2: Run to verify failure.**
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run to verify pass.**
- [ ] **Step 5: Lint and commit**

```bash
composer cs:fix && composer check && composer md
git add -A && git commit -m "feat(#308): merge tick and run finalization into recommendation items"
```

---

### Task 12: Run API endpoints + rate limiter

**Files:**
- Create: `backend/src/Controller/Api/RecommendationRunController.php`
- Modify: `backend/config/packages/rate_limiter.yaml`
- Test: `backend/tests/Controller/Api/RecommendationRunControllerTest.php` (create)

**Interfaces:**
- Consumes: `RecommendationRunStarter`, `RecommendationRunAdvancer`, `RateLimitGuard`, `RateLimiterFactoryInterface $aiRecommendationsLimiter` (autowired from the new limiter name).
- Produces routes:
  - `POST /api/recommendations/runs` (`api_recommendations_start`) → rate-limit, `starter->start($user)`, 200 with `report->toArray()`;
  - `POST /api/recommendations/runs/tick` (`api_recommendations_tick`) → rate-limit, `advancer->advance($user)`, 200;
  - `GET /api/recommendations/runs/current` (`api_recommendations_current`) → **no limiter, no work**: `RecommendationRunReport::fromRun(latest)` or `none()` — inject `RecommendationRunRepository` and map in the action (two expressions; querying via a repository call in an action is the house pattern, no private helper).
- Exception mapping in each action, mirroring `AiSettingsController`: `AiNotConfiguredException` → `AiNotConfiguredApiException`; `ProviderUnreachableException | CredentialsRejectedException | ModelNotOfferedException` → `AiProviderApiException($e->getMessage(), $e)`; `ApiKeyUnreadableException` → `AiKeyUnreadableApiException($e)`.

Rate limiter (`rate_limiter.yaml`) — copy the house shape:

```yaml
        ai_recommendations:
            policy: 'sliding_window'
            limit: 30
            interval: '15 minutes'
            cache_pool: 'cache.rate_limiter'
```

(30 covers a 32k-window run over the default 1000-candidate pool — single-digit batch calls + merge + retries — with slack for a second run.)

- [ ] **Step 1: Write the failing controller test** (WebTestCase, `auth()` helper copied from `EntryControllerTest`, AI settings seeded ready; the container's `ChatCompletionClient` is already the stub via Task 9's alias):
  - unauthenticated tick → 401;
  - start without AI configured → 404 problem `type: ai_not_configured`;
  - start → 200 `{"status":"pending","batchesTotal":null,"batchesDone":0,"error":null}`;
  - tick sequence on a seeded single-batch fixture: snapshot tick → `running`; queue a clean reply; provider tick → `completed`; `GET current` → `completed`;
  - provider unreachable during a tick (queueFailure) → 422 problem `type: ai_provider_rejected`;
  - 31st POST within the window → 429 with `Retry-After` (loop `POST current`… no — loop 30 `POST /runs`, assert the 31st is 429).
- [ ] **Step 2: Run to verify failure.**
- [ ] **Step 3: Implement the controller** — `final readonly class`, `#[Route('/api/recommendations/runs')]`, constructor `(RecommendationRunStarter $starter, RecommendationRunAdvancer $advancer, RecommendationRunRepository $runs, RateLimitGuard $rateLimitGuard, RateLimiterFactoryInterface $aiRecommendationsLimiter)`. Each action: guard → try/delegate/catch-map → `new JsonResponse($report->toArray())`. No private methods.
- [ ] **Step 4: Run to verify pass** — plus `php bin/phpunit` (full native suite).
- [ ] **Step 5: Lint and commit**

```bash
composer cs:fix && composer check && composer md
git add -A && git commit -m "feat(#308): recommendation run endpoints with their own limiter"
```

---

### Task 13: For-you feed query, `/api/entries?view=for-you`, pruner

**Files:**
- Create: `backend/src/Http/RecommendationCursor.php`, `backend/src/Repository/RecommendationFeedRow.php`, `backend/src/Repository/EffectiveReadState.php`, `backend/src/Service/Recommendation/RecommendationFeedPager.php`, `backend/src/Http/RecommendationFeedJson.php`
- Modify: `backend/src/Repository/RecommendationItemRepository.php`, `backend/src/Repository/EntryRepository.php` (`rowIsRead` delegates to `EffectiveReadState`), `backend/src/Controller/Api/EntryController.php`, `backend/src/Repository/EntryQuery.php` (docblock union only — `applyView` never sees `for-you`), `backend/src/Service/EntryPruner.php`
- Test: `backend/tests/Repository/RecommendationFeedTest.php` (create), `backend/tests/Controller/Api/EntryControllerTest.php` (extend), `backend/tests/Service/EntryPrunerTest.php` (extend — the file exists; if not, create following `DbTestCase`)

**Interfaces:**
- Produces:
  - `RecommendationCursor` readonly `{public int $runId, public int $position}` — `encode(int $runId, int $position): string` / `decode(string $cursor): ?self`, base64url of `"<runId>|<position>"`, modeled on `EntryCursor` (score order can't reuse the `(effectiveDate, id)` cursor).
  - `EffectiveReadState::isRead(?bool $explicitFlag, ?\DateTimeInterface $markedReadUntil, \DateTimeImmutable $effectiveDate): bool` — extracted from `EntryRepository::rowIsRead()`, which now delegates.
  - `RecommendationFeedRow` readonly `{public EntryListRow $row, public string $reason, public int $runId, public int $position}`.
  - `RecommendationItemRepository::listForYou(int $userId, ?RecommendationCursor $cursor, int $limit): array` — `list<RecommendationFeedRow>`; completed runs only; newest run first (`r.id DESC`), position ascending within a run; an entry appearing in several runs shows **only in its newest** run (`NOT EXISTS` a completed item of the same entry in a higher-id run); inner-join `Subscription` (unsubscribed feeds drop out, like the main list); left-join `EntryState` for flags. Same projection fields as `rowQueryBuilder` so `EntryListRow` hydrates identically (duplicate the tiny `customTitle ?? feedTitle ?? feedUrl` fallback with a comment pointing at `EntryRepository::rowTitle`).
  - `RecommendationFeedPager::page(int $userId, ?string $cursor, int $limit): RecommendationFeedPage` — `RecommendationFeedPage` readonly `{public array $rows, public ?string $nextCursor}` (a nested class-less DTO in the same namespace); malformed cursor decodes to null → first page; next cursor emitted only on a full page, from the last row's `(runId, position)`; limit clamped to `EntryQuery::MAX_LIMIT`.
  - `RecommendationFeedJson::page(array $rows, ?string $nextCursor): array` — `['entries' => […], 'nextCursor' => …]` where each entry is `EntryJson::one($row->row) + ['recommendationReason' => $row->reason]`.
  - `EntryController::list()` — add `'for-you'` to the view `match`; when it is `for-you`, return `new JsonResponse(RecommendationFeedJson::page($page->rows, $page->nextCursor))` from the injected pager before the existing `EntryQuery` path.
  - `EntryPruner::prune()` gains a third term `pruneEmptyRuns()`: `DELETE FROM RecommendationRun r WHERE r.status = :completed AND NOT EXISTS (SELECT i.id FROM RecommendationItem i WHERE i.run = r)` (the issue: "EntryPruner also deletes runs that no longer hold items"; pending/running/failed runs are never touched — a snapshot has no items yet).

The cursor DQL: `(r.id < :curRun OR (r.id = :curRun AND i.position > :curPos))`.

- [ ] **Step 1: Write the failing repository test** (`RecommendationFeedTest` extends `DbTestCase`): seed two completed runs for one user (run 1 older: entries A, B; run 2 newer: entries B, C) plus a running run (entry D) and a completed run for another user; assert:
  - list order is run2 first `[B, C]` then run1's `[A]` — B deduped to its newest occurrence, D and the foreign run invisible;
  - reasons and flags carried (`isRead` folds the watermark — seed one watermark case);
  - cursor page 2: `listForYou` with the cursor from row 2 returns `[A]`;
  - unsubscribing the feed hides its items.
- [ ] **Step 2: Run to verify failure; implement repository + cursor + `EffectiveReadState` refactor; run `tests/Repository/` to verify pass (existing `EntryListTest` guards the `rowIsRead` refactor).**
- [ ] **Step 3: Write the failing controller + pruner tests:**
  - `GET /api/entries?view=for-you` → 200, entries carry `recommendationReason`, `nextCursor` null on a short page;
  - `view=for-you&cursor=<page1 tail>` pages correctly;
  - `view=bogus` still 422 (the match's default branch survived);
  - pruner: a completed run whose entries all fall past retention loses its items via FK cascade **and** the run row itself on the next `prune()`; a running run with no items survives. (Remember `$this->em->clear()` after `prune()` before asserting — bulk DQL and the identity map.)
- [ ] **Step 4: Implement controller branch, pager, JSON mapper, pruner term; run to verify pass; run the full native suite.**
- [ ] **Step 5: Lint and commit**

```bash
composer cs:fix && composer check && composer md
git add -A && git commit -m "feat(#308): for-you feed view with run-ranked cursor and run pruning"
```

---

### Task 14: Settings API

**Files:**
- Create: `backend/src/Controller/Api/RecommendationSettingsController.php`, `backend/src/Dto/Recommendation/SaveRecommendationSettingsRequest.php`, `backend/src/Http/RecommendationSettingsJson.php`, `backend/src/Service/Recommendation/RecommendationSettingsWriter.php`
- Test: `backend/tests/Controller/Api/RecommendationSettingsControllerTest.php` (create)

**Interfaces:**
- Consumes: `RecommendationSettingsResolver`, `RecommendationSettingsRepository`, `RecommendationPromptText`.
- Produces:
  - `GET /api/me/ai/recommendations` (`api_me_ai_recommendations_show`) and `PUT /api/me/ai/recommendations` (`api_me_ai_recommendations_save`), JWT-authed like every `/api/me` route.
  - `SaveRecommendationSettingsRequest` readonly DTO via `#[MapRequestPayload]`:

```php
public function __construct(
    #[Assert\Length(max: 4000)]
    public ?string $guidancePrompt,
    #[Assert\Range(min: 0, max: 500)]
    public int $favoritesCap,
    #[Assert\Range(min: 0, max: 500)]
    public int $keptCap,
    #[Assert\Range(min: 0, max: 500)]
    public int $viewedCap,
    #[Assert\Range(min: 10, max: 5000)]
    public int $candidatePoolSize,
    #[Assert\Range(min: 1, max: 500)]
    public int $picksLimit,
    #[Assert\Range(min: 4096, max: 2097152)]
    public ?int $contextWindow,
    public bool $debugEnabled,
) {
}

public function values(): RecommendationSettingsValues { /* 1:1 */ }
```

  - `RecommendationSettingsWriter::save(User $user, RecommendationSettingsValues $values): void` — find-or-create the row, `update()`, flush. A blank/whitespace `guidancePrompt` normalizes to null (= default) here.
  - `RecommendationSettingsJson::state(EffectiveRecommendationSettings $effective): array`:

```php
return [
    'guidancePrompt' => $effective->guidancePrompt,
    'defaultGuidancePrompt' => RecommendationPromptText::DEFAULT_GUIDANCE,
    'fixedPrompt' => [
        'role' => RecommendationPromptText::SYSTEM_ROLE,
        'outputContract' => sprintf(RecommendationPromptText::OUTPUT_CONTRACT, $effective->picksLimit),
    ],
    'favoritesCap' => $effective->favoritesCap,
    'keptCap' => $effective->keptCap,
    'viewedCap' => $effective->viewedCap,
    'candidatePoolSize' => $effective->candidatePoolSize,
    'picksLimit' => $effective->picksLimit,
    'contextWindow' => $effective->contextWindow,
    'contextWindowOverride' => 'user' === $effective->contextWindowSource ? $effective->contextWindow : null,
    'contextWindowSource' => $effective->contextWindowSource,
    'debugEnabled' => $effective->debugEnabled,
];
```

- [ ] **Step 1: Write the failing test:** GET with no row → all defaults, `contextWindowSource: 'fallback'`, fixed prompt strings present; PUT full payload → 200 echoing the new state, row persisted; PUT with `favoritesCap: 9999` → 422 `validation_error` with a `favoritesCap` entry in `errors`; PUT `guidancePrompt: "  "` → GET reports `guidancePrompt: null`; unauthenticated → 401.
- [ ] **Step 2: Run to verify failure; implement (controller actions: delegate + `RecommendationSettingsJson::state($this->resolver->forUser($user))`); run to verify pass.**
- [ ] **Step 3: Lint and commit**

```bash
composer cs:fix && composer check && composer md
git add -A && git commit -m "feat(#308): recommendation settings endpoints"
```

---

### Task 15: Frontend plumbing — view, API, poll service

**Files:**
- Modify: `frontend/src/app/reader/models.ts`, `frontend/src/app/reader/query.ts`, `frontend/src/app/reader/reader-api.ts`
- Create: `frontend/src/app/reader/recommendations.service.ts`
- Test: `frontend/src/app/reader/recommendations.service.spec.ts` (create), `frontend/src/app/reader/query.spec.ts` (extend if present; create the for-you cases regardless)

**Interfaces:**
- Produces:
  - `models.ts`: `EntryView` gains `'for-you'`; `EntryDto` gains `recommendationReason?: string | null`; new:

```ts
export interface RecommendationRunReport {
  status: 'none' | 'pending' | 'running' | 'completed' | 'failed' | 'busy';
  batchesTotal: number | null;
  batchesDone: number;
  error: string | null;
}
```

  - `query.ts`: `Selection.kind` gains `'for-you'`; `selectionFromParams` maps `view === 'for-you'`; `queryFromSelection` returns `{ view: 'for-you' }` (the exhaustive switch forces this at compile time); `markReadTarget` returns `null` for it; `canScopedRefresh` stays false (kind not listed).
  - `reader-api.ts`:

```ts
startRecommendations(): Observable<RecommendationRunReport>   // POST /api/recommendations/runs
tickRecommendations(): Observable<RecommendationRunReport>    // POST /api/recommendations/runs/tick
currentRecommendations(): Observable<RecommendationRunReport> // GET  /api/recommendations/runs/current
```

  - `RecommendationsService` (root-provided, modeled line-for-line on `RefreshService`): signals `running`, `report: signal<RecommendationRunReport | null>`, `failure: signal<RecommendationFailure | null>` with `type RecommendationFailure = { kind: 'busy' } | { kind: 'failed'; error: string | null } | { kind: 'http'; problem: Problem }`, `completedStamp = signal(0)`; `progress = computed(...)` (`batchesDone / batchesTotal`, 0 when total is null/0); `start(): void`; `resume(): void` (GET current; continue the loop only for `pending`/`running`; errors swallowed — boot resume is best-effort); private recursive `step(busyRetries)` with `BUSY_BACKOFF_MS = 1500`, `MAX_BUSY_RETRIES = 5`. On `completed`: `completedStamp.update(n => n + 1)`, toast "ready" with a "view" action that `router.navigate(['/'], { queryParams: { view: 'for-you', tag: null, subscription: null, entry: null } })`. On `failed`: failure signal + failure toast. Toast strings via `TranslocoService.translate(...)` (shared components take translated strings). The service injects `ToastService` (Task 16) — **build this task against the `ToastService` interface and land Task 16 first if executing out of order; otherwise stub it in the spec.** To keep tasks strictly ordered: implement Task 16 (toast) **before** this one if the executor prefers; the plan orders them 15 → 16 with the service taking the toast dependency, so: do the toast **first** within this task's branch if needed. Simplest: Task 15 defines and tests the loop with a `ToastService` fake; Task 16 provides the real one — the import already exists because both live in this branch. Execute 16 before 15 if the import graph bothers the executor; content is unchanged either way.

- [ ] **Step 1: Write the failing service spec** (store-spec style: `provideHttpClient` + testing controller + `API_BASE_URL: 'https://api.test'`; fake `ToastService` as `{ show: jest.fn() }`, `TranslocoService` as `{ translate: (k: string) => k }`, `Router` as `{ navigate: jest.fn() }` via `{ provide: X, useValue: … }`):
  - `start` POSTs runs then ticks until `completed`: flush `{status:'pending',…}` on start, `{status:'running',batchesTotal:3,batchesDone:1,…}` on first tick (expect a second tick request), `{status:'completed',batchesTotal:3,batchesDone:3,…}` → `running()` false, `completedStamp()` 1, toast shown with the ready message key;
  - `failed` tick → failure `{kind:'failed'}`, failure toast, no further requests;
  - `busy` tick → retried after the backoff (`jest.useFakeTimers()`, advance 1500), 5 exhaustions → `{kind:'busy'}`;
  - `resume` with `current` = `running` continues ticking; with `completed` does nothing (no toast — the run finished in an earlier session);
  - HTTP error on tick → `{kind:'http'}`, `running()` false;
  - `progress()` is 1/3 after the first tick above.
- [ ] **Step 2: Run to verify failure** — `cd frontend && npx jest src/app/reader/recommendations.service.spec.ts`.
- [ ] **Step 3: Implement models/query/api/service.** Add the two i18n keys the service translates (`reader.forYouReady`, `reader.forYouView`, `reader.forYouFailed`) to **both** `public/i18n/en.json` and `de.json` now (EN: "Recommendations ready", "View", "Recommendations failed. Try again." / DE: "Empfehlungen sind fertig", "Ansehen", "Empfehlungen fehlgeschlagen. Versuch es noch einmal.").
- [ ] **Step 4: Run to verify pass** — the spec plus `npx jest src/app/reader` (query/models ripples), then `npm run check`.
- [ ] **Step 5: Commit**

```bash
git add -A && git commit -m "feat(#308): for-you view plumbing and the recommendation poll loop"
```

---

### Task 16: Shared toast

**Files:**
- Create: `frontend/src/app/shared/toast/toast.service.ts`, `frontend/src/app/shared/toast/toast.component.ts`, `toast.component.html`, `toast.component.scss`
- Modify: `frontend/src/styles.scss` (pane sizing for `.app-toast`), `docs/design-language.md` (catalog entry: the one toast, when to use it vs `app-error-banner`)
- Test: `frontend/src/app/shared/toast/toast.service.spec.ts` (create)

**Interfaces:**
- Produces:

```ts
export interface ToastData {
  /** Already translated — shared components never receive keys. */
  message: string;
  actionLabel?: string;
  action?: () => void;
  durationMs?: number; // default 6000
}

@Injectable({ providedIn: 'root' })
export class ToastService {
  show(toast: ToastData): void; // replaces any visible toast
  dismiss(): void;
}
```

- CDK `Dialog` + `Overlay.position().global().centerHorizontally().bottom('24px')`, `panelClass: 'app-toast'`, `hasBackdrop: false`, `autoFocus: false`, `restoreFocus: false` (a toast must never steal focus). Auto-dismiss via `setTimeout` held in the service; `show()` clears the previous timer and ref. The component template:

```html
<div class="toast" role="status" aria-live="polite">
  <span class="msg">{{ data.message }}</span>
  @if (data.actionLabel) {
    <button type="button" class="act" (click)="onAction()">{{ data.actionLabel }}</button>
  }
  <button type="button" class="close" (click)="svc.dismiss()" aria-label="✕">✕</button>
</div>
```

(`onAction()` runs `data.action?.()` then `svc.dismiss()`. Use an `aria-label` from `data` if a translated label is supplied later; the ✕ glyph is language-neutral.) SCSS: `display: flex; gap: var(--space-3); align-items: center; padding: var(--space-3) var(--space-4); background: var(--surface-2); border: 1px solid var(--border-strong); border-radius: var(--radius-lg); color: var(--text-primary); font-size: var(--fs-sm);` — action button styled like a text button in `--accent`. `styles.scss`: `.cdk-overlay-pane.app-toast { max-width: min(100% - 2 * var(--space-4), 30rem); }`.

- [ ] **Step 1: Write the failing spec** (TestBed with the component imported; jsdom): `show()` renders the message into the document; the action button appears only with `actionLabel`, clicking it invokes the callback and removes the toast; a second `show()` replaces the first (old message gone); `jest.useFakeTimers()` + advance 6000 → dismissed; container has `role="status"` and `aria-live="polite"`.
- [ ] **Step 2: Run to verify failure.**
- [ ] **Step 3: Implement; wire nothing else yet (the reader service from Task 15 already imports it).**
- [ ] **Step 4: Run to verify pass** — `npx jest src/app/shared/toast` and `npm run check`.
- [ ] **Step 5: Commit**

```bash
git add -A && git commit -m "feat(#308): shared toast over the CDK overlay"
```

---

### Task 17: Sidebar row and shell integration

**Files:**
- Modify: `frontend/src/app/reader/sidebar/sidebar.component.{ts,html,scss}`, `frontend/src/app/reader/reader-shell.component.{ts,html}`, `frontend/public/i18n/en.json`, `frontend/public/i18n/de.json`
- Test: `frontend/src/app/reader/sidebar/sidebar.component.spec.ts`, `frontend/src/app/reader/reader-shell.component.spec.ts`

**Interfaces:**
- Consumes: `AiAvailabilityService.ready` (built for exactly this — do not read `auth.user()?.ai`), `RecommendationsService` (Task 15).
- Produces:
  - Sidebar: inject both services (`readonly ai = inject(AiAvailabilityService); readonly recs = inject(RecommendationsService);`); after the Kept row:

```html
@if (ai.ready()) {
  <a
    class="nav for-you"
    [class.active]="selection().kind === 'for-you'"
    [routerLink]="[]"
    [queryParams]="{ view: 'for-you', tag: null, subscription: null, entry: null }"
    queryParamsHandling="merge"
  >
    <app-icon name="auto_awesome" size="sm" [class.pulse]="recs.running()" />
    <span>{{ 'reader.forYou' | transloco }}</span>
  </a>
}
```

SCSS: `.nav app-icon.pulse { animation: for-you-pulse 1.4s ease-in-out infinite; } @keyframes for-you-pulse { 0%, 100% { opacity: 1; } 50% { opacity: 0.35; } }`.
  - Shell: `readonly recs = inject(RecommendationsService);` — `ngOnInit` adds `this.recs.resume();` (the issue: reopening the app resumes the run); the `title()` computed gains a `for-you` case → `'reader.forYou'`; an effect reloads the list when a run completes while the user is on the feed:

```ts
effect(() => {
  if (this.recs.completedStamp() === 0) return; // initial signal value, not a completion
  untracked(() => {
    if (this.selection().kind === 'for-you') this.entries.load({ view: 'for-you' });
  });
});
```

  - i18n: `reader.forYou` — EN "For you" / DE "Für dich".
- [ ] **Step 1: Update the shell spec's `boot()` helper first** — `resume()` fires `GET https://api.test/api/recommendations/runs/current` on init; drain it with `{ status: 'none', batchesTotal: null, batchesDone: 0, error: null }` or every existing shell test fails on an unexpected request. Run `npx jest src/app/reader/reader-shell.component.spec.ts` → green again before adding behavior.
- [ ] **Step 2: Write the failing specs:** sidebar — row absent with `AiAvailabilityService` faked `{ ready: signal(false) }` wait — `ready` is a readonly signal; fake as `{ ready: signal(true).asReadonly() }` or simply `{ ready: signal(true) }` (structural typing); row present when ready; `.pulse` class present when `recs.running()` is true (fake `{ running: signal(true), … }`). Shell — query param `view=for-you` issues `GET /api/entries?view=for-you`; title case.
- [ ] **Step 3: Run to verify failure; implement; run to verify pass.**
- [ ] **Step 4: `npm run check`.**
- [ ] **Step 5: Commit**

```bash
git add -A && git commit -m "feat(#308): For-you sidebar row gated on AI readiness"
```

---

### Task 18: For-you feed controls and the reason line

**Files:**
- Modify: `frontend/src/app/reader/reader-shell.component.{ts,html,scss}`, `frontend/src/app/reader/entry-row/entry-row.component.{html,scss}` (+ `.ts` only if the template needs a helper — it shouldn't), `frontend/src/app/reader/models.ts` (done in 15), `frontend/public/i18n/en.json`, `de.json`
- Test: `frontend/src/app/reader/reader-shell.component.spec.ts`, `frontend/src/app/reader/entry-row/entry-row.component.spec.ts`

**Interfaces:**
- Produces:
  - In the shell's `.under-header` block (after the fetch banner):

```html
@if (selection().kind === 'for-you') {
  <div class="for-you-bar">
    @if (recs.running()) {
      <p role="status" aria-live="polite">
        {{ 'reader.forYouProgress' | transloco: forYouProgress() }}
      </p>
    } @else {
      <button type="button" class="run" (click)="recs.start()">
        {{ 'reader.forYouRun' | transloco }}
      </button>
    }
  </div>
  <app-progress-hairline [active]="recs.running()" [value]="recs.progress()" />
}
```

with `readonly forYouProgress = computed(() => ({ done: this.recs.report()?.batchesDone ?? 0, total: this.recs.report()?.batchesTotal ?? 0 }));` in the shell. `.for-you-bar` SCSS mirrors `.fetch-banner`'s spacing (`padding: var(--space-2) var(--space-4); font-size: var(--fs-sm); color: var(--text-secondary);`), the button styled like the banner's inline retry button.
  - Entry row: under the existing summary/kicker block:

```html
@if (entry().recommendationReason) {
  <p class="reason">{{ entry().recommendationReason }}</p>
}
```

SCSS: `.reason { margin: var(--space-1) 0 0; color: var(--text-muted); font-size: var(--fs-sm); font-style: italic; }`. List rows only — the magazine variants don't render it (their density budget is the recorded reason; note it in the commit message).
  - i18n: `reader.forYouRun` EN "Get recommendations" / DE "Empfehlungen holen"; `reader.forYouProgress` EN "Ranking your feeds — {{done}} of {{total}}" / DE "Deine Feeds werden sortiert — {{done}} von {{total}}".
- [ ] **Step 1: Write the failing specs:** shell — with `view=for-you` and a non-running fake, the run button renders and click calls `recs.start()`; with a running fake, the progress text renders "1 of 3" (report faked accordingly) and no button. Entry row — `recommendationReason: 'because'` renders `.reason`; absent/null renders none.
- [ ] **Step 2: Run to verify failure; implement; run to verify pass.**
- [ ] **Step 3: `npm run check`.**
- [ ] **Step 4: Commit**

```bash
git add -A && git commit -m "feat(#308): run controls with determinate progress and pick reasons"
```

---

### Task 19: Settings card

**Files:**
- Create: `frontend/src/app/settings/recommendation-settings.service.ts`, `frontend/src/app/settings/recommendation-settings-card.component.{ts,html,scss}`
- Modify: `frontend/src/app/settings/ai-section.component.html` (+ `.ts` imports), `frontend/public/i18n/en.json`, `de.json`
- Test: `frontend/src/app/settings/recommendation-settings-card.component.spec.ts` (create)

**Interfaces:**
- Consumes: the Task 14 endpoints; `AiSettingsService.state` (the card renders only when `ai.state().ready` — the block sits in `ai-section.component.html` behind `@if (ai.state().ready)`).
- Produces:
  - `RecommendationSettingsService` — component-provided (`@Injectable()`, listed in the card's `providers`), mirroring `AiSettingsService`: `state = signal<RecommendationSettingsState | null>(null)` (interface mirrors the GET payload 1:1), `busy = signal(false)`, `failure = signal<Problem | null>(null)`, `saved = signal(false)`; `load(): void` (GET), `save(body: SaveRecommendationSettings): void` (PUT; on success `state.set`, `saved.set(true)`); one private `run<T>()` like the AI service.
  - Card component — signals + `linkedSignal` from `state` for each field (`guidance`, `favoritesCap`, `keptCap`, `viewedCap`, `candidatePoolSize`, `picksLimit`, `contextWindow` (the override, may be blank → null), `debugEnabled`); numeric `<input type="number">` inside `<app-field>`; guidance `<textarea [value]="guidance()" (input)="…">` with placeholder = `defaultGuidancePrompt` and a reset button (`guidance.set('')` — blank saves as null/default); the fixed layers rendered read-only inside a `<details>` as `<pre class="fixed">{{ state.fixedPrompt.role }}\n\n{{ state.fixedPrompt.outputContract }}</pre>`; `contextWindowSource` hint line ("from your provider" / "default" / "your override" via keys); `<app-toggle>` for debug; one primary save `<app-button [loading]="svc.busy()">`.
  - i18n block `settings.ai.recommendations.*`: `title` ("Recommendations" / "Empfehlungen"), `guidance` ("Guidance prompt" / "Leitprompt"), `guidanceReset` ("Reset to default" / "Auf Standard zurücksetzen"), `fixedShow` ("Show the fixed prompt" / "Festen Prompt anzeigen"), `favoritesCap` ("Favorites in history" / "Favoriten in der Historie"), `keptCap` ("Kept in history" / "Aufbewahrte in der Historie"), `viewedCap` ("Viewed in history" / "Angesehene in der Historie"), `candidatePool` ("Candidate pool size" / "Größe des Kandidatenpools"), `picksLimit` ("Maximum picks" / "Maximale Auswahl"), `contextWindow` ("Context window (tokens)" / "Kontextfenster (Token)"), `contextWindowFromProvider` ("Reported by your provider" / "Vom Provider gemeldet"), `contextWindowFallback` ("Built-in default" / "Eingebauter Standard"), `contextWindowOverride` ("Your override" / "Deine Einstellung"), `debug` ("Keep debug data for runs" / "Debugdaten für Läufe aufheben"), `save` ("Save" / "Speichern"), `saved` ("Saved." / "Gespeichert.").
- [ ] **Step 1: Write the failing spec** (ai-section spec pattern: `API_BASE_URL: ''`, mount drains `GET /api/me/ai/recommendations`): renders defaults into the fields; editing picks + save PUTs the full body (assert the JSON body precisely, `contextWindow: null` when the field is blank); guidance reset then save sends `guidancePrompt: null`; a 422 shows the error banner.
- [ ] **Step 2: Run to verify failure; implement; run to verify pass.**
- [ ] **Step 3: Wire into `ai-section.component.html`** behind `@if (ai.state().ready)`, import the card in `ai-section.component.ts`; extend `ai-section.component.spec.ts` with one case: card present when the mounted state is ready, absent otherwise.
- [ ] **Step 4: `npm run check`.**
- [ ] **Step 5: Commit**

```bash
git add -A && git commit -m "feat(#308): recommendation settings card"
```

---

### Task 20: Full verification sweep

**Files:** none new.

- [ ] **Step 1: Backend gates**

```bash
cd backend && composer cs && composer stan && composer md && php bin/phpunit
```
Expected: all green.

- [ ] **Step 2: MySQL leg**

```bash
docker compose up -d && docker compose exec php vendor/bin/phpunit && docker compose exec php bin/console doctrine:migrations:migrate --no-interaction && docker compose exec php bin/console doctrine:schema:validate
```
Expected: suite green (the rate-limiter flake documented in memory is order-dependent and not ours — rerun the failing file in isolation to confirm before digging); migrate + validate clean.

- [ ] **Step 3: Mutation gate**

```bash
cd backend && composer infection:diff
```
Expected: MSI at or above `minMsi` in `infection.json5`. Kill escaped mutants by tightening the named test, not by lowering the gate.

- [ ] **Step 4: Frontend gate**

```bash
cd frontend && npm run check
```

- [ ] **Step 5: Scan the dev log** — `tail -200 backend/var/log/dev.log` after clicking through a manual run against a real or stubbed provider in the Docker stack; deprecations and swallowed errors surface there.

- [ ] **Step 6: Commit anything the sweep shook loose**

```bash
git add -A && git commit -m "test(#308): verification sweep fixes"
```

(No new Playwright e2e: a run needs a live provider, and a spec must own its data — stubbing an entire multi-tick provider conversation through route interception is brittle; the backend functional tests own this behavior. Revisit under #311/#312 if a worker-driven flow needs a smoke.)

---

## Self-Review Notes (already applied)

- **Spec coverage:** history sections/caps/dedup (T5), candidates unread/newest/pool (T5), line format + HTML-stripped description (T5/T6), sizing from context window + `/models` prefill (T1/T2/T6), multi-batch + merge (T6/T10/T11), response contract + salvage + 2 retries + corrective message + resume-at-failed-batch (T3/T8/T10/T12), no worker/no queue + poll-driven + lock + rate limit (T9/T12), driver-agnostic tick (T9, #308 comment), persistence entities + cascade (T3/T4), feed view + newest-run dedup + infinite scroll (T13/T15), pruner (T13), sidebar row gated on `ai.ready` + `auto_awesome` (T17), settings entity/API/UI incl. guidance reset, read-only fixed layers, debug switch stored-only (T2/T14/T19), pulsing icon + determinate progress (T17/T18), toast component with aria-live/auto-dismiss/action (T16), run pauses when the tab closes and resumes on reopen (T15 `resume()`, T17 `ngOnInit`).
- **Out of scope honored:** no scheduler, no token streaming to the browser, no debug viewer (the switch persists only).
- **Type consistency:** `RecommendationRunReport` shape identical in PHP `toArray()` (T9) and TS (T15); winners stored as `list<list<array{id,reason}>>` everywhere; `PromptLine` is the one line type across loaders and builder; the view literal is `for-you` in backend match, cursor-free `EntryQuery` docblock, TS `EntryView`, and the sidebar query params.
