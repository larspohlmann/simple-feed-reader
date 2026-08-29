# #721 — The refresh run owns its own progress — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make the server the single source of a refresh run's progress, so the reader
shows one honest, monotonic progress bar instead of three disagreeing symptoms.

**Architecture:** `POST /api/refresh` gains a run-wide `progress: {done, total}` and
loses the ambiguous per-slice `total`. The run's tally lives in a short-lived cache
entry per (user, scope), folded by a new `TrackedRefreshRunner` that wraps the
untouched `RefreshRunner`. The Angular client renders the server's numbers and computes
nothing. The one remaining bar moves inside the app bar, so it travels with the bar and
cannot be left behind.

**Tech Stack:** Symfony 7.4 / PHP 8.4 (PHPUnit, PHPStan max, PHPMD, phptramp),
Angular 20 standalone + signals (Jest, ESLint, Prettier, Stylelint).

**Spec:** `docs/superpowers/specs/2026-08-29-721-refresh-run-progress-design.md`

## Global Constraints

- Every PHP file starts with `declare(strict_types=1);`. PSR-12 (`composer cs`).
- PHPStan runs at level **max** over `src` and `tests`. No new baselines. No
  `@phpstan-ignore` without a comment saying why.
- PHPMD codesize must be clean for **every `src` file touched**, not merely free of new
  findings. Fix the design, never the threshold.
- Controllers carry no private method that does real work (`ThinControllerRule`).
- House style: `final readonly class` with constructor promotion, guard clauses over
  nesting, names that reveal intent, comments that say *why*.
- Frontend: standalone components and signals. Component styles live in a sibling
  `.scss` file, never inline. **No hex colours and no raw `px` spacing outside
  `src/app/theme/`** — both fail `npm run check`.
- Prose in code comments is normal English. Chat replies are Simplified Technical
  English; that rule does not apply to files.
- Commit messages: `type(#721): summary`.
- Backend tests run from `backend/`: `php bin/phpunit`. Frontend tests run from
  `frontend/`: `./node_modules/.bin/jest` (this is a worktree — the Docker frontend
  container serves the MAIN checkout, so it would test the wrong code).

---

### Task 1: `RefreshRunProgress` — the run's arithmetic

The whole fix in one pure value object. It carries no infrastructure, so it is tested
without a kernel, a database or a cache.

**Files:**
- Create: `backend/src/Service/Refresh/RefreshRunProgress.php`
- Test: `backend/tests/Service/Refresh/RefreshRunProgressTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces: `RefreshRunProgress` with `public int $done`, `public int $total`,
  `static start(): self`, `static resumed(int $done, int $total): self`,
  `advancedBy(int $handled, int $remaining): self`, `toArray(): array{done: int, total: int}`.

- [ ] **Step 1: Write the failing test**

Create `backend/tests/Service/Refresh/RefreshRunProgressTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Refresh;

use App\Service\Refresh\RefreshRunProgress;
use PHPUnit\Framework\TestCase;

final class RefreshRunProgressTest extends TestCase
{
    public function testAFreshRunHasDoneNothingAndKnowsNoTotal(): void
    {
        $progress = RefreshRunProgress::start();

        self::assertSame(0, $progress->done);
        self::assertSame(0, $progress->total);
    }

    /**
     * The point of the whole issue. A slice reports its own batch (server-capped
     * at 50) and the run-wide count of what is still due; the run's denominator is
     * neither of those, it is their sum. 20 handled with 180 still due means the
     * run is 20 of 200 — NOT (50 - 180) / 50, which is negative (#721).
     */
    public function testTheFirstSliceEstablishesTheRunWideDenominator(): void
    {
        $progress = RefreshRunProgress::start()->advancedBy(20, 180);

        self::assertSame(20, $progress->done);
        self::assertSame(200, $progress->total);
    }

    public function testLaterSlicesAccumulateAgainstThatSameDenominator(): void
    {
        $progress = RefreshRunProgress::start()
            ->advancedBy(20, 180)
            ->advancedBy(30, 150);

        self::assertSame(50, $progress->done);
        self::assertSame(200, $progress->total);
    }

    /**
     * Feeds fall due while a long sweep runs. Without the max() the denominator
     * would stay at its first value, `done` would sail past it, and the bar would
     * report more than a full run.
     */
    public function testFeedsFallingDueMidRunGrowTheDenominatorInsteadOfOverfillingIt(): void
    {
        $progress = RefreshRunProgress::start()
            ->advancedBy(20, 180)   // 20 of 200
            ->advancedBy(10, 200);  // 30 done, 200 still due — the run grew

        self::assertSame(30, $progress->done);
        self::assertSame(230, $progress->total);
    }

    /**
     * The denominator is a high-water mark, so a slice that handles work without
     * new feeds arriving must not shrink it — a shrinking total is a bar that
     * jumps forward for no reason.
     */
    public function testTheDenominatorNeverShrinks(): void
    {
        $progress = RefreshRunProgress::start()
            ->advancedBy(20, 180)
            ->advancedBy(180, 0);

        self::assertSame(200, $progress->done);
        self::assertSame(200, $progress->total);
    }

    public function testAFinishedRunIsExactlyFull(): void
    {
        $progress = RefreshRunProgress::start()->advancedBy(8, 0);

        self::assertSame($progress->total, $progress->done);
    }

    /**
     * A slice can legitimately handle nothing — every feed it took on was
     * deferred by the time budget. That must not move the bar, and must not
     * disturb the denominator either.
     */
    public function testASliceThatHandledNothingLeavesTheRunWhereItWas(): void
    {
        $progress = RefreshRunProgress::start()
            ->advancedBy(20, 180)
            ->advancedBy(0, 180);

        self::assertSame(20, $progress->done);
        self::assertSame(200, $progress->total);
    }

    /**
     * The store hands a run back to its next slice through this constructor, so
     * it is exercised here rather than left for the store's own tests: a named
     * constructor no test in this class calls is untested code, however soon its
     * caller lands.
     */
    public function testARunResumesExactlyWhereTheStoreLeftIt(): void
    {
        $progress = RefreshRunProgress::resumed(20, 200)->advancedBy(30, 150);

        self::assertSame(50, $progress->done);
        self::assertSame(200, $progress->total);
    }

    public function testItSerialisesForTheWire(): void
    {
        self::assertSame(
            ['done' => 20, 'total' => 200],
            RefreshRunProgress::start()->advancedBy(20, 180)->toArray(),
        );
    }
}
```

- [ ] **Step 2: Run the test and confirm it fails**

```bash
php bin/phpunit tests/Service/Refresh/RefreshRunProgressTest.php
```

Expected: every test errors with `Class "App\Service\Refresh\RefreshRunProgress" not found`.

- [ ] **Step 3: Write the implementation**

Create `backend/src/Service/Refresh/RefreshRunProgress.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Refresh;

/**
 * How far a refresh RUN has got — not the slice that just landed.
 *
 * A slice's report counts two different populations: its `total` is that slice's
 * batch, capped by RefreshRunner::BATCH_LIMIT, while its `remaining` is an uncapped
 * run-wide count of what is still due. Dividing one by the other is what left the
 * reader's bar at zero for minutes, then snapped it to full, and ran it backwards in
 * between (#721).
 *
 * No query is needed to seed the run. After any slice, `handled + remaining` IS the
 * number of feeds that were due when that slice began: every due feed either reached
 * an outcome or is still due. The denominator therefore falls out of the first slice.
 */
