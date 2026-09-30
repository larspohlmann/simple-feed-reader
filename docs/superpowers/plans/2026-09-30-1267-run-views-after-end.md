# A Run's Views Refuse Writes Once the Run Ended (#1267) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A `RunningThrottle` or `RunningCallAttempts` view held past `complete()`, `fail()` or `cancel()` throws on every write instead of silently changing an ended run. One PR, `Closes #1267`.

**Architecture:**
- Each view holds the run it came from next to the embeddable it narrows, and every write first checks that the run is still `RunStatus::Running`; otherwise it throws `App\Entity\Exception\InvalidRunStatusException`. The four call sites (`RecommendationRunDeferral`, `RecommendationTransportFailureRecorder`, `InvalidReplyRetry`, `RecommendationWaveConcurrency`) do not change.
- `InvalidRunStatusException` (a `\LogicException`, next to `UnpersistedEntityException` in `App\Entity\Exception`) owns the one message format, `Cannot %s a recommendation run from status "%s".`. The entity's own `guardStatusOneOf()` throws it too, so the status guard and the views raise one typed error with one wording.

**Tech Stack:** PHP 8.4, Doctrine ORM 3 (embeddables, native lazy objects), PHPUnit 12 (`#[DataProvider]`), PHPStan level max, PHP_CodeSniffer PSR-12, PHPMD codesize, phptramp, Infection.

**Spec:** GitHub issue #1267 (`gh issue view 1267 --repo larspohlmann/simple-feed-reader`); CLAUDE.md "PHP code style"; `docs/superpowers/plans/2026-09-29-1169-final-readonly-injection.md`, D-10 and S-7 (why the views exist).

**Base:** `origin/develop` at `bf742411`. Branch `fix/1267-run-views-after-end`.

## Decisions

- **D-1: the view checks the run's status on each write** (the issue's first option). The views are plain `final readonly` objects built with `new`, never mapped (`backend/src/Entity/RunningThrottle.php:8` `final readonly class RunningThrottle`, no `#[ORM\…]`), so they may hold the run. Reading `getStatus()` on each write needs no new state in the entity. Invalidation (option 2) would need a field or a registry of handed-out views in a Doctrine entity, which a reload loses. A callback (option 3) changes every call (`git grep -c -E 'getRunning(Throttle|CallAttempts)\(\)' bf742411 -- backend/src/Service backend/tests`: 4 in `src/Service`, 56 in `tests`) and adds a public method to `RecommendationRun`. That entity is at PHPMD's `TooManyPublicMethods` ceiling of 10 (#1169 plan, D-10: "That leaves 10, PHPMD's ceiling").
- **D-2: the check is `RunStatus::Running`, not "not terminal".** A view is only ever handed out from `Running` (`backend/src/Entity/RecommendationRun.php:214` `$this->guardStatus(RunStatus::Running, 'record a call attempt on');`, `:276` the same for `'throttle'`), and nothing returns a run to `Pending`, so this is the strictest check with no false positive. Consequence: a view held across `fail()` and then `resume()` writes again, into the same run, which is running again. That is the aggregate's invariant ("only a running run records"), not a leak.
- **D-3: `cancel()` is covered too.** It is the third ending and goes through the same check, so the new tests run over all three endings (`complete()`, `fail()`, `cancel()`).
- **D-4: one typed exception for the whole status guard.** CLAUDE.md "Errors are exceptions, typed and namespaced". The entity's precedent for invariant errors is `App\Entity\Exception` (`backend/src/Entity/Exception/UnpersistedEntityException.php:7` `final class UnpersistedEntityException extends \LogicException`). The views need the guard's exact wording, so the message moves into the exception instead of being copied into the views. It stays a `\LogicException`, so every existing `expectException(\LogicException::class)` in `RecommendationRunTest` still holds and every message stays byte-identical. Nothing in `src` catches a `\LogicException`: `git grep -n -E 'catch \(.*LogicException' bf742411 -- backend/src` prints nothing, while its positive control `git grep -n -E 'catch \(.*Exception' bf742411 -- backend/src` prints `backend/src/Command/ImportCatalogCommand.php:66:        } catch (InvalidCatalogDocumentException $exception) {` among others.
- **D-5: the new tests live in `tests/Entity/RecommendationRunTest.php`,** with the existing view tests (`testARunningRunDefersThroughItsThrottle`, `testARunningRunNarrowsItsWaveThroughItsThrottle`, `testARunningRunRecordsAnInvalidReplyThroughItsCallAttempts`). The views are only obtained through the run and neither has a test file of its own. One test class keeps one endings provider and the run builders already there.
- **D-6: each view keeps its own two-line `guardRunning()`.** Two copies (CLAUDE.md: the third occurrence is the refactor). The entity's `guardStatusOneOf()` checks a list of statuses and reads its own field, so it is not a third copy.

