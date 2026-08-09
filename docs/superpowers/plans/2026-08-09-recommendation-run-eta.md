# For-You Run ETA + Anticipatory Progress Bar (#336) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Give the For-You recommendation run an honest ETA and a progress bar that creeps toward the next milestone between batch completions, both paced by how long the previous batches actually took.

**Architecture:** Backend adds the run's start time to `RecommendationRunReport` as a timeless `startedAt`, and the `Http` mapper turns it into a server-computed `elapsedSeconds` at the wire boundary (one clock consumer, no threading through 11 `fromRun` call sites). Frontend: `RecommendationsService` blends an average batch duration from `elapsedSeconds / batchesDone`, anchors each in-flight batch to a monotonic clock, and an interval ticker drives an interpolated `progress()` (capped just short of the next milestone), an `etaSeconds()`, and an `etaState()`. A new thin `ForYouProgressComponent` composes the dumb shared hairline with the ETA/status label and replaces the two identical render sites in `reader-shell.component.html`.

**Tech Stack:** Symfony 7.4 / PHP 8.4 / Doctrine (MySQL + SQLite), Symfony Clock, Angular 20 standalone + signals, Transloco, Jest, PHPUnit.

## Global Constraints

- **Branch:** `feature/336-recommendation-run-eta` off `develop`. **A different session is currently on `feature/334-multiple-ai-configs` with uncommitted edits — do not touch that branch.** `git status` before branching; concurrent sessions share this checkout.
- Every PHP file: `declare(strict_types=1)`; PSR-12 (`composer cs:fix`); PHPStan level max (warm cache first: `bin/console cache:warmup`); **every touched `src` file PHPMD-clean** (`composer md`), not merely free of new findings. PhpStorm inspections (`mcp__phpstorm__lint_files`) on changed PHP — block on ERROR/WARNING.
- Controllers stay thin (`ThinControllerRule`): no private method carries responsibility; response assembly lives in `src/Http/*Json.php`. Injecting a `ClockInterface` and passing it to the mapper is a dependency, not logic — allowed.
- House style: `final readonly class` + constructor promotion; typed exceptions in `Service/*/Exception/`; no boolean-flag parameters; guard clauses over nesting.
- Datetimes are naive UTC; the Kernel pins UTC. Use the injected `ClockInterface` / `MockClock` for "now" — never `new \DateTimeImmutable()` for the current time in domain/mapper code.
- **No migration and no schema change:** `RecommendationRun.createdAt` already exists; `elapsedSeconds` is derived on read, nothing is persisted.
- No new endpoint — one field is added to an existing response body. Honour the native-iOS shape (docs/architecture.md §6): bearer, stateless, JSON in, `application/problem+json` out.
- Frontend: standalone components + signals; component styles in a sibling `.scss` (never inline in `.ts`); no hex colours / no raw `px` spacing outside `src/app/theme/`; Prettier 100-col; run `npm run check` from `frontend/`.
- Every new UI string gets a key in **both** `frontend/public/i18n/en.json` and `de.json`.
- Frontend time is read through an injectable `MONOTONIC_NOW` token so tests control it; timers follow the house pattern — imperative `setInterval`/`setTimeout` with `DestroyRef.onDestroy` cleanup (see `src/app/core/navigation-watchdog.ts`), tested with `jest.useFakeTimers()` + `jest.advanceTimersByTime()` (the pattern already in `recommendations.service.spec.ts`).
- Tests both legs before the PR: `php bin/phpunit` (SQLite) **and** `docker compose exec php vendor/bin/phpunit` (MySQL). Backend mutation gate: `composer infection:diff`. Frontend: `npm run check`.
- Commit small, one commit per green step, prefix `feat(#336):` / `refactor(#336):` / `fix(#336):`.

## File Structure

| File | Responsibility |
|---|---|
| `backend/src/Service/Recommendation/RecommendationRunReport.php` (modify) | Carries a timeless `?\DateTimeImmutable $startedAt`; `fromRun` fills it from the run. |
| `backend/src/Http/RecommendationRunStatusJson.php` (modify) | Computes `elapsedSeconds` from `startedAt` + injected clock; the only clock consumer. |
| `backend/src/Controller/Api/RecommendationRunController.php` (modify) | Injects `ClockInterface`, passes it to the mapper at all 6 call sites. |
| `frontend/src/app/reader/models.ts` (modify) | Wire type gains `elapsedSeconds: number \| null`. |
| `frontend/src/app/reader/eta-format.ts` (create) | Pure `formatEta(seconds)` → Transloco key + `{count}`. |
| `frontend/src/app/reader/recommendations.service.ts` (modify) | Timing model, ticker, `progress()`/`etaSeconds()`/`etaState()`/`rateLimited`. |
| `frontend/src/app/reader/for-you-progress/for-you-progress.component.*` (create) | Composes the dumb hairline + ETA/status label; the single render site. |
| `frontend/src/app/reader/reader-shell.component.html` (modify) | Both `<app-progress-hairline>` for-you blocks become `<app-for-you-progress>`. |
| `frontend/public/i18n/{en,de}.json` (modify) | The four new label strings. |

---

### Task 0: Branch

- [ ] **Step 1:** `git status`. You must be on a clean tree; you must **not** be on `feature/334-multiple-ai-configs`. If another session left edits, stop and ask.
- [ ] **Step 2:** Create the branch and commit this plan.

```bash
git checkout develop && git pull
git checkout -b feature/336-recommendation-run-eta develop
git add docs/superpowers/plans/2026-08-09-recommendation-run-eta.md
git commit -m "docs(#336): add the recommendation-run ETA implementation plan"
```

---

### Task 1: Backend — `startedAt` on the report, `elapsedSeconds` at the wire boundary

**Files:**
- Modify: `backend/src/Service/Recommendation/RecommendationRunReport.php`
- Modify: `backend/src/Http/RecommendationRunStatusJson.php`
- Modify: `backend/src/Controller/Api/RecommendationRunController.php`
- Test: `backend/tests/Http/RecommendationRunStatusJsonTest.php` (create)
- Test: `backend/tests/Controller/Api/RecommendationRunControllerTest.php` (extend)

**Interfaces:**
- Consumes: `RecommendationRun::getCreatedAt(): \DateTimeImmutable`; `Symfony\Component\Clock\ClockInterface::now(): \DateTimeImmutable`.
- Produces:
  - `RecommendationRunReport::$startedAt: ?\DateTimeImmutable` (public readonly; `null` for `none()`/`busy()`).
  - `RecommendationRunStatusJson::report(RecommendationRunReport $report, RecommendationForYouSummary $summary, ClockInterface $clock): array` — the wire array now also holds `'elapsedSeconds' => ?int` (whole seconds since `startedAt`, clamped at `0`, `null` when there is no run).

