# Concurrent Feed Fetch Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Fetch a refresh sweep's feeds concurrently instead of one at a time, cutting a measured 24-feed sweep from 5.37s of network wait to roughly 1s.

**Architecture:** Symfony's HttpClient already multiplexes via `stream()`, so a new `ConcurrentFeedFetcher` drives many responses at once behind a `BatchFeedFetcherInterface`. `classify()` — the SSRF/redirect security boundary — is extracted once into a `ResponseClassifier` shared by both paths, and the existing serial `HttpFeedFetcher` becomes a thin single-URL adapter over the batch engine so there is only ever one implementation. `RefreshRunner` consumes outcomes as they complete and keeps parse → ingest → flush strictly serial, leaving Doctrine semantics untouched.

**Tech Stack:** PHP 8.4, Symfony 7.4 LTS, PHPUnit, Doctrine ORM. No new dependencies.

**Spec:** [`docs/superpowers/specs/2026-07-26-concurrent-feed-fetch-design.md`](../specs/2026-07-26-concurrent-feed-fetch-design.md) · **Issue:** [#116](https://github.com/larspohlmann/simple-feed-reader/issues/116) · **Branch:** `feature/116-concurrent-feed-fetch`

---

## Before You Start

Read [`CLAUDE.md`](../../../CLAUDE.md). The rules that bite hardest here:

- **Clean Code is a hard gate.** Few parameters (three is a lot), no boolean flag parameters, guard clauses over nesting, `final readonly` by default, no hidden side effects.
- **Every `src` file you touch must be PHPMD-clean before commit** — not merely free of *new* findings. Fix the design the metric points at; never tune the threshold.
- Typed exceptions live in `Service/*/Exception/`. Comments explain **why**, never **what**.
- `declare(strict_types=1)` in every file. PHPStan runs at level **max** over `src` *and* `tests`.
- Tests are production code: same naming, same standards.

Commands (from `backend/`):

```bash
php bin/phpunit                 # SQLite leg
composer check                  # phpcs + phpstan
composer md                     # PHPMD
```

```bash
docker compose exec php vendor/bin/phpunit
```

Run `php bin/console cache:warmup` before `composer stan` if PHPStan complains about a cold cache.

### The one behaviour change to keep in your head

Today the budget check gates *processing feed N*. After this change it gates *starting a fetch*. With concurrency C, up to C feeds start before any time has elapsed, regardless of how small the budget is — that is correct, because they cost one wave of wall-clock rather than C waves. What must not change: **a run always makes progress**, because the frontend polls `/api/refresh` until `remaining` reaches 0 and would otherwise spin forever.

---

## File Structure

**Create — `backend/src/Service/Fetch/`**

| File | Responsibility |
|---|---|
| `FetchTicket.php` | Immutable request: url, etag, lastModified |
| `FetchOutcome.php` | Immutable result: a `FetchResponse` **or** a `FetchException` |
| `HeaderDecision.php` | Enum: `AwaitBody` / `Terminal` / `Redirect` |
| `HeaderVerdict.php` | The classifier's header-phase answer |
| `ResponseClassifier.php` | The security boundary, lifted out of `HttpFeedFetcher` |
| `FetchAttempt.php` | One feed's in-flight state: key, ticket, current URL, hop count, permanent-redirect flag |
| `FetchQueue.php` | Pending work: the ticket iterator plus redirect continuations |
| `BatchFeedFetcherInterface.php` | `fetchAll(iterable $tickets): iterable` |
| `ConcurrentFeedFetcher.php` | The `stream()` loop |

**Create — `backend/src/Service/Refresh/`**

| File | Responsibility |
|---|---|
| `BudgetedFeedQueue.php` | Yields tickets while the budget allows; counts what it started |

**Modify**

| File | Change |
|---|---|
| `src/Service/Fetch/HttpFeedFetcher.php` | Becomes a single-URL adapter over `BatchFeedFetcherInterface` |
| `src/Service/Fetch/FaviconResolver.php` | `resolve()` → `resolveAll()`, batch |
| `src/Service/Refresh/RefreshRunner.php` | Consumes `fetchAll`; favicon becomes phase 2 |
| `config/services.yaml` | Bind `$fetchConcurrency`; alias the batch interface |
| `config/services_test.yaml` | Public alias so tests can swap the batch fetcher |
| `tests/Support/StubFeedFetcher.php` | Implements both interfaces |
| `tests/Service/Refresh/RefreshRunnerTest.php` | Budget + ordering assertions |
| `tests/Service/Fetch/FaviconResolverTest.php` | Batch signature |
| `tests/Service/Fetch/HttpFeedFetcherTest.php` | Constructor helper only — **the 14 cases must not change** |

`HttpFeedFetcherTest` is the regression net proving the serial path kept its behaviour. If you find yourself editing an assertion in it, stop: you have changed behaviour that was supposed to be preserved.

---

## Task 1: FetchTicket and FetchOutcome

**Files:**
- Create: `backend/src/Service/Fetch/FetchTicket.php`
- Create: `backend/src/Service/Fetch/FetchOutcome.php`
- Test: `backend/tests/Service/Fetch/FetchOutcomeTest.php`

- [ ] **Step 1: Write the failing test**

`backend/tests/Service/Fetch/FetchOutcomeTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Fetch;

use App\Service\Fetch\Exception\FeedUnreachableException;
use App\Service\Fetch\FetchOutcome;
use App\Service\Fetch\FetchResponse;
use PHPUnit\Framework\TestCase;

final class FetchOutcomeTest extends TestCase
{
    public function testSucceededCarriesTheResponse(): void
    {
        $response = FetchResponse::notModified('https://example.com/feed', false, null, null);

        $outcome = FetchOutcome::succeeded($response);

        self::assertSame($response, $outcome->responseOrThrow());
    }

    public function testFailedRethrowsTheOriginalException(): void
    {
        $failure = new FeedUnreachableException('https://example.com/feed: HTTP 500');

        $outcome = FetchOutcome::failed($failure);

        self::assertSame($failure, $outcome->failure());
        $this->expectExceptionObject($failure);
        $outcome->responseOrThrow();
    }

    public function testASucceededOutcomeHasNoFailure(): void
    {
        $outcome = FetchOutcome::succeeded(
            FetchResponse::notModified('https://example.com/feed', false, null, null),
        );

        self::assertNull($outcome->failure());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php bin/phpunit tests/Service/Fetch/FetchOutcomeTest.php`
Expected: FAIL — `Class "App\Service\Fetch\FetchOutcome" not found`.

- [ ] **Step 3: Write the implementation**

`backend/src/Service/Fetch/FetchTicket.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Fetch;

/**
 * One feed's fetch request. Bundles the conditional-GET headers with the URL so
 * a batch can carry them together and callers stop passing three positionals.
 */
final readonly class FetchTicket
{
    public function __construct(
        public string $url,
        public ?string $etag = null,
        public ?string $lastModified = null,
    ) {
    }
}
```

`backend/src/Service/Fetch/FetchOutcome.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Fetch;

use App\Service\Fetch\Exception\FetchException;

/**
 * One feed's result inside a batch. Failure is a value here rather than a thrown
 * exception because a batch cannot throw for one feed without abandoning the
 * others still in flight; the caller unwraps and decides per feed.
 */
final readonly class FetchOutcome
{
    private function __construct(
        private ?FetchResponse $response,
        private ?FetchException $failure,
    ) {
    }

    public static function succeeded(FetchResponse $response): self
    {
        return new self($response, null);
    }

    public static function failed(FetchException $failure): self
    {
        return new self(null, $failure);
    }

    public function failure(): ?FetchException
    {
        return $this->failure;
    }

    /** @throws FetchException when this outcome is a failure */
    public function responseOrThrow(): FetchResponse
    {
        if (null !== $this->failure) {
            throw $this->failure;
        }

        // Both properties are set together by the two factories, so a null
        // response here would mean the class was constructed past its own API.
        \assert(null !== $this->response);

        return $this->response;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php bin/phpunit tests/Service/Fetch/FetchOutcomeTest.php`
Expected: PASS, 3 tests.

- [ ] **Step 5: Run the gates**

Run: `composer check && composer md`
Expected: no findings.

- [ ] **Step 6: Commit**

```bash
git add backend/src/Service/Fetch/FetchTicket.php backend/src/Service/Fetch/FetchOutcome.php backend/tests/Service/Fetch/FetchOutcomeTest.php
git commit -m "feat(fetch): add FetchTicket and FetchOutcome value objects (#116)"
```

---

## Task 2: FetchAttempt

**Files:**
- Create: `backend/src/Service/Fetch/FetchAttempt.php`
- Test: `backend/tests/Service/Fetch/FetchAttemptTest.php`

`FetchAttempt` replaces the `bool &$permanentRedirect` by-reference parameter in today's `classify()`. Each hop produces a **new** attempt rather than mutating one, so the redirect state is immutable and traceable.

- [ ] **Step 1: Write the failing test**

`backend/tests/Service/Fetch/FetchAttemptTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Fetch;

use App\Service\Fetch\FetchAttempt;
use App\Service\Fetch\FetchTicket;
use PHPUnit\Framework\TestCase;

final class FetchAttemptTest extends TestCase
{
    private function attempt(): FetchAttempt
    {
        return FetchAttempt::start(
            7,
            new FetchTicket('https://example.com/feed', '"v1"', 'Mon, 20 Jul 2026 08:30:00 GMT'),
        );
    }

    public function testStartsAtTheTicketUrlWithNoRedirects(): void
    {
        $attempt = $this->attempt();

        self::assertSame(7, $attempt->key);
        self::assertSame('https://example.com/feed', $attempt->url);
        self::assertFalse($attempt->permanentRedirect);
        self::assertTrue($attempt->canFollowRedirect());
    }

    public function testATemporaryRedirectMovesTheUrlWithoutMarkingItPermanent(): void
    {
        $next = $this->attempt()->followedTo('https://example.com/moved', permanent: false);

        self::assertSame('https://example.com/moved', $next->url);
        self::assertFalse($next->permanentRedirect);
        // The conditional-GET headers follow the chain.
        self::assertSame('"v1"', $next->ticket->etag);
    }

    public function testAPermanentRedirectAnywhereInTheChainIsRemembered(): void
    {
        $next = $this->attempt()
            ->followedTo('https://example.com/one', permanent: true)
            ->followedTo('https://example.com/two', permanent: false);

        self::assertTrue($next->permanentRedirect);
    }

    public function testTheRedirectBudgetIsExhaustedAfterFiveHops(): void
    {
        $attempt = $this->attempt();
        for ($hop = 0; $hop < 5; $hop++) {
            self::assertTrue($attempt->canFollowRedirect(), sprintf('hop %d should be allowed', $hop));
            $attempt = $attempt->followedTo('https://example.com/' . $hop, permanent: false);
        }

        self::assertFalse($attempt->canFollowRedirect());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php bin/phpunit tests/Service/Fetch/FetchAttemptTest.php`
Expected: FAIL — `Class "App\Service\Fetch\FetchAttempt" not found`.

- [ ] **Step 3: Write the implementation**

`backend/src/Service/Fetch/FetchAttempt.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Fetch;

/**
 * One feed's position in its redirect chain. Immutable: each hop yields a new
 * attempt, so the permanent-redirect flag can no longer be smuggled back to the
 * caller through a by-reference parameter.
 */
final readonly class FetchAttempt
{
    private const int MAX_REDIRECTS = 5;

    /**
     * Private so a fresh attempt can only be built by `start()`, which seeds the
     * URL from the ticket. A public constructor would need `$url` to default to
     * another promoted property, which PHP cannot express.
     */
    private function __construct(
        public int|string $key,
        public FetchTicket $ticket,
        public string $url,
        public bool $permanentRedirect,
        private int $hop,
    ) {
    }

    public static function start(int|string $key, FetchTicket $ticket): self
    {
        return new self($key, $ticket, $ticket->url, false, 0);
    }

    public function canFollowRedirect(): bool
    {
        return $this->hop < self::MAX_REDIRECTS;
    }

    public function followedTo(string $url, bool $permanent): self
    {
        return new self(
            $this->key,
            $this->ticket,
            $url,
            $this->permanentRedirect || $permanent,
            $this->hop + 1,
        );
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php bin/phpunit tests/Service/Fetch/FetchAttemptTest.php`
Expected: PASS, 4 tests.

- [ ] **Step 5: Commit**

```bash
git add backend/src/Service/Fetch/FetchAttempt.php backend/tests/Service/Fetch/FetchAttemptTest.php
git commit -m "feat(fetch): add immutable FetchAttempt redirect state (#116)"
```

---

## Task 3: ResponseClassifier

**Files:**
- Create: `backend/src/Service/Fetch/HeaderDecision.php`
- Create: `backend/src/Service/Fetch/HeaderVerdict.php`
- Create: `backend/src/Service/Fetch/ResponseClassifier.php`
- Test: `backend/tests/Service/Fetch/ResponseClassifierTest.php`

This is the security boundary. It is a **pure lift** of `HttpFeedFetcher::classify()` split into two phases: a header phase (decidable the moment `isFirst()` fires) and a body phase. Do not change any status-code rule while moving it.

- [ ] **Step 1: Write the failing test**

`backend/tests/Service/Fetch/ResponseClassifierTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Fetch;

use App\Service\Fetch\Exception\FeedGoneException;
use App\Service\Fetch\Exception\FeedUnreachableException;
use App\Service\Fetch\Exception\ResponseTooLargeException;
use App\Service\Fetch\FetchAttempt;
use App\Service\Fetch\FetchTicket;
use App\Service\Fetch\HeaderDecision;
use App\Service\Fetch\ResponseClassifier;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\ResponseInterface;

final class ResponseClassifierTest extends TestCase
{
    private function attempt(string $url = 'https://example.com/feed'): FetchAttempt
    {
        return FetchAttempt::start(1, new FetchTicket($url, '"v1"', 'Mon, 20 Jul 2026 08:30:00 GMT'));
    }

    private function respond(MockResponse $mock): ResponseInterface
    {
        return (new MockHttpClient($mock))->request('GET', 'https://example.com/feed');
    }

    public function testATwoHundredAwaitsTheBody(): void
    {
        $verdict = (new ResponseClassifier())->fromHeaders(
            $this->respond(new MockResponse('<rss/>', ['http_code' => 200])),
            $this->attempt(),
        );

        self::assertSame(HeaderDecision::AwaitBody, $verdict->decision);
    }

    public function testAThreeOhOneRedirectsAndIsPermanent(): void
    {
        $verdict = (new ResponseClassifier())->fromHeaders(
            $this->respond(new MockResponse('', [
                'http_code' => 301,
                'response_headers' => ['location' => 'https://example.com/moved'],
            ])),
            $this->attempt(),
        );

        self::assertSame(HeaderDecision::Redirect, $verdict->decision);
        self::assertSame('https://example.com/moved', $verdict->redirectUrl);
        self::assertTrue($verdict->permanent);
    }

    public function testAThreeOhTwoRedirectsButIsNotPermanent(): void
    {
        $verdict = (new ResponseClassifier())->fromHeaders(
            $this->respond(new MockResponse('', [
                'http_code' => 302,
                'response_headers' => ['location' => '/relative'],
            ])),
            $this->attempt(),
        );

        self::assertSame(HeaderDecision::Redirect, $verdict->decision);
        self::assertSame('https://example.com/relative', $verdict->redirectUrl);
        self::assertFalse($verdict->permanent);
    }

    public function testARedirectWithoutALocationIsUnreachable(): void
    {
        $this->expectException(FeedUnreachableException::class);

        (new ResponseClassifier())->fromHeaders(
            $this->respond(new MockResponse('', ['http_code' => 302])),
            $this->attempt(),
        );
    }

    public function testAThreeOhFourIsTerminalAndEchoesTheCachingHeaders(): void
    {
        $verdict = (new ResponseClassifier())->fromHeaders(
            $this->respond(new MockResponse('', ['http_code' => 304])),
            $this->attempt(),
        );

        self::assertSame(HeaderDecision::Terminal, $verdict->decision);
        self::assertTrue($verdict->response?->notModified);
        self::assertSame('"v1"', $verdict->response?->etag);
        self::assertSame('Mon, 20 Jul 2026 08:30:00 GMT', $verdict->response?->lastModified);
    }

    public function testAFourTenIsGone(): void
    {
        $this->expectException(FeedGoneException::class);

        (new ResponseClassifier())->fromHeaders(
            $this->respond(new MockResponse('', ['http_code' => 410])),
            $this->attempt(),
        );
    }

    public function testAFiveHundredIsUnreachable(): void
    {
        $this->expectException(FeedUnreachableException::class);

        (new ResponseClassifier())->fromHeaders(
            $this->respond(new MockResponse('', ['http_code' => 500])),
            $this->attempt(),
        );
    }

    public function testTheBodyPhaseBuildsAFetchedResponse(): void
    {
        $response = $this->respond(new MockResponse('<rss/>', [
            'http_code' => 200,
            'response_headers' => ['etag' => '"v2"', 'last-modified' => 'Tue, 21 Jul 2026 08:30:00 GMT'],
        ]));

        $fetched = (new ResponseClassifier())->fromBody($response, $this->attempt());

        self::assertFalse($fetched->notModified);
        self::assertSame('<rss/>', $fetched->body);
        self::assertSame('"v2"', $fetched->etag);
        self::assertSame('Tue, 21 Jul 2026 08:30:00 GMT', $fetched->lastModified);
        self::assertSame('https://example.com/feed', $fetched->finalUrl);
    }

    public function testABodyOverTheSizeCapIsRejected(): void
    {
        $this->expectException(ResponseTooLargeException::class);

        (new ResponseClassifier())->fromBody(
            $this->respond(new MockResponse(str_repeat('x', 5_000_001), ['http_code' => 200])),
            $this->attempt(),
        );
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php bin/phpunit tests/Service/Fetch/ResponseClassifierTest.php`
Expected: FAIL — `Class "App\Service\Fetch\HeaderDecision" not found`.

- [ ] **Step 3: Write the implementation**

`backend/src/Service/Fetch/HeaderDecision.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Fetch;

enum HeaderDecision
{
    /** 2xx: headers are fine, the body is still arriving. */
    case AwaitBody;
    /** 304: the exchange is over, no body will come. */
    case Terminal;
    /** 3xx: follow the Location header. */
    case Redirect;
}
```

`backend/src/Service/Fetch/HeaderVerdict.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Fetch;

/**
 * What the classifier concluded from a response's headers alone. Modelled as
 * one type rather than a `FetchResponse|string|null` union so the caller cannot
 * mistake "follow this URL" for "here is your answer".
 */
final readonly class HeaderVerdict
{
    private function __construct(
        public HeaderDecision $decision,
        public ?FetchResponse $response,
        public ?string $redirectUrl,
        public bool $permanent,
    ) {
    }

    public static function awaitBody(): self
    {
        return new self(HeaderDecision::AwaitBody, null, null, false);
    }

    public static function terminal(FetchResponse $response): self
    {
        return new self(HeaderDecision::Terminal, $response, null, false);
    }

    public static function permanentRedirectTo(string $url): self
    {
        return new self(HeaderDecision::Redirect, null, $url, true);
    }

    public static function temporaryRedirectTo(string $url): self
    {
        return new self(HeaderDecision::Redirect, null, $url, false);
    }
}
```

Note the two named redirect constructors rather than `redirectTo(string $url, bool $permanent)` — CLAUDE.md forbids boolean flag parameters.

`backend/src/Service/Fetch/ResponseClassifier.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Fetch;

use App\Service\Fetch\Exception\FeedGoneException;
use App\Service\Fetch\Exception\FeedUnreachableException;
use App\Service\Fetch\Exception\ResponseTooLargeException;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Decides what one HTTP response means for the feed that asked for it.
 *
 * SECURITY: this is the single copy of the redirect and status-code rules that
 * the SSRF guard depends on — every hop it returns is re-validated by UrlGuard
 * before the next request. A second implementation would drift out of step with
 * that guard, so both the serial and the concurrent fetcher route through here.
 */
final class ResponseClassifier
{
    private const int MAX_BYTES = 5_000_000;
    private const array REDIRECT_CODES = [301, 302, 303, 307, 308];
    private const array PERMANENT_CODES = [301, 308];

    public function fromHeaders(ResponseInterface $response, FetchAttempt $attempt): HeaderVerdict
    {
        $status = $this->statusCode($response, $attempt->url);

        if (\in_array($status, self::REDIRECT_CODES, true)) {
            return $this->redirect($response, $attempt, $status);
        }

        if (304 === $status) {
            return HeaderVerdict::terminal(FetchResponse::notModified(
                $attempt->url,
                $attempt->permanentRedirect,
                $attempt->ticket->etag,
                $attempt->ticket->lastModified,
            ));
        }

        if (410 === $status) {
            throw new FeedGoneException(sprintf('%s: HTTP 410 Gone', $attempt->url));
        }

        if ($status < 200 || $status >= 300) {
            throw new FeedUnreachableException(
                sprintf('%s: HTTP %d', $attempt->url, $status),
                statusCode: $status,
            );
        }

        return HeaderVerdict::awaitBody();
    }

    public function fromBody(ResponseInterface $response, FetchAttempt $attempt): FetchResponse
    {
        $body = $this->content($response, $attempt->url);
        if (\strlen($body) > self::MAX_BYTES) {
            throw new ResponseTooLargeException(
                sprintf('%s: response exceeds %d bytes', $attempt->url, self::MAX_BYTES),
            );
        }

        return FetchResponse::fetched(
            $attempt->url,
            $attempt->permanentRedirect,
            $body,
            $this->header($response, 'etag'),
            $this->header($response, 'last-modified'),
        );
    }

    private function redirect(ResponseInterface $response, FetchAttempt $attempt, int $status): HeaderVerdict
    {
        $location = $this->header($response, 'location');
        if (null === $location) {
            throw new FeedUnreachableException(
                sprintf('%s: redirect without Location header', $attempt->url),
                statusCode: $status,
            );
        }

        $target = UrlResolver::resolve($attempt->url, $location);

        return \in_array($status, self::PERMANENT_CODES, true)
            ? HeaderVerdict::permanentRedirectTo($target)
            : HeaderVerdict::temporaryRedirectTo($target);
    }

    private function statusCode(ResponseInterface $response, string $url): int
    {
        try {
            return $response->getStatusCode();
        } catch (ExceptionInterface $e) {
            $this->rethrowTooLarge($e);

            throw new FeedUnreachableException(sprintf('%s: %s', $url, $e->getMessage()), previous: $e);
        }
    }

    private function content(ResponseInterface $response, string $url): string
    {
        try {
            return $response->getContent(false);
        } catch (ExceptionInterface $e) {
            $this->rethrowTooLarge($e);

            throw new FeedUnreachableException(sprintf('%s: %s', $url, $e->getMessage()), previous: $e);
        }
    }

    private function header(ResponseInterface $response, string $name): ?string
    {
        try {
            $headers = $response->getHeaders(false);
        } catch (ExceptionInterface) {
            return null;
        }

        return $headers[$name][0] ?? null;
    }

    /**
     * The HTTP client wraps exceptions thrown inside on_progress; unwrap and
     * rethrow our size-limit exception so callers see the real cause.
     */
    private function rethrowTooLarge(?\Throwable $e): void
    {
        while (null !== $e) {
            if ($e instanceof ResponseTooLargeException) {
                throw $e;
            }
            $e = $e->getPrevious();
        }
    }
}
```

Note the behaviour that moved: today `classify()` calls `$response->cancel()` before every non-2xx return. Cancellation is now the *caller's* job, because only the caller knows whether the response is in an in-flight set that needs updating. Do not put `cancel()` in here.

- [ ] **Step 4: Run test to verify it passes**

Run: `php bin/phpunit tests/Service/Fetch/ResponseClassifierTest.php`
Expected: PASS, 9 tests.

- [ ] **Step 5: Run the gates**

Run: `composer check && composer md`
Expected: no findings. If PHPMD flags `fromHeaders` for cyclomatic complexity, extract the 410/non-2xx pair into a private `assertUsable(int $status, string $url): void` guard — do not raise the threshold.

- [ ] **Step 6: Commit**

```bash
git add backend/src/Service/Fetch/HeaderDecision.php backend/src/Service/Fetch/HeaderVerdict.php backend/src/Service/Fetch/ResponseClassifier.php backend/tests/Service/Fetch/ResponseClassifierTest.php
git commit -m "refactor(fetch): extract ResponseClassifier from HttpFeedFetcher (#116)"
```

---

## Task 4: FetchQueue

**Files:**
- Create: `backend/src/Service/Fetch/FetchQueue.php`
- Test: `backend/tests/Service/Fetch/FetchQueueTest.php`

The engine needs one place to ask "what should I start next?", where the answer is either a redirect continuation (higher priority — it is already half-done) or a fresh ticket.

- [ ] **Step 1: Write the failing test**

`backend/tests/Service/Fetch/FetchQueueTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Fetch;

use App\Service\Fetch\FetchAttempt;
use App\Service\Fetch\FetchQueue;
use App\Service\Fetch\FetchTicket;
use PHPUnit\Framework\TestCase;

final class FetchQueueTest extends TestCase
{
    /** @param array<int|string, FetchTicket> $tickets */
    private function queue(array $tickets): FetchQueue
    {
        return new FetchQueue(new \ArrayIterator($tickets));
    }

    public function testDrainsTicketsInOrderAndKeepsTheirKeys(): void
    {
        $queue = $this->queue([
            11 => new FetchTicket('https://one.example.com/feed'),
            22 => new FetchTicket('https://two.example.com/feed'),
        ]);

        self::assertTrue($queue->hasMore());
        $first = $queue->next();
        self::assertSame(11, $first->key);
        self::assertSame('https://one.example.com/feed', $first->url);

        $second = $queue->next();
        self::assertSame(22, $second->key);

        self::assertFalse($queue->hasMore());
    }

    public function testARequeuedRedirectIsServedBeforeUnstartedTickets(): void
    {
        $queue = $this->queue([
            11 => new FetchTicket('https://one.example.com/feed'),
            22 => new FetchTicket('https://two.example.com/feed'),
        ]);

        $first = $queue->next();
        $queue->requeue($first->followedTo('https://one.example.com/moved', permanent: true));

        $served = $queue->next();
        self::assertSame(11, $served->key);
        self::assertSame('https://one.example.com/moved', $served->url);
    }

    public function testAnEmptyQueueHasNoMore(): void
    {
        self::assertFalse($this->queue([])->hasMore());
    }

    public function testNextOnAnExhaustedQueueIsAProgrammingError(): void
    {
        $this->expectException(\LogicException::class);

        $this->queue([])->next();
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php bin/phpunit tests/Service/Fetch/FetchQueueTest.php`
Expected: FAIL — `Class "App\Service\Fetch\FetchQueue" not found`.

- [ ] **Step 3: Write the implementation**

`backend/src/Service/Fetch/FetchQueue.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Fetch;

/**
 * The engine's work list: redirect continuations first, then tickets not yet
 * started. Continuations jump the queue because they already hold an open
 * redirect chain — deferring them behind fresh work would let a chain sit
 * half-finished while the concurrency slots fill with new feeds.
 *
 * Mutable by design; it is the one piece of the fetch loop that has to be.
 */
final class FetchQueue
{
    /** @var list<FetchAttempt> */
    private array $continuations = [];

    /** @param \Iterator<int|string, FetchTicket> $tickets */
    public function __construct(private readonly \Iterator $tickets)
    {
    }

    public function requeue(FetchAttempt $attempt): void
    {
        $this->continuations[] = $attempt;
    }

    public function hasMore(): bool
    {
        return [] !== $this->continuations || $this->tickets->valid();
    }

    public function next(): FetchAttempt
    {
        $continuation = array_shift($this->continuations);
        if (null !== $continuation) {
            return $continuation;
        }

        if (!$this->tickets->valid()) {
            throw new \LogicException('next() called on an exhausted queue; guard with hasMore().');
        }

        $attempt = FetchAttempt::start($this->tickets->key(), $this->tickets->current());
        $this->tickets->next();

        return $attempt;
    }
}
```

`$this->tickets->valid()` on a generator runs it up to its first `yield`, which is what makes the runner's budget gate in Task 9 work: the generator decides, at pull time, whether to hand over another ticket.

- [ ] **Step 4: Run test to verify it passes**

Run: `php bin/phpunit tests/Service/Fetch/FetchQueueTest.php`
Expected: PASS, 4 tests.

- [ ] **Step 5: Commit**

```bash
git add backend/src/Service/Fetch/FetchQueue.php backend/tests/Service/Fetch/FetchQueueTest.php
git commit -m "feat(fetch): add FetchQueue for tickets and redirect continuations (#116)"
```

---

## Task 5: BatchFeedFetcherInterface and ConcurrentFeedFetcher

**Files:**
- Create: `backend/src/Service/Fetch/BatchFeedFetcherInterface.php`
- Create: `backend/src/Service/Fetch/ConcurrentFeedFetcher.php`
- Test: `backend/tests/Service/Fetch/ConcurrentFeedFetcherTest.php`

This is the hard task. Build it against `MockHttpClient`, which implements `stream()` faithfully.

- [ ] **Step 1: Write the failing test**

`backend/tests/Service/Fetch/ConcurrentFeedFetcherTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Fetch;

use App\Service\Fetch\ConcurrentFeedFetcher;
use App\Service\Fetch\DnsResolverInterface;
use App\Service\Fetch\Exception\FeedGoneException;
use App\Service\Fetch\Exception\FeedUnreachableException;
use App\Service\Fetch\Exception\SsrfBlockedException;
use App\Service\Fetch\FetchTicket;
use App\Service\Fetch\IpValidator;
use App\Service\Fetch\ResponseClassifier;
use App\Service\Fetch\UrlGuard;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class ConcurrentFeedFetcherTest extends TestCase
{
    /**
     * Every host in these tests resolves to one public address; the SSRF rules
     * themselves are covered by UrlGuard's own test.
     */
    private function fetcher(callable|iterable $responses, int $concurrency = 4): ConcurrentFeedFetcher
    {
        $resolver = new class () implements DnsResolverInterface {
            public function resolve(string $hostname): array
            {
                return 'blocked.example.com' === $hostname ? [] : ['93.184.216.34'];
            }
        };

        return new ConcurrentFeedFetcher(
            new MockHttpClient($responses),
            new UrlGuard($resolver, new IpValidator()),
            new ResponseClassifier(),
            $concurrency,
        );
    }

    /** @return array<int|string, \App\Service\Fetch\FetchOutcome> */
    private function collect(iterable $outcomes): array
    {
        $collected = [];
        foreach ($outcomes as $key => $outcome) {
            $collected[$key] = $outcome;
        }

        return $collected;
    }

    public function testFetchesASingleTicket(): void
    {
        $fetcher = $this->fetcher([new MockResponse('<rss/>', ['http_code' => 200])]);

        $outcomes = $this->collect($fetcher->fetchAll([7 => new FetchTicket('https://example.com/feed')]));

        self::assertCount(1, $outcomes);
        self::assertSame('<rss/>', $outcomes[7]->responseOrThrow()->body);
    }

    public function testFetchesEveryTicketInABatch(): void
    {
        $fetcher = $this->fetcher(static fn (string $method, string $url): MockResponse => new MockResponse(
            '<rss><channel><title>' . $url . '</title></channel></rss>',
            ['http_code' => 200],
        ));

        $outcomes = $this->collect($fetcher->fetchAll([
            1 => new FetchTicket('https://one.example.com/feed'),
            2 => new FetchTicket('https://two.example.com/feed'),
            3 => new FetchTicket('https://three.example.com/feed'),
            4 => new FetchTicket('https://four.example.com/feed'),
            5 => new FetchTicket('https://five.example.com/feed'),
        ]));

        self::assertCount(5, $outcomes);
        foreach ([1, 2, 3, 4, 5] as $key) {
            self::assertNull($outcomes[$key]->failure(), sprintf('ticket %d should have succeeded', $key));
        }
        // Concurrency is 4, so the fifth only starts once a slot frees.
        self::assertStringContainsString('five.example.com', (string) $outcomes[5]->responseOrThrow()->body);
    }

    public function testOneFailureDoesNotAbandonTheRestOfTheBatch(): void
    {
        $fetcher = $this->fetcher(static fn (string $method, string $url): MockResponse => str_contains($url, 'bad')
            ? new MockResponse('', ['http_code' => 500])
            : new MockResponse('<rss/>', ['http_code' => 200]));

        $outcomes = $this->collect($fetcher->fetchAll([
            1 => new FetchTicket('https://good.example.com/feed'),
            2 => new FetchTicket('https://bad.example.com/feed'),
            3 => new FetchTicket('https://alsogood.example.com/feed'),
        ]));

        self::assertCount(3, $outcomes);
        self::assertNull($outcomes[1]->failure());
        self::assertInstanceOf(FeedUnreachableException::class, $outcomes[2]->failure());
        self::assertNull($outcomes[3]->failure());
    }

    public function testAGoneFeedIsReportedAsAnOutcomeNotAThrow(): void
    {
        $fetcher = $this->fetcher([new MockResponse('', ['http_code' => 410])]);

        $outcomes = $this->collect($fetcher->fetchAll([1 => new FetchTicket('https://example.com/feed')]));

        self::assertInstanceOf(FeedGoneException::class, $outcomes[1]->failure());
    }

    public function testAnSsrfBlockIsReportedAsAnOutcomeNotAThrow(): void
    {
        $fetcher = $this->fetcher([]);

        $outcomes = $this->collect($fetcher->fetchAll([1 => new FetchTicket('https://blocked.example.com/feed')]));

        self::assertInstanceOf(SsrfBlockedException::class, $outcomes[1]->failure());
    }

    public function testFollowsARedirectChainAndReportsItPermanent(): void
    {
        $fetcher = $this->fetcher([
            new MockResponse('', ['http_code' => 301, 'response_headers' => ['location' => 'https://example.com/one']]),
            new MockResponse('', ['http_code' => 302, 'response_headers' => ['location' => 'https://example.com/two']]),
            new MockResponse('<rss/>', ['http_code' => 200]),
        ]);

        $outcomes = $this->collect($fetcher->fetchAll([1 => new FetchTicket('https://example.com/feed')]));

        $response = $outcomes[1]->responseOrThrow();
        self::assertSame('https://example.com/two', $response->finalUrl);
        self::assertTrue($response->permanentRedirect);
        self::assertSame('<rss/>', $response->body);
    }

    public function testARedirectLoopIsCutOffAfterFiveHops(): void
    {
        $fetcher = $this->fetcher(static fn (): MockResponse => new MockResponse('', [
            'http_code' => 302,
            'response_headers' => ['location' => 'https://example.com/next'],
        ]));

        $outcomes = $this->collect($fetcher->fetchAll([1 => new FetchTicket('https://example.com/feed')]));

        $failure = $outcomes[1]->failure();
        self::assertInstanceOf(FeedUnreachableException::class, $failure);
        self::assertStringContainsString('more than 5 redirects', $failure->getMessage());
    }

    public function testAFeedStillRedirectingDoesNotBlockOthersFromCompleting(): void
    {
        $redirects = 0;
        $fetcher = $this->fetcher(
            static function (string $method, string $url) use (&$redirects): MockResponse {
                if (str_contains($url, 'slow')) {
                    $redirects++;

                    return new MockResponse('', [
                        'http_code' => 302,
                        'response_headers' => ['location' => 'https://slow.example.com/hop' . $redirects],
                    ]);
                }

                return new MockResponse('<rss/>', ['http_code' => 200]);
            },
            concurrency: 2,
        );

        $outcomes = $this->collect($fetcher->fetchAll([
            1 => new FetchTicket('https://slow.example.com/feed'),
            2 => new FetchTicket('https://fast.example.com/feed'),
        ]));

        self::assertNull($outcomes[2]->failure());
        self::assertInstanceOf(FeedUnreachableException::class, $outcomes[1]->failure());
    }

    public function testSendsConditionalGetHeaders(): void
    {
        $seen = [];
        $fetcher = $this->fetcher(static function (string $method, string $url, array $options) use (&$seen): MockResponse {
            $seen = $options['normalized_headers'] ?? [];

            return new MockResponse('', ['http_code' => 304]);
        });

        $this->collect($fetcher->fetchAll([
            1 => new FetchTicket('https://example.com/feed', '"v1"', 'Mon, 20 Jul 2026 08:30:00 GMT'),
        ]));

        self::assertSame(['if-none-match: "v1"'], $seen['if-none-match']);
        self::assertSame(['if-modified-since: Mon, 20 Jul 2026 08:30:00 GMT'], $seen['if-modified-since']);
    }

    public function testAbandoningTheGeneratorCancelsWhatIsStillInFlight(): void
    {
        $started = 0;
        $fetcher = $this->fetcher(
            static function () use (&$started): MockResponse {
                $started++;

                return new MockResponse('<rss/>', ['http_code' => 200]);
            },
            concurrency: 3,
        );

        foreach ($fetcher->fetchAll([
            1 => new FetchTicket('https://one.example.com/feed'),
            2 => new FetchTicket('https://two.example.com/feed'),
            3 => new FetchTicket('https://three.example.com/feed'),
            4 => new FetchTicket('https://four.example.com/feed'),
            5 => new FetchTicket('https://five.example.com/feed'),
        ]) as $outcome) {
            self::assertNull($outcome->failure());
            break;
        }

        // Three slots were filled; the fourth and fifth tickets were never
        // pulled, so an aborted run stops making requests instead of draining
        // the whole batch.
        self::assertSame(3, $started);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php bin/phpunit tests/Service/Fetch/ConcurrentFeedFetcherTest.php`
Expected: FAIL — `Class "App\Service\Fetch\ConcurrentFeedFetcher" not found`.

- [ ] **Step 3: Make the redirect limit reachable**

`ConcurrentFeedFetcher` has to name the limit in its error message, and today's `HttpFeedFetcher` keeps that message and its loop bound on one constant. Task 2 made `FetchAttempt::MAX_REDIRECTS` private, which would force a bare `5` literal here — three unlinked places for one number. Change its visibility:

```php
    public const int MAX_REDIRECTS = 5;
```

Enforcement stays inside `canFollowRedirect()`; the constant is public only so the message can quote it.

- [ ] **Step 4: Write the interface**

`backend/src/Service/Fetch/BatchFeedFetcherInterface.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Fetch;

interface BatchFeedFetcherInterface
{
    /**
     * Fetch many feeds concurrently, with SSRF protection and conditional-GET
     * support, yielding each result under its ticket's key as soon as it lands.
     *
     * Never throws for an individual feed: a failure arrives as a FetchOutcome
     * carrying its exception, so one bad feed cannot abandon the others.
     * Abandoning the returned iterator cancels whatever is still in flight.
     *
     * @param iterable<int|string, FetchTicket> $tickets
     *
     * @return iterable<int|string, FetchOutcome>
     */
    public function fetchAll(iterable $tickets): iterable;
}
```

- [ ] **Step 5: Write the engine**

`backend/src/Service/Fetch/ConcurrentFeedFetcher.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Fetch;

use App\Service\Fetch\Exception\FeedUnreachableException;
use App\Service\Fetch\Exception\FetchException;
use App\Service\Fetch\Exception\ResponseTooLargeException;
use Symfony\Contracts\HttpClient\ChunkInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Fetches many feeds at once over Symfony's multiplexing HTTP client.
 *
 * The refresh sweep is network-wait-bound — measured at 5.37 s of waiting across
 * 24 feeds against 0.4 s of parsing — so the requests overlap while the caller
 * still processes results one at a time.
 */
final class ConcurrentFeedFetcher implements BatchFeedFetcherInterface
{
    private const int MAX_BYTES = 5_000_000;
    private const float TIMEOUT_SECONDS = 10.0;
    private const string USER_AGENT = 'SimpleFeedReader/1.0 (+https://github.com/larspohlmann/simple-feed-reader)';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly UrlGuard $urlGuard,
        private readonly ResponseClassifier $classifier,
        private readonly int $concurrency,
    ) {
    }

    public function fetchAll(iterable $tickets): \Generator
    {
        $queue = new FetchQueue($this->iterator($tickets));
        /** @var \SplObjectStorage<ResponseInterface, FetchAttempt> $inFlight */
        $inFlight = new \SplObjectStorage();

        try {
            while (true) {
                yield from $this->fill($queue, $inFlight);

                if (0 === $inFlight->count()) {
                    return;
                }

                yield from $this->awaitNext($queue, $inFlight);
            }
        } finally {
            // Reached on `break` as well as on completion: an aborted run must
            // not leave sockets open behind the caller's back.
            foreach ($inFlight as $response) {
                $response->cancel();
            }
        }
    }

    /**
     * Opens requests until the concurrency cap is reached or the queue dries up.
     * A URL the guard rejects never becomes a request, so it is reported here.
     *
     * @param \SplObjectStorage<ResponseInterface, FetchAttempt> $inFlight
     *
     * @return \Generator<int|string, FetchOutcome>
     */
    private function fill(FetchQueue $queue, \SplObjectStorage $inFlight): \Generator
    {
        while ($inFlight->count() < $this->concurrency && $queue->hasMore()) {
            $attempt = $queue->next();

            try {
                $inFlight[$this->send($attempt)] = $attempt;
            } catch (FetchException $e) {
                yield $attempt->key => FetchOutcome::failed($e);
            }
        }
    }

    /**
     * Streams the in-flight set until one response resolves, then returns so the
     * freed slot can be refilled. Redirects go back on the queue rather than
     * being followed inline, which is what lets a feed on its fourth hop share
     * the loop with one on its first.
     *
     * @param \SplObjectStorage<ResponseInterface, FetchAttempt> $inFlight
     *
     * @return \Generator<int|string, FetchOutcome>
     */
    private function awaitNext(FetchQueue $queue, \SplObjectStorage $inFlight): \Generator
    {
        foreach ($this->httpClient->stream($inFlight, self::TIMEOUT_SECONDS) as $response => $chunk) {
            $attempt = $inFlight[$response];

            try {
                $verdict = $this->advance($response, $chunk, $attempt);
            } catch (FetchException $e) {
                $this->retire($inFlight, $response);

                yield $attempt->key => FetchOutcome::failed($e);

                return;
            }

            if (null === $verdict) {
                continue;
            }

            $this->retire($inFlight, $response);

            if ($verdict instanceof FetchAttempt) {
                $queue->requeue($verdict);

                return;
            }

            yield $attempt->key => FetchOutcome::succeeded($verdict);

            return;
        }
    }

    /**
     * One chunk's worth of progress: null while the response is still arriving,
     * a FetchResponse when it is done, or the next FetchAttempt on a redirect.
     *
     * @throws FetchException
     */
    private function advance(
        ResponseInterface $response,
        ChunkInterface $chunk,
        FetchAttempt $attempt,
    ): FetchResponse|FetchAttempt|null {
        try {
            if ($chunk->isTimeout()) {
                throw new FeedUnreachableException(sprintf('%s: timed out', $attempt->url));
            }

            if ($chunk->isFirst()) {
                return $this->onHeaders($response, $attempt);
            }

            return $chunk->isLast() ? $this->classifier->fromBody($response, $attempt) : null;
        } catch (ExceptionInterface $e) {
            $this->rethrowTooLarge($e);

            throw new FeedUnreachableException(sprintf('%s: %s', $attempt->url, $e->getMessage()), previous: $e);
        }
    }

    /** @throws FetchException */
    private function onHeaders(ResponseInterface $response, FetchAttempt $attempt): FetchResponse|FetchAttempt|null
    {
        $verdict = $this->classifier->fromHeaders($response, $attempt);

        if (HeaderDecision::AwaitBody === $verdict->decision) {
            return null;
        }

        if (HeaderDecision::Terminal === $verdict->decision) {
            \assert(null !== $verdict->response);

            return $verdict->response;
        }

        if (!$attempt->canFollowRedirect()) {
            throw new FeedUnreachableException(sprintf(
                '%s: more than %d redirects',
                $attempt->ticket->url,
                FetchAttempt::MAX_REDIRECTS,
            ));
        }

        \assert(null !== $verdict->redirectUrl);

        return $attempt->followedTo($verdict->redirectUrl, $verdict->permanent);
    }

    /** @param \SplObjectStorage<ResponseInterface, FetchAttempt> $inFlight */
    private function retire(\SplObjectStorage $inFlight, ResponseInterface $response): void
    {
        $inFlight->detach($response);
        $response->cancel();
    }

    /** @throws FetchException when the URL fails the SSRF guard */
    private function send(FetchAttempt $attempt): ResponseInterface
    {
        $guarded = $this->urlGuard->assertSafe($attempt->url);

        try {
            return $this->httpClient->request('GET', $attempt->url, [
                'headers' => $this->headers($attempt->ticket),
                'max_redirects' => 0,
                'timeout' => self::TIMEOUT_SECONDS,
                'max_duration' => self::TIMEOUT_SECONDS * 2,
                'resolve' => [$guarded->host => $guarded->ip],
                'on_progress' => static function (int $downloaded): void {
                    if ($downloaded > self::MAX_BYTES) {
                        throw new ResponseTooLargeException(sprintf('response exceeds %d bytes', self::MAX_BYTES));
                    }
                },
            ]);
        } catch (ExceptionInterface $e) {
            $this->rethrowTooLarge($e);

            throw new FeedUnreachableException(sprintf('%s: %s', $attempt->url, $e->getMessage()), previous: $e);
        }
    }

    /** @return array<string, string> */
    private function headers(FetchTicket $ticket): array
    {
        $headers = [
            'Accept' => 'application/rss+xml, application/atom+xml, application/xml;q=0.9, text/xml;q=0.8, */*;q=0.1',
            // Refuse transparent compression so the MAX_BYTES cap (counted on the
            // wire in on_progress) also bounds the buffered body — a compressed
            // response would otherwise decompress unbounded before the size check.
            'Accept-Encoding' => 'identity',
            'User-Agent' => self::USER_AGENT,
        ];
        if (null !== $ticket->etag) {
            $headers['If-None-Match'] = $ticket->etag;
        }
        if (null !== $ticket->lastModified) {
            $headers['If-Modified-Since'] = $ticket->lastModified;
        }

        return $headers;
    }

    /**
     * @param iterable<int|string, FetchTicket> $tickets
     *
     * @return \Iterator<int|string, FetchTicket>
     */
    private function iterator(iterable $tickets): \Iterator
    {
        if ($tickets instanceof \Iterator) {
            return $tickets;
        }

        return $tickets instanceof \IteratorAggregate
            ? new \IteratorIterator($tickets)
            : new \ArrayIterator(iterator_to_array($tickets));
    }

    /**
     * The HTTP client wraps exceptions thrown inside on_progress; unwrap and
     * rethrow our size-limit exception so callers see the real cause.
     */
    private function rethrowTooLarge(?\Throwable $e): void
    {
        while (null !== $e) {
            if ($e instanceof ResponseTooLargeException) {
                throw $e;
            }
            $e = $e->getPrevious();
        }
    }
}
```

- [ ] **Step 6: Run the tests**

Run: `php bin/phpunit tests/Service/Fetch/ConcurrentFeedFetcherTest.php`
Expected: PASS, 11 tests.

**If `testAbandoningTheGeneratorCancelsWhatIsStillInFlight` fails on the count:** `MockHttpClient` may count a request at `request()` time rather than on first read. Assert on `$fetcher`-observable behaviour instead — record the requested URLs in the response factory and assert the fourth and fifth URLs are absent. Do not weaken the assertion to `assertLessThan`.

**If `testSendsConditionalGetHeaders` fails on the option key:** dump `$options` and read the actual normalisation. `HttpFeedFetcherTest::testSendsConditionalGetHeaders` already asserts this successfully — copy its exact accessor rather than inventing one.

- [ ] **Step 7: Run the gates**

Run: `composer check && composer md`
Expected: no findings. `ConcurrentFeedFetcher` is the class most at risk of tripping PHPMD length/complexity — if it does, extract, do not suppress.

- [ ] **Step 8: Commit**

```bash
git add backend/src/Service/Fetch/FetchAttempt.php backend/src/Service/Fetch/BatchFeedFetcherInterface.php \
  backend/src/Service/Fetch/ConcurrentFeedFetcher.php backend/tests/Service/Fetch/ConcurrentFeedFetcherTest.php
```

---

## Task 6: HttpFeedFetcher becomes an adapter

**Files:**
- Modify: `backend/src/Service/Fetch/HttpFeedFetcher.php` (full rewrite)
- Modify: `backend/tests/Service/Fetch/HttpFeedFetcherTest.php:22-42` (the `fetcher()` helper **only**)
- Modify: `backend/config/services.yaml`
- Modify: `backend/config/services_test.yaml`

The 14 existing cases in `HttpFeedFetcherTest` are the proof that the serial path survived. Change the helper that builds the subject; change nothing else in that file.

- [ ] **Step 1: Rewrite HttpFeedFetcher**

`backend/src/Service/Fetch/HttpFeedFetcher.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Fetch;

use App\Service\Fetch\Exception\FeedUnreachableException;

/**
 * Single-URL adapter over the batch engine, for the callers that genuinely want
 * one feed and can afford to block: discovery, preview, favicon resolution and
 * the backfill command.
 *
 * It delegates rather than implementing a second fetch loop on purpose — the
 * redirect and status-code rules are an SSRF control, and two copies of them
 * would drift.
 */
final class HttpFeedFetcher implements FeedFetcherInterface
{
    public function __construct(private readonly BatchFeedFetcherInterface $fetcher)
    {
    }

    public function fetch(string $url, ?string $etag = null, ?string $lastModified = null): FetchResponse
    {
        foreach ($this->fetcher->fetchAll([new FetchTicket($url, $etag, $lastModified)]) as $outcome) {
            return $outcome->responseOrThrow();
        }

        // The engine yields exactly one outcome per ticket, so an empty result
        // means the batch contract was broken rather than the feed being at fault.
        throw new FeedUnreachableException(sprintf('%s: the fetcher returned no outcome', $url));
    }
}
```

- [ ] **Step 2: Update only the test helper**

In `backend/tests/Service/Fetch/HttpFeedFetcherTest.php`, replace the body of `fetcher()`'s return statement:

```php
        return new HttpFeedFetcher(new ConcurrentFeedFetcher(
            new MockHttpClient($responses),
            new UrlGuard($resolver, new IpValidator()),
            new ResponseClassifier(),
            1,
        ));
```

Add `use App\Service\Fetch\ConcurrentFeedFetcher;` and `use App\Service\Fetch\ResponseClassifier;` to the imports. **Do not touch any `public function test…` in this file.**

- [ ] **Step 3: Wire the container**

In `backend/config/services.yaml`, fill in the (currently empty) `parameters:` block:

```yaml
parameters:
    # How many feed requests a refresh sweep keeps in flight. A parameter rather
    # than a class constant so the shared Strato host can be dialled down in
    # config alone. Eight x the 5 MB response cap is 40 MB against a 512 MB limit.
    fetch_concurrency: 8
```

and under `_defaults.bind`, add:

```yaml
            int $fetchConcurrency: '%fetch_concurrency%'
```

Next to the existing interface aliases, add:

```yaml
    App\Service\Fetch\BatchFeedFetcherInterface: '@App\Service\Fetch\ConcurrentFeedFetcher'
```

In `backend/config/services_test.yaml`, add alongside the existing `FeedFetcherInterface` entry:

```yaml
    # RefreshRunner now depends on the batch interface, and the functional tests
    # swap it for a stub via `self::getContainer()->set(...)`, which the container
    # only allows on public services. Test environment only.
    App\Service\Fetch\BatchFeedFetcherInterface:
        alias: App\Service\Fetch\ConcurrentFeedFetcher
        public: true
```

- [ ] **Step 4: Run the full suite**

Run: `php bin/phpunit`
Expected: PASS. `HttpFeedFetcherTest`'s 14 cases green with no assertion edited is the signal that the serial path is behaviour-identical.

- [ ] **Step 5: Verify the container builds**

Run: `php bin/console lint:container`
Expected: no errors — this catches a mistyped alias or an unbound `$fetchConcurrency`.

- [ ] **Step 6: Run the gates and commit**

```bash
composer check && composer md
git add backend/src/Service/Fetch/HttpFeedFetcher.php backend/tests/Service/Fetch/HttpFeedFetcherTest.php backend/config/services.yaml backend/config/services_test.yaml
git commit -m "refactor(fetch): make HttpFeedFetcher a single-URL adapter (#116)"
```

---

## Task 7: StubFeedFetcher implements both interfaces

**Files:**
- Modify: `backend/tests/Support/StubFeedFetcher.php`

The stub models concurrency as **waves**: each pass takes up to `$concurrency` tickets and advances the `MockClock` by `secondsPerFetch` once for the whole wave. That is what makes the rewritten budget tests in Task 9 meaningful — three feeds at concurrency 3 cost one wave, not three.

- [ ] **Step 1: Rewrite the stub**

`backend/tests/Support/StubFeedFetcher.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Service\Fetch\BatchFeedFetcherInterface;
use App\Service\Fetch\Exception\FetchException;
use App\Service\Fetch\FeedFetcherInterface;
use App\Service\Fetch\FetchOutcome;
use App\Service\Fetch\FetchResponse;
use App\Service\Fetch\FetchTicket;
use Symfony\Component\Clock\MockClock;

final class StubFeedFetcher implements FeedFetcherInterface, BatchFeedFetcherInterface
{
    /** @var array<string, FetchResponse|FetchException> */
    private array $results = [];

    /** @var list<string> */
    public array $fetchedUrls = [];

    /**
     * Wall-clock cost of one wave of concurrent fetches, not of one fetch. A
     * batch of `concurrency` feeds advances the clock once.
     */
    public int $secondsPerFetch = 0;

    public function __construct(
        private readonly ?MockClock $clock = null,
        private readonly int $concurrency = 8,
    ) {
    }

    public function willReturn(string $url, FetchResponse $response): void
    {
        $this->results[$url] = $response;
    }

    public function willThrow(string $url, FetchException $exception): void
    {
        $this->results[$url] = $exception;
    }

    public function fetch(string $url, ?string $etag = null, ?string $lastModified = null): FetchResponse
    {
        foreach ($this->fetchAll([new FetchTicket($url, $etag, $lastModified)]) as $outcome) {
            return $outcome->responseOrThrow();
        }

        throw new \LogicException('No outcome for ' . $url);
    }

    public function fetchAll(iterable $tickets): \Generator
    {
        $wave = [];

        foreach ($tickets as $key => $ticket) {
            $wave[$key] = $ticket;
            if (\count($wave) < $this->concurrency) {
                continue;
            }

            yield from $this->runWave($wave);
            $wave = [];
        }

        if ([] !== $wave) {
            yield from $this->runWave($wave);
        }
    }

    /**
     * @param array<int|string, FetchTicket> $wave
     *
     * @return \Generator<int|string, FetchOutcome>
     */
    private function runWave(array $wave): \Generator
    {
        if ($this->secondsPerFetch > 0) {
            $this->clock?->sleep($this->secondsPerFetch);
        }

        foreach ($wave as $key => $ticket) {
            $this->fetchedUrls[] = $ticket->url;

            $result = $this->results[$ticket->url] ?? throw new \LogicException('No stubbed result for ' . $ticket->url);

            yield $key => $result instanceof FetchException
                ? FetchOutcome::failed($result)
                : FetchOutcome::succeeded($result);
        }
    }
}
```

Note: the wave advances the clock **before** yielding, so a caller that consumes the first outcome has already paid the wave's cost — matching the real engine, where nothing is readable until the network has answered.

- [ ] **Step 2: Run the suite to see what breaks**

Run: `php bin/phpunit`
Expected: `RefreshRunnerTest` budget tests FAIL (they encode serial semantics — Task 9 rewrites them). `FaviconResolverTest` should still pass, since `fetch()` still works. Everything else green.

Do **not** fix `RefreshRunnerTest` here. Commit the stub and move on.

- [ ] **Step 3: Commit**

```bash
git add backend/tests/Support/StubFeedFetcher.php
git commit -m "test: teach StubFeedFetcher the batch interface (#116)"
```

---

## Task 8: BudgetedFeedQueue

**Files:**
- Create: `backend/src/Service/Refresh/BudgetedFeedQueue.php`
- Test: `backend/tests/Service/Refresh/BudgetedFeedQueueTest.php`

This is the fan-out bookkeeping the spec keeps out of `RefreshRunner`. It hands the engine tickets one at a time and stops once the deadline is close, counting what it let through.

- [ ] **Step 1: Write the failing test**

`backend/tests/Service/Refresh/BudgetedFeedQueueTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Refresh;

use App\Entity\Feed;
use App\Service\Refresh\BudgetedFeedQueue;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

final class BudgetedFeedQueueTest extends TestCase
{
    /** @return list<Feed> */
    private function feeds(int $count): array
    {
        return array_map(
            static fn (int $index): Feed => new Feed(sprintf('https://feed%d.example.com/rss', $index)),
            range(1, $count),
        );
    }

    public function testYieldsEveryFeedWhenTheBudgetIsAmple(): void
    {
        $clock = new MockClock('2026-07-26 12:00:00', 'UTC');
        $queue = new BudgetedFeedQueue($this->feeds(3), $clock, $clock->now()->getTimestamp() + 300);

        $tickets = iterator_to_array($queue->tickets(), preserve_keys: false);

        self::assertCount(3, $tickets);
        self::assertSame(3, $queue->startedCount());
        self::assertSame(0, $queue->skippedCount());
    }

    public function testCarriesTheFeedsConditionalGetHeaders(): void
    {
        $clock = new MockClock('2026-07-26 12:00:00', 'UTC');
        $feed = new Feed('https://one.example.com/feed');
        $feed->setEtag('"v1"');
        $feed->setLastModified('Mon, 20 Jul 2026 08:30:00 GMT');

        $queue = new BudgetedFeedQueue([$feed], $clock, $clock->now()->getTimestamp() + 300);
        $tickets = iterator_to_array($queue->tickets(), preserve_keys: false);

        self::assertSame('https://one.example.com/feed', $tickets[0]->url);
        self::assertSame('"v1"', $tickets[0]->etag);
        self::assertSame('Mon, 20 Jul 2026 08:30:00 GMT', $tickets[0]->lastModified);
    }

    public function testStopsYieldingOnceTheDeadlineIsWithinTheSafetyMargin(): void
    {
        $clock = new MockClock('2026-07-26 12:00:00', 'UTC');
        $queue = new BudgetedFeedQueue($this->feeds(3), $clock, $clock->now()->getTimestamp() + 100);

        $taken = [];
        foreach ($queue->tickets() as $ticket) {
            $taken[] = $ticket;
            // Simulate a wave that consumed almost the whole budget.
            $clock->sleep(95);
        }

        self::assertCount(1, $taken);
        self::assertSame(1, $queue->startedCount());
        self::assertSame(2, $queue->skippedCount());
    }

    /**
     * The user endpoint polls until `remaining` reaches 0. A run that starts
     * nothing leaves `remaining` unchanged and the client spins forever.
     */
    public function testAlwaysYieldsTheFirstFeedEvenWithNoBudgetLeft(): void
    {
        $clock = new MockClock('2026-07-26 12:00:00', 'UTC');
        $queue = new BudgetedFeedQueue($this->feeds(3), $clock, $clock->now()->getTimestamp() - 60);

        $taken = iterator_to_array($queue->tickets(), preserve_keys: false);

        self::assertCount(1, $taken);
        self::assertSame(1, $queue->startedCount());
        self::assertSame(2, $queue->skippedCount());
    }

    public function testAnEmptyFeedListStartsAndSkipsNothing(): void
    {
        $clock = new MockClock('2026-07-26 12:00:00', 'UTC');
        $queue = new BudgetedFeedQueue([], $clock, $clock->now()->getTimestamp() + 300);

        self::assertSame([], iterator_to_array($queue->tickets(), preserve_keys: false));
        self::assertSame(0, $queue->startedCount());
        self::assertSame(0, $queue->skippedCount());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php bin/phpunit tests/Service/Refresh/BudgetedFeedQueueTest.php`
Expected: FAIL — `Class "App\Service\Refresh\BudgetedFeedQueue" not found`.

- [ ] **Step 3: Write the implementation**

`backend/src/Service/Refresh/BudgetedFeedQueue.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Refresh;

use App\Entity\Feed;
use App\Service\Fetch\FetchTicket;
use Symfony\Component\Clock\ClockInterface;

/**
 * Feeds the fetch engine tickets while the time budget allows, and remembers how
 * many it let through.
 *
 * The engine pulls lazily, one ticket per free slot, so the deadline is checked
 * at the moment a fetch would start rather than up front — a wave that finishes
 * early therefore buys the next feed its chance.
 */
final class BudgetedFeedQueue
{
    private const int SAFETY_MARGIN_SECONDS = 10;

    private int $started = 0;

    /** @param list<Feed> $feeds */
    public function __construct(
        private readonly array $feeds,
        private readonly ClockInterface $clock,
        private readonly int $deadline,
    ) {
    }

    /** @return \Generator<int, FetchTicket> */
    public function tickets(): \Generator
    {
        foreach ($this->feeds as $feed) {
            if (!$this->mayStartAnother()) {
                return;
            }

            $this->started++;

            yield (int) $feed->getId() => new FetchTicket(
                $feed->getUrl(),
                $feed->getEtag(),
                $feed->getLastModified(),
            );
        }
    }

    public function startedCount(): int
    {
        return $this->started;
    }

    public function skippedCount(): int
    {
        return \count($this->feeds) - $this->started;
    }

    /**
     * The first feed is always started. A run that returns without touching
     * anything leaves `remaining` unchanged, and the user endpoint polls until
     * `remaining` hits 0 — so a budget at or below the safety margin would spin
     * the client forever. One feed per call is slow; zero never terminates.
     */
    private function mayStartAnother(): bool
    {
        if (0 === $this->started) {
            return true;
        }

        return $this->deadline - $this->clock->now()->getTimestamp() >= self::SAFETY_MARGIN_SECONDS;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php bin/phpunit tests/Service/Refresh/BudgetedFeedQueueTest.php`
Expected: PASS, 5 tests.

Note the keyed `yield` inside a `@return \Generator<int, FetchTicket>` — PHPStan wants `\Generator<int, FetchTicket, mixed, void>` if it complains about the key type. Fix the annotation, not the code.

- [ ] **Step 5: Commit**

```bash
git add backend/src/Service/Refresh/BudgetedFeedQueue.php backend/tests/Service/Refresh/BudgetedFeedQueueTest.php
git commit -m "feat(refresh): add BudgetedFeedQueue for lazy, deadline-gated fan-out (#116)"
```

---

## Task 9: RefreshRunner consumes the batch

**Files:**
- Modify: `backend/src/Service/Refresh/RefreshRunner.php:15,51,77-176`
- Modify: `backend/tests/Service/Refresh/RefreshRunnerTest.php`

- [ ] **Step 1: Rewrite the failing budget tests first**

In `backend/tests/Service/Refresh/RefreshRunnerTest.php`, replace `testBudgetExhaustionSkipsRemainingFeeds` (line ~297) with a version that pins concurrency so waves are observable:

```php
    /**
     * With concurrency 1 the engine starts one feed per wave, so the deadline is
     * re-checked between each — the same skid the serial runner had, now
     * expressed in terms of when a fetch may *start*.
     */
    public function testBudgetExhaustionSkipsFeedsThatWereNeverStarted(): void
    {
        $first = $this->dueFeed('https://one.example.com/feed');
        $second = $this->dueFeed('https://two.example.com/feed');
        $third = $this->dueFeed('https://three.example.com/feed');
        $this->em->flush();

        $this->fetcher = new StubFeedFetcher($this->clock, concurrency: 1);
        foreach ([$first, $second, $third] as $index => $feed) {
            $this->fetcher->willReturn(
                $feed->getUrl(),
                FetchResponse::fetched($feed->getUrl(), false, $this->rss('F' . $index, 'g-' . $index), null, null),
            );
        }
        $this->fetcher->secondsPerFetch = 100;

        $report = $this->runner()->run(RefreshRequest::allDue(205));

        // 100 s + 100 s spent leaves 5 s — below the 10 s safety margin, so the
        // third feed never starts and stays due for the next run.
        self::assertSame('partial', $report->status);
        self::assertSame(2, $report->fetched);
        self::assertSame(1, $report->skippedForBudget);
        self::assertSame(1, $report->remaining);
        self::assertCount(2, $this->fetcher->fetchedUrls);
    }

    /**
     * The counterpart to the test above, and the actual point of this change: at
     * a realistic concurrency the same three feeds all fit in one wave, so a
     * budget that used to skip one now completes the sweep.
     */
    public function testAConcurrentWaveCompletesWithinABudgetThatSerialWouldExhaust(): void
    {
        foreach (['one', 'two', 'three'] as $index => $name) {
            $feed = $this->dueFeed(sprintf('https://%s.example.com/feed', $name));
            $this->fetcher->willReturn(
                $feed->getUrl(),
                FetchResponse::fetched($feed->getUrl(), false, $this->rss('F' . $index, 'g-' . $index), null, null),
            );
        }
        $this->em->flush();
        $this->fetcher->secondsPerFetch = 100;

        $report = $this->runner()->run(RefreshRequest::allDue(205));

        self::assertSame('completed', $report->status);
        self::assertSame(3, $report->fetched);
        self::assertSame(0, $report->skippedForBudget);
        self::assertSame(0, $report->remaining);
    }
```

Replace `testBudgetSmallerThanTheSafetyMarginStillProcessesOneFeed` (line ~332) with:

```php
    /**
     * The user endpoint polls until `remaining` reaches 0. A run that processes
     * nothing leaves `remaining` unchanged, so a budget at or below the safety
     * margin would spin the client forever. Guarantee one feed of progress.
     */
    public function testBudgetSmallerThanTheSafetyMarginStillProcessesOneFeed(): void
    {
        $first = $this->dueFeed('https://one.example.com/feed');
        $second = $this->dueFeed('https://two.example.com/feed');
        $this->em->flush();

        $this->fetcher = new StubFeedFetcher($this->clock, concurrency: 1);
        foreach ([$first, $second] as $feed) {
            $this->fetcher->willReturn(
                $feed->getUrl(),
                FetchResponse::notModified($feed->getUrl(), false, null, null),
            );
        }
        $this->fetcher->secondsPerFetch = 5;

        $report = $this->runner()->run(RefreshRequest::allDue(3));

        self::assertSame(1, $report->notModified);
        self::assertSame(1, $report->skippedForBudget);
        self::assertSame([$first->getUrl()], $this->fetcher->fetchedUrls);
        // Progress was made, so a polling caller converges instead of looping.
        self::assertSame(1, $report->remaining);
    }
```

In the abort test (line ~668), the ordered assertion becomes order-insensitive — outcomes now arrive by completion:

```php
        // The run stopped: the third feed's outcome was never processed.
        self::assertCount(2, $this->fetcher->fetchedUrls);
        self::assertNotContains('https://three.example.com/feed', $this->fetcher->fetchedUrls);
```

Add `concurrency: 1` to the `StubFeedFetcher` in that test's arrangement so "the third feed is never started" is deterministic rather than a race against the wave size.

- [ ] **Step 2: Run to verify they fail**

Run: `php bin/phpunit tests/Service/Refresh/RefreshRunnerTest.php`
Expected: FAIL — `RefreshRunner` still calls `fetch()` per feed.

- [ ] **Step 3: Rewrite RefreshRunner's refresh loop**

This step references `RefreshTally`, which Step 4 creates. Write both before running the suite — or do Step 4 first if you would rather keep the file parseable at every point.

In `backend/src/Service/Refresh/RefreshRunner.php`, change the import on line 15 and the constructor property on line 51:

```php
use App\Service\Fetch\BatchFeedFetcherInterface;
```

```php
        private readonly BatchFeedFetcherInterface $fetcher,
```

Replace `refresh()` (lines 77–159) with:

```php
    private function refresh(RefreshRequest $request): RefreshReport
    {
        $now = $this->clock->now();
        $cooldownCutoff = $request->force
            ? $now->modify(sprintf('-%d minutes', self::COOLDOWN_MINUTES))
            : null;

        $feeds = $this->feedRepository->findDue(
            $now,
            self::BATCH_LIMIT,
            $request->userId,
            $request->feedId,
            $request->tagId,
            $request->force,
            $cooldownCutoff,
        );

        $queue = new BudgetedFeedQueue($feeds, $this->clock, $now->getTimestamp() + $request->budgetSeconds);
        $tally = $this->processOutcomes($feeds, $queue);

        if ($tally->aborted) {
            // The EntityManager is likely closed: no favicons, no countDue, no
            // prune. Everything unprocessed stays due for the next run.
            return RefreshReport::aborted(
                \count($feeds),
                $tally->fetched,
                $tally->notModified,
                $tally->failed,
                \count($feeds) - $tally->processed,
            );
        }

        $this->resolveMissingFavicons($feeds);

        return RefreshReport::finished(
            \count($feeds),
            $tally->fetched,
            $tally->notModified,
            $tally->failed,
            $queue->skippedCount(),
            $this->countRemaining($request, $cooldownCutoff, $queue->skippedCount()),
            $request->prune ? $this->pruner->prune() : 0,
        );
    }

    /**
     * Drives the concurrent fetch and applies each result serially as it lands.
     * Breaking out of the loop cancels whatever is still in flight.
     *
     * @param list<Feed> $feeds
     */
    private function processOutcomes(array $feeds, BudgetedFeedQueue $queue): RefreshTally
    {
        $byId = [];
        foreach ($feeds as $feed) {
            $byId[(int) $feed->getId()] = $feed;
        }

        $tally = new RefreshTally();

        foreach ($this->fetcher->fetchAll($queue->tickets()) as $feedId => $outcome) {
            $outcomeKind = $this->applyOutcome($byId[$feedId], $outcome);
            $tally->record($outcomeKind);

            if (FeedOutcome::Aborted === $outcomeKind) {
                break;
            }
        }

        return $tally;
    }

    private function applyOutcome(Feed $feed, FetchOutcome $outcome): FeedOutcome
    {
        try {
            return $this->persistOutcome($feed, $outcome);
        } catch (UniqueConstraintViolationException | ORMException $e) {
            // A failed flush rolls back AND closes the EntityManager, so every
            // later persist/flush would throw "EntityManager is closed".
            // Stop here instead of cascading the failure across the batch.
            $this->logger->error(
                'Refresh aborted: persistence failed for {url}',
                ['url' => $feed->getUrl(), 'exception' => $e],
            );

            return FeedOutcome::Aborted;
        }
    }

    /**
     * A single-feed scope matches on id alone — countDue ignores the schedule and
     * would keep answering 1 even after a successful refresh, so a polling caller
     * would never see `remaining` reach 0.
     */
    private function countRemaining(RefreshRequest $request, ?\DateTimeImmutable $cooldownCutoff, int $skipped): int
    {
        if (null !== $request->feedId) {
            return $skipped;
        }

        return $this->feedRepository->countDue(
            $this->clock->now(),
            $request->userId,
            $request->feedId,
            $request->tagId,
            $request->force,
            $cooldownCutoff,
        );
    }
```

Replace `fetchParseAndPersist()` (lines 178–229) with a version that unwraps an outcome instead of calling the fetcher:

```php
    private function persistOutcome(Feed $feed, FetchOutcome $outcome): FeedOutcome
    {
        try {
            $response = $outcome->responseOrThrow();

            if ($response->notModified) {
                // A feed can be permanently moved AND answer 304 at the new
                // location; without this the redirect chain is re-walked on
                // every single refresh, forever.
                $this->applyPermanentRedirect($feed, $response);
                $this->scheduler->recordSuccess($feed, 0);
                $this->em->flush();

                return FeedOutcome::NotModified;
            }

            $body = $response->body;
            if (null === $body) {
                // Not reachable via the FetchResponse factories, but parsing an
                // empty string would silently record a bogus "successful" fetch.
                throw new FeedParseException('Fetcher returned a modified response without a body.');
            }

            $parsed = $this->bodyParser->parse($feed, $body);
            $created = $this->ingestor->ingest($feed, $parsed);

            $feed->setEtag($this->truncate($response->etag, self::ETAG_MAX));
            $feed->setLastModified($this->truncate($response->lastModified, self::LAST_MODIFIED_MAX));
            $this->applyPermanentRedirect($feed, $response);
            $this->scheduler->recordSuccess($feed, $created);
            $this->em->flush();

            return FeedOutcome::Fetched;
        } catch (FeedGoneException $e) {
            $this->scheduler->recordGone($feed, $e->getMessage());
            $this->em->flush();
            $this->logger->warning('Feed gone: {url}', ['url' => $feed->getUrl(), 'exception' => $e]);

            return FeedOutcome::Failed;
        } catch (FetchException | FeedParseException $e) {
            $this->scheduler->recordFailure($feed, $e->getMessage());
            $this->em->flush();
            $this->logger->warning('Feed refresh failed: {url}', ['url' => $feed->getUrl(), 'exception' => $e]);

            return FeedOutcome::Failed;
        }
    }
```

Delete `resolveFaviconIfMissing()` and its two call sites — Task 10 replaces it with `resolveMissingFavicons()`. Until then, stub it so the suite compiles:

```php
    /** @param list<Feed> $feeds */
    private function resolveMissingFavicons(array $feeds): void
    {
        foreach ($feeds as $feed) {
            if (null !== $feed->getFaviconUrl()) {
                continue;
            }
            $feed->setFaviconUrl($this->faviconResolver->resolve($feed->getSiteUrl() ?? $feed->getUrl()));
        }
        $this->em->flush();
    }
```

- [ ] **Step 4: Add RefreshTally**

`backend/src/Service/Refresh/RefreshTally.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Refresh;

/** Running counts for one refresh pass. Mutable: it is a tally. */
final class RefreshTally
{
    public int $fetched = 0;
    public int $notModified = 0;
    public int $failed = 0;
    public int $processed = 0;
    public bool $aborted = false;

    public function record(FeedOutcome $outcome): void
    {
        // An aborted feed is deliberately NOT counted as processed: its flush
        // rolled back, so it is still due and must appear in `remaining`.
        // Counting it here would under-report by one and let a polling client
        // believe a feed was handled when nothing was persisted.
        if (FeedOutcome::Aborted === $outcome) {
            $this->failed++;
            $this->aborted = true;

            return;
        }

        $this->processed++;

        match ($outcome) {
            FeedOutcome::Fetched => $this->fetched++,
            FeedOutcome::NotModified => $this->notModified++,
            FeedOutcome::Failed => $this->failed++,
        };
    }
}
```

Check this against the existing abort test, which is the arithmetic that matters: three feeds, the second one's flush fails. `processed` is 1, so `remaining` is `3 - 1 = 2` — the failing feed plus the untouched third — and `failed` is 1. Those are exactly the numbers `testAbortStopsTheRun` already asserts.

- [ ] **Step 5: Run the tests**

Run: `php bin/phpunit tests/Service/Refresh/RefreshRunnerTest.php`
Expected: PASS, 26 tests (25 original plus the new concurrency case).

- [ ] **Step 6: Run the full suite and gates**

Run: `php bin/phpunit && composer check && composer md`
Expected: all green. `RefreshRunner` must be PHPMD-clean; if the class is now too long, move `processOutcomes`/`applyOutcome` into a dedicated collaborator rather than suppressing.

- [ ] **Step 7: Commit**

```bash
git add backend/src/Service/Refresh/ backend/tests/Service/Refresh/
git commit -m "perf(refresh): fetch due feeds concurrently (#116)"
```

---

## Task 10: Concurrent favicon resolution

**Files:**
- Modify: `backend/src/Service/Fetch/FaviconResolver.php`
- Modify: `backend/src/Service/Refresh/RefreshRunner.php` (`resolveMissingFavicons`)
- Modify: `backend/tests/Service/Fetch/FaviconResolverTest.php`
- Modify: `backend/tests/Service/Refresh/RefreshRunnerTest.php`

`RefreshRunner` is the only production caller, so `resolve()` becomes `resolveAll()` outright — no adapter, no second code path.

- [ ] **Step 1: Write the failing test**

Add to `backend/tests/Service/Fetch/FaviconResolverTest.php`:

```php
    public function testResolvesManySitesInOneBatch(): void
    {
        $fetcher = new StubFeedFetcher();
        $fetcher->willReturn(
            'https://one.example.com',
            FetchResponse::fetched('https://one.example.com', false, '<link rel="icon" href="/a.png">', null, null),
        );
        $fetcher->willReturn(
            'https://two.example.com',
            FetchResponse::fetched('https://two.example.com', false, '<html></html>', null, null),
        );

        $icons = $this->resolver($fetcher)->resolveAll([
            7 => 'https://one.example.com/feed',
            9 => 'https://two.example.com/feed',
        ]);

        self::assertSame('https://one.example.com/a.png', $icons[7]);
        // No <link> tag, so the /favicon.ico convention stands in.
        self::assertSame('https://two.example.com/favicon.ico', $icons[9]);
    }

    public function testAFailedHomepageFetchYieldsTheConventionalFallback(): void
    {
        $fetcher = new StubFeedFetcher();
        $fetcher->willThrow('https://one.example.com', new FeedUnreachableException('boom'));

        $icons = $this->resolver($fetcher)->resolveAll([7 => 'https://one.example.com/feed']);

        self::assertSame('https://one.example.com/favicon.ico', $icons[7]);
    }

    public function testAUrlWithoutAHostYieldsNoIcon(): void
    {
        $icons = $this->resolver(new StubFeedFetcher())->resolveAll([7 => 'not a url']);

        self::assertNull($icons[7]);
    }
```

Convert every existing case in the file from `resolve($url)` to `resolveAll([1 => $url])[1]`, keeping each assertion's meaning identical.

- [ ] **Step 2: Run to verify it fails**

Run: `php bin/phpunit tests/Service/Fetch/FaviconResolverTest.php`
Expected: FAIL — `Call to undefined method … ::resolveAll()`.

- [ ] **Step 3: Rewrite FaviconResolver's public surface**

Replace the constructor and `resolve()`/`fromHomepage()` in `backend/src/Service/Fetch/FaviconResolver.php` with:

```php
    public function __construct(
        private BatchFeedFetcherInterface $fetcher,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Resolve a favicon for each site, fetching the homepages concurrently.
     *
     * @param array<int, string> $baseUrlsByFeedId a feed's siteUrl or, failing
     *                                             that, its feed URL
     *
     * @return array<int, string|null> an https URL per input key, or null when
     *                                 the URL carried no host to derive one from
     */
    public function resolveAll(array $baseUrlsByFeedId): array
    {
        $origins = [];
        $icons = [];
        foreach ($baseUrlsByFeedId as $feedId => $baseUrl) {
            $origin = self::httpsOrigin($baseUrl);
            if (null === $origin) {
                $icons[$feedId] = null;
                continue;
            }
            $origins[$feedId] = $origin;
        }

        foreach ($this->fetcher->fetchAll(array_map(
            static fn (string $origin): FetchTicket => new FetchTicket($origin),
            $origins,
        )) as $feedId => $outcome) {
            $icons[$feedId] = mb_substr(
                $this->iconFrom($outcome, $origins[$feedId]) ?? $origins[$feedId] . '/favicon.ico',
                0,
                self::URL_MAX,
            );
        }

        return $icons;
    }

    private function iconFrom(FetchOutcome $outcome, string $origin): ?string
    {
        $failure = $outcome->failure();
        if (null !== $failure) {
            $this->logger->info('Favicon fetch failed for {origin}', ['origin' => $origin, 'exception' => $failure]);

            return null;
        }

        $response = $outcome->responseOrThrow();
        $body = $response->body ?? '';

        return '' === trim($body) ? null : $this->pickIcon($body, $response->finalUrl);
    }
```

`pickIcon()`, `largestSize()` and `httpsOrigin()` stay exactly as they are. Update the imports: add `BatchFeedFetcherInterface`, `FetchOutcome`, `FetchTicket`; the class stays `final readonly`.

- [ ] **Step 4: Rewrite the runner's phase 2**

In `backend/src/Service/Refresh/RefreshRunner.php`:

```php
    /**
     * Phase two: one concurrent sweep of the homepages of feeds that still have
     * no favicon. It runs after phase one has flushed because ingestion is what
     * fills in siteUrl, and it is skipped entirely on abort — the EntityManager
     * is closed by then.
     *
     * @param list<Feed> $feeds
     */
    private function resolveMissingFavicons(array $feeds): void
    {
        $baseUrls = [];
        foreach ($feeds as $feed) {
            if (null !== $feed->getFaviconUrl()) {
                continue;
            }
            $baseUrls[(int) $feed->getId()] = $feed->getSiteUrl() ?? $feed->getUrl();
        }

        if ([] === $baseUrls) {
            return;
        }

        $icons = $this->faviconResolver->resolveAll($baseUrls);
        foreach ($feeds as $feed) {
            $icon = $icons[(int) $feed->getId()] ?? null;
            if (null !== $icon) {
                $feed->setFaviconUrl($icon);
            }
        }

        $this->em->flush();
    }
```

- [ ] **Step 5: Update the runner test's favicon wiring**

In `RefreshRunnerTest::setUp()`, `$this->faviconFetcher` is already a `StubFeedFetcher`, which now implements `BatchFeedFetcherInterface` — no change needed. Add one case:

```php
    public function testFaviconsAreResolvedForFeedsThatLackOne(): void
    {
        $feed = $this->dueFeed('https://one.example.com/feed');
        $this->em->flush();
        $this->fetcher->willReturn(
            $feed->getUrl(),
            FetchResponse::fetched($feed->getUrl(), false, $this->rss('F', 'g-1'), null, null),
        );
        $this->faviconFetcher->willReturn(
            'https://one.example.com',
            FetchResponse::fetched('https://one.example.com', false, '<link rel="icon" href="/i.png">', null, null),
        );

        $this->runner()->run(RefreshRequest::allDue(300));

        self::assertSame('https://one.example.com/i.png', $feed->getFaviconUrl());
    }

    public function testAnAbortedRunResolvesNoFavicons(): void
    {
        $feed = $this->dueFeed('https://one.example.com/feed');
        $this->em->flush();
        $this->fetcher->willReturn(
            $feed->getUrl(),
            FetchResponse::fetched($feed->getUrl(), false, $this->rss('F', 'g-1'), null, null),
        );

        $failingEm = $this->createStub(EntityManagerInterface::class);
        $failingEm->method('flush')->willThrowException(new UniqueConstraintViolationException(
            new class ('duplicate key', '23000', 1062) extends DriverAbstractException {
            },
            null,
        ));

        $report = $this->runner($failingEm)->run(RefreshRequest::allDue(300));

        self::assertSame('aborted', $report->status);
        // The EntityManager is closed; phase two never ran.
        self::assertSame([], $this->faviconFetcher->fetchedUrls);
    }
```

- [ ] **Step 6: Run the tests and gates**

Run: `php bin/phpunit && composer check && composer md`
Expected: all green.

- [ ] **Step 7: Commit**

```bash
git add backend/src/Service/Fetch/FaviconResolver.php backend/src/Service/Refresh/RefreshRunner.php backend/tests/
git commit -m "perf(refresh): resolve missing favicons concurrently (#116)"
```

---

## Task 11: Verify against the real thing

**Files:** none — this task produces evidence, not code.

- [ ] **Step 1: Run both suite legs**

```bash
php bin/phpunit
```

```bash
docker compose exec php vendor/bin/phpunit
```

Expected: both green. The MySQL leg matters here — the refresh path flushes per feed and SQLite is more forgiving about constraint timing.

- [ ] **Step 2: Run every gate**

```bash
composer check && composer md
```

Then run `mcp__phpstorm__lint_files` over every changed PHP file. Block on ERROR and WARNING; weak warnings are advisory.

- [ ] **Step 3: Check the log for what tests do not catch**

```bash
docker compose exec php bin/console app:feeds:refresh --user=49 --budget=300
```

Then read `backend/var/log/dev.log` for deprecations and swallowed errors. A generator that is never closed, or a cancelled response that logs a transport warning, shows up here and nowhere else.

- [ ] **Step 4: Re-measure**

Re-run the timing script from the issue against the same 24-feed set and record the new total. **Baseline to beat: 5.37s of fetch time over 24 feeds, 5.77s end-to-end.** Expected: roughly 1s of fetch time.

If the improvement is materially worse than ~3x, do not paper over it — find out why before opening the PR. The likeliest causes are a concurrency parameter that did not get bound (check `bin/console debug:container --parameter=fetch_concurrency`) or `awaitNext()` returning after every chunk rather than every completed response.

- [ ] **Step 5: Verify in the browser**

With `docker compose up -d` and `npm start` running, click refresh in the reader at http://localhost:4200/ and confirm the spinner completes in one poll round with no console errors.

- [ ] **Step 6: Open the PR**

```bash
git push -u origin feature/116-concurrent-feed-fetch
```

```bash
gh pr create --base develop --title "perf(refresh): fetch feeds concurrently instead of one at a time (#116)" --body "Closes #116

Before: 5.37s of fetch time over 24 feeds, 5.77s end-to-end.
After: <measured>

<summary of the measured before/after and anything that surprised you>"
```

PRs target `develop`, never `main`, so GitHub will **not** auto-close #116 — close it by hand when the PR merges.

---

## Notes for the implementer

**If `stream()` behaves differently than this plan assumes.** The riskiest assumption is that `awaitNext()` can `return` mid-`foreach` and re-enter `stream()` with a mutated in-flight set. This is the documented Symfony pattern, but if responses stall after the first return, the fallback is to drain the whole `foreach` and collect completions into a buffer, refilling only once the stream is exhausted. That costs some overlap at the tail of each wave but is strictly simpler. Measure before choosing it.

**If PHPStan fights the `\SplObjectStorage` generics.** `@var \SplObjectStorage<ResponseInterface, FetchAttempt>` on the declaration is usually enough. If it still complains about `$inFlight[$response]` returning `mixed`, assign through a local with an `assert($attempt instanceof FetchAttempt)`. Do not add a baseline entry — CLAUDE.md forbids new ones.

**What must not regress.** The `remaining` contract. `RefreshService.step()` in the frontend recurses while `status === 'partial' && remaining > 0`, with no cap on that recursion — only the `busy` path has a retry limit. A run that reports `partial` while making no progress is an infinite loop in the browser, not a slow refresh.
