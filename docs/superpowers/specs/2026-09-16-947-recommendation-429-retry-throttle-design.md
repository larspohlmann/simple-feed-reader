# Handle provider 429 during recommendation runs (#947)

Wait, retry, and lower the call rate when a hosted provider rate-limits a run.

## Problem

During a recommendation run a hosted, OpenAI-compatible provider (an
OpenRouter-style endpoint) sometimes answers `POST /chat/completions` with
status 429. A local LM Studio server never does. Today the run has no outbound
retry, backoff, or throttle:

- `OpenAiCompatibleChatClient::guardStatus()` maps any status of 300 or more
  (except 401 and 403) to `ProviderUnreachableException`. A 429 is not distinct
  from a 500 or a redirect.
- In the batch wave a single failed call aborts the whole wave atomically
  (`RecommendationBatchWave::guardWaveTransport()`), banks nothing, and re-runs
  next tick from the unmoved cursor (#344).
- The failure counts as one transport strike. Three strikes fail the run
  (`RecommendationRun::MAX_TRANSPORT_FAILURES`).
- A wave fires up to `batchConcurrency` simultaneous requests with no spacing.

A transient 429 therefore burns strikes and can fail a run that would have
succeeded a few seconds later.

## Goal

Make a run survive a provider rate limit. When the provider signals a retryable
status, wait and retry. Also lower the call rate for the rest of the run, so a
later wave does not provoke the limit again.

## Scope

- Recommendation runs only. The model-listing path in `OpenAiCompatibleCatalog`
  keeps its current fast-fail: an interactive settings save must give a fast,
  clear error, not a silent multi-second wait.
- Backend only. The frontend already re-polls every 4 seconds while a worker
  owns the run, and backs off on the poll endpoint's own rate limiter; no
  frontend change is needed for the defer path.

## Retryable statuses

`guardStatus()` throws a new retryable exception for **429, 502, 503, 504**. It
carries the parsed `Retry-After` value when the response supplies one. All other
error statuses keep the current fast-fail. Status 401 and 403 keep their
credentials exception.

## The three drivers

`TickDriver` has three cases. Their regimes decide who may block:

| Driver | Runs inside | On a retryable status |
|---|---|---|
| `Worker` | its own process (Docker `worker` service; Strato cgi-fcgi drainer) | blocks and retries in-tick, within a budget |
| `Poll` | a bounded web request | never blocks; defers to a later tick |
| `Sweep` | a cron-triggered web request | never blocks; defers to a later tick |

Production always runs a `Worker`-driver backstop (the Docker worker or the
Strato drainer). `Poll` and `Sweep` drive a run only when nobody else does.

## Design decisions

### 1. React and throttle

On a retryable status the run both retries after a wait and, for the batch wave,
lowers its concurrency for the rest of the run.

### 2. Retry re-fires only the rate-limited calls

When a concurrent wave meets a 429 on a subset of its calls, only those calls are
re-sent. The calls that already answered keep their answers. This spares a paid
provider the re-bill of discarded work and does not add load to the very limit we
hit. The retry loop therefore lives next to the concurrent reader, in a new
`RateLimitedCompletion` collaborator, not in the advancer.

### 3. Backoff schedule

When `Retry-After` is present, the handler honours it. When it is absent, the
handler uses exponential backoff: 1 second, then 2, then 4. The worker makes at
most 3 attempts per call per tick.

### 4. Blocking budget, split by driver

Production nginx puts no `fastcgi_read_timeout` on `/api/`, so the poll request
is bounded by the nginx default of 60 seconds. Strato adds a 240-second cgi-fcgi
cap.

- The **poll and sweep drivers never block**. On a retryable status they record a
  "retry not before T" timestamp on the run and return at once. A later tick, or
  the worker, resumes after T.
- The **worker driver blocks in-tick**, up to a total-wait budget of about 120
  seconds. This stays under the Strato 240-second cap, and no proxy sits in front.

A `Retry-After` larger than the budget is never blocked on by anyone. When the
next wait would cross the budget, the worker also defers through "retry not
before T" and picks the run up on its next pass. The worker blocks only for waits
that fit inside the budget.

### 5. Concurrency reduction

Each observed 429 halves the wave concurrency. The floor is 1. The reduced value
persists on the run for the rest of the run and never climbs back up inside the
same run. A new run starts at the full concurrency. A resumed run also starts
fresh (see decision 8). The reduction fires even when the retry then recovers.

The reduction applies **only to the batch wave**. The single-call phases
(distillation, consolidation) send one call and have no wave concurrency to lower.

### 6. Strikes

- A retry that succeeds burns no transport strike.
- The worker exhausting its 3 in-tick attempts marks the outcome as a plain
  provider failure (`ProviderUnreachableException`, whose message names the last
  429/5xx status). `guardWaveTransport()` and the advancer's existing
  transport-strike path then fire unchanged and burn one strike. The existing
  `MAX_TRANSPORT_FAILURES = 3` limit still fails the run. No new catch is added to
  the strike path.
- The poll and sweep drivers never strike on a rate limit. They defer. On a
  worker-less install a permanently rate-limited run therefore stays `running`
  until the provider recovers or a worker takes over. This is an accepted
  property of a degraded, unsupported config: the defer returns a normal running
  report, so the frontend keeps showing the run as in progress rather than
  failing it.

### 7. The gate

At the start of a tick, if now is before the run's "retry not before T", the tick
makes no provider call and returns the running report. The gate paces the retries
and stops a cheap poll from re-firing early. It applies to every driver.

### 8. Reads and resets

- `waveSize()` clamps to the reduced concurrency, in addition to the driver
  regime and the batches the plan has left.
- A banked batch, a recorded profile, and a completed run clear "retry not before
  T". The reduced concurrency persists across those.
- `resume()` clears both fields: a resumed run starts fresh at full concurrency,
  consistent with resume already clearing the attempt and strike counters.

## New persisted state on `RecommendationRun`

A `RunThrottle` embeddable, mapped with `columnPrefix: false`, mirrors the
existing `RunBatchProgress` and `RunProfile` embeddables and keeps the entity's
field count down.

| Column | Type | Meaning |
|---|---|---|
| `retry_not_before` | datetime, nullable | the gate; no provider call before this time |
| `reduced_concurrency` | int, nullable | the halved wave cap for the rest of the run |

A migration adds both columns, platform-aware for MySQL and SQLite. Because
`tests/bootstrap.php` builds the schema from ORM metadata, the migration is
exercised only by CI's migrate-from-empty leg — it needs its own verification.

## Components

### New

- `App\Service\Ai\Exception\RetryableProviderException` — `\RuntimeException` with
  `int $status` and `?int $retryAfterSeconds`.
- `App\Service\Recommendation\RetryPlan` — value object built from `TickDriver`.
  Exposes whether it blocks, the max attempts, the budget, and
  `waitSecondsFor(int $attempt, ?int $retryAfterSeconds): float`
  (`Retry-After` when present, else the backoff step).
- `App\Service\Recommendation\RateLimitedCompletion` — over `ChatCompletionClient`
  and `ClockInterface`. Runs the calls; for a blocking plan it waits and re-fires
  only the still-retryable calls, within the attempt and budget limits. Returns
  one of: *completed* (final outcomes, plus whether a 429 was observed; a call the
  worker retried to exhaustion is turned into a plain `ProviderUnreachableException`
  failure outcome, so nothing downstream needs to know it began as a 429) or
  *deferred* (the wait to apply, when the plan defers or the next wait would cross
  the budget).
- `App\Service\Recommendation\Exception\RecommendationRunRateLimitedException` —
  carries the wait in seconds. The wave and the single-call wrapper throw it to
  hand the deferral up to the advancer.
- `App\Entity\RunThrottle` — the embeddable above, with `deferUntil()`,
  `mustWait(now)`, `reduceConcurrency(int $currentCap)`, `effectiveCap(int
  $configured)`, `clearDeferral()`, and `reset()`.

### Changed

- `OpenAiCompatibleChatClient::guardStatus()` — throw `RetryableProviderException`
  for 429/502/503/504, reading `Retry-After` from the response headers (headers
  are readable at `$chunk->isFirst()` via `getHeaders(false)`).
- `OpenAiCompatibleChatClient::advance()` — catch `RetryableProviderException` and
  fold it into a per-call failure outcome, so per-call isolation and the
  atomic-wave shape are preserved.
- `CompletionOutcome` — add `isRetryable()` (cause is a
  `RetryableProviderException`) and `retryAfterSeconds()`.
- `RecommendationProviderCall::complete()` — route through `RateLimitedCompletion`
  with the plan; on a deferred result, settle the recorded row and throw the
  deferral exception.
- `RecommendationBatchWave` — route the round through `RateLimitedCompletion` with
  the plan; on a deferred result, settle the round's rows and throw the deferral
  exception; return whether a 429 was observed alongside the winners; a worker's
  exhausted call arrives already converted to a `ProviderUnreachableException`
  outcome and flows through `guardWaveTransport()` as the hard failure it is.
- `RecommendationRunAdvancer` — build the plan from the driver; gate at tick start;
  in the batch phase, halve on any observed 429, catch the deferral to write
  "retry not before T", and keep the existing strike path for a hard failure; in
  the single-call phases, catch the deferral (no halving) and keep the strike
  path; clamp `waveSize()` to the reduced cap.
- `RecommendationRun` — embed `RunThrottle`; expose the gate, the halve, the
  effective-cap read, and the deferral write; clear the deferral on progress and
  reset the throttle on resume.

### Unchanged

- `OpenAiCompatibleCatalog` (model listing) keeps its fast-fail (decision under
  Scope).
- The frontend.

## Control flow

### Batch wave (worker)

1. `providerTick` builds the worker plan and computes `waveSize` from the reduced
   cap.
2. The wave runs the round through `RateLimitedCompletion`.
3. A subset answers 429. The collaborator waits (`Retry-After` or 1/2/4) and
   re-fires only those calls, up to 3 attempts within ~120s.
4. On recovery: the wave returns the winners with "429 observed" true. The
   advancer halves the run's concurrency, banks the winners, clears any deferral,
   and flushes.
5. On exhaustion: the outcomes carry a hard failure. `guardWaveTransport()` throws
   it; the advancer halves, records one strike, and re-throws. Three strikes fail
   the run.
6. On a wait beyond budget: the collaborator returns *deferred*. The wave throws
   the deferral. The advancer halves, writes "retry not before T", and returns the
   running report — no strike.

### Batch wave (poll / sweep)

1. `providerTick` builds the deferring plan.
2. The wave runs the round. A 429 appears. The collaborator does not wait; it
   returns *deferred* with the wait.
3. The wave throws the deferral. The advancer halves, writes "retry not before
   T", and returns the running report — no strike.
4. A later tick hits the gate until T passes, then re-runs the wave at the reduced
   cap.

### Single-call phases (distillation, consolidation)

Same as the wave, without the halving. The blocking plan retries in-tick and, on
exhaustion, leaves the hard failure the existing code already turns into a strike.
The deferring plan defers.

## Acceptance

- A run that meets one or more transient 429/502/503/504 responses and then
  recovers completes successfully and burns no strike.
- The wave concurrency halves after the first observed 429 and stays reduced for
  the rest of that run.
- A poll or sweep tick never blocks past the 60-second nginx ceiling; it defers
  through "retry not before T" instead.
- A worker tick honours `Retry-After` up to the ~120-second budget, and defers a
  longer wait rather than blocking or striking.
- The worker fails a persistently rate-limited run through the existing three
  strikes.
- The model-listing path keeps its current fast-fail.
- Mutation testing gates the touched files (`composer infection:diff`). The
  backoff schedule, the `Retry-After` precedence, the halving, the floor of 1, the
  gate, the strike-on-exhaustion, and the worker-blocks-vs-poll-defers split each
  need a killing test.

## Testing notes

- Direct-invocation tests mislead: back any listener/driver assertion with a
  functional test that runs the real wiring.
- Datetimes are stored as naive UTC; compute "retry not before T" from the
  injected clock and persist a UTC value.
- The migration needs its own check on both SQLite and MySQL (schema-validate leg).
- Anything that runs tests in parallel must set `TEST_TOKEN`.
