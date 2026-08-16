# Recommendation run resilience — design

Date: 2026-08-16
Issues: #444, #439, #445, #393
Branch: `feature/439-recommendation-run-resilience`

Four issues that all touch how a recommendation run is driven and how long a
failure costs. They ship together because they share the same files and the same
tests, not because they share a cause.

- **#444 / #439** — a worker that dies mid-tick strands the run for the whole
  lock TTL, up to 3 h 05 m on the slow profile.
- **#445** — `slow_model` means three things; only one of them is timeouts.
- **#393** — the drainer spawn sits inside the run starter and the maintenance
  tick, and pays for its placement with a process-scoped flag.

---

## Part 1 — A lock bounded by liveness, not by patience (#444, #439)

### The defect

`RecommendationRunAdvancer::advance()` sets the lock TTL once, at acquire, and
never extends it:

```php
return RecommendationRun::MAX_ATTEMPTS * $timeouts->wallClockSeconds + self::LOCK_TTL_MARGIN_SECONDS;
```

That is 2100 s on the standard profile and 11 100 s on the slow one. The TTL is
sized for the worst case a live holder can legally take, because the invariant it
protects is that no second process may steal the lock mid-tick — `RecommendationRun`
has no optimistic-version guard, and two concurrent ticks would double-bank
winners and double-bill provider spend.

The `finally` release covers a normal return, and the `register_shutdown_function`
hook covers a request killed by `max_execution_time`. Neither runs when the
container goes away. The TTL is the backstop, and the backstop is three hours.

### The fix

Refresh the lock while the holder is alive, and size the TTL against the longest
silence a live holder can produce instead of against the longest legal call.

**`TickLockKeepalive`** — new, `Service/Recommendation/`, implements
`CompletionStreamHeartbeat`.

- `hold(Lock $lock): void` and `release(): void`, armed by
  `RecommendationRunAdvancer::advance()` around `tick()` and disarmed in the
  `finally`.
- `beat()` returns immediately unless armed, throttles to one write per
  `MINIMUM_INTERVAL_SECONDS = 30` against the injected clock — the same shape as
  `SweepStreamHeartbeat` — and calls `Lock::refresh()`.
- A `LockException` from `refresh()` is logged and swallowed. A lost lock must
  not raise a second failure inside a tick that is still working; the tick's own
  `finally` still runs.

**`CompositeCompletionStreamHeartbeat`** — new, `Service/Recommendation/`,
implements `CompletionStreamHeartbeat` and fans `beat()` to the heartbeats it is
given. Wired explicitly in `config/services.yaml` beside the existing alias, so
the two members are visible at the one place the indirection applies:

```yaml
App\Service\Recommendation\CompletionStreamHeartbeat: '@App\Service\Recommendation\CompositeCompletionStreamHeartbeat'
App\Service\Recommendation\CompositeCompletionStreamHeartbeat:
    arguments:
        $heartbeats:
            - '@App\Service\Worker\SweepStreamHeartbeat'
            - '@App\Service\Recommendation\TickLockKeepalive'
```

Each class keeps one job: one marks worker presence, one holds a lock.
`OpenAiCompatibleChatClient` is unchanged — it still pings one
`CompletionStreamHeartbeat` on every chunk.

Because the **advancer** arms the keepalive rather than the sweep, the poll path
gets refreshes too. #444 assumed the beats would come from `SweepStreamHeartbeat`,
which is armed only for a sweep, and concluded a poll tick would get none. Arming
from the advancer removes that caveat.

**The new TTL:**

```php
private function lockTtlFor(User $user): float
{
    ...
    return $timeouts->firstByteSeconds + self::LOCK_TTL_MARGIN_SECONDS;
}
```

- standard: 180 + 300 = **480 s** (was 2100 s)
- slow: 900 + 300 = **1200 s** (was 11 100 s)

`RecommendationRun::MAX_ATTEMPTS` leaves the formula. The invariant it protects
is unchanged in kind and stronger in practice: a live holder refreshes at least
every 30 s, so the lock can only lapse if the holder falls silent for the whole
TTL. The longest silence a live holder can produce is one first-byte wait —
between attempts and between waves the work is bookkeeping and persistence,
which is sub-second. The 300 s margin also clears Strato's 240 s cap on a web
request, which is the floor for a poll tick that dies before any chunk arrives.

### Surfacing the stall (#439 direction 3)