final readonly class RefreshRunProgress
{
    private function __construct(
        /** Feeds this run has taken to an outcome, summed over every slice. */
        public int $done,
        /** What the run has to do: everything finished plus everything still due. */
        public int $total,
    ) {
    }

    public static function start(): self
    {
        return new self(0, 0);
    }

    /** Rebuilds a run from what the store kept between two slices. */
    public static function resumed(int $done, int $total): self
    {
        return new self($done, $total);
    }

    /**
     * @param int $handled   feeds this slice took to an outcome
     * @param int $remaining feeds still due run-wide once this slice finished
     */
    public function advancedBy(int $handled, int $remaining): self
    {
        $done = $this->done + $handled;

        // A high-water mark, not a recomputation. Feeds fall due while a long sweep
        // runs, which would otherwise push `done` past a denominator fixed by the
        // first slice; and a slice that handles work without new arrivals must not
        // shrink the denominator, because a shrinking total is a bar that lurches
        // forward for no reason the user can see.
        return new self($done, max($this->total, $done + $remaining));
    }

    /** @return array{done: int, total: int} */
    public function toArray(): array
    {
        return ['done' => $this->done, 'total' => $this->total];
    }
}
```

- [ ] **Step 4: Run the test and confirm it passes**

```bash
php bin/phpunit tests/Service/Refresh/RefreshRunProgressTest.php
```

Expected: PASS, 9 tests.

- [ ] **Step 5: Run the quality gates on the new files**

```bash
composer cs && composer stan && composer md
```

Expected: no findings. If `composer stan` complains about a cold cache, run
`bin/console cache:warmup` first.

- [ ] **Step 6: Commit**

```bash
git add backend/src/Service/Refresh/RefreshRunProgress.php backend/tests/Service/Refresh/RefreshRunProgressTest.php
git commit -m "feat(#721): give a refresh run a value object that owns its progress"
```

---

### Task 2: `RefreshRunStore` — the run record between two slices

**Files:**
- Create: `backend/src/Service/Refresh/RefreshRunStore.php`
- Create: `backend/tests/Service/Refresh/RefreshRunStoreTest.php`
- Modify: `backend/config/packages/cache.yaml` (add the `refresh.run.cache` pool)
- Modify: `backend/config/services.yaml` (bind `$refreshRunCache`, around line 49)

**Interfaces:**
- Consumes: `RefreshRunProgress` from Task 1; the existing
  `App\Service\Refresh\RefreshRequest` (public readonly `?int $userId`, `?int $feedId`,
  `?int $tagId`).
- Produces: `RefreshRunStore` with `open(RefreshRequest): RefreshRunProgress`,
  `save(RefreshRequest, RefreshRunProgress): void`, `forget(RefreshRequest): void`.

- [ ] **Step 1: Write the failing test**

Create `backend/tests/Service/Refresh/RefreshRunStoreTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Refresh;

use App\Service\Refresh\RefreshRequest;
use App\Service\Refresh\RefreshRunProgress;
use App\Service\Refresh\RefreshRunStore;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

final class RefreshRunStoreTest extends TestCase
{
    private const int BUDGET = 25;

    private RefreshRunStore $store;

    protected function setUp(): void
    {
        $this->store = new RefreshRunStore(new ArrayAdapter());
    }

    public function testAnUnknownRunOpensAtZero(): void
    {
        $progress = $this->store->open(RefreshRequest::forUser(1, self::BUDGET));

        self::assertSame(0, $progress->done);
        self::assertSame(0, $progress->total);
    }

    public function testASavedRunIsHandedBackToTheNextSlice(): void
    {
        $request = RefreshRequest::forUser(1, self::BUDGET);
        $this->store->save($request, RefreshRunProgress::start()->advancedBy(20, 180));

        $resumed = $this->store->open($request);

        self::assertSame(20, $resumed->done);
        self::assertSame(200, $resumed->total);
    }

    /** One user's sweep must never be handed to another's. */
    public function testRunsAreKeptApartByUser(): void
    {
        $this->store->save(
            RefreshRequest::forUser(1, self::BUDGET),
            RefreshRunProgress::start()->advancedBy(20, 180),
        );

        self::assertSame(0, $this->store->open(RefreshRequest::forUser(2, self::BUDGET))->done);
    }

    /**
     * Refreshing one feed while a whole sweep is in flight is a different run with
     * a different denominator; sharing a key would make each corrupt the other.
     */
    public function testRunsAreKeptApartByScope(): void
    {
        $userId = 1;
        $this->store->save(
            RefreshRequest::forUser($userId, self::BUDGET),
            RefreshRunProgress::start()->advancedBy(20, 180),
        );

        self::assertSame(0, $this->store->open(RefreshRequest::forUserFeed($userId, 7, self::BUDGET))->done);
        self::assertSame(0, $this->store->open(RefreshRequest::forUserTag($userId, 7, self::BUDGET))->done);
    }

    /** A feed scope and a tag scope with the same id are still two runs. */
    public function testAFeedScopeAndATagScopeWithTheSameIdDoNotCollide(): void
    {
        $userId = 1;
        $this->store->save(
            RefreshRequest::forUserFeed($userId, 7, self::BUDGET),
            RefreshRunProgress::start()->advancedBy(1, 0),
        );

        self::assertSame(0, $this->store->open(RefreshRequest::forUserTag($userId, 7, self::BUDGET))->done);
    }

    public function testAForgottenRunStartsOverNextTime(): void
    {
        $request = RefreshRequest::forUser(1, self::BUDGET);
        $this->store->save($request, RefreshRunProgress::start()->advancedBy(20, 180));

        $this->store->forget($request);

        self::assertSame(0, $this->store->open($request)->done);
    }

    /**
     * A cache file is not a contract. A truncated or stale-shaped entry must open
     * a fresh run rather than reach into an array that is not there.
     */
    public function testAMalformedEntryOpensAFreshRun(): void
    {
        $cache = new ArrayAdapter();
        $store = new RefreshRunStore($cache);
        $request = RefreshRequest::forUser(1, self::BUDGET);
        $store->save($request, RefreshRunProgress::start()->advancedBy(20, 180));

        foreach (array_keys($cache->getValues()) as $key) {
            $item = $cache->getItem($key);
            $item->set('not an array');
            $cache->save($item);
        }

        self::assertSame(0, $store->open($request)->done);
    }

    /**
     * The CLI and maintenance sweeps build requests with no user and call
     * RefreshRunner directly. Reaching this store without a user is a wiring
     * mistake, and a silent shared key would pool every user's run into one.
     */
    public function testARequestWithNoUserIsAProgrammingError(): void
    {
        $this->expectException(\LogicException::class);

        $this->store->open(RefreshRequest::allDue(self::BUDGET));
    }
}
```

- [ ] **Step 2: Run the test and confirm it fails**

```bash
php bin/phpunit tests/Service/Refresh/RefreshRunStoreTest.php
```

Expected: `Class "App\Service\Refresh\RefreshRunStore" not found`.

- [ ] **Step 3: Write the implementation**

Create `backend/src/Service/Refresh/RefreshRunStore.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Refresh;

use Psr\Cache\CacheItemPoolInterface;
use Psr\Cache\InvalidArgumentException;

/**
 * Remembers one run's progress between two of its slices.
 *
 * A cache pool, not a table. The record is two integers describing a run that lasts a
 * couple of minutes and is worthless the moment it ends: an entity, a migration, a CI
 * migration leg and a sweeper for abandoned runs would all be paid for nothing. The
 * TTL is the reaper. If an entry evaporates — a cleared cache, a moved deploy
 * directory — the next slice re-derives a denominator from itself and the bar jumps
 * once, which is the whole cost of losing it.
 *
 * The scope is part of the key on purpose. Refreshing one feed while a whole sweep is
 * in flight is a different run with a different denominator, and a shared key would
 * make each corrupt the other.
 */
