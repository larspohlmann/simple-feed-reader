# Recommendation Run Resilience Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Bound a stranded recommendation run to minutes instead of hours, split `slow_model` into timeouts and batch cap, and move the drainer spawn to a terminate listener.

**Architecture:** The advancer arms a `TickLockKeepalive` that refreshes its lock on every stream chunk, so the lock TTL can be sized against one first-byte wait instead of three full wall clocks. The per-connection batch cap becomes its own nullable column, where null means "the default stands". The drainer spawn becomes a terminate listener that asks the presence question once per request or command, replacing a process-scoped flag.

**Tech Stack:** Symfony 7.4 LTS, PHP 8.4, Doctrine ORM + DBAL lock store, Angular 20 with signals, Jest, PHPUnit, Infection.

**Source spec:** `docs/superpowers/specs/2026-08-16-recommendation-run-resilience-design.md`

## Global Constraints

- **Clean Code is mandatory.** `final readonly` with constructor promotion is the house style. Names reveal intent, functions do one thing, guard clauses over nesting, no boolean flag parameters, no hidden side effects. Comments explain *why*, never *what*.
- `declare(strict_types=1)` in every PHP file. PSR-12.
- **Controllers hold no private methods that carry responsibility** — enforced by `ThinControllerRule`.
- **Every `src` file you touch must be PHPMD-clean before commit**, not merely free of new findings.
- **phptramp**: a value forwarded through 4+ methods across 2+ classes fails the build. Give a value a home rather than a longer signature.
- **Frontend**: standalone components and signals. **No hex colours and no raw `px` in `.scss` outside `src/app/theme/`.** Component styles live in a sibling `.scss` file, never inline in the `.ts`. Prettier at 100 columns.
- **Datetimes are naive UTC.** Normalise before persisting.
- **i18n**: two locales, `frontend/public/i18n/en.json` and `de.json`. Every new key lands in both. `frontend/dist/` is build output — never edit it.
- **Read the file before you edit it.** Every code block below is a sketch of intent, not a verbatim patch; match the surrounding file's naming, docblock density and idiom.
- Commit after every task with a message of the form `type(#NNN): imperative summary`.

---

## File Structure