## Questions for the planner

None.

## File map

| File | Change |
|---|---|
| `backend/src/Entity/Exception/InvalidRunStatusException.php` | new: the run's status-guard error and its message |
| `backend/src/Entity/RecommendationRun.php` | `guardStatusOneOf()` throws it; the two accessors pass `$this` to the views |
| `backend/src/Entity/RunningThrottle.php` | holds the run; both writes check it is running |
| `backend/src/Entity/RunningCallAttempts.php` | holds the run; both writes check it is running |
| `backend/tests/Entity/RecommendationRunTest.php` | two tests expect the typed exception; four new tests over three endings |
| `docs/superpowers/plans/2026-09-30-1267-run-views-after-end.md` | this plan |

Global rules for every task: commands run from `backend/` unless a step says otherwise. Every deletion check is restored with the Edit tool by re-applying the step's After (never `git checkout --`), and its FAIL output is quoted in the task report. Commits are `type(#1267): …`, no attribution or co-author lines.

---

### Task 0: Branch and plan

**Files:**
- Create: `docs/superpowers/plans/2026-09-30-1267-run-views-after-end.md`

- [ ] **Step 1: Check the shared checkout is clean** (another session may be mid-edit).

Run (repo root): `git status --short && git branch --show-current`
Expected: no status lines. If there are any, stop and report to the planner; do not stash.

- [ ] **Step 2: Branch off develop**

```bash
git fetch origin
git switch -c fix/1267-run-views-after-end origin/develop
git log --oneline -1
```

Expected: the tip is `bf742411` or a later develop commit. If later, re-run the greps in Task 1 Step 1 and Task 2 Step 1 before editing; they state what the edits assume.

- [ ] **Step 3: Add the plan and commit**

Copy this file to `docs/superpowers/plans/2026-09-30-1267-run-views-after-end.md`.

```bash
git add docs/superpowers/plans/2026-09-30-1267-run-views-after-end.md
git commit -m "docs(#1267): add plan"
```

---

### Task 1: The run's status guard throws a typed `InvalidRunStatusException`

**Files:**
- Create: `backend/src/Entity/Exception/InvalidRunStatusException.php`
- Modify: `backend/src/Entity/RecommendationRun.php` (imports, `guardStatusOneOf()`)
- Test: `backend/tests/Entity/RecommendationRunTest.php` (imports, `testAPendingRunHandsOutNoThrottle`, `testAPendingRunHandsOutNoCallAttempts`)

- [ ] **Step 1: Confirm the anchors**

Run (repo root):
```bash
git grep -n -E 'LogicException' -- backend/src/Entity/RecommendationRun.php backend/src/Entity/Exception/UnpersistedEntityException.php
git grep -n -E 'InvalidRunStatusException' -- backend
```
Expected, first command, exactly two lines (the first is the positive control):
```
backend/src/Entity/Exception/UnpersistedEntityException.php:7:final class UnpersistedEntityException extends \LogicException
backend/src/Entity/RecommendationRun.php:381:            throw new \LogicException(sprintf(
```
Second command: no output (the name is free). If the first command's line number moved, use the text, not the number.