final readonly class RefreshRunStore
{
    /**
     * Comfortably longer than a run: a slice is budgeted at 25 s and a large sweep is
     * a handful of them. Short enough that an abandoned run — a closed tab, a phone
     * that slept — is gone long before the user comes back and starts a new one.
     */
    private const int LIFETIME_SECONDS = 600;
    private const string KEY_PREFIX = 'refresh_run_';

    public function __construct(private CacheItemPoolInterface $refreshRunCache)
    {
    }

    /** @throws InvalidArgumentException */
    public function open(RefreshRequest $request): RefreshRunProgress
    {
        $item = $this->refreshRunCache->getItem($this->keyFor($request));
        $stored = $item->isHit() ? $item->get() : null;

        // A cache file is not a contract: it survives deploys that change this
        // shape, and it can be truncated. An unreadable entry is a new run, not a
        // crash.
        if (!\is_array($stored) || !\is_int($stored['done'] ?? null) || !\is_int($stored['total'] ?? null)) {
            return RefreshRunProgress::start();
        }

        return RefreshRunProgress::resumed($stored['done'], $stored['total']);
    }

    /** @throws InvalidArgumentException */
    public function save(RefreshRequest $request, RefreshRunProgress $progress): void
    {
        $item = $this->refreshRunCache->getItem($this->keyFor($request));
        $item->set($progress->toArray());
        $item->expiresAfter(self::LIFETIME_SECONDS);
        $this->refreshRunCache->save($item);
    }

    /** @throws InvalidArgumentException */
    public function forget(RefreshRequest $request): void
    {
        $this->refreshRunCache->deleteItem($this->keyFor($request));
    }

    private function keyFor(RefreshRequest $request): string
    {
        if (null === $request->userId) {
            throw new \LogicException(
                'A tracked refresh run needs a user. The CLI and maintenance sweeps call RefreshRunner directly.',
            );
        }

        return self::KEY_PREFIX . $request->userId . '.' . $this->scopeOf($request);
    }

    private function scopeOf(RefreshRequest $request): string
    {
        if (null !== $request->feedId) {
            return 'feed-' . $request->feedId;
        }

        if (null !== $request->tagId) {
            return 'tag-' . $request->tagId;
        }

        return 'all';
    }
}
```

- [ ] **Step 4: Add the cache pool**

In `backend/config/packages/cache.yaml`, append to the `pools:` block (after
`github.release.cache`), keeping the surrounding indentation:

```yaml
            # One refresh run's progress between two of its slices (#721). The
            # store sets an explicit ten-minute expiry on every entry it writes;
            # this lifetime is only a backstop for an entry whose expiry was
            # somehow lost, the same relationship oauth.login_code.cache has to
            # its store. Losing an entry costs one jump of the progress bar, which
            # is why two integers with a two-minute life get a cache pool rather
            # than a table and a sweeper.
            refresh.run.cache:
                adapter: cache.adapter.filesystem
                default_lifetime: 900
```

- [ ] **Step 5: Bind the pool**

In `backend/config/services.yaml`, inside `_defaults: bind:`, after the
`$githubReleaseCache` line:

```yaml
            Psr\Cache\CacheItemPoolInterface $refreshRunCache: '@refresh.run.cache'
```

- [ ] **Step 6: Run the test and confirm it passes**

```bash
php bin/phpunit tests/Service/Refresh/RefreshRunStoreTest.php
```

Expected: PASS, 8 tests.

- [ ] **Step 7: Prove the wiring, not just the class**

```bash
bin/console cache:warmup && bin/console debug:container App\\Service\\Refresh\\RefreshRunStore
```

Expected: the service resolves and its argument shows `refresh.run.cache`. A
container that cannot build the service is the failure this step exists to catch —
the unit test above constructs it by hand and would never see it.

- [ ] **Step 8: Run the quality gates**

```bash
composer cs && composer stan && composer md
```

- [ ] **Step 9: Commit**

```bash
git add backend/src/Service/Refresh/RefreshRunStore.php backend/tests/Service/Refresh/RefreshRunStoreTest.php backend/config/packages/cache.yaml backend/config/services.yaml
git commit -m "feat(#721): keep a refresh run's progress between its slices"
```

---

### Task 3: `TrackedRefreshRunner` — folding a slice into the run

**Files:**
- Create: `backend/src/Service/Refresh/RefreshRunnerInterface.php`
- Create: `backend/src/Service/Refresh/TrackedRefreshReport.php`
- Create: `backend/src/Service/Refresh/TrackedRefreshRunner.php`
- Create: `backend/tests/Service/Refresh/TrackedRefreshRunnerTest.php`
- Create: `backend/tests/Service/Refresh/FakeRefreshRunner.php`
- Modify: `backend/src/Service/Refresh/RefreshRunner.php` (implement the interface)
- Modify: `backend/src/Service/Refresh/RefreshReport.php` (add `STATUS_BUSY`)
- Modify: `backend/config/services.yaml` (alias the interface, near line 89)

**Interfaces:**
- Consumes: `RefreshRunProgress` (Task 1), `RefreshRunStore` (Task 2).
- Produces:
  - `RefreshRunnerInterface::run(RefreshRequest $request): RefreshReport`
  - `TrackedRefreshReport` with `public RefreshReport $report`,
    `public RefreshRunProgress $progress`
  - `TrackedRefreshRunner::run(RefreshRequest $request): TrackedRefreshReport`
  - `RefreshReport::STATUS_BUSY` (`'busy'`)

- [ ] **Step 1: Write the failing test**

Create `backend/tests/Service/Refresh/TrackedRefreshRunnerTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Refresh;

use App\Service\Refresh\RefreshReport;
use App\Service\Refresh\RefreshRequest;
use App\Service\Refresh\RefreshRunnerInterface;
use App\Service\Refresh\RefreshRunStore;
use App\Service\Refresh\TrackedRefreshRunner;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

final class TrackedRefreshRunnerTest extends TestCase
{
    private const int BUDGET = 25;

    private RefreshRunStore $store;

    protected function setUp(): void
    {
        $this->store = new RefreshRunStore(new ArrayAdapter());
    }

    /**
     * The issue itself. A 200-feed sweep's first slice takes on the server's batch
     * of 50, finishes 20 of them inside its time budget and leaves 180 due. The old
     * client computed (50 - 180) / 50 and clamped the bar to zero; the run is 20 of
     * 200 (#721).
     */
    public function testTheFirstSliceOfALargeSweepReportsRunWideProgress(): void
    {
        $runner = $this->trackedRunner(
            RefreshReport::finished(50, 20, 0, 0, 0, 30, 180, 0),
        );

        $tracked = $runner->run(RefreshRequest::forUser(1, self::BUDGET));

        self::assertSame('partial', $tracked->report->status);
        self::assertSame(20, $tracked->progress->done);
        self::assertSame(200, $tracked->progress->total);
    }

    public function testProgressCarriesAcrossSlicesAndOnlyEverMovesForward(): void
    {
        $request = RefreshRequest::forUser(1, self::BUDGET);
        $runner = $this->trackedRunner(
            RefreshReport::finished(50, 20, 0, 0, 0, 30, 180, 0),
            RefreshReport::finished(50, 45, 5, 0, 0, 0, 130, 0),
            RefreshReport::finished(50, 48, 2, 0, 0, 0, 80, 0),
        );

        $first = $runner->run($request)->progress;
        $second = $runner->run($request)->progress;
        $third = $runner->run($request)->progress;

        self::assertSame([20, 200], [$first->done, $first->total]);
        self::assertSame([70, 200], [$second->done, $second->total]);
        self::assertSame([120, 200], [$third->done, $third->total]);
    }

