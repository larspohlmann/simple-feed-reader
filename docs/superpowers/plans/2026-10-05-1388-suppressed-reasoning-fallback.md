# A model that refuses suppressed reasoning is retried without it, and remembered — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development to implement this
> plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** With "Ask the model not to reason" on, a model that rejects `reasoning: {"effort": "none"}` works
anyway: the request is sent again without the field, the connection remembers that this model refuses it, and
Settings → AI says so.

**Spec:** GitHub issue #1388. Lars chose "Retry and remember" on 2026-10-05. Third of three; starts only after
#1387 is merged.

**Architecture:** The memory *is* the retry. `AiProviderSettings` gains the name of the model that refused
suppression. `Reasoning::preferredBy()` — the one place every request, budget and shortlist size reads the
preference from — answers `Allowed` for a connection whose current model is the remembered one. So when a
suppressed request is rejected, the tick records the refusal on the connection and ends without failing the
run; the next tick builds its request as for any connection with suppression off (no `reasoning` field, full
reasoning headroom in `max_tokens`) and goes through. Nothing in the HTTP client changes, and no request is
resent inside a call.

**Tech stack:** PHP 8.4, Symfony 7.4, Doctrine migrations (SQLite + MySQL), Angular 20, Jest.

## Global constraints

- `CLAUDE.md` rules, backend and frontend; `composer check`, `composer md`, both suite legs,
  `composer infection:diff`, `npm run check` in the Docker frontend container.
- Branch `fix/1388-suppressed-reasoning-fallback` off the `develop` that holds #1387. Commits `type(#1388): …`.
- Read #1387's merged code first: this plan names its catch arms (`ProfileRunTick`, `TickPhases`) and
  `ProfileRunFailure::failRejected()` as #1387's plan wrote them.
- Native-iOS constraint (`docs/architecture.md` §6): the new field is plain JSON on an existing response.

## Decisions (mine, within "Retry and remember" — say so in the PR body)

- **What counts as "the model refused suppression":** a `ProviderRejectedRequestException` with status **400 or
  422**, on a connection whose request went out suppressed. The provider's sentence is not parsed: its wording
  is theirs to change. Other rejecting statuses (402, 404 …) fail at once, as #1387 left them.
- **A false positive costs one request.** If the 400 was for another reason, the resend without the field is
  rejected too and fails the run with the provider's reason; the mark stays until the model or the setting
  changes. Accepted: the alternative is tracking probe state for a case the provider's own message explains.
- **The retry is the next tick, not a second request inside the call.** The run stays `running` with no strike.
  The poll driver and the worker tick again within seconds.
- **The mark is the model's name on the connection row.** It applies only while that model is the chosen one,
  survives switching away and back, and is cleared when the user toggles the setting (off or on), which is the
  way to make the reader try suppression again.
- **JEV is excluded:** it sends no `reasoning` field. The check is the engine's capability
  (`RecommendationTuningField::SuppressReasoning` in `capabilitiesFor($connection)->tuningFields`), not a
  comparison of engine kinds.
- **The help text changes** — it tells the user to turn the setting off "only if the endpoint rejects the
  request", which this makes unnecessary.

## Files

| File | Change |
|---|---|
| `src/Entity/AiProviderSettings.php` | `suppression_refused_by_model` column and its three methods |
| `migrations/Version<timestamp>.php` | Adds the column |
| `src/Service/Recommendation/Llm/Completion/Model/Reasoning.php` | `preferredBy()` honours the refusal |
| `src/Service/Recommendation/Llm/Run/SuppressedReasoningFallback.php` | Create: decides and records |
| `src/Service/Recommendation/Profile/ProfileRunTick.php`, `Run/TickPhases.php` | Ask the fallback before failing |
| `src/Http/AiSettingsJson.php` | `suppressionRefused` |
| `frontend/src/app/settings/ai/*`, `frontend/public/i18n/{en,de}.json` | The note and the help text |
| `docs/recommendations-runs.md` | The fallback |

---

### Task 1: The connection remembers the refusing model

**Interfaces — produces** on `AiProviderSettings`:
- `recordSuppressionRefused(): void` — remembers the current model; a `\LogicException` when there is none.
- `refusesSuppressedReasoning(): bool` — true while the current model is the remembered one.
- `setSuppressReasoning(bool)` additionally forgets the remembered model.

