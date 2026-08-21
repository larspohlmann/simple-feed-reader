# Two-Step Recommendations (Profile + Consolidation) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Restructure the recommendation pipeline into four LLM phases — distill a preference profile, score candidates in cheap score-only batches, consolidate the top-100 in one re-score + reason + dedup call, finalize — cutting tokens per run while keeping (or improving) result quality.

**Architecture:** Insert a **distillation** phase after snapshot that writes a per-run preference `profileText` (also cached on the user's settings for display). Batches become a **coarse filter**: they carry `profile + FAVORITES` only, reply `{id, score}` only, and their sole job is to surface the top-100. A new **consolidation** phase replaces dedup: one call over the top-`2×picksLimit` carries the **same `profile + FAVORITES`** as the batches, re-scores for final sort order, writes the reader-facing `reason` text, and flags duplicates in one reply. Only the distillation call sees the full three-section history — every later call speaks through the profile. The finalizer is unchanged. Every phase degrades rather than fails.

**Tech Stack:** Symfony 7.4 (PHP 8.4), Doctrine ORM, MySQL (prod/Docker) + SQLite (native tests), PHPUnit, Infection, Angular 20 (standalone + signals), Jest.

**Spec:** GitHub issue [#493](https://github.com/larspohlmann/simple-feed-reader/issues/493) and its two comments (the addition comment: score-only batches + post-dedup reasons; the top-100 comment: one final consolidation call). This plan is the reconciled design agreed in the grilling session that preceded it.

## Global Constraints

- **`declare(strict_types=1);`** in every PHP file. PSR-12 (`composer cs`). PHPStan level max over `src` and `tests` — no new baseline, no bare `@phpstan-ignore`.
- **Clean Code is mandatory** (CLAUDE.md): intention-revealing names, one-thing functions, guard clauses, no boolean-flag parameters, `final readonly class` with constructor promotion as the house style, depend on injected interfaces, typed namespaced exceptions, DRY.
- **PHPMD codesize clean on every `src` file touched** — not merely free of new findings. Fix the design the metric points at; never tune the threshold.
- **phptramp:** no parameter forwarded unread through a chain of 4+ methods across 2+ classes. Prefer a context object over a longer signature.
- **Thin controllers** (`ThinControllerRule`): the settings controller stays a read-delegate-respond action; all work in services / `src/Http/*Json.php` mappers.
- **Datetimes are naive UTC** — not relevant to this change (no new date persistence), but do not introduce feed-offset dates.
- **Native iOS boundary:** JSON in / `application/problem+json` out, Bearer auth, no browser-only inputs. The one client-facing surface here (the settings GET gains a read-only `profileText` string) already satisfies this.
- **LM Studio provider handling** for every new phase call: strict `json_schema` response format (never `json_object`, #329) and answer recovery from `reasoning_content` (#323). Both are already handled centrally by `OpenAiCompatibleChatClient::completionPayload()` and `contentOf()` — a new phase inherits them by adding a `RecommendationResponseSchema` case and calling `RecommendationCompletionRequestFactory::create()`, exactly as the batch and dedup phases do.
- **Tests are production code** — same naming, structure, standards. Prefer functional tests over direct-invocation for wiring (CLAUDE.md).
- **Mutation gate:** `composer infection:diff` must stay green at the configured `minMsi`. Escaped mutants arrive as PR annotations.
- **Parallel test isolation:** any parallel run sets `TEST_TOKEN` (already wired via `tests/Support/WorkerIsolation.php`).

**Branch:** `feature/493-two-step-recommendation-profile` off `develop`. One PR into `develop`, body `Closes #493`.

**Degrade contract (applies across phases — copy verbatim into each phase's reasoning):**
- Distillation fails after `MAX_ATTEMPTS`, or history too thin → the run's `profileText` stays `null`; batches send FAVORITES only, no profile block. Never fail the run.
- Consolidation fails after `MAX_ATTEMPTS` → finalize the top-`picksLimit` in batch-score order, no dedup, empty-string `reason`. Never fail the run.

---

## File Structure

**New files (backend):**
- `backend/src/Service/Recommendation/RecommendationProfileParser.php` — parses `{profile:string}`.
- `backend/src/Service/Recommendation/ProfileParseResult.php` — `{profile:?string, usable:bool}`.
- `backend/src/Service/Recommendation/RecommendationProfileDistiller.php` — the distillation phase resolver.
- `backend/src/Service/Recommendation/ProfileDistillationOutcome.php` — usable(profileText) / unusable(reply).
- `backend/src/Service/Recommendation/RecommendationConsolidationParser.php` — parses `{recommendations:[{id,score,reason}], duplicates:[int]}`.
- `backend/src/Service/Recommendation/ConsolidationParseResult.php` — `{picks:list<RecommendationPick>, duplicateIds:list<int>, usable:bool}`.
- `backend/src/Service/Recommendation/RecommendationConsolidationResolver.php` — the consolidation phase resolver (replaces `RecommendationDedupResolver`).
- `backend/src/Service/Recommendation/ConsolidationOutcome.php` — usable(ranked) / unusable(reply, pool) (replaces `DedupOutcome`).
- `backend/migrations/Version20260821130000.php` — `user_recommendation_settings.profile_text`.
- `backend/migrations/Version20260821140000.php` — `recommendation_run.profile_text` + `recommendation_run.distilled`.

**Modified files (backend):**
- `RecommendationResponseSchema.php` — replace `Ranking`/`Duplicates` cases with `Distillation`/`BatchScore`/`Consolidation`.
- `RecommendationPromptText.php` — add batch score-only, distillation, consolidation strings; retire dedup + reason-bearing batch strings.
- `RecommendationPromptBuilder.php` — score-only token constants + packing; `batchMessages` gains a profile block and drops KEPT/VIEWED; new `distillMessages` and `consolidationMessages`; `answerBoundTokens` match arms.
- `RecommendationPickParser.php` — tolerate a missing `reason` (default `''`) so it serves score-only batches.
- `RecommendationBatchWave.php` — pass `$run->getProfileText()`, use `BatchScore` schema.
- `RecommendationWinnerRanker.php` — unchanged shape; verify `cutForDedup` naming reads for consolidation (rename to `topForConsolidation` optional, kept as-is to limit churn).
- `RecommendationRunAdvancer.php` — new dispatch (`distillTick`, `consolidateTick`), `pickEndingAfterWave` always hands to consolidation.
- `Entity/RecommendationRun.php` — `profileText`, `distilled`, `recordProfile()`, `getProfileText()`, `isDistilled()`.
- `Entity/RecommendationRunProgress.php` — `isConsolidationPhase` (unconditional), `batchesTotal += 2`, `distilled` input.
- `Entity/RecommendationRunLog.php` — `PHASE_DISTILL`, `PHASE_CONSOLIDATE` constants.
- `Entity/RecommendationSettings.php`, `RecommendationSettingsValues.php`, `EffectiveRecommendationSettings.php`, `RecommendationSettingsResolver.php`, `RecommendationSettingsWriter.php` — carry `profileText`; writer gains `storeProfile()`.
- `Http/RecommendationSettingsJson.php` — emit `profileText`.

**Deleted files (backend, in the cleanup task):**
- `RecommendationDedupResolver.php`, `DedupOutcome.php`, `RecommendationDuplicateParser.php`, `DuplicateParseResult.php` (folded into consolidation).

**Modified files (frontend):**
- `frontend/src/app/settings/recommendation-settings.service.ts` — `RecommendationSettingsState.profileText`.
- `frontend/src/app/settings/recommendation-settings-card.component.ts` / `.html` / `.scss` — read-only profile row inside the Expert-settings disclosure.
- `frontend/src/assets/i18n/*` (or the Transloco source) — the profile label key.

---

## Task 1: Persist `profileText` on user settings (data layer only)

Adds a nullable profile field to the per-user settings, resolved and emitted read-only. No pipeline change yet — the field is written later by the distiller. `guidancePrompt` (nullable TEXT) is the exact template through all layers.

**Files:**
- Create: `backend/migrations/Version20260821130000.php`
- Modify: `backend/src/Entity/RecommendationSettings.php` (column + `update()` + `values()`), `backend/src/Service/Recommendation/RecommendationSettingsValues.php`, `backend/src/Service/Recommendation/EffectiveRecommendationSettings.php`, `backend/src/Service/Recommendation/RecommendationSettingsResolver.php:24-55`, `backend/src/Service/Recommendation/RecommendationSettingsWriter.php:40-61`, `backend/src/Http/RecommendationSettingsJson.php:26-51`
- Test: `backend/tests/Service/Recommendation/RecommendationSettingsResolverTest.php` (existing — extend), `backend/tests/Http/RecommendationSettingsJsonTest.php` (existing — extend)

**Interfaces:**
- Produces: `EffectiveRecommendationSettings::$profileText` (`public ?string`), `RecommendationSettingsValues::$profileText` (`public ?string`), `RecommendationSettings::values()` carrying it, `RecommendationSettingsJson::state()` emitting `'profileText' => $effective->profileText`, and a new `RecommendationSettingsWriter::storeProfile(User $user, ?string $profileText): void`.
- Consumes: nothing from earlier tasks.

- [ ] **Step 1: Write the failing resolver test**

Add to `RecommendationSettingsResolverTest`:

```php
public function testProfileTextDefaultsToNullWhenNoRowExists(): void
{
    $settings = $this->resolver->forUser($this->userWithoutSettingsRow());

    self::assertNull($settings->profileText);
}

public function testProfileTextIsReadFromTheSettingsRow(): void
{
    $row = $this->settingsRowFor($this->user, profileText: 'Likes self-hosted home automation.');

    $settings = $this->resolver->forUser($this->user);

    self::assertSame('Likes self-hosted home automation.', $settings->profileText);
}
```

(Mirror the existing `guidancePrompt` test helpers in this file; if `settingsRowFor` does not take named params, set `profileText` via the entity's `update()` with a `RecommendationSettingsValues` carrying it.)

- [ ] **Step 2: Run the test to verify it fails**

Run: `cd backend && php bin/phpunit --filter RecommendationSettingsResolverTest`
Expected: FAIL — `profileText` is not a known property / named arg.

- [ ] **Step 3: Thread `profileText` through the value + effective + entity layers**

`RecommendationSettingsValues.php` — add promoted prop beside `guidancePrompt`:

```php
public ?string $profileText,
```

`EffectiveRecommendationSettings.php` — add prop beside `guidancePrompt` (nullable, no `DEFAULT_*` constant — it has no default, like `guidancePrompt`):

```php
public ?string $profileText,
```

`RecommendationSettings.php` — add column beside `guidancePrompt` (line 31-32):

```php
#[ORM\Column(type: Types::TEXT, nullable: true)]
private ?string $profileText = null;
```

In `update(RecommendationSettingsValues $values)` add `$this->profileText = $values->profileText;`. In `values()` pass `profileText: $this->profileText` (or positionally, matching the ctor order you chose).

`RecommendationSettingsResolver::forUser()` — read it into the constructed `EffectiveRecommendationSettings`:

```php
profileText: $row?->values()->profileText,
```

`RecommendationSettingsWriter::withNormalisedGuidance()` — this method rebuilds `RecommendationSettingsValues` field-for-field; add `profileText: $values->profileText,` so a save does not wipe it.

- [ ] **Step 4: Run the test to verify it passes**

Run: `cd backend && php bin/phpunit --filter RecommendationSettingsResolverTest`
Expected: PASS.

- [ ] **Step 5: Write the failing JSON-emission test**

Add to `RecommendationSettingsJsonTest`:

```php
public function testStateEmitsProfileText(): void
{
    $effective = $this->effectiveSettings(profileText: 'Likes Rust and homelab posts.');

    $state = RecommendationSettingsJson::state($effective, workerAlive: true);

    self::assertSame('Likes Rust and homelab posts.', $state['profileText']);
}

public function testStateEmitsNullProfileTextWhenAbsent(): void
{
    $state = RecommendationSettingsJson::state($this->effectiveSettings(), workerAlive: true);

    self::assertNull($state['profileText']);
}
```

- [ ] **Step 6: Run it to verify it fails**

Run: `cd backend && php bin/phpunit --filter RecommendationSettingsJsonTest`
Expected: FAIL — undefined array key `profileText`.

- [ ] **Step 7: Emit `profileText` from the read DTO**

`RecommendationSettingsJson::state()` — add beside the `'guidancePrompt'` line (line 29):

```php
'profileText' => $effective->profileText,
```

- [ ] **Step 8: Run it to verify it passes**

Run: `cd backend && php bin/phpunit --filter RecommendationSettingsJsonTest`
Expected: PASS.

- [ ] **Step 9: Add `storeProfile()` to the writer with a failing test**

Add to `RecommendationSettingsWriterTest` (or create it if absent, mirroring existing writer test setup):

```php
public function testStoreProfilePersistsOnlyTheProfileText(): void
{
    $this->writer->storeProfile($this->user, 'Likes long-form essays on typography.');

    $reloaded = $this->settingsRepository->findForUser($this->user);
    self::assertSame('Likes long-form essays on typography.', $reloaded->values()->profileText);
}

public function testStoreProfileCreatesARowWhenNoneExists(): void
{
    $this->writer->storeProfile($this->userWithoutSettingsRow(), 'Likes maps and cartography.');

    self::assertNotNull($this->settingsRepository->findForUser($this->userWithoutSettingsRow()));
}
```

Implement `storeProfile()` on `RecommendationSettingsWriter`: load-or-create the row via the repository, then update only the profile field (build a `RecommendationSettingsValues` from the existing `values()` with `profileText` replaced, and call `update()`), then flush. Do not touch other fields.

- [ ] **Step 10: Run the writer test to verify it passes**

Run: `cd backend && php bin/phpunit --filter RecommendationSettingsWriterTest`
Expected: PASS.

- [ ] **Step 11: Write the migration**

Create `backend/migrations/Version20260821130000.php`, copying the platform-aware, idempotent shape of `Version20260821120000.php` (private `mysql(): bool` helper, `hasColumn` guards):

```php
public function getDescription(): string
{
    return 'Add profile_text to user_recommendation_settings (#493 preference profile).';
}

public function up(Schema $schema): void
{
    $table = $schema->getTable('user_recommendation_settings');
    if ($table->hasColumn('profile_text')) {
        return;
    }

    $this->addSql($this->mysql()
        ? 'ALTER TABLE user_recommendation_settings ADD profile_text LONGTEXT DEFAULT NULL'
        : 'ALTER TABLE user_recommendation_settings ADD COLUMN profile_text CLOB DEFAULT NULL');
}

public function down(Schema $schema): void
{
    $table = $schema->getTable('user_recommendation_settings');
    if (!$table->hasColumn('profile_text')) {
        return;
    }
    $this->addSql('ALTER TABLE user_recommendation_settings DROP COLUMN profile_text');
}
```

(Match the exact SQLite type token the existing TEXT columns use in this repo's migrations — check `Version20260821120000.php` and prior migrations for whether they emit `CLOB` or `TEXT`; use whatever the house style is. `guidancePrompt` is `Types::TEXT`, so mirror its migration.)

- [ ] **Step 12: Verify the migration applies on both dialects and validates**

Run:

```bash
cd backend && php bin/console doctrine:migrations:migrate --no-interaction && php bin/console doctrine:schema:validate
```

Expected: migration runs; schema validates (mapping ↔ database in sync). Then confirm the MySQL leg in Docker:

```bash
docker compose exec php bin/console doctrine:migrations:migrate --no-interaction && docker compose exec php bin/console doctrine:schema:validate
```

- [ ] **Step 13: Run gates and commit**

Run: `cd backend && composer cs && composer stan && php bin/phpunit --filter Recommendation`
Expected: PASS.

```bash
git add backend/src backend/migrations backend/tests
git commit -m "feat(#493): persist a read-only preference profileText on user settings"
```

---

## Task 2: Read-only profile display in Expert settings (frontend)

Shows the persisted profile inside the existing Expert-settings disclosure. Read-only — editability is a deferred follow-up, so no write path.

**Files:**
- Modify: `frontend/src/app/settings/recommendation-settings.service.ts:13-39` (`RecommendationSettingsState`), `frontend/src/app/settings/recommendation-settings-card.component.html:35-52` (inside `app-disclosure` / `expert-grid`), `frontend/src/app/settings/recommendation-settings-card.component.scss` (if a row style is needed), the Transloco i18n source for the label key.
- Test: `frontend/src/app/settings/recommendation-settings-card.component.spec.ts`

**Interfaces:**
- Consumes: the GET payload `profileText: string | null` from Task 1.
- Produces: a read-only display element with a stable test hook (`data-testid="recommendation-profile"`).

- [ ] **Step 1: Write the failing Jest test**

Add to `recommendation-settings-card.component.spec.ts`:

```ts
it('shows the persisted preference profile read-only when present', () => {
  setState({ ...baseState(), profileText: 'Likes self-hosted tooling and Rust.' });
  fixture.detectChanges();

  const el = fixture.nativeElement.querySelector('[data-testid="recommendation-profile"]');
  expect(el?.textContent).toContain('Likes self-hosted tooling and Rust.');
  // read-only: no input/textarea bound to it
  expect(el?.querySelector('textarea')).toBeNull();
});

it('hides the profile block when no profile has been generated yet', () => {
  setState({ ...baseState(), profileText: null });
  fixture.detectChanges();

  expect(fixture.nativeElement.querySelector('[data-testid="recommendation-profile"]')).toBeNull();
});
```

(Use the file's existing `setState` / `baseState` helpers; if `baseState` is a literal, add `profileText: null` to it.)

- [ ] **Step 2: Run it to verify it fails**

Run: `cd frontend && npx jest recommendation-settings-card`
Expected: FAIL — element not found / `profileText` not on the state type.

- [ ] **Step 3: Add `profileText` to the state interface**

`recommendation-settings.service.ts` — add to `RecommendationSettingsState` (the "Mirrors the GET payload 1:1" interface):

```ts
profileText: string | null;
```

Do **not** add it to `SaveRecommendationSettings` — it is read-only.

- [ ] **Step 4: Render the read-only block in the Expert disclosure**

Inside the `app-disclosure` (`recommendation-settings-card.component.html:35`), within `expert-grid`, add a conditional read-only row. Use Transloco for the label and no hex/px literals (theme tokens only):

```html
@if (svc.state()?.profileText; as profile) {
  <div class="expert-row" data-testid="recommendation-profile">
    <span class="expert-label">{{ 'settings.ai.recommendations.profileLabel' | transloco }}</span>
    <p class="expert-profile-text">{{ profile }}</p>
  </div>
}
```

Add the `settings.ai.recommendations.profileLabel` key to the i18n source (e.g. `"Current preference profile"`), following how `expertTitle` is defined. If `.expert-row` / `.expert-profile-text` need styling, add them to the sibling `.scss` using existing spacing tokens.

- [ ] **Step 5: Run the test to verify it passes**

Run: `cd frontend && npx jest recommendation-settings-card`
Expected: PASS.

- [ ] **Step 6: Run the CI gate and commit**

Run: `cd frontend && npm run check`
Expected: PASS (ESLint + Prettier + Stylelint + Jest).

```bash
git add frontend/src
git commit -m "feat(#493): show the persisted preference profile read-only in expert settings"
```

---

## Task 3: Response-schema cases for the three new phases

Replaces the two current schema cases with the three the new pipeline speaks. Keeping the old cases would leave dead match arms; this task swaps them and updates the one match site (`answerBoundTokens`) plus the request factory reference in the same commit so the tree compiles.

**Files:**
- Modify: `backend/src/Service/Recommendation/RecommendationResponseSchema.php`
- Modify: `backend/src/Service/Recommendation/RecommendationPromptBuilder.php:379-389` (`answerBoundTokens` match)
- Test: `backend/tests/Service/Recommendation/RecommendationResponseSchemaTest.php` (create if absent)

**Interfaces:**
- Produces: `RecommendationResponseSchema::Distillation`, `::BatchScore`, `::Consolidation`; `toJsonSchema()` returning the wire schemas below.
- Consumes: nothing.

- [ ] **Step 1: Write the failing schema test**

```php
public function testBatchScoreSchemaAsksForIdAndScoreOnly(): void
{
    $schema = RecommendationResponseSchema::BatchScore->toJsonSchema();
    $item = $schema->schema['properties']['recommendations']['items'];

    self::assertSame(['id', 'score'], $item['required']);
    self::assertArrayNotHasKey('reason', $item['properties']);
    self::assertFalse($item['additionalProperties']);
}

public function testConsolidationSchemaCarriesReasonsAndDuplicates(): void
{
    $schema = RecommendationResponseSchema::Consolidation->toJsonSchema();

    $item = $schema->schema['properties']['recommendations']['items'];
    self::assertSame(['id', 'score', 'reason'], $item['required']);
    self::assertSame('integer', $schema->schema['properties']['duplicates']['items']['type']);
    self::assertSame(['recommendations', 'duplicates'], $schema->schema['required']);
}

public function testDistillationSchemaAsksForAProfileString(): void
{
    $schema = RecommendationResponseSchema::Distillation->toJsonSchema();

    self::assertSame('string', $schema->schema['properties']['profile']['type']);
    self::assertSame(['profile'], $schema->schema['required']);
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `cd backend && php bin/phpunit --filter RecommendationResponseSchemaTest`
Expected: FAIL — unknown enum cases.

- [ ] **Step 3: Rewrite the enum**

`RecommendationResponseSchema.php` — replace cases `Ranking`, `Duplicates` with `Distillation`, `BatchScore`, `Consolidation`. Replace the two schema constants with three:

```php
private const array DISTILLATION_SCHEMA = [
    'type' => 'object',
    'properties' => [
        'profile' => ['type' => 'string'],
    ],
    'required' => ['profile'],
    'additionalProperties' => false,
];

private const array BATCH_SCORE_SCHEMA = [
    'type' => 'object',
    'properties' => [
        'recommendations' => [
            'type' => 'array',
            'items' => [
                'type' => 'object',
                'properties' => [
                    'id' => ['type' => 'integer'],
                    'score' => ['type' => 'integer'],
                ],
                'required' => ['id', 'score'],
                'additionalProperties' => false,
            ],
        ],
    ],
    'required' => ['recommendations'],
    'additionalProperties' => false,
];

private const array CONSOLIDATION_SCHEMA = [
    'type' => 'object',
    'properties' => [
        'recommendations' => [
            'type' => 'array',
            'items' => [
                'type' => 'object',
                'properties' => [
                    'id' => ['type' => 'integer'],
                    'score' => ['type' => 'integer'],
                    'reason' => ['type' => 'string'],
                ],
                'required' => ['id', 'score', 'reason'],
                'additionalProperties' => false,
            ],
        ],
        'duplicates' => [
            'type' => 'array',
            'items' => ['type' => 'integer'],
        ],
    ],
    'required' => ['recommendations', 'duplicates'],
    'additionalProperties' => false,
];
```

`toJsonSchema()`:

```php
public function toJsonSchema(): JsonSchema
{
    return match ($this) {
        self::Distillation => new JsonSchema('profile', self::DISTILLATION_SCHEMA),
        self::BatchScore => new JsonSchema('recommendations', self::BATCH_SCORE_SCHEMA),
        self::Consolidation => new JsonSchema('recommendations', self::CONSOLIDATION_SCHEMA),
    };
}
```

- [ ] **Step 4: Update the one match site so the tree compiles**

`RecommendationPromptBuilder::answerBoundTokens()` — replace the `match ($schema)` arms (this reads token constants added in Task 5; for now, keep it compiling by referencing the existing `TOKENS_PER_PICK` / `TOKENS_PER_DUPLICATE_ID` and a placeholder that Task 5 finalizes):

```php
$perItem = match ($schema) {
    RecommendationResponseSchema::Distillation => self::TOKENS_PER_PICK,
    RecommendationResponseSchema::BatchScore => self::TOKENS_PER_PICK,
    RecommendationResponseSchema::Consolidation => self::TOKENS_PER_PICK,
};
```

(Task 5 replaces these with the real per-phase constants and the distillation fixed-reserve path. This interim keeps the enum swap a self-contained, compiling commit.) Also update `RecommendationBatchWave.php:238` and `RecommendationDedupResolver.php` references from `::Ranking` / `::Duplicates` to `::BatchScore` / `::Consolidation` respectively **only enough to compile** — the batch wave uses `::BatchScore`, and the dedup resolver (to be replaced in Task 11) temporarily uses `::Consolidation`.

- [ ] **Step 5: Run schema test + stan**

Run: `cd backend && php bin/phpunit --filter RecommendationResponseSchemaTest && composer stan`
Expected: PASS, and PHPStan reports no non-exhaustive match.

- [ ] **Step 6: Commit**

```bash
git add backend/src backend/tests
git commit -m "feat(#493): replace ranking/dedup schemas with distillation, batch-score, consolidation"
```

---

## Task 4: Prompt strings for the new phases

Adds the fixed wording for the three new phases; retires the reason-bearing batch contract and the dedup strings (kept until the cleanup task removes their last callers).

**Files:**
- Modify: `backend/src/Service/Recommendation/RecommendationPromptText.php`
- Test: none of its own (strings are asserted through the builder tests in Tasks 5–7); PHPStan covers usage.

**Interfaces:**
- Produces: `BATCH_SYSTEM_ROLE`, `BATCH_OUTPUT_CONTRACT`, `DISTILL_ROLE`, `DISTILL_OUTPUT_CONTRACT`, `DISTILL_CORRECTIVE`, `CONSOLIDATION_ROLE`, `CONSOLIDATION_OUTPUT_CONTRACT`, `CONSOLIDATION_CORRECTIVE` on `RecommendationPromptText`.

- [ ] **Step 1: Add the constants**

Append to `RecommendationPromptText` (keep the existing scoring calibration voice; these are load-bearing wording, so copy verbatim):

```php
public const string BATCH_SYSTEM_ROLE = 'You score candidate posts for one reader of an RSS reader. The user '
    . 'message holds a PROFILE describing the reader, a FAVORITES section listing the posts the reader liked most '
    . '(newest first), and a CANDIDATES section listing unread posts; each candidate line starts with the '
    . 'candidate id in square brackets. Score each candidate from 0 to 1000 for how strongly the PROFILE and the '
    . 'FAVORITES suggest the reader would open it. Be critical and sparing with high scores: most candidates are '
    . 'only a weak or partial fit and must score below 500. Reserve 900-1000 for the rare candidate that is an '
    . 'unmistakable, specific match to a strong, repeated interest; 700-899 for a clear, direct match; 400-699 '
    . 'for a real but partial or merely thematic match; 100-399 for a weak or tangential link; 0-99 for no '
    . 'visible connection. If you are giving many candidates scores above 800, you are being too generous — lower '
    . 'them. When uncertain, score lower. Prefer recent posts. Use the whole range and give each candidate its '
    . 'own exact number: 843, 617, 291. Do not round to multiples of ten, and do not give the same score to '
    . 'several candidates. Score every candidate you are shown — never leave a candidate out. Return the id and '
    . 'the score only; do not write a reason.';

public const string BATCH_OUTPUT_CONTRACT = 'Reply with JSON only, no prose: {"recommendations": '
    . '[{"id": <candidate id>, "score": <0-1000>}]}. Return one object for every candidate line, in the order '
    . 'the lines appear. Use only ids that appear in the candidate lines.';

public const string DISTILL_ROLE = 'You read one reader\'s history from an RSS reader and write a short '
    . 'preference profile for them. The user message holds three sections — FAVORITES, KEPT and VIEWED, newest '
    . 'first — where FAVORITES weighs strongest, KEPT next, VIEWED least. Write a compact profile, at most about '
    . '300 words, that names the reader\'s specific, repeated interests — topics, subjects, companies, '
    . 'technologies, people, kinds of story — and what they clearly avoid. Name concrete interests, not broad '
    . 'categories: prefer "self-hosted home automation" over "technology". The profile is used to score unread '
    . 'posts, so it must be specific enough to tell a strong match from a weak one.';

public const string DISTILL_OUTPUT_CONTRACT = 'Reply with JSON only, no prose: '
    . '{"profile": "<the preference profile>"}.';

public const string DISTILL_CORRECTIVE = 'Your previous reply was not usable. Reply again with JSON only, '
    . 'exactly in the required shape: {"profile": "<the preference profile>"}.';

public const string CONSOLIDATION_ROLE = 'You rank a shortlist of unread posts for one reader of an RSS reader '
    . 'and remove duplicates. The user message holds a PROFILE describing the reader, a FAVORITES section listing '
    . 'the posts the reader liked most (newest first), and a SHORTLIST of candidate posts, each line starting '
    . 'with the candidate id in square brackets. Do two things. First, score each shortlisted post from 0 to 1000 '
    . 'for how strongly the PROFILE and the FAVORITES suggest the reader would open it; use the whole range, '
    . 'give each its own exact number, and write one short sentence for each, shown to the reader, that names '
    . 'what the post is about and the interest or earlier post it matches. Do not open a reason with a fixed '
    . 'phrase such as "Directly aligns" or "Matches the reader\'s". Second, name the duplicates: two posts are '
    . 'duplicates only when they report the same specific event, told by different sources; posts that merely '
    . 'share a topic, company, technology or person are not duplicates. Keep the best-scored source and name the '
    . 'others as duplicates; when in doubt, name neither.';

public const string CONSOLIDATION_OUTPUT_CONTRACT = 'Reply with JSON only, no prose: {"recommendations": '
    . '[{"id": <id>, "score": <0-1000>, "reason": "<one short sentence>"}], "duplicates": [<id>, ...]}. Return '
    . 'one recommendation object for every shortlist line, in the order the lines appear. List in "duplicates" '
    . 'only ids that duplicate a better-scored post; if there are none, reply "duplicates": []. Use only ids '
    . 'that appear in the lines.';

public const string CONSOLIDATION_CORRECTIVE = 'Your previous reply was not usable. Reply again with JSON only, '
    . 'exactly in the required shape, using only ids that appear in the lines. Score and give a reason for every '
    . 'shortlist line, and name a duplicate only when it reports the same specific story as a better-scored '
    . 'entry.';
```

- [ ] **Step 2: Verify it parses**

Run: `cd backend && composer cs && composer stan`
Expected: PASS.

- [ ] **Step 3: Commit**

```bash
git add backend/src/Service/Recommendation/RecommendationPromptText.php
git commit -m "feat(#493): add distillation, score-only batch, and consolidation prompt text"
```

---

## Task 5: Score-only token math + packing in the prompt builder

Splits the per-item reply cost so score-only batches pack larger (the compounding token win), and gives distillation a fixed answer reserve. Packing now budgets for `profile + FAVORITES`, not the full three-section history.

**Files:**
- Modify: `backend/src/Service/Recommendation/RecommendationPromptBuilder.php`
- Test: `backend/tests/Service/Recommendation/RecommendationPromptBuilderTest.php` (existing — extend)

**Interfaces:**
- Produces: constants `TOKENS_PER_SCORE_PICK = 15`, `PROFILE_ANSWER_TOKENS = 1200`, `ESTIMATED_PROFILE_TOKENS = 700`; `answerBoundTokens()` with real per-phase arms; `packBatches()` budgeting on score-only reply + `profile+FAVORITES` history.
- Consumes: `RecommendationResponseSchema::{Distillation,BatchScore,Consolidation}` (Task 3).

- [ ] **Step 1: Write the failing token-math tests**

```php
public function testScoreOnlyBatchesPackLargerThanReasonBearingWouldHave(): void
{
    $candidates = $this->promptLines(200);           // helper: 200 candidate PromptLines
    $history = $this->historyWith(favorites: 40, kept: 40, viewed: 80);
    $settings = $this->effectiveSettings(contextWindow: 32768);

    $batches = $this->builder->packBatches($candidates, $history, $settings);

    // score-only reply (15/pick) + favorites-only history budget => far fewer, fuller batches
    self::assertLessThanOrEqual(5, count($batches));
}

public function testAnswerBoundIsSchemaAware(): void
{
    self::assertSame(
        intdiv(max(1024, 100 * 15) * 150, 100),
        $this->builder->answerBoundTokens(100, RecommendationResponseSchema::BatchScore),
    );
    self::assertSame(
        intdiv(max(1024, 100 * 70) * 150, 100),
        $this->builder->answerBoundTokens(100, RecommendationResponseSchema::Consolidation),
    );
    self::assertSame(
        intdiv(max(1024, 1200) * 150, 100),
        $this->builder->answerBoundTokens(1, RecommendationResponseSchema::Distillation),
    );
}
```

- [ ] **Step 2: Run to verify failure**

Run: `cd backend && php bin/phpunit --filter RecommendationPromptBuilderTest`
Expected: FAIL — batch count too high / distillation arm wrong.

- [ ] **Step 3: Add the constants and rewrite `answerBoundTokens`**

Add constants near `TOKENS_PER_PICK`:

```php
/** What one score-only pick costs in a batch reply: `{"id":123,"score":843}` — an id and an
 *  integer, no prose. About a fifth of a reason-bearing pick, which is the whole point of the
 *  coarse-filter batch: the answer reserve shrinks, so packBatches fits more candidates per
 *  batch and the run makes fewer calls (#493). */
private const int TOKENS_PER_SCORE_PICK = 15;

/** The answer reserve for the distillation reply. One `{"profile": "..."}` string of at most
 *  ~300 words; sized generously so a reasoning model still finishes the JSON (#493). */
private const int PROFILE_ANSWER_TOKENS = 1200;

/** What packBatches assumes the not-yet-distilled profile block will cost, so it can budget the
 *  batch prompt before the distillation phase has run. An estimate on purpose — the real profile
 *  is bounded to roughly this by DISTILL_ROLE's word cap (#493). */
private const int ESTIMATED_PROFILE_TOKENS = 700;
```

Rewrite `answerBoundTokens()`:

```php
public function answerBoundTokens(int $replyItemCount, RecommendationResponseSchema $schema): int
{
    $expected = match ($schema) {
        RecommendationResponseSchema::Distillation => self::PROFILE_ANSWER_TOKENS,
        RecommendationResponseSchema::BatchScore => $replyItemCount * self::TOKENS_PER_SCORE_PICK,
        RecommendationResponseSchema::Consolidation => $replyItemCount * self::TOKENS_PER_PICK,
    };

    $bounded = max(self::MINIMUM_ANSWER_TOKENS, $expected);

    return intdiv($bounded * self::ANSWER_BOUND_PERCENT, 100);
}
```

- [ ] **Step 4: Repoint `packBatches` at the score-only reserve and the batch history**

In `packBatches()`:
- Replace `$historyTokens = $this->tokens($this->historySections($history, $descriptionLength));` with the batch-shaped history budget:

```php
$historyTokens = self::ESTIMATED_PROFILE_TOKENS
    + $this->tokens($this->historySection('FAVORITES (newest first):', $history->favorites, $descriptionLength));
```

- Replace `$responseReserve = $this->answerTokenReserve($cap);` with the score-only reserve:

```php
$responseReserve = intdiv($cap * self::TOKENS_PER_SCORE_PICK * self::ANSWER_BOUND_PERCENT, 100);
$responseReserve = max(self::MINIMUM_ANSWER_TOKENS, $responseReserve);
```

(`answerTokenReserve()` is now used by nothing else; delete it in the cleanup task, or inline it here — do not leave a second reserve helper carrying the old 70-token rate.)

- [ ] **Step 5: Run to verify passes**

Run: `cd backend && php bin/phpunit --filter RecommendationPromptBuilderTest`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add backend/src backend/tests
git commit -m "feat(#493): split reply-token cost per phase so score-only batches pack larger"
```

---

## Task 6: Batch messages carry `profile + FAVORITES`, reply score-only

Rewrites the batch prompt: a profile block (or nothing, on degrade) plus FAVORITES only, with the score-only role and contract. Makes the pick parser tolerate a missing `reason` so the same parser serves both the score-only batch and the consolidation reply.

**Files:**
- Modify: `backend/src/Service/Recommendation/RecommendationPromptBuilder.php` (`batchMessages`, `userSections`), `backend/src/Service/Recommendation/RecommendationPickParser.php`, `backend/src/Service/Recommendation/RecommendationBatchWave.php`
- Test: `RecommendationPromptBuilderTest`, `RecommendationPickParserTest`

**Interfaces:**
- Produces: `batchMessages(RecommendationHistory $history, array $candidateLines, EffectiveRecommendationSettings $settings, ?string $profile, ?CandidatePoolSummary $poolSummary = null)`; `RecommendationPick` with `reason` defaulting to `''` when absent.
- Consumes: `BATCH_SYSTEM_ROLE`, `BATCH_OUTPUT_CONTRACT` (Task 4); `RecommendationResponseSchema::BatchScore` (Task 3); `RecommendationRun::getProfileText()` (Task 8 — the wave call is wired there; here `batchMessages` just accepts the param).

- [ ] **Step 1: Write the failing batch-message test**

```php
public function testBatchMessagesCarryProfileAndFavouritesOnly(): void
{
    $history = $this->historyWith(favorites: 3, kept: 3, viewed: 3);
    $messages = $this->builder->batchMessages(
        $history,
        $this->promptLines(2),
        $this->effectiveSettings(),
        'Likes homelab and Rust.',
    );

    $user = $messages[1]['content'];
    self::assertStringContainsString('PROFILE', $user);
    self::assertStringContainsString('Likes homelab and Rust.', $user);
    self::assertStringContainsString('FAVORITES', $user);
    self::assertStringNotContainsString('KEPT', $user);
    self::assertStringNotContainsString('VIEWED', $user);
    self::assertStringContainsString('"score"', $messages[0]['content']); // contract, no reason
    self::assertStringNotContainsString('"reason"', $messages[0]['content']);
}

public function testBatchMessagesOmitProfileBlockWhenProfileIsNull(): void
{
    $messages = $this->builder->batchMessages(
        $this->historyWith(favorites: 2, kept: 0, viewed: 0),
        $this->promptLines(1),
        $this->effectiveSettings(),
        null,
    );

    self::assertStringNotContainsString('PROFILE', $messages[1]['content']);
    self::assertStringContainsString('FAVORITES', $messages[1]['content']);
}
```

- [ ] **Step 2: Run to verify failure**

Run: `cd backend && php bin/phpunit --filter RecommendationPromptBuilderTest`
Expected: FAIL — signature has no `$profile`.

- [ ] **Step 3: Rewrite `batchMessages` + `userSections`**

`batchMessages` — new signature and system assembly:

```php
public function batchMessages(
    RecommendationHistory $history,
    array $candidateLines,
    EffectiveRecommendationSettings $settings,
    ?string $profile,
    ?CandidatePoolSummary $poolSummary = null,
): array {
    $descriptionLength = $this->descriptionLength($settings->packing->contextWindow);
    $guidance = $settings->guidancePrompt ?? RecommendationPromptText::DEFAULT_GUIDANCE;
    $system = implode("\n\n", [
        RecommendationPromptText::BATCH_SYSTEM_ROLE,
        $guidance,
        RecommendationPromptText::BATCH_OUTPUT_CONTRACT,
    ]);

    $user = implode("\n\n", $this->batchUserSections($history, $candidateLines, $descriptionLength, $profile, $poolSummary));

    return [
        ['role' => 'system', 'content' => $system],
        ['role' => 'user', 'content' => $user],
    ];
}
```

Add `batchUserSections()` (profile block + FAVORITES only + pool frame + candidates):

```php
private function batchUserSections(
    RecommendationHistory $history,
    array $candidateLines,
    int $descriptionLength,
    ?string $profile,
    ?CandidatePoolSummary $poolSummary,
): array {
    $sections = [];
    if (null !== $profile && '' !== trim($profile)) {
        $sections[] = "PROFILE:\n" . $profile;
    }
    $sections[] = $this->historySection('FAVORITES (newest first):', $history->favorites, $descriptionLength);
    $poolFrame = $this->poolFrameLine($poolSummary);
    if (null !== $poolFrame) {
        $sections[] = $poolFrame;
    }
    $sections[] = $this->candidateSection($candidateLines, $descriptionLength);

    return $sections;
}
```

(The old `userSections()` becomes unused once consolidation has its own; delete it in the cleanup task if nothing else calls it.)

- [ ] **Step 4: Run the builder test to verify it passes**

Run: `cd backend && php bin/phpunit --filter RecommendationPromptBuilderTest`
Expected: PASS.

- [ ] **Step 5: Write the failing pick-parser test**

Add to `RecommendationPickParserTest`:

```php
public function testParsesScoreOnlyPicksWithoutAReason(): void
{
    $result = $this->parser->parse('{"recommendations":[{"id":7,"score":800}]}', [7]);

    self::assertTrue($result->usable);
    self::assertSame(7, $result->picks[0]->entryId);
    self::assertSame(800, $result->picks[0]->score);
    self::assertSame('', $result->picks[0]->reason);
}
```

- [ ] **Step 6: Run to verify failure**

Run: `cd backend && php bin/phpunit --filter RecommendationPickParserTest`
Expected: FAIL — missing `reason` treated as malformed.

- [ ] **Step 7: Default `reason` to `''` in the parser**

In `RecommendationPickParser::parse()`, when building each `RecommendationPick`, read `reason` as `is_string($row['reason'] ?? null) ? $row['reason'] : ''`. Do not reject a row for a missing reason (id and score remain required).

- [ ] **Step 8: Point the batch wave at the score-only schema**

`RecommendationBatchWave::sendRound()` — change `RecommendationResponseSchema::Ranking` (line 238) to `RecommendationResponseSchema::BatchScore`. (The `$run->getProfileText()` argument is threaded in Task 12, where the advancer/wave wiring lands; here the wave still calls `batchMessages(..., $poolSummary)` positionally — update its private `batchMessages()` wrapper at line 364-384 to pass `profile: null` for now, replaced in Task 12.)

- [ ] **Step 9: Run parser + builder + stan**

Run: `cd backend && php bin/phpunit --filter "RecommendationPickParserTest|RecommendationPromptBuilderTest" && composer stan`
Expected: PASS.

- [ ] **Step 10: Commit**

```bash
git add backend/src backend/tests
git commit -m "feat(#493): batch prompt carries profile+favorites and replies score-only"
```

---

## Task 7: Distillation and consolidation messages + their parsers

Adds the two remaining prompt shapes and the parsers for their replies. The consolidation parser reads recommendations and duplicates from one reply.

**Files:**
- Modify: `backend/src/Service/Recommendation/RecommendationPromptBuilder.php` (`distillMessages`, `consolidationMessages`)
- Create: `RecommendationProfileParser.php`, `ProfileParseResult.php`, `RecommendationConsolidationParser.php`, `ConsolidationParseResult.php`
- Test: `RecommendationPromptBuilderTest`, `RecommendationProfileParserTest`, `RecommendationConsolidationParserTest`

**Interfaces:**
- Produces:
  - `distillMessages(RecommendationHistory $history, EffectiveRecommendationSettings $settings): list<array{role:string,content:string}>`
  - `consolidationMessages(array $rankedPool, array $linesById, RecommendationHistory $history, EffectiveRecommendationSettings $settings, ?string $profile): list<array{role:string,content:string}>` where `$rankedPool` is `list<array{id:int,score:int,reason:string}>`, `$linesById` is `array<int,PromptLine>`. Renders `profile + FAVORITES` (not the full history) plus the shortlist.
  - `ProfileParseResult { public ?string $profile; public bool $usable; static usable(string): self; static unusable(): self; }`
  - `RecommendationProfileParser::parse(string $content): ProfileParseResult`
  - `ConsolidationParseResult { public array $picks; public array $duplicateIds; public bool $usable; }` (`picks` = `list<RecommendationPick>`)
  - `RecommendationConsolidationParser::parse(string $content, array $shownIds): ConsolidationParseResult`
- Consumes: `DISTILL_*`, `CONSOLIDATION_*` (Task 4); `ModelReplyJsonDecoder` (inject, as the existing parsers do); `PlausibleDuplicateShare` (existing, for the duplicate-share cap).

- [ ] **Step 1: Write the failing distillation-message test**

```php
public function testDistillMessagesCarryAllThreeHistorySections(): void
{
    $messages = $this->builder->distillMessages(
        $this->historyWith(favorites: 2, kept: 2, viewed: 2),
        $this->effectiveSettings(),
    );

    self::assertStringContainsString('FAVORITES', $messages[1]['content']);
    self::assertStringContainsString('KEPT', $messages[1]['content']);
    self::assertStringContainsString('VIEWED', $messages[1]['content']);
    self::assertStringContainsString('"profile"', $messages[0]['content']);
}
```

- [ ] **Step 2: Write the failing consolidation-message test**

```php
public function testConsolidationMessagesCarryProfileFavouritesAndShortlist(): void
{
    $pool = [['id' => 5, 'score' => 900, 'reason' => '']];
    $lines = [5 => $this->promptLine(id: 5, title: 'Rust 2.0 released')];

    $messages = $this->builder->consolidationMessages(
        $pool,
        $lines,
        $this->historyWith(favorites: 1, kept: 1, viewed: 1),
        $this->effectiveSettings(),
        'Likes Rust.',
    );

    self::assertStringContainsString('PROFILE', $messages[1]['content']);
    self::assertStringContainsString('Likes Rust.', $messages[1]['content']);
    self::assertStringContainsString('FAVORITES', $messages[1]['content']);
    self::assertStringNotContainsString('KEPT', $messages[1]['content']);
    self::assertStringNotContainsString('VIEWED', $messages[1]['content']);
    self::assertStringContainsString('[5]', $messages[1]['content']);
    self::assertStringContainsString('Rust 2.0 released', $messages[1]['content']);
    self::assertStringContainsString('duplicates', $messages[0]['content']);
}
```

- [ ] **Step 3: Run to verify failure**

Run: `cd backend && php bin/phpunit --filter RecommendationPromptBuilderTest`
Expected: FAIL — methods absent.

- [ ] **Step 4: Implement the two message builders**

`distillMessages()`:

```php
public function distillMessages(RecommendationHistory $history, EffectiveRecommendationSettings $settings): array
{
    $descriptionLength = $this->descriptionLength($settings->packing->contextWindow);

    return [
        [
            'role' => 'system',
            'content' => RecommendationPromptText::DISTILL_ROLE
                . "\n\n" . RecommendationPromptText::DISTILL_OUTPUT_CONTRACT,
        ],
        ['role' => 'user', 'content' => $this->historySections($history, $descriptionLength)],
    ];
}
```

`consolidationMessages()` — `profile + FAVORITES` (the same fidelity as the batches), then the shortlist rendered with the candidate-style lines (id in brackets, title/feed/date/description). Reuse `candidateSection` by mapping the pool ids back to their `PromptLine`s in ranked order:

```php
public function consolidationMessages(
    array $rankedPool,
    array $linesById,
    RecommendationHistory $history,
    EffectiveRecommendationSettings $settings,
    ?string $profile,
): array {
    if ([] === $rankedPool) {
        throw new \LogicException('The consolidation phase requires at least one ranked winner.');
    }

    $descriptionLength = $this->descriptionLength($settings->packing->contextWindow);
    $shortlistLines = [];
    foreach ($rankedPool as $winner) {
        $line = $linesById[$winner['id']] ?? null;
        if (null !== $line) {
            $shortlistLines[] = $line;
        }
    }

    $sections = [];
    if (null !== $profile && '' !== trim($profile)) {
        $sections[] = "PROFILE:\n" . $profile;
    }
    $sections[] = $this->historySection('FAVORITES (newest first):', $history->favorites, $descriptionLength);
    $sections[] = "SHORTLIST:\n" . $this->candidateSection($shortlistLines, $descriptionLength);
    $user = implode("\n\n", $sections);

    return [
        [
            'role' => 'system',
            'content' => RecommendationPromptText::CONSOLIDATION_ROLE
                . "\n\n" . RecommendationPromptText::CONSOLIDATION_OUTPUT_CONTRACT,
        ],
        ['role' => 'user', 'content' => $user],
    ];
}
```

- [ ] **Step 5: Run builder test to verify it passes**

Run: `cd backend && php bin/phpunit --filter RecommendationPromptBuilderTest`
Expected: PASS.

- [ ] **Step 6: Write the profile-parser tests, then implement**

`RecommendationProfileParserTest`:

```php
public function testParsesAProfileString(): void
{
    $result = $this->parser->parse('{"profile":"Likes maps and Rust."}');
    self::assertTrue($result->usable);
    self::assertSame('Likes maps and Rust.', $result->profile);
}

public function testRejectsMissingOrEmptyProfile(): void
{
    self::assertFalse($this->parser->parse('{"profile":""}')->usable);
    self::assertFalse($this->parser->parse('not json')->usable);
    self::assertFalse($this->parser->parse('{"nope":1}')->usable);
}
```

Implement `ProfileParseResult` (`final readonly`, `usable(string $profile)` / `unusable()` factories) and `RecommendationProfileParser` (inject `ModelReplyJsonDecoder`; decode, read `profile`, `unusable()` unless it is a non-empty trimmed string).

- [ ] **Step 7: Write the consolidation-parser tests, then implement**

`RecommendationConsolidationParserTest`:

```php
public function testParsesPicksAndDuplicates(): void
{
    $json = '{"recommendations":[{"id":5,"score":900,"reason":"On Rust."},{"id":6,"score":300,"reason":"Weak."}],'
        . '"duplicates":[6]}';

    $result = $this->parser->parse($json, [5, 6]);

    self::assertTrue($result->usable);
    self::assertSame([5, 6], array_map(static fn ($p) => $p->entryId, $result->picks));
    self::assertSame([6], $result->duplicateIds);
}

public function testRejectsWhenNoPicksSurvive(): void
{
    self::assertFalse($this->parser->parse('{"recommendations":[],"duplicates":[]}', [5])->usable);
}

public function testDropsDuplicateIdsNotShown(): void
{
    $json = '{"recommendations":[{"id":5,"score":900,"reason":"x"}],"duplicates":[999]}';
    $result = $this->parser->parse($json, [5]);
    self::assertSame([], $result->duplicateIds); // 999 was never shown
}
```

Implement `ConsolidationParseResult` and `RecommendationConsolidationParser`: decode once; reuse the pick-salvaging rules (clamp score to `MAXIMUM_SCORE`, drop unknown ids, `reason` defaults to `''`); read `duplicates` as ints filtered to `$shownIds`; `usable` only when at least one pick survives; an empty `duplicates` list is legitimate. Do **not** re-apply `PlausibleDuplicateShare` here unless the shortlist size makes an implausible share meaningful — mirror `RecommendationDuplicateParser`'s share guard so a reply naming most of the shortlist as duplicates is rejected whole.

- [ ] **Step 8: Run the new parser tests**

Run: `cd backend && php bin/phpunit --filter "RecommendationProfileParserTest|RecommendationConsolidationParserTest"`
Expected: PASS.

- [ ] **Step 9: Commit**

```bash
git add backend/src backend/tests
git commit -m "feat(#493): distillation and consolidation messages with their reply parsers"
```

---

## Task 8: Run-level profile state (`profileText`, `distilled`)

Gives the run its own frozen profile and a distillation-done marker, isolated from the settings display copy so a degraded distillation this run never reads last run's profile.

**Files:**
- Create: `backend/migrations/Version20260821140000.php`
- Modify: `backend/src/Entity/RecommendationRun.php`
- Test: `backend/tests/Entity/RecommendationRunTest.php` (existing — extend)

**Interfaces:**
- Produces: `RecommendationRun::recordProfile(?string $profileText): void`, `getProfileText(): ?string`, `isDistilled(): bool`. `snapshot()` leaves `distilled = false`.
- Consumes: `MAX_ATTEMPTS`, `guardStatus()` (existing).

- [ ] **Step 1: Write the failing entity tests**

```php
public function testRecordProfileStoresTextAndMarksDistilled(): void
{
    $run = $this->runInRunningState();
    $run->recordProfile('Likes Rust.');

    self::assertTrue($run->isDistilled());
    self::assertSame('Likes Rust.', $run->getProfileText());
}

public function testRecordProfileWithNullMarksDistilledButKeepsNoProfile(): void
{
    $run = $this->runInRunningState();
    $run->recordProfile(null);

    self::assertTrue($run->isDistilled());
    self::assertNull($run->getProfileText());
}

public function testFreshRunIsNotDistilled(): void
{
    self::assertFalse($this->runInRunningState()->isDistilled());
}
```

- [ ] **Step 2: Run to verify failure**

Run: `cd backend && php bin/phpunit --filter RecommendationRunTest`
Expected: FAIL.

- [ ] **Step 3: Add the columns and methods**

In `RecommendationRun` add, beside `error`/`lastInvalidReply`:

```php
#[ORM\Column(type: Types::TEXT, nullable: true)]
private ?string $profileText = null;

#[ORM\Column(options: ['default' => false])]
private bool $distilled = false;
```

```php
public function recordProfile(?string $profileText): void
{
    $this->guardStatus(self::STATUS_RUNNING, 'recordProfile');

    $this->profileText = $profileText;
    $this->distilled = true;
    $this->attempts = 0;
    $this->transportFailures = 0;
    $this->lastInvalidReply = null;
}

public function getProfileText(): ?string
{
    return $this->profileText;
}

public function isDistilled(): bool
{
    return $this->distilled;
}
```

- [ ] **Step 4: Write the migration**

Create `backend/migrations/Version20260821140000.php` (same platform-aware, guarded shape) adding two columns to `recommendation_run`: `profile_text` (LONGTEXT/CLOB nullable) and `distilled` (`TINYINT(1)`/`BOOLEAN NOT NULL DEFAULT 0`). Mirror an existing boolean-with-default column's DDL from a prior migration for the exact type tokens.

- [ ] **Step 5: Verify migration + schema validate on both dialects**

Run:

```bash
cd backend && php bin/console doctrine:migrations:migrate --no-interaction && php bin/console doctrine:schema:validate
docker compose exec php bin/console doctrine:migrations:migrate --no-interaction && docker compose exec php bin/console doctrine:schema:validate
```

Expected: applies clean; schema valid on SQLite and MySQL.

- [ ] **Step 6: Run entity test + commit**

Run: `cd backend && php bin/phpunit --filter RecommendationRunTest && composer cs && composer stan`

```bash
git add backend/src backend/migrations backend/tests
git commit -m "feat(#493): frozen per-run profileText and distilled marker on the run"
```

---

## Task 9: Progress accounting for the two new phases

Consolidation now runs unconditionally, and the run has two extra phases. `RecommendationRunProgress` learns the new phase flag and total.

**Files:**
- Modify: `backend/src/Entity/RecommendationRunProgress.php`
- Test: `backend/tests/Entity/RecommendationRunProgressTest.php` (existing — extend)

**Interfaces:**
- Produces: `forBatchPlan(?array $candidateBatches, int $batchesDone, int $attempts, bool $distilled)`; fields `isConsolidationPhase` (replaces `isDedupPhase`), `distillPending`; `batchesTotal = batchCount + 2` when candidates exist.
- Consumes: `RecommendationRun::isDistilled()` / `progress()` call site (Task 8, Task 12).

- [ ] **Step 1: Write the failing progress tests**

```php
public function testConsolidationRunsEvenForASingleBatch(): void
{
    $progress = RecommendationRunProgress::forBatchPlan([[1, 2, 3]], batchesDone: 1, attempts: 0, distilled: true);

    self::assertTrue($progress->isConsolidationPhase);
}

public function testConsolidationWaitsUntilAllBatchesAreDone(): void
{
    $progress = RecommendationRunProgress::forBatchPlan([[1], [2]], batchesDone: 1, attempts: 0, distilled: true);

    self::assertFalse($progress->isConsolidationPhase);
}

public function testDistillPendingUntilDistilled(): void
{
    self::assertTrue(
        RecommendationRunProgress::forBatchPlan([[1]], 0, 0, distilled: false)->distillPending,
    );
    self::assertFalse(
        RecommendationRunProgress::forBatchPlan([[1]], 0, 0, distilled: true)->distillPending,
    );
}

public function testTotalCountsDistillationAndConsolidation(): void
{
    self::assertSame(4, RecommendationRunProgress::forBatchPlan([[1], [2]], 0, 0, true)->batchesTotal);
}
```

- [ ] **Step 2: Run to verify failure**

Run: `cd backend && php bin/phpunit --filter RecommendationRunProgressTest`
Expected: FAIL — signature/field mismatch.

- [ ] **Step 3: Rewrite the VO**

Replace the constructor field `isDedupPhase` with `isConsolidationPhase`; drop `needsDedup` (no caller after Task 12); add `distillPending`. Rewrite `forBatchPlan`:

```php
public static function forBatchPlan(?array $candidateBatches, int $batchesDone, int $attempts, bool $distilled): self
{
    $batchCount = $candidateBatches === null ? 0 : count($candidateBatches);
    $hasPlan = $candidateBatches !== null && $batchCount > 0;
    $allBatchCallsDone = $batchesDone === $batchCount;

    return new self(
        batchesDone: $batchesDone,
        batchesTotal: $hasPlan ? $batchCount + 2 : null,
        distillPending: $hasPlan && !$distilled,
        isConsolidationPhase: $hasPlan && $distilled && $allBatchCallsDone,
        allBatchCallsDone: $allBatchCallsDone,
        nextBatchIndex: $batchesDone,
        attemptsExhausted: $attempts >= RecommendationRun::MAX_ATTEMPTS,
    );
}
```

(`isConsolidationPhase` also gates on `$distilled` so the advancer never routes to consolidation before distillation ran. Update the promoted-property constructor to match the new field set. Remove `batchesTotal()`'s old `+ ($batchCount > 1 ? 1 : 0)` helper.)

- [ ] **Step 4: Run to verify passes + stan**

Run: `cd backend && php bin/phpunit --filter RecommendationRunProgressTest && composer stan`
Expected: PASS. (PHPStan will flag `RecommendationRun::progress()` still calling the 3-arg `forBatchPlan` — fix that call to pass `$this->distilled` now.)

- [ ] **Step 5: Commit**

```bash
git add backend/src backend/tests
git commit -m "feat(#493): progress accounts for distillation and unconditional consolidation"
```

---

## Task 10: The distillation phase resolver

One provider call over the full history producing the profile; writes it to the run and caches it on settings. Modeled on the dedup resolver's call/record/parse/settle/guard shape.

**Files:**
- Create: `RecommendationProfileDistiller.php`, `ProfileDistillationOutcome.php`
- Test: `backend/tests/Service/Recommendation/RecommendationProfileDistillerTest.php`

**Interfaces:**
- Produces: `RecommendationProfileDistiller::distill(RecommendationRun $run, AiProviderSettings $settings, int $userId, EffectiveRecommendationSettings $effectiveSettings): ProfileDistillationOutcome`; `ProfileDistillationOutcome { public bool $usable; public ?string $profileText; private ?string $unusableReply; static usable(string): self; static unusable(string $reply): self; requireUnusableReply(): string; }`
- Consumes: `RecommendationHistoryLoader`, `RecommendationCallRecorder` (`PHASE_DISTILL`), `RecommendationCompletionRequestFactory`, `ChatCompletionClient`, `ProviderConnectionFactory`, `RecommendationProfileParser`, `RecommendationPromptBuilder::distillMessages`, `RecommendationTickCheckpoint`, `RecommendationRunLog::PHASE_DISTILL`. Writes via `RecommendationSettingsWriter::storeProfile` on success only.

- [ ] **Step 1: Add the phase constant with a failing usage test**

Add to `RecommendationRunLog`:

```php
public const string PHASE_DISTILL = 'distill';
public const string PHASE_CONSOLIDATE = 'consolidate';
```

(Both ≤16 chars — the `phase` column limit.)

- [ ] **Step 2: Write the failing distiller test**

Use the test doubles the dedup-resolver test uses (a fake `ChatCompletionClient` returning a canned reply, in-memory recorder). Assert:

```php
public function testUsableReplyWritesProfileToRunAndSettings(): void
{
    $this->chat->willReply('{"profile":"Likes Rust and homelab."}');
    $run = $this->runInRunningState();

    $outcome = $this->distiller->distill($run, $this->settings, $this->userId, $this->effectiveSettings);

    self::assertTrue($outcome->usable);
    self::assertSame('Likes Rust and homelab.', $outcome->profileText);
    self::assertSame('Likes Rust and homelab.', $this->settingsWriter->storedProfileFor($this->userId));
}

public function testUnusableReplyReturnsUnusableAndDoesNotTouchSettings(): void
{
    $this->chat->willReply('not json');
    $outcome = $this->distiller->distill($this->runInRunningState(), $this->settings, $this->userId, $this->effectiveSettings);

    self::assertFalse($outcome->usable);
    self::assertNull($this->settingsWriter->storedProfileFor($this->userId));
}
```

- [ ] **Step 3: Run to verify failure**

Run: `cd backend && php bin/phpunit --filter RecommendationProfileDistillerTest`
Expected: FAIL — class absent.

- [ ] **Step 4: Implement the outcome and the resolver**

`ProfileDistillationOutcome` as specified. `RecommendationProfileDistiller` (`final readonly`): load history via `historyLoader->load($userId, $effectiveSettings)`; build `promptBuilder->distillMessages($history, $effectiveSettings)`; `callRecorder->begin($run, RecommendationRunLog::PHASE_DISTILL, null, $messages, model)`; `requestFactory->create($settings, $messages, 1, RecommendationResponseSchema::Distillation)`; call the provider through the same `callProvider` pattern the dedup resolver uses (wrap `chat->complete(...)`, `abortAfterTransportFailure` on throw); parse with `profileParser->parse($content)`; `recordedCall->settle($content, $result->usable)`; `checkpoint->guard($run)`. On usable: `settingsWriter->storeProfile($run->getUser(), $result->profile)` and return `usable($result->profile)`. On unusable: return `unusable($content)`. Do **not** write to the run here — the advancer's `distillTick` owns `recordProfile()` (so retry accounting lives in one place), mirroring how `dedupTick` — not the resolver — calls `finalize`/`retryOrDegrade`.

- [ ] **Step 5: Run to verify passes**

Run: `cd backend && php bin/phpunit --filter RecommendationProfileDistillerTest && composer stan`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add backend/src backend/tests
git commit -m "feat(#493): profile distillation phase resolver"
```

---

## Task 11: The consolidation phase resolver

Replaces `RecommendationDedupResolver`. One call over the top-`2×picksLimit` that re-scores, reasons, and dedups; produces the final ranked+deduped+reasoned list. Degrades to the batch-score pool with empty reasons.

**Files:**
- Create: `RecommendationConsolidationResolver.php`, `ConsolidationOutcome.php`
- Test: `backend/tests/Service/Recommendation/RecommendationConsolidationResolverTest.php`

**Interfaces:**
- Produces: `RecommendationConsolidationResolver::resolve(RecommendationRun $run, AiProviderSettings $settings, int $userId, int $picksLimit, EffectiveRecommendationSettings $effectiveSettings): ConsolidationOutcome`; `ConsolidationOutcome { public bool $usable; public array $ranked; static finalizeWith(list<array{id:int,score:int,reason:string}>): self; static unusable(string $reply, list<...> $fallbackPool): self; requireUnusableReply(): string; }`
- Consumes: `RecommendationWinnerRanker::ranked` + `cutForDedup`, `RecommendationCandidateLoader::linesForIds`, `RecommendationHistoryLoader::load`, `RecommendationPromptBuilder::consolidationMessages`, `RecommendationConsolidationParser`, `RecommendationCallRecorder` (`PHASE_CONSOLIDATE`), `RecommendationCompletionRequestFactory` (`Consolidation` schema), `ChatCompletionClient`, `RecommendationTickCheckpoint`.

- [ ] **Step 1: Write the failing resolver tests**

```php
public function testUsableReplyDropsDuplicatesAndSortsByNewScore(): void
{
    // batch winners give the pool; the consolidation reply re-scores + flags a duplicate
    $run = $this->runWithWinners([['id' => 5, 'score' => 400, 'reason' => ''], ['id' => 6, 'score' => 700, 'reason' => '']]);
    $this->chat->willReply('{"recommendations":[{"id":5,"score":950,"reason":"On Rust."},'
        . '{"id":6,"score":300,"reason":"Weak fit."}],"duplicates":[6]}');

    $outcome = $this->resolver->resolve($run, $this->settings, $this->userId, 50, $this->effectiveSettings);

    self::assertTrue($outcome->usable);
    self::assertSame([5], array_map(static fn ($p) => $p['id'], $outcome->ranked)); // 6 deduped
    self::assertSame('On Rust.', $outcome->ranked[0]['reason']);
    self::assertSame(950, $outcome->ranked[0]['score']);
}

public function testUnusableReplyFallsBackToBatchScorePoolWithEmptyReasons(): void
{
    $run = $this->runWithWinners([['id' => 5, 'score' => 700, 'reason' => ''], ['id' => 6, 'score' => 400, 'reason' => '']]);
    $this->chat->willReply('not json');

    $outcome = $this->resolver->resolve($run, $this->settings, $this->userId, 50, $this->effectiveSettings);

    self::assertFalse($outcome->usable);
    self::assertSame([5, 6], array_map(static fn ($p) => $p['id'], $outcome->requireFallbackPool())); // batch order
}
```

- [ ] **Step 2: Run to verify failure**

Run: `cd backend && php bin/phpunit --filter RecommendationConsolidationResolverTest`
Expected: FAIL — class absent.

- [ ] **Step 3: Implement the outcome and resolver**

`ConsolidationOutcome`: `finalizeWith(array $ranked)` (usable), `unusable(string $reply, array $fallbackPool)`, `requireUnusableReply()`, `requireFallbackPool()`. `RecommendationConsolidationResolver::resolve()`:
1. `$pool = $this->ranker->cutForDedup($this->ranker->ranked($run->getWinners()), $picksLimit);` — top 100.
2. If `[] === $pool` → `ConsolidationOutcome::finalizeWith([])` (no call), mirroring the dedup all-pruned short-circuit.
3. `$linesById = $this->candidateLoader->linesForIds($userId, array_map(fn($w) => $w['id'], $pool));` and drop pruned (`stillPresent`, as dedup does).
4. `$history = $this->historyLoader->load($userId, $effectiveSettings);` (only its FAVORITES section is rendered).
5. `$messages = $this->promptBuilder->consolidationMessages($pool, $linesById, $history, $effectiveSettings, $run->getProfileText());` with the corrective tail (`CONSOLIDATION_CORRECTIVE`) via `messagesWithCorrectiveTail($messages, $run->getLastInvalidReply(), ...)`.
6. record (`PHASE_CONSOLIDATE`, null batch), `requestFactory->create($settings, $messages, count($pool), RecommendationResponseSchema::Consolidation)`, call, `parser->parse($content, $shownIds)`, settle, `checkpoint->guard`.
7. On usable: build `$ranked` = pool picks minus `duplicateIds`, replaced with the reply's `{id,score,reason}`, sorted by score desc (`usort`, stable). Return `finalizeWith($ranked)`.
8. On unusable: return `unusable($content, $pool)` (pool carries `reason:''` from batch winners — the degrade path).

Keep the method within PHPMD complexity limits: extract `rankedFromReply(array $pool, ConsolidationParseResult $result): array` and `stillPresent(...)` helpers rather than inlining.

- [ ] **Step 4: Run to verify passes + phpmd on the new file**

Run: `cd backend && php bin/phpunit --filter RecommendationConsolidationResolverTest && composer md && composer stan`
Expected: PASS, PHPMD clean.

- [ ] **Step 5: Commit**

```bash
git add backend/src backend/tests
git commit -m "feat(#493): consolidation phase resolver (re-score + reason + dedup in one call)"
```

---

## Task 12: Wire the advancer — distill, score-only batches, consolidate

The integration task: new phase dispatch, `distillTick`, `consolidateTick`, the batch wave passing the run's profile, and `pickEndingAfterWave` always handing to consolidation. Backed by a **functional** test of the whole pipeline (CLAUDE.md: functional over direct-invocation for wiring).

**Files:**
- Modify: `backend/src/Service/Recommendation/RecommendationRunAdvancer.php`, `backend/src/Service/Recommendation/RecommendationBatchWave.php` (accept + pass `$run->getProfileText()`)
- Test: `backend/tests/Functional/Recommendation/RecommendationPipelineTest.php` (existing functional recommendation test — extend, or create following the existing functional harness that stubs the provider)

**Interfaces:**
- Consumes: everything from Tasks 3–11.
- Produces: a run that advances `snapshot → distill → score-only batches → consolidate → finalize` across ticks.

- [ ] **Step 1: Write the failing functional test**

Using the existing functional harness that seeds a user with history + candidates and stubs the provider per phase (find the current recommendation functional test to copy its provider-stub wiring), assert one full run:

```php
public function testRunDistillsThenScoresThenConsolidates(): void
{
    $this->stubProvider()
        ->onDistill('{"profile":"Likes Rust."}')
        ->onBatchScore('{"recommendations":[{"id":%ID%,"score":800}]}')   // per candidate
        ->onConsolidate('{"recommendations":[{"id":%ID%,"score":900,"reason":"On Rust."}],"duplicates":[]}');

    $this->advanceUntilComplete($this->startRunFor($this->user));

    $items = $this->recommendationItemsFor($this->user);
    self::assertNotEmpty($items);
    self::assertSame('On Rust.', $items[0]->getReason());          // reason came from consolidation
    self::assertSame(900, $items[0]->getScore());                  // score is the consolidation score
    self::assertSame('Likes Rust.', $this->settingsProfileFor($this->user)); // cached on settings
    $this->assertProviderCalledPhases(['distill', 'batch', 'consolidate']);
}

public function testConsolidationRunsEvenWhenThereIsOneBatch(): void
{
    // small candidate pool => single batch; consolidation must still produce reasons
    $this->stubProvider()->onDistill('{"profile":"x"}')
        ->onBatchScore('{"recommendations":[{"id":%ID%,"score":700}]}')
        ->onConsolidate('{"recommendations":[{"id":%ID%,"score":700,"reason":"y"}],"duplicates":[]}');

    $this->advanceUntilComplete($this->startRunForSmallFeed($this->user));

    self::assertSame('y', $this->recommendationItemsFor($this->user)[0]->getReason());
}

public function testDistillationFailureDegradesToFavoritesOnlyBatches(): void
{
    $this->stubProvider()->onDistillAlwaysUnusable()
        ->onBatchScore('{"recommendations":[{"id":%ID%,"score":600}]}')
        ->onConsolidate('{"recommendations":[{"id":%ID%,"score":600,"reason":"z"}],"duplicates":[]}');

    $this->advanceUntilComplete($this->startRunFor($this->user));

    self::assertNotEmpty($this->recommendationItemsFor($this->user)); // run still completes
    self::assertNull($this->runProfileTextFor($this->user));          // no profile frozen on the run
}

public function testConsolidationFailureDegradesToBatchOrderEmptyReasons(): void
{
    $this->stubProvider()->onDistill('{"profile":"x"}')
        ->onBatchScore('{"recommendations":[{"id":%ID%,"score":500}]}')
        ->onConsolidateAlwaysUnusable();

    $this->advanceUntilComplete($this->startRunFor($this->user));

    $items = $this->recommendationItemsFor($this->user);
    self::assertNotEmpty($items);
    self::assertSame('', $items[0]->getReason());  // empty-string reason on degrade
}
```

(Adapt method names to the existing functional harness. The `%ID%` placeholder stands for a per-candidate stub; if the harness cannot vary by id, return a fixed usable reply covering the seeded candidate.)

- [ ] **Step 2: Run to verify failure**

Run: `cd backend && php bin/phpunit --filter RecommendationPipelineTest`
Expected: FAIL — advancer still runs the old dedup dispatch.

- [ ] **Step 3: Rewrite `tickActiveRun` dispatch**

```php
if (RecommendationRun::STATUS_PENDING === $run->getStatus()) {
    return $this->snapshotTick($run, $user);
}

if ($run->progress()->distillPending) {
    return $this->distillTick($run, $user, $settings);
}

if ($run->progress()->isConsolidationPhase) {
    return $this->consolidateTick($run, $user, $settings);
}

return $this->providerTick($run, $user, $settings, $driver);
```

- [ ] **Step 4: Add `distillTick`**

Mirror `dedupTick`'s retry/degrade structure. On a usable outcome call `$run->recordProfile($outcome->profileText)`; on an unusable outcome call `retryOrDegrade($run, $settings, $outcome->requireUnusableReply(), degrade: fn () => $run->recordProfile(null))`. After recording (either branch), `flush` and return `RecommendationRunReport::fromRun($run)` so the next tick proceeds to batches. Inject `RecommendationProfileDistiller` into the advancer constructor.

- [ ] **Step 5: Add `consolidateTick` and delete the dedup branch**

Replace `dedupTick` with `consolidateTick`: call `$this->consolidationResolver->resolve($run, $settings, $userId, $picksLimit, $effectiveSettings)`. On usable → `$this->finalizer->finalize($run, $outcome->ranked)`. On unusable → `retryOrDegrade(..., degrade: fn () => $this->finalizer->finalize($run, $outcome->requireFallbackPool()))`. Swap the injected `RecommendationDedupResolver` for `RecommendationConsolidationResolver`.

- [ ] **Step 6: Make `pickEndingAfterWave` always hand to consolidation**

Remove the `if (!$run->progress()->needsDedup) { return $this->finalizer->finalize(...); }` branch. After banking winners, always `flush` and `return RecommendationRunReport::fromRun($run)` — the next tick sees `isConsolidationPhase` (true for any batch count now) and runs consolidation.

- [ ] **Step 7: Pass the run's profile into the batch wave**

`RecommendationBatchWave::resolve()` already has `$run`; thread `$run->getProfileText()` into its private `batchMessages()` wrapper and on to `promptBuilder->batchMessages(..., profile: $profile, poolSummary: $poolSummary)`. (Signature: add `?string $profile` to the wrapper, sourced once in `resolve()`.)

- [ ] **Step 8: Run the functional suite to verify it passes**

Run: `cd backend && php bin/phpunit --filter RecommendationPipelineTest && composer stan`
Expected: PASS.

- [ ] **Step 9: Commit**

```bash
git add backend/src backend/tests
git commit -m "feat(#493): advance runs through distill, score-only batches, and consolidation"
```

---

## Task 13: Remove the dead ranking/dedup code

Deletes what the new pipeline superseded and clears the now-unused prompt strings, constants, and helpers so `composer md` and PHPStan see no dead weight.

**Files:**
- Delete: `RecommendationDedupResolver.php`, `DedupOutcome.php`, `RecommendationDuplicateParser.php`, `DuplicateParseResult.php` (and their tests)
- Modify: `RecommendationPromptText.php` (remove `SYSTEM_ROLE`, `OUTPUT_CONTRACT`, `CORRECTIVE`, `DEDUP_ROLE`, `DEDUP_OUTPUT_CONTRACT`, `DEDUP_CORRECTIVE` if unreferenced), `RecommendationPromptBuilder.php` (remove `dedupMessages`, `dedupSizeFrame`, `winnerLine` if consolidation reuses `candidateSection` instead, `answerTokenReserve`, `userSections`, `TOKENS_PER_PICK`/`TOKENS_PER_DUPLICATE_ID`/`DEDUP_DESCRIPTION_CHARS` if now unused), service wiring (`config/services.yaml` if the deleted services were declared explicitly)
- Test: remove the dead tests; run the whole recommendation suite

**Interfaces:** none produced; this is subtraction. Verify each deletion has zero references first.

- [ ] **Step 1: Prove each symbol is unreferenced**

Run (for every symbol you intend to delete):

```bash
cd backend && grep -rn "RecommendationDedupResolver\|DedupOutcome\|RecommendationDuplicateParser\|DuplicateParseResult\|dedupMessages\|answerTokenReserve\|DEDUP_ROLE\|::SYSTEM_ROLE\|::OUTPUT_CONTRACT\b" src tests config
```

Expected: only definitions and their own tests remain (no live callers). Anything still referenced stays until its caller is gone.

- [ ] **Step 2: Delete the files and dead members**

Remove the four files and their tests; delete the unreferenced constants/methods listed above. Keep `TOKENS_PER_PICK` (still used by consolidation's `answerBoundTokens` arm) — re-check with grep before removing any constant. Keep `PlausibleDuplicateShare` (consolidation parser uses it).

- [ ] **Step 3: Run the full backend gate**

Run: `cd backend && composer check && composer md && php bin/phpunit`
Expected: PASS — `cs + stan + tramp`, PHPMD clean, whole suite green. If PHPMD flags a file you touched but did not clean, fix the design (extract methods), do not adjust thresholds.

- [ ] **Step 4: Commit**

```bash
git add -A backend
git commit -m "refactor(#493): remove superseded ranking-reason and dedup code paths"
```

---

## Task 14: Whole-system verification

The pre-PR gate. No new behavior — proves the branch is green across every leg and leaves nothing broken.

**Files:** none (verification only).

- [ ] **Step 1: Backend gates (SQLite leg)**

Run: `cd backend && composer check && composer md && php bin/phpunit`
Expected: all green.

- [ ] **Step 2: Backend MySQL leg + migration parity**

Run:

```bash
docker compose exec php vendor/bin/phpunit
docker compose exec php bin/console doctrine:migrations:migrate --no-interaction
docker compose exec php bin/console doctrine:schema:validate
```

Expected: suite green on MySQL; migrations apply from current; schema validates. (Migrations are never executed by the test bootstrap — this leg is the only thing that proves them, per CLAUDE.md.)

- [ ] **Step 3: Mutation gate on the diff**

Run: `cd backend && composer infection:diff`
Expected: MSI at or above `infection.json5`'s `minMsi`. Kill escaped mutants with real assertions (especially in the parsers and the consolidation dedup/sort logic — mutating a `<=>` or a filter should fail a test). Never lower `minMsi`.

- [ ] **Step 4: Frontend gate**

Run: `cd frontend && npm run check`
Expected: green (ESLint + Prettier + Stylelint + Jest).

- [ ] **Step 5: Scan the dev log**

Run: `cd backend && tail -n 200 var/log/dev.log`
Expected: no new deprecations or swallowed errors from the recommendation phases after a manual/functional run.

- [ ] **Step 6: Open the PR**

```bash
git push -u origin feature/493-two-step-recommendation-profile
```

Create the PR into `develop` with body including `Closes #493` and a summary of the four-phase pipeline. After merge, verify #493 closed automatically (develop is the default branch).

---

## Self-Review

**Spec coverage** (issue #493 + comments):
- Profile step (distill history once) → Tasks 4, 7, 10, 12. ✅
- Send profile per batch, drop KEPT/VIEWED → Task 6. ✅ (hybrid: profile + FAVORITES)
- Fewer tokens per batch / larger batches → Task 5 (score-only reserve + favorites-only history budget). ✅
- Score-only batch replies `{id,score}` → Tasks 3, 6. ✅
- Consolidation over top-100 that re-scores, reasons, dedups (comment 2) → Tasks 7, 11, 12. ✅ (carries `profile + FAVORITES`, the same fidelity as the batches — Q6 correction: every post-distillation call speaks through the profile; only distillation sees full history.)
- `2 × picksLimit` cut kept → Task 11 (reuses `cutForDedup`). ✅
- Consolidation runs unconditionally, even single-batch → Tasks 9, 12. ✅
- Profile persisted per-user for settings/debug display, refreshed per run → Tasks 1, 2, 10. ✅
- Read-only profile in expert settings → Task 2. ✅
- Strict `json_schema` per phase (#329) + `reasoning_content` recovery (#323) → inherited via `RecommendationCompletionRequestFactory` + `OpenAiCompatibleChatClient` (Global Constraints; Tasks 10, 11 call the factory). ✅
- Degrade never fails: distillation → favorites-only batches; consolidation → batch order, empty reasons → Tasks 12 (both functional-tested). ✅
- Dedup phase otherwise unchanged in spirit (folded into consolidation) → Tasks 11, 13. ✅

**Placeholder scan:** no `TBD`/`add error handling`/`similar to Task N` — degrade paths, parser rules, and math are spelled out with code. The `%ID%` token in Task 12 is explicitly defined as a stub placeholder to adapt to the harness, not a gap in the plan.

**Type consistency:** the winner shape `array{id:int,score:int,reason:string}` is preserved end-to-end (batches set `reason:''`); `RecommendationPick->{entryId,score,reason}` matches the parser; `forBatchPlan(...,bool $distilled)` is used consistently in `RecommendationRun::progress()` (Task 8/9) and the advancer (Task 12); `isConsolidationPhase`/`distillPending` replace `isDedupPhase`/`needsDedup` everywhere they were read (Tasks 9, 12). `RecommendationResponseSchema::{Distillation,BatchScore,Consolidation}` names are used identically in the schema, request factory calls, and `answerBoundTokens` arms.

One flagged risk carried from grilling: the consolidation call reasons over all 100 (≈3,500 output tokens on the ~50 later deduped) — an accepted cost of the single-call design, not a defect.
