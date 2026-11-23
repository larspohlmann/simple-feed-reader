# Refresh lock keepalive with a ceiling — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A sweep keeps the global `feed-refresh` lock for as long as it really runs, but never past a hard ceiling, so a stuck or endless pass cannot hold the lock forever.

**Architecture:** A per-pass `SweepLockKeepalive` (in `Refresh/Pass/`) holds the sweep's lock and renews it on `beat()` at most every 20 s, and only until a ceiling of `deadline + 120 s`. After that it stops renewing and logs once, and the lock dies 60 s after its last renewal. The recorder beats after each stored outcome, and the runner beats before the favicon step and before each housekeeping step. If a renewal finds the lock taken by another process, the keepalive records the loss and the runner skips housekeeping for that pass. A one-feed run (no sweep lock) gets a keepalive with no lock, whose beats do nothing.

**Tech Stack:** Symfony 8.1 / PHP 8.5, Symfony Lock (`LockInterface::refresh()`, `LockConflictedException`), PHPUnit with `MockClock` and `InMemoryStore`.

**Spec:** GitHub issue #2489 (option 2, chosen by Lars: renew the lock while the pass runs, with protection against endless renewal).

## Global Constraints

- `CLAUDE.md` house rules: verbs from the method-name table, no comments unless a reader would get the code wrong, PHPMD-clean touched files, `composer check` green, no `@phpstan-ignore`.
- The lock name `feed-refresh` and its 60 s TTL (`LOCK_TTL_SECONDS = 60.0`) stay unchanged; renewal resets it to 60 s from the moment of the renewal.
- Renewal interval exactly `20` seconds (`RENEWAL_INTERVAL_SECONDS`); ceiling exactly `deadline + 120` seconds (`MAX_OVERRUN_SECONDS = 120`), where `deadline` is the pass's budget deadline (`now + budgetSeconds` at pass start).
- Log messages are exact strings: `'Another process took the feed refresh lock'` (lock lost, warning), `'Could not renew the feed refresh lock'` (store failure, warning), `'The feed refresh lock stopped renewing at its ceiling'` (ceiling reached, warning, once per pass).
- No change to the client API or to the one-feed path's behaviour.

## Review Focus

1. **A pass that never ends** (a hung persist loop beating forever) → renewals stop at the ceiling, so the lock expires at most `ceiling + 60 s` after the pass started. (Task 1 test.)
2. **The lock store hiccups** (`refresh()` throws a lock exception other than a conflict) → logged, the pass carries on, housekeeping still runs. (Task 1 + Task 2 tests.)
3. **Another sweep took the lock after it expired** → the loss is recorded and this pass skips orphan reclaim, history and pruning. (Task 2 test.)
4. **A one-feed run** (no sweep lock) → beats are no-ops; nothing is logged. (Task 1 test.)
5. **Beats arrive faster than every 20 s** (a burst of outcomes) → only one renewal per 20 s. (Task 1 test.)

---

### Task 1: `SweepLockKeepalive`

**Files:**
- Create: `backend/src/Service/Ingestion/Refresh/Pass/SweepLockKeepalive.php`
- Test: `backend/tests/Service/Ingestion/Refresh/Pass/SweepLockKeepaliveTest.php`

**Interfaces:**
- Produces: `final class SweepLockKeepalive` (per-call, stateful):
  - `public const int RENEWAL_INTERVAL_SECONDS = 20;` `public const int MAX_OVERRUN_SECONDS = 120;` and the three message constants `LOCK_TAKEN_MESSAGE`, `RENEWAL_FAILED_MESSAGE`, `CEILING_REACHED_MESSAGE` with the exact strings above.
  - `public static function holding(LockInterface $lock, ClockInterface $clock, int $deadline, LoggerInterface $logger): self` — a keepalive for a sweep; its ceiling is `$deadline + MAX_OVERRUN_SECONDS`.
  - `public static function withoutLock(): self` — for a one-feed run; every method is a no-op and `hasLostTheLock()` is false.
  - `public function beat(): void` — renews when due: the first beat after construction counts from construction time (no renewal before 20 s have passed), later ones from the last renewal attempt; once `now > ceiling`, logs `CEILING_REACHED_MESSAGE` once and never renews again.
  - `public function hasLostTheLock(): bool`.