Why the clock lives only in the mapper: `elapsedSeconds` is a render-time value ("how long as of now"), while a `RecommendationRunReport` is built at many points that do not all hold a clock. Keeping `toArray()` timeless and computing the elapsed once, where the response is assembled, avoids threading a clock through all 11 `fromRun` call sites and keeps the value on the server's own clock (the reason we send `elapsedSeconds` and not an absolute timestamp — no cross-machine subtraction, so the naive-UTC / non-UTC-worker skew hazard cannot corrupt it).

- [ ] **Step 1: Write the failing mapper test.** Create `backend/tests/Http/RecommendationRunStatusJsonTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Entity\RecommendationRun;
use App\Entity\User;
use App\Http\RecommendationRunStatusJson;
use App\Service\Recommendation\RecommendationForYouSummary;
use App\Service\Recommendation\RecommendationRunReport;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

final class RecommendationRunStatusJsonTest extends TestCase
{
    public function testElapsedSecondsIsWholeSecondsSinceStartedAt(): void
    {
        $startedAt = new \DateTimeImmutable('2026-08-09T10:00:00');
        $report = RecommendationRunReport::fromRun(new RecommendationRun($this->user(), $startedAt));
        $clock = new MockClock($startedAt->modify('+90 seconds'));

        $json = RecommendationRunStatusJson::report($report, $this->emptySummary(), $clock);

        self::assertSame(90, $json['elapsedSeconds']);
    }

    public function testElapsedSecondsClampsToZeroWhenTheClockIsBehindStartedAt(): void
    {
        $startedAt = new \DateTimeImmutable('2026-08-09T10:00:00');
        $report = RecommendationRunReport::fromRun(new RecommendationRun($this->user(), $startedAt));
        $clock = new MockClock($startedAt->modify('-5 seconds'));

        $json = RecommendationRunStatusJson::report($report, $this->emptySummary(), $clock);

        self::assertSame(0, $json['elapsedSeconds']);
    }

    public function testElapsedSecondsIsNullWhenThereIsNoRun(): void
    {
        $json = RecommendationRunStatusJson::report(
            RecommendationRunReport::none(),
            $this->emptySummary(),
            new MockClock('2026-08-09T10:00:00'),
        );

        self::assertNull($json['elapsedSeconds']);
    }

    private function user(): User
    {
        return new User('eta@example.test', new \DateTimeImmutable('2026-07-01T00:00:00Z'));
    }

    private function emptySummary(): RecommendationForYouSummary
    {
        return new RecommendationForYouSummary(0, null);
    }
}
```

- [ ] **Step 2: Run it — expect failure** (arity mismatch on `report()`, unknown `$startedAt`, missing `elapsedSeconds` key).

```bash
php bin/phpunit tests/Http/RecommendationRunStatusJsonTest.php
```

- [ ] **Step 3: Add `startedAt` to the report.** In `RecommendationRunReport.php`, add the promoted property (last, defaulted so `none()`/`busy()` need no change) and fill it in `fromRun`/`inBackground`:

```php
    private function __construct(
        public string $status,
        public ?int $batchesTotal,
        public int $batchesDone,
        public ?string $error,
        public bool $background = false,
        public int $streamedChars = 0,
        public ?\DateTimeImmutable $startedAt = null,
    ) {
    }
```

```php
    public static function fromRun(RecommendationRun $run): self
    {
        $progress = $run->progress();

        return new self(
            $run->getStatus(),
            $progress->batchesTotal,
            $progress->batchesDone,
            $run->getError(),
            streamedChars: $run->getStreamedChars(),
            startedAt: $run->getCreatedAt(),
        );
    }
```

```php
    public function inBackground(): self
    {
        return new self(
            $this->status,
            $this->batchesTotal,
            $this->batchesDone,
            $this->error,
            true,
            $this->streamedChars,
            $this->startedAt,
        );
    }
```

`toArray()` stays exactly as it is — it remains the timeless snapshot; the elapsed is added by the mapper.

- [ ] **Step 4: Compute `elapsedSeconds` in the mapper.** Rewrite `RecommendationRunStatusJson.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http;

use App\Service\Recommendation\RecommendationForYouSummary;
use App\Service\Recommendation\RecommendationRunReport;
use Symfony\Component\Clock\ClockInterface;

/**
 * The wire shape every /api/recommendations/runs* action returns: the run
 * report, the run's live `elapsedSeconds` (computed here so it stays on the
 * server's own clock — the client never subtracts timestamps across machines),
 * and the for-you summary.
 */
final class RecommendationRunStatusJson
{
    /** @return array<string, mixed> */
    public static function report(
        RecommendationRunReport $report,
        RecommendationForYouSummary $summary,
        ClockInterface $clock,
    ): array {
        return $report->toArray() + [
            'elapsedSeconds' => self::elapsedSeconds($report, $clock),
            'forYou' => [
                'itemCount' => $summary->itemCount,
                'generatedAt' => $summary->generatedAt?->format(\DateTimeInterface::ATOM),
            ],
        ];
    }

    private static function elapsedSeconds(RecommendationRunReport $report, ClockInterface $clock): ?int
    {
        if (null === $report->startedAt) {
            return null;
        }

        return max(0, $clock->now()->getTimestamp() - $report->startedAt->getTimestamp());
    }

    private function __construct()
    {
    }
}
```

- [ ] **Step 5: Thread the clock through the controller.** In `RecommendationRunController.php` add the dependency and pass it at every `RecommendationRunStatusJson::report(...)` call (lines ~70, 92, 110, 118, 135, 151):

```php
use Symfony\Component\Clock\ClockInterface;
```

```php
    public function __construct(
        private RecommendationRunStarter $starter,
        private RecommendationPollDriver $pollDriver,
        private RecommendationRunPurger $purger,
        private RecommendationRunCanceller $canceller,
        private RecommendationForYouSummaryProvider $forYouSummaries,
        private RateLimitGuard $rateLimitGuard,
        private RateLimiterFactoryInterface $aiRecommendationsLimiter,
        private RateLimiterFactoryInterface $aiRecommendationStartsLimiter,
        private ClockInterface $clock,
    ) {
    }
```

Each call becomes, for example:

```php
return new JsonResponse(
    RecommendationRunStatusJson::report($report, $this->forYouSummaries->forUser($user), $this->clock),
);
```

Apply the third argument `$this->clock` to all six calls — including the `none()` one at ~line 151.

- [ ] **Step 6: Run the mapper test — expect PASS.**

```bash
php bin/phpunit tests/Http/RecommendationRunStatusJsonTest.php
```

- [ ] **Step 7: Extend the controller functional test.** In `tests/Controller/Api/RecommendationRunControllerTest.php`, in the existing `current` test that drives a run to `completed` (around line 288–294), after fetching `/current`, assert the live field is present and sane; and in the "no run" test (around line 301–306) assert it is null:

```php
// after: $current = $this->payload($client->getResponse());
self::assertArrayHasKey('elapsedSeconds', $current);
self::assertIsInt($current['elapsedSeconds']);
self::assertGreaterThanOrEqual(0, $current['elapsedSeconds']);
```

```php
// in the run-current-none test, on the decoded payload:
self::assertNull($payload['elapsedSeconds']);
```

- [ ] **Step 8: Run the controller test — expect PASS.**

```bash
php bin/phpunit tests/Controller/Api/RecommendationRunControllerTest.php
```

- [ ] **Step 9: Quality gates.** `bin/console cache:warmup` then `composer check` and `composer md` (both touched `src` files must be clean); PhpStorm inspections on the three changed PHP files.
- [ ] **Step 10: Mutation.** `composer infection:diff` — the `max(0, …)` clamp and the timestamp subtraction are the mutants; Step 1's `+90` and `-5` tests kill them. Add a killing test for any escapee; never lower the gate.
- [ ] **Step 11: Commit.**

```bash
git add backend/src/Service/Recommendation/RecommendationRunReport.php backend/src/Http/RecommendationRunStatusJson.php backend/src/Controller/Api/RecommendationRunController.php backend/tests/Http/RecommendationRunStatusJsonTest.php backend/tests/Controller/Api/RecommendationRunControllerTest.php
git commit -m "feat(#336): surface server-computed elapsedSeconds on the run report"
```

---

### Task 2: Frontend wire type carries `elapsedSeconds`

**Files:**
- Modify: `frontend/src/app/reader/models.ts:217-232`
- Modify: `frontend/src/app/reader/recommendations.service.spec.ts` (the shared `report()` fixture helper)

**Interfaces:**
- Produces: `RecommendationRunReport.elapsedSeconds: number | null`.

- [ ] **Step 1: Add the field** to the interface, next to `streamedChars`:

```ts
  /** Whole seconds the run has been going, computed on the server's clock;
   *  null when there is no run. The client keeps it live between polls with a
   *  local monotonic delta rather than re-subtracting server time. */
  elapsedSeconds: number | null;
```

- [ ] **Step 2: Update the spec fixture** so every existing test keeps compiling and its behaviour is unchanged (a null elapsed means no ETA and no creep — see Task 5). In `recommendations.service.spec.ts`, add `elapsedSeconds: null` to the `report()` helper defaults:

```ts
const report = (over: Partial<RecommendationRunReport>): RecommendationRunReport => ({
  status: 'pending',
  batchesTotal: null,
  batchesDone: 0,
  error: null,
  background: false,
  streamedChars: 0,
  elapsedSeconds: null,
  forYou: { itemCount: 0, generatedAt: null },
  ...over,
});
```

- [ ] **Step 3: Compile check.** `npm run check` — TypeScript flags any other literal `RecommendationRunReport` built in tests; add `elapsedSeconds: null` there too. Existing assertions (e.g. `progress()` `toBeCloseTo(1/3)`) stay green because a null elapsed yields the plain `batchesDone/batchesTotal` value.
- [ ] **Step 4: Commit.**

```bash
git add frontend/src/app/reader/models.ts frontend/src/app/reader/recommendations.service.spec.ts
git commit -m "feat(#336): carry elapsedSeconds on the frontend run report type"
```

---

### Task 3: Pure ETA formatter

**Files:**
- Create: `frontend/src/app/reader/eta-format.ts`
- Test: `frontend/src/app/reader/eta-format.spec.ts`

**Interfaces:**
- Produces: `formatEta(seconds: number): { key: string; params: { count: number } }` — ceil-rounded, floored at 1, seconds under a minute else whole minutes. Consumed by `ForYouProgressComponent` (Task 6), which passes the result to `TranslocoService.translate`.

- [ ] **Step 1: Write the failing test.** Create `eta-format.spec.ts`:

```ts
import { formatEta } from './eta-format';

describe('formatEta', () => {
  it('renders sub-minute values in seconds', () => {
    expect(formatEta(30)).toEqual({ key: 'reader.eta.seconds', params: { count: 30 } });
    expect(formatEta(59)).toEqual({ key: 'reader.eta.seconds', params: { count: 59 } });
  });

  it('renders a full minute and above in whole minutes, rounding up', () => {
    expect(formatEta(60)).toEqual({ key: 'reader.eta.minutes', params: { count: 1 } });
    expect(formatEta(61)).toEqual({ key: 'reader.eta.minutes', params: { count: 2 } });
    expect(formatEta(120)).toEqual({ key: 'reader.eta.minutes', params: { count: 2 } });
    expect(formatEta(121)).toEqual({ key: 'reader.eta.minutes', params: { count: 3 } });
  });

  it('never shows zero or a fraction', () => {
    expect(formatEta(0)).toEqual({ key: 'reader.eta.seconds', params: { count: 1 } });
    expect(formatEta(0.4)).toEqual({ key: 'reader.eta.seconds', params: { count: 1 } });
  });
});
```

- [ ] **Step 2: Run it — expect failure** (module not found).

```bash
npx jest src/app/reader/eta-format.spec.ts
```

- [ ] **Step 3: Implement.** Create `eta-format.ts`:

```ts
export interface EtaLabel {
  readonly key: string;
  readonly params: { count: number };
}

/** Turns a remaining-seconds estimate into a coarse, honestly-approximate
 *  Transloco key + count. Ceil-rounded and floored at 1 so it never promises
 *  sooner than reality and never reads "~0". No DatePipe — that always renders
 *  en-US here (no LOCALE_ID + runtime Transloco switching). */
export function formatEta(seconds: number): EtaLabel {
  const safeSeconds = Math.max(1, Math.ceil(seconds));
  if (safeSeconds < 60) {
    return { key: 'reader.eta.seconds', params: { count: safeSeconds } };
  }
  return { key: 'reader.eta.minutes', params: { count: Math.ceil(safeSeconds / 60) } };
}
```