- [ ] **Step 1: Failing tests** in the entity's test (`tests/Entity/AiProviderSettingsTest.php`; build the row
  as `ProfileInputFingerprintTest::connection()` does, model chosen with `chooseModel()`):

```php
    public function testAFreshConnectionRefusesNothing(): void
    {
        self::assertFalse($this->connectionOn('model-a')->refusesSuppressedReasoning());
    }

    public function testARecordedRefusalHoldsForTheModelThatRefused(): void
    {
        $connection = $this->connectionOn('model-a');

        $connection->recordSuppressionRefused();

        self::assertTrue($connection->refusesSuppressedReasoning());
    }

    public function testAnotherModelOnTheConnectionIsNotTheOneThatRefused(): void
    {
        $connection = $this->connectionOn('model-a');
        $connection->recordSuppressionRefused();

        $connection->chooseModel('model-b', new \DateTimeImmutable('2026-10-06 09:00:00'), 32768);

        self::assertFalse($connection->refusesSuppressedReasoning());
    }

    public function testSwitchingBackToTheModelThatRefusedRemembersIt(): void
    {
        $connection = $this->connectionOn('model-a');
        $connection->recordSuppressionRefused();
        $connection->chooseModel('model-b', new \DateTimeImmutable('2026-10-06 09:00:00'), 32768);

        $connection->chooseModel('model-a', new \DateTimeImmutable('2026-10-06 09:01:00'), 32768);

        self::assertTrue($connection->refusesSuppressedReasoning());
    }

    #[DataProvider('bothSettings')]
    public function testChangingTheSettingForgetsTheRefusal(bool $suppressReasoning): void
    {
        $connection = $this->connectionOn('model-a');
        $connection->recordSuppressionRefused();

        $connection->setSuppressReasoning($suppressReasoning);

        self::assertFalse($connection->refusesSuppressedReasoning());
    }

    public function testAConnectionWithoutAModelCannotRecordARefusal(): void
    {
        $this->expectException(\LogicException::class);

        $this->connectionWithoutAModel()->recordSuppressionRefused();
    }
```

  `bothSettings` yields `true` and `false`. A duplicated connection
  (`AiProviderConfigurator::duplicateConfiguration`) starts without the mark; add a test there only if the
  duplicate copies columns generically — read it.

- [ ] **Step 2: Run, expect** "undefined method".
- [ ] **Step 3: Implement.** Match the `model` column's length for the new one:

```php
    /** The model that rejected a request asking it not to reason; such requests go to it without that field. */
    #[ORM\Column(name: 'suppression_refused_by_model', length: 255, nullable: true)]
    private ?string $suppressionRefusedByModel = null;

    public function recordSuppressionRefused(): void
    {
        $this->suppressionRefusedByModel = $this->model
            ?? throw new \LogicException('A connection without a model has sent no request to refuse.');
    }

    public function refusesSuppressedReasoning(): bool
    {
        return null !== $this->model && $this->model === $this->suppressionRefusedByModel;
    }

    public function setSuppressReasoning(bool $suppressReasoning): void
    {
        $this->suppressReasoning = $suppressReasoning;
        $this->suppressionRefusedByModel = null;
    }
```

- [ ] **Step 4: Migration.** `bin/console doctrine:migrations:diff` against a migrated database, then reduce the
  result to the one statement and the house shape (`skipIf` guard as in `Version20261003120000`):
  `ALTER TABLE user_ai_settings ADD suppression_refused_by_model VARCHAR(255) DEFAULT NULL` — valid on both
  platforms as written; `down()` drops the column (SQLite ≥ 3.35 supports `DROP COLUMN`; check how the newest
  migrations that drop a column do it on SQLite and follow them). Verify as CI's migration leg does, locally:
  migrate from empty on SQLite and on MySQL, then `doctrine:schema:validate` on each. Apply it to the live
  Docker database (`docker compose exec php bin/console doctrine:migrations:migrate --no-interaction`).
- [ ] **Step 5: Green; commit** — `feat(#1388): a connection remembers the model that refused suppressed reasoning`.

---

### Task 2: A remembered refusal sends the request without the field

**Files:** `Reasoning.php`; tests `tests/Service/Recommendation/Llm/Completion/Model/ReasoningTest.php`
(create if absent) and `tests/Service/Recommendation/Llm/Prompt/Factory/…RequestFactoryTest.php`.