- [ ] **Step 2: Write the failing test**

In `backend/tests/Entity/RecommendationRunTest.php`:

Before:
```php
namespace App\Tests\Entity;

use App\Entity\RecommendationRun;
use App\Entity\User;
```
After:
```php
namespace App\Tests\Entity;

use App\Entity\Exception\InvalidRunStatusException;
use App\Entity\RecommendationRun;
use App\Entity\User;
```

Before:
```php
    public function testAPendingRunHandsOutNoThrottle(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Cannot throttle a recommendation run from status "pending".');
```
After:
```php
    public function testAPendingRunHandsOutNoThrottle(): void
    {
        $this->expectException(InvalidRunStatusException::class);
        $this->expectExceptionMessage('Cannot throttle a recommendation run from status "pending".');
```

Before:
```php
    public function testAPendingRunHandsOutNoCallAttempts(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Cannot record a call attempt on a recommendation run from status "pending".');
```
After:
```php
    public function testAPendingRunHandsOutNoCallAttempts(): void
    {
        $this->expectException(InvalidRunStatusException::class);
        $this->expectExceptionMessage('Cannot record a call attempt on a recommendation run from status "pending".');
```

- [ ] **Step 3: Run it and watch it fail**

Run: `php bin/phpunit --filter 'testAPendingRunHandsOut' tests/Entity/RecommendationRunTest.php`
Expected: `Failures: 2`, each reading
```
Failed asserting that exception of type "LogicException" matches expected exception "App\Entity\Exception\InvalidRunStatusException".
```

- [ ] **Step 4: Add the exception**

Create `backend/src/Entity/Exception/InvalidRunStatusException.php`:
```php
<?php

declare(strict_types=1);

namespace App\Entity\Exception;

use App\Enum\RunStatus;

final class InvalidRunStatusException extends \LogicException
{
    public function __construct(string $operation, RunStatus $status)
    {
        parent::__construct(sprintf(
            'Cannot %s a recommendation run from status "%s".',
            $operation,
            $status->value,
        ));
    }
}
```

- [ ] **Step 5: Throw it from the entity's guard**

In `backend/src/Entity/RecommendationRun.php`:

Before:
```php
namespace App\Entity;

use App\Enum\RunStatus;
use App\Repository\RecommendationRunRepository;
```
After:
```php
namespace App\Entity;

use App\Entity\Exception\InvalidRunStatusException;
use App\Enum\RunStatus;
use App\Repository\RecommendationRunRepository;
```

Before:
```php
    private function guardStatusOneOf(array $allowedStatuses, string $transition): void
    {
        if (!\in_array($this->status, $allowedStatuses, true)) {
            throw new \LogicException(sprintf(
                'Cannot %s a recommendation run from status "%s".',
                $transition,
                $this->status->value,
            ));
        }
    }
```
After:
```php
    private function guardStatusOneOf(array $allowedStatuses, string $transition): void
    {
        if (!\in_array($this->status, $allowedStatuses, true)) {
            throw new InvalidRunStatusException($transition, $this->status);
        }
    }
```

- [ ] **Step 6: Run it and watch it pass**

Run: `php bin/phpunit tests/Entity/RecommendationRunTest.php`
Expected: `OK`, no failures (the other `expectException(\LogicException::class)` tests still pass: the new type is a `\LogicException`).

- [ ] **Step 7: Deletion check**

Edit `guardStatusOneOf()` in `backend/src/Entity/RecommendationRun.php` back to the Step 5 Before body (`throw new \LogicException(sprintf(` … `));`). Run: `php bin/phpunit --filter 'testAPendingRunHandsOut' tests/Entity/RecommendationRunTest.php`
Expected: `Failures: 2`, each
```
Failed asserting that exception of type "LogicException" matches expected exception "App\Entity\Exception\InvalidRunStatusException".
```
Restore by re-applying Step 5's After with the Edit tool, re-run the Step 6 command: `OK`.