- [ ] **Step 4: Run it — expect PASS.**

```bash
npx jest src/app/reader/eta-format.spec.ts
```

- [ ] **Step 5: Commit.**

```bash
git add frontend/src/app/reader/eta-format.ts frontend/src/app/reader/eta-format.spec.ts
git commit -m "feat(#336): add the coarse ETA label formatter"
```

---

### Task 4: Service — injectable clock, blended average, batch anchor, rate-limit signal

This task adds the *state* the ticker and label will consume, wired through one `applyReport` funnel, but not yet the interpolation or the ticker. Keep every piece behind a signal.

**Files:**
- Modify: `frontend/src/app/reader/recommendations.service.ts`
- Test: `frontend/src/app/reader/recommendations.service.spec.ts`

**Interfaces:**
- Produces (for Task 5):
  - `MONOTONIC_NOW: InjectionToken<() => number>` (exported from the service file) — default `() => performance.now()`.
  - `readonly rateLimited: Signal<boolean>` — true while the poll loop is in 429 backoff.
  - `private avgCompletedSeconds: Signal<number | null>` — blended average per completed batch; `null` until batch 1 completes.
  - `private currentBatchStart: Signal<number | null>` — monotonic ms when the in-flight batch began, from the client's point of view.
  - `private applyReport(report): void` — the single funnel that stamps timing state and stores the report; replaces every `this.report.set(...)`.

- [ ] **Step 1: Write the failing tests.** Add to `recommendations.service.spec.ts` a block that provides a controllable clock. Put a mutable `let nowMs` in scope and register the token override in `TestBed`:

```ts
import { MONOTONIC_NOW } from './recommendations.service';

// ...inside describe, add to the module providers:
//   { provide: MONOTONIC_NOW, useValue: () => nowMs },
// and declare `let nowMs = 0;` reset to 0 in beforeEach.

it('blends the average from elapsedSeconds and anchors each new batch', () => {
  nowMs = 1000;
  svc.start();
  ctrl.expectOne('https://api.test/api/recommendations/runs').flush(report({ status: 'pending' }));

  nowMs = 5000;
  ctrl
    .expectOne('https://api.test/api/recommendations/runs/tick')
    .flush(report({ status: 'running', batchesTotal: 4, batchesDone: 2, elapsedSeconds: 40 }));

  // 40s over 2 done batches = 20s/batch; the current batch is anchored at now.
  expect(svc.averageBatchSecondsForTest()).toBe(20);
  expect(svc.currentBatchStartForTest()).toBe(5000);
});

it('sets and clears the rate-limited signal around a 429 backoff', fakeAsync(() => {
  jest.useFakeTimers();
  svc.start();
  ctrl.expectOne('https://api.test/api/recommendations/runs').flush(report({ status: 'pending' }));
  ctrl
    .expectOne('https://api.test/api/recommendations/runs/tick')
    .flush(report({ status: 'running', batchesTotal: 4, batchesDone: 1, elapsedSeconds: 20 }));

  ctrl.expectOne('https://api.test/api/recommendations/runs/tick').error(
    new ProgressEvent('error'),
    { status: 429, statusText: 'Too Many Requests' },
  );
  expect(svc.rateLimited()).toBe(true);

  jest.advanceTimersByTime(15000); // RATE_LIMIT_POLL_MS
  ctrl
    .expectOne('https://api.test/api/recommendations/runs/tick')
    .flush(report({ status: 'running', batchesTotal: 4, batchesDone: 2, elapsedSeconds: 40 }));
  expect(svc.rateLimited()).toBe(false);

  ctrl
    .expectOne('https://api.test/api/recommendations/runs/tick')
    .flush(report({ status: 'completed', batchesTotal: 4, batchesDone: 4, elapsedSeconds: 80 }));
  jest.useRealTimers();
}));
```

> The two `...ForTest()` accessors exist only so this task is testable before the ticker lands; they wrap the private signals. If you prefer not to expose them, assert `svc.progress()`/`svc.etaSeconds()` after Task 5 instead and fold these assertions into Task 5's tests. Keep them out of production call paths either way.

- [ ] **Step 2: Run — expect failure** (unknown token/signals).
- [ ] **Step 3: Implement the state.** In `recommendations.service.ts`:

Add imports and the token near the top:

```ts
import { DestroyRef, InjectionToken, Injectable, computed, inject, signal } from '@angular/core';
```

```ts
/** Monotonic wall-clock in ms. Injectable so tests drive time deterministically
 *  instead of leaning on a real clock. */
export const MONOTONIC_NOW = new InjectionToken<() => number>('MONOTONIC_NOW', {
  providedIn: 'root',
  factory: () => () => performance.now(),
});
```

Inside the class, add the fields:

```ts
  private readonly now = inject(MONOTONIC_NOW);

  /** Monotonic ms when the in-flight batch began, from our point of view: set
   *  whenever `batchesDone` changes (a completion) and on the first report. */
  private readonly currentBatchStart = signal<number | null>(null);

  /** Blended seconds-per-completed-batch (`elapsedSeconds / batchesDone`);
   *  null until batch 1 completes, which is the honest-blank window. */
  private readonly avgCompletedSeconds = signal<number | null>(null);

  /** True while the poll loop is waiting out the 429 limiter. Freezes the bar
   *  and swaps the ETA number for a wait label rather than letting the estimate
   *  balloon while nothing is actually progressing. */
  readonly rateLimited = signal(false);
```

Add the funnel and route every existing `this.report.set(...)` through it. There are three: in `onReport` (line ~198), `refreshStatus` (line ~149), and `resume` (line ~163):

```ts
  /** The single place a fresh report lands: it re-blends the average and
   *  re-anchors the current batch whenever `batchesDone` moves, clears the
   *  rate-limited flag on any live report, then stores the report. */
  private applyReport(next: RecommendationRunReport): void {
    const previousDone = this.report()?.batchesDone ?? -1;
    if (next.batchesDone !== previousDone) {
      this.avgCompletedSeconds.set(
        next.batchesDone >= 1 && next.elapsedSeconds !== null
          ? next.elapsedSeconds / next.batchesDone
          : null,
      );
      this.currentBatchStart.set(this.now());
    }
    if (next.status === 'running' || next.status === 'pending') {
      this.rateLimited.set(false);
    }
    this.report.set(next);
  }
```

