# A rejected request fails the run at once — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development to implement this
> plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A provider that rejects a request as wrong (400, 404, 422 …) ends the run on that first answer with the
provider's reason. No retry, no strike, no wait.

**Spec:** GitHub issue #1387. Second of three; starts only after #1386 is merged, and #1388 starts only after
this is.

**Architecture:** A new typed failure, `ProviderRejectedRequestException`, separates "the request is wrong"
from "the provider is unreachable or busy". Both completion clients (LLM and JEV) throw it for a rejecting
status; the two places that turn provider failures into strikes (`ProfileRunTick`, `Run/TickPhases`) catch it
ahead of the strike path and fail the run. Everything between (call recording, the atomic-wave rule, the
rate-limit loop) already treats any non-retryable failure alike and needs no change.

**Tech stack:** PHP 8.4, Symfony 7.4, PHPUnit 12. Commands run from `backend/`.

## Global constraints

- `CLAUDE.md` Clean Code rules; `composer check`, `composer md`, both suite legs, `composer infection:diff`.
- Branch `fix/1387-rejected-request-fails-at-once` off the `develop` that holds #1386. Commits `type(#1387): …`.
- Build on what #1386 actually merged, not on this plan's memory of it: read `ErrorBody`,
  `ProviderErrorReason` and `collectErrorBody()` as they are on `develop` first.

## Decisions (mine, not Lars's — say so in the PR body)

- **Which statuses reject:** every 4xx except 401 and 403 (credentials), 408 and 425 (timing: they stay a
  strike), and 429 (retryable). So 400, 402, 404, 405, 409, 410, 413, 415, 422 and the rest fail at once.
  402 is deliberate: OpenRouter answers it for an empty balance, and three retries do not top it up.
- **3xx and unclassified 5xx (500, 501 …) stay as they are:** a strike, with #1386's reason.
- **JEV is included.** `HttpSystemOneClient` reports 400/422 as `ProviderUnreachableException` and so has the
  same three-retries bug. Both clients share one status rule.
- **Wording:** `That provider refused the request (status 400): <reason>` — `RefusalMessage`'s sentence, which
  JEV already shows. The run's error stays wrapped: `The AI provider at <base url> failed: That provider
  refused the request (status 400): <reason>`.
- **The run-log verdict stays `transport-failed`** with the reason in `error_detail`. A fourth `CallVerdict`
  would reach the database, the debug-log JSON and the frontend for a label; not worth it here. Offer it as a
  follow-up in the PR body.
- **A rejected recommendation run does not rethrow.** The strike path rethrows so the driver logs it; a
  rejection is fully told by the failed run, so `TickPhases` returns the failed run's report.

## Files

| File | Change |
|---|---|
| `src/Service/Ai/Exception/ProviderRejectedRequestException.php` | Create |
| `src/Service/Ai/Support/RejectingStatus.php` | Create: the one rule for "this status rejects the request" |
| `src/Service/Recommendation/Support/RefusalMessage.php` | Moved from `Jev/Support/` (both engines use it) |
| `…/ChatCompletionClient/OpenAiCompatibleChatClient.php` | A rejecting status throws the new exception |
| `…/Jev/SystemOneClient/HttpSystemOneClient.php` | Same |
| `src/Service/Recommendation/Profile/ProfileRunTick.php`, `ProfileRunFailure.php` | Fail at once |
| `src/Service/Recommendation/Run/TickPhases.php` | Fail at once |
| `docs/recommendations-runs.md` | Rejection vs transport failure |

---

### Task 1: The exception and the status rule

**Interfaces — produces:**
- `new ProviderRejectedRequestException(int $status, string $message)`, `status(): int`. `final`, extends
  `\RuntimeException`, in `App\Service\Ai\Exception`.
- `RejectingStatus::matches(int $status): bool` in `App\Service\Ai\Support` (static-only helper, private
  constructor, like `ResponseByteCap`).

- [ ] **Step 1: Failing test** `tests/Service/Ai/Support/RejectingStatusTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Ai\Support;