- [ ] **Step 8: Grep check**

Run (repo root): the first command of Step 1 again.
Expected: exactly one line, the positive control `backend/src/Entity/Exception/UnpersistedEntityException.php:7:final class UnpersistedEntityException extends \LogicException`.

- [ ] **Step 9: Per-task gates**

```bash
php -l src/Entity/Exception/InvalidRunStatusException.php
php -l src/Entity/RecommendationRun.php
php -l tests/Entity/RecommendationRunTest.php
vendor/bin/phpcs src/Entity/Exception/InvalidRunStatusException.php src/Entity/RecommendationRun.php tests/Entity/RecommendationRunTest.php
php bin/phpunit tests/Entity/RecommendationRunTest.php
```
Expected: `No syntax errors detected` three times, phpcs prints nothing, phpunit `OK`.

- [ ] **Step 10: Commit**

```bash
git add backend/src/Entity/Exception/InvalidRunStatusException.php backend/src/Entity/RecommendationRun.php backend/tests/Entity/RecommendationRunTest.php
git commit -m "refactor(#1267): the run's status guard throws InvalidRunStatusException"
```

---

### Task 2: A throttle or call-attempt view held past the run's end refuses to write

**Files:**
- Modify: `backend/src/Entity/RunningThrottle.php`, `backend/src/Entity/RunningCallAttempts.php`, `backend/src/Entity/RecommendationRun.php` (`getRunningCallAttempts()`, `getRunningThrottle()`)
- Test: `backend/tests/Entity/RecommendationRunTest.php`

- [ ] **Step 1: Confirm nothing else builds a view**

Run (repo root):
```bash
git grep -n -E 'new Running(Throttle|CallAttempts)\(' -- backend/src backend/tests
git grep -c -E 'getRunning(Throttle|CallAttempts)\(\)' -- backend/src/Service
```
Expected, first command, exactly the two lines the edit below changes (they are its positive control):
```
backend/src/Entity/RecommendationRun.php:216:        return new RunningCallAttempts($this->callAttempts);
backend/src/Entity/RecommendationRun.php:278:        return new RunningThrottle($this->throttle);
```
Second command, the four call sites (unchanged by this task):
```
backend/src/Service/Recommendation/Run/InvalidReplyRetry.php:1
backend/src/Service/Recommendation/Run/RecommendationRunDeferral.php:1
backend/src/Service/Recommendation/Run/RecommendationTransportFailureRecorder.php:1
backend/src/Service/Recommendation/Run/RecommendationWaveConcurrency.php:1
```

- [ ] **Step 2: Write the failing tests**

In `backend/tests/Entity/RecommendationRunTest.php`:

Before:
```php
use App\Enum\RunStatus;
use PHPUnit\Framework\TestCase;
```
After:
```php
use App\Enum\RunStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
```