Today `busy` never leaves the backend: `RecommendationPollDriver` swaps it for
the latest persisted report with `background: true`. A lock held by a dead
process is therefore indistinguishable from a worker doing the work.

`RecommendationRunReport` gains `waitingForLock`, set when `advance()` returns
busy **and** `WorkerPresence::isAnybodyDrivingRecommendationRuns()` is stale —
a lock held with nobody beating. It rides through `toArray()` and
`RecommendationRunStatusJson`. The frontend model gains the field,
`RecommendationsService.etaState` gains a state for it, and
`ForYouProgressComponent` maps that to a new `reader.eta` key in `en.json` and
`de.json`. The advancer logs the stall.

**Correction to the issue's wording:** #439 asks for the message to carry the
expiry. `Lock::getRemainingLifetime()` is populated from the key only after a
successful acquire, so a process that failed to acquire cannot read the holder's
expiry without querying the lock store's own table. The UI reports that the lock
is held, not when it frees. Reaching into `lock_keys` from application code is
not worth the coupling.

### Out of scope

Why the worker went away at all (#444's open question) is not addressed. This
work makes the failure survivable; it does not diagnose the recycling.

---

## Part 2 — `slow_model` governs timeouts only (#445)

### The split

`slow_model` keeps the timeouts, and the lock TTL that derives from them. The
batch cap moves to its own per-connection column.

**Entity** — `AiProviderSettings::$maxBatchSize`, **nullable** int:

```php
#[ORM\Column(nullable: true)]
private ?int $maxBatchSize = null;
```

Null means "no claim, the default stands". That is exactly what
`batchCeilingFor()`'s docblock already argues for an absent connection, it keeps
the default in one place, and it keeps a service-layer constant out of the
entity:

```php
private static function batchCeilingFor(?AiProviderSettings $provider): int
{
    return $provider?->maxBatchSize() ?? RecommendationPackingSettings::DEFAULT_MAXIMUM_BATCH_SIZE;
}
```

`RecommendationPackingSettings::SLOW_MODEL_MAXIMUM_BATCH_SIZE` is deleted. The
trade it records — the history sections are re-sent with every batch, so smaller
batches mean more calls and more prompt tokens — moves into the new setting's
help text, and the #437 failure it guards against moves into the entity
property's docblock.

**Migration** — nullable column, then backfill so no connection changes
behaviour on upgrade:

```sql
UPDATE user_ai_settings SET max_batch_size = 30 WHERE slow_model = 1
```

Same shape as `Version20260816160000`: non-transactional, idempotent via
`hasColumn()`, MySQL/SQLite aware.

**API** — `PUT /api/me/ai/configs/{id}/max-batch-size`, route name
`api_me_ai_set_max_batch_size`, `SetMaxBatchSizeRequest` with a nullable int and
`#[Assert\Range(min: 5, max: 200)]` (Range ignores null, so clearing the setting
stays legal). `AiSettingsJson::configuration()` gains `maxBatchSize`,
`AiConfigurationEditor` gains `setMaxBatchSize()`, and
`AiProviderConfigurator::duplicateConfiguration()` copies it.

The bound is a sanity bound, not a quality bound: 200 is far above anything a
model ranks well, and it stops a typo sending five thousand candidates in one
call. The real guard on batch size remains the token budget.

**Frontend** — `AiConfig.maxBatchSize: number | null`,
`AiSettingsService.setMaxBatchSize()`, a number input beside the slow toggle with
the default as its placeholder, an `app-info-tip`, and label plus help text in
`en.json` and `de.json`. The `slow_model` help text stops implying it affects
anything but timeouts.

---

## Part 3 — The drainer spawn moves to a terminate listener (#393)

### Shape

`RecommendationDrainOnTerminateListener` — new, `src/EventListener/`, with
`#[AsEventListener(event: TerminateEvent::class)]` and
`#[AsEventListener(event: ConsoleTerminateEvent::class)]`, mirroring
`DeferredMailFlushListener`.

It asks the cheap question first:

1. Is any run active? A new `RecommendationRunRepository::hasActiveRun()`, an
   indexed existence read that normally answers no.
2. Only then, `RecommendationDrainSpawner::spawnIfNoWorker()`.

`RecommendationDrainSpawner::$launched` is deleted and the class becomes
`final readonly` — once-per-process becomes structural, because the listener
fires once per request or command. `RecommendationRunStarter` and
`MaintenanceTick` lose the dependency and return to their single jobs, and
`RecordingProcessLauncher` leaves both their tests.

### Two guards the current placement encodes implicitly

- **A closed entity manager.** `MaintenanceTick::run()` returns before the spawn
  when the refresh aborted, because the EM is shut and even the heartbeat read is
  off-limits (asserted in `MaintenanceTickTest`). A listener that queries
  unconditionally would fatal there. It checks `isOpen()` and returns, and wraps
  the rest in a `Throwable` catch that logs — a failure to spawn must not turn a
  served response into an error.
- **The drainer's own exit.** `app:recommendations:drain` surrenders its liveness
  key before terminating, so at `ConsoleTerminateEvent` it looks absent and the
  listener would fork a successor at every drain exit. The listener skips that
  command by name.

### What this closes

`POST /api/recommendations/runs/tick` gets the respawn net, which is #393's
stated new behaviour. `/maintenance/recommendations/sweep` gets it too — it calls
`ForYouSweep::sweepOnce()` directly and has no net today either, which neither
issue names.

The exec still detaches immediately (`&` plus `/dev/null` redirects), so the
weaker post-flush timing on Strato's `cgi-fcgi` — where `fastcgi_finish_request()`
does not exist — costs nothing that the current placement does not already cost.

---

## Testing

**Part 1**

- `TickLockKeepaliveTest` — unarmed beats do nothing; the first armed beat
  refreshes; a beat inside 30 s does not; a beat at exactly 30 s does; `release()`
  stops refreshes; a throwing `refresh()` is swallowed and logged.
- `CompositeCompletionStreamHeartbeatTest` — every member is beaten.
- `RecommendationRunAdvancerTest` — replace
  `testLockTtlOutlivesTheWorstCaseMultiRoundTick` with a test that the TTL clears
  `firstByteSeconds` on both profiles, over the existing `TtlRecordingLockFactory`.
  Add a test that a tick whose provider call streams refreshes the lock, and one
  that a run held by a stale lock reports `waitingForLock`.
- `RecommendationRunControllerTest` — the lock-held tick carries
  `waitingForLock: true`, and a tick deferring to a **fresh** worker heartbeat
  carries `false`.
- Frontend Jest — `recommendations.service.spec.ts` for the new eta state,
  `for-you-progress.component.spec.ts` for the phrase.

**Part 2**

- `RecommendationSettingsResolverTest` — null means the default; a set cap is
  honoured; no connection falls back to the default. The "marked slow packs
  shorter batches" test is replaced, since marking slow no longer changes packing.
- `AiSettingsControllerTest` — the new route round-trips, rejects out of range,
  accepts null, and is ownership-scoped 404 (add to `idBearingRoutes()`).
- `AiProviderConfiguratorTest` — duplicate carries the cap.
- Migration leg — the backfill sets 30 for slow rows and leaves the rest NULL, on
  both SQLite and MySQL. `tests/bootstrap.php` builds from ORM metadata, so this
  needs the dedicated CI migration leg, not the suite.

**Part 3**

- `RecommendationDrainOnTerminateListenerTest` — against the real kernel, in the
  shape of `DeferredMailFlushListenerTest`: `handle()` does not spawn,
  `terminate()` does; no active run means no query past the existence read; a
  fresh worker heartbeat suppresses; a closed EM is survived; the drain command's
  own terminate does not spawn.
- `RecommendationDrainSpawnerTest` — drop the once-per-process test, which no
  longer describes the class.
- `MaintenanceTickTest`, `RecommendationRunStarterTest` — drop
  `RecordingProcessLauncher` and the spawn assertions.
- Functional — a `/tick` on an active run with no live drainer spawns one.

**Gates** — `composer check`, `composer md`, `php bin/phpunit` natively and in
Docker, `composer infection:diff`, and `npm run check` in `frontend/`.

---

## Order of work

1. Part 3 (#393) — self-contained, touches the fewest files the others need.
2. Part 2 (#445) — entity, migration, API, frontend.
3. Part 1 (#444, #439) — the keepalive and the TTL, which reads the timeouts
   Part 2 leaves in place.

Parts 2 and 3 are independent. Part 1 shares `RecommendationRunAdvancerTest` with
nothing else, but its TTL test reads `ProviderTimeouts`, which Part 2 does not
change.