    /** Every outcome that ends a feed's turn counts, not only a successful fetch. */
    public function testNotModifiedFailedAndThrottledFeedsAllCountAsHandled(): void
    {
        $runner = $this->trackedRunner(
            RefreshReport::finished(8, 2, 3, 2, 1, 0, 0, 0),
        );

        $tracked = $runner->run(RefreshRequest::forUser(1, self::BUDGET));

        self::assertSame(8, $tracked->progress->done);
        self::assertSame(8, $tracked->progress->total);
    }

    /**
     * A busy answer means the global lock was held and NO slice ran. Its tally is
     * all zeros, including `remaining` — folding that in would set the denominator
     * to whatever was already done and slam the bar to full.
     */
    public function testABusyAnswerLeavesTheRunExactlyWhereItWas(): void
    {
        $request = RefreshRequest::forUser(1, self::BUDGET);
        $runner = $this->trackedRunner(
            RefreshReport::finished(50, 20, 0, 0, 0, 30, 180, 0),
            RefreshReport::busy(),
        );

        $runner->run($request);
        $tracked = $runner->run($request);

        self::assertSame('busy', $tracked->report->status);
        self::assertSame(20, $tracked->progress->done);
        self::assertSame(200, $tracked->progress->total);
    }

    /**
     * A busy answer to the very first call must not leave a zeroed run behind for
     * the real first slice to resume from.
     */
    public function testABusyFirstCallStoresNothing(): void
    {
        $request = RefreshRequest::forUser(1, self::BUDGET);
        $runner = $this->trackedRunner(
            RefreshReport::busy(),
            RefreshReport::finished(50, 20, 0, 0, 0, 30, 180, 0),
        );

        $runner->run($request);
        $tracked = $runner->run($request);

        self::assertSame(20, $tracked->progress->done);
        self::assertSame(200, $tracked->progress->total);
    }

    /** A finished run must not be resumed by the next press of Refresh. */
    public function testAFinishedRunIsForgotten(): void
    {
        $request = RefreshRequest::forUser(1, self::BUDGET);
        $runner = $this->trackedRunner(
            RefreshReport::finished(4, 4, 0, 0, 0, 0, 0, 0),
            RefreshReport::finished(2, 2, 0, 0, 0, 0, 0, 0),
        );

        $runner->run($request);
        $tracked = $runner->run($request);

        self::assertSame(2, $tracked->progress->done);
        self::assertSame(2, $tracked->progress->total);
    }

    /**
     * An aborted run is over: the EntityManager is closed and the client stops.
     * Leaving its record behind would have the next run resume a dead one.
     */
    public function testAnAbortedRunIsForgotten(): void
    {
        $request = RefreshRequest::forUser(1, self::BUDGET);
        $runner = $this->trackedRunner(
            RefreshReport::aborted(50, 3, 0, 0, 0, 47),
            RefreshReport::finished(2, 2, 0, 0, 0, 0, 0, 0),
        );

        $runner->run($request);
        $tracked = $runner->run($request);

        self::assertSame(2, $tracked->progress->done);
        self::assertSame(2, $tracked->progress->total);
    }

    private function trackedRunner(RefreshReport ...$reports): TrackedRefreshRunner
    {
        return new TrackedRefreshRunner(new FakeRefreshRunner(...$reports), $this->store);
    }
}
```

Create `backend/tests/Service/Refresh/FakeRefreshRunner.php` — its own file, because
`phpcs.xml.dist` applies PSR-12 to `tests/` and only `tests/PhpStan/data/*` is excused
from one-class-per-file. This matches `tests/Service/Search/FakeSearchIndexReader.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Refresh;

use App\Service\Refresh\RefreshReport;
use App\Service\Refresh\RefreshRequest;
use App\Service\Refresh\RefreshRunnerInterface;

/**
 * Hands out one prepared report per call. A double rather than a mock so the
 * expectations read as "given these slices" instead of as call counts — and
 * because RefreshRunner is final, which is why the interface exists at all.
 */
final class FakeRefreshRunner implements RefreshRunnerInterface
{
    /** @var list<RefreshReport> */
    private array $reports;

    public function __construct(RefreshReport ...$reports)
    {
        $this->reports = array_values($reports);
    }

    public function run(RefreshRequest $request): RefreshReport
    {
        $report = array_shift($this->reports);
        if (null === $report) {
            throw new \LogicException('The runner was asked for more slices than the test prepared.');
        }

        return $report;
    }
}
```

- [ ] **Step 2: Run the test and confirm it fails**

```bash
php bin/phpunit tests/Service/Refresh/TrackedRefreshRunnerTest.php
```

Expected: `Interface "App\Service\Refresh\RefreshRunnerInterface" not found`.

- [ ] **Step 3: Extract the seam**

Create `backend/src/Service/Refresh/RefreshRunnerInterface.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Refresh;

/**
 * One budgeted slice of refresh work.
 *
 * The seam exists so a caller that WRAPS a run — TrackedRefreshRunner, which folds
 * each slice into a run-wide tally — can be tested against prepared slices instead of
 * against the network, the clock and a database. RefreshRunner is final, so without
 * this there is no double to give it.
 */
interface RefreshRunnerInterface
{
    public function run(RefreshRequest $request): RefreshReport;
}
```

In `backend/src/Service/Refresh/RefreshRunner.php`, change the class declaration:

```php
final class RefreshRunner implements RefreshRunnerInterface
```

In `backend/config/services.yaml`, beside the other aliases (after the
`BatchFeedFetcherInterface` line, around line 89):

```yaml
    App\Service\Refresh\RefreshRunnerInterface: '@App\Service\Refresh\RefreshRunner'
```

- [ ] **Step 4: Name the busy status**

In `backend/src/Service/Refresh/RefreshReport.php`, beside `STATUS_ABORTED`:

```php
    /** The global refresh lock was held: NO slice ran, and every counter is zero. */
    public const string STATUS_BUSY = 'busy';
```

…and use it in the factory so the literal exists once:

```php
    public static function busy(): self
    {
        return new self(self::STATUS_BUSY, 0, 0, 0, 0, 0, 0, 0, 0);
    }
```

- [ ] **Step 5: Write the pair object**

Create `backend/src/Service/Refresh/TrackedRefreshReport.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Refresh;

/**
 * What one slice did, and where its run now stands.
 *
 * Two values because they answer two different questions and have two different
 * lifetimes: the report is this slice's, the progress is the run's. Hanging the
 * progress off RefreshReport instead would make it nullable for the CLI and
 * maintenance sweeps, which have no run to track and must not pay for one.
 */
final readonly class TrackedRefreshReport
{
    public function __construct(
        public RefreshReport $report,
        public RefreshRunProgress $progress,
    ) {
    }
}
```

- [ ] **Step 6: Write the tracker**

Create `backend/src/Service/Refresh/TrackedRefreshRunner.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Refresh;

use Psr\Cache\InvalidArgumentException;

/**
 * Runs one slice and folds it into the run it belongs to.
 *
 * The ONLY place run-wide accounting happens. RefreshRunner is deliberately left
 * alone: it already carries thirteen collaborators, and the CLI and maintenance
 * sweeps — which nothing polls — must not pay for a feature that exists for the
 * polling client.
 */