Before:
```php
    public function testARunningRunNarrowsItsWaveThroughItsThrottle(): void
    {
        $run = $this->runInRunningState();

        $run->getRunningThrottle()->reduceConcurrency(8);

        self::assertSame(4, $run->getWaveConcurrencyCap(8));
    }
```
After:
```php
    public function testARunningRunNarrowsItsWaveThroughItsThrottle(): void
    {
        $run = $this->runInRunningState();

        $run->getRunningThrottle()->reduceConcurrency(8);

        self::assertSame(4, $run->getWaveConcurrencyCap(8));
    }

    /** @return iterable<string, array{\Closure(RecommendationRun): void, string}> */
    public static function runEndings(): iterable
    {
        $when = new \DateTimeImmutable('2026-08-07T10:00:00Z');

        yield 'complete()' => [static fn (RecommendationRun $run) => $run->complete($when), 'completed'];
        yield 'fail()' => [static fn (RecommendationRun $run) => $run->fail('boom', $when), 'failed'];
        yield 'cancel()' => [static fn (RecommendationRun $run) => $run->cancel($when), 'cancelled'];
    }

    /** @param \Closure(RecommendationRun): void $end */
    #[DataProvider('runEndings')]
    public function testAThrottleHeldPastTheRunsEndRefusesToDefer(\Closure $end, string $endStatus): void
    {
        $run = $this->runInRunningState();
        $throttle = $run->getRunningThrottle();
        $end($run);

        $this->expectException(InvalidRunStatusException::class);
        $this->expectExceptionMessage(
            sprintf('Cannot defer a recommendation run from status "%s".', $endStatus),
        );

        $throttle->deferUntil(new \DateTimeImmutable('2026-08-07T10:05:00Z'));
    }

    /** @param \Closure(RecommendationRun): void $end */
    #[DataProvider('runEndings')]
    public function testAThrottleHeldPastTheRunsEndRefusesToNarrowTheWave(\Closure $end, string $endStatus): void
    {
        $run = $this->runInRunningState();
        $throttle = $run->getRunningThrottle();
        $end($run);

        $this->expectException(InvalidRunStatusException::class);
        $this->expectExceptionMessage(
            sprintf('Cannot narrow the wave of a recommendation run from status "%s".', $endStatus),
        );

        $throttle->reduceConcurrency(8);
    }

    /** @param \Closure(RecommendationRun): void $end */
    #[DataProvider('runEndings')]
    public function testCallAttemptsHeldPastTheRunsEndRefuseAnInvalidReply(\Closure $end, string $endStatus): void
    {
        $run = $this->runInRunningState();
        $callAttempts = $run->getRunningCallAttempts();
        $end($run);

        $this->expectException(InvalidRunStatusException::class);
        $this->expectExceptionMessage(
            sprintf('Cannot record an invalid reply on a recommendation run from status "%s".', $endStatus),
        );

        $callAttempts->recordInvalidReply('garbage');
    }

    /** @param \Closure(RecommendationRun): void $end */
    #[DataProvider('runEndings')]
    public function testCallAttemptsHeldPastTheRunsEndRefuseATransportFailure(\Closure $end, string $endStatus): void
    {
        $run = $this->runInRunningState();
        $callAttempts = $run->getRunningCallAttempts();
        $end($run);

        $this->expectException(InvalidRunStatusException::class);
        $this->expectExceptionMessage(
            sprintf('Cannot record a transport failure on a recommendation run from status "%s".', $endStatus),
        );

        $callAttempts->recordTransportFailure();
    }
```

- [ ] **Step 3: Run them and watch them fail**

Run: `php bin/phpunit --filter 'HeldPastTheRunsEnd' tests/Entity/RecommendationRunTest.php`
Expected: `Tests: 12`, `Failures: 12`, each reading
```
Failed asserting that exception of type "App\Entity\Exception\InvalidRunStatusException" is thrown.
```
(The views write silently today: that is the defect.)

- [ ] **Step 4: The throttle view checks the run**

Replace the whole of `backend/src/Entity/RunningThrottle.php`.

Before (verbatim, whole file):
```php
<?php

declare(strict_types=1);

namespace App\Entity;

/** A running run's throttle, narrowed to the rate-limit transitions so no holder can reset it mid-run. */
final readonly class RunningThrottle
{
    /** @noinspection AutowireWrongClass Built with new, never autowired */
    public function __construct(private RunThrottle $throttle)
    {
    }

    public function deferUntil(\DateTimeImmutable $when): void
    {
        $this->throttle->deferUntil($when);
    }

    public function reduceConcurrency(int $configuredCap): void
    {
        $this->throttle->reduceConcurrency($configuredCap);
    }
}
```
After (whole file):
```php
<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Exception\InvalidRunStatusException;
use App\Enum\RunStatus;

/** A running run's throttle, narrowed to the rate-limit transitions so no holder can reset it mid-run. */
final readonly class RunningThrottle
{
    /** @noinspection AutowireWrongClass Built with new, never autowired */
    public function __construct(private RecommendationRun $run, private RunThrottle $throttle)
    {
    }

    public function deferUntil(\DateTimeImmutable $when): void
    {
        $this->guardRunning('defer');
        $this->throttle->deferUntil($when);
    }

    public function reduceConcurrency(int $configuredCap): void
    {
        $this->guardRunning('narrow the wave of');
        $this->throttle->reduceConcurrency($configuredCap);
    }

    private function guardRunning(string $write): void
    {
        $status = $this->run->getStatus();
        if (RunStatus::Running !== $status) {
            throw new InvalidRunStatusException($write, $status);
        }
    }
}
```