- [ ] **Step 1: Write the failing tests**

`InMemoryStore` expires keys by real `microtime`, not by `MockClock`, so lifetimes cannot be asserted. Count renewals instead with a new test double `backend/tests/Support/RenewalCountingLock.php` (`final class … implements LockInterface`; `renewals(): int` counts `refresh()` calls; an optional `\Throwable` it throws from `refresh()`; the other methods answer as a held lock). Clock: `MockClock`, advanced with `sleep()`. Logger: the logger double `RefreshRunnerTest` uses (`RecordingLogger`).

- `testABeatBeforeTheIntervalRenewsNothing`: deadline now+120; sleep 10; `beat()` → `renewals() === 0`.
- `testABeatAfterTheIntervalRenewsTheLock`: sleep 25; `beat()` → 1.
- `testBeatsInABurstRenewOncePerInterval`: sleep 25, beat, sleep 5, beat → 1; sleep 20, beat → 2.
- `testRenewalStopsAtTheCeiling`: deadline now+10 (ceiling now+130); beat at +20, +40, … +120 → 6 renewals; beats at +140, +160, +500 → still 6; the logger saw `CEILING_REACHED_MESSAGE` exactly once.
- `testALockTakenByAnotherProcessIsRecorded`: lock throws `new LockConflictedException()` → after a due beat `hasLostTheLock()` true, logger saw `LOCK_TAKEN_MESSAGE`.
- `testAStoreFailureIsLoggedAndTheLockIsNotCountedLost`: lock throws `new LockStorageException()` → `hasLostTheLock()` false, logger saw `RENEWAL_FAILED_MESSAGE`.
- `testWithoutALockEveryBeatIsANoOp`: `withoutLock()`; beat after +25 s and +500 s → `hasLostTheLock()` false, no exception.

- [ ] **Step 2: Run them to see them fail**

Run: `cd backend && php bin/phpunit tests/Service/Ingestion/Refresh/Pass/SweepLockKeepaliveTest.php`
Expected: FAIL — class not found.

- [ ] **Step 3: Implement the class**

`refresh()` with no argument renews to the lock's own TTL. Catch `LockConflictedException` before `Symfony\Component\Lock\Exception\ExceptionInterface`, as `TickLockKeepalive` does. `withoutLock()` holds a null lock and a null logger and clock internally; keep the class `final`, not `readonly` (it is stateful).

- [ ] **Step 4: Run them to pass, then gates**

Run: `cd backend && php bin/phpunit tests/Service/Ingestion/Refresh/Pass/SweepLockKeepaliveTest.php && composer check && composer md`
Expected: PASS, no findings in the new file.

- [ ] **Step 5: Commit**

```bash
git add backend/src/Service/Ingestion/Refresh/Pass/SweepLockKeepalive.php backend/tests/Service/Ingestion/Refresh/Pass/SweepLockKeepaliveTest.php
git commit -m "feat(#2489): a sweep's lock keepalive renews every 20 s and stops at a ceiling"
```

### Task 2: Sweeps renew their lock; a lost lock skips housekeeping

**Files:**
- Modify: `backend/src/Service/Ingestion/Refresh/RefreshRunner/RefreshRunner.php`, `backend/src/Service/Ingestion/Refresh/RefreshPassRecorder.php`
- Modify: the callers of `RefreshPassRecorder::record()` in `backend/tests` (pass `SweepLockKeepalive::withoutLock()`)
- Test: `backend/tests/Service/Ingestion/Refresh/RefreshRunner/RefreshRunnerTest.php`, `backend/tests/Service/Ingestion/Refresh/RefreshPassRecorderTest.php`

