# Scoped refresh: retry keeps its scope, one-feed refresh skips the sweep lock, closable failure banner — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Refreshing one subscription fetches that feed straight away even while a sweep runs; a failed refresh's Retry repeats that same refresh; the failure banner can be closed.

**Architecture:** Every refresh pass claims a per-feed lock (`feed-refresh-feed-<id>`) when it starts a feed and releases it once that feed's outcome is stored; a feed another pass holds is not started. A request whose target is one feed no longer takes the global `feed-refresh` lock, so it only waits for that one feed, never for the whole sweep. A pass that started nothing because every due feed was held reports `busy`, which the client already backs off on. In the SPA, `RefreshService` remembers its last run and offers `retry()` and `dismissFailure()`; the banner calls those.

**Tech Stack:** Symfony 8.1 / PHP 8.5, Symfony Lock (DBAL store in prod, `InMemoryStore` in tests), Angular 20 signals, Jest.

**Spec:** GitHub issue #2487 (the incident timeline and the three changes); this plan is the design.

## Global Constraints

- House rules from `CLAUDE.md`: `final readonly` where stateless, verbs from the method-name table, no comments unless a reader would get the code wrong, PHPMD-clean touched files, `composer check` green.
- Per-feed lock name exactly `feed-refresh-feed-<feedId>`; TTL `60.0` seconds (same as the global lock's `LOCK_TTL_SECONDS`).
- The global lock name `feed-refresh` and its behaviour for every non-single-feed target stay unchanged.
- Client-visible API unchanged: `POST /api/refresh` and its `status` values (`busy`, `partial`, `completed`, `aborted`) keep their meaning.
- Frontend: component styles in the sibling `.scss`, no hex colours or ad-hoc px; strings through Transloco, existing key `reader.dismiss` for the close control's label (all four languages already carry it).
- Jest only inside the Docker `frontend` container.

## Review Focus

1. **A sweep holds the very feed the user refreshes** → the single-feed run must report `busy` (client retries every 1.5 s, five times) and succeed on a later try once the sweep has stored that feed; it must never report `completed` with nothing fetched. (Task 2 test.)
2. **A pass aborts mid-way (EntityManager closed)** → every claimed lock is still released; otherwise a feed stays unrefreshable for the 60 s TTL. (Task 1 test on `releaseAll` in the runner's `finally`.)
3. **The budget stops a sweep before it starts some feeds** → feeds never started are never claimed, so no lock lingers for them. (Task 1 test.)
4. **Retry after a failed scoped refresh** sends `feedId` again, not an unscoped sweep; retry after a failed social-import run still calls its `onDone`. (Task 3 tests.)
5. **Closing the banner** clears the failure without any request; a later failure shows the banner again. (Task 4 test.)

Known, accepted risk (no task): a single-feed run and a sweep now write concurrently. Two feeds carrying the same canonical item in the same second could hit a unique constraint; that path already ends as an `aborted` report the client shows. Say this in the PR body.

---

### Task 1: Per-feed claims in every pass

**Files:**
- Create: `backend/src/Service/Ingestion/Refresh/Pass/FeedClaims.php`
- Modify: `backend/src/Service/Ingestion/Refresh/Pass/BudgetedFeedQueue.php`, `Pass/RefreshPass.php`, `RefreshPassRecorder.php`, `RefreshRunner/RefreshRunner.php`
- Modify (constructor call sites): every `new RefreshPass(` in `backend/tests` (8 sites: `PassTicketsTest`, `RefreshPassTest`, `ConcurrentPassFetcherTest` ×3, `RefreshPassRecorderBlueskyTest`, `RefreshPassRecorderTest`, `PassFetcherWiringTest`) — pass `new FeedClaims(new LockFactory(new InMemoryStore()))`
- Test: `backend/tests/Service/Ingestion/Refresh/Pass/FeedClaimsTest.php`, `backend/tests/Service/Ingestion/Refresh/Pass/RefreshPassTest.php`, `backend/tests/Service/Ingestion/Refresh/RefreshRunner/RefreshRunnerTest.php`

**Interfaces:**
- Produces:
  - `final class FeedClaims` (per-call, stateful, in `Pass/`): `__construct(LockFactory $lockFactory)`, `claim(int $feedId): bool` (non-blocking acquire of `feed-refresh-feed-<id>`, TTL 60.0; true when this pass now holds it), `release(int $feedId): void` (no-op when not held), `releaseAll(): void`.
  - `RefreshPass::__construct(array $feeds, ClockInterface $clock, int $deadline, FeedClaims $claims)`; new `RefreshPass::releaseFeed(int $feedId): void`, `RefreshPass::releaseAllFeeds(): void`, `RefreshPass::heldFeedIds(): list<int>` (due feeds skipped because another pass held them).
  - `BudgetedFeedQueue::__construct(array $feeds, ClockInterface $clock, int $deadline, FeedClaims $claims)`; `heldFeedIds(): list<int>`.

- [ ] **Step 1: Write the failing tests**

`FeedClaimsTest` (one shared `InMemoryStore`, two `FeedClaims` over two `LockFactory`s on it):
- `testASecondPassCannotClaimAFeedTheFirstHolds`: `$first->claim(7)` true, `$second->claim(7)` false, `$second->claim(8)` true.
- `testAReleasedFeedCanBeClaimedAgain`: `$first->claim(7)`; `$first->release(7)`; `$second->claim(7)` true.
- `testReleaseAllFreesEveryClaim`: first claims 7 and 8, `releaseAll()`, second claims both → true, true.

`RefreshPassTest`:
- `testAFeedAnotherPassHoldsIsNotStarted`: feeds A, B; another `FeedClaims` on the same store holds B's id → `iterator_to_array($pass->startedFeeds())` keys `== [A]`, `heldFeedIds() === [B]`.
- `testAFeedTheBudgetNeverStartsIsNeverClaimed`: deadline already inside the safety margin, feeds A, B → only A started; a second `FeedClaims` can `claim(B)` → true.

`RefreshRunnerTest` (store = the test's `InMemoryStore` given to `TtlRecordingLockFactory`):
- `testEveryClaimIsReleasedAfterThePass`: two due feeds, `runner()->run(RefreshRequestModel::allDue(25))`; afterwards a fresh `FeedClaims` on the same store claims both ids → true.
- `testEveryClaimIsReleasedWhenThePassAborts`: reuse the setup of `testEntityManagerFailureAbortsRunWithoutCascading`; after the aborted run, both ids claimable.

- [ ] **Step 2: Run them to see them fail**

Run: `cd backend && php bin/phpunit --filter 'FeedClaimsTest|RefreshPassTest|RefreshRunnerTest'`
Expected: FAIL — `FeedClaims` not found / `RefreshPass::__construct` arity.

- [ ] **Step 3: Implement**

- `FeedClaims`: holds `array<int, SharedLockInterface>`; `claim()` creates the lock and calls `acquire()` (non-blocking).
- `BudgetedFeedQueue::startedFeeds()`: after `mayStartAnother()`, `if (!$this->claims->claim($id)) { $this->heldFeedIds[] = $id; continue; }`. A held feed is neither started nor counted as started, so "the first feed always starts" still means the first claimable one.
- `RefreshPassRecorder::record()`: call `$pass->releaseFeed($feedId)` right after `$pass->tally->record(...)`, before the abort check.
- `RefreshRunner::refresh()`: build the pass with `new FeedClaims($this->lockFactory)`; wrap `record` through the report in `try { … } finally { $pass->releaseAllFeeds(); }`.
- Update the 8 test call sites.

- [ ] **Step 4: Run the refresh tests**

Run: `cd backend && php bin/phpunit tests/Service/Ingestion/Refresh tests/Command tests/Controller/MaintenanceControllerTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add backend/src/Service/Ingestion/Refresh backend/tests/Service/Ingestion/Refresh
git commit -m "feat(#2487): a refresh pass claims each feed it starts and skips feeds another pass holds"
```

### Task 2: A one-feed refresh skips the sweep lock

**Files:**
- Modify: `backend/src/Service/Ingestion/Refresh/Model/RefreshRequestModel.php`, `backend/src/Service/Ingestion/Refresh/RefreshRunner/RefreshRunner.php`
- Test: `backend/tests/Service/Ingestion/Refresh/RefreshRunner/RefreshRunnerTest.php`, `backend/tests/Service/Ingestion/Refresh/Model/RefreshRequestModelTest.php` (create if absent)

**Interfaces:**
- Consumes: Task 1's `FeedClaims`, `RefreshPass::heldFeedIds()`, `RefreshPass::startedFeedIds()`.
- Produces: `RefreshRequestModel::isSingleFeed(): bool` (true exactly when `$target instanceof FeedTargetModel`).

- [ ] **Step 1: Write the failing tests**

`RefreshRequestModelTest`:
- `testOnlyAFeedTargetIsASingleFeed`: `forFeed(5, 25)->isSingleFeed()` true; `forUser(1, new FeedTargetModel(5), 25)` true; `allDue(25)` false; `forUser(1, new TagTargetModel(3), 25)` false.

`RefreshRunnerTest`:
- `testAOneFeedRefreshRunsWhileASweepHoldsTheGlobalLock`: acquire `feed-refresh` from `$this->lockFactory` first; `run(RefreshRequestModel::forFeed($feedId, 25))` → `status === 'completed'`, `fetched === 1`, and the stub fetcher saw that feed's URL.
- `testASweepIsStillRefusedWhileTheGlobalLockIsHeld`: same held lock; `run(allDue(25))->status === RefreshReportModel::STATUS_BUSY`.
- `testAOneFeedRefreshIsBusyWhileAnotherPassHoldsThatFeed`: a separate `FeedClaims` on the test store claims the feed; `run(forFeed($feedId, 25))->status === STATUS_BUSY`, fetcher saw no request; after `releaseAll()` on the other claims, the same call → `completed`, `fetched === 1`.
- `testASweepWhoseEveryDueFeedIsHeldIsBusy`: one due feed held by another `FeedClaims`; `run(allDue(25))->status === STATUS_BUSY`.

- [ ] **Step 2: Run them to see them fail**

Run: `cd backend && php bin/phpunit --filter 'RefreshRequestModelTest|RefreshRunnerTest'`
Expected: FAIL — `isSingleFeed` undefined; one-feed run reports busy under the global lock; held feed reports completed.

- [ ] **Step 3: Implement**

- `RefreshRequestModel::isSingleFeed()`.
- `RefreshRunner::run()`: `if ($request->isSingleFeed()) { return $this->refresh($request); }` before taking the global lock.
- `RefreshRunner::refresh()`: after `record`, when `$pass->startedFeedIds() === []` and `$pass->heldFeedIds() !== []`, return `RefreshReportModel::busy()` right after `record`. Update the class docblock's "lock-guarded" phrase to say sweeps take the run lock and every pass claims its feeds.

- [ ] **Step 4: Run the backend gates**

Run: `cd backend && php bin/phpunit tests/Service/Ingestion/Refresh tests/Command tests/Controller && composer check && composer md`
Expected: PASS, no PHPMD finding in touched files.

- [ ] **Step 5: Commit**

```bash
git add backend/src/Service/Ingestion/Refresh backend/tests/Service/Ingestion/Refresh
git commit -m "fix(#2487): refreshing one feed waits for that feed alone, not for the sweep lock"
```

### Task 3: Retry repeats the refresh that failed

**Files:**
- Modify: `frontend/src/app/reader/state/refresh.service.ts`, `frontend/src/app/reader/shell/reader-shell.component.html` (the banner's button)
- Test: `frontend/src/app/reader/state/refresh.service.spec.ts`, `frontend/src/app/reader/shell/reader-shell.component.spec.ts` (`refresh failures` block)

**Interfaces:**
- Produces: `RefreshService.retry(): void` — reruns the last `run()` with the same `onDone` and `scope`; a no-op while running or before any run. `RefreshService.dismissFailure(): void` — sets `failure` to null.

- [ ] **Step 1: Write the failing tests**

`refresh.service.spec.ts`:
- `it('retries the failed run with its scope')`: `run(undefined, { kind: 'feed', feedId: 7 })`, flush a 500 → `failure()` not null; `retry()` → the next `/api/refresh` request has `params.get('feedId') === '7'`; flush done → `failure()` null.
- `it('retries with the failed run's onDone')`: `run(onDone, { kind: 'neverFetched' })`, 500, `onDone` called once; `retry()`, flush done → `onDone` called twice.
- `it('dismisses a failure without a request')`: 500 → `dismissFailure()` → `failure()` null, `ctrl.verify()` shows no open request.

`reader-shell.component.spec.ts`, `refresh failures`:
- `it('retries a failed list refresh with the list scope, not a full sweep')`: boot on a subscription selection (as the existing scoped-refresh tests do), call `refresh.refreshScoped()`, answer 500, click the banner's Retry → the request's `params.get('feedId')` is the subscription's feed id.
- Keep the existing `refreshes again when the failure banner is retried` passing (its run was unscoped, so retry is unscoped).

- [ ] **Step 2: Run them to see them fail**

Run: `docker compose exec -T frontend npx jest src/app/reader/state/refresh.service.spec.ts src/app/reader/shell/reader-shell.component.spec.ts`
Expected: FAIL — `retry`/`dismissFailure` not functions; retry request carries no `feedId`.

- [ ] **Step 3: Implement**

- `RefreshService`: private `lastRun: { onDone?: () => void; scope?: RefreshScope } | null`, set in `run()`; `retry()` and `dismissFailure()` as above.
- Banner button: `(click)="refreshSvc.retry()"`.

- [ ] **Step 4: Run them again**

Same command. Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add frontend/src/app/reader/state frontend/src/app/reader/shell
git commit -m "fix(#2487): retrying a failed refresh repeats that refresh, with its scope"
```

### Task 4: Close the failure banner

**Files:**
- Modify: `frontend/src/app/reader/shell/reader-shell.component.html` (failure banner), `reader-shell.component.scss` (`.fetch-banner`)
- Test: `frontend/src/app/reader/shell/reader-shell.component.spec.ts` (`refresh failures`)

**Interfaces:**
- Consumes: Task 3's `RefreshService.dismissFailure()`.

- [ ] **Step 1: Write the failing test**

- `it('closes the failure banner without refreshing')`: 500 answer → `banner()` present; click `banner()!.querySelector('.fetch-banner-close')` → `fixture.detectChanges()`, `banner()` null, no further `/api/refresh` request (`ctrl.expectNone`); its `aria-label` is `'Dismiss'`.
- `it('shows the banner again on the next failure after closing it')`: close, then `refreshAll()`, answer 500 → `banner()` present.
- Update the existing Retry test to click `.fetch-banner-retry` rather than the first `button`.

- [ ] **Step 2: Run it to see it fail**

Run: `docker compose exec -T frontend npx jest src/app/reader/shell/reader-shell.component.spec.ts -t 'refresh failures'`
Expected: FAIL — no `.fetch-banner-close`.

- [ ] **Step 3: Implement**

In the failure branch of the banner: Retry button gets class `fetch-banner-retry`; after it, `<button type="button" class="fetch-banner-close" [attr.aria-label]="'reader.dismiss' | transloco" (click)="refreshSvc.dismissFailure()">&times;</button>`, pushed to the strip's end (`margin-inline-start: auto`), glyph sized like `app-error-banner`'s `.dismiss` (`font-size: var(--fs-lg); line-height: 1`). The progress branch is untouched.

- [ ] **Step 4: Run the frontend gate**

Run: `docker compose exec -T frontend npm run check`
Expected: PASS.

- [ ] **Step 5: Real-render check**

On the Docker stack (`:4200`), stub `/api/refresh` to 500 in the built-in browser, refresh a list, screenshot the banner at desktop and 375 px width; click × → banner gone. Send both screenshots to Lars before the PR.

- [ ] **Step 6: Commit**

```bash
git add frontend/src/app/reader/shell
git commit -m "feat(#2487): the refresh-failure banner can be closed without retrying"
```

### Task 5: Both test legs and the dev log

- [ ] Run `cd backend && php bin/phpunit` and `docker compose exec php composer test:parallel` (in parallel, never alongside Jest). Expected: both green.
- [ ] Run `cd backend && composer infection:diff`. Expected: MSI at or above `minMsi`.
- [ ] Scan today's dev log: `ls -t backend/var/log/dev-*.log | head -1 | xargs tail -n 200 | jq -c 'select(.level >= 300)'`. Expected: no new errors from refresh.