Replace `this.report.set(r)` in `onReport` with `this.applyReport(r)`; replace `next: (r) => this.report.set(r)` in `refreshStatus` with `next: (r) => this.applyReport(r)`; replace `this.report.set(r)` in `resume`'s `next` with `this.applyReport(r)`.

Set the flag in `backOffWhileRateLimited` — add as its first line (after the ceiling guard is fine; before `stepLater` so the flag is up during the wait):

```ts
  private backOffWhileRateLimited(e: HttpErrorResponse, attempts: PollAttempts): void {
    if (attempts.rateLimited >= MAX_RATE_LIMIT_RETRIES) {
      this.stopWithHttpError(e);
      return;
    }
    this.rateLimited.set(true);
    this.stepLater({ ...attempts, rateLimited: attempts.rateLimited + 1 }, RATE_LIMIT_POLL_MS);
  }
```

Clear it in `finish()` so a stopped/failed run never sticks in the wait label:

```ts
  private finish(): void {
    this.running.set(false);
    this.stopping.set(false);
    this.rateLimited.set(false);
  }
```

If you kept the `...ForTest()` accessors, add:

```ts
  /** @internal test seam — see recommendations.service.spec.ts */
  averageBatchSecondsForTest(): number | null {
    return this.avgCompletedSeconds();
  }

  /** @internal test seam */
  currentBatchStartForTest(): number | null {
    return this.currentBatchStart();
  }
```

- [ ] **Step 4: Run — expect PASS.** `npx jest src/app/reader/recommendations.service.spec.ts`
- [ ] **Step 5: `npm run check`**, then commit.

```bash
git add frontend/src/app/reader/recommendations.service.ts frontend/src/app/reader/recommendations.service.spec.ts
git commit -m "feat(#336): blend batch timing and surface the rate-limit state"
```

---

### Task 5: Service — interpolated `progress`, `etaSeconds`, `etaState`, and the ticker

**Files:**
- Modify: `frontend/src/app/reader/recommendations.service.ts`
- Test: `frontend/src/app/reader/recommendations.service.spec.ts`

**Interfaces:**
- Consumes: `avgCompletedSeconds`, `currentBatchStart`, `rateLimited`, `report`, `now`, `running` (Task 4).
- Produces:
  - `readonly progress: Signal<number>` — **rewrites** the existing computed. `0..1`, interpolated toward the next milestone, capped at `CREEP_CAP` of the gap, frozen while `rateLimited`.
  - `readonly etaSeconds: Signal<number | null>` — ceil seconds remaining, or `null` when unknown / not running.
  - `readonly etaState: Signal<'hidden' | 'starting' | 'waiting' | 'eta'>` — drives the Task 6 label.

**Design of the interpolation (why it is honest and self-correcting):** at each observed batch completion `applyReport` snapshots `avg = elapsed/done` and anchors `currentBatchStart = now`. Between polls the fraction into the current batch is `(now − start)/avg`, clamped to `[0,1]`; the bar fills from `done/total` toward `(done+1)/total` by that fraction × `CREEP_CAP` (< 1), so it never reaches the next tick until a real completion snaps it there. Because `avg` is re-blended on every completion and `start` re-anchored, a slow or fast batch corrects at the next completion. It never overshoots (forward-only snaps). A tab that opens mid-run gets `avg` from the very first `elapsedSeconds` and anchors the current batch to "now" (a slight under-fill that the next completion corrects) — the reason `elapsedSeconds` is server-supplied rather than observed only client-side.

- [ ] **Step 1: Write the failing tests.** Add to `recommendations.service.spec.ts`:

```ts
it('creeps toward the next milestone but never reaches it between completions', fakeAsync(() => {
  jest.useFakeTimers();
  nowMs = 0;
  svc.start();
  ctrl.expectOne('https://api.test/api/recommendations/runs').flush(report({ status: 'pending' }));
  nowMs = 20000;
  ctrl
    .expectOne('https://api.test/api/recommendations/runs/tick')
    .flush(report({ status: 'running', batchesTotal: 4, batchesDone: 1, elapsedSeconds: 20 }));

  // On the completion boundary the bar is exactly at the milestone.
  expect(svc.progress()).toBeCloseTo(0.25);

  // Half an average batch later (10s of 20s) it is a fraction of the way to 0.5,
  // capped short of it.
  nowMs = 30000;
  jest.advanceTimersByTime(200); // TICK_MS -> recompute
  const p = svc.progress();
  expect(p).toBeGreaterThan(0.25);
  expect(p).toBeLessThan(0.5); // never reaches the next milestone

  // A whole average later it sits at the cap, still short of 0.5.
  nowMs = 45000;
  jest.advanceTimersByTime(200);
  expect(svc.progress()).toBeCloseTo(0.25 + 0.25 * 0.92);
  expect(svc.progress()).toBeLessThan(0.5);

  discardPeriodicTasks();
  jest.useRealTimers();
}));

it('snaps to the true milestone when a batch completes', fakeAsync(() => {
  jest.useFakeTimers();
  nowMs = 0;
  svc.start();
  ctrl.expectOne('https://api.test/api/recommendations/runs').flush(report({ status: 'pending' }));
  nowMs = 20000;
  ctrl
    .expectOne('https://api.test/api/recommendations/runs/tick')
    .flush(report({ status: 'running', batchesTotal: 4, batchesDone: 1, elapsedSeconds: 20 }));
  nowMs = 40000;
  ctrl
    .expectOne('https://api.test/api/recommendations/runs/tick')
    .flush(report({ status: 'running', batchesTotal: 4, batchesDone: 2, elapsedSeconds: 40 }));
  expect(svc.progress()).toBeCloseTo(0.5);
  discardPeriodicTasks();
  jest.useRealTimers();
}));

it('computes an ETA of average x remaining, and hides it before batch 1', fakeAsync(() => {
  jest.useFakeTimers();
  nowMs = 0;
  svc.start();
  ctrl.expectOne('https://api.test/api/recommendations/runs').flush(report({ status: 'pending' }));

  // Before any completion: no average -> starting, no creep.
  expect(svc.etaSeconds()).toBeNull();
  expect(svc.etaState()).toBe('starting');
  nowMs = 12345;
  jest.advanceTimersByTime(200);
  expect(svc.progress()).toBe(0);

  nowMs = 20000;
  ctrl
    .expectOne('https://api.test/api/recommendations/runs/tick')
    .flush(report({ status: 'running', batchesTotal: 4, batchesDone: 1, elapsedSeconds: 20 }));
  // avg 20s, 3 batches remain (2,3,dedup). At the boundary, into-current = 0.
  expect(svc.etaSeconds()).toBe(60);
  expect(svc.etaState()).toBe('eta');

  discardPeriodicTasks();
  jest.useRealTimers();
}));

it('freezes the bar and reports the waiting state during a 429 backoff', fakeAsync(() => {
  jest.useFakeTimers();
  nowMs = 0;
  svc.start();
  ctrl.expectOne('https://api.test/api/recommendations/runs').flush(report({ status: 'pending' }));
  nowMs = 20000;
  ctrl
    .expectOne('https://api.test/api/recommendations/runs/tick')
    .flush(report({ status: 'running', batchesTotal: 4, batchesDone: 1, elapsedSeconds: 20 }));
  nowMs = 30000;
  jest.advanceTimersByTime(200);
  const beforeLimit = svc.progress();

  ctrl.expectOne('https://api.test/api/recommendations/runs/tick').error(
    new ProgressEvent('error'),
    { status: 429, statusText: 'Too Many Requests' },
  );
  expect(svc.etaState()).toBe('waiting');

  nowMs = 90000; // time marches on, but the bar must not move
  jest.advanceTimersByTime(200);
  expect(svc.progress()).toBeCloseTo(beforeLimit);

  jest.advanceTimersByTime(15000);
  ctrl
    .expectOne('https://api.test/api/recommendations/runs/tick')
    .flush(report({ status: 'completed', batchesTotal: 4, batchesDone: 4, elapsedSeconds: 100 }));
  discardPeriodicTasks();
  jest.useRealTimers();
}));

it('stops the ticker when the run ends', fakeAsync(() => {
  jest.useFakeTimers();
  svc.start();
  ctrl.expectOne('https://api.test/api/recommendations/runs').flush(report({ status: 'pending' }));
  ctrl
    .expectOne('https://api.test/api/recommendations/runs/tick')
    .flush(report({ status: 'completed', batchesTotal: 3, batchesDone: 3, elapsedSeconds: 30 }));
  expect(svc.running()).toBe(false);
  // No periodic task should remain; if the ticker leaked, fakeAsync would throw here.
  jest.useRealTimers();
}));
```