**Interfaces:**
- Consumes: Task 1's `SweepLockKeepalive::holding()`, `::withoutLock()`, `beat()`, `hasLostTheLock()`.
- Produces: `RefreshPassRecorder::record(RefreshPass $pass, \DateTimeImmutable $now, SweepLockKeepalive $keepalive): void` — beats after each stored outcome.

- [ ] **Step 1: Write the failing tests**

Add `backend/tests/Support/RenewalCountingLockFactory.php`: extends `RecordingLockFactory`, hands out a `RenewalCountingLock` (Task 1) for `feed-refresh` wrapping the real lock from the parent (delegating acquire/release/isAcquired, counting refresh, optionally throwing a configured `\Throwable` from refresh), and the real lock for any other resource (the per-feed claims). Wire it in with `RefreshRunners::lockingWith()`.

`RefreshPassRecorderTest`:
- `testEachStoredOutcomeBeatsTheKeepalive`: three due feeds, a `RenewalCountingLock`, `holding($lock, $clock, deadline now+300, logger)`; the stub fetcher advances the `MockClock` 25 s per outcome → `renewals() === 3`.

`RefreshRunnerTest`:
- `testALongSweepRenewsItsLock`: `allDue(300)` over four feeds, fetcher advancing the clock 25 s per outcome → the `feed-refresh` lock's `renewals() >= 4` (outcomes plus the pre-housekeeping beats) and the report is `completed`.
- `testASweepThatLostItsLockSkipsHousekeeping`: the factory's `feed-refresh` lock throws `LockConflictedException` on refresh; fetcher advances 25 s per outcome; one entry old enough to be pruned (reuse the fixture style of this file's existing prune test) → report `pruned === 0` and the entry still exists; the logger saw `LOCK_TAKEN_MESSAGE`.
- `testAStoreHiccupStillPrunes`: same with `LockStorageException` → `pruned === 1`.
- `testAOneFeedRunTakesNoSweepLock`: `forFeed()` with the clock advanced 200 s during the fetch → the factory created no `feed-refresh` lock (`lastLockFor('feed-refresh') === null`) and the logger has none of the three keepalive messages.

- [ ] **Step 2: Run them to see them fail**

Run: `cd backend && php bin/phpunit --filter 'RefreshPassRecorderTest|RefreshRunnerTest'`
Expected: FAIL — `record()` arity / lock not renewed / housekeeping ran.

- [ ] **Step 3: Implement**

- `RefreshRunner::run()`: build `SweepLockKeepalive::holding($lock, $this->clock, $now + $request->budgetSeconds, $this->logger)` after acquiring; the one-feed path uses `withoutLock()`. Pass it into `refresh()`. Compute the deadline once, so the pass and the keepalive share it.
- `RefreshRunner::refresh()`: hand the keepalive to `record`; `beat()` before favicon resolution and before each of `reclaimOrphanedFeeds` / `recordSubscriptionHistory` / `pruneEntries`; skip those three when `hasLostTheLock()` (reclaim runs before the pass, when nothing can be lost yet, so beat-and-check applies to history and prune; keep reclaim's position).
- `RefreshPassRecorder::record()`: `$keepalive->beat()` after each `tally->record`.
- Update the test call sites of `record()`.

- [ ] **Step 4: Run the gates**

Run: `cd backend && php bin/phpunit tests/Service/Ingestion/Refresh tests/Command tests/Controller tests/Service/Operations && composer check && composer md`
Expected: PASS, touched files PHPMD-clean.

- [ ] **Step 5: Commit**

```bash
git add backend/src/Service/Ingestion/Refresh backend/tests/Service/Ingestion/Refresh
git commit -m "fix(#2489): a sweep renews the refresh lock while it runs and skips housekeeping once it lost it"
```

### Task 3: Both test legs

- [ ] `cd backend && php bin/phpunit` and `docker compose exec php composer test:parallel`, in parallel. Expected: both green.
