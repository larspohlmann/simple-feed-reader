# Saved-search terms shape the For You profile — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or
> superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** The profile distillation call sees the reader's saved-search terms, weighted equal to FAVORITES.

**Spec:** GitHub issue #1384.

**Architecture:** A profile run's inputs become one value, `ProfileInputsModel` (the reading history plus the
saved-search terms as the prompt shows them). `ProfileInputsLoader` builds it, `ProfileTick` carries it in place
of `$history`, and the three consumers read it: `ProfileInputFingerprint` (staleness), `ProfileRunOpening`
(nothing to build from) and `RecommendationPromptBuilder::distillMessages()` (the prompt). Scoring and
consolidation calls are untouched.

**Tech stack:** PHP 8.4, Symfony 7.4, Doctrine, PHPUnit 12. All commands run from `backend/`.

## Global constraints

- Clean Code rules in `CLAUDE.md`: `final readonly class`, no abbreviations, no comment that restates code.
- `composer check`, `composer md`, both suite legs and `composer infection:diff` pass before the PR.
- Commits: `type(#1384): lower-case summary`. Branch `feature/1384-saved-searches-shape-profile`.
- No new setting, no cap on the number of terms, JEV engine untouched.

## One deviation from the issue text

The issue says the fingerprint includes each term "with its whole-word/phrase mode". The fingerprint covers
what the prompt shows instead: the term, quoted when it is a phrase. Whole-word mode never reaches the prompt,
so toggling it alone would rebuild the profile from an identical request.

## Files

| File | Change |
|---|---|
| `src/Service/Recommendation/Profile/Model/ProfileInputsModel.php` | Create: history + terms, `isEmpty()` |
| `src/Service/Recommendation/Profile/ProfileInputsLoader.php` | Create: loads history and saved-search terms |
| `src/Service/Recommendation/Profile/Pass/ProfileTick.php` | `$history` becomes `ProfileInputsModel $inputs` |
| `src/Service/Recommendation/Profile/ProfileRunTick.php` | Injects `ProfileInputsLoader` instead of `RecommendationHistoryLoader` |
| `src/Service/Recommendation/Profile/ProfileRunOpening.php` | Reads `$tick->inputs` |
| `src/Service/Recommendation/Profile/Support/ProfileInputFingerprint.php` | Takes `ProfileInputsModel`, hashes sorted terms |
| `src/Service/Recommendation/Llm/Prompt/RecommendationPromptBuilder.php` | `distillMessages(ProfileInputsModel)`, SAVED SEARCHES section |
| `src/Service/Recommendation/Llm/Prompt/Support/RecommendationPromptText.php` | `DISTILL_ROLE` names the section and its weight |
| `src/Service/Recommendation/Llm/Run/LlmProfileRunDistiller.php` | Passes `$tick->inputs` |
| `frontend/public/i18n/en.json`, `de.json` | `statusNoHistory` copy mentions saved searches |
| `docs/recommendations-runs.md` | "The profile" and the Jev paragraph |

---

### Task 1: The prompt carries a SAVED SEARCHES section

**Files:**
- Create: `src/Service/Recommendation/Profile/Model/ProfileInputsModel.php`
- Modify: `src/Service/Recommendation/Llm/Prompt/RecommendationPromptBuilder.php` (`distillMessages`, near line 200)
- Modify: `src/Service/Recommendation/Llm/Prompt/Support/RecommendationPromptText.php` (`DISTILL_ROLE`)
- Modify: `src/Service/Recommendation/Llm/Run/LlmProfileRunDistiller.php` (keeps compiling: wraps the history)
- Test: `tests/Service/Recommendation/Profile/Model/ProfileInputsModelTest.php` (create),
  `tests/Service/Recommendation/Llm/Prompt/RecommendationPromptBuilderTest.php`

**Interfaces — produces:**
- `new ProfileInputsModel(RecommendationHistoryModel $history, list<string> $savedSearchTerms)`, public
  readonly properties of those names, `isEmpty(): bool`.
- `RecommendationPromptBuilder::distillMessages(ProfileInputsModel $inputs): list<array{role: string, content: string}>`

- [ ] **Step 1: Write the failing tests.**