**Part 3 — drainer spawn (#393)**

| File | Responsibility |
|---|---|
| `backend/src/Repository/RecommendationRunRepository.php` (modify) | Add `hasActiveRun(): bool` — one indexed existence read |
| `backend/src/EventListener/RecommendationDrainOnTerminateListener.php` (create) | Decide and launch, once per request or command, after the response |
| `backend/src/Service/Recommendation/RecommendationDrainSpawner.php` (modify) | Loses `$launched`, becomes `final readonly` |
| `backend/src/Service/Recommendation/RecommendationRunStarter.php` (modify) | Loses the spawner dependency |
| `backend/src/Service/Maintenance/MaintenanceTick.php` (modify) | Loses the spawner dependency |

**Part 2 — batch cap (#445)**

| File | Responsibility |
|---|---|
| `backend/src/Entity/AiProviderSettings.php` (modify) | `?int $maxBatchSize` column plus accessors |
| `backend/migrations/Version20260816T*.php` (create) | Add the column, backfill 30 for `slow_model = 1` |
| `backend/src/Service/Recommendation/RecommendationPackingSettings.php` (modify) | Delete `SLOW_MODEL_MAXIMUM_BATCH_SIZE` |
| `backend/src/Service/Recommendation/RecommendationSettingsResolver.php` (modify) | Read the column, fall back to the default |
| `backend/src/Dto/Ai/SetMaxBatchSizeRequest.php` (create) | Request shape and range validation |
| `backend/src/Service/Ai/AiConfigurationEditor.php` (modify) | `setMaxBatchSize()` |
| `backend/src/Service/Ai/AiProviderConfigurator.php` (modify) | Duplicate carries the cap |
| `backend/src/Http/AiSettingsJson.php` (modify) | Serialise `maxBatchSize` |
| `backend/src/Controller/Api/AiSettingsController.php` (modify) | `PUT /configs/{id}/max-batch-size` |
| `frontend/src/app/settings/ai-settings.service.ts` (modify) | `maxBatchSize` on `AiConfig`, `setMaxBatchSize()` |
| `frontend/src/app/settings/ai-section.component.{ts,html,scss}` (modify) | The number input |
| `frontend/public/i18n/{en,de}.json` (modify) | Label, help text, corrected `slowModel` help |

**Part 1 — lock keepalive (#444, #439)**

| File | Responsibility |
|---|---|
| `backend/src/Service/Recommendation/TickLockKeepalive.php` (create) | Hold a lock alive while its tick streams |
| `backend/src/Service/Recommendation/CompositeCompletionStreamHeartbeat.php` (create) | Fan one beat to several heartbeats |
| `backend/config/services.yaml` (modify) | Wire the composite in place of the direct alias |
| `backend/src/Service/Recommendation/RecommendationRunAdvancer.php` (modify) | Arm the keepalive, resize the TTL, report the stall |
| `backend/src/Service/Recommendation/RecommendationRunReport.php` (modify) | `waitingForLock` |
| `backend/src/Service/Recommendation/RecommendationPollDriver.php` (modify) | Set it when the lock is held and nobody beats |
| `backend/src/Http/RecommendationRunStatusJson.php` (modify) | Serialise it |
| `frontend/src/app/reader/models.ts` (modify) | `waitingForLock` on the report |
| `frontend/src/app/reader/recommendations.service.ts` (modify) | New eta state |
| `frontend/src/app/reader/for-you-progress/for-you-progress.component.ts` (modify) | New phrase |
| `frontend/public/i18n/{en,de}.json` (modify) | `reader.eta.lockHeld` |

---

# Part 3 — The drainer spawn moves to a terminate listener (#393)

### Task 1: An existence read for active runs

**Files:**
- Modify: `backend/src/Repository/RecommendationRunRepository.php`
- Test: `backend/tests/Repository/RecommendationRunRepositoryTest.php` (create if absent)

**Interfaces:**
- Produces: `RecommendationRunRepository::hasActiveRun(): bool`

Read `findAllActive()` in the same repository first and reuse whatever it treats as "active" — do not restate the status list from memory. The new method answers the same question without hydrating entities.

- [ ] **Step 1: Write the failing tests**

Three cases: an empty table is false; a run in each active status is true; a run in each terminal status (`completed`, `cancelled`, `failed`) is false. Follow the repository test conventions already in `backend/tests/Repository/`.

- [ ] **Step 2: Run them and watch them fail**

```bash
cd backend && php bin/phpunit tests/Repository/RecommendationRunRepositoryTest.php
```

Expected: fail on an undefined method.

- [ ] **Step 3: Implement**

```php
/**
 * Whether any run anywhere still needs driving. A count, not a fetch: the
 * terminate listener asks this on every request and must not pay for
 * hydration to learn the answer is no.
 */
public function hasActiveRun(): bool
{
    // Mirror findAllActive()'s notion of active — read it, do not restate it.
}
```

- [ ] **Step 4: Run them and watch them pass**

- [ ] **Step 5: Commit**

```bash
git add backend/src/Repository/RecommendationRunRepository.php backend/tests/Repository/RecommendationRunRepositoryTest.php
git commit -m "feat(#393): ask whether any run is active without hydrating one"
```

---

### Task 2: The terminate listener

**Files:**
- Create: `backend/src/EventListener/RecommendationDrainOnTerminateListener.php`
- Test: `backend/tests/EventListener/RecommendationDrainOnTerminateListenerTest.php`

**Interfaces:**
- Consumes: `RecommendationRunRepository::hasActiveRun()`, `RecommendationDrainSpawner::spawnIfNoWorker()`, `RecommendationDrainSpawner::DRAIN_COMMAND`
- Produces: a listener on `TerminateEvent` and `ConsoleTerminateEvent`

Read `backend/src/EventListener/DeferredMailFlushListener.php` and `backend/tests/EventListener/DeferredMailFlushListenerTest.php` first. This is the same shape, and the test asserts against the real kernel: `handle()` must not spawn, `terminate()` must.

- [ ] **Step 1: Write the failing tests**

Cases, each against the real kernel with a `RecordingProcessLauncher` swapped in:

1. A request handled with an active run and no fresh heartbeat spawns nothing until `terminate()` is called, and exactly one launch after it.
2. No active run spawns nothing, and does not reach the presence read.
3. A fresh worker heartbeat suppresses the spawn.
4. A `ConsoleTerminateEvent` for `RecommendationDrainSpawner::DRAIN_COMMAND` spawns nothing, even with an active run and no heartbeat.
5. A `ConsoleTerminateEvent` for any other command with an active run spawns.
6. A closed entity manager is survived: no exception escapes, nothing is launched.

- [ ] **Step 2: Run them and watch them fail**

```bash
cd backend && php bin/phpunit tests/EventListener/RecommendationDrainOnTerminateListenerTest.php
```

- [ ] **Step 3: Implement**

```php
#[AsEventListener(event: TerminateEvent::class, method: 'onKernelTerminate')]
#[AsEventListener(event: ConsoleTerminateEvent::class, method: 'onConsoleTerminate')]
final readonly class RecommendationDrainOnTerminateListener
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private RecommendationRunRepository $runs,
        private RecommendationDrainSpawner $spawner,
        private LoggerInterface $logger,
    ) {
    }

    public function onKernelTerminate(TerminateEvent $event): void
    {
        $this->spawnIfRunsNeedDriving();
    }

    /**
     * The drainer surrenders its liveness key before it terminates, so at this
     * point it looks absent to the presence read and would fork its own
     * successor at every exit.
     */
    public function onConsoleTerminate(ConsoleTerminateEvent $event): void
    {
        if (RecommendationDrainSpawner::DRAIN_COMMAND === $event->getCommand()?->getName()) {
            return;
        }

        $this->spawnIfRunsNeedDriving();
    }

    private function spawnIfRunsNeedDriving(): void
    {
        // A tick that aborted its refresh leaves the manager closed, and even
        // the existence read is off-limits then.
        if (!$this->entityManager->isOpen()) {
            return;
        }

        try {
            if (!$this->runs->hasActiveRun()) {
                return;
            }
            $this->spawner->spawnIfNoWorker();
        } catch (\Throwable $failure) {
            // A response is already on the wire. Failing to spawn costs the
            // next cron tick; raising here would cost the request.
            $this->logger->warning('Deferred drainer spawn failed', ['exception' => $failure]);
        }
    }
}
```

Watch `ThinControllerRule` does not apply here, but the Clean Code rules do: `spawnIfRunsNeedDriving()` is one thing at one level of abstraction.

- [ ] **Step 4: Run them and watch them pass**

- [ ] **Step 5: Commit**

```bash
git add backend/src/EventListener/RecommendationDrainOnTerminateListener.php backend/tests/EventListener/RecommendationDrainOnTerminateListenerTest.php
git commit -m "feat(#393): spawn the drainer from a terminate listener"
```

---

### Task 3: Retire the inline spawns and the process-scoped flag

**Files:**
- Modify: `backend/src/Service/Recommendation/RecommendationDrainSpawner.php`
- Modify: `backend/src/Service/Recommendation/RecommendationRunStarter.php` (spawner calls at the `start()` early return, the `start()` main path, and `resume()`)
- Modify: `backend/src/Service/Maintenance/MaintenanceTick.php` (`run()`)
- Modify: `backend/tests/Service/Recommendation/RecommendationDrainSpawnerTest.php`
- Modify: `backend/tests/Service/Recommendation/RecommendationRunStarterTest.php`
- Modify: `backend/tests/Service/Maintenance/MaintenanceTickTest.php`
- Modify: `backend/tests/Controller/Api/RecommendationRunControllerTest.php`

**Interfaces:**
- Consumes: the listener from Task 2
- Produces: `final readonly class RecommendationDrainSpawner` whose `spawnIfNoWorker()` has no memory

- [ ] **Step 1: Delete the tests that no longer describe the code**

From `RecommendationRunStarterTest`, remove the four spawn tests and the `starterWith()` / launcher plumbing; the starter no longer launches anything. From `MaintenanceTickTest`, remove `spawnerWith()` and the three launch assertions. From `RecommendationDrainSpawnerTest`, remove the once-per-process test — the class no longer remembers.

- [ ] **Step 2: Add the test that pins the new behaviour**

In `RecommendationRunControllerTest`, a functional test: with an active run and no fresh heartbeat, `POST /api/recommendations/runs/tick` followed by kernel termination launches exactly one drainer. This is #393's stated new behaviour and must not regress.

- [ ] **Step 3: Run and watch the new test fail**

- [ ] **Step 4: Strip the spawner out**

`RecommendationDrainSpawner`: delete `private bool $launched`, delete the early return that reads it, mark the class `final readonly`, and rewrite the class docblock — it currently justifies the flag, and that justification is now the listener's.

`RecommendationRunStarter`: drop the constructor argument and all three call sites. Delete the comment about flushing before the spawn if it now explains nothing.

`MaintenanceTick::run()`: drop the constructor argument and the `activeRuns > 0` spawn branch. The aborted-refresh early return stays — it exists for the report shape, not only for the spawn.

- [ ] **Step 5: Run the full backend suite**

```bash
cd backend && php bin/phpunit
```

- [ ] **Step 6: Commit**

```bash
git add -A backend/src backend/tests
git commit -m "refactor(#393): return the run starter and the tick to their single jobs"
```

---

# Part 2 — `slow_model` governs timeouts only (#445)

### Task 4: The column and its migration

**Files:**
- Modify: `backend/src/Entity/AiProviderSettings.php`
- Create: `backend/migrations/Version<timestamp after 20260816160000>.php`
- Test: `backend/tests/Service/Recommendation/RecommendationSettingsResolverTest.php` (helper only, in Task 5)

**Interfaces:**
- Produces: `AiProviderSettings::maxBatchSize(): ?int`, `AiProviderSettings::setMaxBatchSize(?int $maxBatchSize): void`, `AiProviderSettings::MINIMUM_BATCH_SIZE = 5`, `AiProviderSettings::MAXIMUM_BATCH_SIZE = 200`

Note the accessor is `maxBatchSize()` with no `get` prefix, matching the sibling `batchConcurrency()`.

- [ ] **Step 1: Add the property**

```php
/**
 * How many candidates one batch of this connection's run may carry, or null
 * to take the default. Split off `slow_model` in #445: how fast an endpoint
 * answers and how long a list its model holds in order are different
 * properties, and one flag could not express a large model on slow hardware.
 *
 * The cap is not free either way. #437 watched a 4B local model given 45
 * entries answer eight batches correctly and fall into a repetition loop on
 * the ninth, inventing ids until `max_tokens` stopped it — a shorter list is
 * easier to hold in order, and it bounds what one runaway costs. Against
 * that, the history sections are re-sent with every batch, so smaller
 * batches mean more calls and more prompt tokens over the run.
 */
#[ORM\Column(nullable: true)]
private ?int $maxBatchSize = null;
```

Add `MINIMUM_BATCH_SIZE` and `MAXIMUM_BATCH_SIZE` constants beside `MAX_BATCH_CONCURRENCY`, with a docblock recording that 200 is a sanity bound against a typo, not a quality bound — the token budget remains the real guard.

- [ ] **Step 2: Write the migration**

Copy the shape of `backend/migrations/Version20260816160000.php` exactly: `isTransactional(): false`, the `hasColumn()` idempotence guard, and the private `mysql()` helper that aborts on any other platform.

```php
public function getDescription(): string
{
    return 'Add user_ai_settings.max_batch_size and backfill slow connections (#445)';
}
```

Column: `ALTER TABLE user_ai_settings ADD max_batch_size INT DEFAULT NULL` (both platforms accept this form; check the sibling migration for the `ADD COLUMN` spelling SQLite wants).

Then the backfill, which is what keeps upgrade behaviour identical:

```sql
UPDATE user_ai_settings SET max_batch_size = 30 WHERE slow_model = 1
```

The literal 30 belongs in the migration, not a constant reference: a migration records what the schema was on that day and must not move when the code does.

- [ ] **Step 3: Verify the migration on a scratch database, never the dev one**

```bash
cd backend && php bin/console doctrine:migrations:migrate --no-interaction && php bin/console doctrine:schema:validate
```

Then confirm the same on MySQL through Docker.

- [ ] **Step 4: Commit**

```bash
git add backend/src/Entity/AiProviderSettings.php backend/migrations
git commit -m "feat(#445): give a connection its own batch cap"
```

---

### Task 5: The resolver reads the cap

**Files:**
- Modify: `backend/src/Service/Recommendation/RecommendationSettingsResolver.php`
- Modify: `backend/src/Service/Recommendation/RecommendationPackingSettings.php`
- Modify: `backend/tests/Service/Recommendation/RecommendationSettingsResolverTest.php`
- Modify: `backend/tests/Service/Recommendation/RecommendationPromptBuilderTest.php` (only if it references the deleted constant)
- Modify: `backend/tests/Service/Recommendation/RecommendationRunAdvancerTest.php` (only if `markConnectionSlow()` asserted on packing)

**Interfaces:**
- Consumes: `AiProviderSettings::maxBatchSize()`

- [ ] **Step 1: Rewrite the resolver tests**

`testAConnectionMarkedSlowPacksShorterBatches` no longer describes the code — marking a connection slow changes timeouts only. Replace the three existing cases with four:

1. A connection with no cap set keeps `DEFAULT_MAXIMUM_BATCH_SIZE`.
2. A connection with a cap of 30 packs 30.
3. No configuration at all falls back to `DEFAULT_MAXIMUM_BATCH_SIZE`.
4. A connection marked slow but with no cap set keeps `DEFAULT_MAXIMUM_BATCH_SIZE` — this is the regression test for the split, and it must fail before the change.

Update the `seedAiSettingsWithModel()` helper: it currently takes `bool $slowModel`. A boolean flag parameter plus a new int parameter is the wrong shape — give the helper a `?int $maxBatchSize` and let the two tests that genuinely need `slow_model` set it on the returned entity themselves.

- [ ] **Step 2: Run and watch case 4 fail**

- [ ] **Step 3: Implement**

```php
/**
 * How many candidates one batch may carry. A property of the endpoint, not
 * of the account's taste, so it is read off the connection rather than
 * offered as a recommendation setting: how long a list a model holds in
 * order is a property of its size and training (#437). No claim means the
 * default stands. Split off `slow_model` in #445, which now governs
 * timeouts alone.
 */
private static function batchCeilingFor(?AiProviderSettings $provider): int
{
    return $provider?->maxBatchSize() ?? RecommendationPackingSettings::DEFAULT_MAXIMUM_BATCH_SIZE;
}
```

Delete `RecommendationPackingSettings::SLOW_MODEL_MAXIMUM_BATCH_SIZE`. Fold what its docblock recorded into the entity property docblock from Task 4 and into the frontend help text in Task 7, so nothing is lost. Grep for remaining references before deleting.

- [ ] **Step 4: Run the recommendation test group**

```bash
cd backend && php bin/phpunit tests/Service/Recommendation
```

- [ ] **Step 5: Commit**

```bash
git add -A backend/src backend/tests
git commit -m "feat(#445): pack batches from the connection's own cap"
```

---

### Task 6: The API

**Files:**
- Create: `backend/src/Dto/Ai/SetMaxBatchSizeRequest.php`
- Modify: `backend/src/Service/Ai/AiConfigurationEditor.php`
- Modify: `backend/src/Service/Ai/AiProviderConfigurator.php` (`duplicateConfiguration()`)
- Modify: `backend/src/Http/AiSettingsJson.php` (`configuration()`)
- Modify: `backend/src/Controller/Api/AiSettingsController.php`
- Modify: `backend/tests/Controller/Api/AiSettingsControllerTest.php`
- Modify: `backend/tests/Service/Ai/AiProviderConfiguratorTest.php`

**Interfaces:**
- Consumes: `AiProviderSettings::setMaxBatchSize()`, `AiProviderSettings::MINIMUM_BATCH_SIZE`, `MAXIMUM_BATCH_SIZE`
- Produces: `PUT /api/me/ai/configs/{id}/max-batch-size`, route name `api_me_ai_set_max_batch_size`, response key `maxBatchSize`

Read `SetBatchConcurrencyRequest` and the `PUT /configs/{id}/batch-concurrency` action first — that pair is the closest existing precedent, and it carries a real `Range` where the slow-model one does not.

- [ ] **Step 1: Write the failing controller tests**

1. Default is null in `GET /api/me/ai`.
2. `PUT` with `{"maxBatchSize": 30}` returns 200 with the new value, and a follow-up `GET` agrees.
3. `PUT` with `{"maxBatchSize": null}` clears it back to null.
4. `PUT` with a value below `MINIMUM_BATCH_SIZE` and one above `MAXIMUM_BATCH_SIZE` are both 422.
5. Add `yield 'setting the batch cap' => ['PUT', '/max-batch-size', '{"maxBatchSize":30}'];` to `idBearingRoutes()` so the ownership-scoped 404 is covered.

In `AiProviderConfiguratorTest`, extend the duplicate test to assert the cap is carried.

- [ ] **Step 2: Run and watch them fail**

- [ ] **Step 3: Implement**

```php
final readonly class SetMaxBatchSizeRequest
{
    public function __construct(
        #[Assert\Range(
            min: AiProviderSettings::MINIMUM_BATCH_SIZE,
            max: AiProviderSettings::MAXIMUM_BATCH_SIZE,
        )]
        public ?int $maxBatchSize,
    ) {
    }
}
```

`Assert\Range` ignores null, so clearing stays legal without a second constraint. The controller action mirrors the slow-model one exactly: resolve through `$this->configuration->require($user, $id)`, map `ConfigurationNotFoundException` to `AiConfigurationNotFoundApiException`, call the editor, re-serialise with `AiSettingsJson::configuration(...)`. No private helpers on the controller.

- [ ] **Step 4: Run and watch them pass, then run the frontend contract check**

```bash
cd backend && php bin/phpunit tests/Controller/Api/AiSettingsControllerTest.php tests/Service/Ai
```

- [ ] **Step 5: Commit**

```bash
git add -A backend/src backend/tests
git commit -m "feat(#445): let an account set a connection's batch cap"
```

---

### Task 7: The settings UI

**Files:**
- Modify: `frontend/src/app/settings/ai-settings.service.ts`
- Modify: `frontend/src/app/settings/ai-section.component.ts`
- Modify: `frontend/src/app/settings/ai-section.component.html`
- Modify: `frontend/src/app/settings/ai-section.component.scss`
- Modify: `frontend/public/i18n/en.json`, `frontend/public/i18n/de.json`
- Modify: `frontend/src/app/settings/ai-settings.service.spec.ts`
- Modify: `frontend/src/app/settings/ai-section.component.spec.ts`

**Interfaces:**
- Consumes: `PUT /api/me/ai/configs/{id}/max-batch-size` from Task 6
- Produces: `AiConfig.maxBatchSize: number | null`, `AiSettingsService.setMaxBatchSize(id: number, maxBatchSize: number | null): void`

- [ ] **Step 1: Write the failing Jest tests**

In `ai-settings.service.spec.ts`, assert the PUT URL and the body `{ maxBatchSize: 30 }`, and that the returned config is upserted into the signal. In `ai-section.component.spec.ts`, assert the input renders the stored value, shows the default as its placeholder when the value is null, and calls through on change.

- [ ] **Step 2: Run and watch them fail**

```bash
cd frontend && npx jest src/app/settings
```

- [ ] **Step 3: Implement**

Add `readonly maxBatchSize: number | null;` to `AiConfig`. Add `setMaxBatchSize()` in the shape of the neighbouring `setSlowModel()` — same `run({ action: 'row', configId: id }, ...)` wrapper and `upsert` callback.

In the template, place a number input in the row that holds the slow toggle, with `[disabled]="ai.busy()"`, `min` and `max` matching the backend range, and an `<app-info-tip>` on the new help key. An empty field must send null, not `NaN` — read the input's `value` and treat `''` as null before calling the service.

Any new spacing in the `.scss` uses the design tokens; a raw `px` or a hex colour fails `npm run check`.

- [ ] **Step 4: Add the i18n keys to both locales**

`settings.ai.configs.maxBatchSize` — the label. `settings.ai.info.maxBatchSize` — the help text, which must carry the trade the deleted `SLOW_MODEL_MAXIMUM_BATCH_SIZE` docblock recorded: a shorter list is easier for a small model to hold in order and bounds what a runaway costs, but the history is re-sent with every batch, so smaller batches mean more calls and more prompt tokens.

Correct `settings.ai.info.slowModel` in both locales: it must claim timeouts only, and no longer imply it affects batch size.

- [ ] **Step 5: Run the frontend gate**

```bash
cd frontend && npm run check
```

- [ ] **Step 6: Commit**

```bash
git add -A frontend/src frontend/public
git commit -m "feat(#445): offer the batch cap beside the slow-model toggle"
```

---

# Part 1 — A lock bounded by liveness (#444, #439)

### Task 8: The keepalive and the composite heartbeat

**Files:**
- Create: `backend/src/Service/Recommendation/TickLockKeepalive.php`
- Create: `backend/src/Service/Recommendation/CompositeCompletionStreamHeartbeat.php`
- Modify: `backend/config/services.yaml` (the `CompletionStreamHeartbeat` alias)
- Test: `backend/tests/Service/Recommendation/TickLockKeepaliveTest.php`
- Test: `backend/tests/Service/Recommendation/CompositeCompletionStreamHeartbeatTest.php`

**Interfaces:**
- Consumes: `CompletionStreamHeartbeat::beat()`, `Symfony\Component\Lock\LockInterface::refresh()`
- Produces: `TickLockKeepalive::hold(LockInterface $lock): void`, `TickLockKeepalive::release(): void`, `TickLockKeepalive::MINIMUM_INTERVAL_SECONDS = 30`

Read `backend/src/Service/Worker/SweepStreamHeartbeat.php` and its test first. The keepalive is the same shape with a different effect, down to the injected `ClockInterface` and the `isDue()` throttle, and its test can follow `SweepStreamHeartbeatTest`'s `MockClock` cases exactly.

- [ ] **Step 1: Write the failing keepalive tests**

Using a `MockClock` and a lock double that counts refreshes:

1. A beat before `hold()` refreshes nothing.
2. The first beat after `hold()` refreshes.
3. A beat 5 s later does not.
4. A beat at exactly 30 s does.
5. After `release()`, a beat refreshes nothing.
6. `hold()` on a second lock resets the throttle, so the new lock is refreshed at once — a fresh tick must never inherit the previous tick's beat clock.
7. A `LockException` from `refresh()` does not escape `beat()`, and is logged.

- [ ] **Step 2: Write the failing composite test**

Beating the composite beats every member exactly once, in the order given.

- [ ] **Step 3: Run both and watch them fail**

```bash
cd backend && php bin/phpunit tests/Service/Recommendation/TickLockKeepaliveTest.php tests/Service/Recommendation/CompositeCompletionStreamHeartbeatTest.php
```

- [ ] **Step 4: Implement**

```php
/**
 * Keeps the lock of a tick that is streaming from expiring under it.
 *
 * The alternative is what #439 and #444 cost: a TTL sized for the longest
 * legal call, so a holder that dies mid-tick strands the run for hours. A
 * lock that a live holder refreshes can be sized against the longest
 * silence instead, and a dead holder stops refreshing within one beat.
 *
 * Not readonly: the held lock is the point.
 */
final class TickLockKeepalive implements CompletionStreamHeartbeat
{
    public const int MINIMUM_INTERVAL_SECONDS = 30;

    private ?LockInterface $held = null;
    private ?\DateTimeImmutable $lastRefreshAt = null;

    public function __construct(
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function hold(LockInterface $lock): void
    {
        $this->held = $lock;
        $this->lastRefreshAt = null;
    }

    public function release(): void
    {
        $this->held = null;
        $this->lastRefreshAt = null;
    }

    public function beat(): void
    {
        if (null === $this->held || !$this->isDue()) {
            return;
        }

        $this->lastRefreshAt = $this->clock->now();

        try {
            $this->held->refresh();
        } catch (LockException $failure) {
            // The tick is still working and its own release still runs. A
            // lock lost here means the TTL lapsed, which the refresh exists
            // to prevent — worth a line, not worth a second failure.
            $this->logger->warning('Could not refresh the recommendation tick lock', ['exception' => $failure]);
        }
    }

    private function isDue(): bool
    {
        // Same throttle as SweepStreamHeartbeat: a streamed answer costs a
        // couple of writes a minute, not one per chunk.
    }
}
```

```php
/**
 * One beat, several listeners. The transport pings a single heartbeat on
 * every chunk and should not learn how many things care.
 */
final readonly class CompositeCompletionStreamHeartbeat implements CompletionStreamHeartbeat
{
    /**
     * @param iterable<CompletionStreamHeartbeat> $heartbeats
     */
    public function __construct(private iterable $heartbeats)
    {
    }

    public function beat(): void
    {
        foreach ($this->heartbeats as $heartbeat) {
            $heartbeat->beat();
        }
    }
}
```

In `config/services.yaml`, replace the line that aliases `CompletionStreamHeartbeat` to `SweepStreamHeartbeat` with an alias to the composite, and give the composite its two members explicitly. Keep the existing comment style — the reason for the explicit wiring is that the members are visible at the one place the indirection applies.

Both `SweepStreamHeartbeat` and `TickLockKeepalive` must stay injectable by their own class name, because their arming methods are called by name from the sweep and from the advancer.

- [ ] **Step 5: Run both and watch them pass, then warm the cache and check the container**

```bash
cd backend && php bin/console cache:warmup && php bin/phpunit tests/Service/Recommendation tests/Service/Worker
```

- [ ] **Step 6: Commit**

```bash
git add -A backend/src backend/tests backend/config
git commit -m "feat(#444): let a streaming tick hold its own lock alive"
```

---

### Task 9: Arm the keepalive and resize the TTL

**Files:**
- Modify: `backend/src/Service/Recommendation/RecommendationRunAdvancer.php` (`advance()`, `lockTtlFor()`)
- Modify: `backend/tests/Service/Recommendation/RecommendationRunAdvancerTest.php`

**Interfaces:**
- Consumes: `TickLockKeepalive::hold()` / `release()`

- [ ] **Step 1: Rewrite the TTL test**

`testLockTtlOutlivesTheWorstCaseMultiRoundTick` asserts the old invariant and must go. Replace it with `testLockTtlClearsTheLongestSilenceALiveHolderCanProduce`, over the same `TtlRecordingLockFactory` and the same `timeoutProfiles` data provider, but with the cases changed to `firstByteSeconds` — `[false, 180.0]` and `[true, 900.0]` — asserting the recorded TTL is at least that, and additionally at least the 240 s Strato request cap.

Add `testATickThatStreamsRefreshesItsLock`: drive `advance()` with a stubbed provider call that beats the container's `CompletionStreamHeartbeat`, and assert the lock's remaining lifetime moved. Add `testTheKeepaliveIsReleasedAfterATick`: after `advance()` returns, a beat refreshes nothing.

- [ ] **Step 2: Run and watch them fail**

- [ ] **Step 3: Implement**

In `advance()`, arm around the tick and release in the `finally` beside the lock release:

```php
$this->keepalive->hold($lock);

try {
    return $this->tick($user, $driver);
} finally {
    $this->keepalive->release();
    $lock->release();
}
```

Release the keepalive **before** the lock, so no beat can refresh a lock that is being released.

In `lockTtlFor()`, return `$timeouts->firstByteSeconds + self::LOCK_TTL_MARGIN_SECONDS`. Rewrite the docblock: it currently records the multi-hour stall as an accepted tradeoff, and that tradeoff is no longer being made. The new invariant to record is that a live holder refreshes at least every `TickLockKeepalive::MINIMUM_INTERVAL_SECONDS`, so the TTL only has to clear the longest silence a live holder can produce — one first-byte wait — and the margin also clears Strato's 240 s cap on a web request that dies before any chunk arrives.

`RecommendationRun::MAX_ATTEMPTS` leaves the formula. Check whether the `use` for it is still needed.

- [ ] **Step 4: Run the advancer tests and the whole recommendation group**

```bash
cd backend && php bin/phpunit tests/Service/Recommendation tests/Controller/Api/RecommendationRunControllerTest.php tests/Command/RecommendationDrainCommandTest.php
```

`RecommendationDrainCommandTest` asserts the drain lock TTL against `MAX_ATTEMPTS * ProviderTimeouts::standard()->wallClockSeconds`. That is the drain command's own lock, which this task does not change — if it breaks, the cause is a shared helper, not the intended change.

- [ ] **Step 5: Commit**

```bash
git add -A backend/src backend/tests
git commit -m "fix(#444): size the tick lock against silence, not against patience"
```

---

### Task 10: Report a lock held by nobody

**Files:**
- Modify: `backend/src/Service/Recommendation/RecommendationRunReport.php`
- Modify: `backend/src/Service/Recommendation/RecommendationPollDriver.php`
- Modify: `backend/src/Http/RecommendationRunStatusJson.php`
- Modify: `backend/src/Service/Recommendation/RecommendationRunAdvancer.php` (log the stall)
- Modify: `backend/tests/Controller/Api/RecommendationRunControllerTest.php`

**Interfaces:**
- Produces: `waitingForLock` in the run status JSON

Read `RecommendationPollDriver::poll()` first. It already distinguishes the two cases — a fresh worker heartbeat at the top, and a `busy` from `advance()` below — and this task only has to carry the difference outward.

- [ ] **Step 1: Write the failing tests**

In `RecommendationRunControllerTest`:

1. `testATickThatFindsTheLockHeldReportsTheRunAsSomebodyElsesWork` already holds the lock and asserts `background: true`; extend it to assert `waitingForLock: true`.
2. `testTickDefersToAFreshWorkerHeartbeat` asserts `waitingForLock: false` — a worker owning the run is not a stall.
3. A run with no contention at all reports `false`.

- [ ] **Step 2: Run and watch them fail**

- [ ] **Step 3: Implement**

Add the field to `RecommendationRunReport` and its `toArray()`, and to `RecommendationRunStatusJson`. Keep `RecommendationRunReport` immutable — follow whatever `inBackground()` already does to produce a modified copy rather than adding a setter.

Set it in the poll driver only on the `busy` branch, and only when the presence read is stale. The fresh-heartbeat branch above it must leave it false.

In the advancer, log the failed acquire at warning with the lock name and the driver, so the stall leaves a trace in `dev.log`. Do not attempt to log an expiry: `Lock::getRemainingLifetime()` is populated from the key only after a successful acquire, so a process that failed to acquire cannot read the holder's.

- [ ] **Step 4: Run and watch them pass**

- [ ] **Step 5: Commit**

```bash
git add -A backend/src backend/tests
git commit -m "feat(#439): tell the client a lock is held with nobody behind it"
```

---

### Task 11: Say so in the UI

**Files:**
- Modify: `frontend/src/app/reader/models.ts`
- Modify: `frontend/src/app/reader/recommendations.service.ts`
- Modify: `frontend/src/app/reader/for-you-progress/for-you-progress.component.ts`
- Modify: `frontend/public/i18n/en.json`, `frontend/public/i18n/de.json`
- Modify: `frontend/src/app/reader/recommendations.service.spec.ts`
- Modify: `frontend/src/app/reader/for-you-progress/for-you-progress.component.spec.ts`

**Interfaces:**
- Consumes: `waitingForLock` from Task 10

- [ ] **Step 1: Write the failing Jest tests**

`etaState` returns the new state when the report carries `waitingForLock: true`, and does not when it is false or absent. The rate-limit `'waiting'` state keeps precedence over it if both are set — a 429 is the more actionable message.

`ForYouProgressComponent` renders the new phrase for that state.

- [ ] **Step 2: Run and watch them fail**

```bash
cd frontend && npx jest src/app/reader
```

- [ ] **Step 3: Implement**

Add `readonly waitingForLock?: boolean;` to the report interface — optional, so a cached response from an older backend does not break the type. Add the state to `etaState`'s union and map it in `ForYouProgressComponent`'s existing switch.

- [ ] **Step 4: Add `reader.eta.lockHeld` to both locales**

English: say that another process holds the run and this one is waiting, in the voice of the neighbouring `reader.eta` phrases. German likewise.

- [ ] **Step 5: Run the frontend gate**

```bash
cd frontend && npm run check
```

- [ ] **Step 6: Commit**

```bash
git add -A frontend/src frontend/public
git commit -m "feat(#439): name the stall instead of showing a run that is not moving"
```

---

### Task 12: Documentation and the full gate

**Files:**
- Modify: `docs/` — grep for `slow_model`, `slowModel`, `SLOW_MODEL_MAXIMUM_BATCH_SIZE` and the drainer spawn, and correct every page that now describes something untrue. `docs/architecture.md` and the AI settings page docs are the likely hits.
- Modify: `docs/superpowers/plans/2026-08-16-recommendation-run-resilience.md` — tick the boxes.

- [ ] **Step 1: Correct the docs**

Anything that says `slow_model` changes batch size is now wrong. Anything that describes the drainer spawn as happening inside the run starter is now wrong. Anything that quotes the old lock TTL is now wrong.

- [ ] **Step 2: Run every gate**

```bash
cd backend && composer check && composer md && php bin/phpunit
```

```bash
cd frontend && npm run check
```

```bash
docker compose exec -T php vendor/bin/phpunit
```

```bash
cd backend && composer infection:diff
```

Scan `backend/var/log/dev.log` for deprecations and swallowed errors after the backend runs.

- [ ] **Step 3: Verify the migration leg on both platforms from empty**

The suite builds its schema from ORM metadata, so no test ever executes the migration. Migrate from empty on SQLite and on MySQL, then `doctrine:schema:validate` on both.

- [ ] **Step 4: Commit**

```bash
git add -A docs
git commit -m "docs(#445): correct what the slow-model flag governs"
```

---

## Self-review notes

- **Spec coverage.** Part 1 → Tasks 8–11. Part 2 → Tasks 4–7. Part 3 → Tasks 1–3. Testing section → folded into each task plus Task 12. The spec's "out of scope" (why the worker was recycled) has no task, by design.
- **Naming consistency.** `maxBatchSize()` (accessor, no `get`), `setMaxBatchSize()`, `maxBatchSize` (JSON and TypeScript), `max_batch_size` (column), `api_me_ai_set_max_batch_size` (route). `hold()` / `release()` on the keepalive, `hasActiveRun()` on the repository, `waitingForLock` end to end.
- **Deliberate omission.** No task shortens `WorkerPresence::FRESH_SECONDS`. It is sized against the slow first-byte bound, which this work does not change.
