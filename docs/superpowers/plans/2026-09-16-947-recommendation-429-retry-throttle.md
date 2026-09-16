# Provider 429 Retry and Throttle Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make a recommendation run survive a provider rate limit — wait and retry on 429/502/503/504, and lower the wave call rate for the rest of the run.

**Architecture:** A new retryable exception carries the HTTP status and `Retry-After`. A new `RateLimitedCompletion` collaborator wraps the concurrent chat client and, for the worker, waits and re-fires only the rate-limited calls; for poll/sweep it returns a *deferred* signal. The advancer builds a per-tick `RetryPlan` from the driver, gates a tick before its "retry not before" time, halves the run's wave concurrency once per rate-limited tick, and defers or strikes per the result. Two new persisted fields live in a `RunThrottle` embeddable on `RecommendationRun`.

**Tech Stack:** PHP 8.4, Symfony 7.4, Doctrine ORM, PHPUnit 12, Infection.

**Spec:** [docs/superpowers/specs/2026-09-16-947-recommendation-429-retry-throttle-design.md](../specs/2026-09-16-947-recommendation-429-retry-throttle-design.md)

## Global Constraints

- **All commands run from `backend/`.** Native test run is SQLite: `php bin/phpunit`.
- **Clean Code is mandatory** (see CLAUDE.md): intent-revealing names, small single-purpose methods, guard clauses, immutability (`final readonly` where possible), inject interfaces, typed namespaced exceptions, comments only for a genuinely non-obvious invariant (one line, three at most).
- **Gates that must stay green:** `composer cs` (PSR-12), `composer stan` (PHPStan max — warm cache first with `bin/console cache:warmup`), `composer md` (every `src` file you touch must be PHPMD-clean), `composer tramp` (no 4+ forwarding chain across 2+ classes). Run `composer check` before each commit that touches PHP.
- **Retryable statuses:** exactly `429, 502, 503, 504`. 401/403 stay credentials errors; every other `>= 300` stays `ProviderUnreachableException`.
- **Backoff schedule (interpretation to confirm at review):** up to **3 retries** after the initial send, waiting **1 s, 2 s, 4 s** before each retry. `Retry-After` (integer seconds) overrides the backoff step when present. "3 attempts per call per tick" in the issue is read here as *3 retry attempts*, so all three backoff values are exercised.
- **Worker total-wait budget:** ~120 s. If the next wait would cross it, defer instead of blocking.
- **Concurrency halving:** once per tick whose batch wave observed a 429; halve the configured cap (`min(batchConcurrency, MAX_BATCH_CONCURRENCY)`), floor 1, never climbs, persists for the run, reset only by `resume()`.
- **Datetimes are naive UTC.** Compute "retry not before" from the injected `ClockInterface`; the process is pinned to UTC.
- **Scope:** recommendation runs only. `OpenAiCompatibleCatalog` (model listing) is untouched. No frontend change.
- **Commit message format:** `type(#947): summary` (e.g. `feat(#947): ...`, `test(#947): ...`). No attribution lines.

---

### Task 1: `RetryableProviderException`

**Files:**
- Create: `backend/src/Service/Ai/Exception/RetryableProviderException.php`
- Test: `backend/tests/Service/Ai/Exception/RetryableProviderExceptionTest.php`

**Interfaces:**
- Produces: `RetryableProviderException` with `int status()`, `?int retryAfterSeconds()`; extends `\RuntimeException`.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Ai\Exception;

use App\Service\Ai\Exception\RetryableProviderException;
use PHPUnit\Framework\TestCase;

final class RetryableProviderExceptionTest extends TestCase
{
    public function testCarriesStatusAndRetryAfter(): void
    {
        $exception = new RetryableProviderException(429, 12);

        self::assertSame(429, $exception->status());
        self::assertSame(12, $exception->retryAfterSeconds());
        self::assertStringContainsString('429', $exception->getMessage());
    }