- [ ] **Step 5: The call-attempts view checks the run**

Replace the whole of `backend/src/Entity/RunningCallAttempts.php`.

Before (verbatim, whole file):
```php
<?php

declare(strict_types=1);

namespace App\Entity;

/** A running run's call attempts, narrowed to recording so no holder can reset the MAX_TRANSPORT_FAILURES count. */
final readonly class RunningCallAttempts
{
    /** @noinspection AutowireWrongClass Built with new, never autowired */
    public function __construct(private RunCallAttempts $callAttempts)
    {
    }

    public function recordInvalidReply(string $reply): void
    {
        $this->callAttempts->recordInvalidReply($reply);
    }

    public function recordTransportFailure(): void
    {
        $this->callAttempts->recordTransportFailure();
    }
}
```
After (whole file):
```php
<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Exception\InvalidRunStatusException;
use App\Enum\RunStatus;

/** A running run's call attempts, narrowed to recording so no holder can reset the MAX_TRANSPORT_FAILURES count. */
final readonly class RunningCallAttempts
{
    /** @noinspection AutowireWrongClass Built with new, never autowired */
    public function __construct(private RecommendationRun $run, private RunCallAttempts $callAttempts)
    {
    }

    public function recordInvalidReply(string $reply): void
    {
        $this->guardRunning('record an invalid reply on');
        $this->callAttempts->recordInvalidReply($reply);
    }

    public function recordTransportFailure(): void
    {
        $this->guardRunning('record a transport failure on');
        $this->callAttempts->recordTransportFailure();
    }

    private function guardRunning(string $write): void
    {
        $status = $this->run->getStatus();
        if (RunStatus::Running !== $status) {
            throw new InvalidRunStatusException($write, $status);
        }
    }
}
```

- [ ] **Step 6: The run hands itself to its views**

In `backend/src/Entity/RecommendationRun.php`:

Before:
```php
        $this->guardStatus(RunStatus::Running, 'record a call attempt on');

        return new RunningCallAttempts($this->callAttempts);
```
After:
```php
        $this->guardStatus(RunStatus::Running, 'record a call attempt on');

        return new RunningCallAttempts($this, $this->callAttempts);
```

Before:
```php
        $this->guardStatus(RunStatus::Running, 'throttle');

        return new RunningThrottle($this->throttle);
```
After:
```php
        $this->guardStatus(RunStatus::Running, 'throttle');

        return new RunningThrottle($this, $this->throttle);
```

- [ ] **Step 7: Run them and watch them pass**

Run: `php bin/phpunit tests/Entity/RecommendationRunTest.php`
Expected: `OK` (the 12 new cases pass; every existing test that writes through a fresh view on a running run still passes).

Then the view's callers and the repository round trip:
Run: `php bin/phpunit tests/Service/Recommendation tests/Repository/RecommendationRunRepositoryTest.php tests/Controller/Api/RecommendationDebugLogControllerTest.php`
Expected: `OK`. If a test fails with `InvalidRunStatusException`, a production path writes through a view after the run ended: stop and report it to the planner with the stack trace; do not loosen the check.

- [ ] **Step 8: Deletion checks, one per write**

Each check edits one line, runs one command, and is restored by re-applying the Step 4 or Step 5 After with the Edit tool. After each restore, the Step 7 first command prints `OK`.

