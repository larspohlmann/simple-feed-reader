# #1419 Warm-up ramp for the worker's refresh sweep — implementation plan

**Goal:** after a worker (re)start, the refresh sweep fetches a small batch first and ramps up to the
steady-state batch over the next firings, so the backlog no longer lands in one 50-feed burst that
starves web requests on SQLite/qemu installs.

**Design**

- `RefreshRequestModel` gains a `batchLimit` (default `RefreshRequestModel::DEFAULT_BATCH_LIMIT = 50`,
  today's `RefreshRunner::BATCH_LIMIT`) and `limitedTo(int $batchLimit): self`. `RefreshRunner` reads
  the limit from the request; the constant leaves the runner. HTTP and CLI callers are unchanged.
- New `App\Service\Worker\RefreshWarmUp`, `#[ProcessLifetimeState]` (the count must survive Messenger's
  per-message reset): `nextBatchLimit(): int` returns 10 on the first firing of the process, 25 on the
  second, then `DEFAULT_BATCH_LIMIT` for good.
- `RefreshDueFeedsHandler` asks `RefreshWarmUp` for the limit and runs
  `RefreshRequestModel::allDue(BUDGET)->limitedTo($limit)`.
- The ramp is keyed to process start, so the hourly `--time-limit=3600` recycle also takes one small
  firing; steady state only has one tick's worth of due feeds, and whatever is left is picked up by the
  next firing, so that costs at most five minutes of latency on a few feeds once an hour.

**Tasks (TDD)**

1. `RefreshWarmUpTest` (unit): the sequence 10, 25, 50, 50; ramp never exceeds the default.
2. `RefreshRequestModelTest`: default limit, `limitedTo()` keeps every other field.
3. `RefreshRunner`: a functional test that a request limited to 1 fetches one of two due feeds and
   reports the other as remaining.
4. `RefreshDueFeedsHandlerTest`: a fresh handler's first firing with 11 due feeds fetches 10.
5. Gates: `composer check`, `composer md`, phpunit (SQLite + MySQL), `composer infection:diff`.
