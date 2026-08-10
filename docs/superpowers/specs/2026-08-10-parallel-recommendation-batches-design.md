# Parallel Recommendation Batch Calls — Design

**Issue:** [#344](https://github.com/larspohlmann/simple-feed-reader/issues/344)
**Date:** 2026-08-10

## Goal

Let a recommendation run's **batch phase** send several provider calls at
once, bounded by a per-connection cap, so the run's wall-clock stops being the
sum of every batch call. The resumable state machine, the dedup barrier, the
#329 degrade logic, and the #336 ETA surface stay intact.

## Motivation

A run makes **one provider call per tick**. The default 500-candidate pool at
batch size ≤ 45 is ~12 batch calls plus one dedup call, all sequential, on both
drivers (worker sweep and browser poll). The batch calls are independent — each
scores its own candidates against a shared rubric, and ranking happens in code
(`RecommendationWinnerRanker`), not in the model. So the batch phase is a safe
place to run calls concurrently. Against a hosted, horizontally-scaled provider
this is a real speed-up; against a single-GPU local model it is a no-op that the
low default cap keeps safe.

## Agreed decisions

| Decision | Resolution |
|---|---|
| Target providers | Both hosted and local — so the cap is low and configurable |
| Shape | Fan-out **inside one tick**, batch phase only |
| Driver model | One **wave** per tick on both drivers; worker uses the full cap, poll clamps to `min(cap, POLL_MAX)` |
| Cap configuration | Per-connection setting on `AiProviderSettings`, `Range(1..4)`, **default 1** |
| Retry state | **In-tick** retries; the single-integer `batchesDone` cursor is kept |
| Transport failure | **Atomic wave**: bank nothing, **one** ceiling increment, re-run the wave next tick; healthy siblings complete and their answers are discarded |
| Stop | Checked at **round boundaries** inside the tick |
| ETA (#336) | Untouched — throughput-based, self-corrects |
| Debug panel | Untouched |
| Dedup | Stays one sequential barrier call |

## Architecture

### The concurrency model is cooperative, not threaded

Symfony HttpClient multiplexes concurrent requests in **one PHP process**. The
fan-out reads all responses in a single combined `stream(iterable $responses)`
loop; PHP dispatches one chunk at a time. There is **no OS thread**, so there is
no race on the `RecommendationRun`, the `EntityManager`, or the per-call
`RecordedCall` recorder (which already writes through the DBAL connection, not
the EM). This removes the scariest failure mode: the wave is concurrent on the
wire and serial in memory.

### Snapshot pool ordering

The snapshot still **selects** the newest `candidatePoolSize` unread
candidates, but before packing it **shuffles** the pool into a random order
(clock-seeded, deterministic per seed) so each batch is a representative
sample of the whole pool instead of a recency cluster (#344).

### The wave

A tick's wave covers the batches
`[nextBatchIndex, nextBatchIndex + waveSize)` of the frozen batch plan, where:

```
waveSize = min(effectiveCap, batchesRemaining)
effectiveCap = worker ? cap : min(cap, POLL_MAX)   // POLL_MAX = 2
```

`cap` is the connection's configured `batchConcurrency` (1..4). With `cap == 1`
the wave is a single batch and the tick calls the existing `complete()` once —
byte-for-byte today's behaviour.

The tick resolves its whole wave before returning: every batch in the wave ends
the tick either **banked** (a usable reply → winners recorded) or **degraded**
(unusable after `MAX_ATTEMPTS` → dropped, the #329 ending), or the wave is
**re-run whole** next tick (a transport failure), or the run **fails** (the
transport-failure ceiling is reached). `batchesDone` advances by the count of
resolved batches — the wave size — so the cursor stays a single integer.

### In-tick retries (choice X)

Within the tick:

1. **Round 1** fires `waveSize` requests concurrently, reads them in one
   `stream()` loop, parses each reply.
2. Batches whose reply is **usable** are held as winners.
3. Batches whose reply is **unusable** are collected. If any remain and the
   wave has not exhausted `MAX_ATTEMPTS`, a **retry round** fires *only* the
   still-unusable batches concurrently, each carrying its **own** corrective
   tail built from *its own* last invalid reply (a local value, not the
   entity's single `lastInvalidReply`). Repeat up to `MAX_ATTEMPTS` rounds.
4. A batch still unusable after the last round is **degraded** (dropped).
5. Between rounds, the cancellation checkpoint is checked (Stop granularity).

The per-batch corrective tail is the reason retry state does **not** need to be
persisted: every retry for a batch happens inside the tick that first sent it,
so the run entity never has to remember more than one integer.

### Transport failure = atomic wave (choice P)

If any call in a wave raises `ProviderUnreachableException` or
`CredentialsRejectedException`:

- `completeMany` cancels only the **failed** call's own response
  (`$response->cancel()`); it does not cancel its siblings. The maintainer
  decided against implementing sibling cancellation — a healthy sibling
  already in flight simply **keeps streaming to completion** on its own
  connection.
- The wave banks **nothing** — no `batchesDone` advance — so a healthy
  sibling's answer is read to completion and then **discarded**: the round
  that produced it is never persisted.
- The run records **one** transport-failure increment for the whole wave (not
  one per failed call), so a cap of 4 cannot exhaust a ceiling of 3 in a single
  wave. `RecommendationRun::recordTransportFailure()` returns whether the
  ceiling is now reached; if so the run fails with the real per-call detail, as
  today.
- If the ceiling is not reached, the exception propagates exactly as today: the
  controller maps it, the worker's fault floor logs it, and the **next tick
  re-runs the whole wave** from the unchanged cursor.

Re-billing the usable calls of a wave that had one transport failure is the
accepted cost — both the discarded healthy siblings' answers and their
provider spend. It is self-correcting: a provider that rate-limits at
concurrency 4 will likely rate-limit the retry too, trip the ceiling, and fail
the run — the correct signal to lower the connection's cap.

### The dedup barrier is unchanged

Dedup needs every batch's winners pooled and ranked, so it stays one sequential
`complete()` call in `dedupTick`, with its existing retry-or-degrade ending.

## Components

### Backend — the setting

- **`AiProviderSettings`** — new column `batch_concurrency SMALLINT DEFAULT 1
  NOT NULL`, field `private int $batchConcurrency = 1;`, accessors
  `batchConcurrency(): int` and `setBatchConcurrency(int): void`. Mirrors the
  #323 `suppressReasoning` addition. Not touched by `replaceConnection()`.
- **Migration** — `batch_concurrency` on both MySQL (`SMALLINT DEFAULT 1 NOT
  NULL`) and SQLite (`INTEGER DEFAULT 1 NOT NULL`). Verified from empty on both
  dialects (the standing migration rule).
- **`SetBatchConcurrencyRequest`** DTO — `#[Assert\Range(min: 1, max: 4)] public
  int $batchConcurrency;` (the hard upper limit lives here and in one shared
  constant).
- **`AiProviderConfigurator::setBatchConcurrency()`** — mirrors
  `setSuppressReasoning()`.
- **`AiSettingsController`** — `PUT /configs/{id}/batch-concurrency`, route name
  `api_me_ai_set_batch_concurrency`. Thin: read request, delegate, return.
- **`AiSettingsJson`** — add `'batchConcurrency' => $settings->batchConcurrency()`.

### Backend — the fan-out

- **`ChatCompletionClient`** (interface) + **`OpenAiCompatibleChatClient`** —
  new method:

  ```php
  /**
   * @param non-empty-list<ConcurrentCompletion> $calls
   * @return list<CompletionOutcome>  aligned with $calls by index
   */
  public function completeMany(ProviderCredentials $credentials, array $calls): array;
  ```

  Each `ConcurrentCompletion` pairs a `CompletionRequest` with its
  `CompletionStreamObserver` (the `RecordedCall`). The method fires all requests,
  reads them in one `stream(iterable $responses)` loop keyed by
  `SplObjectStorage<ResponseInterface, CompletionStreamReader>`, and returns one
  `CompletionOutcome` per call — either the answer string or the transport
  exception, so the advancer can apply the atomic-wave rule without one failure
  aborting the read loop for the others. The existing single-call `complete()`
  stays for `cap == 1` and for dedup. The byte caps, timeouts, `Accept-Encoding:
  identity`, and the reasoning-channel fallback are shared with `complete()` —
  factor the per-response read into a helper both call.

- **`RecommendationRunAdvancer::providerTick`** — becomes a wave driver:
  computes `waveSize`, builds one `RecordedCall` and one `CompletionRequest` per
  batch, runs the in-tick retry rounds via `completeMany`, applies the
  atomic-wave transport rule, and records winners for each resolved batch. The
  all-pruned short-circuit and the degrade ending are preserved per batch.

  The advancer cannot infer its driver from inside `advance()`, so the regime is
  an **explicit parameter**: `advance(User $user, TickDriver $driver)` with a
  `TickDriver::Worker | TickDriver::Poll` enum. The worker handler passes
  `Worker`, the poll driver passes `Poll`, and `effectiveCap` is
  `Poll === $driver ? min(cap, POLL_MAX) : cap`. This is the one signature change
  to the public tick; both existing callers are updated.

- **`RecommendationRun`** — no new persisted field. `recordBatchWinners` is
  called once per resolved batch as today; confirm nothing in it assumes the
  call happens exactly once per tick. `recordTransportFailure` is called once
  per failed wave.

### Frontend — the setting

- **`ai-settings.service.ts`** — `setBatchConcurrency(id, value)` calling the new
  route; extend the settings type with `batchConcurrency: number`.
- **`ai-section.component`** — a number input in the expert area, mirroring the
  reasoning row from #323, labelled and hinted. Range 1..4. i18n keys in
  `en.json` and `de.json`.

## Data flow

```
tick → providerTick(run, user, settings, driver)
  waveSize = min(effectiveCap(driver, settings.batchConcurrency), batchesRemaining)
  waveIds  = candidateBatches[nextBatchIndex .. nextBatchIndex+waveSize)
  build one RecordedCall + CompletionRequest per non-pruned batch
  round = 1
  loop:
     outcomes = chat.completeMany(credentials, pending calls)   // concurrent
     if any outcome is a transport failure:
        healthy siblings finish streaming, their answers discarded;
        recordTransportFailure (one); flush; throw or re-run
     parse each usable/unusable; hold usable as winners
     guard cancellation (round boundary)
     if no unusable left OR round == MAX_ATTEMPTS: break
     round++; rebuild pending calls with per-batch corrective tails
  record winners for each usable batch; degrade the rest
  advance cursor by waveSize → checkpoint or finalize/dedup
```

## Testing

- **Unit** — `completeMany` against a mock HttpClient returning several staged
  responses: all-usable, mixed usable/unusable (retry only the unusable ones),
  one transport failure (only the failed call's response is cancelled; a
  healthy sibling's outcome still carries its answer, which the caller then
  discards under the atomic-wave rule).
- **Advancer** — a wave of N banks N winners in one tick; an unusable batch in a
  wave retries in-tick and degrades after `MAX_ATTEMPTS` without dropping its
  usable siblings; a transport failure in a wave advances nothing and increments
  the ceiling exactly once, discarding any healthy sibling answer the round
  already produced; `cap == 1` takes the single-call path unchanged; the poll
  driver clamps to `POLL_MAX`.
- **API** — `PUT …/batch-concurrency` validates the range, persists, and
  round-trips through `AiSettingsJson`; out-of-range is a 422.
- **Migration leg** — schema builds from empty on SQLite and MySQL;
  `doctrine:schema:validate` clean.
- **Mutation** — `composer infection:diff` stays at or above the gate on the
  changed files.
- **Frontend** — service posts the value; the input validates 1..4.

## Verification bar (standing rule)

Gates green is **not** the deliverable. This ships only after a **live run
against a real provider** completes with **0 transport failures** at a
configured concurrency **> 1**, confirmed through the debug panel.

## Out of scope (named, not silently dropped)

- Smoothing the #336 progress bar's one-batch anticipation during a wave — the
  ETA number is correct; the bar jump is cosmetic. Follow-up if the live run
  shows it looks bad.
- Mid-stream Stop cancellation — round-boundary granularity matches today.
- Debug-panel wave grouping — simultaneous streaming rows already read as
  concurrent.
- Parallelising the dedup call — it is a barrier by definition.
- Promoting the cap to a global or worker-level setting — per-connection covers
  the need.