Add `discardPeriodicTasks` to the testing import: `import { TestBed, fakeAsync, tick, discardPeriodicTasks } from '@angular/core/testing';`.

- [ ] **Step 2: Run — expect failure.**
- [ ] **Step 3: Implement.** Add constants near the existing ones:

```ts
/** Ticker cadence for the anticipatory bar. Fine enough to read as motion,
 *  coarse enough to be cheap; the shared hairline's own `width` transition
 *  smooths between ticks. */
const TICK_MS = 200;
/** How far into the gap to the next milestone the creep is allowed to reach.
 *  Strictly < 1 so the bar never claims a step done before the server confirms
 *  it; the real completion snaps it the rest of the way. */
const CREEP_CAP = 0.92;
```

Add a module-level clamp helper:

```ts
const clamp01 = (value: number): number => Math.min(1, Math.max(0, value));
```

Add the freeze anchor and rewrite `progress`:

```ts
  /** The progress value captured the instant a 429 backoff began, held while
   *  `rateLimited` so the bar freezes instead of creeping to its cap during a
   *  wait. Cleared when a live report resumes progress. */
  private readonly frozenProgress = signal<number | null>(null);

  /** Ticker handle; the bar re-reads the clock on every bump. */
  private tickerId: ReturnType<typeof setInterval> | null = null;

  /** Bumped by the ticker so the interpolated reads recompute between polls. */
  private readonly frame = signal(0);

  readonly progress = computed(() => {
    this.frame(); // re-run on every ticker bump
    const current = this.report();
    if (!current || !current.batchesTotal) return 0;

    const base = clamp01(current.batchesDone / current.batchesTotal);
    if (this.rateLimited()) return this.frozenProgress() ?? base;

    const average = this.avgCompletedSeconds();
    const batchStart = this.currentBatchStart();
    if (average === null || batchStart === null) return base; // honest blank

    const next = clamp01((current.batchesDone + 1) / current.batchesTotal);
    const secondsIntoBatch = (this.now() - batchStart) / 1000;
    const fractionIntoBatch = clamp01(secondsIntoBatch / average);
    return clamp01(base + fractionIntoBatch * (next - base) * CREEP_CAP);
  });

  readonly etaSeconds = computed<number | null>(() => {
    this.frame();
    const current = this.report();
    if (!current || !current.batchesTotal) return null;
    if (current.status !== 'running' && current.status !== 'pending') return null;

    const average = this.avgCompletedSeconds();
    const batchStart = this.currentBatchStart();
    if (average === null || batchStart === null) return null;

    const batchesRemaining = current.batchesTotal - current.batchesDone; // includes the in-flight one
    const secondsIntoBatch = (this.now() - batchStart) / 1000;
    return Math.max(0, Math.ceil(average * batchesRemaining - secondsIntoBatch));
  });

  readonly etaState = computed<'hidden' | 'starting' | 'waiting' | 'eta'>(() => {
    if (!this.running()) return 'hidden';
    if (this.rateLimited()) return 'waiting';
    if (this.etaSeconds() === null) return 'starting';
    return 'eta';
  });
```

Capture the freeze value in `backOffWhileRateLimited` — set it **before** raising `rateLimited` so `progress()` still computes the live value at capture time:

```ts
    this.frozenProgress.set(this.progress());
    this.rateLimited.set(true);
    this.stepLater({ ...attempts, rateLimited: attempts.rateLimited + 1 }, RATE_LIMIT_POLL_MS);
```

Clear the freeze in `applyReport` alongside clearing `rateLimited`:

```ts
    if (next.status === 'running' || next.status === 'pending') {
      this.rateLimited.set(false);
      this.frozenProgress.set(null);
    }
```

Start/stop the ticker. Start it wherever a run begins (`beginRun` after `running.set(true)`, and `resume` after `running.set(true)`); stop it in `finish()`:

```ts
  private startTicker(): void {
    if (this.tickerId !== null) return;
    this.tickerId = setInterval(() => this.frame.update((n) => n + 1), TICK_MS);
  }

  private stopTicker(): void {
    if (this.tickerId === null) return;
    clearInterval(this.tickerId);
    this.tickerId = null;
  }
```

Guarantee teardown on service destruction — add to the constructor (create one if the service has none):

```ts
  constructor() {
    inject(DestroyRef).onDestroy(() => this.stopTicker());
  }
```