`tests/Service/Recommendation/Profile/Model/ProfileInputsModelTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Recommendation\Profile\Model;

use App\Service\Recommendation\Pool\Model\ArticleLineModel;
use App\Service\Recommendation\Pool\Model\RecommendationHistoryModel;
use App\Service\Recommendation\Profile\Model\ProfileInputsModel;
use PHPUnit\Framework\TestCase;

final class ProfileInputsModelTest extends TestCase
{
    public function testWithoutHistoryAndWithoutSavedSearchesItIsEmpty(): void
    {
        self::assertTrue((new ProfileInputsModel(new RecommendationHistoryModel([], [], []), []))->isEmpty());
    }

    public function testASavedSearchAloneIsSomethingToBuildFrom(): void
    {
        self::assertFalse((new ProfileInputsModel(new RecommendationHistoryModel([], [], []), ['rust']))->isEmpty());
    }

    public function testHistoryAloneIsSomethingToBuildFrom(): void
    {
        $history = new RecommendationHistoryModel(
            [],
            [],
            [new ArticleLineModel(7, 'Viewed', 'Feed', '2026-10-01', null)],
        );

        self::assertFalse((new ProfileInputsModel($history, []))->isEmpty());
    }
}
```

In `RecommendationPromptBuilderTest`, change the two existing distill tests to pass
`new ProfileInputsModel($history, [])` (the exact-structure test's expected user message stays as it is — that
is the "section omitted when there are no saved searches" pin), and add:

```php
    public function testDistillMessagesListTheSavedSearchesAheadOfTheHistory(): void
    {
        $inputs = new ProfileInputsModel(
            new RecommendationHistoryModel(favorites: [self::line(1, 'Fav one', 10)], kept: [], viewed: []),
            ['rust', '"home assistant"'],
        );

        $user = $this->builder->distillMessages($inputs)[1]['content'];

        self::assertStringStartsWith(
            "SAVED SEARCHES:\n- rust\n- \"home assistant\"\n\nFAVORITES (newest first):\n- Fav one",
            $user,
        );
    }

    public function testTheDistillRoleWeighsSavedSearchesWithFavourites(): void
    {
        self::assertStringContainsString(
            'SAVED SEARCHES and FAVORITES weigh strongest, KEPT next, VIEWED least',
            RecommendationPromptText::DISTILL_ROLE,
        );
    }
```

- [ ] **Step 2: Run them, expect failure.**
  `php bin/phpunit tests/Service/Recommendation/Profile/Model/ProfileInputsModelTest.php tests/Service/Recommendation/Llm/Prompt/RecommendationPromptBuilderTest.php --filter 'ProfileInputs|Distill'`
  Expected: errors on the missing `ProfileInputsModel` class.

- [ ] **Step 3: Implement.**

`src/Service/Recommendation/Profile/Model/ProfileInputsModel.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Profile\Model;

use App\Service\Recommendation\Pool\Model\RecommendationHistoryModel;

final readonly class ProfileInputsModel
{
    /**
     * @param list<string> $savedSearchTerms as the prompt shows them, newest saved first
     */
    public function __construct(
        public RecommendationHistoryModel $history,
        public array $savedSearchTerms,
    ) {
    }

    public function isEmpty(): bool
    {
        return $this->history->isEmpty() && [] === $this->savedSearchTerms;
    }
}
```

`RecommendationPromptBuilder` — replace `distillMessages` and add two private methods beside `historySections`:

```php
    /**
     * The only call that sees SAVED SEARCHES, KEPT and VIEWED; every later phase gets the profile it writes plus
     * FAVORITES.
     *
     * @return list<array{role: string, content: string}>
     */
    public function distillMessages(ProfileInputsModel $inputs): array
    {
        return [
            [
                'role' => 'system',
                'content' => RecommendationPromptText::DISTILL_ROLE
                    . "\n\n" . RecommendationPromptText::DISTILL_OUTPUT_CONTRACT,
            ],
            ['role' => 'user', 'content' => $this->distillSections($inputs)],
        ];
    }

    private function distillSections(ProfileInputsModel $inputs): string
    {
        $historySections = $this->historySections($inputs->history);
        if ([] === $inputs->savedSearchTerms) {
            return $historySections;
        }

        return $this->savedSearchesSection($inputs->savedSearchTerms) . "\n\n" . $historySections;
    }

    /**
     * @param list<string> $savedSearchTerms
     */
    private function savedSearchesSection(array $savedSearchTerms): string
    {
        $rendered = array_map(static fn (string $term): string => '- ' . $term, $savedSearchTerms);

        return "SAVED SEARCHES:\n" . implode("\n", $rendered);
    }
```

`RecommendationPromptText::DISTILL_ROLE`:

```php
    public const string DISTILL_ROLE = 'You read one reader\'s history from an RSS reader and write a short '
        . 'preference profile for them. The user message holds three sections — FAVORITES, KEPT and VIEWED, newest '
        . 'first — and, when the reader has any, a SAVED SEARCHES section ahead of them: the search terms they '
        . 'saved to keep following a subject. SAVED SEARCHES and FAVORITES weigh strongest, KEPT next, VIEWED least. '
        . 'A saved search is a standing interest even when nothing in the history matches it. Write a compact '
        . 'profile, at most about 300 words, that names the reader\'s specific, repeated interests — topics, '
        . 'subjects, companies, technologies, people, kinds of story — and what they clearly avoid. Name concrete '
        . 'interests, not broad categories: prefer "self-hosted home automation" over "technology". The profile is '
        . 'used to score unread posts, so it must be specific enough to tell a strong match from a weak one.';
```

`LlmProfileRunDistiller::distill()` — until Task 3 gives the tick its inputs:
`$this->promptBuilder->distillMessages(new ProfileInputsModel($tick->history, []))`.

- [ ] **Step 4: Run the two test files whole, expect green.**
- [ ] **Step 5: Commit** — `feat(#1384): the distillation prompt lists saved searches beside favourites`.

---

### Task 2: A changed saved search changes the fingerprint

**Files:**
- Modify: `src/Service/Recommendation/Profile/Support/ProfileInputFingerprint.php`
- Modify: `src/Service/Recommendation/Profile/ProfileRunOpening.php:31` (keeps compiling: wraps the history)
- Test: `tests/Service/Recommendation/Profile/Support/ProfileInputFingerprintTest.php`

**Interfaces — consumes:** `ProfileInputsModel` (Task 1).
**Produces:** `ProfileInputFingerprint::of(ProfileInputsModel $inputs, RecommendationHistoryCaps $caps, AiProviderSettings $connection): string`

- [ ] **Step 1: Write the failing tests.** Change the test's `history()` helper to return the inputs, with the
  terms as an optional fourth argument, so every existing test keeps its call:

```php
    /**
     * @param list<int>    $favorites
     * @param list<int>    $kept
     * @param list<int>    $viewed
     * @param list<string> $savedSearchTerms
     */
    private function history(
        array $favorites,
        array $kept,
        array $viewed,
        array $savedSearchTerms = [],
    ): ProfileInputsModel {
        return new ProfileInputsModel(
            new RecommendationHistoryModel(self::lines($favorites), self::lines($kept), self::lines($viewed)),
            $savedSearchTerms,
        );
    }
```

  `testAnEntryRetitledByItsFeedLeavesItAlone` wraps its hand-built history: `new ProfileInputsModel($retitled, [])`.
  Add:

```php
    public function testASavedSearchChangesIt(): void
    {
        self::assertNotSame(
            ProfileInputFingerprint::of($this->history([11], [12], [13]), $this->caps(), $this->connection(5, 'm')),
            ProfileInputFingerprint::of(
                $this->history([11], [12], [13], ['rust']),
                $this->caps(),
                $this->connection(5, 'm'),
            ),
        );
    }

    public function testASearchSavedAsAPhraseChangesIt(): void
    {
        self::assertNotSame(
            ProfileInputFingerprint::of(
                $this->history([11], [12], [13], ['home assistant']),
                $this->caps(),
                $this->connection(5, 'm'),
            ),
            ProfileInputFingerprint::of(
                $this->history([11], [12], [13], ['"home assistant"']),
                $this->caps(),
                $this->connection(5, 'm'),
            ),
        );
    }

    public function testTheOrderOfTheSavedSearchesLeavesItAlone(): void
    {
        self::assertSame(
            ProfileInputFingerprint::of(
                $this->history([11], [12], [13], ['rust', 'maps']),
                $this->caps(),
                $this->connection(5, 'm'),
            ),
            ProfileInputFingerprint::of(
                $this->history([11], [12], [13], ['maps', 'rust']),
                $this->caps(),
                $this->connection(5, 'm'),
            ),
        );
    }
```

- [ ] **Step 2: Run the file, expect** a `TypeError` (a `ProfileInputsModel` passed where a history is typed).

- [ ] **Step 3: Implement.** `of()` takes `ProfileInputsModel $inputs`, reads `$inputs->history->favorites` etc.,
  and adds one key after `viewed`:

```php
            'savedSearches' => self::sorted($inputs->savedSearchTerms),
```

```php
    /**
     * @param list<string> $savedSearchTerms
     *
     * @return list<string>
     */
    private static function sorted(array $savedSearchTerms): array
    {
        sort($savedSearchTerms, \SORT_STRING);

        return $savedSearchTerms;
    }
```

  The class docblock becomes: `What a profile depends on: the history's entry ids per section, the saved-search
  terms, the caps, and the connection and its model.`
  `ProfileRunOpening::open()` passes `new ProfileInputsModel($tick->history, [])` until Task 3.