    public function testRetryAfterIsOptional(): void
    {
        self::assertNull((new RetryableProviderException(503))->retryAfterSeconds());
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php bin/phpunit tests/Service/Ai/Exception/RetryableProviderExceptionTest.php`
Expected: FAIL — class not found.

- [ ] **Step 3: Write the implementation**

```php
<?php

declare(strict_types=1);

namespace App\Service\Ai\Exception;

/**
 * A provider status the run may wait out and retry: 429, 502, 503, 504. Carries
 * the parsed `Retry-After` when the response supplied one. Distinct from
 * ProviderUnreachableException so the advancer can tell a rate limit — which
 * throttles and retries — from a dead address, which fails fast.
 */
final class RetryableProviderException extends \RuntimeException
{
    public function __construct(
        private readonly int $status,
        private readonly ?int $retryAfterSeconds = null,
    ) {
        parent::__construct(sprintf('That provider answered with status %d.', $status));
    }

    public function status(): int
    {
        return $this->status;
    }

    public function retryAfterSeconds(): ?int
    {
        return $this->retryAfterSeconds;
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `php bin/phpunit tests/Service/Ai/Exception/RetryableProviderExceptionTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
composer cs && git add -A && git commit -m "feat(#947): add RetryableProviderException carrying status and Retry-After"
```

---

### Task 2: `CompletionOutcome` learns retryable

**Files:**
- Modify: `backend/src/Service/Recommendation/CompletionOutcome.php`
- Create: `backend/tests/Service/Recommendation/CompletionOutcomeTest.php`

**Interfaces:**
- Consumes: `RetryableProviderException` (Task 1).
- Produces: `CompletionOutcome::isRetryable(): bool`, `CompletionOutcome::retryAfterSeconds(): ?int`.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Recommendation;

use App\Service\Ai\Exception\ProviderUnreachableException;
use App\Service\Ai\Exception\RetryableProviderException;
use App\Service\Recommendation\CompletionOutcome;
use PHPUnit\Framework\TestCase;

final class CompletionOutcomeTest extends TestCase
{
    public function testARetryableFailureIsFlaggedAndCarriesItsRetryAfter(): void
    {
        $outcome = CompletionOutcome::failure(new RetryableProviderException(429, 7));

        self::assertTrue($outcome->isFailure());
        self::assertTrue($outcome->isRetryable());
        self::assertSame(7, $outcome->retryAfterSeconds());
    }

    public function testAPlainTransportFailureIsNotRetryable(): void
    {
        $outcome = CompletionOutcome::failure(new ProviderUnreachableException('gone'));

        self::assertFalse($outcome->isRetryable());
        self::assertNull($outcome->retryAfterSeconds());
    }

    public function testAnAnswerIsNotRetryable(): void
    {
        $outcome = CompletionOutcome::answer('{}');

        self::assertFalse($outcome->isRetryable());
        self::assertNull($outcome->retryAfterSeconds());
    }
}
```

- [ ] **Step 2: Run to verify it fails**

Run: `php bin/phpunit tests/Service/Recommendation/CompletionOutcomeTest.php`
Expected: FAIL — `isRetryable()` not defined.

- [ ] **Step 3: Implement**

Add the import at the top of `CompletionOutcome.php`:

```php
use App\Service\Ai\Exception\RetryableProviderException;
```

Add these two methods (after `cause()`):

```php
    public function isRetryable(): bool
    {
        return $this->cause instanceof RetryableProviderException;
    }

    public function retryAfterSeconds(): ?int
    {
        return $this->cause instanceof RetryableProviderException
            ? $this->cause->retryAfterSeconds()
            : null;
    }
```

- [ ] **Step 4: Run to verify it passes**

Run: `php bin/phpunit tests/Service/Recommendation/CompletionOutcomeTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
composer cs && git add -A && git commit -m "feat(#947): CompletionOutcome flags a retryable provider failure"
```

---

### Task 3: `OpenAiCompatibleChatClient` maps retryable statuses

**Files:**
- Modify: `backend/src/Service/Recommendation/OpenAiCompatibleChatClient.php` (`guardStatus`, its call site in `consumeChunk`, and the catch in `advance`)
- Test: `backend/tests/Service/Recommendation/OpenAiCompatibleChatClientTest.php`

**Interfaces:**
- Consumes: `RetryableProviderException` (Task 1), `CompletionOutcome::isRetryable/retryAfterSeconds` (Task 2).
- Produces: a 429/502/503/504 response becomes a per-call outcome whose `isRetryable()` is true and whose `retryAfterSeconds()` reflects the `Retry-After` header.

- [ ] **Step 1: Write the failing tests**

Add to `OpenAiCompatibleChatClientTest.php` (import `App\Service\Ai\Exception\RetryableProviderException` at the top):

```php
    public function testA429BecomesARetryableOutcomeCarryingRetryAfter(): void
    {
        $client = $this->clientAnswering(
            new MockResponse('{"error":"slow down"}', [
                'http_code' => 429,
                'response_headers' => ['retry-after' => '9'],
            ]),
        );

        $outcome = $this->soleOutcomeOf($client, $this->request());

        self::assertTrue($outcome->isFailure());
        self::assertTrue($outcome->isRetryable());
        self::assertInstanceOf(RetryableProviderException::class, $outcome->cause());
        self::assertSame(9, $outcome->retryAfterSeconds());
    }

    public function testA503IsRetryableWithNoRetryAfter(): void
    {
        $client = $this->clientAnswering(new MockResponse('', ['http_code' => 503]));

        $outcome = $this->soleOutcomeOf($client, $this->request());

        self::assertTrue($outcome->isRetryable());
        self::assertNull($outcome->retryAfterSeconds());
    }

    public function testA500IsStillANonRetryableUnreachableFailure(): void
    {
        $client = $this->clientAnswering(new MockResponse('', ['http_code' => 500]));

        $outcome = $this->soleOutcomeOf($client, $this->request());

        self::assertTrue($outcome->isFailure());
        self::assertFalse($outcome->isRetryable());
        self::assertInstanceOf(ProviderUnreachableException::class, $outcome->cause());
    }

    public function testANonNumericRetryAfterFallsBackToNoHint(): void
    {
        $client = $this->clientAnswering(
            new MockResponse('', ['http_code' => 429, 'response_headers' => ['retry-after' => 'Wed, 21 Oct 2026 07:28:00 GMT']]),
        );

        self::assertNull($this->soleOutcomeOf($client, $this->request())->retryAfterSeconds());
    }
```

- [ ] **Step 2: Run to verify they fail**

Run: `php bin/phpunit tests/Service/Recommendation/OpenAiCompatibleChatClientTest.php --filter Retryable`
(Then also `--filter RetryAfter` / by method name.) Expected: FAIL — 429 currently maps to `ProviderUnreachableException`.

- [ ] **Step 3: Implement**

Add the import near the other exception imports:

```php
use App\Service\Ai\Exception\RetryableProviderException;
```

Change the `consumeChunk` call site from `$this->guardStatus($response->getStatusCode());` to:

```php
            $this->guardStatus($response);
```

Replace `guardStatus` with a response-aware version plus a header parser:

```php
    private function guardStatus(ResponseInterface $response): void
    {
        $status = $response->getStatusCode();

        if (401 === $status || 403 === $status) {
            throw new CredentialsRejectedException('That provider refused the API key.');
        }

        if (\in_array($status, [429, 502, 503, 504], true)) {
            throw new RetryableProviderException($status, $this->retryAfterSeconds($response));
        }

        if ($status >= 300) {
            throw new ProviderUnreachableException(sprintf('That provider answered with status %d.', $status));
        }
    }

    /**
     * Integer seconds only. An HTTP-date form is left to the caller's backoff:
     * turning a date into a wait needs a clock this driver-agnostic client does
     * not carry, and the standard rate-limit form is a seconds count anyway.
     */
    private function retryAfterSeconds(ResponseInterface $response): ?int
    {
        $header = $response->getHeaders(false)['retry-after'][0] ?? null;

        return null !== $header && ctype_digit($header) ? (int) $header : null;
    }
```

Add `RetryableProviderException` to the fold-to-outcome catch in `advance`:

```php
        } catch (CredentialsRejectedException | ProviderUnreachableException | RetryableProviderException $failure) {
            $response->cancel();

            return CompletionOutcome::failure($failure);
        }
```

- [ ] **Step 4: Run to verify they pass**

Run: `php bin/phpunit tests/Service/Recommendation/OpenAiCompatibleChatClientTest.php`
Expected: PASS (all, including the pre-existing status tests).

- [ ] **Step 5: Commit**

```bash
composer check && git add -A && git commit -m "feat(#947): map 429/502/503/504 to a retryable provider outcome"
```

---

### Task 4: `RetryPlan`

**Files:**
- Create: `backend/src/Service/Recommendation/RetryPlan.php`
- Test: `backend/tests/Service/Recommendation/RetryPlanTest.php`

**Interfaces:**
- Consumes: `TickDriver` (`Worker`, `Poll`, `Sweep`).
- Produces: `RetryPlan::forDriver(TickDriver): self`, `blocks(): bool`, `maxRetries(): int`, `budgetSeconds(): float`, `waitSecondsFor(int $retryIndex, ?int $retryAfterSeconds): float`.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Recommendation;

use App\Service\Recommendation\RetryPlan;
use App\Service\Recommendation\TickDriver;
use PHPUnit\Framework\TestCase;

final class RetryPlanTest extends TestCase
{
    public function testTheWorkerPlanBlocks(): void
    {
        self::assertTrue(RetryPlan::forDriver(TickDriver::Worker)->blocks());
    }

    public function testThePollAndSweepPlansDefer(): void
    {
        self::assertFalse(RetryPlan::forDriver(TickDriver::Poll)->blocks());
        self::assertFalse(RetryPlan::forDriver(TickDriver::Sweep)->blocks());
    }

    public function testTheBackoffScheduleIsOneTwoFour(): void
    {
        $plan = RetryPlan::forDriver(TickDriver::Worker);

        self::assertSame(1.0, $plan->waitSecondsFor(0, null));
        self::assertSame(2.0, $plan->waitSecondsFor(1, null));
        self::assertSame(4.0, $plan->waitSecondsFor(2, null));
    }

    public function testRetryAfterOverridesTheBackoffStep(): void
    {
        self::assertSame(30.0, RetryPlan::forDriver(TickDriver::Worker)->waitSecondsFor(0, 30));
    }

    public function testItAllowsThreeRetriesWithinATwoMinuteBudget(): void
    {
        $plan = RetryPlan::forDriver(TickDriver::Worker);

        self::assertSame(3, $plan->maxRetries());
        self::assertSame(120.0, $plan->budgetSeconds());
    }
}
```

- [ ] **Step 2: Run to verify it fails**

Run: `php bin/phpunit tests/Service/Recommendation/RetryPlanTest.php`
Expected: FAIL — class not found.

- [ ] **Step 3: Implement**

```php
<?php

declare(strict_types=1);

namespace App\Service\Recommendation;

/**
 * How a tick handles a provider rate limit, decided by its driver. The worker
 * owns its process, so it blocks and retries in-tick within a budget; the poll
 * and sweep drivers run inside a bounded web request, so they never block and
 * defer to a later tick instead (#947).
 */
final readonly class RetryPlan
{
    /** Waits before the 1st, 2nd, 3rd retry when the provider gives no Retry-After. */
    private const array BACKOFF_SECONDS = [1.0, 2.0, 4.0];

    private const int MAX_RETRIES = 3;

    /** Stays under Strato's 240 s cgi-fcgi cap with room for the call itself. */
    private const float WORKER_BUDGET_SECONDS = 120.0;

    private function __construct(private bool $blocks)
    {
    }

    public function forDriver(TickDriver $driver): self
    {
        return new self(TickDriver::Worker === $driver);
    }

    public function blocks(): bool
    {
        return $this->blocks;
    }

    public function maxRetries(): int
    {
        return self::MAX_RETRIES;
    }

    public function budgetSeconds(): float
    {
        return self::WORKER_BUDGET_SECONDS;
    }

    public function waitSecondsFor(int $retryIndex, ?int $retryAfterSeconds): float
    {
        if (null !== $retryAfterSeconds) {
            return (float) $retryAfterSeconds;
        }

        return self::BACKOFF_SECONDS[$retryIndex] ?? self::BACKOFF_SECONDS[array_key_last(self::BACKOFF_SECONDS)];
    }
}
```

Note: `forDriver` must be `static`. Write it as `public static function forDriver(...)`.

- [ ] **Step 4: Run to verify it passes**

Run: `php bin/phpunit tests/Service/Recommendation/RetryPlanTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
composer check && git add -A && git commit -m "feat(#947): add RetryPlan built from the tick driver"
```

---

### Task 5: `RateLimitedCompletion` and `RateLimitedResult`

**Files:**
- Create: `backend/src/Service/Recommendation/RateLimitedResult.php`
- Create: `backend/src/Service/Recommendation/RateLimitedCompletion.php`
- Create: `backend/src/Service/Recommendation/Exception/RecommendationRunRateLimitedException.php`
- Test: `backend/tests/Service/Recommendation/RateLimitedCompletionTest.php`

**Interfaces:**
- Consumes: `ChatCompletionClient` (base), `ClockInterface`, `RetryPlan`, `CompletionOutcome`, `ConcurrentCompletion`, `ProviderConnection`.
- Produces:
  - `RateLimitedResult::completed(list<CompletionOutcome> $outcomes, bool $rateLimitObserved): self`, `RateLimitedResult::deferred(float $waitSeconds): self`, `isDeferred(): bool`, readonly `array $outcomes`, `bool $rateLimitObserved`, `float $deferSeconds` (0.0 when not deferred).
  - `RateLimitedCompletion::completeMany(ProviderConnection $connection, non-empty-list<ConcurrentCompletion> $calls, RetryPlan $plan): RateLimitedResult`.
  - `RateLimitedCompletion::complete(ProviderConnection $connection, CompletionRequest $request, CompletionStreamObserver $observer, RetryPlan $plan): string` — throws `RecommendationRunRateLimitedException` when the plan defers.
  - `RecommendationRunRateLimitedException::__construct(float $waitSeconds)`, `waitSeconds(): float`.

- [ ] **Step 1: Create the deferral exception**

```php
<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Exception;

/**
 * A rate-limited call the current tick will not wait out: the advancer catches
 * it, records "retry not before now + waitSeconds" on the run, and returns at
 * once. Named apart from App\Exception\RateLimitedException, which is the HTTP
 * limiter's 429 to the client (#947).
 */
final class RecommendationRunRateLimitedException extends \RuntimeException
{
    public function __construct(private readonly float $waitSeconds)
    {
        parent::__construct(sprintf('Provider rate limited; deferring for %.0f s.', $waitSeconds));
    }

    public function waitSeconds(): float
    {
        return $this->waitSeconds;
    }
}
```

- [ ] **Step 2: Create the result value object**

```php
<?php

declare(strict_types=1);

namespace App\Service\Recommendation;

/**
 * What one rate-limit-aware round of calls produced: either completed outcomes
 * (with whether any 429 was seen along the way) or a deferral with the wait to
 * apply. A worker call retried to exhaustion stays a failure inside $outcomes,
 * carrying its RetryableProviderException (#947).
 */
final readonly class RateLimitedResult
{
    /**
     * @param list<CompletionOutcome> $outcomes
     */
    private function __construct(
        public array $outcomes,
        public bool $rateLimitObserved,
        public float $deferSeconds,
        private bool $deferred,
    ) {
    }

    /**
     * @param list<CompletionOutcome> $outcomes
     */
    public static function completed(array $outcomes, bool $rateLimitObserved): self
    {
        return new self($outcomes, $rateLimitObserved, 0.0, false);
    }

    public static function deferred(float $waitSeconds): self
    {
        return new self([], true, $waitSeconds, true);
    }

    public function isDeferred(): bool
    {
        return $this->deferred;
    }
}
```

- [ ] **Step 3: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Recommendation;

use App\Service\Ai\Exception\RetryableProviderException;
use App\Service\Ai\ProviderConnection;
use App\Service\Ai\ProviderCredentials;
use App\Service\Ai\ProviderTimeouts;
use App\Service\Recommendation\CompletionRequest;
use App\Service\Recommendation\ConcurrentCompletion;
use App\Service\Recommendation\Exception\RecommendationRunRateLimitedException;
use App\Service\Recommendation\JsonSchema;
use App\Service\Recommendation\NullCompletionStreamObserver;
use App\Service\Recommendation\RateLimitedCompletion;
use App\Service\Recommendation\RetryPlan;
use App\Service\Recommendation\TickDriver;
use App\Tests\Support\StubChatClient;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

final class RateLimitedCompletionTest extends TestCase
{
    private function connection(): ProviderConnection
    {
        return new ProviderConnection(
            ProviderCredentials::fromStoredConfiguration('https://api.example.test/v1', 'sk-test'),
            ProviderTimeouts::standard(),
        );
    }

    private function request(): CompletionRequest
    {
        return new CompletionRequest('m', [['role' => 'user', 'content' => 'x']], 2048, new JsonSchema('s', ['type' => 'object']), false);
    }

    /** @return list<ConcurrentCompletion> */
    private function calls(int $count): array
    {
        $calls = [];
        for ($i = 0; $i < $count; $i++) {
            $calls[] = new ConcurrentCompletion($this->request(), new NullCompletionStreamObserver());
        }

        return $calls;
    }

    public function testWorkerRetriesTheRateLimitedCallAndRecovers(): void
    {
        $chat = new StubChatClient();
        $chat->queueFailure(new RetryableProviderException(429));
        $chat->queueContent('{"ok":1}');
        $clock = new MockClock(new \DateTimeImmutable('2026-01-01T00:00:00Z'));

        $result = (new RateLimitedCompletion($chat, $clock))
            ->completeMany($this->connection(), $this->calls(1), RetryPlan::forDriver(TickDriver::Worker));

        self::assertFalse($result->isDeferred());
        self::assertTrue($result->rateLimitObserved);
        self::assertSame('{"ok":1}', $result->outcomes[0]->content());
        self::assertSame(1, $clock->now()->getTimestamp() - (new \DateTimeImmutable('2026-01-01T00:00:00Z'))->getTimestamp());
    }

    public function testWorkerWaitsOneTwoFourAcrossThreeRetries(): void
    {
        $chat = new StubChatClient();
        $chat->queueFailure(new RetryableProviderException(429)); // initial send
        $chat->queueFailure(new RetryableProviderException(429)); // retry 1 (after 1 s)
        $chat->queueFailure(new RetryableProviderException(429)); // retry 2 (after 2 s)
        $chat->queueContent('{"ok":1}');                          // retry 3 (after 4 s)
        $clock = new MockClock(new \DateTimeImmutable('2026-01-01T00:00:00Z'));

        $result = (new RateLimitedCompletion($chat, $clock))
            ->completeMany($this->connection(), $this->calls(1), RetryPlan::forDriver(TickDriver::Worker));

        self::assertSame('{"ok":1}', $result->outcomes[0]->content());
        self::assertSame(7, $clock->now()->getTimestamp() - (new \DateTimeImmutable('2026-01-01T00:00:00Z'))->getTimestamp());
    }

    public function testRetryAfterOverridesTheBackoffWait(): void
    {
        $chat = new StubChatClient();
        $chat->queueFailure(new RetryableProviderException(429, 5));
        $chat->queueContent('{"ok":1}');
        $clock = new MockClock(new \DateTimeImmutable('2026-01-01T00:00:00Z'));

        (new RateLimitedCompletion($chat, $clock))
            ->completeMany($this->connection(), $this->calls(1), RetryPlan::forDriver(TickDriver::Worker));

        self::assertSame(5, $clock->now()->getTimestamp() - (new \DateTimeImmutable('2026-01-01T00:00:00Z'))->getTimestamp());
    }

    public function testWorkerDefersWhenTheWaitWouldCrossTheBudget(): void
    {
        $chat = new StubChatClient();
        $chat->queueFailure(new RetryableProviderException(429, 200));
        $clock = new MockClock(new \DateTimeImmutable('2026-01-01T00:00:00Z'));

        $result = (new RateLimitedCompletion($chat, $clock))
            ->completeMany($this->connection(), $this->calls(1), RetryPlan::forDriver(TickDriver::Worker));

        self::assertTrue($result->isDeferred());
        self::assertSame(200.0, $result->deferSeconds);
        self::assertSame(0, $clock->now()->getTimestamp() - (new \DateTimeImmutable('2026-01-01T00:00:00Z'))->getTimestamp());
    }

    public function testWorkerExhaustsRetriesAndKeepsTheRetryableFailure(): void
    {
        $chat = new StubChatClient();
        for ($i = 0; $i < 4; $i++) { // initial + 3 retries, all 429
            $chat->queueFailure(new RetryableProviderException(429));
        }
        $clock = new MockClock(new \DateTimeImmutable('2026-01-01T00:00:00Z'));

        $result = (new RateLimitedCompletion($chat, $clock))
            ->completeMany($this->connection(), $this->calls(1), RetryPlan::forDriver(TickDriver::Worker));

        self::assertFalse($result->isDeferred());
        self::assertTrue($result->rateLimitObserved);
        self::assertTrue($result->outcomes[0]->isRetryable());
        self::assertSame(7, $clock->now()->getTimestamp() - (new \DateTimeImmutable('2026-01-01T00:00:00Z'))->getTimestamp());
    }

    public function testPollDefersImmediatelyWithoutWaiting(): void
    {
        $chat = new StubChatClient();
        $chat->queueFailure(new RetryableProviderException(429, 15));
        $clock = new MockClock(new \DateTimeImmutable('2026-01-01T00:00:00Z'));

        $result = (new RateLimitedCompletion($chat, $clock))
            ->completeMany($this->connection(), $this->calls(1), RetryPlan::forDriver(TickDriver::Poll));

        self::assertTrue($result->isDeferred());
        self::assertSame(15.0, $result->deferSeconds);
        self::assertSame(0, $clock->now()->getTimestamp() - (new \DateTimeImmutable('2026-01-01T00:00:00Z'))->getTimestamp());
    }

    public function testWorkerRefiresOnlyTheRateLimitedCallOfAWave(): void
    {
        $chat = new StubChatClient();
        $chat->queueContent('{"a":1}');                           // call 0 answers
        $chat->queueFailure(new RetryableProviderException(429));  // call 1 is limited
        $chat->queueContent('{"b":2}');                           // call 1 on retry
        $clock = new MockClock(new \DateTimeImmutable('2026-01-01T00:00:00Z'));

        $result = (new RateLimitedCompletion($chat, $clock))
            ->completeMany($this->connection(), $this->calls(2), RetryPlan::forDriver(TickDriver::Worker));

        self::assertSame('{"a":1}', $result->outcomes[0]->content());
        self::assertSame('{"b":2}', $result->outcomes[1]->content());
        // 2 initial calls + 1 re-fired call proves only the limited call was re-sent.
        self::assertCount(3, $chat->calls());
    }

    public function testCompleteThrowsTheDeferralForAPollTick(): void
    {
        $chat = new StubChatClient();
        $chat->queueFailure(new RetryableProviderException(429, 8));
        $clock = new MockClock(new \DateTimeImmutable('2026-01-01T00:00:00Z'));

        $this->expectException(RecommendationRunRateLimitedException::class);

        (new RateLimitedCompletion($chat, $clock))
            ->complete($this->connection(), $this->request(), new NullCompletionStreamObserver(), RetryPlan::forDriver(TickDriver::Poll));
    }
}
```

- [ ] **Step 4: Run to verify it fails**

Run: `php bin/phpunit tests/Service/Recommendation/RateLimitedCompletionTest.php`
Expected: FAIL — class not found.

- [ ] **Step 5: Implement `RateLimitedCompletion`**

```php
<?php

declare(strict_types=1);

namespace App\Service\Recommendation;

use App\Service\Ai\ProviderConnection;
use App\Service\Recommendation\Exception\RecommendationRunRateLimitedException;
use Symfony\Component\Clock\ClockInterface;

/**
 * Wraps the concurrent chat client with the run's rate-limit policy (#947). For
 * a blocking plan (the worker) it waits and re-fires only the still-rate-limited
 * calls, up to the plan's retries and total-wait budget; a call retried to
 * exhaustion keeps its RetryableProviderException. For a deferring plan (poll,
 * sweep) it never waits and returns the deferral for the advancer to record.
 *
 * It re-fires the limited subset rather than the whole wave, so a paid provider
 * is not re-billed for the calls that already answered.
 */
final readonly class RateLimitedCompletion
{
    public function __construct(
        private ChatCompletionClient $chat,
        private ClockInterface $clock,
    ) {
    }

    public function complete(
        ProviderConnection $connection,
        CompletionRequest $request,
        CompletionStreamObserver $observer,
        RetryPlan $plan,
    ): string {
        $result = $this->completeMany($connection, [new ConcurrentCompletion($request, $observer)], $plan);

        if ($result->isDeferred()) {
            throw new RecommendationRunRateLimitedException($result->deferSeconds);
        }

        $outcome = $result->outcomes[0];
        if ($outcome->isFailure()) {
            throw $outcome->cause();
        }

        return $outcome->content();
    }

    /**
     * @param non-empty-list<ConcurrentCompletion> $calls
     */
    public function completeMany(ProviderConnection $connection, array $calls, RetryPlan $plan): RateLimitedResult
    {
        $outcomes = $this->chat->completeMany($connection, $calls);
        $observed = false;
        $waited = 0.0;

        // $retry === 0 is the first retry after the initial send above; the wait
        // it uses is BACKOFF[0], the "1 s" step.
        for ($retry = 0; ; $retry++) {
            $pending = $this->retryablePositions($outcomes);
            if ([] === $pending) {
                return RateLimitedResult::completed($outcomes, $observed);
            }

            $observed = true;
            $wait = $plan->waitSecondsFor($retry, $this->retryAfterAcross($outcomes, $pending));

            if (!$plan->blocks()) {
                return RateLimitedResult::deferred($wait);
            }

            if ($retry >= $plan->maxRetries()) {
                return RateLimitedResult::completed($outcomes, true);
            }

            if ($waited + $wait > $plan->budgetSeconds()) {
                return RateLimitedResult::deferred($wait);
            }

            $this->clock->sleep($wait);
            $waited += $wait;
            $outcomes = $this->refire($connection, $calls, $outcomes, $pending);
        }
    }

    /**
     * @param list<CompletionOutcome> $outcomes
     *
     * @return list<int>
     */
    private function retryablePositions(array $outcomes): array
    {
        $positions = [];
        foreach ($outcomes as $position => $outcome) {
            if ($outcome->isRetryable()) {
                $positions[] = $position;
            }
        }

        return $positions;
    }

    /**
     * @param list<CompletionOutcome> $outcomes
     * @param list<int>               $pending
     */
    private function retryAfterAcross(array $outcomes, array $pending): ?int
    {
        $seconds = null;
        foreach ($pending as $position) {
            $hint = $outcomes[$position]->retryAfterSeconds();
            if (null !== $hint) {
                $seconds = null === $seconds ? $hint : max($seconds, $hint);
            }
        }

        return $seconds;
    }

    /**
     * @param non-empty-list<ConcurrentCompletion> $calls
     * @param list<CompletionOutcome>              $outcomes
     * @param list<int>                            $pending
     *
     * @return list<CompletionOutcome>
     */
    private function refire(ProviderConnection $connection, array $calls, array $outcomes, array $pending): array
    {
        $subset = array_values(array_map(static fn (int $position): ConcurrentCompletion => $calls[$position], $pending));
        $refired = $this->chat->completeMany($connection, $subset);

        foreach ($pending as $index => $position) {
            $outcomes[$position] = $refired[$index];
        }

        return $outcomes;
    }
}
```

- [ ] **Step 6: Run to verify it passes**

Run: `php bin/phpunit tests/Service/Recommendation/RateLimitedCompletionTest.php`
Expected: PASS. If `testWorkerWaitsOneTwoFour...` expects 7 s but you see 3 s, your loop is capping at 2 retries — confirm `$retry >= $plan->maxRetries()` (3) allows retries 0,1,2.

- [ ] **Step 7: Commit**

```bash
composer check && git add -A && git commit -m "feat(#947): add RateLimitedCompletion with subset retry and defer"
```

---

### Task 6: `RunThrottle` embeddable, `RecommendationRun` wiring, migration

**Files:**
- Create: `backend/src/Entity/RunThrottle.php`
- Modify: `backend/src/Entity/RecommendationRun.php` (embed + delegating methods + resets)
- Create: `backend/migrations/Version<timestamp>.php`
- Test: `backend/tests/Entity/RunThrottleTest.php` (new), and add cases to an existing `RecommendationRun` test if one exists; otherwise cover through the entity directly.

**Interfaces:**
- Produces on `RecommendationRun`:
  - `mustWaitBeforeRetry(\DateTimeImmutable $now): bool`
  - `deferRetryUntil(\DateTimeImmutable $when): void`
  - `reduceWaveConcurrency(int $configuredCap): void`
  - `waveConcurrencyCap(int $configuredCap): int`
  - `getRetryNotBefore(): ?\DateTimeImmutable`
- `resume()` clears both throttle fields; `recordBatchWinners()`, `recordProfile()`, `complete()` clear the deferral (concurrency persists).

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\RunThrottle;
use PHPUnit\Framework\TestCase;

final class RunThrottleTest extends TestCase
{
    public function testAFreshThrottleNeitherWaitsNorReducesTheCap(): void
    {
        $throttle = new RunThrottle();

        self::assertFalse($throttle->mustWait(new \DateTimeImmutable('2026-01-01T00:00:00Z')));
        self::assertSame(8, $throttle->effectiveCap(8));
    }

    public function testItGatesUntilTheDeferralTimePasses(): void
    {
        $throttle = new RunThrottle();
        $throttle->deferUntil(new \DateTimeImmutable('2026-01-01T00:00:10Z'));

        self::assertTrue($throttle->mustWait(new \DateTimeImmutable('2026-01-01T00:00:05Z')));
        self::assertFalse($throttle->mustWait(new \DateTimeImmutable('2026-01-01T00:00:10Z')));
        self::assertFalse($throttle->mustWait(new \DateTimeImmutable('2026-01-01T00:00:11Z')));
    }

    public function testHalvingFloorsAtOneAndNeverClimbs(): void
    {
        $throttle = new RunThrottle();

        $throttle->reduceConcurrency(8);
        self::assertSame(4, $throttle->effectiveCap(8));

        $throttle->reduceConcurrency(8);
        self::assertSame(2, $throttle->effectiveCap(8));

        $throttle->reduceConcurrency(8);
        self::assertSame(1, $throttle->effectiveCap(8));

        $throttle->reduceConcurrency(8);
        self::assertSame(1, $throttle->effectiveCap(8)); // floor holds
    }

    public function testAnOddCapHalvesDownward(): void
    {
        $throttle = new RunThrottle();
        $throttle->reduceConcurrency(3);

        self::assertSame(1, $throttle->effectiveCap(3));
    }

    public function testClearDeferralLeavesTheReducedCap(): void
    {
        $throttle = new RunThrottle();
        $throttle->reduceConcurrency(8);
        $throttle->deferUntil(new \DateTimeImmutable('2026-01-01T00:00:10Z'));

        $throttle->clearDeferral();

        self::assertFalse($throttle->mustWait(new \DateTimeImmutable('2026-01-01T00:00:05Z')));
        self::assertSame(4, $throttle->effectiveCap(8));
    }

    public function testResetRestoresFullConcurrencyAndClearsTheGate(): void
    {
        $throttle = new RunThrottle();
        $throttle->reduceConcurrency(8);
        $throttle->deferUntil(new \DateTimeImmutable('2026-01-01T00:00:10Z'));

        $throttle->reset();

        self::assertSame(8, $throttle->effectiveCap(8));
        self::assertFalse($throttle->mustWait(new \DateTimeImmutable('2026-01-01T00:00:05Z')));
    }
}
```

- [ ] **Step 2: Run to verify it fails**

Run: `php bin/phpunit tests/Entity/RunThrottleTest.php`
Expected: FAIL — class not found.

- [ ] **Step 3: Implement `RunThrottle`**

```php
<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A run's rate-limit throttle (#947): when the next provider call may fire, and
 * the wave concurrency lowered after a 429. Embedded, unprefixed columns, like
 * RunBatchProgress and RunProfile — the two belong to one concern and keep
 * RecommendationRun's field count down.
 */
#[ORM\Embeddable]
class RunThrottle
{
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $retryNotBefore = null;

    #[ORM\Column(nullable: true)]
    private ?int $reducedConcurrency = null;

    public function deferUntil(\DateTimeImmutable $when): void
    {
        $this->retryNotBefore = $when;
    }

    public function mustWait(\DateTimeImmutable $now): bool
    {
        return null !== $this->retryNotBefore && $now < $this->retryNotBefore;
    }

    public function retryNotBefore(): ?\DateTimeImmutable
    {
        return $this->retryNotBefore;
    }

    public function clearDeferral(): void
    {
        $this->retryNotBefore = null;
    }

    public function reduceConcurrency(int $configuredCap): void
    {
        $base = $this->reducedConcurrency ?? $configuredCap;
        $this->reducedConcurrency = max(1, intdiv($base, 2));
    }

    public function effectiveCap(int $configuredCap): int
    {
        return min($configuredCap, $this->reducedConcurrency ?? $configuredCap);
    }

    public function reset(): void
    {
        $this->retryNotBefore = null;
        $this->reducedConcurrency = null;
    }
}
```

- [ ] **Step 4: Wire it into `RecommendationRun`**

Add the embed near the other embeddables (after `RunProfile`):

```php
    #[ORM\Embedded(class: RunThrottle::class, columnPrefix: false)]
    private RunThrottle $throttle;
```

Initialise it in the constructor (beside the others):

```php
        $this->throttle = new RunThrottle();
```

Add the delegating methods (place them near `getStreamedChars()`):

```php
    public function mustWaitBeforeRetry(\DateTimeImmutable $now): bool
    {
        return $this->throttle->mustWait($now);
    }

    public function deferRetryUntil(\DateTimeImmutable $when): void
    {
        $this->guardStatus(self::STATUS_RUNNING, 'defer a recommendation run');
        $this->throttle->deferUntil($when);
    }

    public function reduceWaveConcurrency(int $configuredCap): void
    {
        $this->guardStatus(self::STATUS_RUNNING, 'reduce the wave concurrency of');
        $this->throttle->reduceConcurrency($configuredCap);
    }

    public function waveConcurrencyCap(int $configuredCap): int
    {
        return $this->throttle->effectiveCap($configuredCap);
    }

    public function getRetryNotBefore(): ?\DateTimeImmutable
    {
        return $this->throttle->retryNotBefore();
    }
```

In `recordBatchWinners()` and `recordProfile()` and `complete()`, add after the existing resets:

```php
        $this->throttle->clearDeferral();
```

In `resume()`, add after the existing resets:

```php
        $this->throttle->reset();
```

- [ ] **Step 5: Run the entity test and the existing run/advancer suite**

Run: `php bin/phpunit tests/Entity/RunThrottleTest.php tests/Service/Recommendation/RecommendationRunAdvancerTest.php`
Expected: PASS (the advancer suite still green — schema is built from ORM metadata, so the new columns exist in the test DB automatically).

- [ ] **Step 6: Generate and hand-verify the migration**

Create `backend/migrations/Version<timestamp>.php` (use a timestamp later than `Version20260913180658`, e.g. today's date `Version20260916HHMMSS`):

```php
<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Add the run throttle columns for provider rate-limit handling (#947):
 * retry_not_before gates the next tick, reduced_concurrency lowers the wave.
 * PLATFORM-AWARE DDL — tests build schema from ORM metadata and never run a
 * migration, so a dialect error here is caught only by CI's migrate-from-empty leg.
 */
final class Version20260916HHMMSS extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add recommendation_run throttle columns for 429 retry and reduced concurrency (#947).';
    }

    public function up(Schema $schema): void
    {
        $this->assertSupportedPlatform();
        $platform = $this->connection->getDatabasePlatform();

        if ($platform instanceof AbstractMySQLPlatform) {
            $this->addSql('ALTER TABLE recommendation_run ADD retry_not_before DATETIME DEFAULT NULL, ADD reduced_concurrency INT DEFAULT NULL');

            return;
        }

        $this->addSql('ALTER TABLE recommendation_run ADD COLUMN retry_not_before DATETIME DEFAULT NULL');
        $this->addSql('ALTER TABLE recommendation_run ADD COLUMN reduced_concurrency INTEGER DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->assertSupportedPlatform();
        $platform = $this->connection->getDatabasePlatform();

        if ($platform instanceof AbstractMySQLPlatform) {
            $this->addSql('ALTER TABLE recommendation_run DROP retry_not_before, DROP reduced_concurrency');

            return;
        }

        $this->addSql('ALTER TABLE recommendation_run DROP COLUMN retry_not_before');
        $this->addSql('ALTER TABLE recommendation_run DROP COLUMN reduced_concurrency');
    }

    private function assertSupportedPlatform(): void
    {
        $platform = $this->connection->getDatabasePlatform();
        $this->abortIf(
            !($platform instanceof AbstractMySQLPlatform) && !($platform instanceof SQLitePlatform),
            sprintf('No DDL defined for platform %s; only MySQL and SQLite are supported.', $platform::class),
        );
    }
}
```

Verify the DDL matches what Doctrine expects on both platforms (the DATETIME type for `retry_not_before` must match the `datetime_immutable` mapping). Run:

```bash
bin/console doctrine:migrations:migrate --no-interaction --env=test
bin/console doctrine:schema:validate --env=test
```

Expected: schema validates clean ("in sync"). If `schema:validate` reports a diff on the two columns, adjust the DDL to match its expected SQL exactly.

- [ ] **Step 7: Commit**

```bash
composer check && git add -A && git commit -m "feat(#947): persist run throttle (retry gate and reduced concurrency)"
```

---

### Task 7: The retry gate in the advancer

**Files:**
- Modify: `backend/src/Service/Recommendation/RecommendationRunAdvancer.php` (`tickActiveRun` — add the gate)
- Test: `backend/tests/Service/Recommendation/RecommendationRunAdvancerTest.php`

**Interfaces:**
- Consumes: `RecommendationRun::mustWaitBeforeRetry()` (Task 6), `$this->clock`.
- Produces: a tick whose run is still within its "retry not before" window makes no provider call and returns the running report.

- [ ] **Step 1: Write the failing test**

Add to `RecommendationRunAdvancerTest.php`. Follow the file's existing helpers (`seedMultiBatchFixture`, `startSnapshotAndDistill`, `stubChatClient`, `activeRun`, `runs`). The gate is proven by setting `retry_not_before` in the future via direct DBAL (as the cancellation test writes status directly), then asserting no provider call happens.

```php
    public function testATickWithinItsRetryWindowMakesNoProviderCall(): void
    {
        $this->seedMultiBatchFixture();
        $run = $this->startSnapshotAndDistill(); // run is RUNNING, ready for the batch phase
        $runId = $run->getId() ?? 0;

        // Defer far into the future, straight to the DB — the ticking side holds
        // an entity that predates this write, exactly like the cancellation race.
        $this->em->getConnection()->update(
            'recommendation_run',
            ['retry_not_before' => '2099-01-01 00:00:00'],
            ['id' => $runId],
        );
        $this->em->clear();

        $callsBefore = \count($this->stubChatClient()->calls());
        $report = $this->advancer()->advance($this->user, TickDriver::Worker);

        self::assertSame('running', $report->status);
        self::assertCount($callsBefore, $this->stubChatClient()->calls()); // no new provider call
    }
```

- [ ] **Step 2: Run to verify it fails**

Run: `php bin/phpunit tests/Service/Recommendation/RecommendationRunAdvancerTest.php --filter testATickWithinItsRetryWindowMakesNoProviderCall`
Expected: FAIL — the tick fires a batch call (StubChatClient throws "no queued response left", or the count rises).

- [ ] **Step 3: Implement the gate**

In `tickActiveRun`, after the PENDING/snapshot branch and before the distill branch, add:

```php
        if ($run->mustWaitBeforeRetry($this->clock->now())) {
            return RecommendationRunReport::fromRun($run);
        }
```

- [ ] **Step 4: Run to verify it passes**

Run: `php bin/phpunit tests/Service/Recommendation/RecommendationRunAdvancerTest.php --filter testATickWithinItsRetryWindowMakesNoProviderCall`
Expected: PASS. Then run the whole advancer test file to confirm no regression.

- [ ] **Step 5: Commit**

```bash
composer check && git add -A && git commit -m "feat(#947): gate a tick until its retry-not-before time"
```

---

### Task 8: Single-call phases retry and defer

**Files:**
- Modify: `backend/src/Service/Recommendation/RecommendationProviderCall.php` (inject `RateLimitedCompletion`, add `RetryPlan` param, route through it)
- Modify: `backend/src/Service/Recommendation/RecommendationProfileDistiller.php` (accept + pass `RetryPlan`)
- Modify: `backend/src/Service/Recommendation/RecommendationConsolidationResolver.php` (accept + pass `RetryPlan`)
- Modify: `backend/src/Service/Recommendation/RecommendationRunAdvancer.php` (`tickActiveRun` builds the plan; `distillTick`/`consolidateTick` pass it and catch the deferral + the retryable failure)
- Test: `backend/tests/Service/Recommendation/RecommendationRunAdvancerTest.php`

**Interfaces:**
- Consumes: `RateLimitedCompletion::complete(..., RetryPlan)` (Task 5), `RecommendationRunRateLimitedException` (Task 5), `RetryableProviderException` (Task 1), `RetryPlan::forDriver` (Task 4), `RecommendationRun::deferRetryUntil` (Task 6).
- Produces: `RecommendationProviderCall::complete(AiProviderSettings, CompletionRequest, RecordedCall, RetryPlan): string`; distiller/consolidation `resolve/distill(..., RetryPlan $plan)`.

- [ ] **Step 1: Write the failing tests**

Add to `RecommendationRunAdvancerTest.php`. Use the distill phase (the first provider call of a run). `startSnapshot()` (or the existing helper that reaches the distill phase without answering it) leaves the run at `distillPending`. Confirm the exact helper name in the file; below assumes `seedMultiBatchFixture()` + `starter()->start()` + one `advance()` reaches the snapshot, and the next tick is the distill call.

```php
    public function testAPollDistillTickDefersOnA429WithoutStrikingOrCalling(): void
    {
        $this->seedMultiBatchFixture();
        $this->starter()->start($this->user);
        $this->advancer()->advance($this->user, TickDriver::Poll); // snapshot
        $this->stubChatClient()->queueFailure(new \App\Service\Ai\Exception\RetryableProviderException(429, 30));

        $report = $this->advancer()->advance($this->user, TickDriver::Poll); // distill, rate limited

        self::assertSame('running', $report->status);
        $run = $this->activeRun();
        self::assertSame(0, $run->getTransportFailures());       // no strike burned
        self::assertNotNull($run->getRetryNotBefore());          // deferral recorded
        self::assertFalse($run->isDistilled());                  // profile not written
    }

    public function testAWorkerDistillTickRetriesA429AndRecovers(): void
    {
        $this->seedMultiBatchFixture();
        $this->swapInMockClock(); // see helper below — avoids a real sleep
        $this->starter()->start($this->user);
        $this->advancer()->advance($this->user, TickDriver::Worker); // snapshot
        $this->stubChatClient()->queueFailure(new \App\Service\Ai\Exception\RetryableProviderException(429));
        $this->queueDistillReply('a profile');

        $report = $this->advancer()->advance($this->user, TickDriver::Worker); // distill, recovers

        self::assertSame('running', $report->status);
        self::assertTrue($this->activeRun()->isDistilled());
        self::assertSame(0, $this->activeRun()->getTransportFailures());
    }
```

Add a helper that swaps a `MockClock` into the container so a worker tick does not sleep in real time (mirrors how the file swaps `LockFactory`):

```php
    private function swapInMockClock(): void
    {
        self::getContainer()->set(
            \Symfony\Component\Clock\ClockInterface::class,
            new \Symfony\Component\Clock\MockClock(new \DateTimeImmutable('2026-01-01T00:00:00Z')),
        );
    }
```

Confirm the container id for the clock is `Symfony\Component\Clock\ClockInterface` (autowired default). If services resolve a different id, set that one. Verify `RateLimitedCompletion` and the advancer both receive the swapped instance (they must — both autowire `ClockInterface`).

- [ ] **Step 2: Run to verify they fail**

Run: `php bin/phpunit tests/Service/Recommendation/RecommendationRunAdvancerTest.php --filter DistillTick`
Expected: FAIL — a 429 currently strikes the run (or the signatures do not yet accept a plan).

- [ ] **Step 3: Route `RecommendationProviderCall` through `RateLimitedCompletion`**

Replace its constructor and body:

```php
final readonly class RecommendationProviderCall
{
    public function __construct(
        private RateLimitedCompletion $completion,
        private ProviderConnectionFactory $connections,
    ) {
    }

    public function complete(
        AiProviderSettings $settings,
        CompletionRequest $request,
        RecordedCall $recordedCall,
        RetryPlan $plan,
    ): string {
        try {
            return $this->completion->complete(
                $this->connections->forSettings($settings),
                $request,
                $recordedCall,
                $plan,
            );
        } catch (\Throwable $e) {
            $recordedCall->abortAfterTransportFailure($e->getMessage());

            throw $e;
        }
    }
}
```

- [ ] **Step 4: Thread the plan through the distiller and consolidation resolver**

In `RecommendationProfileDistiller::distill`, add `RetryPlan $plan` as the last parameter and pass it to `providerCall->complete(...)`:

```php
    public function distill(
        RecommendationRun $run,
        AiProviderSettings $settings,
        int $userId,
        EffectiveRecommendationSettings $effectiveSettings,
        RetryPlan $plan,
    ): ProfileDistillationOutcome {
        // ...unchanged until the provider call...
        $content = $this->providerCall->complete(
            $settings,
            $this->requestFactory->create($settings, $messages, 1, RecommendationResponseSchema::Distillation),
            $recordedCall,
            $plan,
        );
        // ...unchanged...
    }
```

In `RecommendationConsolidationResolver::resolve`, add `RetryPlan $plan` as the last parameter and pass it to `providerCall->complete(...)` the same way.

- [ ] **Step 5: Build the plan and handle deferral/strike in the advancer**

In `tickActiveRun`, build the plan once and pass it to the phase methods:

```php
        $plan = RetryPlan::forDriver($driver);

        if ($run->progress()->distillPending) {
            return $this->distillTick($run, $user, $settings, $plan);
        }

        if ($run->progress()->isConsolidationPhase) {
            return $this->consolidateTick($run, $user, $settings, $plan);
        }

        return $this->providerTick($run, $user, $settings, $driver);
```

(`providerTick` gets the plan in Task 9; for now it still builds its own or is unchanged — leave its signature as is this task.)

Update `distillTick` to accept `RetryPlan $plan`, pass it to `distiller->distill(...)`, and add a deferral catch before the existing transport catch, and add `RetryableProviderException` to the strike catch:

```php
    private function distillTick(
        RecommendationRun $run,
        User $user,
        AiProviderSettings $settings,
        RetryPlan $plan,
    ): RecommendationRunReport {
        $userId = $this->requireUserId($user);
        $effectiveSettings = $this->settingsResolver->forUser($user);

        try {
            $outcome = $this->distiller->distill($run, $settings, $userId, $effectiveSettings, $plan);
        } catch (RecommendationRunRateLimitedException $e) {
            return $this->deferRun($run, $e);
        } catch (ProviderUnreachableException | CredentialsRejectedException | RetryableProviderException $e) {
            $this->recordTransportFailure($run, $settings, $e->getMessage());

            throw $e;
        }
        // ...unchanged tail...
    }
```

Add the shared defer helper:

```php
    /**
     * Record "retry not before now + the wait" and return at once. No strike:
     * a deferral is a wait, not a failure (#947).
     */
    private function deferRun(RecommendationRun $run, RecommendationRunRateLimitedException $rateLimited): RecommendationRunReport
    {
        $this->checkpoint->guard($run);
        $run->deferRetryUntil($this->clock->now()->add(self::waitInterval($rateLimited->waitSeconds())));
        $this->entityManager->flush();

        return RecommendationRunReport::fromRun($run);
    }

    private static function waitInterval(float $seconds): \DateInterval
    {
        return new \DateInterval('PT' . max(0, (int) ceil($seconds)) . 'S');
    }
```

Apply the identical deferral + `RetryableProviderException` catch to `consolidateTick` (accept `RetryPlan $plan`, pass to `consolidationResolver->resolve(...)`).

Add the imports to the advancer:

```php
use App\Service\Ai\Exception\RetryableProviderException;
use App\Service\Recommendation\Exception\RecommendationRunRateLimitedException;
```

- [ ] **Step 6: Run to verify they pass**

Run: `php bin/phpunit tests/Service/Recommendation/RecommendationRunAdvancerTest.php`
Expected: PASS (new distill cases and all existing).

- [ ] **Step 7: Commit**

```bash
composer check && git add -A && git commit -m "feat(#947): distillation and consolidation retry then defer on a 429"
```

---

### Task 9: Batch wave retry, defer, and concurrency halving

**Files:**
- Create: `backend/src/Service/Recommendation/BatchWaveResult.php`
- Modify: `backend/src/Service/Recommendation/RecommendationBatchWave.php` (inject `RateLimitedCompletion`, accept `RetryPlan`, route the round, defer, accumulate observed, return `BatchWaveResult`)
- Modify: `backend/src/Service/Recommendation/RecommendationRunAdvancer.php` (`providerTick` builds/uses the plan; halve on observed 429; defer; strike on `RetryableProviderException`; `waveSize` clamps to the reduced cap)
- Test: `backend/tests/Service/Recommendation/RecommendationRunAdvancerTest.php`

**Interfaces:**
- Consumes: `RateLimitedCompletion::completeMany(..., RetryPlan): RateLimitedResult` (Task 5), `RecommendationRun::reduceWaveConcurrency/waveConcurrencyCap` (Task 6), `RetryableProviderException`, `RecommendationRunRateLimitedException`.
- Produces: `RecommendationBatchWave::resolve(..., RetryPlan $plan): BatchWaveResult`; `BatchWaveResult` with `list winners`, `bool rateLimitObserved`.

- [ ] **Step 1: Write the failing tests**

Add to `RecommendationRunAdvancerTest.php`. Use a multi-batch fixture past the warm-up wave so a real fan-out wave runs. Reuse the file's pattern of banking the warm-up batch first.

```php
    public function testAPollBatchWaveDefersAndHalvesTheConcurrencyOnA429(): void
    {
        $this->seedForcedBatchCountFixture(entryCount: 20, batchCount: 4);
        $this->setBatchConcurrency(4);
        $this->starter()->start($this->user);
        $this->advancer()->advance($this->user, TickDriver::Poll); // snapshot
        $this->queueDistillReply();
        $this->advancer()->advance($this->user, TickDriver::Poll); // distill
        $batches = $this->activeRun()->getCandidateBatches();
        $this->stubChatClient()->queueContent(json_encode([
            'recommendations' => [['id' => $batches[0][0], 'score' => 90, 'reason' => 'warm']],
        ], \JSON_THROW_ON_ERROR));
        $this->advancer()->advance($this->user, TickDriver::Poll); // warm-up wave banks batch 0

        // The fan-out wave over batches 1..3 meets a 429 on its first call.
        $this->stubChatClient()->queueFailure(new \App\Service\Ai\Exception\RetryableProviderException(429, 20));

        $report = $this->advancer()->advance($this->user, TickDriver::Poll);

        self::assertSame('running', $report->status);
        $run = $this->activeRun();
        self::assertSame(0, $run->getTransportFailures());     // deferral, not a strike
        self::assertNotNull($run->getRetryNotBefore());
        self::assertSame(1, $run->progress()->batchesDone);    // nothing new banked (warm-up only)
        self::assertSame(2, $run->waveConcurrencyCap(4));      // halved from 4
    }

    public function testAWorkerBatchWaveHalvesButStillBanksWhenTheRetryRecovers(): void
    {
        $this->seedForcedBatchCountFixture(entryCount: 20, batchCount: 4);
        $this->setBatchConcurrency(4);
        $this->swapInMockClock();
        $this->starter()->start($this->user);
        $this->advancer()->advance($this->user, TickDriver::Worker);
        $this->queueDistillReply();
        $this->advancer()->advance($this->user, TickDriver::Worker);
        $batches = $this->activeRun()->getCandidateBatches();
        $this->stubChatClient()->queueContent(json_encode([
            'recommendations' => [['id' => $batches[0][0], 'score' => 90, 'reason' => 'warm']],
        ], \JSON_THROW_ON_ERROR));
        $this->advancer()->advance($this->user, TickDriver::Worker); // warm-up

        // Fan-out over batches 1..3: call for position 1 is limited once, then recovers.
        $this->stubChatClient()->queueContent(json_encode([
            'recommendations' => [['id' => $batches[1][0], 'score' => 91, 'reason' => 'one']],
        ], \JSON_THROW_ON_ERROR));
        $this->stubChatClient()->queueFailure(new \App\Service\Ai\Exception\RetryableProviderException(429));
        $this->stubChatClient()->queueContent(json_encode([
            'recommendations' => [['id' => $batches[3][0], 'score' => 93, 'reason' => 'three']],
        ], \JSON_THROW_ON_ERROR));
        // Retry re-fires only the limited call (position 2).
        $this->stubChatClient()->queueContent(json_encode([
            'recommendations' => [['id' => $batches[2][0], 'score' => 92, 'reason' => 'two']],
        ], \JSON_THROW_ON_ERROR));

        $report = $this->advancer()->advance($this->user, TickDriver::Worker);

        self::assertSame(4, $report->batchesDone);              // whole wave banked
        self::assertSame(0, $this->activeRun()->getTransportFailures());
        self::assertSame(2, $this->activeRun()->waveConcurrencyCap(4)); // halved once
    }
```

Note on the second test: the fan-out fires batches 1,2,3 as one round; queue order is by call order. Adjust which position is queued as the failure to match the wave's ordering if the assertion on re-fire count differs — the invariant to prove is: the run banks all four batches, burns no strike, and the cap halved to 2.

- [ ] **Step 2: Run to verify they fail**

Run: `php bin/phpunit tests/Service/Recommendation/RecommendationRunAdvancerTest.php --filter BatchWave`
Expected: FAIL — a 429 currently strikes the wave.

- [ ] **Step 3: Create `BatchWaveResult`**

```php
<?php

declare(strict_types=1);

namespace App\Service\Recommendation;

/**
 * A resolved batch wave: the winners in plan order, and whether any 429 was
 * observed this tick — which halves the run's wave concurrency (#947).
 */
final readonly class BatchWaveResult
{
    /**
     * @param list<list<array{id: int, score: int, reason: string}>> $winners
     */
    public function __construct(
        public array $winners,
        public bool $rateLimitObserved,
    ) {
    }
}
```

- [ ] **Step 4: Route the wave round through `RateLimitedCompletion`**

In `RecommendationBatchWave`:

Change the constructor dependency from `ChatCompletionClient $chat` to `RateLimitedCompletion $completion`.

Change `resolve()` to accept `RetryPlan $plan` (last parameter) and return `BatchWaveResult`. Thread an `observed` flag through the round loop and pass `$plan` to `sendRound`. At the end, return `new BatchWaveResult($this->degradeUnresolved($winners, $pending), $observed)`.

Change `completeRound` to call the rate-limited client and interpret the result — on a deferral it settles the round's rows and throws; otherwise it returns the outcomes (and the observed flag is read by the caller):

```php
    /**
     * @param non-empty-list<ConcurrentCompletion> $calls
     * @param list<RecordedCall>                   $recordedCalls
     */
    private function completeRound(AiProviderSettings $settings, array $calls, array $recordedCalls, RetryPlan $plan): RateLimitedResult
    {
        try {
            return $this->completion->completeMany($this->connections->forSettings($settings), $calls, $plan);
        } catch (\Throwable $e) {
            foreach ($recordedCalls as $recordedCall) {
                $recordedCall->abortAfterTransportFailure($e->getMessage());
            }

            throw $e;
        }
    }
```

In `sendRound` (which calls `completeRound`), interpret the `RateLimitedResult`:

```php
        $result = $this->completeRound($settings, $calls, $recordedCalls, $plan);

        if ($result->isDeferred()) {
            foreach ($recordedCalls as $recordedCall) {
                $recordedCall->abortAfterTransportFailure('Provider rate limited; deferring.');
            }

            throw new RecommendationRunRateLimitedException($result->deferSeconds);
        }

        $outcomes = $result->outcomes;
        $this->guardWaveTransport($recordedCalls, $outcomes);

        return ['replies' => $this->repliesByPosition($pending, $outcomes, $recordedCalls), 'observed' => $result->rateLimitObserved];
```

Adjust `sendRound`'s return type to carry both replies and the observed flag, and have `resolve()`'s loop OR the observed flag across rounds. (Keep the change small: return `array{replies: array<int, array{content: string, call: RecordedCall}>, observed: bool}` and read both in the loop.)

Add the imports:

```php
use App\Service\Recommendation\Exception\RecommendationRunRateLimitedException;
```

`guardWaveTransport` is unchanged: a worker-exhausted `RetryableProviderException` is a failure outcome, so it throws it as before, and the advancer's strike path catches it.

- [ ] **Step 5: Halve, defer, and strike in `providerTick`; clamp `waveSize`**

Give `providerTick` the plan (pass it from `tickActiveRun`: `return $this->providerTick($run, $user, $settings, $driver, $plan);`). Then:

```php
    private function providerTick(
        RecommendationRun $run,
        User $user,
        AiProviderSettings $settings,
        TickDriver $driver,
        RetryPlan $plan,
    ): RecommendationRunReport {
        $this->markFirstBatchBeforeCallingProvider($run);
        $userId = $this->requireUserId($user);
        $effectiveSettings = $this->settingsResolver->forUser($user);
        $waveSize = $this->waveSize($run, $settings, $driver);

        try {
            $result = $this->batchWave->resolve($run, $settings, $effectiveSettings, $userId, $waveSize, $plan);
        } catch (RecommendationRunRateLimitedException $e) {
            $this->halveWaveConcurrency($run, $settings);

            return $this->deferRun($run, $e);
        } catch (RetryableProviderException $e) {
            $this->halveWaveConcurrency($run, $settings);
            $this->recordTransportFailure($run, $settings, $e->getMessage());

            throw $e;
        } catch (ProviderUnreachableException | CredentialsRejectedException $e) {
            $this->recordTransportFailure($run, $settings, $e->getMessage());

            throw $e;
        }

        if ($result->rateLimitObserved) {
            $this->halveWaveConcurrency($run, $settings);
        }

        return $this->pickEndingAfterWave($run, $result->winners);
    }
```

Note: `deferRun` (added in Task 8) calls `checkpoint->guard` + flush. `halveWaveConcurrency` mutates before `deferRun` flushes, so the halved cap is persisted with the deferral. For the strike branch, `recordTransportFailure` flushes; the halve mutates before it. Add the helper:

```php
    private function halveWaveConcurrency(RecommendationRun $run, AiProviderSettings $settings): void
    {
        $run->reduceWaveConcurrency($this->configuredCap($settings));
    }

    private function configuredCap(AiProviderSettings $settings): int
    {
        return min($settings->batchConcurrency(), AiProviderSettings::MAX_BATCH_CONCURRENCY);
    }
```

Delete the now-unused `resolveWave` method (its try/catch moved into `providerTick`), or repurpose it — do not leave a dead private method (PHPMD/readability).

Update `waveSize` to clamp to the reduced cap:

```php
        $batchesRemaining = \count($run->getCandidateBatches()) - $run->progress()->nextBatchIndex;

        return min(
            $this->effectiveCap($settings, $driver),
            $run->waveConcurrencyCap($this->configuredCap($settings)),
            $batchesRemaining,
        );
```

- [ ] **Step 6: Run to verify they pass**

Run: `php bin/phpunit tests/Service/Recommendation/RecommendationRunAdvancerTest.php`
Expected: PASS (new batch cases and all existing).

- [ ] **Step 7: Commit**

```bash
composer check && git add -A && git commit -m "feat(#947): batch wave retries, defers, and halves concurrency on a 429"
```

---

### Task 10: Full-suite verification and mutation gate

**Files:** none (verification only).

- [ ] **Step 1: Run the whole backend suite (SQLite)**

Run: `php bin/phpunit`
Expected: green.

- [ ] **Step 2: Run all gates**

Run: `bin/console cache:warmup && composer check && composer md`
Expected: cs, stan, tramp, md all clean. Fix any finding in a file you touched (design fix, not threshold tuning).

- [ ] **Step 3: Scan the dev log**

Run: `ls -t var/log/dev-*.log | head -1 | xargs tail -n 50 | jq .`
Expected: no new deprecations or swallowed errors from the touched paths.

- [ ] **Step 4: Mutation testing over the diff**

Run: `composer infection:diff`
Expected: no escaped mutants on the new code. If a mutant escapes on the backoff schedule, the halving, the floor of 1, or the strike accounting, add the killing test it names and re-run. Prove worker isolation is intact if the score looks implausibly high (`infection --noop` — every noop mutant must survive).

- [ ] **Step 5: Migration leg on both dialects (parity with CI)**

Run (SQLite already done in Task 6). For MySQL, if the Docker stack is up:

```bash
docker compose exec php bin/console doctrine:migrations:migrate --no-interaction
docker compose exec php bin/console doctrine:schema:validate
```

Expected: migrates from the current head and validates in sync. (Skip if the reviewer will run the MySQL leg in CI; note it in the PR.)

- [ ] **Step 6: Final commit if anything changed**

```bash
composer check && git add -A && git commit -m "test(#947): cover escaped mutants and verify gates"
```

---

## Self-Review (completed by plan author)

**Spec coverage:** exception (T1), outcome flag (T2), guardStatus + Retry-After (T3), RetryPlan/backoff/budget (T4), subset retry + defer + budget cutoff + exhaustion (T5), RunThrottle + fields + halve/floor/gate/reset + migration (T6), gate (T7), single-call retry/defer/strike (T8), batch retry/defer/halve/strike + waveSize clamp (T9), acceptance + mutation (T10). Model-listing untouched (no task edits it — verified in scope). No frontend task (correct).

**Placeholder scan:** none — every step has concrete code or an exact command.

**Type consistency:** `RateLimitedResult` (`completed`/`deferred`/`isDeferred`/`outcomes`/`rateLimitObserved`/`deferSeconds`), `BatchWaveResult` (`winners`/`rateLimitObserved`), `RetryPlan` (`forDriver`/`blocks`/`maxRetries`/`budgetSeconds`/`waitSecondsFor`), `RunThrottle` (`deferUntil`/`mustWait`/`clearDeferral`/`reduceConcurrency`/`effectiveCap`/`reset`), and the `RecommendationRun` delegators (`mustWaitBeforeRetry`/`deferRetryUntil`/`reduceWaveConcurrency`/`waveConcurrencyCap`/`getRetryNotBefore`) are used consistently across T5–T9.

**Open item flagged for review:** the "3 retries with 1/2/4" reading of "3 attempts per call per tick" (see Global Constraints). If the intent was 3 *total* sends (waits 1, 2 only), adjust `RetryPlan::MAX_RETRIES` to 2 and drop the 4 s backoff test.