use App\Service\Ai\Support\RejectingStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RejectingStatusTest extends TestCase
{
    /** @return iterable<string, array{int}> */
    public static function rejectingStatuses(): iterable
    {
        yield 'bad request' => [400];
        yield 'payment required' => [402];
        yield 'not found' => [404];
        yield 'unprocessable' => [422];
        yield 'the last 4xx' => [499];
    }

    /** @return iterable<string, array{int}> */
    public static function otherStatuses(): iterable
    {
        yield 'ok' => [200];
        yield 'a redirect' => [399];
        yield 'unauthorized, a credentials failure' => [401];
        yield 'forbidden, a credentials failure' => [403];
        yield 'request timeout' => [408];
        yield 'too early' => [425];
        yield 'rate limited' => [429];
        yield 'the first 5xx' => [500];
    }

    #[DataProvider('rejectingStatuses')]
    public function testItRejectsTheRequest(int $status): void
    {
        self::assertTrue(RejectingStatus::matches($status));
    }

    #[DataProvider('otherStatuses')]
    public function testItIsNotARejection(int $status): void
    {
        self::assertFalse(RejectingStatus::matches($status));
    }
}
```

- [ ] **Step 2: Run, expect** "class not found".
- [ ] **Step 3: Implement.**

```php
final class RejectingStatus
{
    private const array NOT_A_VERDICT_ON_THE_REQUEST = [401, 403, 408, 425, 429];

    public static function matches(int $status): bool
    {
        return $status >= 400 && $status < 500 && !\in_array($status, self::NOT_A_VERDICT_ON_THE_REQUEST, true);
    }

    private function __construct()
    {
    }
}
```

```php
/**
 * The provider answered that the request itself is wrong. Apart from ProviderUnreachableException because
 * resending the same request earns the same answer: the run ends on the first one.
 */
final class ProviderRejectedRequestException extends \RuntimeException
{
    public function __construct(private readonly int $status, string $message)
    {
        parent::__construct($message);
    }