- [ ] **Step 4: Run the file, expect green.**
- [ ] **Step 5: Commit** — `feat(#1384): a changed saved search makes the profile stale`.

---

### Task 3: A profile run loads the saved searches

**Files:**
- Create: `src/Service/Recommendation/Profile/ProfileInputsLoader.php`
- Modify: `Pass/ProfileTick.php`, `ProfileRunTick.php`, `ProfileRunOpening.php`,
  `../Llm/Run/LlmProfileRunDistiller.php`
- Test: `tests/Service/Recommendation/Profile/ProfileRunTickTest.php`

**Interfaces — consumes:** `ProfileInputsModel`, `distillMessages(ProfileInputsModel)`,
`ProfileInputFingerprint::of(ProfileInputsModel, …)`; `SavedSearchRepository::findForUser(int): list<SavedSearch>`
(newest first; `getTerm()` holds a phrase without its quotes, `isPhrase()` says it is one).
**Produces:** `ProfileInputsLoader::load(int $userId, EffectiveRecommendationSettingsModel $settings): ProfileInputsModel`;
`ProfileTick::$inputs` replaces `ProfileTick::$history`.

- [ ] **Step 1: Write the failing tests** in `ProfileRunTickTest` (they go through the container, so the wiring
  is what is tested):

```php
    public function testTheModelIsShownTheSavedSearchesNewestFirstAPhraseInQuotes(): void
    {
        $this->fixtures->seedFavorites($this->owner, 'maps', 1);
        $this->saveSearch('rust', false);
        $this->saveSearch('home assistant', true);
        $this->chat()->queueContent('{"profile":"Likes maps, Rust and Home Assistant."}');

        $this->advance($this->profileRun(ProfileRunTrigger::Manual), TickDriver::Poll);

        self::assertStringStartsWith(
            "SAVED SEARCHES:\n- \"home assistant\"\n- rust\n\nFAVORITES",
            $this->chat()->calls()[0]['messages'][1]['content'],
        );
    }

    public function testAnotherReadersSavedSearchesStayOutOfThePrompt(): void
    {
        $this->fixtures->seedFavorites($this->owner, 'maps', 1);
        $stranger = $this->user('profile-run-tick-other-reader@example.test');
        $this->entityManager->persist(new SavedSearch($stranger, 'knitting', false));
        $this->entityManager->flush();
        $this->chat()->queueContent('{"profile":"Likes maps."}');

        $this->advance($this->profileRun(ProfileRunTrigger::Manual), TickDriver::Poll);

        self::assertStringNotContainsString('SAVED SEARCHES', $this->chat()->calls()[0]['messages'][1]['content']);
    }

    public function testSavedSearchesAloneAreEnoughToBuildAProfile(): void
    {
        $this->saveSearch('rust', false);
        $this->chat()->queueContent('{"profile":"Follows Rust."}');
        $profileRun = $this->profileRun(ProfileRunTrigger::Manual);

        $this->advance($profileRun, TickDriver::Poll);

        self::assertSame(ProfileRunOutcome::Generated, $profileRun->getOutcome());
        self::assertSame('Follows Rust.', $this->storedProfile()->getText());
    }

    public function testAScheduledRunAfterASearchWasSavedCallsTheModel(): void
    {
        $this->fixtures->seedFavorites($this->owner, 'maps', 2);
        $this->chat()->queueContent('{"profile":"First."}');
        $this->advance($this->profileRun(ProfileRunTrigger::Manual), TickDriver::Poll);
        $this->saveSearch('rust', false);
        $this->chat()->queueContent('{"profile":"Maps and Rust."}');
        $scheduled = $this->profileRun(ProfileRunTrigger::Scheduled);

        $this->advance($scheduled, TickDriver::Poll);

        self::assertSame(ProfileRunOutcome::Generated, $scheduled->getOutcome());
        self::assertSame('Maps and Rust.', $this->storedProfile()->getText());
    }

    private function saveSearch(string $term, bool $phrase): void
    {
        $this->entityManager->persist(new SavedSearch($this->owner, $term, false, $phrase));
        $this->entityManager->flush();
    }
```

  Add `use App\Entity\SavedSearch;`. `testWithoutHistoryTheRunCompletesWithoutACallAndStoresNothing` stays as
  the pin for "both empty".

- [ ] **Step 2: Run** `php bin/phpunit tests/Service/Recommendation/Profile/ProfileRunTickTest.php --filter 'SavedSearch|SearchWasSaved'`.
  Expected: the first fails on the missing section, the third with `NoHistory`, the fourth with `Unchanged`;
  the stranger test passes already (it guards the loader's `findForUser` scoping once that exists — break-test it
  in Step 4).