final readonly class TrackedRefreshRunner
{
    public function __construct(
        private RefreshRunnerInterface $refreshRunner,
        private RefreshRunStore $runs,
    ) {
    }

    /** @throws InvalidArgumentException */
    public function run(RefreshRequest $request): TrackedRefreshReport
    {
        $progress = $this->runs->open($request);
        $report = $this->refreshRunner->run($request);

        // The lock was held, so no slice ran. Its counters are all zero including
        // `remaining`, and folding those in would drop the denominator to whatever
        // was already done and report the run as finished.
        if (RefreshReport::STATUS_BUSY === $report->status) {
            return new TrackedRefreshReport($report, $progress);
        }

        $advanced = $progress->advancedBy($this->handledIn($report), $report->remaining);

        if (0 === $report->remaining || $report->isAborted()) {
            $this->runs->forget($request);

            return new TrackedRefreshReport($report, $advanced);
        }

        $this->runs->save($request, $advanced);

        return new TrackedRefreshReport($report, $advanced);
    }

    /**
     * Every outcome that ends a feed's turn, not only a successful fetch. A 304, a
     * failure and a 429 all take their feed out of `remaining`, so leaving them out
     * here would strand the bar short of full. Feeds the time budget deferred are
     * absent on purpose: they never started, and `remaining` still counts them.
     */
    private function handledIn(RefreshReport $report): int
    {
        return $report->fetched + $report->notModified + $report->failed + $report->throttled;
    }
}
```

- [ ] **Step 7: Run the test and confirm it passes**

```bash
php bin/phpunit tests/Service/Refresh/TrackedRefreshRunnerTest.php
```

Expected: PASS, 7 tests.

- [ ] **Step 8: Run the whole backend suite — the interface touched a shared class**

```bash
php bin/phpunit
```

Expected: PASS. `RefreshRunner` gained an interface and `RefreshReport` gained a
constant; nothing else changed behaviour.

- [ ] **Step 9: Run the quality gates**

```bash
composer check && composer md
```

`composer check` is cs + stan + tramp. Watch phptramp in particular: `$request` is
passed through `run()` into `open()`/`save()`/`forget()`, and all of those read its
fields, so it is not tramp data — but confirm rather than assume. If phptramp fails,
first run `composer show larspohlmann/phptramp`: CI runs the tip of its develop
branch, and a red gate here is sometimes that tool changing rather than this code.

- [ ] **Step 10: Commit**

```bash
git add backend/src/Service/Refresh backend/tests/Service/Refresh backend/config/services.yaml
git commit -m "feat(#721): fold each refresh slice into the run it belongs to"
```

---

### Task 4: The wire contract — `progress` in, `total` out

**Files:**
- Create: `backend/src/Http/RefreshJson.php`
- Modify: `backend/src/Controller/Api/RefreshController.php`
- Modify: `backend/tests/Controller/Api/RefreshControllerTest.php:56,108,179`

**Interfaces:**
- Consumes: `TrackedRefreshRunner::run(RefreshRequest): TrackedRefreshReport` (Task 3).
- Produces: the HTTP contract
  `{status, progress: {done, total}, fetched, notModified, failed, throttled, skippedForBudget, remaining, pruned}`.

- [ ] **Step 1: Write the failing test**

In `backend/tests/Controller/Api/RefreshControllerTest.php`, replace the three
`self::assertSame(…, $body['total']);` assertions.

At line 56 (`testRefreshWithNoFeedsReportsCompleted`):

```php
        self::assertSame('completed', $body['status']);
        self::assertSame(['done' => 0, 'total' => 0], $body['progress']);
        // `total` was this slice's server-capped batch size sitting next to a
        // run-wide `remaining`, and dividing one by the other is issue #721. It is
        // gone, and this asserts it stays gone.
        self::assertArrayNotHasKey('total', $body);
```

At line 108 (the per-feed refresh) and line 179 (the per-tag refresh), replace each
`self::assertSame(1, $body['total']);` with:

```php
        // Asserted as the invariant rather than as two literals: these tests accept
        // either `completed` or `partial`, and a partial slice leaves feeds in
        // `remaining` that belong in the run's denominator.
        self::assertSame(1, $body['progress']['done']);
        self::assertSame(1 + $body['remaining'], $body['progress']['total']);
        self::assertArrayNotHasKey('total', $body);
```

- [ ] **Step 2: Run the test and confirm it fails**

```bash
php bin/phpunit tests/Controller/Api/RefreshControllerTest.php
```

Expected: FAIL — `Undefined array key "progress"`.

- [ ] **Step 3: Write the mapper**

Create `backend/src/Http/RefreshJson.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http;

use App\Service\Refresh\TrackedRefreshReport;

/**
 * The refresh endpoint's response.
 *
 * `progress` is the run — the one figure a client renders, and the reason no client
 * has to reconcile anything. The counters beside it describe the slice that just
 * landed and name their own scope.
 *
 * There is deliberately no `total`. It was this slice's batch size, capped by
 * RefreshRunner::BATCH_LIMIT, sitting next to a run-wide `remaining` and inviting the
 * division that produced #721. RefreshReport still carries it for the worker's log,
 * which is a different audience with a different question.
 */
final class RefreshJson
{
    /**
     * @return array{status: string, progress: array{done: int, total: int}, fetched: int,
     *     notModified: int, failed: int, throttled: int, skippedForBudget: int,
     *     remaining: int, pruned: int}
     */
    public static function slice(TrackedRefreshReport $tracked): array
    {
        $report = $tracked->report;

        return [
            'status' => $report->status,
            'progress' => $tracked->progress->toArray(),
            'fetched' => $report->fetched,
            'notModified' => $report->notModified,
            'failed' => $report->failed,
            'throttled' => $report->throttled,
            'skippedForBudget' => $report->skippedForBudget,
            'remaining' => $report->remaining,
            'pruned' => $report->pruned,
        ];
    }
}
```

- [ ] **Step 4: Point the controller at it**

In `backend/src/Controller/Api/RefreshController.php`:

- swap the import `use App\Service\Refresh\RefreshRunner;` for
  `use App\Http\RefreshJson;` and `use App\Service\Refresh\TrackedRefreshRunner;`
- change the constructor property
  `private readonly RefreshRunner $refreshRunner,` to
  `private readonly TrackedRefreshRunner $trackedRefreshRunner,` — renamed, because the
  collaborator is no longer a `RefreshRunner` and the name has to say so
- change the return statement to:

```php
        return new JsonResponse(RefreshJson::slice($this->trackedRefreshRunner->run($request)));
```

Also update the class docblock's last sentence, which currently describes the loop
only. Replace `and loops until \`remaining\` reaches 0.` with:

```
 * and loops until `remaining` reaches 0. `progress` is the run as a whole — every
 * slice of it — and is the only figure a client should render.
```

- [ ] **Step 5: Run the test and confirm it passes**

```bash
php bin/phpunit tests/Controller/Api/RefreshControllerTest.php
```

Expected: PASS.

- [ ] **Step 6: Confirm the worker's log is untouched**

```bash
php bin/phpunit tests/Service/Worker/RefreshDueFeedsHandlerTest.php
```

Expected: PASS with its `'status', 'total', 'fetched', …` key list unchanged — proof
that dropping `total` from the API did not drop it from `RefreshReport::toArray()`,
which the worker still logs.

- [ ] **Step 7: Run the whole suite and the gates**

```bash
php bin/phpunit && composer check && composer md
```

`ThinControllerRule` runs inside `composer stan`; the action is one expression, so it
passes.

- [ ] **Step 8: Commit**

```bash
git add backend/src/Http/RefreshJson.php backend/src/Controller/Api/RefreshController.php backend/tests/Controller/Api/RefreshControllerTest.php
git commit -m "feat(#721): report the run's progress and drop the per-slice total"
```

---

### Task 5: The client renders the server's numbers

**Files:**
- Modify: `frontend/src/app/reader/models.ts:176-186`
- Modify: `frontend/src/app/reader/refresh.service.ts`
- Modify: `frontend/src/app/reader/refresh.service.spec.ts`