In `beginRun`, after `this.running.set(true)` add `this.startTicker();`. In `resume`, after `this.running.set(true)` add `this.startTicker();`. In `finish()`, add `this.stopTicker();` (keep the `rateLimited`/`frozenProgress` clears from Task 4/here). Remove the two `...ForTest()` accessors if you added them in Task 4 and folded their assertions here; otherwise leave them.

- [ ] **Step 4: Run — expect PASS.** Run the full service spec; confirm the pre-existing tests (including `progress() toBeCloseTo(1/3)`) stay green — those fixtures carry `elapsedSeconds: null`, so `progress` returns the plain `batchesDone/batchesTotal`.

```bash
npx jest src/app/reader/recommendations.service.spec.ts
```

- [ ] **Step 5: Audit for timer leaks.** Grep the spec for tests that end with `svc.running() === true` and are **not** `fakeAsync` with `discardPeriodicTasks()` / a terminal report. Any such test now leaks the ticker interval — convert it to drive the run to a terminal state or add `discardPeriodicTasks()`. Run the whole `frontend` Jest suite and confirm no "periodic task" warnings.
- [ ] **Step 6: `npm run check`**, then commit.

```bash
git add frontend/src/app/reader/recommendations.service.ts frontend/src/app/reader/recommendations.service.spec.ts
git commit -m "feat(#336): interpolate the run bar and derive an ETA from batch timing"
```

---

### Task 6: `ForYouProgressComponent` + reader-shell swap + i18n

**Files:**
- Create: `frontend/src/app/reader/for-you-progress/for-you-progress.component.ts`
- Create: `frontend/src/app/reader/for-you-progress/for-you-progress.component.html`
- Create: `frontend/src/app/reader/for-you-progress/for-you-progress.component.scss`
- Test: `frontend/src/app/reader/for-you-progress/for-you-progress.component.spec.ts`
- Modify: `frontend/src/app/reader/reader-shell.component.html:87-88` and `:132-133`
- Modify: `frontend/public/i18n/en.json`, `frontend/public/i18n/de.json`

**Interfaces:**
- Consumes: `RecommendationsService.running()`, `.progress()`, `.etaSeconds()`, `.etaState()`; `formatEta` (Task 3); `TranslocoService.translate`.
- Produces: `<app-for-you-progress />` — no inputs; reads the root service directly. Replaces both `<app-progress-hairline>` for-you render sites.

- [ ] **Step 1: Add the i18n keys.** In `frontend/public/i18n/en.json`, under the `reader` object, add:

```json
"eta": {
  "starting": "Starting…",
  "rateLimited": "Waiting — rate limited",
  "seconds": "~{{count}} s left",
  "minutes": "~{{count}} min left"
}
```

In `frontend/public/i18n/de.json`, under `reader`:

```json
"eta": {
  "starting": "Wird gestartet…",
  "rateLimited": "Wartet — Ratenlimit",
  "seconds": "~{{count}} Sek. übrig",
  "minutes": "~{{count}} Min. übrig"
}
```