1. In `RunningThrottle::deferUntil()`, delete the line `$this->guardRunning('defer');`.
   Run: `php bin/phpunit --filter 'testAThrottleHeldPastTheRunsEndRefusesToDefer' tests/Entity/RecommendationRunTest.php`
   Expected: `Failures: 3`, each `Failed asserting that exception of type "App\Entity\Exception\InvalidRunStatusException" is thrown.`
2. In `RunningThrottle::reduceConcurrency()`, delete the line `$this->guardRunning('narrow the wave of');`.
   Run: `php bin/phpunit --filter 'testAThrottleHeldPastTheRunsEndRefusesToNarrowTheWave' tests/Entity/RecommendationRunTest.php`
   Expected: `Failures: 3`, each `Failed asserting that exception of type "App\Entity\Exception\InvalidRunStatusException" is thrown.`
3. In `RunningCallAttempts::recordInvalidReply()`, delete the line `$this->guardRunning('record an invalid reply on');`.
   Run: `php bin/phpunit --filter 'testCallAttemptsHeldPastTheRunsEndRefuseAnInvalidReply' tests/Entity/RecommendationRunTest.php`
   Expected: `Failures: 3`, each `Failed asserting that exception of type "App\Entity\Exception\InvalidRunStatusException" is thrown.`
4. In `RunningCallAttempts::recordTransportFailure()`, delete the line `$this->guardRunning('record a transport failure on');`.
   Run: `php bin/phpunit --filter 'testCallAttemptsHeldPastTheRunsEndRefuseATransportFailure' tests/Entity/RecommendationRunTest.php`
   Expected: `Failures: 3`, each `Failed asserting that exception of type "App\Entity\Exception\InvalidRunStatusException" is thrown.`
5. In `RunningThrottle::guardRunning()`, change `RunStatus::Running !== $status` to `RunStatus::Running === $status`.
   Run: `php bin/phpunit --filter 'testARunningRunDefersThroughItsThrottle' tests/Entity/RecommendationRunTest.php`
   Expected: `Errors: 1`, `App\Entity\Exception\InvalidRunStatusException: Cannot defer a recommendation run from status "running".` (the check must not refuse a running run).

- [ ] **Step 9: Grep checks**

Run (repo root):
```bash
git grep -n -E 'new Running(Throttle|CallAttempts)\(' -- backend/src backend/tests
git diff --stat origin/develop...HEAD -- backend/src/Service
git diff --stat HEAD -- backend/src/Entity
```
Expected: the first prints exactly the two changed lines (`new RunningCallAttempts($this, $this->callAttempts);`, `new RunningThrottle($this, $this->throttle);`). The second prints nothing: no call site changed. The third, the positive control for the second, lists `RecommendationRun.php`, `RunningCallAttempts.php` and `RunningThrottle.php` (uncommitted until Step 11).

- [ ] **Step 10: Per-task gates**

```bash
php -l src/Entity/RunningThrottle.php
php -l src/Entity/RunningCallAttempts.php
php -l src/Entity/RecommendationRun.php
php -l tests/Entity/RecommendationRunTest.php
vendor/bin/phpcs src/Entity/RunningThrottle.php src/Entity/RunningCallAttempts.php src/Entity/RecommendationRun.php tests/Entity/RecommendationRunTest.php
php bin/phpunit tests/Entity/RecommendationRunTest.php
```
Expected: `No syntax errors detected` four times, phpcs prints nothing, phpunit `OK`.

- [ ] **Step 11: Commit**

```bash
git add backend/src/Entity/RunningThrottle.php backend/src/Entity/RunningCallAttempts.php backend/src/Entity/RecommendationRun.php backend/tests/Entity/RecommendationRunTest.php
git commit -m "fix(#1267): a throttle or call-attempt view held past the run's end refuses to write"
```

---

### Task 3: PR gates, review and PR