- [ ] **Step 3: Implement.**

`src/Service/Recommendation/Profile/ProfileInputsLoader.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Profile;

use App\Entity\SavedSearch;
use App\Repository\SavedSearchRepository;
use App\Service\Recommendation\Pool\RecommendationHistoryLoader;
use App\Service\Recommendation\Profile\Model\ProfileInputsModel;
use App\Service\Recommendation\Settings\Model\EffectiveRecommendationSettingsModel;

final readonly class ProfileInputsLoader
{
    public function __construct(
        private RecommendationHistoryLoader $historyLoader,
        private SavedSearchRepository $savedSearches,
    ) {
    }

    public function load(int $userId, EffectiveRecommendationSettingsModel $settings): ProfileInputsModel
    {
        return new ProfileInputsModel(
            $this->historyLoader->load($userId, $settings),
            array_map(self::termAsTyped(...), $this->savedSearches->findForUser($userId)),
        );
    }

    private static function termAsTyped(SavedSearch $savedSearch): string
    {
        return $savedSearch->isPhrase() ? '"' . $savedSearch->getTerm() . '"' : $savedSearch->getTerm();
    }
}
```

  - `ProfileTick`: `public RecommendationHistoryModel $history` becomes `public ProfileInputsModel $inputs`.
  - `ProfileRunTick`: the constructor takes `ProfileInputsLoader $inputsLoader` in place of
    `RecommendationHistoryLoader $historyLoader`; `tickFor()` passes
    `$this->inputsLoader->load($user->requireId(), $settings)`.
  - `ProfileRunOpening::open()`: `ProfileInputFingerprint::of($tick->inputs, …)` and
    `if ($tick->inputs->isEmpty())`. Its docblock: `Starts a pending profile run; with nothing to build from, or
    as a scheduled run with unchanged inputs, it ends right there.`
  - `LlmProfileRunDistiller`: `$this->promptBuilder->distillMessages($tick->inputs)`.
  - Remove the two temporary `new ProfileInputsModel($tick->history, [])` wrappers and their imports.

- [ ] **Step 4: Run the whole `tests/Service/Recommendation` directory, expect green.** Then break-test the
  stranger pin: temporarily replace `findForUser($userId)` with `findAll()` in the loader, confirm `testAnotherReadersSavedSearchesStayOutOfThePrompt` fails,
  and restore the line by editing it back (no `git checkout --`).
- [ ] **Step 5: Commit** — `feat(#1384): a profile run reads the reader's saved searches`.

---

### Task 4: Copy and docs

- [ ] `frontend/public/i18n/en.json` `settings.profile.statusNoHistory`:
  `There is no reading history and no saved search yet to build a profile from.`
- [ ] `frontend/public/i18n/de.json`:
  `Es gibt noch keinen Leseverlauf und keine gespeicherte Suche, aus denen sich ein Profil erstellen ließe.`
- [ ] `docs/recommendations-runs.md`, "The profile": a profile run loads the reading history **and the saved-search
  terms** (the terms, not their results; a phrase in quotes), which the prompt weighs with the favourites; the
  fingerprint covers them; "No history completes the run without a call" becomes "Neither history nor a saved
  search completes the run (`no_history`) without a call". The Jev paragraph's "(an account without reading
  history)" becomes "(an account with neither reading history nor a saved search)".
- [ ] From `frontend/`: `docker compose exec -T frontend npm run check` (the i18n files are linted by Prettier).
- [ ] Commit — `docs(#1384): saved searches as a profile input`.

---

### Task 5: Gates and a real run

- [ ] `bin/console cache:warmup && composer check && composer md`
- [ ] `composer test:parallel` and, in parallel, `docker compose exec php composer test` (MySQL leg).
- [ ] `git add -A` first (infection:diff ignores untracked files), then `composer infection:diff`; kill any
  escaped mutant with a test, never by lowering `minMsi`.
- [ ] `mcp__phpstorm__lint_files` on the changed PHP; block on ERROR and WARNING.
- [ ] Check the Docker container serves this branch's code, then trigger "Generate now" for the profile against
  the live provider on the dev stack and read the profile run's recorded request: it carries the SAVED SEARCHES
  section, the run completes as `generated`, and the stored profile names a saved-search subject. Then start a
  recommendation run and watch it to `completed` with `transport_failures = 0`.
- [ ] Scan today's `backend/var/log/dev-*.log` for errors and deprecations.
- [ ] PR into `develop`, body ending `Closes #1384`.