(Place the object next to the existing `forYou*` keys; match the file's key ordering and indentation.)

- [ ] **Step 2: Write the failing component test.** Create `for-you-progress.component.spec.ts`:

```ts
import { signal } from '@angular/core';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { TranslocoService } from '@jsverse/transloco';
import { RecommendationsService } from '../recommendations.service';
import { ForYouProgressComponent } from './for-you-progress.component';

describe('ForYouProgressComponent', () => {
  const running = signal(true);
  const progress = signal(0.25);
  const etaSeconds = signal<number | null>(90);
  const etaState = signal<'hidden' | 'starting' | 'waiting' | 'eta'>('eta');

  const build = (): ComponentFixture<ForYouProgressComponent> => {
    TestBed.configureTestingModule({
      imports: [ForYouProgressComponent],
      providers: [
        { provide: RecommendationsService, useValue: { running, progress, etaSeconds, etaState } },
        {
          provide: TranslocoService,
          useValue: { translate: (key: string, params?: Record<string, unknown>) => `${key}:${params?.['count'] ?? ''}` },
        },
      ],
    });
    const fixture = TestBed.createComponent(ForYouProgressComponent);
    fixture.detectChanges();
    return fixture;
  };

  it('shows the ETA label in the eta state', () => {
    etaState.set('eta');
    etaSeconds.set(90);
    const text = build().nativeElement.textContent as string;
    expect(text).toContain('reader.eta.minutes:2'); // 90s -> ceil(90/60)=2
  });

  it('shows the starting label with no number before batch 1', () => {
    etaState.set('starting');
    etaSeconds.set(null);
    const text = build().nativeElement.textContent as string;
    expect(text).toContain('reader.eta.starting');
    expect(text).not.toContain('reader.eta.minutes');
  });

  it('shows the rate-limited label while waiting', () => {
    etaState.set('waiting');
    const text = build().nativeElement.textContent as string;
    expect(text).toContain('reader.eta.rateLimited');
  });

  it('renders no label when hidden', () => {
    etaState.set('hidden');
    const text = build().nativeElement.textContent as string;
    expect(text.trim()).toBe('');
  });
});
```

- [ ] **Step 3: Run — expect failure** (component missing).
- [ ] **Step 4: Implement the component.** `for-you-progress.component.ts`:

```ts
import { ChangeDetectionStrategy, Component, computed, inject } from '@angular/core';
import { TranslocoService } from '@jsverse/transloco';
import { ProgressHairlineComponent } from '../shared/progress-hairline/progress-hairline.component';
import { formatEta } from './eta-format';
import { RecommendationsService } from './recommendations.service';

/**
 * The For-You run's in-reader progress surface: the shared determinate hairline
 * plus a live ETA/status label. It reads the run service directly and is the
 * single definition behind both render sites in the reader shell, so the two
 * cannot drift. The shared hairline stays dumb — all interpolation lives in the
 * service.
 */
@Component({
  selector: 'app-for-you-progress',
  standalone: true,
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [ProgressHairlineComponent],
  templateUrl: './for-you-progress.component.html',
  styleUrl: './for-you-progress.component.scss',
})
export class ForYouProgressComponent {
  private readonly i18n = inject(TranslocoService);
  protected readonly recs = inject(RecommendationsService);

  /** The label text for the current state, or null when nothing should show. */
  protected readonly label = computed<string | null>(() => {
    switch (this.recs.etaState()) {
      case 'starting':
        return this.i18n.translate('reader.eta.starting');
      case 'waiting':
        return this.i18n.translate('reader.eta.rateLimited');
      case 'eta': {
        const seconds = this.recs.etaSeconds();
        if (seconds === null) return null;
        const { key, params } = formatEta(seconds);
        return this.i18n.translate(key, params);
      }
      case 'hidden':
        return null;
    }
  });
}
```

`for-you-progress.component.html`:

```html
<app-progress-hairline [active]="recs.running()" [value]="recs.progress()" />
@if (label(); as text) {
  <p class="eta" aria-live="polite">{{ text }}</p>
}
```

`for-you-progress.component.scss` (tokens only — no hex, no raw px):

```scss
.eta {
  margin: 0;
  padding: var(--space-1) var(--space-2);
  font-size: var(--font-size-caption);
  color: var(--text-muted);
}
```

> Confirm the exact token names against `docs/design-language.md` / `src/app/theme/` before writing — use the caption font-size token and the muted text colour token this project actually defines. If a caption size token does not exist, use the smallest defined step; do not introduce a raw value (Stylelint blocks hex and raw px outside `theme/`).

- [ ] **Step 5: Run the component test — expect PASS.** `npx jest src/app/reader/for-you-progress/for-you-progress.component.spec.ts`
- [ ] **Step 6: Swap the render sites.** In `reader-shell.component.html`, replace **both** occurrences of:

```html
@if (selection().kind === 'for-you') {
  <app-progress-hairline [active]="recs.running()" [value]="recs.progress()" />
}
```

with:

```html
@if (selection().kind === 'for-you') {
  <app-for-you-progress />
}
```

Then update `reader-shell.component.ts` imports: add `ForYouProgressComponent` to the component's `imports` array. Only remove `ProgressHairlineComponent` from that array if the refresh bar at line ~15 no longer uses it — it does (feed refresh), so **keep** `ProgressHairlineComponent` imported.

- [ ] **Step 7: Verify the shell still builds and its spec passes.** `npx jest src/app/reader/reader-shell.component.spec.ts` — if that spec asserted on `app-progress-hairline` under the for-you branch, update it to `app-for-you-progress`.
- [ ] **Step 8: `npm run check`** — Jest, ESLint, Prettier, and **Stylelint** (hex/px guard) all green.
- [ ] **Step 9: Commit.**

```bash
git add frontend/src/app/reader/for-you-progress frontend/src/app/reader/reader-shell.component.html frontend/src/app/reader/reader-shell.component.ts frontend/public/i18n/en.json frontend/public/i18n/de.json
git commit -m "feat(#336): show the ETA and anticipatory bar in the reader"
```

---

### Task 7: Full verification and PR

- [ ] **Step 1: Backend, both legs.** From `backend/`: `bin/console cache:warmup`, `composer check`, `composer md`, `php bin/phpunit`. Then MySQL: `docker compose exec php vendor/bin/phpunit`.
- [ ] **Step 2: Backend mutation.** `composer infection:diff` at or above the configured `minMsi`. Kill any escapee on the clamp/subtraction; never lower the gate.
- [ ] **Step 3: Frontend.** From `frontend/`: `npm run check`.
- [ ] **Step 4: Real-run smoke (the actual deliverable — gates green is not enough, per the project's own #320 lesson).** Bring up the Docker stack, open the reader, start a For-You run, and watch:
  - batch 1 shows "Starting…" with a still bar;
  - from batch 2 the bar creeps between completions and rests just short of each notch, snapping forward on completion;
  - the ETA counts down and reads "~N min left" / "~N s left";
  - if a rate-limit window is reachable, the label flips to "Waiting — rate limited" and the bar freezes, then resumes.
  Scan `backend/var/log/dev.log` for deprecations/errors afterward.
- [ ] **Step 5: Open the PR** into `develop` with body `Closes #336`. After merge, verify #336 auto-closed.

```bash
git push -u origin feature/336-recommendation-run-eta
gh pr create --base develop --title "For-You run: ETA + anticipatory progress bar (#336)" --body "Closes #336"
```

---

## Risks & notes

- **Blended average is coarse by design** (decision Q2, option B over C). It cannot tell that recent batches slowed; the honest upgrade path — per-batch durations surfaced from `RecommendationRunLog` (currently debug-gated) — is a separate ticket, not this one.
- **`elapsedSeconds` includes the in-flight batch**, so `average = elapsed/done` slightly over-counts and the ETA leans **long, never short** — matching the ceil-rounding and cap-short bar. This is deliberate.
- **The freeze is a captured value, not a paused clock.** `frozenProgress` holds the bar during a 429 wait so it does not creep to its cap while nothing progresses (decision Q7). The ticker keeps running; `progress()` simply returns the frozen value while `rateLimited`.
- **The shared hairline stays dumb** (decision Q5). Its `width 0.3s ease-out` transition smooths the 200 ms ticker steps. If it ever reads as laggy, shorten the transition in `for-you-progress.component.scss` for this usage — never edit the shared component.
- **Background-worker runs** get `elapsedSeconds` from the server via the `current` poll, so the ETA works when the tab is only watching, not driving — the reason B beat frontend-observed timing.

## Self-review (run against the spec before handing off)

- **Spec coverage:** ETA from prior batch times → Tasks 1,4,5. Bar moves in anticipation → Task 5 interpolation. Honest blank before batch 1 → `etaState 'starting'` + `avg === null` guard (Task 5). Rate-limit freeze/label → `frozenProgress` + `etaState 'waiting'` (Tasks 4,5). Coarse ETA text, de+en, no DatePipe → Tasks 3,6. DRY single render site → Task 6. Recommendation-only scope → no refresh-bar file is touched. All covered.
- **Type consistency:** `elapsedSeconds` (backend key, wire field, model field) matches across Tasks 1,2. `avgCompletedSeconds`, `currentBatchStart`, `frozenProgress`, `frame`, `rateLimited`, `etaSeconds`, `etaState`, `MONOTONIC_NOW`, `formatEta`, `CREEP_CAP`, `TICK_MS`, `clamp01` are used with identical names/signatures in Tasks 3–6. Transloco keys `reader.eta.{starting,rateLimited,seconds,minutes}` match between the i18n files (Task 6 Step 1) and the component (Task 6 Step 4).
- **Placeholder scan:** every code step carries real code; the two `...ForTest()` seams are explicitly optional and removable; the one soft spot — exact SCSS token names — is flagged to confirm against `design-language.md` rather than guessed, because inventing a token would fail Stylelint.