**Interfaces:**
- Consumes: the Task 4 contract.
- Produces: `RefreshProgress {done: number; total: number}` exported from `models.ts`;
  `RefreshService.progress(): RefreshProgress` and `RefreshService.fraction(): number`.
  `RefreshService.report` becomes **private**. `running()`, `failure()`, `slice()` and
  `run()` are unchanged.

- [ ] **Step 1: Write the failing test**

In `frontend/src/app/reader/refresh.service.spec.ts`, update the `report` helper — drop
`total`, add `throttled` and `progress`:

```ts
const report = (over: Partial<Record<string, unknown>>) => ({
  status: 'partial',
  progress: { done: 5, total: 10 },
  fetched: 0,
  notModified: 0,
  failed: 0,
  throttled: 0,
  skippedForBudget: 0,
  remaining: 5,
  pruned: 0,
  ...over,
});
```

In the first test (`loops partial then completes and calls onDone`), replace
`expect(svc.progress()).toBe(1);` with:

```ts
    expect(svc.fraction()).toBe(1);
```

…and change that test's completing flush to carry a finished run:

```ts
      .flush(report({ status: 'completed', remaining: 0, fetched: 10, progress: { done: 10, total: 10 } }));
```

Then add this block immediately before the test
`'scopes every request to the given feed id across the poll loop'`:

```ts
  // The client used to divide a slice's server-capped batch size by a run-wide
  // count of what was still due. On a 200-feed sweep that is (50 - 180) / 50 —
  // negative, clamped to 0 — so the bar sat still for minutes, then snapped to
  // full on the last slice (#721). The server now owns the figure and the client
  // renders it.
  describe('progress comes from the server, not from arithmetic here', () => {
    it('reports the run the server describes, not the slice', () => {
      svc.run();
      ctrl.expectOne('https://api.test/api/refresh').flush(
        report({ status: 'partial', remaining: 180, fetched: 20, progress: { done: 20, total: 200 } }),
      );

      expect(svc.progress()).toEqual({ done: 20, total: 200 });
      expect(svc.fraction()).toBeCloseTo(0.1);
    });

    it('is empty before the first slice lands', () => {
      svc.run();

      expect(svc.progress()).toEqual({ done: 0, total: 0 });
      expect(svc.fraction()).toBe(0);
    });

    it('starts the next run from zero rather than from the last one', () => {
      svc.run();
      ctrl
        .expectOne('https://api.test/api/refresh')
        .flush(report({ status: 'completed', remaining: 0, fetched: 4, progress: { done: 4, total: 4 } }));
      expect(svc.fraction()).toBe(1);

      svc.run();

      expect(svc.progress()).toEqual({ done: 0, total: 0 });
      expect(svc.fraction()).toBe(0);
      ctrl
        .expectOne('https://api.test/api/refresh')
        .flush(report({ status: 'completed', remaining: 0, fetched: 4, progress: { done: 4, total: 4 } }));
    });

    // A server that reports more done than total would be a bug, but the bar must
    // not render past its own track if one ever does.
    it('never renders past full', () => {
      svc.run();
      ctrl.expectOne('https://api.test/api/refresh').flush(
        report({ status: 'completed', remaining: 0, fetched: 12, progress: { done: 12, total: 10 } }),
      );

      expect(svc.fraction()).toBe(1);
    });
  });
```

- [ ] **Step 2: Run the tests and confirm they fail**

```bash
./node_modules/.bin/jest src/app/reader/refresh.service.spec.ts
```

Expected: FAIL — `svc.fraction is not a function`.

- [ ] **Step 3: Update the DTO**

In `frontend/src/app/reader/models.ts`, replace the `RefreshReport` interface
(lines 176-186) with:

```ts
/** How far a refresh RUN has got, straight from the server.
 *
 *  Run-wide, across every slice — which is why no client computes it. A slice's own
 *  counters describe that slice, and only the server sees the run (#721). */
export interface RefreshProgress {
  /** Feeds this run has taken to an outcome. */
  done: number;
  /** What the run has to do: `done` plus what is still due. */
  total: number;
}

export interface RefreshReport {
  status: 'busy' | 'partial' | 'completed' | 'aborted';
  progress: RefreshProgress;
  fetched: number;
  notModified: number;
  failed: number;
  /** Feeds the site rationed. Healthy, and asked again shortly — not failures. */
  throttled: number;
  skippedForBudget: number;
  remaining: number;
  pruned: number;
}
```

- [ ] **Step 4: Update the service**

In `frontend/src/app/reader/refresh.service.ts`:

- change the import line to
  `import { RefreshProgress, RefreshReport } from './models';`
- make the report signal private and replace the `progress` computed. Change:

```ts
  readonly report = signal<RefreshReport | null>(null);
```

to:

```ts
  /** The newest slice. Private: it describes ONE slice, and every consumer that
   *  did its own arithmetic over it got a different answer (#721). What callers
   *  want is `progress`, which describes the run. */
  private readonly report = signal<RefreshReport | null>(null);
```

Replace the `progress` computed:

```ts
  readonly progress = computed(() => {
    const r = this.report();
    if (!r || r.total <= 0) return 0;
    return Math.min(1, Math.max(0, (r.total - r.remaining) / r.total));
  });
```

with:

```ts
  /** The run's progress exactly as the server reports it. Nothing here recomputes
   *  it — that is the whole point of #721. */
  readonly progress = computed<RefreshProgress>(
    () => this.report()?.progress ?? { done: 0, total: 0 },
  );

  /** The same figure as a 0..1 fraction, for anything that draws a bar. Clamped
   *  only so a bar can never render past its own track. */
  readonly fraction = computed(() => {
    const { done, total } = this.progress();
    if (total <= 0) return 0;
    return Math.min(1, Math.max(0, done / total));
  });
```

- [ ] **Step 5: Run the tests and confirm they pass**

```bash
./node_modules/.bin/jest src/app/reader/refresh.service.spec.ts
```

Expected: PASS. The `report()` signal is now private, so if any spec outside the
service reads it the compiler says so — that is Task 6's work.

- [ ] **Step 6: Commit**

```bash
git add frontend/src/app/reader/models.ts frontend/src/app/reader/refresh.service.ts frontend/src/app/reader/refresh.service.spec.ts
git commit -m "feat(#721): render the run's progress instead of recomputing it"
```

---

### Task 6: One bar, and it lives in the app bar

**Files:**
- Modify: `frontend/src/app/reader/sidebar/sidebar.component.html:29-31` (delete)
- Modify: `frontend/src/app/reader/sidebar/sidebar.component.scss:46-60` (delete)
- Modify: `frontend/src/app/reader/sidebar/sidebar.component.spec.ts:148-157`
- Modify: `frontend/src/app/reader/header/reader-header.component.html` (add, at the end)
- Modify: `frontend/src/app/reader/header/reader-header.component.ts` (import)
- Modify: `frontend/src/app/reader/header/reader-header.component.scss` (position it)
- Modify: `frontend/src/app/reader/reader-shell.component.html:16-19,28` (remove the
  hairline, repoint the banner)
- Modify: `frontend/src/app/reader/reader-shell.component.ts:191-195` (delete
  `fetchProgress`)
- Modify: `frontend/src/app/reader/reader-shell.component.spec.ts:934-943,1001,1024`

**Interfaces:**
- Consumes: `RefreshService.progress()` and `.fraction()` from Task 5.
- Produces: no new API. `ProgressHairlineComponent` keeps its `active` and `value`
  inputs unchanged.

- [ ] **Step 1: Write the failing tests**