- [ ] **Step 1: Failing tests.**

```php
    public function testAConnectionThatSuppressesAsksTheModelNotToReason(): void
    {
        self::assertSame(Reasoning::Suppressed, Reasoning::preferredBy($this->connectionOn('model-a')));
    }

    public function testAModelThatRefusedSuppressionIsAllowedToReason(): void
    {
        $connection = $this->connectionOn('model-a');
        $connection->recordSuppressionRefused();

        self::assertSame(Reasoning::Allowed, Reasoning::preferredBy($connection));
    }

    public function testASettingTurnedOffAllowsReasoning(): void
    {
        $connection = $this->connectionOn('model-a');
        $connection->setSuppressReasoning(false);

        self::assertSame(Reasoning::Allowed, Reasoning::preferredBy($connection));
    }
```

  And in the request factory's test: for a connection with a recorded refusal, the built request has
  `Reasoning::Allowed` **and** the `maxAnswerTokens` of a connection with suppression off (the full reasoning
  headroom) — the budget must follow the field, or the model reasons into a starved `max_tokens`.

- [ ] **Step 2: Run, expect** the second and the factory test to fail.
- [ ] **Step 3: Implement.**

```php
    public static function preferredBy(AiProviderSettings $connection): self
    {
        return $connection->suppressesReasoning() && !$connection->refusesSuppressedReasoning()
            ? self::Suppressed
            : self::Allowed;
    }
```

  Then `grep -rn "suppressesReasoning()" src`: every reader other than `Reasoning::preferredBy()` and
  `AiSettingsJson` (which reports the user's setting, unchanged) must go through `preferredBy()`. Fix any that
  reads the flag directly, with a test.
- [ ] **Step 4: Green; commit** — `feat(#1388): a model that refused suppression is asked without it`.

---

### Task 3: A refused suppressed request is recorded, and the run goes on

**Files:** create `src/Service/Recommendation/Llm/Run/SuppressedReasoningFallback.php`; modify
`ProfileRunTick.php`, `Run/TickPhases.php`; tests `ProfileRunTickTest`, `RecommendationRunAdvancerTest`,
`JevRecommendationEngineTest`, and the fallback's own unit test.

**Interfaces — produces:**
`SuppressedReasoningFallback::absorbs(AiProviderSettings $connection, ProviderRejectedRequestException $rejection): bool`
— when the rejection may be the model refusing suppression, records that on the connection, flushes, and
returns true; otherwise changes nothing and returns false. (`absorbs` mutates: it is not a `get…`/`is…`.)

**Module placement — unverified.** It is called from `Profile` and from `Run`, both inside the
`Recommendation` service module, so no module cycle arises; whether `ServiceRoleRule` accepts it in `Llm/Run/`
(a stateless `final readonly` service) is for `composer stan` to say. If `Run/TickPhases` importing from
`Llm/Run` reads wrong to you (the engine-neutral driver reaching into one engine), give `TickPhases` a small
interface it owns in `Run/` and let the fallback implement it.

- [ ] **Step 1: Failing unit tests** for the fallback (a `DbTestCase`, or a plain `TestCase` with a mock
  `EntityManagerInterface` if the neighbours do that):
  - 400 on a suppressing LLM connection → true, `refusesSuppressedReasoning()` true, flushed.
  - 422 likewise.
  - 404 and 402 → false, nothing recorded.
  - 400 on a connection with the setting off → false.
  - 400 on a connection whose model already refused → false (this is what ends the loop: the resend was
    rejected too).
  - 400 on a JEV connection (model `jev-latest`, setting at its default `true`) → false, nothing recorded.

- [ ] **Step 2: Failing functional tests.** In `ProfileRunTickTest` (the fixture connection suppresses by default):

```php
    public function testAModelThatRefusesSuppressedReasoningIsAskedAgainWithoutIt(): void
    {
        $this->fixtures->seedFavorites($this->owner, 'maps', 1);
        $profileRun = $this->profileRun(ProfileRunTrigger::Manual);
        $this->chat()->queueFailure(new ProviderRejectedRequestException(
            400,
            'That provider refused the request (status 400): Reasoning effort "none" is not supported.',
        ));
        $this->chat()->queueContent('{"profile":"Likes maps."}');

        $this->advance($profileRun, TickDriver::Poll);

        self::assertSame(RunStatus::Running, $this->saved($profileRun)->getStatus());
        self::assertSame(0, $this->saved($profileRun)->getTransportFailures());
        self::assertSame(0, $this->saved($profileRun)->getAttempts());

        $this->advance($this->saved($profileRun), TickDriver::Poll);

        self::assertSame(ProfileRunOutcome::Generated, $this->saved($profileRun)->getOutcome());
        $calls = $this->chat()->calls();
        self::assertTrue($calls[0]['suppressReasoning']);
        self::assertFalse($calls[1]['suppressReasoning']);
        self::assertGreaterThan($calls[0]['maxAnswerTokens'], $calls[1]['maxAnswerTokens']);
    }

    public function testASecondRejectionWithoutSuppressionFailsTheRun(): void
    {
        $this->fixtures->seedFavorites($this->owner, 'maps', 1);
        $profileRun = $this->profileRun(ProfileRunTrigger::Manual);
        foreach ([1, 2] as $attempt) {
            $this->chat()->queueFailure(new ProviderRejectedRequestException(
                400,
                'That provider refused the request (status 400): The schema is not supported.',
            ));
        }

        $this->advance($profileRun, TickDriver::Poll);
        $this->advance($this->saved($profileRun), TickDriver::Poll);

        self::assertSame(RunStatus::Failed, $this->saved($profileRun)->getStatus());
        self::assertCount(2, $this->chat()->calls());
    }
```

  `saved()` clears the entity manager, so `$this->owner` and the connection are detached afterwards — if
  `advance()` needs managed ones, re-read them as `testAnUnreadableKeyFailsTheRunAtOnceKeepingTheProfile` does.

  #1387's `testARejectedRequestFailsTheRunAtOnceKeepingTheProfile` and its siblings queue a **400** on this same
  suppressing connection and will now be absorbed. Keep their meaning — "a rejection that is not about
  suppression fails at once" — by turning the fixture connection's setting off in those tests, not by changing
  the status to dodge the branch.

  In `RecommendationRunAdvancerTest`: a batch wave whose calls are rejected with 400 on a suppressing connection
  leaves the run `Running` with no strike and every call row of the wave settled; the next tick's requests all
  carry `suppressReasoning: false` and the run completes. And in `JevRecommendationEngineTest`: a JEV 400 still
  fails the run at once (#1387's test, which must stay green **without** touching the setting — that is the
  JEV exclusion's pin).

- [ ] **Step 3: Run, expect** the first to end `Failed` on the first tick.

- [ ] **Step 4: Implement.**

```php
final readonly class SuppressedReasoningFallback
{
    private const array STATUSES_A_REFUSED_PARAMETER_EARNS = [400, 422];

    public function __construct(
        private RecommendationEngineResolver $engines,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function absorbs(AiProviderSettings $connection, ProviderRejectedRequestException $rejection): bool
    {
        if (!$this->mayBeARefusedSuppression($connection, $rejection)) {
            return false;
        }

        $connection->recordSuppressionRefused();
        $this->entityManager->flush();

        return true;
    }

    private function mayBeARefusedSuppression(
        AiProviderSettings $connection,
        ProviderRejectedRequestException $rejection,
    ): bool {
        return \in_array($rejection->status(), self::STATUSES_A_REFUSED_PARAMETER_EARNS, true)
            && Reasoning::Suppressed === Reasoning::preferredBy($connection)
            && \in_array(
                RecommendationTuningField::SuppressReasoning,
                $this->engines->capabilitiesFor($connection)->tuningFields,
                true,
            );
    }
}
```

  `ProfileRunTick`'s rejection arm (#1387) becomes:

```php
        } catch (ProviderRejectedRequestException $exception) {
            if (!$this->suppressedReasoningFallback->absorbs($tick->connection, $exception)) {
                $this->failure->failRejected($tick, $exception->getMessage());
            }
        } catch (
```

  and `TickPhases`' arm returns `RecommendationRunReportModel::fromRun($tick->run)` when absorbed, else fails as
  before. `ProfileRunTick` then has seven constructor dependencies and `TickPhases` seven: PHPMD will say so.
  Fix the design it points at — in both, the rejection handling (fallback, then fail) is one responsibility
  that can live in one collaborator per driver (`ProfileRunFailure` already owns "how a profile run ends
  badly"; giving its `failRejected()` the fallback keeps `ProfileRunTick` unchanged in size) — never the
  threshold.

  **Lock-lost guard:** a profile tick that lost its lock must record nothing (`failRejected()` already checks);
  make sure the absorb path is behind the same check, with a test like #1387's lock-lost one.

- [ ] **Step 5: Green across `tests/Service/Recommendation` and `tests/Service/Worker`.**
- [ ] **Step 6: Commit** — `fix(#1388): a model that refuses suppressed reasoning is retried without it`.

---

### Task 4: Settings → AI says so

**Files:** `src/Http/AiSettingsJson.php` (+ its test), `frontend/src/app/settings/ai/ai-settings.service.ts`,
`ai-section.component.html` / `.ts` / `.scss` / `.spec.ts`, `frontend/public/i18n/en.json`, `de.json`.

- [ ] **Backend, test first:** `configuration()` gains `'suppressionRefused' => $settings->refusesSuppressedReasoning()`.
  Check `docs/` for an API reference of this payload and add the field there.
- [ ] **Frontend, test first** (`ai-section.component.spec.ts`, run in the container:
  `docker compose exec -T frontend npm test -- ai-section`): `AiConfig` gains
  `readonly suppressionRefused: boolean`. Under the reasoning checkbox, a note is shown exactly when
  `config.suppressReasoning && config.suppressionRefused`; pin both halves (setting off + refused → no note;
  setting on + not refused → no note). Read `docs/design-language.md` first and use the shared hint/note
  treatment the settings sections already have — no new colours, spacing literals or inline styles.
- [ ] **Copy:**
  - `settings.ai.configs.reasoningRefused` — en: `This model rejected the request, so it is asked without it.`
    de: `Dieses Modell hat die Anfrage abgelehnt und wird deshalb ohne sie gefragt.`
  - `settings.ai.info.reasoning` — en: `Asks the model to answer without a separate reasoning phase, which saves
    time and tokens. A model that rejects the request is asked again without it, and the reader remembers that.
    Turn it off for a local endpoint that ignores it: there it only shrinks the room for the answer.`
    de: the same three sentences, in the register of the neighbouring help texts.
  The last sentence is the `suppress-reasoning-starves-local-models` finding; if you judge it out of scope for
  this issue, drop it and say so in the PR — but the "turn it off only if the endpoint rejects the request"
  sentence must go either way.
- [ ] `npm run check` in the container. Commit — `feat(#1388): settings say when a model refused suppressed reasoning`.

---

### Task 5: Docs, simplify, gates, real runs, PR

- [ ] `docs/recommendations-runs.md`: the fallback — when it triggers, that it costs one rejected request once
  per model, that the retry is the next tick, how the mark is cleared.
- [ ] Run `/simplify` on the branch diff; apply what it finds; re-run the affected tests.
- [ ] Backend gates as in #1387; frontend `npm run check`; PhpStorm lint. Migration leg locally on both
  databases, then `doctrine:schema:validate`.
- [ ] **Real runs** (container current, migration applied to the live Docker DB):
  1. One of the five models from the issue, setting **on**, fresh connection row: generate the profile. Expect
     two `distill` log rows — the first rejected with the provider's sentence, the second usable — the run
     `generated`, `suppression_refused_by_model` set, and the note visible in Settings → AI (screenshot it at
     desktop and mobile width).
  2. Generate again: **one** call, no rejected row.
  3. A model that accepts suppression (the dev account's usual one): one call, `suppressReasoning` sent,
     no mark.
  4. A recommendation run on the refusing model on the LLM engine to `completed`, `transport_failures = 0`.
     This is also the first live LLM-engine run since #1384 raised the profile reserve; note the batch count.
  Restore the dev account's connection, model and setting afterwards and say in the report what you restored.
- [ ] Scan today's `backend/var/log/dev-*.log`.
- [ ] PR into `develop`, body ending `Closes #1388`, with the decisions above marked as the planner's and the
  screenshots. **Merge when all checks are green**, verify #1388 auto-closed, then report to "Saved searches in
  For You profile" with the merge commit SHA. **Do not tag or deploy** — the planner session pushes
  `v1.1.2-dev.1` after your report.