**Files:** none changed unless a gate or the review finds something.

- [ ] **Step 1: PR gates** (from `backend/`)

```bash
composer cs
bin/console cache:warmup && composer stan
composer md
composer tramp
php bin/phpunit
```
Expected: each exits 0. `composer md` prints nothing for `src/Entity` (the entity's public-method count is unchanged: the views gained a private method, the entity none). `composer tramp` reports no chain through `InvalidRunStatusException` (its constructor reads `$operation`). If `composer tramp` fails on files this branch did not touch, run `composer show larspohlmann/phptramp` and report it to the planner (CLAUDE.md: CI runs phptramp's `develop` tip).

Then the MySQL leg and mutation testing:
```bash
docker compose exec php composer test
composer infection:diff
```
Expected: `OK` on MySQL; Infection meets `minMsi` with no escaped mutant on a changed line. The guard's `!==` is killed by the existing fresh-view tests, each `guardRunning(...)` call by its Task 2 test, and the `throw` in `guardStatusOneOf()` by the pending-run tests.

PhpStorm: `mcp__phpstorm__lint_files` on `backend/src/Entity/Exception/InvalidRunStatusException.php`, `backend/src/Entity/RecommendationRun.php`, `backend/src/Entity/RunningThrottle.php`, `backend/src/Entity/RunningCallAttempts.php`, `backend/tests/Entity/RecommendationRunTest.php`. Block on ERROR or WARNING; weak warnings are advisory.

- [ ] **Step 2: Final review (opus)**

Dispatch one fresh reviewer subagent with `model: "opus"`, this plan, the issue and `git diff origin/develop...HEAD`. It reports findings and fixes nothing. Attack points: every write on both views checks the status before it writes; no path in `src/Service/Recommendation` writes through a view after `complete()`, `fail()` or `cancel()` in the same tick (read `InvalidReplyRetry` and `RecommendationTransportFailureRecorder`, where `fail()` follows the write); every existing message is byte-identical; nothing catches `\LogicException` in a way the new subclass changes; no comment was added. Re-run one Task 2 deletion check and quote its FAIL. Fix each finding rated Important or above in its own commit (`fix(#1267): review — <finding>`), and re-run the Step 1 gates.

- [ ] **Step 3: Push and open the PR**

```bash
git push -u origin fix/1267-run-views-after-end
gh pr create --repo larspohlmann/simple-feed-reader --base develop --head fix/1267-run-views-after-end \
  --title "fix(#1267): a run's views refuse writes once the run ended" \
  --body "$(cat <<'EOF'
A `RunningThrottle` or `RunningCallAttempts` view held past `complete()`, `fail()` or `cancel()` could still write into the ended run; only the call sites' habit of using the view inline prevented it.

- Each view holds its run and checks, on every write, that the run is still running. Otherwise it throws `App\Entity\Exception\InvalidRunStatusException`. The four call sites are unchanged.
- `InvalidRunStatusException` (a `\LogicException`) owns the guard's message. The entity's own status guard throws it too, so every existing message is byte-identical.
- Chosen over invalidating handed-out views (state in a Doctrine entity) and over a callback API (every call site changes, and `RecommendationRun` is at PHPMD's public-method ceiling).
- Tests: a view held across each ending, for each of the four writes; deletion checks per write.

Closes #1267
EOF
)"
```
Expected: the PR URL.

- [ ] **Merge when CI is green, then report.** Watch CI with a Monitor, merge with `gh pr merge --merge` (never `--auto`) once every check is green. Before merging, check `gh pr view <PR> --json closingIssuesReferences` lists #1267; if it is empty, re-save the body with `gh pr edit <PR> --body-file <file>` and check again. After the merge, verify #1267 is CLOSED (COMPLETED); if GitHub still did not close it, close it by hand with `--reason completed` and a comment naming the PR and merge SHA. Report the PR URL, the merge SHA, the quoted deletion-check FAILs and the gate results to the planner.