In `frontend/src/app/reader/sidebar/sidebar.component.spec.ts`, replace the test at
line 148 with:

```ts
  it('disables Refresh while refreshing and shows no progress bar of its own', () => {
    const f = mount();
    TestBed.inject(RefreshService).running.set(true);
    f.detectChanges();
    const el = f.nativeElement as HTMLElement;
    expect((el.querySelector('.act[aria-label="Refresh"]') as HTMLButtonElement).disabled).toBe(
      true,
    );
    // The refresh has exactly one bar and it belongs to the app bar. A second one
    // here was narrower than the first, sat directly under it on desktop, and drew
    // the same number twice (#721).
    expect(el.querySelector('.prog')).toBeNull();
  });
```

In `frontend/src/app/reader/reader-shell.component.spec.ts`, update the `refreshDone`
fixture at line 934:

```ts
  const refreshDone = {
    status: 'completed',
    progress: { done: 0, total: 0 },
    fetched: 0,
    notModified: 0,
    failed: 0,
    throttled: 0,
    skippedForBudget: 0,
    remaining: 0,
    pruned: 0,
  };
```

At line 1001, replace the partial-slice flush with:

```ts
      refresh.flush({
        ...refreshDone,
        status: 'partial',
        progress: { done: 1, total: 2 },
        fetched: 1,
        remaining: 1,
      });
```

At line 1024, replace the finishing flush with:

```ts
      next.flush({ ...refreshDone, progress: { done: 2, total: 2 }, fetched: 2 });
```

Then add this test to the same file, beside the other header tests (search for
`app-reader-header .for-you-progress` near line 1785 and add after that test):

```ts
  it('draws the refresh bar inside the app bar, and nowhere else', () => {
    const f = boot();
    TestBed.inject(RefreshService).running.set(true);
    f.detectChanges();
    const el = f.nativeElement as HTMLElement;

    // Exactly one. Two bars for one refresh is #721's first symptom.
    expect(el.querySelectorAll('app-progress-hairline').length).toBe(1);
    // Inside the bar, so it travels with the bar. Parked in the strip below it,
    // the bar retracted on scroll and left a 2px band over the content.
    expect(el.querySelector('app-reader-header app-progress-hairline')).not.toBeNull();
    expect(el.querySelector('.under-header app-progress-hairline')).toBeNull();
  });
```

- [ ] **Step 2: Run the tests and confirm they fail**

```bash
./node_modules/.bin/jest src/app/reader/sidebar src/app/reader/reader-shell.component.spec.ts
```

Expected: the sidebar test fails (`.prog` is still there) and the new shell test fails
(the hairline is not in the header).

- [ ] **Step 3: Delete the sidebar's bar**

In `frontend/src/app/reader/sidebar/sidebar.component.html`, delete these three lines
(29-31) entirely, including the blank line after them:

```html
    @if (refreshSvc.running()) {
      <span class="prog"><i [style.width.%]="refreshSvc.progress() * 100"></i></span>
    }
```

In `frontend/src/app/reader/sidebar/sidebar.component.scss`, delete both rules
(lines 46-60): `.prog { … }` and `.prog i { … }`.

Leave `refreshSvc` injected in `sidebar.component.ts` — the Refresh button's
`[disabled]` still reads `running()`.

- [ ] **Step 4: Put the hairline in the app bar**

In `frontend/src/app/reader/header/reader-header.component.ts`, add the import beside
the other shared-component imports (after line 18):

```ts
import { ProgressHairlineComponent } from '../../shared/progress-hairline/progress-hairline.component';
```

…and add `ProgressHairlineComponent` to the `imports:` array of the `@Component`
decorator.

In `frontend/src/app/reader/header/reader-header.component.html`, append at the very
end of the file — **outside** the `@if (!searchOpen())` block, so a refresh started
before the mobile search bar opened still reports itself:

```html
<!-- The refresh bar belongs to the app bar, so it retracts with it. Parked in a
     strip below the bar it stayed behind when the bar slid away on scroll, leaving
     a 2px band over the article (#721). Absolutely positioned on the bar's bottom
     edge, so it adds nothing to the height the shell's ResizeObserver publishes as
     --app-bar-h and starting a refresh no longer thickens the chrome. -->
<app-progress-hairline [active]="refreshSvc.running()" [value]="refreshSvc.fraction()" />
```

In `frontend/src/app/reader/header/reader-header.component.scss`, append:

```scss
/* The shell positions this component (`app-reader-header` in
   reader-shell.component.scss), so the host is the containing block and the bar
   hangs off its bottom edge. Above `header`'s own z-index 2 so it paints over the
   bottom border rather than under it. */
app-progress-hairline {
  position: absolute;
  bottom: 0;
  left: 0;
  right: 0;
  z-index: 3;
}
```

- [ ] **Step 5: Take it out of the shell**

In `frontend/src/app/reader/reader-shell.component.html`, find the comment block that
begins `<!-- Out of flow, dropped into the seam` and ends `stacked above the
sweep-only counted banner. -->`, followed by `<div class="under-header">` and then the
`<app-progress-hairline … />` line. Replace all of it — comment, wrapper opening and
hairline — with the wrapper and its new comment (the hairline line goes entirely):

```html
<!-- Out of flow, dropped into the seam just under the (absolute, opaque) header:
     an in-flow banner would paint at y=0 behind the header. The hairline used to
     live here too and now sits inside the bar itself, so that it retracts with it
     (#721); what is left are the two banners, which are correct to stay put and
     visible the way the list header does. -->
<div class="under-header">
```

Then, at line 28, repoint the banner's counts at the service:

```html
      {{ 'reader.fetchingFeeds' | transloco: refreshSvc.progress() }}
```

Remove the now-unused `ProgressHairlineComponent` import and its entry in the
`imports:` array of `frontend/src/app/reader/reader-shell.component.ts`.

- [ ] **Step 6: Delete the shell's own arithmetic**

In `frontend/src/app/reader/reader-shell.component.ts`, delete the whole
`fetchProgress` computed (lines 191-195):

```ts
  readonly fetchProgress = computed(() => {
    const report = this.refreshSvc.report();
    if (!report) return { done: 0, total: 0 };
    return { done: report.total - report.remaining, total: report.total };
  });
```

This was the second consumer doing its own maths over the slice DTO, and the reason
the onboarding banner could count backwards from zero.

- [ ] **Step 7: Run the tests and confirm they pass**

```bash
./node_modules/.bin/jest src/app/reader src/app/shared/progress-hairline
```

Expected: PASS.

- [ ] **Step 8: Run the frontend gate**

```bash
npm run check
```

Expected: clean. ESLint reports the unused `ProgressHairlineComponent` import if
Step 5 missed it.

- [ ] **Step 9: Commit**

```bash
git add frontend/src/app/reader frontend/src/app/shared
git commit -m "fix(#721): draw one refresh bar, inside the app bar it belongs to"
```

---

### Task 7: The banners travel with the bar too

**Files:**
- Modify: `frontend/src/app/reader/reader-shell.component.scss:218-224`

**Interfaces:**
- Consumes: the `--app-bar-shift` custom property the shell already publishes
  (`reader-shell.component.ts:674`).
- Produces: nothing.

- [ ] **Step 1: Make the strip follow the bar**

In `frontend/src/app/reader/reader-shell.component.scss`, replace the `.under-header`
rule with:

