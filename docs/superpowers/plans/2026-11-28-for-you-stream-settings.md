# For you stream settings Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Give the reader an on/off switch, a read-only view of the AI's weighted phrases, and a five-step
precision control for the For you stream, in Settings → AI → Recommendations.

**Architecture:** Two settings (`enabled`, `precision`) live in a new embeddable on `RecommendationSettings`.
The precision enum maps each step to a minimum score. The sweep reads that score per account instead of
`ForYouStreamScorer::MIN_SCORE`. Disabled accounts are skipped by the term extraction and the sidebar tally, and
the sweep fast-forwards their marks. The AI's phrases are stored as JSON on `interest_term_set` next to the
stems. Settings and phrases travel through the existing `GET`/`PUT /api/me/ai/recommendations` pair. The
frontend gets a new settings group, and its precision control is a shared stepper taken out of the sidebar
brightness control.

**Tech Stack:** Symfony 8.1 / Doctrine ORM (PHP 8.5), MySQL + SQLite migrations, Angular 20 standalone + signals,
Transloco (de/en/fr/es), Jest.

**Spec:** `docs/superpowers/specs/2026-11-28-for-you-stream-settings-design.md` (issue #2594)

## Global Constraints

- Precision steps, narrow → wide, exact minimum scores: **4.0, 3.0, 2.0, 1.5, 1.0**. The default is the 4th step
  (1.5, today's `MIN_SCORE`).
- `forYouStreamEnabled` defaults to **true**. With no settings row, the account is enabled at the default precision.
- Disabling deletes nothing: the stream entries, the term set, its stems and its phrases all stay.
- No backfill. Term sets that already exist show no phrases until their next refresh. A re-enable does not
  re-match entries from the off period.
- No precision or list change rescores the stream. Changes apply only to entries swept afterwards.
- JSON in, `application/problem+json` out, nothing browser-coupled (iOS constraint, architecture §6).
- Every migration must run on SQLite **and** MySQL (`abortIfUnsupportedPlatform` pattern, see
  `migrations/Version20261127150000.php`).
- Copy is in de/en/fr/es: French *vous*, Spanish *tú*, glossary
  `docs/superpowers/plans/2026-11-13-2205-french-spanish-translations.md`.
- Commits look like `type(#2594): lower-case summary`, with no attribution lines.
- CLAUDE.md naming verbs and folder roles apply: no `get…` on non-entities, no `of…` names, `final readonly`,
  and no comment unless a reader would otherwise get the code wrong.
- Use asymmetric test values throughout, for example two accounts with different precisions and a disabled account
  next to an enabled one. Every new guard needs a test that fails when the guard is removed.

### Per-task verification (Gate cadence, #2525)

- Backend: `cd backend && php bin/phpunit <test files of the task>`, then
  `vendor/bin/phpcs <changed files>` (or `composer cs`) and `composer stan`. Run
  `bin/console cache:warmup` once first if the dev cache is cold. Run no full test legs or infection here.
- Frontend: **the Docker stack bind-mounts the main checkout, not this worktree**, so frontend Jest does
  not run in the container here. Run `cd frontend && npm ci` once per worktree. After that, run each spec with
  `./node_modules/.bin/jest --testPathIgnorePatterns "/node_modules/" "/e2e/" <spec path>`.
  The worktree path has no `+`, so this works.
- The full gate set (cs, stan, md, tramp, both test legs, `infection:diff`, PhpStorm lint, `npm run check`,
  `npm run build`, migrate-from-empty on both dialects) runs **once before the PR**, not per task.

## Decisions taken while planning (spec left them open)

- **Re-enable and the sweep mark.** The 48 h window alone is not enough. `ForYouStreamSweep::raiseLaggards`
  lifts a stale mark only to the first entry published in the last 48 h, and
  `ForYouStreamSourceRepository::findCandidates` keeps `effectiveDate >= now − 48h`. So re-enabling after a long
  pause would still match the last 48 h of the off period, which is a backfill. **The fix: on every run the sweep
  moves each disabled account's mark to the settled ceiling without matching.** After a re-enable, matching
  resumes from the last sweep's ceiling. Only entries that arrived since that sweep get matched, which is the
  normal one-run lag.
- **Rarity refresh keeps running for disabled accounts.** It costs no AI call, and it keeps the stems current for a
  re-enable. Only the AI extraction is skipped.
- **Phrases** are stored in a nullable JSON column `interest_term_set.phrases`. Null means "never stored" (rows
  from before this change) and reads as an empty list. `InterestTermModel` moves to `App\Entity\InterestPhrase`,
  because the entity now stores it (the "shared values have one home" rule).
- **Wire shape.** The response gets `forYouStream: {enabled, precision, defaultPrecision, phrases: [{language,
  phrases: [{phrase, weight}]}]}`. Languages come in `FeedLanguage::cases()` order. Within a language, phrases go
  by weight descending, then by phrase ascending. The request gets an optional nested `forYouStream: {enabled,
  precision}`. **When it is absent, the stored values are kept**, so an older client cannot quietly re-enable a
  disabled stream.
- **Precision control.** Lars asked for the shared stepper from the brightness control, not a select. The left
  end (`zoom_in_map`) is narrower and the right end (`zoom_out_map`) is wider. The fill grows towards wide.
  Clicking the bar resets to `wide` (step 4). The readout names the step label. The spec doc's line about the
  control is updated in the plan commit.
- **Enum values:** `narrowest`, `narrow`, `balanced`, `wide`, `widest`, in a `VARCHAR(10)` column, default `'wide'`.

## Review Focus

1. **Re-enable after weeks off.** The stream must not suddenly fill with the off period's entries. Pinned in
   Task 3 (`testADisabledAccountsMarkMovesToTheCeilingUnmatched`).
2. **PUT without `forYouStream`.** An older body must keep the stored toggle and precision, not reset them to
   defaults. Pinned in Task 4 (`testAPutWithoutForYouStreamKeepsTheStoredSettings`).
3. **An account with no settings row** must still stream, at 1.5. Pinned in Task 3
   (`testAnAccountWithoutSettingsRowMatchesAtTheDefaultPrecision`).
4. **Score exactly on the threshold** is a match (`>=`), at every step, not only at 1.5. Pinned in Task 3's scorer
   test.
5. **A term set stored before this change** (phrases NULL) must read as an empty list, not 500. Pinned in Task 2
   (`testATermSetWithoutStoredPhrasesReadsAsEmpty`) and Task 4 (empty `phrases` in the GET).

---

### Task 1: Stream settings on the settings row

**Files:**
- Create: `backend/src/Enum/ForYouStreamPrecision.php`
- Create: `backend/src/Entity/ForYouStreamTuning.php` (embeddable)
- Modify: `backend/src/Entity/RecommendationSettings.php`, `backend/src/Entity/RecommendationSettingsValues.php`,
  `backend/src/Service/Curation/Recommendation/Settings/RecommendationSettingsWriter.php`
- Create: `backend/migrations/Version20261128100000.php`
- Test: `backend/tests/Enum/ForYouStreamPrecisionTest.php`,
  `backend/tests/Service/Curation/Recommendation/Settings/RecommendationSettingsWriterTest.php` (extend if it exists)

**Interfaces:**
- Produces:
  - `enum ForYouStreamPrecision: string` with cases `Narrowest='narrowest'`, `Narrow='narrow'`, `Balanced='balanced'`,
    `Wide='wide'`, `Widest='widest'` in that order, `public const self DEFAULT = self::Wide;`, and
    `public function minimumScore(): float` (4.0 / 3.0 / 2.0 / 1.5 / 1.0).
  - `#[ORM\Embeddable] final class ForYouStreamTuning`: `__construct(bool $enabled, ForYouStreamPrecision $precision)`,
    `static defaults(): self`, `isEnabled(): bool`, `getPrecision(): ForYouStreamPrecision`. The columns are
    `for_you_stream_enabled` (default true) and `for_you_stream_precision` (length 10,
    `enumType: ForYouStreamPrecision::class`, default `'wide'`).
  - `RecommendationSettings`: embedded `forYouStream` (`columnPrefix: false`), initialised to `ForYouStreamTuning::defaults()`,
    and `getForYouStreamTuning(): ForYouStreamTuning`. `update()` assigns `$values->forYouStream` **only when it is
    non-null**.
  - `RecommendationSettingsValues`: a new last parameter `public ?ForYouStreamTuning $forYouStream = null`, where
    null means "keep the stored value".

- [ ] **Step 1: Write the failing tests**

```php
// ForYouStreamPrecisionTest
public function testTheStepsNarrowToWideCarryTheirMinimumScores(): void
{
    self::assertSame(
        [4.0, 3.0, 2.0, 1.5, 1.0],
        array_map(static fn (ForYouStreamPrecision $precision): float => $precision->minimumScore(), ForYouStreamPrecision::cases()),
    );
    self::assertSame(ForYouStreamPrecision::Wide, ForYouStreamPrecision::DEFAULT);
}

// RecommendationSettingsWriterTest (KernelTestCase, real DB)
public function testSavingStoresTheStreamTuningThroughTheGuidanceNormalisation(): void
// save() with guidancePrompt '   ' and forYouStream new ForYouStreamTuning(false, Narrow);
// reload → guidancePrompt null, isEnabled() false, getPrecision() Narrow
public function testSavingWithoutStreamTuningKeepsTheStoredOne(): void
// first save (false, Balanced), then save with forYouStream null → still (false, Balanced)
public function testANewRowStreamsAtTheDefaultPrecision(): void
// new RecommendationSettings($user)->getForYouStreamTuning() → enabled true, Wide
```

- [ ] **Step 2: Run them.** Run `php bin/phpunit tests/Enum/ForYouStreamPrecisionTest.php tests/Service/Curation/Recommendation/Settings/RecommendationSettingsWriterTest.php`. Expected: FAIL, class `ForYouStreamPrecision` not found.

- [ ] **Step 3: Implement the enum, the embeddable, the entity wiring and the Values parameter.** `withNormalisedGuidance` must forward `forYouStream`. The first writer test fails without that.

- [ ] **Step 4: Write the migration `Version20261128100000`** with the description `'For you stream switch and precision on the recommendation settings (#2594).'`.
  It targets table `user_recommendation_settings` and skips when `for_you_stream_enabled` already exists. MySQL:
  `ADD for_you_stream_enabled TINYINT(1) DEFAULT 1 NOT NULL, ADD for_you_stream_precision VARCHAR(10) DEFAULT 'wide' NOT NULL`.
  SQLite: two `ALTER TABLE … ADD COLUMN` (`BOOLEAN DEFAULT 1 NOT NULL`, `VARCHAR(10) DEFAULT 'wide' NOT NULL`).
  `down` drops both, using one statement per column on SQLite. Verify it on SQLite:
  `DATABASE_URL=sqlite:///%kernel.project_dir%/var/migrate-check.db bin/console doctrine:migrations:migrate -n && bin/console doctrine:schema:validate`
  on a scratch file. Expected: `[OK] The database schema is in sync with the mapping files.` The MySQL check runs
  in the pre-PR migrate leg.

- [ ] **Step 5: Run the tests, then cs and stan on the changed files.** Expected: PASS and clean.

- [ ] **Step 6: Commit** `feat(#2594): store the For you stream switch and precision on the settings row`

---

### Task 2: Store the AI's phrases with the term set

**Files:**
- Create: `backend/src/Entity/InterestPhrase.php` (replaces `Service/Curation/Recommendation/Stream/Model/InterestTermModel.php`, delete that)
- Create: `backend/src/Service/Curation/Recommendation/Stream/Model/ExtractedTermsModel.php`
- Modify: `Stream/Model/InterestTermOutcomeModel.php`, `Stream/InterestTermStems.php`, `Stream/InterestTermStore.php`,
  `Stream/InterestTermRefresh.php`, `Llm/Run/LlmInterestTermExtractor.php`, `Llm/Prompt/InterestTermParser.php`,
  `backend/src/Entity/InterestTermSet.php`
- Create: `backend/migrations/Version20261128110000.php`
- Test: `tests/Service/Curation/Recommendation/Stream/InterestTermStoreTest.php`, `InterestTermStemsTest.php`,
  `InterestTermRefreshTest.php`, `InterestTermRefreshExtractorFailureTest.php`, `tests/Service/Curation/Recommendation/Llm/Prompt/InterestTermParserTest.php`
  (update them for the rename)

**Interfaces:**
- Produces:
  - `final readonly class App\Entity\InterestPhrase(FeedLanguage $language, string $phrase, float $weight)`, plus
    `toStoredArray(): array{language: string, phrase: string, weight: float}` and
    `static fromStoredArray(array $stored): self`.
  - `final readonly class ExtractedTermsModel(list<InterestPhrase> $phrases, list<TermStemModel> $stems)`.
  - `InterestTermOutcomeModel::usable(ExtractedTermsModel $extracted)`. The property `?ExtractedTermsModel $extracted`
    replaces `$stems`.
  - `InterestTermStore::replaceTerms(User $user, \DateTimeImmutable $profileGeneratedAt, ExtractedTermsModel $extracted): InterestTermSet`.
  - `InterestTermSet`: nullable `Types::JSON` column `phrases`, `recordPhrases(list<InterestPhrase> $phrases): void`,
    and `getPhrases(): list<InterestPhrase>`, which returns `[]` when the column is null.

- [ ] **Step 1: Write the failing tests**

```php
// InterestTermStoreTest
public function testReplacingTermsStoresThePhrasesWithTheStems(): void
// replaceTerms with phrases [en 'renewable energy' 0.9, de 'Wärmepumpe' 0.6] and two stems
// → findForUser()->getPhrases() equals those two, in the same order, with weight 0.6 still 0.6
public function testASecondReplaceSwapsThePhrasesWholesale(): void
// replace with [en 'a' 1.0, en 'b' 0.5] then with [fr 'vélo' 0.7] → only [fr 'vélo' 0.7]
public function testATermSetWithoutStoredPhrasesReadsAsEmpty(): void
// new InterestTermSet(...) persisted without recordPhrases, reloaded → getPhrases() === []

// InterestTermRefreshTest: the existing Stored path asserts that the stub extractor's phrases arrive in the set
```

- [ ] **Step 2: Run them.** Expected: FAIL (`InterestPhrase` not found).

- [ ] **Step 3: Implement.** `LlmInterestTermExtractor` keeps the parsed phrases and builds `ExtractedTermsModel($phrases, $stems)`.
  It stays unusable when `$stems === []`. `InterestTermStore` calls `$set->recordPhrases($extracted->phrases)` on
  both the create path and the replace path. Rename every use of `InterestTermModel` to `InterestPhrase`.

- [ ] **Step 4: Write the migration `Version20261128110000`** with the description `'The AI phrases behind the interest terms (#2594).'`.
  It adds `interest_term_set.phrases`: on MySQL `JSON DEFAULT NULL`, on SQLite `CLOB DEFAULT NULL`
  (precedent: `Version20261115140200`). It skips when the column exists, and `down` drops it. Run the same
  SQLite migrate-and-validate check as in Task 1.

- [ ] **Step 5: Run the tests, cs and stan.** Expected: PASS and clean.

- [ ] **Step 6: Commit** `feat(#2594): store the AI's phrases with the interest term set`

---

### Task 3: Sweep and extraction honour the settings

**Files:**
- Modify: `Stream/ForYouStreamScorer.php` (drop `MIN_SCORE`), `Stream/ForYouStreamChunkMatcher.php`,
  `Stream/Pass/StreamSweepPass.php`, `Stream/Pass/ForYouStreamMarkGroupWalk.php`, `Stream/ForYouStreamSweep.php`,
  `backend/src/Repository/InterestTermSetRepository.php`, `backend/src/Repository/RecommendationSettingsRepository.php`
- Test: `tests/Service/Curation/Recommendation/Stream/ForYouStreamScorerTest.php`, `ForYouStreamSweepTest.php`,
  `DueInterestTermFinderTest.php`, `tests/Repository/InterestTermSetRepositoryTest.php` (create),
  `tests/Repository/RecommendationSettingsRepositoryTest.php` (create or extend)

**Interfaces:**
- Consumes: `ForYouStreamPrecision::minimumScore()`, `ForYouStreamTuning` (Task 1).
- Produces:
  - `ForYouStreamScorer::findMatch(InterestTermsModel $terms, EntryStemsModel $stems, float $minimumScore): ?StreamScoreModel`.
  - `StreamSweepPass::join(array $termsByUserId, array $precisionsByUserId): void`, where the second argument is
    `array<int, ForYouStreamPrecision>`. `StreamSweepPass::readMinimumScore(int $userId): float` falls back to
    `ForYouStreamPrecision::DEFAULT` when the user has no entry.
  - `RecommendationSettingsRepository::findStreamPrecisions(list<int> $userIds): array<int, ForYouStreamPrecision>`
    returns only the users that have a row.
  - `InterestTermSetRepository::findBelowMark(...)` now excludes disabled accounts. It uses a LEFT JOIN
    on `RecommendationSettings` by user, `settings.id IS NULL OR settings.forYouStream.enabled = true`.
  - `InterestTermSetRepository::findDisabledBelowMark(int $ceiling, \DateTimeImmutable $now): list<int>` returns
    the active accounts whose setting is off and whose mark is below the ceiling.
  - `RecommendationSettingsRepository::findWithProfileNewerThanInterestTerms()` adds `s.forYouStream.enabled = true`.
  - `ForYouStreamSweep::sweep()` calls `$this->sets->advanceMarks($this->sets->findDisabledBelowMark($ceiling, $now), $ceiling)`
    before it groups the enabled marks. `ForYouStreamMarkGroupWalk::joinGroup()` joins the terms with
    `findStreamPrecisions($group)`. The walk receives `RecommendationSettingsRepository` through its constructor,
    passed from the sweep.

- [ ] **Step 1: Write the failing tests**

```php
// ForYouStreamScorerTest (data provider over all five cases)
public function testAScoreOnTheThresholdMatchesAndJustBelowDoesNot(ForYouStreamPrecision $precision): void
// terms strength x = minimumScore → match with score minimumScore; x = minimumScore - 0.01 → null

// ForYouStreamSweepTest (KernelTestCase, fixtures as the existing tests build them)
public function testEachAccountMatchesAtItsOwnPrecision(): void
// entry scoring 2.5 for both A (Balanced, 2.0) and B (Narrow, 3.0) → stream row for A only
public function testAnAccountWithoutSettingsRowMatchesAtTheDefaultPrecision(): void
// no settings row, entry scoring 1.6 → in stream; a second account at Narrowest with the same entry → not
public function testADisabledAccountIsNotMatchedAndKeepsItsEntries(): void
// disabled account with an existing for_you_stream_entry row and a fresh matching entry
// → no new row, the old row still there, interest_term rows still there
public function testADisabledAccountsMarkMovesToTheCeilingUnmatched(): void
// disabled, mark 0, entries published 1h ago → after sweep matchedUpToEntryId() == settled ceiling, no rows;
// then enable and add one newer matching entry → the next sweep adds exactly that entry

// DueInterestTermFinderTest
public function testADisabledAccountIsNeverDueForTerms(): void
// two accounts with newer profiles and usable connections, one disabled → findDue() holds only the enabled one
```

- [ ] **Step 2: Run them.** Expected: FAIL (signature mismatch / disabled rows matched).

- [ ] **Step 3: Implement.** `ForYouStreamChunkMatcher` passes `$pass->readMinimumScore($userId)` to the scorer.
  This keeps the score off the phptramp chain, because the pass holds it as a field. The repository queries stay in
  `src/Repository` (`QueriesLiveInRepositoriesRule`).

- [ ] **Step 4: Run the tests above, plus `ForYouStreamSourceRepositoryTest` and `InterestTermSweepTest`, then cs, stan, and `composer tramp`.** Expected: PASS, and no new tramp warning.

- [ ] **Step 5: Commit** `feat(#2594): the stream sweep and term extraction honour the account's switch and precision`

---

### Task 4: Settings endpoint reads and writes the stream settings and phrases

**Files:**
- Create: `backend/src/Dto/Recommendation/SaveForYouStreamRequest.php`
- Modify: `backend/src/Dto/Recommendation/SaveRecommendationSettingsRequest.php`
- Create: `backend/src/Service/Curation/Recommendation/Stream/ForYouStreamSettingsReader.php`,
  `Stream/Model/ForYouStreamStateModel.php`
- Create: `backend/src/Http/ForYouStreamSettingsJson.php`
- Modify: `backend/src/Http/RecommendationSettingsJson.php`, `backend/src/Controller/Api/RecommendationSettingsController.php`
- Test: `tests/Controller/Api/RecommendationSettingsControllerTest.php`, `tests/Http/RecommendationSettingsJsonTest.php`,
  `tests/Http/ForYouStreamSettingsJsonTest.php`

**Interfaces:**
- Consumes: `ForYouStreamTuning`, `InterestTermSet::getPhrases()`, `InterestPhrase` (Tasks 1–2).
- Produces:
  - `final readonly class SaveForYouStreamRequest(bool $enabled, ForYouStreamPrecision $precision)` and
    `toTuning(): ForYouStreamTuning`.
  - `SaveRecommendationSettingsRequest`: a new last parameter `#[Assert\Valid] public ?SaveForYouStreamRequest $forYouStream = null`.
    `values()` passes `$this->forYouStream?->toTuning()`.
  - `final readonly class ForYouStreamStateModel(ForYouStreamTuning $tuning, list<InterestPhrase> $phrases)`.
  - `ForYouStreamSettingsReader::readState(User $user): ForYouStreamStateModel` reads the row's tuning, or
    `ForYouStreamTuning::defaults()` when there is no row, plus the term set's phrases, or `[]` when there is no set.
  - `ForYouStreamSettingsJson::state(ForYouStreamStateModel $state): array{enabled: bool, precision: string, defaultPrecision: string, phrases: list<array{language: string, phrases: list<array{phrase: string, weight: float}>}>}`
    has languages in `FeedLanguage::cases()` order, leaves out empty languages, and sorts by weight descending,
    then phrase ascending.
  - `RecommendationSettingsJson::state(…, bool $workerAlive, ForYouStreamStateModel $forYouStream)` adds the
    `'forYouStream'` key. The fourth parameter is a deliberate exception: `Settings` cannot own a bundling reader,
    because `Run → Settings` already exists and `Settings → Run` would make a module cycle.

- [ ] **Step 1: Write the failing tests**

```php
// ForYouStreamSettingsJsonTest
public function testPhrasesGroupByLanguageInCaseOrderStrongestFirst(): void
// phrases [fr 'vélo' 0.4, de 'Wärme' 0.5, de 'Akku' 0.9, de 'Bahn' 0.9] →
// [['language'=>'de','phrases'=>[['phrase'=>'Akku','weight'=>0.9],['phrase'=>'Bahn','weight'=>0.9],['phrase'=>'Wärme','weight'=>0.5]]],
//  ['language'=>'fr','phrases'=>[['phrase'=>'vélo','weight'=>0.4]]]]
// (FeedLanguage::cases() order is de, en, fr, es)

// RecommendationSettingsControllerTest
public function testShowCarriesTheStreamDefaultsAndAnEmptyListWithoutTermSet(): void
// GET → forYouStream == ['enabled'=>true,'precision'=>'wide','defaultPrecision'=>'wide','phrases'=>[]]
public function testPutStoresTheStreamSwitchAndPrecision(): void
// PUT full body + forYouStream {enabled:false, precision:'narrow'} → 200, response enabled false/narrow; GET agrees
public function testAPutWithoutForYouStreamKeepsTheStoredSettings(): void
// PUT {…, forYouStream:{enabled:false, precision:'balanced'}}, then PUT the body without forYouStream → still false/balanced
public function testAPutWithAnUnknownPrecisionIsRejected(): void
// precision 'sharpest' → Response::HTTP_UNPROCESSABLE_ENTITY, application/problem+json
public function testShowListsTheStoredPhrases(): void
// term set with phrases [en 'heat pumps' 0.8] → forYouStream.phrases == [['language'=>'en','phrases'=>[['phrase'=>'heat pumps','weight'=>0.8]]]]
```

- [ ] **Step 2: Run them.** Expected: FAIL (no `forYouStream` key).

- [ ] **Step 3: Implement.** The controller injects `ForYouStreamSettingsReader` and passes `readState($user)` in both actions. The controller stays thin, so `ThinControllerRule` passes.

- [ ] **Step 4: Run the tests, cs and stan.** Expected: PASS and clean.

- [ ] **Step 5: Commit** `feat(#2594): the recommendation settings endpoint carries the For you stream settings and phrases`

---

### Task 5: Sidebar hides the stream when it is off

**Files:**
- Modify: `backend/src/Repository/InterestTermSetRepository.php` (`hasForUser` → `hasEnabledStreamForUser`),
  `backend/src/Service/Library/Subscription/SubscriptionTallyReader.php`
- Test: `tests/Controller/Api/SubscriptionControllerTest.php`

**Interfaces:**
- Produces: `InterestTermSetRepository::hasEnabledStreamForUser(int $userId): bool`, which is true when a term set
  exists **and** the account has no settings row or its stream is enabled. `hasForUser` has no other callers, so
  remove it.

- [ ] **Step 1: Write the failing test**

```php
public function testTheCountsPayloadMarksADisabledStreamUnavailable(): void
// term set + 3 stream entries (2 unread), settings forYouStream disabled
// → $counts['forYouStream'] === ['available' => false, 'unreadCount' => 0, 'entryCount' => 0]
// while testTheCountsPayloadCarriesTheStreamTally (no settings row) still sees available true, 2, 3
```

- [ ] **Step 2: Run the test.** Expected: FAIL (`available` true).
- [ ] **Step 3: Implement.** `countStream` calls `hasEnabledStreamForUser`.
- [ ] **Step 4: Run the test, plus `tests/Http/SubscriptionCountsJsonTest.php`, then cs and stan.** Expected: PASS.
- [ ] **Step 5: Commit** `feat(#2594): a disabled For you stream leaves the sidebar`

---

### Task 6: Shared stepper, taken out of the brightness control

**Files:**
- Create: `frontend/src/app/shared/stepper-bar/stepper-bar.component.{ts,html,scss,spec.ts}`
- Modify: `frontend/src/app/reader/shell/sidebar/brightness-control.component.{ts,html,scss}`
- Modify: `docs/design-language.md` (§2 component catalog: add `### <app-stepper-bar>` after `<app-segmented-choice>`)
- Test: `frontend/src/app/reader/shell/sidebar/brightness-control.component.spec.ts` (**unchanged, must stay green**)

**Interfaces:**
- Produces: `StepperBarComponent`, selector `app-stepper-bar`, `OnPush`. It knows no translation keys, so all
  text arrives already translated. Inputs, all `input.required` unless a default is given:
  `step: number`, `min: number`, `max: number`, `decreaseIcon: string`, `increaseIcon: string`,
  `groupLabel: string`, `decreaseLabel: string`, `increaseLabel: string`, `resetLabel: string`, `readout: string`,
  `decreaseClass = input('decrease')`, `increaseClass = input('increase')`. Outputs: `decrease`, `increase`,
  `reset` (`output<void>()`). `fillPercent = computed((step − min) / (max − min) × 100)`.
  The DOM is exactly today's brightness DOM: `.seg[role=group]`, `button.end.<decreaseClass>`, `button.bar > .track > .fill`,
  `button.end.<increaseClass>`, `output.sr-only[aria-live=polite]`. The end buttons are disabled at min/max. The
  SCSS moves `segmented.frame` and `segmented.stepper-bar` here, and `:host` stays `display: flex`, sized so the
  sidebar renders the same.
- `BrightnessControlComponent` keeps its selector and its `fillPercent`/`signedStep` behaviour. Its template is a
  single `<app-stepper-bar>` that passes `dark_mode`/`light_mode`, `decreaseClass="darker"`,
  `increaseClass="brighter"`, the translated `reader.brightness.*` labels, and the readout (`readoutDefault` at
  step 0, else `readout` with the signed value).

- [ ] **Step 1: Write the failing spec** `stepper-bar.component.spec.ts` against a host component.

```ts
it('fills a quarter at step 2 of 1..5', ...)            // .fill inline-size 25
it('emits decrease, increase and reset from moon end, sun end and bar', ...)
it('disables the decrease end at min and the increase end at max', ...)
it('renders the given icons, labels, classes and readout', ...)
// decreaseIcon 'zoom_in_map', decreaseClass 'narrower' → '.narrower' textContent contains 'zoom_in_map',
// title === decreaseLabel; output text === readout
```

- [ ] **Step 2: Run** `./node_modules/.bin/jest --testPathIgnorePatterns "/node_modules/" "/e2e/" src/app/shared/stepper-bar`. Expected: FAIL (module not found).
- [ ] **Step 3: Implement** the shared component, then reduce `brightness-control` to the wrapper.
- [ ] **Step 4: Run the stepper spec and the unchanged `brightness-control.component.spec.ts`.** Expected: both PASS.
- [ ] **Step 5: Do a live check of the render.** Compare the sidebar brightness control at 1440 and 390 widths
  before and after, in the built-in browser on the dev server. Pixel-identical layout means done.
- [ ] **Step 6: Write the design-language catalog entry.** Cover the purpose (a stepped dial: two end buttons, a
  bar that resets), the input/output table as above, the rule that callers pass translated text, and the two users
  (brightness, For you precision).
  Note that `text-size-control` is the same pattern with a visible value in the bar, and has not been migrated.
- [ ] **Step 7: Commit** `refactor(#2594): take the stepper bar out of the brightness control into shared`

---

### Task 7: The For you stream group in the recommendations card

**Files:**
- Modify: `frontend/src/app/settings/recommendations/recommendation-settings.service.ts`
  (`RecommendationSettingsState`, `SaveRecommendationSettings`, `bodyFromState`, new `saveForYouStream`)
- Modify: `frontend/src/app/settings/recommendations/recommendation-settings-card.component.{ts,html,scss,spec.ts}`
- Modify: `frontend/src/app/settings/recommendations/recommendation-settings.service.spec.ts`
- Modify: `frontend/public/i18n/{en,de,fr,es}.json`

**Interfaces:**
- Consumes: the Task 4 wire shape and the Task 6 `StepperBarComponent`.
- Produces:
  - `export const FOR_YOU_STREAM_PRECISIONS = ['narrowest', 'narrow', 'balanced', 'wide', 'widest'] as const;`,
    `export type ForYouStreamPrecision = (typeof FOR_YOU_STREAM_PRECISIONS)[number];`
  - `interface ForYouStreamSettings { enabled: boolean; precision: ForYouStreamPrecision }`.
    The state's `forYouStream` adds `defaultPrecision` and
    `phrases: readonly { language: string; phrases: readonly { phrase: string; weight: number }[] }[]`.
  - `SaveRecommendationSettings.forYouStream: ForYouStreamSettings`. `bodyFromState` always sends it.
  - `RecommendationSettingsService.saveForYouStream(settings: ForYouStreamSettings): void` PUTs
    `{...bodyFromState, forYouStream}`. On success it sets the state, sets `saved`, and calls
    `this.subscriptions.reloadCountsQuietly()`, so the sidebar entry appears or disappears at once.
- The group goes after the "For You" group:
  `<app-settings-group icon="stream" [title]="…forYouStream.title" [caption]="…forYouStream.caption">`.
  - The row `data-testid="for-you-stream-enabled"` has a toggle and an `app-info-tip rowTitleTip` with
    `settings.ai.info.forYouStream`.
  - When enabled:
    - A row `data-testid="for-you-stream-precision"` with `<app-stepper-bar>`. Step = index + 1, min 1, max 5,
      `zoom_in_map` / `zoom_out_map`, classes `narrower`/`wider`. Decrease and increase move one step. Reset goes
      to `defaultPrecision`. The readout is `…precisionReadout` with `{label}`. Each move saves through
      `saveForYouStream`.
    - A read-only word list `data-testid="for-you-stream-phrases"`: one block per language, headed by the language
      name (reuse the existing language-name keys if a feed-language label set exists, else
      `…forYouStream.language.<code>`), with each phrase and its weight to one decimal. The empty state is
      `…phrasesEmpty`. The list follows design-language row density and uses no hex colours or px literals.

- [ ] **Step 1: Write the failing specs**

```ts
// service spec
it('sends forYouStream in every body built from state', ...)    // saveInstant({debugEnabled:true}) body.forYouStream == state's {enabled, precision}
it('reloads the sidebar counts after a stream save', ...)       // saveForYouStream → reloadCountsQuietly called once after the PUT flushes, not before
// card spec
it('hides precision and word list while the stream is off', ...)
it('saves the switch off through saveForYouStream', ...)        // {enabled:false, precision:'balanced'} keeps the precision
it('steps wider from balanced to wide and narrower to narrow', ...)
it('resets to the default precision from the bar', ...)         // from 'narrowest' → 'wide'
it('fills the bar towards wide', ...)                           // 'narrow' → 25 %, 'widest' → 100 %
it('lists phrases per language, strongest first as served', ...)// two languages, weight 0.85 shown '0.9'
it('shows the empty state without phrases', ...)
```

- [ ] **Step 2: Run** the two specs with the worktree Jest command. Expected: FAIL.
- [ ] **Step 3: Implement** the service, the card and the template.
- [ ] **Step 4: Add the i18n keys under `settings.ai.recommendations.forYouStream`, in all four languages, plus `settings.ai.info.forYouStream`.**

| key | en | de |
|---|---|---|
| `title` | For you stream | For-you-Stream |
| `caption` | Entries from your feeds that match your interests, as they arrive. | Einträge aus deinen Feeds, die zu deinen Interessen passen, sobald sie ankommen. |
| `enabled` | Show the For you stream | For-you-Stream anzeigen |
| `precision` | Precision | Genauigkeit |
| `narrower` / `wider` | Narrower / Wider | Enger / Weiter |
| `resetPrecision` | Reset precision | Genauigkeit zurücksetzen |
| `precisionReadout` | Precision: {{label}} | Genauigkeit: {{label}} |
| `precisionLabel.narrowest…widest` | Narrowest, Narrow, Balanced, Wide, Widest | Am engsten, Eng, Ausgewogen, Weit, Am weitesten |
| `phrases` | Words the AI picked | Von der KI gewählte Begriffe |
| `phrasesEmpty` | No word list yet. It appears after your next profile update. | Noch keine Begriffsliste. Sie erscheint nach der nächsten Profilaktualisierung. |

`settings.ai.info.forYouStream` (en): "The AI turns your profile into weighted words for each language. Every new
entry's headline and text are matched against them, and words that are rare in your feeds count more. A change
only affects entries that arrive afterwards." Write de from this. Write fr (*vous*) and es (*tú*) per the glossary,
using "For you" as the loanword the existing `forYouTitle` translations use.

- [ ] **Step 5: Run the specs.** Expected: PASS. Then run
  `./node_modules/.bin/ng build` once, because `npm run check` misses template errors.
- [ ] **Step 6: Do a live check** on the dev server at 1440 and 390 widths:
  - Toggle off: the sidebar entry disappears.
  - Toggle on: the precision bar and the word list show.
  - Five steps, with the bar reset working.
- [ ] **Step 7: Commit** `feat(#2594): the For you stream group in the recommendation settings`

---

## Spec edit in the plan commit

Done in the plan commit: the spec now names the shared stepper instead of "a select or segmented control", and "Left to the builder" keeps only the step labels.