    public function status(): int
    {
        return $this->status;
    }
}
```

- [ ] **Step 4: Green. Step 5: Commit** — `feat(#1387): a status that rejects the request has its own failure`.

---

### Task 2: Both clients throw it

- [ ] **Step 1: Move `RefusalMessage`** to `src/Service/Recommendation/Support/` (and its test beside it) with
  PhpStorm's move refactoring or by hand; run the Jev tests green. Commit —
  `refactor(#1387): the refusal message is shared by both engines`.

- [ ] **Step 2: Failing tests, LLM client** (`OpenAiCompatibleChatClientTest`). #1386's
  `testAnErrorStatusCarriesTheProvidersReason` and its 400/404 siblings now expect the new class and sentence;
  change them, and add:

```php
    public function testARejectingStatusIsARejectedRequestNotAnUnreachableProvider(): void
    {
        $client = $this->clientAnswering(new MockResponse(
            '{"error":{"message":"Reasoning effort \"none\" is not supported."}}',
            ['http_code' => 400],
        ));

        try {
            $client->complete($this->connection(), $this->request(), new NullCompletionStreamObserver());
            self::fail('The rejection was not reported.');
        } catch (ProviderRejectedRequestException $exception) {
            self::assertSame(400, $exception->status());
            self::assertSame(
                'That provider refused the request (status 400): Reasoning effort "none" is not supported.',
                $exception->getMessage(),
            );
        }
    }

    public function testARejectionWithoutAReadableReasonStillNamesTheStatus(): void
    {
        $client = $this->clientAnswering(new MockResponse('<html>Not Found</html>', ['http_code' => 404]));

        $this->expectException(ProviderRejectedRequestException::class);
        $this->expectExceptionMessage('That provider refused the request (status 404).');
        $client->complete($this->connection(), $this->request(), new NullCompletionStreamObserver());
    }

    public function testARequestTimeoutStaysAnUnreachableProvider(): void
    {
        $client = $this->clientAnswering(new MockResponse('', ['http_code' => 408]));

        $this->expectException(ProviderUnreachableException::class);
        $this->expectExceptionMessage('That provider answered with status 408.');
        $client->complete($this->connection(), $this->request(), new NullCompletionStreamObserver());
    }

    public function testARejectedCallIsAFailedOutcomeThatIsNotRetryable(): void
    {
        $outcome = $this->soleOutcomeOf(/* a client answering 400, as the file's helper takes it */);

        self::assertTrue($outcome->isFailure());
        self::assertFalse($outcome->isRetryable());
        self::assertInstanceOf(ProviderRejectedRequestException::class, $outcome->cause());
    }
```

  The key-redaction, oversized-body and sibling tests from #1386 keep their intent with the new sentence. The
  300 and 500 tests stay `ProviderUnreachableException`, unchanged.

- [ ] **Step 3: Implement** in the method #1386 left as `collectErrorBody()` (read it; the names here are
  #1386's plan, not necessarily its result):

```php
        $status = $slot->errorBody->status();
        $body = $slot->errorBody->text();

        if (RejectingStatus::matches($status)) {
            throw new ProviderRejectedRequestException(
                $status,
                $slot->credentials->withoutApiKey(RefusalMessage::of($status, $body)),
            );
        }
        // the #1386 path, unchanged, for 3xx, 408, 425 and unclassified 5xx
```

  and add `ProviderRejectedRequestException` to the `catch` in `advance()` that turns a failure into
  `CompletionOutcomeModel::failure()`. Without it the exception falls to no arm and aborts the siblings' reads —
  `testOneCallsErrorStatusLeavesItsSiblingsAnswerAlone` is the pin; watch it fail before adding the catch.
  Update the `@throws` lists (`consumeChunk()`, `ChatCompletionClientInterface`).

- [ ] **Step 4: JEV client.** Failing test first in `HttpSystemOneClientTest`: a 400 and a 422 give an outcome
  whose cause is `ProviderRejectedRequestException` with `RefusalMessage`'s sentence; a 404 too (today it is the
  bare "answered with status 404"); a 500 stays `ProviderUnreachableException`. Then in `outcomeOf()` replace
  the `400 === $status, 422 === $status` arm with `RejectingStatus::matches($status)` throwing the new class
  (still key-redacted, as #1386 Task 4 left it).

- [ ] **Step 5: Run `tests/Service/Recommendation` whole.** Tests that queued a 400 as an unreachable provider
  and expected three strikes will fail — those are Task 3's and Task 4's; leave them red for now only if they
  are exactly those, and say which in the commit body.
- [ ] **Step 6: Commit** — `fix(#1387): a rejecting status is reported as a rejected request`.

---

### Task 3: A profile run fails on the first rejection

**Files:** `ProfileRunTick.php`, `ProfileRunFailure.php`; test `tests/Service/Recommendation/Profile/ProfileRunTickTest.php`.

- [ ] **Step 1: Failing tests.**

```php
    public function testARejectedRequestFailsTheRunAtOnceKeepingTheProfile(): void
    {
        $this->fixtures->seedFavorites($this->owner, 'maps', 1);
        $this->fixtures->storeProfile($this->owner, 'Earlier profile.');
        $profileRun = $this->profileRun(ProfileRunTrigger::Manual);
        $this->chat()->queueFailure(new ProviderRejectedRequestException(
            400,
            'That provider refused the request (status 400): Unknown parameter.',
        ));

        $this->advance($profileRun, TickDriver::Poll);

        self::assertSame(RunStatus::Failed, $this->saved($profileRun)->getStatus());
        self::assertSame(
            'The AI provider at https://api.example.test/v1 failed: '
            . 'That provider refused the request (status 400): Unknown parameter.',
            $this->saved($profileRun)->getError(),
        );
        self::assertSame(0, $this->saved($profileRun)->getTransportFailures());
        self::assertCount(1, $this->chat()->calls());
        self::assertSame('Earlier profile.', $this->storedProfile()->getText());
    }

    public function testARejectedCallIsSettledInTheRunLogWithTheProvidersReason(): void
    {
        $this->fixtures->seedFavorites($this->owner, 'maps', 1);
        $profileRun = $this->profileRun(ProfileRunTrigger::Manual);
        $this->chat()->queueFailure(new ProviderRejectedRequestException(400, 'That provider refused the request (status 400): No.'));

        $this->advance($profileRun, TickDriver::Poll);

        $rows = $this->logs()->listForProfileRun($this->owner, $profileRun->requireId());
        self::assertSame([CallVerdict::TransportFailed], array_column($rows, 'verdict'));
        // and the row's error detail is the sentence: assert it with whatever key listForProfileRun() returns it under.
    }

    public function testARejectionAfterTheLockWasLostRecordsNothing(): void
    {
        // As testAnUnexpectedFailureAfterTheLockWasLostRecordsNothing, with the stub queued to throw the rejection
        // inside duringNextCall()'s beat: the run stays Running and unfailed.
    }
```

  `saved()` clears the entity manager, so read `storedProfile()` after it as the neighbouring tests do. The
  suppress-reasoning default on the fixture connection is `true`; that is irrelevant here and becomes relevant
  in #1388, which revisits these tests.

- [ ] **Step 2: Run, expect** the first to end `Running` with one transport failure.
- [ ] **Step 3: Implement.** `ProfileRunTick::advanceRecordingProviderFailures()` gains, ahead of the strike arm:

```php
        } catch (ProviderRejectedRequestException $exception) {
            $this->failure->failRejected($tick, $exception->getMessage());
        } catch (
```

  `ProfileRunFailure`:

```php
    /** No strike and no retry: the same request would be rejected again. A tick that lost its lock records nothing. */
    public function failRejected(ProfileTick $tick, string $rejection): void
    {
        $profileRun = $tick->profileRun;
        if ($this->keepalive->hasLostTheLock()) {
            $this->entityManager->refresh($profileRun);

            return;
        }

        $this->fail($profileRun, $this->providerFailed($tick, $rejection));
    }
```

  `recordTransportFailure()` builds the same `PROVIDER_FAILED` sentence: extract `providerFailed(ProfileTick,
  string): string` and use it in both, so the lock guard is the only thing the two methods repeat. If that
  repeat reads as duplication, extract it too.

- [ ] **Step 4: Green; run `ProfileRunAdvancerTest` too. Step 5: Commit** —
  `fix(#1387): a profile run fails on the first rejected request`.

---

### Task 4: A recommendation run fails on the first rejection, on both engines

**Files:** `src/Service/Recommendation/Run/TickPhases.php`; tests `tests/Service/Recommendation/Run/RecommendationRunAdvancerTest.php`,
`tests/Service/Recommendation/Jev/JevRecommendationEngineTest.php`, `tests/Service/Worker/AdvanceRecommendationRunsHandlerTest.php`.

- [ ] **Step 1: Failing tests.** In `RecommendationRunAdvancerTest`, beside the test that queues
  `new ProviderUnreachableException(…)` (read it for the fixture and the tick helper): a batch call that fails
  with `ProviderRejectedRequestException(400, …)` leaves the run `Failed` after **one** tick, with the wrapped
  sentence as its error, zero transport failures on the running call attempts, every call row of the wave
  settled, and **no exception thrown out of the tick**. One more for a rejection in the consolidation phase if
  that file has a transport-failure test for it. In `JevRecommendationEngineTest`, the same for a JEV run whose
  System One call is refused with 400. In `AdvanceRecommendationRunsHandlerTest`: a rejected run does not log
  the sweep's "provider call failed" warning and does not stop the sweep for the next user's run.

- [ ] **Step 2: Run, expect** one strike and a rethrown exception.
- [ ] **Step 3: Implement** in `TickPhases::advanceWithinTheEnvelope()`, ahead of the strike arm:

```php
        } catch (ProviderRejectedRequestException $exception) {
            return $this->runFailure->fail($tick->run, \sprintf(
                RecommendationTransportFailureRecorder::PROVIDER_FAILED,
                $tick->connection->getBaseUrl(),
                $exception->getMessage(),
            ));
        } catch (ProviderUnreachableException | CredentialsRejectedException | RetryableProviderException $exception) {
```

  `RecommendationRunFailure::fail()` already guards the checkpoint. Check, by reading, every other
  `catch (ProviderUnreachableException` in `src` (`grep -rn "ProviderUnreachableException" src`): the worker
  sweep, `AiProblems`, the model catalog and `AiProviderConfigurator` catch it for calls that are not
  completions; none should need the new class, but confirm no synchronous HTTP path can surface it uncaught as
  a 500.

- [ ] **Step 4: Run `tests/Service/Recommendation` and `tests/Service/Worker` whole, green.**
- [ ] **Step 5: Break-test the order of the catch arms.** `ProviderRejectedRequestException` does not extend
  `ProviderUnreachableException`, so order cannot matter — confirm that by reading, and delete this step's
  worry rather than adding a test for it.
- [ ] **Step 6: Commit** — `fix(#1387): a recommendation run fails on the first rejected request`.

---

### Task 5: Docs, simplify, gates, real run, PR

- [ ] `docs/recommendations-runs.md`: where the three-strikes rule is described, add the rejection: which
  statuses, that the run fails on the first answer with the provider's reason, that it spends no strike, and
  that the log row still reads `transport-failed`.
- [ ] Run `/simplify` on the branch diff; apply what it finds; re-run the affected tests.
- [ ] `bin/console cache:warmup && composer check && composer md`; `composer test:parallel`;
  `docker compose exec php composer test`; `git add -A && composer infection:diff`; PhpStorm lint.
- [ ] **Real run** (container current first): the same model and setting as #1386's real run. The profile run
  must now fail within one call — one `distill` log row, `transport_failures = 0`, the provider's sentence in
  the run's error and in Settings. Then a recommendation run on the dev account's normal connection to
  `completed`, to prove the happy path is untouched. Restore the dev account's connection afterwards.
- [ ] Scan today's `backend/var/log/dev-*.log`.
- [ ] PR into `develop`, body ending `Closes #1387`, listing the decisions above as the planner's and offering
  the fourth `CallVerdict` as a follow-up. **Merge when all checks are green**, verify #1387 auto-closed, report
  to "Saved searches in For You profile", go on to #1388.