```scss
.under-header {
  position: absolute;
  top: var(--app-bar-h, var(--bar-h));
  left: 0;
  right: 0;
  z-index: 4;

  /* Travels with the app bar, exactly as `.list-header` does and for the same
     reason: it hangs directly beneath the bar, so staying put while the bar
     retracted would leave it floating over a strip of nothing. A banner is an
     alert and is correct to stay visible at the top — unlike the refresh
     hairline, which is chrome and now retracts with the bar itself (#721). */
  transform: translateY(var(--app-bar-shift, 0));
  transition: transform 0.2s ease;
}

@media (prefers-reduced-motion: reduce) {
  .under-header {
    transition: none;
  }
}
```

- [ ] **Step 2: Confirm nothing regressed**

```bash
./node_modules/.bin/jest src/app/reader/reader-shell.component.spec.ts && npm run check
```

Expected: PASS and clean. Jest cannot see a transform; Task 9 verifies this one on
the real render.

- [ ] **Step 3: Commit**

```bash
git add frontend/src/app/reader/reader-shell.component.scss
git commit -m "fix(#721): let the under-header banners retract with the app bar"
```

---

### Task 8: The bar says "working" between slices

A slice is budgeted at 25 seconds, so a large sweep steps roughly every 25 seconds. The
width stays honest and a sheen carries the activity.

**Files:**
- Modify: `frontend/src/app/shared/progress-hairline/progress-hairline.component.scss`
- Modify: `frontend/src/app/shared/progress-hairline/progress-hairline.component.ts`
  (docblock only)

**Interfaces:**
- Consumes: nothing new.
- Produces: nothing new. Pure CSS on the existing markup.

- [ ] **Step 1: Add the sheen**

Replace the whole of
`frontend/src/app/shared/progress-hairline/progress-hairline.component.scss` with:

```scss
.bar {
  height: var(--space-0);
  background: var(--border);
}

span {
  display: block;
  position: relative;
  height: 100%;
  background: var(--accent);
  overflow: hidden;
  transition: width 0.3s ease-out;
}

/* The server reports at slice boundaries and a slice is budgeted at 25 s, so the
   width steps rather than creeps. This says "still working" in between WITHOUT
   claiming progress the server has not reported — the alternative, gliding the
   width toward a guessed value, shows work nobody has done and then has to correct
   itself (#721). */
span::after {
  content: '';
  position: absolute;
  inset: 0;
  background: linear-gradient(90deg, transparent, var(--accent-soft), transparent);
  animation: hairline-sheen 1.6s ease-in-out infinite;
}

@keyframes hairline-sheen {
  from {
    transform: translateX(-100%);
  }

  to {
    transform: translateX(100%);
  }
}

@media (prefers-reduced-motion: reduce) {
  span::after {
    animation: none;
    opacity: 0;
  }
}
```

- [ ] **Step 2: Update the component's docblock**

In `frontend/src/app/shared/progress-hairline/progress-hairline.component.ts`, replace
the class docblock with:

```ts
/**
 * A 2px determinate bar. Zero layout shift, so it sits in the app bar permanently
 * and upgrades EVERY refresh — not just the onboarding sweep — from "an icon is
 * spinning" to "this much of it is done".
 *
 * The width is only ever what the server has reported. A slice is budgeted at 25 s,
 * so it steps rather than creeps; the stylesheet's sheen carries the activity in
 * between (#721).
 */
```

- [ ] **Step 3: Confirm the gate is clean**

```bash
./node_modules/.bin/jest src/app/shared/progress-hairline && npm run check
```

Expected: PASS and clean. Stylelint rejects a hex colour or a raw `px` here, so the
sheen uses `--accent-soft` and the existing `--space-0`.

- [ ] **Step 4: Commit**

```bash
git add frontend/src/app/shared/progress-hairline
git commit -m "feat(#721): keep the refresh bar alive between slices"
```

---

### Task 9: Remove the dead code, then prove the whole thing on the real render

The change is not finished while a replaced mechanism is still in the tree, and gates
being green is not the deliverable.

**Files:**
- Modify: `backend/src/Service/Refresh/RefreshReport.php` — the one candidate that
  needs a decision rather than a deletion (see Step 2). Everything else the sweep can
  find was already deleted by Tasks 5-8; the greps below exist to prove that, and to
  catch an import or assertion left without a subject.
- Modify: `frontend/e2e/pull-to-refresh-mobile.spec.ts:46-50`

**Interfaces:**
- Consumes: everything above.
- Produces: a branch with no orphaned symbol.

- [ ] **Step 1: Update the e2e stub to the new contract**

In `frontend/e2e/pull-to-refresh-mobile.spec.ts`, replace the refresh stub's JSON:

```ts
  await page.route('**/api/refresh*', async (route) =>
    route.fulfill({
      status: 200,
      json: { status: 'completed', progress: { done: 1, total: 1 }, remaining: 0 },
    }),
  );
```

- [ ] **Step 2: Sweep for orphans**

Run each of these from the repository root. Every one must come back empty, except
where noted:

```bash
grep -rn "fetchProgress" frontend/src
grep -rn "\.prog\b" frontend/src/app/reader/sidebar
grep -rn "refreshSvc.report()\|refresh.report()" frontend/src
grep -rn "progress-hairline" frontend/src/app/reader/reader-shell.component.ts frontend/src/app/reader/reader-shell.component.html
```

Then decide the one real question — whether `RefreshReport::$total` still has a reader:

```bash
grep -rn "\->total\|'total'" backend/src backend/tests | grep -i refresh
```

`RefreshReport::toArray()` and `RefreshDueFeedsHandlerTest` are expected hits: the
worker logs the slice's batch size and that is a legitimate, different question from
the API's. **If those are the only hits, `$total` stays** and the spec's note about it
is satisfied. If it has no reader at all, remove it from the constructor, from
`busy()`, `finished()`, `aborted()` and `toArray()`, and update
`RefreshDueFeedsHandlerTest`'s key-list assertion.

- [ ] **Step 3: Run every gate, both suites**

From `backend/`:

```bash
php bin/phpunit && composer check && composer md
```

From `frontend/`:

```bash
npm run check
```

Expected: all clean.

- [ ] **Step 4: Run the mutation gate on what this branch changed**

From `backend/`:

```bash
composer infection:diff
```

Expected: at or above `minMsi` in `infection.json5`. Escaped mutants on
`RefreshRunProgress::advancedBy`'s `max()` mean a missing test — the denominator cases
in Task 1 are the ones that kill it. Never lower `minMsi` to pass.

- [ ] **Step 5: Verify on the real render**

Gates green is not the deliverable — this issue is three visual defects.

```bash
docker compose up -d
```

Then, in the running stack, with an account holding enough feeds that a sweep needs
more than one slice:

1. Press Refresh on a wide window. **One** bar, full width, under the app bar. Nothing
   in the sidebar.
2. Watch it across the whole sweep. It must start above zero on the first slice, only
   ever move forward, and reach full exactly as the run ends. It must never sit at
   zero for the first minute — that was the original complaint.
3. The sheen must be visible between steps and readable in **both** themes. Check
   `--accent-soft` actually reads as a sheen against `--accent` in dark mode; if it
   does not, pick the token that does and say which.
4. On a narrow window, start a refresh and scroll the list down. The app bar retracts
   and the bar must go with it — **no** 2px band left over the article. Scroll back up
   and it returns with the bar.
5. Check the dev log for anything the change surfaced:

```bash
ls -t backend/var/log/dev-*.log | head -1
```

- [ ] **Step 6: Commit whatever the sweep and the verification changed**

```bash
git add -A
git commit -m "chore(#721): remove the replaced refresh-progress code"
```

- [ ] **Step 7: Open the pull request**

```bash
gh pr create --base develop --title "fix(#721): give the refresh run its own progress" --body "Closes #721"
```

The body must say `Closes #721` so the merge closes the issue. Verify it closed after
the merge rather than closing it by hand.
