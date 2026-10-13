# Fetch private social timelines concurrently (#1607) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A refresh pass reads its private Bluesky and Mastodon timelines concurrently, so N due private feeds cost
about the slowest request instead of the sum, while keeping every per-account rule (stale session, throttle, refusal)
and the UrlGuard boundary.

**Architecture:** The request loop inside `ConcurrentFeedFetcher` moves into a generic `Service/Fetch/ConcurrentRequestEngine`.
The engine takes `RequestTicketModel`s and yields one `FetchOutcomeModel` per ticket key. A per-kind
`ResponseExchangeInterface` strategy (tag plus keyed locator) reads each answer: `FeedResponseExchange` is today's
RSS behaviour, `ApiResponseExchange` returns any status as an answer. The RSS fetcher becomes a thin client of the
engine. `Service/Social` gets a second client, `ConcurrentTimelineFetcher`. Its per-call `TimelineRound` builds one
ticket per private feed, slotted per account. It gates parked tickets of an account that got throttled or refused,
and settles each answer through the network's `TimelineSourceInterface`, which is now split into `request()` and
`pageOf()`. `Refresh\SocialFeedRefresher` stores each outcome as it lands, one at a time on the one EntityManager.

**Tech Stack:** Symfony 7.4 / PHP 8.4, Symfony HttpClient (`stream()`, `MockHttpClient`), PHPUnit 12, PHPStan max,
PHPMD, phptramp, Infection.

**Spec:** GitHub issue #1607, plus the agreed design below (Lars, 2026-10-13, option A). The design decisions are
binding.

## Design (agreed, binding)

- **Scope.** Private Bluesky and Mastodon timelines are fetched concurrently among themselves. Interleaving them with
  the RSS batch is #1608 and out of scope. The design must keep that a small next step: both ticket kinds run on one
  engine.
- **Decision A: one shared guarded request engine.** The engine is extracted from
  `Service/Fetch/BatchFeedFetcher/ConcurrentFeedFetcher`. It covers:
  - UrlGuard with the DNS pin;
  - EgressOptions, proxy or pin, with cross-family failover by requeue through FetchRetryPolicy;
  - `max_redirects` 0, the size cap via `on_progress`, and the timeouts;
  - per-key slots via FetchQueue and HostSlots;
  - the `HttpClient::stream` loop, and cancelling what is in flight in `finally`.

  It becomes a generic concurrent engine that takes request tickets (method, url, headers, slot key, size limit…)
  and yields resolved answers per ticket key. The RSS fetcher becomes a client of it with **unchanged behaviour**:
  ConcurrentFeedFetcherTest, ConcurrentFeedFetcherProxyTest and RefreshRunnerConcurrentFetchTest stay green, adapted
  only where they test internals that moved. A new social timeline fetcher is the second client. All security rules
  live once, in the engine.
- **Social pass flow.**
  1. *Before dispatch, sequential per account:* skip the feeds whose account needs reconnecting or is throttled in
     this pass. For Bluesky, ensure a session from the cache, or open one, at most once per account per pass.
  2. *Dispatch:* each due private feed becomes a ticket. The URL is always `account->getServiceUrl() . path` plus the
     token header; the token never goes anywhere else, and no redirect is followed. Slots are keyed **per account,
     cap 3**, held in a container parameter like `fetch_host_concurrency`. The BudgetedFeedQueue deadline check still
     applies per start.
  3. *Answers are mapped as they land* by response mappers extracted from the timeline sources. The mappers are pure,
     over the engine's answer type. They keep today's status mapping (`BlueskySessions::accepted`,
     `MastodonApiClient::accepted`, Retry-After):
     - **Bluesky stale token** (400/401 `ExpiredToken`/`InvalidToken`): re-open that account's session once and
       requeue its stale feeds. A second stale answer for the account means its credentials were rejected.
     - **429 / throttle:** mark the account throttled. Its feeds not yet dispatched are held as today. The feeds in
       flight finish.
     - **401 / rejected:** mark the account as needing a reconnect and drop its queued feeds, as the sequential path
       does today.
  4. *Each result is persisted immediately and sequentially* on the single EntityManager, through the existing
     SocialFeedRefresher and FeedOutcomePersister paths (`storeParsed`, `storeThrottled`, `storeFailure`,
     `recordNewestSeen`, abort semantics). PostEnricher's blocking AppView calls stay in persist; they are out of
     scope.
- **Keep.**
  - The phase order of `RefreshPassFetcher`: the RSS batch, then social.
  - One path for a single feed (the plan writer decides from the callers).
  - The guarantee of `BlueskyTimelineSourceTest::testACachedSessionsTokenGoesOnlyToTheAccountsOwnService`, or its
    moved equivalent.
- **Done when.** A pass with N private feeds spends about the slowest request, not the sum. Tests prove:
  - every timeline request is on the wire before the first answer is consumed;
  - the per-account cap holds;
  - under concurrency, the throttle, stale-session and rejected paths only affect their own account;
  - the budget gate still applies;
  - an abort cancels what is in flight.

## Rulings where the design left a choice or the code disagreed

- **Engine shape.** The engine is `Service/Fetch/ConcurrentRequestEngine`, a root service. It owns the global cap
  (`fetch_concurrency`) and the User-Agent.
  - It answers `sendAll(iterable<RequestTicketModel>, int $slotCapacity, ?DispatchGateInterface $gate = null)`.
  - It yields `FetchOutcomeModel`, which gains `answered(ApiResponseModel)` and `withheld()`.
  - The slot capacity comes per call (2 per host for RSS, 3 per account for social). #1608 moves it onto the ticket
    when one run mixes both kinds.
  - `RequestTicketModel` carries the exchange kind, an `ApiRequestModel` (method, url, headers, json) and the slot.
- **API answers keep today's routing.** Any status is an answer, a redirect included. A retryable error status
  (`CrossFamilyFailover::isRetryableStatus`) is first asked again over the next route: the direct fallback, or the
  next address family. That is what `FailoverRequestSender` does today.
  - *The code disagreed in one place:* on a transport failure, the engine's rule (the RSS one) is used. That differs
    from `FailoverRequestSender` only in that a proxied *timeout* falls back to a direct attempt when `directFallback`
    is on.
  - `GuardedApiClient` and `FailoverRequestSender` stay for the blocking one-off calls (session open, likes, picker).
    Re-basing them on the engine is a follow-up, not this issue.
- **A dispatch gate.** `FetchQueue` pulls and parks up to `lookAhead` tickets ahead of the wire. So "not yet
  dispatched" must be decided when a ticket would be *sent*, not when it is pulled. `DispatchGateInterface::admits()`
  is asked right before each send. A refused ticket yields `FetchOutcomeModel::withheld()`.
- **Stale-session requeue is a second wave.** The engine stops once its ticket source is exhausted, so it cannot take
  a ticket added mid-run.
  - Stale feeds are collected while the first wave runs.
  - After it drains, each such account's session is dropped once, and a second `sendAll()` sends those feeds again.
    The first ticket rebuilt opens the fresh session.
  - A stale answer in the second wave is a refusal.
  - A stale session is rare: the cache lifetime is 90 minutes, under the token's lifetime.
- **"Not yet dispatched" feeds of a throttled or paused account are not persisted.**
  - *The design text says `storeThrottled`; the code says otherwise.* Today's code returns
    `FeedRefreshResultModel::of(Throttled|Failed)` without a store (`SocialFeedRefresher::refresh`). "Exactly as today"
    wins, so the code is followed.
  - An answer that was already in flight is settled on its merits: a second 429 is stored as throttled, a second 401
    as failed.
- **Account logic lives in `Service/Social`; it cannot live in Refresh.**
  - `Refresh → Social → Fetch` is the module order, and Social may not import Refresh.
  - `Social/Pass/TimelineRound` and `Social/Pass/TimelineAccounts` replace `Refresh/Pass/SocialRefreshPass`, which is
    deleted.
  - `PrivateFeedOrphanedException` moves to `Social/Exception`.
  - Social records the newest-seen id and marks the account as needing a reconnect. Refresh's store flushes both, as
    today.
- **One path for one feed.** Nothing outside `RefreshPassFetcher` calls `SocialFeedRefresher::refresh`, and only it
  uses `TimelineReaderInterface`. So the blocking path goes:
  - `TimelineReader/`;
  - the sources' `page()`;
  - `MastodonAccountApi::get()`;
  - `StubTimelineReader` (Task 6).
- **Session open.** A session opens lazily, when the first feed of an account is pulled (sequential, inside the
  engine's fill). The cache makes that once per account.
  - A throttled or refused open holds the account's other feeds.
  - An *unreachable* open is tried again by the account's next feed, as today. "At most one open" refers to opens
    that answer.
- **Mapper home.** The "pure response mappers" are `TimelineSourceInterface::pageOf(SocialFeed, FetchOutcomeModel)`
  on the existing per-network strategies. That is where the status mapping already sits, and it needs no service.
  `request(SocialFeed)` is the other half of the non-blocking split the issue asks for.
- **Global cap and budget.**
  - Social reuses `fetch_concurrency`. A new parameter, `social_account_concurrency: 3`, sets the per-account cap.
  - A social feed counts as started when the engine pulls it, parked or not. That matches the RSS path today. A held
    feed counts as started, as today's sequential skip does.
- **No migration.** Nothing here touches the schema.

## Global Constraints

- CLAUDE.md applies in full: Clean Code, no abbreviations, guard clauses, `final readonly` services, role folders
  (§10), `Response::HTTP_*` for every status in `src` and `tests`, PSR-12, and `declare(strict_types=1)`.
- **No needless comments.** Move a load-bearing comment verbatim with its code: the chunk order, `Accept-Encoding`,
  the `finally` cancel, the cap below one, and the unreadable proxy password.
- Every line you write is **at most 120 columns**. The code blocks below favour readability: wrap any line phpcs
  reports as too long (one argument or parameter per line, or a closure assigned to a variable first) without changing
  behaviour.
- Every `src` file you touch is PHPMD-clean (`composer md`). These stay green under `composer check`:
  - phptramp;
  - `ServiceRoleRule`;
  - `ServiceModuleCycleRule` and `ServiceModuleBoundaryRule`;
  - `AbbreviatedNameRule`;
  - `NoCollaboratorDefaultRule`.
- **Module direction.** `Service/Fetch` imports nothing from `Service/Social` or `Service/Refresh`, and `Service/Social`
  imports nothing from `Service/Refresh`. Check it with
  `grep -rnE 'App\\Service\\(Social|Refresh)' src/Service/Fetch; grep -rn 'App\\Service\\Refresh' src/Service/Social`.
  Both must print nothing.
- Avoid `(new X())->call()` in `src`: pdepend, which `composer md` runs, cannot parse it. Assign to a variable first.
- Commands run from `backend/` unless a step says "from the repo root". **Git commands always run from the repo
  root.**
- Commit messages are `type(#1607): …`. Never add a `Co-Authored-By`, "Generated with" or session line. **Do not
  push.**
- **Between tasks:** run `git status`, then `git fetch origin develop`. If `git log --oneline HEAD..origin/develop` is
  non-empty, run `git merge origin/develop`, resolve any conflict, and rerun the previous task's tests before you
  start the next task.
- Concurrent sessions share this checkout. Never `git checkout --`, `git reset` or `git stash`; undo a break-test by
  editing the line back by hand.
- **No migration, and never touch the dev database.** If a step seems to need either, stop and report it.
- `composer stan` needs a warm cache. Run `bin/console cache:warmup` after every `config/` change.

## File structure

| File | Responsibility |
|---|---|
| `src/Service/Fetch/ConcurrentRequestEngine.php` (create) | the guarded concurrent loop, generic over tickets |
| `src/Service/Fetch/ResponseExchange/{ResponseExchangeInterface,FeedResponseExchange,ApiResponseExchange}.php` (create) | how one kind of answer is read |
| `src/Service/Fetch/Model/{RequestTicketModel,ResponseExchangeKind,ExchangeStepModel}.php` (create) | the engine's ticket, its kind, one chunk's verdict |
| `src/Service/Fetch/DispatchGate/DispatchGateInterface.php` (create) | "may this ticket go on the wire now?" |
| `src/Service/Fetch/Pass/HostSlots.php` → `RequestSlots.php` (rename, Task 2) | in-flight count per slot key |
| `src/Service/Fetch/BatchFeedFetcher/ConcurrentFeedFetcher.php` (rewrite) | the RSS client of the engine |
| `src/Service/Fetch/{FetchRetryPolicy,ResponseClassifier}.php`, `Model/{FetchAttemptModel,FetchOutcomeModel}.php`, `Pass/FetchQueue.php` (modify) | the generic ticket type, new outcome kinds, error-status rerouting |
| `src/Service/Social/TimelineSource/*.php` (modify) | `request()` / `pageOf()` / `dropSession()` |
| `src/Service/Social/{BlueskySessions,BlueskyApiClient,MastodonApiClient,MastodonAccountApi}.php` (modify) | authorised requests without sending, mapping an outcome |
| `src/Service/Social/TimelineSources.php` (create) | the network's source for an account |
| `src/Service/Social/Pass/{TimelineRound,TimelineAccounts}.php` (create) | one refresh's private-feed reads and per-account verdicts |
| `src/Service/Social/Model/{TimelineOutcome,TimelineOutcomeModel}.php` (create) | what a private feed's read came to |
| `src/Service/Social/TimelineFetcher/{TimelineFetcherInterface,ConcurrentTimelineFetcher}.php` (create) | the social client of the engine |
| `src/Service/Social/Exception/{SocialSessionStaleException,PrivateFeedOrphanedException}.php` (create / move) | typed failures |
| `src/Service/Refresh/{SocialFeedRefresher,RefreshPassFetcher}.php` (modify), `Refresh/Pass/SocialRefreshPass.php` (delete) | store outcomes as they land |
| `src/Service/Social/TimelineReader/*` (delete, Task 6) | the blocking path |
| `config/services.yaml` (modify) | engine cap, host cap, account cap |
| `docs/security.md` (modify) | the social section names the engine |

---

### Task 1: Extract the engine from `ConcurrentFeedFetcher` (pure refactor)

A reviewer must be able to approve this task alone: no behaviour changes. Every existing RSS fetch test stays green.
Tests change only where they build objects whose constructors moved.

**Files:**
- Create: `backend/src/Service/Fetch/ConcurrentRequestEngine.php`
- Create: `backend/src/Service/Fetch/ResponseExchange/ResponseExchangeInterface.php`
- Create: `backend/src/Service/Fetch/ResponseExchange/FeedResponseExchange.php`
- Create: `backend/src/Service/Fetch/Model/RequestTicketModel.php`
- Create: `backend/src/Service/Fetch/Model/ResponseExchangeKind.php`
- Create: `backend/src/Service/Fetch/Model/ExchangeStepModel.php`
- Modify: `backend/src/Service/Fetch/BatchFeedFetcher/ConcurrentFeedFetcher.php` (rewrite)
- Modify: `backend/src/Service/Fetch/Model/FetchAttemptModel.php`
- Modify: `backend/src/Service/Fetch/Pass/FetchQueue.php`
- Modify: `backend/src/Service/Fetch/Pass/HostSlots.php`
- Modify: `backend/src/Service/Fetch/ResponseClassifier.php`
- Modify: `backend/config/services.yaml`
- Create: `backend/tests/Support/FeedTickets.php`
- Modify: `backend/tests/Support/FetchWiring.php`
- Create: `backend/tests/Service/Fetch/Model/RequestTicketModelTest.php`
- Create: `backend/tests/Service/Fetch/Model/ResponseExchangeKindTest.php`
- Create: `backend/tests/Service/Fetch/ResponseExchange/ResponseExchangeWiringTest.php`
- Modify the tests that build the moved objects:
  - `backend/tests/Service/Fetch/BatchFeedFetcher/ConcurrentFeedFetcherTest.php`
  - `backend/tests/Service/Fetch/BatchFeedFetcher/ConcurrentFeedFetcherProxyTest.php`
  - `backend/tests/Service/Refresh/RefreshRunner/RefreshRunnerConcurrentFetchTest.php`
  - `backend/tests/Service/Fetch/FeedFetcher/HttpFeedFetcherTest.php`
  - `backend/tests/Service/Fetch/Model/FetchAttemptModelTest.php`
  - `backend/tests/Service/Fetch/Pass/FetchQueueTest.php`
  - `backend/tests/Service/Fetch/Pass/HostSlotsTest.php`
  - `backend/tests/Service/Fetch/ResponseClassifierTest.php`
  - `backend/tests/Service/Fetch/OutboundUserAgentWiringTest.php`

**Interfaces:**
- Produces:
  - `ConcurrentRequestEngine::sendAll(iterable<int|string, RequestTicketModel> $tickets, int $slotCapacity): \Generator<int|string, FetchOutcomeModel>`.
  - `RequestTicketModel::feed(FetchTicketModel $ticket): self`. Public `ResponseExchangeKind $exchange` and
    `ApiRequestModel $request`, plus `slotKeyFor(string $url): string` and `feedTicket(): FetchTicketModel`.
  - `ResponseExchangeKind` (string-backed; `Feed = 'feed'`) with `accept(): string` and
    `sizeLimit(): ResponseSizeLimit`.
  - `ExchangeStepModel::awaitBody()`, `::answered(FetchOutcomeModel)` and `::sendNext(FetchAttemptModel)`. Public
    `?FetchOutcomeModel $outcome` and `?FetchAttemptModel $nextAttempt`, plus `isAwaitingBody(): bool`.
  - `ResponseExchangeInterface`: static `kind(): string`,
    `onHeaders(ResponseInterface, FetchAttemptModel): ExchangeStepModel` and
    `onBody(ResponseInterface, FetchAttemptModel): FetchOutcomeModel`. It is tagged `app.response_exchange`.
  - `FetchAttemptModel::start(int|string $key, RequestTicketModel $ticket, ?ProxyConfigModel $proxy = null)`, and
    `$attempt->ticket` is now a `RequestTicketModel`.
  - `ConcurrentFeedFetcher::__construct(ConcurrentRequestEngine $engine, int $hostConcurrency)`.
  - Test support:
    - `FetchWiring::requestEngine(HttpClientInterface $client, UrlGuard $urlGuard, EgressProxySourceInterface $egressProxy, int $concurrency = 4): ConcurrentRequestEngine`;
    - `FeedTickets::of(string $url, ?string $etag = null, ?string $lastModified = null): RequestTicketModel`.

- [ ] **Step 1: Write the failing tests for the new pieces**

Create `tests/Service/Fetch/Model/RequestTicketModelTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Fetch\Model;

use App\Service\Fetch\Model\FetchTicketModel;
use App\Service\Fetch\Model\RequestTicketModel;
use App\Service\Fetch\Model\ResponseExchangeKind;
use PHPUnit\Framework\TestCase;

final class RequestTicketModelTest extends TestCase
{
    private const string LAST_MODIFIED = 'Mon, 20 Jul 2026 08:30:00 GMT';

    public function testAFeedTicketIsAGetCarryingOnlyTheValidatorItHas(): void
    {
        $ticket = RequestTicketModel::feed(new FetchTicketModel('https://example.com/feed', '"v1"'));

        self::assertSame(ResponseExchangeKind::Feed, $ticket->exchange);
        self::assertSame('GET', $ticket->request->method);
        self::assertSame('https://example.com/feed', $ticket->request->url);
        self::assertSame(['If-None-Match' => '"v1"'], $ticket->request->headers);
        self::assertNull($ticket->request->json);
    }

    public function testAFeedTicketSendsBothValidatorsWhenItHasBoth(): void
    {
        $ticket = RequestTicketModel::feed(new FetchTicketModel('https://example.com/feed', '"v1"', self::LAST_MODIFIED));

        self::assertSame(
            ['If-None-Match' => '"v1"', 'If-Modified-Since' => self::LAST_MODIFIED],
            $ticket->request->headers,
        );
    }

    public function testAnUnconditionalFeedTicketSendsNoValidator(): void
    {
        self::assertSame([], RequestTicketModel::feed(new FetchTicketModel('https://example.com/feed'))->request->headers);
    }

    public function testAFeedTicketKeepsItsFeedTicketForTheNotModifiedAnswer(): void
    {
        $feedTicket = new FetchTicketModel('https://example.com/feed', '"v1"');

        self::assertSame($feedTicket, RequestTicketModel::feed($feedTicket)->feedTicket());
    }

    public function testAFeedTicketsSlotIsTheHostOfTheUrlItHasReached(): void
    {
        $ticket = RequestTicketModel::feed(new FetchTicketModel('https://www.example.com/feed'));

        self::assertSame($ticket->slotKeyFor('https://example.com/a'), $ticket->slotKeyFor('https://www.example.com:443/b'));
        self::assertNotSame($ticket->slotKeyFor('https://example.com/a'), $ticket->slotKeyFor('https://other.example.com/a'));
    }
}
```

Create `tests/Service/Fetch/Model/ResponseExchangeKindTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Fetch\Model;

use App\Service\Fetch\Model\ResponseExchangeKind;
use App\Service\Fetch\Model\ResponseSizeLimit;
use PHPUnit\Framework\TestCase;

final class ResponseExchangeKindTest extends TestCase
{
    public function testAFeedAsksForAFeedFormatFirstAndIsCappedAtTheFeedLimit(): void
    {
        self::assertStringStartsWith('application/rss+xml, application/atom+xml', ResponseExchangeKind::Feed->accept());
        self::assertSame(ResponseSizeLimit::Feed, ResponseExchangeKind::Feed->sizeLimit());
    }
}
```

Create `tests/Service/Fetch/ResponseExchange/ResponseExchangeWiringTest.php`. It reaches the engine through the
aliased fetcher, because the container may inline a private service that has only one consumer:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Fetch\ResponseExchange;

use App\Service\Fetch\BatchFeedFetcher\ConcurrentFeedFetcher;
use App\Service\Fetch\ConcurrentRequestEngine;
use App\Service\Fetch\Model\ResponseExchangeKind;
use App\Service\Fetch\ResponseExchange\ResponseExchangeInterface;
use Psr\Container\ContainerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ResponseExchangeWiringTest extends KernelTestCase
{
    public function testTheEngineReadsEveryKindOfAnswerThroughItsOwnExchange(): void
    {
        self::bootKernel();
        $fetcher = self::getContainer()->get(ConcurrentFeedFetcher::class);
        $engine = (new \ReflectionProperty(ConcurrentFeedFetcher::class, 'engine'))->getValue($fetcher);
        self::assertInstanceOf(ConcurrentRequestEngine::class, $engine);
        $exchanges = (new \ReflectionProperty(ConcurrentRequestEngine::class, 'exchanges'))->getValue($engine);
        self::assertInstanceOf(ContainerInterface::class, $exchanges);

        foreach (ResponseExchangeKind::cases() as $kind) {
            $exchange = $exchanges->get($kind->value);
            self::assertInstanceOf(ResponseExchangeInterface::class, $exchange);
            self::assertSame($kind->value, $exchange::kind());
        }
    }
}
```

- [ ] **Step 2: Run them to verify they fail**

Run: `php bin/phpunit --filter 'RequestTicketModelTest|ResponseExchangeKindTest|ResponseExchangeWiringTest'`
Expected: FAIL with `Class "App\Service\Fetch\Model\RequestTicketModel" not found` (and the same for the other two).

- [ ] **Step 3: Create the engine's value types**

`src/Service/Fetch/Model/ResponseExchangeKind.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Fetch\Model;

/** Which exchange reads a ticket's answer; the engine keys its exchange locator by the value. */
enum ResponseExchangeKind: string
{
    private const string FEED_ACCEPT =
        'application/rss+xml, application/atom+xml, application/xml;q=0.9, text/xml;q=0.8, */*;q=0.1';

    case Feed = 'feed';

    public function accept(): string
    {
        return match ($this) {
            self::Feed => self::FEED_ACCEPT,
        };
    }

    public function sizeLimit(): ResponseSizeLimit
    {
        return match ($this) {
            self::Feed => ResponseSizeLimit::Feed,
        };
    }
}
```

`src/Service/Fetch/Model/RequestTicketModel.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Fetch\Model;

use App\Service\Fetch\Support\HostKey;

/** One request the concurrent engine sends: what goes on the wire, and which exchange reads the answer. */
final readonly class RequestTicketModel
{
    private function __construct(
        public ResponseExchangeKind $exchange,
        public ApiRequestModel $request,
        private ?FetchTicketModel $feedTicket,
    ) {
    }

    public static function feed(FetchTicketModel $ticket): self
    {
        return new self(
            ResponseExchangeKind::Feed,
            ApiRequestModel::get($ticket->url, self::validatorHeaders($ticket)),
            $ticket,
        );
    }

    public function slotKeyFor(string $url): string
    {
        return HostKey::forUrl($url);
    }

    public function feedTicket(): FetchTicketModel
    {
        return $this->feedTicket ?? throw new \LogicException('Only a feed ticket carries cache validators.');
    }

    /** @return array<string, string> */
    private static function validatorHeaders(FetchTicketModel $ticket): array
    {
        $headers = [];
        if (null !== $ticket->etag) {
            $headers['If-None-Match'] = $ticket->etag;
        }
        if (null !== $ticket->lastModified) {
            $headers['If-Modified-Since'] = $ticket->lastModified;
        }

        return $headers;
    }
}
```

`src/Service/Fetch/Model/ExchangeStepModel.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Fetch\Model;

/** What an exchange concluded from one chunk: wait for more, the request's outcome, or the attempt to send next. */
final readonly class ExchangeStepModel
{
    private function __construct(
        public ?FetchOutcomeModel $outcome,
        public ?FetchAttemptModel $nextAttempt,
    ) {
    }

    public static function awaitBody(): self
    {
        return new self(null, null);
    }

    public static function answered(FetchOutcomeModel $outcome): self
    {
        return new self($outcome, null);
    }

    public static function sendNext(FetchAttemptModel $attempt): self
    {
        return new self(null, $attempt);
    }

    public function isAwaitingBody(): bool
    {
        return null === $this->outcome && null === $this->nextAttempt;
    }
}
```

- [ ] **Step 4: Point the attempt, the queue, the slots and the classifier at the generic ticket**

In `src/Service/Fetch/Model/FetchAttemptModel.php`, change the ticket type and where `start()` reads the URL. Leave
every other method as it is:

```php
    /** Private: only `start()` seeds `$url` from the ticket, a default a promoted parameter cannot express. */
    private function __construct(
        public int|string $key,
        public RequestTicketModel $ticket,
        public string $url,
        public bool $permanentRedirect,
        private int $hop,
        public int $pinnedAddressAttempt = 0,
        public ?ProxyConfigModel $proxy = null,
    ) {
    }

    public static function start(int|string $key, RequestTicketModel $ticket, ?ProxyConfigModel $proxy = null): self
    {
        return new self($key, $ticket, $ticket->request->url, false, 0, proxy: $proxy);
    }
```

In `src/Service/Fetch/Pass/FetchQueue.php`:
- replace `use App\Service\Fetch\Model\FetchTicketModel;` with `use App\Service\Fetch\Model\RequestTicketModel;`;
- change the constructor's docblock to `/** @param \Iterator<int|string, RequestTicketModel> $tickets */`.

Nothing else in the queue changes.

In `src/Service/Fetch/Pass/HostSlots.php`:
- delete `use App\Service\Fetch\Support\HostKey;`;
- make `keyOf()` read the ticket.

```php
    private function keyOf(FetchAttemptModel $attempt): string
    {
        return $attempt->ticket->slotKeyFor($attempt->url);
    }
```

In `src/Service/Fetch/ResponseClassifier.php`, `notModifiedOrEmptyFetch()` reads the feed ticket:

```php
    private function notModifiedOrEmptyFetch(FetchAttemptModel $attempt): FetchResponseModel
    {
        $ticket = $attempt->ticket->feedTicket();
```

The rest of that method is unchanged.

- [ ] **Step 5: Create the exchange seam and the feed exchange**

`src/Service/Fetch/ResponseExchange/ResponseExchangeInterface.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Fetch\ResponseExchange;

use App\Service\Fetch\Exception\FetchException;
use App\Service\Fetch\Model\ExchangeStepModel;
use App\Service\Fetch\Model\FetchAttemptModel;
use App\Service\Fetch\Model\FetchOutcomeModel;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/** How the engine reads one kind of answer, keyed by its ResponseExchangeKind value in ConcurrentRequestEngine. */
#[AutoconfigureTag('app.response_exchange')]
interface ResponseExchangeInterface
{
    public static function kind(): string;

    /**
     * @throws FetchException
     * @throws ExceptionInterface wrapped into a FetchException by the engine
     */
    public function onHeaders(ResponseInterface $response, FetchAttemptModel $attempt): ExchangeStepModel;

    /**
     * @throws FetchException
     * @throws ExceptionInterface wrapped into a FetchException by the engine
     */
    public function onBody(ResponseInterface $response, FetchAttemptModel $attempt): FetchOutcomeModel;
}
```

`src/Service/Fetch/ResponseExchange/FeedResponseExchange.php` (the logic of the old
`ConcurrentFeedFetcher::onHeaders()`):

```php
<?php

declare(strict_types=1);

namespace App\Service\Fetch\ResponseExchange;

use App\Service\Fetch\Exception\FeedUnreachableException;
use App\Service\Fetch\Model\ExchangeStepModel;
use App\Service\Fetch\Model\FetchAttemptModel;
use App\Service\Fetch\Model\FetchOutcomeModel;
use App\Service\Fetch\Model\HeaderDecision;
use App\Service\Fetch\Model\ResponseExchangeKind;
use App\Service\Fetch\ResponseClassifier;
use Symfony\Contracts\HttpClient\ResponseInterface;

/** A feed's answer: a 304 settles it at the headers, a redirect goes back on the queue, a 2xx waits for its body. */
final readonly class FeedResponseExchange implements ResponseExchangeInterface
{
    public function __construct(private ResponseClassifier $classifier)
    {
    }

    public static function kind(): string
    {
        return ResponseExchangeKind::Feed->value;
    }

    public function onHeaders(ResponseInterface $response, FetchAttemptModel $attempt): ExchangeStepModel
    {
        $verdict = $this->classifier->fromHeaders($response, $attempt);

        if (HeaderDecision::AwaitBody === $verdict->decision) {
            return ExchangeStepModel::awaitBody();
        }

        if (HeaderDecision::Terminal === $verdict->decision) {
            \assert(null !== $verdict->response);

            return ExchangeStepModel::answered(FetchOutcomeModel::succeeded($verdict->response));
        }

        if (!$attempt->canFollowRedirect()) {
            throw new FeedUnreachableException(sprintf(
                '%s: more than %d redirects',
                $attempt->ticket->request->url,
                FetchAttemptModel::MAX_REDIRECTS,
            ));
        }

        \assert(null !== $verdict->redirectUrl);

        return ExchangeStepModel::sendNext($attempt->followedTo($verdict->redirectUrl, $verdict->permanent));
    }

    public function onBody(ResponseInterface $response, FetchAttemptModel $attempt): FetchOutcomeModel
    {
        return FetchOutcomeModel::succeeded($this->classifier->fromBody($response, $attempt));
    }
}
```

- [ ] **Step 6: Create the engine**

`src/Service/Fetch/ConcurrentRequestEngine.php`. The comments are the old fetcher's load-bearing ones, moved
verbatim:

```php
<?php

declare(strict_types=1);

namespace App\Service\Fetch;

use App\Service\Crypto\Exception\SecretUnreadableException;
use App\Service\Fetch\EgressProxySource\EgressProxySourceInterface;
use App\Service\Fetch\Exception\FeedUnreachableException;
use App\Service\Fetch\Exception\FetchException;
use App\Service\Fetch\Exception\ResponseTooLargeException;
use App\Service\Fetch\Model\ExchangeStepModel;
use App\Service\Fetch\Model\FetchAttemptModel;
use App\Service\Fetch\Model\FetchOutcomeModel;
use App\Service\Fetch\Model\RequestTicketModel;
use App\Service\Fetch\Pass\FetchQueue;
use App\Service\Fetch\Pass\HostSlots;
use App\Service\Fetch\ResponseExchange\ResponseExchangeInterface;
use App\Service\Fetch\Support\EgressOptions;
use Psr\Container\ContainerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireLocator;
use Symfony\Contracts\HttpClient\ChunkInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Sends many guarded requests at once over Symfony's multiplexing HTTP client and yields each outcome under its
 * ticket's key as it lands. SECURITY: every attempt, a failover retry or a followed redirect included, passes UrlGuard
 * and goes out pinned or proxied with `max_redirects` 0; the ticket's exchange reads the answer.
 */
final readonly class ConcurrentRequestEngine
{
    private const float TIMEOUT_SECONDS = 10.0;

    public function __construct(
        private HttpClientInterface $httpClient,
        private UrlGuard $urlGuard,
        private EgressProxySourceInterface $egressProxySource,
        private FetchRetryPolicy $retryPolicy,
        #[AutowireLocator('app.response_exchange', defaultIndexMethod: 'kind')]
        private ContainerInterface $exchanges,
        private int $concurrency,
        private string $userAgent,
    ) {
        // A cap below one opens no request, so an empty run would read as a clean one: the sweep's `remaining` never
        // decrements and the frontend polls forever on `partial`.
        if ($concurrency < 1) {
            throw new \InvalidArgumentException(
                sprintf('Concurrency must be at least 1, got %d.', $concurrency),
            );
        }
    }

    /**
     * A failed request arrives as a failed outcome, never a throw, so one bad ticket cannot abandon the others;
     * abandoning the generator cancels whatever is still in flight.
     *
     * @param iterable<int|string, RequestTicketModel> $tickets
     *
     * @return \Generator<int|string, FetchOutcomeModel>
     */
    public function sendAll(iterable $tickets, int $slotCapacity): \Generator
    {
        try {
            $batchProxy = $this->egressProxySource->egressProxy();
        } catch (SecretUnreadableException $exception) {
            // An unreadable proxy password fails every ticket, one outcome each: the sweep's `remaining` only
            // decrements on a yielded outcome, so letting the exception escape would strand the run.
            yield from $this->failEvery($tickets, $exception);

            return;
        }

        // More full-slot candidates than there are slots cannot open a request this pass.
        $queue = new FetchQueue($this->iterator($tickets), new HostSlots($slotCapacity), $this->concurrency, $batchProxy);
        /** @var \SplObjectStorage<ResponseInterface, FetchAttemptModel> $inFlight */
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
     * @param \SplObjectStorage<ResponseInterface, FetchAttemptModel> $inFlight
     *
     * @return \Generator<int|string, FetchOutcomeModel>
     */
    private function fill(FetchQueue $queue, \SplObjectStorage $inFlight): \Generator
    {
        while ($inFlight->count() < $this->concurrency) {
            $attempt = $queue->takeRunnable();
            if (null === $attempt) {
                return;
            }

            try {
                $response = $this->send($attempt);
            } catch (FetchException $exception) {
                $fallback = $this->retryPolicy->directFallbackFor($attempt);
                if (null !== $fallback) {
                    $queue->requeue($fallback);
                    continue;
                }

                yield $attempt->key => FetchOutcomeModel::failed($exception);
                continue;
            }

            $queue->onSent($attempt);
            $inFlight[$response] = $attempt;
        }
    }

    /**
     * Streams until one response resolves, then returns so the freed slot is refilled. A follow-up attempt goes back
     * on the queue instead of being sent inline, so a feed on its fourth hop shares the loop with one on its first.
     *
     * @param \SplObjectStorage<ResponseInterface, FetchAttemptModel> $inFlight
     *
     * @return \Generator<int|string, FetchOutcomeModel>
     */
    private function awaitNext(FetchQueue $queue, \SplObjectStorage $inFlight): \Generator
    {
        foreach ($this->httpClient->stream($inFlight, self::TIMEOUT_SECONDS) as $response => $chunk) {
            $attempt = $inFlight[$response];

            try {
                $step = $this->advance($response, $chunk, $attempt);
            } catch (FetchException $exception) {
                $this->retire($queue, $inFlight, $response);

                $requeue = $this->retryPolicy->nextAttemptAfter($attempt, $exception);
                if (null !== $requeue) {
                    $queue->requeue($requeue);

                    return;
                }

                yield $attempt->key => FetchOutcomeModel::failed($exception);

                return;
            }

            if ($step->isAwaitingBody()) {
                continue;
            }

            $this->retire($queue, $inFlight, $response);

            if (null !== $step->nextAttempt) {
                $queue->requeue($step->nextAttempt);

                return;
            }

            \assert(null !== $step->outcome);

            yield $attempt->key => $step->outcome;

            return;
        }
    }

    /** @throws FetchException */
    private function advance(
        ResponseInterface $response,
        ChunkInterface $chunk,
        FetchAttemptModel $attempt,
    ): ExchangeStepModel {
        $exchange = $this->exchangeFor($attempt);

        try {
            // Order is load-bearing: on a timeout chunk isFirst() throws while isTimeout() returns true, so asking
            // isFirst() first would report every timeout as a transport failure. On an error chunk isTimeout() throws,
            // and the catch below keeps the real cause.
            if ($chunk->isTimeout()) {
                throw new FeedUnreachableException(sprintf('%s: timed out', $attempt->url));
            }

            if ($chunk->isFirst()) {
                return $exchange->onHeaders($response, $attempt);
            }

            return $chunk->isLast()
                ? ExchangeStepModel::answered($exchange->onBody($response, $attempt))
                : ExchangeStepModel::awaitBody();
        } catch (ExceptionInterface $exception) {
            throw FetchException::from($attempt->url, $exception);
        }
    }

    private function exchangeFor(FetchAttemptModel $attempt): ResponseExchangeInterface
    {
        $exchange = $this->exchanges->get($attempt->ticket->exchange->value);
        \assert($exchange instanceof ResponseExchangeInterface);

        return $exchange;
    }

    /** @param \SplObjectStorage<ResponseInterface, FetchAttemptModel> $inFlight */
    private function retire(FetchQueue $queue, \SplObjectStorage $inFlight, ResponseInterface $response): void
    {
        $queue->onRetired($inFlight[$response]);
        $inFlight->detach($response);
        $response->cancel();
    }

    /** @throws FetchException when the URL fails the SSRF guard */
    private function send(FetchAttemptModel $attempt): ResponseInterface
    {
        $proxy = $attempt->proxy;

        // The host guard runs on both paths; only the IP pin is proxy-incompatible.
        $guarded = $this->urlGuard->assertSafe($attempt->url);

        $egress = null !== $proxy
            ? EgressOptions::proxied($proxy)
            : EgressOptions::pinned($guarded, $attempt->pinnedAddressAttempt);
        $request = $attempt->ticket->request;
        $sizeLimit = $attempt->ticket->exchange->sizeLimit();

        try {
            return $this->httpClient->request($request->method, $attempt->url, [
                'headers' => $this->headersOf($attempt->ticket),
                'max_redirects' => 0,
                'timeout' => self::TIMEOUT_SECONDS,
                'max_duration' => self::TIMEOUT_SECONDS * 2,
                'on_progress' => static function (int $downloaded) use ($sizeLimit): void {
                    ResponseTooLargeException::throwIfExceeded($sizeLimit, $downloaded);
                },
                ...(null === $request->json ? [] : ['json' => $request->json]),
                ...$egress,
            ]);
        } catch (ExceptionInterface $exception) {
            throw FetchException::from($attempt->url, $exception);
        }
    }

    /** @return array<string, string> */
    private function headersOf(RequestTicketModel $ticket): array
    {
        return [
            'Accept' => $ticket->exchange->accept(),
            // Refuse transparent compression so the size limit (counted on the
            // wire in on_progress) also bounds the buffered body — a compressed
            // response would otherwise decompress unbounded before the size check.
            'Accept-Encoding' => 'identity',
            'User-Agent' => $this->userAgent,
            ...$ticket->request->headers,
        ];
    }

    /**
     * Adapts any iterable to the Iterator the queue needs. Being a generator
     * itself, it never materialises the batch — the queue pulls tickets one at a
     * time, and buffering them would defeat a lazy source.
     *
     * @param iterable<int|string, RequestTicketModel> $tickets
     *
     * @return \Iterator<int|string, RequestTicketModel>
     */
    private function iterator(iterable $tickets): \Iterator
    {
        yield from $tickets;
    }

    /**
     * @param iterable<int|string, RequestTicketModel> $tickets
     *
     * @return \Generator<int|string, FetchOutcomeModel>
     */
    private function failEvery(iterable $tickets, \Throwable $cause): \Generator
    {
        foreach ($tickets as $key => $ticket) {
            yield $key => FetchOutcomeModel::failed(new FeedUnreachableException(
                sprintf('%s: the instance egress proxy is unusable: %s', $ticket->request->url, $cause->getMessage()),
                previous: $cause,
            ));
        }
    }
}
```

`new FetchQueue(...)` is 117 columns wide, which is within the limit. If phpcs disagrees, break the arguments onto
separate lines.

- [ ] **Step 7: Make `ConcurrentFeedFetcher` a client of the engine**

Replace the whole file `src/Service/Fetch/BatchFeedFetcher/ConcurrentFeedFetcher.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Fetch\BatchFeedFetcher;

use App\Service\Fetch\ConcurrentRequestEngine;
use App\Service\Fetch\Model\FetchOutcomeModel;
use App\Service\Fetch\Model\FetchTicketModel;
use App\Service\Fetch\Model\RequestTicketModel;

/**
 * Fetches many feeds at once on the shared request engine; the refresh sweep is network-wait-bound, so requests
 * overlap while the caller still processes results one at a time.
 */
final readonly class ConcurrentFeedFetcher implements BatchFeedFetcherInterface
{
    public function __construct(private ConcurrentRequestEngine $engine, private int $hostConcurrency)
    {
        if ($hostConcurrency < 1) {
            throw new \InvalidArgumentException(
                sprintf('Per-host concurrency must be at least 1, got %d.', $hostConcurrency),
            );
        }
    }

    /**
     * @param iterable<int|string, FetchTicketModel> $tickets
     *
     * @return \Generator<int|string, FetchOutcomeModel>
     */
    public function fetchAll(iterable $tickets): \Generator
    {
        return $this->engine->sendAll($this->requestTickets($tickets), $this->hostConcurrency);
    }

    /**
     * One ticket at a time: the source is budget-gated, and mapping ahead would start feeds no slot has opened for.
     *
     * @param iterable<int|string, FetchTicketModel> $tickets
     *
     * @return \Generator<int|string, RequestTicketModel>
     */
    private function requestTickets(iterable $tickets): \Generator
    {
        foreach ($tickets as $key => $ticket) {
            yield $key => RequestTicketModel::feed($ticket);
        }
    }
}
```

- [ ] **Step 8: Wire the caps**

In `config/services.yaml`, replace the `ConcurrentFeedFetcher` block and its comment with:

```yaml
    # Wired on these services, not bound globally: a bind matches by parameter name, and `int $concurrency` would feed
    # any service that names a parameter that way.
    App\Service\Fetch\ConcurrentRequestEngine:
        arguments:
            $concurrency: '%fetch_concurrency%'

    App\Service\Fetch\BatchFeedFetcher\ConcurrentFeedFetcher:
        arguments:
            $hostConcurrency: '%fetch_host_concurrency%'
```

`string $userAgent` reaches the engine through the existing `_defaults` bind.

- [ ] **Step 9: Adapt the test wiring and the tests that built moved objects**

Create `tests/Support/FeedTickets.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Service\Fetch\Model\FetchTicketModel;
use App\Service\Fetch\Model\RequestTicketModel;

/** The engine ticket for a feed URL, as ConcurrentFeedFetcher maps it. */
final class FeedTickets
{
    private function __construct()
    {
    }

    public static function of(string $url, ?string $etag = null, ?string $lastModified = null): RequestTicketModel
    {
        return RequestTicketModel::feed(new FetchTicketModel($url, $etag, $lastModified));
    }
}
```

In `tests/Support/FetchWiring.php`, add these imports:
- `use App\Service\Fetch\ConcurrentRequestEngine;`
- `use App\Service\Fetch\ResponseClassifier;`
- `use App\Service\Fetch\ResponseExchange\FeedResponseExchange;`
- `use Symfony\Component\Clock\MockClock;`
- `use Symfony\Component\DependencyInjection\ServiceLocator;`

Then add the method:

```php
    /** The engine as the container wires it, with every exchange keyed by its kind. */
    public static function requestEngine(
        HttpClientInterface $client,
        UrlGuard $urlGuard,
        EgressProxySourceInterface $egressProxy,
        int $concurrency = 4,
    ): ConcurrentRequestEngine {
        return new ConcurrentRequestEngine(
            $client,
            $urlGuard,
            $egressProxy,
            self::retryPolicy($urlGuard),
            new ServiceLocator([
                FeedResponseExchange::kind() => static fn (): FeedResponseExchange => new FeedResponseExchange(
                    new ResponseClassifier(new MockClock()),
                ),
            ]),
            $concurrency,
            'TestAgent/1.0',
        );
    }
```

Then point each construction of `ConcurrentFeedFetcher` at the engine. Delete each file's now-unused
`ResponseClassifier` and `MockClock` imports.

- `ConcurrentFeedFetcherTest::fetcher()`: replace the `return new ConcurrentFeedFetcher(...)` with:

  ```php
          return new ConcurrentFeedFetcher(
              FetchWiring::requestEngine(new MockHttpClient($responses), $urlGuard, $this->noEgressProxy(), $concurrency),
              $hostConcurrency,
          );
  ```

- `ConcurrentFeedFetcherProxyTest::fetcher()`:

  ```php
          return new ConcurrentFeedFetcher(
              FetchWiring::requestEngine(new MockHttpClient($responses), $urlGuard, $egressProxySource),
              100,
          );
  ```

  In `testAnUnreadableProxyPasswordFailsEveryFeedInsteadOfAbortingTheSweep`:

  ```php
          $fetcher = new ConcurrentFeedFetcher(FetchWiring::requestEngine($client, $urlGuard, $egressProxySource), 100);
  ```

- `RefreshRunnerConcurrentFetchTest::concurrentFetcher()`:

  ```php
          return new ConcurrentFeedFetcher(
              FetchWiring::requestEngine($httpClient, $urlGuard, $this->noEgressProxy(), $concurrency),
              $hostConcurrency,
          );
  ```

- `HttpFeedFetcherTest::fetcher()`:

  ```php
          return new HttpFeedFetcher(new ConcurrentFeedFetcher(
              FetchWiring::requestEngine(new MockHttpClient($responses), $urlGuard, $this->noEgressProxy(), 1),
              100,
          ));
  ```

In `FetchAttemptModelTest`, `FetchQueueTest`, `HostSlotsTest` and `ResponseClassifierTest`, each `new FetchTicketModel(`
becomes `FeedTickets::of(`, with the same arguments:

```bash
sed -i '' 's/new FetchTicketModel(/FeedTickets::of(/g; s/use App\\Service\\Fetch\\Model\\FetchTicketModel;/use App\\Tests\\Support\\FeedTickets;/' \
  tests/Service/Fetch/Model/FetchAttemptModelTest.php tests/Service/Fetch/Pass/FetchQueueTest.php \
  tests/Service/Fetch/Pass/HostSlotsTest.php tests/Service/Fetch/ResponseClassifierTest.php
```

Then fix the two type references by hand:
- In `FetchAttemptModelTest::testATemporaryRedirectMovesTheUrlWithoutMarkingItPermanent`, write
  `$next->ticket->feedTicket()->etag`.
- In `FetchQueueTest::queue()`, change the docblock to `@param array<int|string, RequestTicketModel> $tickets` and add
  `use App\Service\Fetch\Model\RequestTicketModel;`.

In `tests/Service/Fetch/OutboundUserAgentWiringTest.php`:
- delete the `yield 'feed fetcher' => …` line from `outboundServices()`;
- add `use App\Service\Fetch\ConcurrentRequestEngine;`;
- add this test. It reaches the engine through the aliased fetcher, because the engine may be inlined.

```php
    public function testTheRequestEngineBehindTheFeedFetcherSendsTheConfiguredAgent(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $fetcher = $container->get(ConcurrentFeedFetcher::class);
        $engine = (new \ReflectionProperty(ConcurrentFeedFetcher::class, 'engine'))->getValue($fetcher);
        self::assertInstanceOf(ConcurrentRequestEngine::class, $engine);

        self::assertSame(
            $container->getParameter('outbound_user_agent'),
            (new \ReflectionProperty(ConcurrentRequestEngine::class, 'userAgent'))->getValue($engine),
        );
    }
```

- [ ] **Step 10: Run the fetch and refresh suites**

Run: `bin/console cache:warmup && php bin/phpunit tests/Service/Fetch tests/Service/Refresh tests/Service/Discovery`
Expected: PASS. Every existing test passes unmodified except for the constructor and ticket changes above. That
includes all 28 tests of `ConcurrentFeedFetcherTest`, `ConcurrentFeedFetcherProxyTest`,
`RefreshRunnerConcurrentFetchTest` and `HttpFeedFetcherTest`.

- [ ] **Step 11: Run the gates**

Run: `composer check && composer md && composer test:parallel`
Expected:
- `composer check` and the suite are clean;
- `composer md` names none of `ConcurrentRequestEngine`, `ConcurrentFeedFetcher`, `FeedResponseExchange`,
  `RequestTicketModel`, `ExchangeStepModel`, `ResponseExchangeKind`, `FetchAttemptModel`, `FetchQueue`, `HostSlots` or
  `ResponseClassifier`.

- [ ] **Step 12: Commit**

From the repo root:

```bash
git add backend/src/Service/Fetch backend/config/services.yaml backend/tests/Support/FeedTickets.php \
  backend/tests/Support/FetchWiring.php backend/tests/Service/Fetch backend/tests/Service/Refresh/RefreshRunner/RefreshRunnerConcurrentFetchTest.php
git commit -m "refactor(#1607): extract the concurrent request engine from the feed fetcher"
```

---

### Task 2: API tickets, named slots and a dispatch gate on the engine

**Files:**
- Modify: `backend/src/Service/Fetch/Model/ResponseExchangeKind.php`
- Modify: `backend/src/Service/Fetch/Model/RequestTicketModel.php`
- Modify: `backend/src/Service/Fetch/Model/FetchOutcomeModel.php`
- Modify: `backend/src/Service/Fetch/FetchRetryPolicy.php`
- Create: `backend/src/Service/Fetch/ResponseExchange/ApiResponseExchange.php`
- Create: `backend/src/Service/Fetch/DispatchGate/DispatchGateInterface.php`
- Rename: `backend/src/Service/Fetch/Pass/HostSlots.php` → `backend/src/Service/Fetch/Pass/RequestSlots.php`
- Modify: `backend/src/Service/Fetch/Pass/FetchQueue.php`
- Modify: `backend/src/Service/Fetch/ConcurrentRequestEngine.php`
- Modify: `backend/tests/Support/FetchWiring.php`
- Create: `backend/tests/Service/Fetch/ConcurrentRequestEngineTest.php`
- Rename: `backend/tests/Service/Fetch/Pass/HostSlotsTest.php` → `backend/tests/Service/Fetch/Pass/RequestSlotsTest.php`
- Modify: `backend/tests/Service/Fetch/Pass/FetchQueueTest.php`
- Modify: `backend/tests/Service/Fetch/Model/FetchOutcomeModelTest.php`
- Modify: `backend/tests/Service/Fetch/Model/RequestTicketModelTest.php`
- Modify: `backend/tests/Service/Fetch/Model/ResponseExchangeKindTest.php`

**Interfaces:**
- Consumes (Task 1): `ConcurrentRequestEngine`, `RequestTicketModel`, `ResponseExchangeInterface`,
  `ExchangeStepModel`, `FetchWiring::requestEngine()`, `FeedTickets::of()`.
- Produces:
  - `ResponseExchangeKind::Api` (`'api'`; accept `application/json`, size `ResponseSizeLimit::Api`).
  - `RequestTicketModel::api(ApiRequestModel $request, string $slotName): self`. A host slot keys as `host:<host>`, a
    named slot as `slot:<name>`.
  - `FetchOutcomeModel::answered(ApiResponseModel $answer): self` and `::withheld(): self`, plus
    `isWithheld(): bool` and `answerOrThrow(): ApiResponseModel`.
  - `FetchRetryPolicy::nextRouteAfterErrorStatus(FetchAttemptModel $attempt): ?FetchAttemptModel`.
  - `DispatchGateInterface::admits(int|string $key): bool`.
  - `ConcurrentRequestEngine::sendAll(iterable $tickets, int $slotCapacity, ?DispatchGateInterface $gate = null)`.
  - `RequestSlots` (was `HostSlots`; same methods; message `Slot capacity must be at least 1, got %d.`).
  - `FetchWiring::egressProxy(?ProxyConfigModel $proxy): EgressProxySourceInterface`.

- [ ] **Step 1: Write the failing engine tests for API tickets**

Create `tests/Service/Fetch/ConcurrentRequestEngineTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Fetch;

use App\Enum\ProxyType;
use App\Service\Fetch\ConcurrentRequestEngine;
use App\Service\Fetch\DispatchGate\DispatchGateInterface;
use App\Service\Fetch\Exception\ResponseTooLargeException;
use App\Service\Fetch\IpValidator;
use App\Service\Fetch\Model\ApiRequestModel;
use App\Service\Fetch\Model\FetchOutcomeModel;
use App\Service\Fetch\Model\ProxyConfigModel;
use App\Service\Fetch\Model\RequestTicketModel;
use App\Service\Fetch\Model\ResponseSizeLimit;
use App\Service\Fetch\UrlGuard;
use App\Tests\Support\FetchWiring;
use App\Tests\Support\RoutedHttpClient;
use App\Tests\Support\StaticDnsResolver;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class ConcurrentRequestEngineTest extends TestCase
{
    private const string API = 'https://api.example.test/timeline';
    private const string OTHER = 'https://other.example.test/timeline';
    private const string DUAL = 'https://dual.example.test/timeline';
    private const string IPV6 = '2a02:2e0:3fe:1001:7777:772e:2:85';
    private const string IPV4 = '193.99.144.85';

    private function engine(HttpClientInterface $client, ?ProxyConfigModel $proxy = null): ConcurrentRequestEngine
    {
        $guard = new UrlGuard(new StaticDnsResolver([
            'api.example.test' => ['93.184.216.34'],
            'other.example.test' => ['93.184.216.35'],
            'dual.example.test' => [self::IPV6, self::IPV4],
        ]), new IpValidator());

        return FetchWiring::requestEngine($client, $guard, FetchWiring::egressProxy($proxy));
    }

    private static function ticket(string $url = self::API, string $slotName = 'account-1'): RequestTicketModel
    {
        return RequestTicketModel::api(ApiRequestModel::get($url, ['Authorization' => 'Bearer jwt-1']), $slotName);
    }

    /**
     * @param iterable<int|string, FetchOutcomeModel> $outcomes
     *
     * @return array<int|string, FetchOutcomeModel>
     */
    private static function collect(iterable $outcomes): array
    {
        $collected = [];
        foreach ($outcomes as $key => $outcome) {
            $collected[$key] = $outcome;
        }

        return $collected;
    }

    private function onlyAnswer(HttpClientInterface $client, string $url = self::API): FetchOutcomeModel
    {
        return self::collect($this->engine($client)->sendAll([1 => self::ticket($url)], 3))[1];
    }

    public function testAnErrorStatusIsAnAnswerWithItsBodyAndHeaders(): void
    {
        $client = new MockHttpClient([new MockResponse('{"error":"RateLimitExceeded"}', [
            'http_code' => Response::HTTP_TOO_MANY_REQUESTS,
            'response_headers' => ['Retry-After' => '120'],
        ])]);

        $answer = $this->onlyAnswer($client)->answerOrThrow();

        self::assertSame(Response::HTTP_TOO_MANY_REQUESTS, $answer->status);
        self::assertSame('{"error":"RateLimitExceeded"}', $answer->body);
        self::assertSame('120', $answer->header('retry-after'));
    }

    public function testARedirectIsAnAnswerAndNeverFollowed(): void
    {
        $http = new RoutedHttpClient(['GET ' . self::API => new MockResponse('', [
            'http_code' => Response::HTTP_FOUND,
            'response_headers' => ['Location' => self::OTHER],
        ])]);

        $answer = $this->onlyAnswer($http->client())->answerOrThrow();

        self::assertSame(Response::HTTP_FOUND, $answer->status);
        self::assertSame([self::API], $http->requestedUrls());
    }

    public function testAnErrorStatusIsAskedAgainOverTheNextFamily(): void
    {
        $client = new MockHttpClient(static function (string $method, string $url, array $options): MockResponse {
            /** @var array<string, string> $resolve */
            $resolve = $options['resolve'];

            return self::IPV4 === $resolve['dual.example.test']
                ? new MockResponse('{"feed":[]}')
                : new MockResponse('{}', ['http_code' => Response::HTTP_SERVICE_UNAVAILABLE]);
        });

        self::assertSame(Response::HTTP_OK, $this->onlyAnswer($client, self::DUAL)->answerOrThrow()->status);
    }

    public function testTheLastFamilysErrorStatusStandsAsTheAnswer(): void
    {
        /** @var list<string> $pins */
        $pins = [];
        $client = new MockHttpClient(static function (string $method, string $url, array $options) use (&$pins): MockResponse {
            /** @var array<string, string> $resolve */
            $resolve = $options['resolve'];
            $pins[] = $resolve['dual.example.test'];

            return new MockResponse('{"error":"InvalidToken"}', ['http_code' => Response::HTTP_UNAUTHORIZED]);
        });

        $answer = $this->onlyAnswer($client, self::DUAL)->answerOrThrow();

        self::assertSame(Response::HTTP_UNAUTHORIZED, $answer->status);
        self::assertSame('{"error":"InvalidToken"}', $answer->body);
        self::assertSame([self::IPV6 . ',' . self::IPV4, self::IPV4, self::IPV6], $pins);
    }

    public function testAMissingResourceIsNotAskedAgain(): void
    {
        $requests = 0;
        $client = new MockHttpClient(static function () use (&$requests): MockResponse {
            ++$requests;

            return new MockResponse('{}', ['http_code' => Response::HTTP_NOT_FOUND]);
        });

        self::assertSame(Response::HTTP_NOT_FOUND, $this->onlyAnswer($client, self::DUAL)->answerOrThrow()->status);
        self::assertSame(1, $requests);
    }

    public function testAProxiedRefusalIsAskedOnceDirectly(): void
    {
        /** @var list<bool> $proxied */
        $proxied = [];
        $client = new MockHttpClient(static function (string $method, string $url, array $options) use (&$proxied): MockResponse {
            $proxied[] = isset($options['proxy']);

            return isset($options['proxy'])
                ? new MockResponse('', ['http_code' => Response::HTTP_FORBIDDEN])
                : new MockResponse('[]');
        });
        $engine = $this->engine($client, new ProxyConfigModel(ProxyType::Socks5, 'p', 1080, null, null));

        $answer = self::collect($engine->sendAll([1 => self::ticket()], 3))[1]->answerOrThrow();

        self::assertSame(Response::HTTP_OK, $answer->status);
        self::assertSame([true, false], $proxied);
    }

    public function testAProxiedRefusalStandsWhenDirectFallbackIsOff(): void
    {
        $requests = 0;
        $client = new MockHttpClient(static function () use (&$requests): MockResponse {
            ++$requests;

            return new MockResponse('', ['http_code' => Response::HTTP_FORBIDDEN]);
        });
        $engine = $this->engine($client, new ProxyConfigModel(ProxyType::Socks5, 'p', 1080, null, null, false));

        $answer = self::collect($engine->sendAll([1 => self::ticket()], 3))[1]->answerOrThrow();

        self::assertSame(Response::HTTP_FORBIDDEN, $answer->status);
        self::assertSame(1, $requests);
    }

    public function testSendsTheTicketsMethodHeadersAndJsonBody(): void
    {
        $http = new RoutedHttpClient(['POST ' . self::API => new MockResponse('{}')]);
        $ticket = RequestTicketModel::api(
            ApiRequestModel::postJson(self::API, ['identifier' => 'did:plc:alice'], ['Authorization' => 'Bearer jwt-1']),
            'account-1',
        );

        self::collect($this->engine($http->client())->sendAll([1 => $ticket], 3));

        self::assertSame('{"identifier":"did:plc:alice"}', $http->requests[0]['body']);
        self::assertContains('Accept: application/json', $http->requests[0]['headers']);
        self::assertContains('Authorization: Bearer jwt-1', $http->requests[0]['headers']);
        self::assertContains('User-Agent: TestAgent/1.0', $http->requests[0]['headers']);
    }

    public function testANamedSlotCapsItsRequestsWhateverTheirHost(): void
    {
        $inFlight = 0;
        $peak = 0;
        $client = new MockHttpClient(static function () use (&$inFlight, &$peak): MockResponse {
            ++$inFlight;
            $peak = max($peak, $inFlight);
            $body = (static function () use (&$inFlight): \Generator {
                yield '{}';
                --$inFlight;
            })();

            return new MockResponse($body);
        });

        $outcomes = self::collect($this->engine($client)->sendAll([
            1 => self::ticket(self::API),
            2 => self::ticket(self::OTHER),
            3 => self::ticket(self::API),
        ], 1));

        self::assertCount(3, $outcomes);
        self::assertSame(1, $peak);
    }

    public function testAWithheldTicketGoesNowhere(): void
    {
        $http = new RoutedHttpClient(['GET ' . self::API => new MockResponse('{}')]);
        $gate = new class () implements DispatchGateInterface {
            public function admits(int|string $key): bool
            {
                return 2 !== $key;
            }
        };

        $outcomes = self::collect($this->engine($http->client())->sendAll(
            [1 => self::ticket(self::API), 2 => self::ticket(self::OTHER, 'account-2')],
            3,
            $gate,
        ));

        self::assertFalse($outcomes[1]->isWithheld());
        self::assertTrue($outcomes[2]->isWithheld());
        self::assertSame([self::API], $http->requestedUrls());
    }

    /** The gate is asked when a parked ticket would go out, not when the queue pulled it. */
    public function testTheGateDecidesWhenAParkedTicketWouldGoOut(): void
    {
        $gate = new class () implements DispatchGateInterface {
            public bool $open = true;

            public function admits(int|string $key): bool
            {
                return $this->open;
            }
        };
        $http = new RoutedHttpClient(['GET ' . self::API => new MockResponse('{}')]);

        $outcomes = [];
        foreach ($this->engine($http->client())->sendAll([1 => self::ticket(), 2 => self::ticket()], 1, $gate) as $key => $outcome) {
            $outcomes[$key] = $outcome;
            $gate->open = false;
        }

        self::assertFalse($outcomes[1]->isWithheld());
        self::assertTrue($outcomes[2]->isWithheld());
        self::assertCount(1, $http->requests);
    }

    public function testAnOversizedAnswerFailsAtTheApiLimit(): void
    {
        $client = new MockHttpClient([new MockResponse(str_repeat('x', ResponseSizeLimit::Api->bytes() + 1))]);

        self::assertInstanceOf(ResponseTooLargeException::class, $this->onlyAnswer($client)->failure());
    }
}
```

The `foreach (… sendAll(...) as $key => $outcome)` line in `testTheGateDecidesWhenAParkedTicketWouldGoOut` is over 120
columns. Assign `$outcomes = $this->engine($http->client())->sendAll([...], 1, $gate);` first, then iterate. Do the
same for any `new MockHttpClient(static function (…) use (&…): MockResponse {` line that phpcs flags: put the closure
in a `$respond` variable first.

Append to `tests/Service/Fetch/Model/FetchOutcomeModelTest.php` (add `use App\Service\Fetch\Model\ApiResponseModel;`):

```php
    public function testAnAnsweredOutcomeCarriesItsAnswerAndNoFailure(): void
    {
        $answer = new ApiResponseModel(401, '{"error":"InvalidToken"}');

        $outcome = FetchOutcomeModel::answered($answer);

        self::assertSame($answer, $outcome->answerOrThrow());
        self::assertNull($outcome->failure());
        self::assertFalse($outcome->isWithheld());
    }

    public function testAFailedOutcomeRethrowsInsteadOfAnswering(): void
    {
        $failure = new FeedUnreachableException('https://example.com/api: timed out');

        $this->expectExceptionObject($failure);

        FetchOutcomeModel::failed($failure)->answerOrThrow();
    }

    public function testAWithheldOutcomeIsNeitherAnsweredNorFailed(): void
    {
        $outcome = FetchOutcomeModel::withheld();

        self::assertTrue($outcome->isWithheld());
        self::assertNull($outcome->failure());
        $this->expectException(\LogicException::class);
        $outcome->answerOrThrow();
    }

    public function testAFeedOutcomeCarriesNoApiAnswer(): void
    {
        $outcome = FetchOutcomeModel::succeeded(
            FetchResponseModel::notModified('https://example.com/feed', false, null, null),
        );

        self::assertFalse($outcome->isWithheld());
        $this->expectException(\LogicException::class);
        $outcome->answerOrThrow();
    }
```

Append to `tests/Service/Fetch/Model/RequestTicketModelTest.php` (add
`use App\Service\Fetch\Model\ApiRequestModel;`):

```php
    public function testAnApiTicketIsReadAsAnAnswer(): void
    {
        $request = ApiRequestModel::get('https://pds.example.test/xrpc/x', ['Authorization' => 'Bearer jwt-1']);

        $ticket = RequestTicketModel::api($request, 'account-7');

        self::assertSame(ResponseExchangeKind::Api, $ticket->exchange);
        self::assertSame($request, $ticket->request);
    }

    public function testANamedSlotIgnoresTheHostAndNeverMeetsAHostSlot(): void
    {
        $api = RequestTicketModel::api(ApiRequestModel::get('https://example.com/a'), 'example.com');
        $feed = RequestTicketModel::feed(new FetchTicketModel('https://example.com/feed'));

        self::assertSame($api->slotKeyFor('https://example.com/a'), $api->slotKeyFor('https://other.example/b'));
        self::assertNotSame($api->slotKeyFor('https://example.com/a'), $feed->slotKeyFor('https://example.com/a'));
    }

    public function testAnApiTicketCarriesNoFeedTicket(): void
    {
        $this->expectException(\LogicException::class);

        RequestTicketModel::api(ApiRequestModel::get('https://example.com/a'), 'account-7')->feedTicket();
    }
```

Append to `tests/Service/Fetch/Model/ResponseExchangeKindTest.php`:

```php
    public function testAnApiAsksForJsonAndIsCappedAtTheApiLimit(): void
    {
        self::assertSame('application/json', ResponseExchangeKind::Api->accept());
        self::assertSame(ResponseSizeLimit::Api, ResponseExchangeKind::Api->sizeLimit());
    }
```

- [ ] **Step 2: Run them to verify they fail**

Run: `php bin/phpunit --filter 'ConcurrentRequestEngineTest|FetchOutcomeModelTest|RequestTicketModelTest|ResponseExchangeKindTest'`
Expected: FAIL with `Call to undefined method …RequestTicketModel::api()`, `…FetchOutcomeModel::answered()` and
`Undefined constant …ResponseExchangeKind::Api`.

- [ ] **Step 3: Add the API kind, the API ticket and the new outcome kinds**

In `ResponseExchangeKind`, add `case Api = 'api';` under `Feed`, and an arm to each match:

```php
    public function accept(): string
    {
        return match ($this) {
            self::Feed => self::FEED_ACCEPT,
            self::Api => 'application/json',
        };
    }

    public function sizeLimit(): ResponseSizeLimit
    {
        return match ($this) {
            self::Feed => ResponseSizeLimit::Feed,
            self::Api => ResponseSizeLimit::Api,
        };
    }
```

In `RequestTicketModel`, add the slot name, the `api()` factory, and the prefixed slot keys:

```php
    private function __construct(
        public ResponseExchangeKind $exchange,
        public ApiRequestModel $request,
        private ?string $slotName,
        private ?FetchTicketModel $feedTicket,
    ) {
    }

    public static function feed(FetchTicketModel $ticket): self
    {
        return new self(
            ResponseExchangeKind::Feed,
            ApiRequestModel::get($ticket->url, self::validatorHeaders($ticket)),
            null,
            $ticket,
        );
    }

    /** Any status comes back as the answer; tickets sharing a slot name share its capacity, whatever their host. */
    public static function api(ApiRequestModel $request, string $slotName): self
    {
        return new self(ResponseExchangeKind::Api, $request, $slotName, null);
    }

    public function slotKeyFor(string $url): string
    {
        return null === $this->slotName ? 'host:' . HostKey::forUrl($url) : 'slot:' . $this->slotName;
    }
```

Replace `src/Service/Fetch/Model/FetchOutcomeModel.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Fetch\Model;

use App\Service\Fetch\Exception\FetchException;

/**
 * One request's result inside a batch. Failure is a value here rather than a thrown
 * exception because a batch cannot throw for one request without abandoning the
 * others still in flight; the caller unwraps and decides per request.
 */
final readonly class FetchOutcomeModel
{
    private function __construct(
        private ?FetchResponseModel $response,
        private ?ApiResponseModel $answer,
        private ?FetchException $failure,
    ) {
    }

    public static function succeeded(FetchResponseModel $response): self
    {
        return new self($response, null, null);
    }

    public static function answered(ApiResponseModel $answer): self
    {
        return new self(null, $answer, null);
    }

    public static function failed(FetchException $failure): self
    {
        return new self(null, null, $failure);
    }

    /** The dispatch gate kept the request off the wire. */
    public static function withheld(): self
    {
        return new self(null, null, null);
    }

    public function isWithheld(): bool
    {
        return null === $this->response && null === $this->answer && null === $this->failure;
    }

    public function failure(): ?FetchException
    {
        return $this->failure;
    }

    /** @throws FetchException when this outcome is a failure */
    public function responseOrThrow(): FetchResponseModel
    {
        if (null !== $this->failure) {
            throw $this->failure;
        }

        return $this->response ?? throw new \LogicException('This outcome carries no feed response.');
    }

    /** @throws FetchException when this outcome is a failure */
    public function answerOrThrow(): ApiResponseModel
    {
        if (null !== $this->failure) {
            throw $this->failure;
        }

        return $this->answer ?? throw new \LogicException('This outcome carries no API answer.');
    }
}
```

- [ ] **Step 4: Teach the retry policy to reroute an error status**

In `src/Service/Fetch/FetchRetryPolicy.php`, replace `nextAttemptAfter()` and `overNextFamily()`. Add
`nextRouteAfterErrorStatus()` between them. `directFallbackFor()` and `warrantsAnotherFamily()` stay as they are.

```php
    /**
     * The next attempt this failure earns, or null when it is terminal. A still-proxied attempt (no direct fallback)
     * never retries over another family: that would re-send the same proxied request once per family.
     */
    public function nextAttemptAfter(FetchAttemptModel $attempt, FetchException $failure): ?FetchAttemptModel
    {
        $fallback = $this->directFallbackFor($attempt);
        if (null !== $fallback) {
            return $fallback;
        }

        return $attempt->isProxied() || !$this->warrantsAnotherFamily($failure) ? null : $this->overNextFamily($attempt);
    }

    /** An error status can be tied to the route: the one direct fallback, else the next address family, may differ. */
    public function nextRouteAfterErrorStatus(FetchAttemptModel $attempt): ?FetchAttemptModel
    {
        return $this->directFallbackFor($attempt) ?? ($attempt->isProxied() ? null : $this->overNextFamily($attempt));
    }

    private function overNextFamily(FetchAttemptModel $attempt): ?FetchAttemptModel
    {
        try {
            $familyCount = \count($this->urlGuard->assertSafe($attempt->url)->pinnedAddressAttempts());
        } catch (FetchException) {
            return null;
        }

        return $attempt->pinnedAddressAttempt + 1 < $familyCount
            ? $attempt->overNextPinnedAddress()
            : null;
    }
```

- [ ] **Step 5: Create the API exchange and the dispatch gate**

`src/Service/Fetch/ResponseExchange/ApiResponseExchange.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Fetch\ResponseExchange;

use App\Service\Fetch\CrossFamilyFailover;
use App\Service\Fetch\FetchRetryPolicy;
use App\Service\Fetch\Model\ApiResponseModel;
use App\Service\Fetch\Model\ExchangeStepModel;
use App\Service\Fetch\Model\FetchAttemptModel;
use App\Service\Fetch\Model\FetchOutcomeModel;
use App\Service\Fetch\Model\ResponseExchangeKind;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * An API's answer whatever its status, a redirect included; an error status another route may answer differently is
 * asked again over that route first, as FailoverRequestSender does for a blocking call.
 */
final readonly class ApiResponseExchange implements ResponseExchangeInterface
{
    public function __construct(private FetchRetryPolicy $retryPolicy, private CrossFamilyFailover $failover)
    {
    }

    public static function kind(): string
    {
        return ResponseExchangeKind::Api->value;
    }

    public function onHeaders(ResponseInterface $response, FetchAttemptModel $attempt): ExchangeStepModel
    {
        if (!$this->failover->isRetryableStatus($response->getStatusCode())) {
            return ExchangeStepModel::awaitBody();
        }
        $nextRoute = $this->retryPolicy->nextRouteAfterErrorStatus($attempt);

        return null === $nextRoute ? ExchangeStepModel::awaitBody() : ExchangeStepModel::sendNext($nextRoute);
    }

    public function onBody(ResponseInterface $response, FetchAttemptModel $attempt): FetchOutcomeModel
    {
        return FetchOutcomeModel::answered(new ApiResponseModel(
            $response->getStatusCode(),
            $response->getContent(false),
            $response->getHeaders(false),
        ));
    }
}
```

`src/Service/Fetch/DispatchGate/DispatchGateInterface.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Fetch\DispatchGate;

/** Asked as a ticket's request is about to go on the wire, not when it is pulled: a parked ticket may wait a while. */
interface DispatchGateInterface
{
    public function admits(int|string $key): bool;
}
```

- [ ] **Step 6: Rename the slots, and gate the engine's fill**

From the repo root:

```bash
git mv backend/src/Service/Fetch/Pass/HostSlots.php backend/src/Service/Fetch/Pass/RequestSlots.php
git mv backend/tests/Service/Fetch/Pass/HostSlotsTest.php backend/tests/Service/Fetch/Pass/RequestSlotsTest.php
```

Replace `src/Service/Fetch/Pass/RequestSlots.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Fetch\Pass;

use App\Service\Fetch\Model\FetchAttemptModel;

/** In-flight requests per slot key, capped so a ticket whose slot is full waits for it instead of earning a 429. */
final class RequestSlots
{
    /** @var array<string, int> */
    private array $inFlightBySlot = [];

    public function __construct(private readonly int $capacityPerSlot)
    {
        if ($capacityPerSlot < 1) {
            throw new \InvalidArgumentException(
                sprintf('Slot capacity must be at least 1, got %d.', $capacityPerSlot),
            );
        }
    }

    public function hasCapacityFor(FetchAttemptModel $attempt): bool
    {
        return ($this->inFlightBySlot[$this->keyOf($attempt)] ?? 0) < $this->capacityPerSlot;
    }

    public function acquire(FetchAttemptModel $attempt): void
    {
        $slot = $this->keyOf($attempt);
        $this->inFlightBySlot[$slot] = ($this->inFlightBySlot[$slot] ?? 0) + 1;
    }

    public function release(FetchAttemptModel $attempt): void
    {
        $slot = $this->keyOf($attempt);
        $remaining = ($this->inFlightBySlot[$slot] ?? 0) - 1;

        if ($remaining <= 0) {
            unset($this->inFlightBySlot[$slot]);

            return;
        }

        $this->inFlightBySlot[$slot] = $remaining;
    }

    private function keyOf(FetchAttemptModel $attempt): string
    {
        return $attempt->ticket->slotKeyFor($attempt->url);
    }
}
```

In `src/Service/Fetch/Pass/FetchQueue.php`, the constructor parameter `HostSlots $hostSlots` becomes
`RequestSlots $slots`, and every `$this->hostSlots` becomes `$this->slots`:

```bash
sed -i '' 's/HostSlots \$hostSlots/RequestSlots $slots/; s/\$this->hostSlots/$this->slots/g' src/Service/Fetch/Pass/FetchQueue.php
```

`FetchQueue` and `HostSlots` share the namespace, so the queue imports nothing.

In `src/Service/Fetch/ConcurrentRequestEngine.php`:
- replace `use App\Service\Fetch\Pass\HostSlots;` with `use App\Service\Fetch\Pass\RequestSlots;`;
- add `use App\Service\Fetch\DispatchGate\DispatchGateInterface;`;
- in `sendAll()`, change the signature and docblock, the queue line and the fill call;
- replace `fill()`.

```php
    /**
     * A failed request arrives as a failed outcome, never a throw, so one bad ticket cannot abandon the others;
     * abandoning the generator cancels whatever is still in flight. A ticket the gate refuses yields a withheld outcome.
     *
     * @param iterable<int|string, RequestTicketModel> $tickets
     *
     * @return \Generator<int|string, FetchOutcomeModel>
     */
    public function sendAll(iterable $tickets, int $slotCapacity, ?DispatchGateInterface $gate = null): \Generator
```

```php
        $queue = new FetchQueue($this->iterator($tickets), new RequestSlots($slotCapacity), $this->concurrency, $batchProxy);
```

That line is 123 columns. Write it as:

```php
        $queue = new FetchQueue(
            $this->iterator($tickets),
            new RequestSlots($slotCapacity),
            $this->concurrency,
            $batchProxy,
        );
```

Inside the `while (true)` loop: `yield from $this->fill($queue, $inFlight, $gate);`.

```php
    /**
     * @param \SplObjectStorage<ResponseInterface, FetchAttemptModel> $inFlight
     *
     * @return \Generator<int|string, FetchOutcomeModel>
     */
    private function fill(FetchQueue $queue, \SplObjectStorage $inFlight, ?DispatchGateInterface $gate): \Generator
    {
        while ($inFlight->count() < $this->concurrency) {
            $attempt = $queue->takeRunnable();
            if (null === $attempt) {
                return;
            }
            if (null !== $gate && !$gate->admits($attempt->key)) {
                yield $attempt->key => FetchOutcomeModel::withheld();
                continue;
            }

            try {
                $response = $this->send($attempt);
            } catch (FetchException $exception) {
                $fallback = $this->retryPolicy->directFallbackFor($attempt);
                if (null !== $fallback) {
                    $queue->requeue($fallback);
                    continue;
                }

                yield $attempt->key => FetchOutcomeModel::failed($exception);
                continue;
            }

            $queue->onSent($attempt);
            $inFlight[$response] = $attempt;
        }
    }
```

- [ ] **Step 7: Wire the API exchange into the test engine, and port the slot and queue tests**

In `tests/Support/FetchWiring.php`:
- add `use App\Service\Fetch\ResponseExchange\ApiResponseExchange;`;
- make `requestEngine()` register both exchanges;
- extract the egress stub that `guardedApiClient()` builds into `egressProxy()`, and have `guardedApiClient()` call
  `self::egressProxy($proxy)`.

```php
    public static function requestEngine(
        HttpClientInterface $client,
        UrlGuard $urlGuard,
        EgressProxySourceInterface $egressProxy,
        int $concurrency = 4,
    ): ConcurrentRequestEngine {
        $retryPolicy = self::retryPolicy($urlGuard);

        return new ConcurrentRequestEngine(
            $client,
            $urlGuard,
            $egressProxy,
            $retryPolicy,
            new ServiceLocator([
                FeedResponseExchange::kind() => static fn (): FeedResponseExchange => new FeedResponseExchange(
                    new ResponseClassifier(new MockClock()),
                ),
                ApiResponseExchange::kind() => static fn (): ApiResponseExchange => new ApiResponseExchange(
                    $retryPolicy,
                    new CrossFamilyFailover(),
                ),
            ]),
            $concurrency,
            'TestAgent/1.0',
        );
    }

    public static function egressProxy(?ProxyConfigModel $proxy): EgressProxySourceInterface
    {
        return new class ($proxy) implements EgressProxySourceInterface {
            public function __construct(private readonly ?ProxyConfigModel $proxy)
            {
            }

            public function egressProxy(): ?ProxyConfigModel
            {
                return $this->proxy;
            }
        };
    }
```

In `tests/Service/Fetch/Pass/RequestSlotsTest.php`:

```bash
sed -i '' 's/HostSlots/RequestSlots/g; s/Per-host concurrency must be at least 1, got 0./Slot capacity must be at least 1, got 0./' \
  tests/Service/Fetch/Pass/RequestSlotsTest.php
sed -i '' 's/HostSlots/RequestSlots/g; s/\$hostSlots/$slots/g' tests/Service/Fetch/Pass/FetchQueueTest.php
```

Then append to `RequestSlotsTest`. Add `use App\Service\Fetch\Model\ApiRequestModel;` and
`use App\Service\Fetch\Model\RequestTicketModel;`.

```php
    private function apiAttempt(string $url, string $slotName): FetchAttemptModel
    {
        return FetchAttemptModel::start(1, RequestTicketModel::api(ApiRequestModel::get($url), $slotName));
    }

    public function testANamedSlotIsSharedWhateverTheHost(): void
    {
        $slots = new RequestSlots(1);

        $slots->acquire($this->apiAttempt('https://one.example.com/a', 'account-7'));

        self::assertFalse($slots->hasCapacityFor($this->apiAttempt('https://two.example.com/b', 'account-7')));
        self::assertTrue($slots->hasCapacityFor($this->apiAttempt('https://one.example.com/a', 'account-8')));
    }

    public function testANamedSlotNeverTakesAHostsCapacity(): void
    {
        $slots = new RequestSlots(1);

        $slots->acquire($this->apiAttempt('https://example.com/a', 'example.com'));

        self::assertTrue($slots->hasCapacityFor($this->attempt('https://example.com/feed')));
    }
```

- [ ] **Step 8: Run the fetch suites**

Run: `php bin/phpunit tests/Service/Fetch tests/Service/Refresh/RefreshRunner tests/Service/Social`
Expected: PASS. The 28 RSS engine tests are unchanged. `ConcurrentRequestEngineTest` (12),
`FetchOutcomeModelTest`, `RequestTicketModelTest`, `ResponseExchangeKindTest` and `RequestSlotsTest` are green.
`GuardedApiClientTest` still passes on the extracted egress stub.

- [ ] **Step 9: Run the gates**

Run: `bin/console cache:warmup && composer check && composer md && composer test:parallel`
Expected: clean. `composer md` names none of the touched Fetch files. `ResponseExchangeWiringTest` now also checks
`api`.

- [ ] **Step 10: Commit**

From the repo root:

```bash
git add backend/src/Service/Fetch backend/tests/Support/FetchWiring.php backend/tests/Service/Fetch
git commit -m "feat(#1607): API tickets, named slots and a dispatch gate on the request engine"
```

---

### Task 3: Split each timeline source into a request and a page mapper

The blocking `page()` stays until Task 6, so the tree keeps working. This task adds the non-blocking half beside it.

**Files:**
- Create: `backend/src/Service/Social/Exception/SocialSessionStaleException.php`
- Modify: `backend/src/Service/Social/BlueskySessions.php`
- Modify: `backend/src/Service/Social/BlueskyApiClient.php`
- Modify: `backend/src/Service/Social/MastodonApiClient.php`
- Modify: `backend/src/Service/Social/MastodonAccountApi.php`
- Modify: `backend/src/Service/Social/TimelineSource/TimelineSourceInterface.php`
- Modify: `backend/src/Service/Social/TimelineSource/BlueskyTimelineSource.php`
- Modify: `backend/src/Service/Social/TimelineSource/MastodonTimelineSource.php`
- Modify: `backend/tests/Service/Social/TimelineSource/BlueskyTimelineSourceTest.php`
- Modify: `backend/tests/Service/Social/TimelineSource/MastodonTimelineSourceTest.php`
- Modify: `backend/tests/Service/Social/TimelineReader/NetworkTimelineReaderTest.php`

**Interfaces:**
- Consumes (Task 2): `FetchOutcomeModel::answered()`, `::failed()`, `answerOrThrow()`; `ApiRequestModel`;
  `ApiResponseModel`.
- Produces:
  - `TimelineSourceInterface::request(SocialFeed $socialFeed): ApiRequestModel`, which throws Rejected, Throttled or
    Unreachable.
  - `TimelineSourceInterface::pageOf(SocialFeed $socialFeed, FetchOutcomeModel $outcome): TimelinePageModel`, which
    throws `SocialSessionStaleException`, Rejected, Throttled or Unreachable.
  - `TimelineSourceInterface::dropSession(SocialAccount $account): void`.
  - `BlueskySessions::SESSION_REFUSED`, `BlueskySessions::authorizedGet(SocialAccount, string): ApiRequestModel`,
    `BlueskySessions::forget(SocialAccount): void`, and the public static
    `BlueskySessions::isStaleSession(ApiResponseModel): bool`.
  - `BlueskyApiClient::answerOf(FetchOutcomeModel): ApiResponseModel` and
    `MastodonApiClient::answerOf(FetchOutcomeModel): ApiResponseModel`, both static.
  - `MastodonAccountApi::authorizedGet(SocialAccount, string): ApiRequestModel`.
  - `SocialSessionStaleException` (final, extends `\RuntimeException`).

- [ ] **Step 1: Write the failing source tests**

In `tests/Service/Social/TimelineSource/BlueskyTimelineSourceTest.php`, add these imports:
- `use App\Service\Fetch\Exception\FeedUnreachableException;`
- `use App\Service\Fetch\Model\ApiResponseModel;`
- `use App\Service\Fetch\Model\FetchOutcomeModel;`
- `use App\Service\Social\Exception\SocialSessionStaleException;`
- `use App\Service\Social\Model\TimelinePageModel;`

Then change `timeline()` to `return self::json(self::timelineBody());`, with the array moved into:

```php
    /** @return array<string, mixed> */
    private static function timelineBody(): array
    {
        return ['feed' => [[
            'post' => [
                'uri' => 'at://did:plc:bob/app.bsky.feed.post/3abc',
                'author' => ['did' => 'did:plc:bob', 'handle' => 'bob.bsky.social'],
                'record' => ['text' => 'Hello', 'createdAt' => '2026-10-12T10:00:00Z'],
            ],
        ]]];
    }
```

Add these tests and helpers:

```php
    public function testARequestCarriesTheSessionsTokenToTheAccountsOwnServiceOnly(): void
    {
        $this->sessionCache()->remember(
            $this->account,
            new BlueskySessionModel('cached-jwt', 'alice.bsky.social'),
        );
        $this->rewriteCachedEntry(['pdsUrl' => 'https://elsewhere.example.test']);
        $http = new RoutedHttpClient([]);

        $request = $this->source($http->client())->request($this->socialFeed(SocialFeedKind::Home, ''));

        self::assertSame(self::TIMELINE, $request->method . ' ' . $request->url);
        self::assertSame('Bearer cached-jwt', $request->headers['Authorization'] ?? null);
        self::assertSame([], $http->requests);
    }

    #[DataProvider('collections')]
    public function testAListOrCustomFeedIsRequestedByItsUri(SocialFeedKind $kind, string $uri, string $route): void
    {
        $http = new RoutedHttpClient([self::SESSION => self::session('jwt-1')]);

        $request = $this->source($http->client())->request($this->socialFeed($kind, $uri));

        self::assertSame($route, $request->method . ' ' . $request->url);
    }

    public function testRequestsForSeveralFeedsOpenOneSession(): void
    {
        $http = new RoutedHttpClient([self::SESSION => self::session('jwt-1')]);
        $source = $this->source($http->client());

        $source->request($this->socialFeed(SocialFeedKind::Home, ''));
        $source->request($this->socialFeed(SocialFeedKind::UserList, self::LIST));

        self::assertSame(['POST'], array_column($http->requests, 'method'));
    }

    public function testADroppedSessionIsOpenedAfreshByTheNextRequest(): void
    {
        $this->sessionCache()->remember(
            $this->account,
            new BlueskySessionModel('stale-jwt', 'alice.bsky.social'),
        );
        $http = new RoutedHttpClient([self::SESSION => self::session('jwt-2')]);
        $source = $this->source($http->client());

        $source->dropSession($this->account);
        $request = $source->request($this->socialFeed(SocialFeedKind::Home, ''));

        self::assertSame('Bearer jwt-2', $request->headers['Authorization'] ?? null);
        self::assertCount(1, $http->requests);
    }

    public function testAnAnswerMapsToThePage(): void
    {
        self::assertCount(1, $this->pageOf(self::answer(self::timelineBody()))->parsed->entries);
    }

    /** @return iterable<string, array{int, string}> */
    public static function staleSessions(): iterable
    {
        yield 'expired' => [Response::HTTP_BAD_REQUEST, 'ExpiredToken'];
        yield 'invalid' => [Response::HTTP_UNAUTHORIZED, 'InvalidToken'];
    }

    #[DataProvider('staleSessions')]
    public function testAStaleSessionAsksForAFreshOne(int $status, string $error): void
    {
        $this->expectException(SocialSessionStaleException::class);
        $this->expectExceptionMessage('Bluesky refused the session of this account.');

        $this->pageOf(self::answer(['error' => $error], $status));
    }

    public function testAnUnauthenticatedAnswerIsARefusedCredential(): void
    {
        $this->expectException(SocialCredentialsRejectedException::class);

        $this->pageOf(self::answer(['error' => 'AuthenticationRequired'], Response::HTTP_UNAUTHORIZED));
    }

    public function testATooManyRequestsAnswerIsAThrottleCarryingItsDelay(): void
    {
        $answer = self::answer(['error' => 'RateLimitExceeded'], Response::HTTP_TOO_MANY_REQUESTS, ['120']);

        try {
            $this->pageOf($answer);
            self::fail('A 429 must not read as a page.');
        } catch (SocialNetworkThrottledException $exception) {
            self::assertSame(120, $exception->retryAfterSeconds);
        }
    }

    public function testNoAnswerIsAnUnreachableBluesky(): void
    {
        $this->expectException(SocialNetworkUnreachableException::class);
        $this->expectExceptionMessage('Bluesky could not be reached.');

        $this->pageOf(FetchOutcomeModel::failed(new FeedUnreachableException(self::PDS . ': timed out')));
    }

    /** @return iterable<string, array{int, array<string, mixed>}> */
    public static function unusableBodies(): iterable
    {
        yield 'server error' => [Response::HTTP_BAD_GATEWAY, []];
        yield 'server error that still names a feed' => [Response::HTTP_BAD_GATEWAY, ['feed' => []]];
        yield 'no feed' => [Response::HTTP_OK, ['cursor' => 'x']];
    }

    /** @param array<string, mixed> $body */
    #[DataProvider('unusableBodies')]
    public function testAnUnusableAnswerReadsAsAnUnreachableNetwork(int $status, array $body): void
    {
        $this->expectException(SocialNetworkUnreachableException::class);

        $this->pageOf(self::answer($body, $status));
    }

    private function pageOf(FetchOutcomeModel $outcome): TimelinePageModel
    {
        return $this->source(new MockHttpClient())->pageOf($this->socialFeed(SocialFeedKind::Home, ''), $outcome);
    }

    /**
     * @param array<mixed> $body
     * @param list<string> $retryAfter
     */
    private static function answer(array $body, int $status = Response::HTTP_OK, array $retryAfter = []): FetchOutcomeModel
    {
        $headers = [] === $retryAfter ? [] : ['retry-after' => $retryAfter];

        return FetchOutcomeModel::answered(new ApiResponseModel($status, json_encode($body, \JSON_THROW_ON_ERROR), $headers));
    }
```

The `answer()` signature and its `return` line are over 120 columns. Break the parameters and the
`new ApiResponseModel(...)` arguments onto separate lines.

In `tests/Service/Social/TimelineSource/MastodonTimelineSourceTest.php`, add these imports:
- `use App\Service\Fetch\Exception\FeedUnreachableException;`
- `use App\Service\Fetch\Model\ApiResponseModel;`
- `use App\Service\Fetch\Model\FetchOutcomeModel;`
- `use App\Service\Social\Exception\SocialNetworkThrottledException;`
- `use App\Service\Social\Model\TimelinePageModel;`

Then change `statuses()` to `return new MockResponse(self::statusesBody($newestId));`, with the JSON moved into a
`private static function statusesBody(string $newestId): string`. Add:

```php
    public function testARequestCarriesTheTokenToTheAccountsInstance(): void
    {
        $request = $this->source(new RoutedHttpClient([]))->request(self::socialFeed(SocialFeedKind::Home, ''));

        self::assertSame(self::HOME, $request->method . ' ' . $request->url);
        self::assertSame('Bearer ' . SocialAccounts::ACCESS_TOKEN, $request->headers['Authorization'] ?? null);
    }

    public function testAListRequestAsksOnlyForWhatCameAfterTheNewestSeenStatus(): void
    {
        $list = self::socialFeed(SocialFeedKind::UserList, '42');
        $list->recordNewestSeen('100');

        $request = $this->source(new RoutedHttpClient([]))->request($list);

        self::assertSame('https://mastodon.example/api/v1/timelines/list/42?limit=40&min_id=100', $request->url);
    }

    #[DataProvider('foreignListIds')]
    public function testAForeignListIdIsNeverTurnedIntoARequest(string $listId): void
    {
        $this->expectException(SocialNetworkUnreachableException::class);

        $this->source(new RoutedHttpClient([]))->request(self::socialFeed(SocialFeedKind::UserList, $listId));
    }

    public function testAnAnswerMapsToThePageAndNamesItsNewestStatus(): void
    {
        $page = $this->pageOf(self::answered(self::statusesBody('109')));

        self::assertCount(1, $page->parsed->entries);
        self::assertSame('109', $page->newestId);
    }

    /** @return iterable<string, array{int}> */
    public static function refusals(): iterable
    {
        yield 'unauthorized' => [Response::HTTP_UNAUTHORIZED];
        yield 'forbidden' => [Response::HTTP_FORBIDDEN];
    }

    #[DataProvider('refusals')]
    public function testARefusedTokenInTheAnswerIsARefusedCredential(int $status): void
    {
        $this->expectException(SocialCredentialsRejectedException::class);

        $this->pageOf(self::answered('{}', $status));
    }

    public function testATooManyRequestsAnswerIsAThrottleCarryingItsDelay(): void
    {
        try {
            $this->pageOf(self::answered('{}', Response::HTTP_TOO_MANY_REQUESTS, ['retry-after' => ['60']]));
            self::fail('A 429 must not read as a page.');
        } catch (SocialNetworkThrottledException $exception) {
            self::assertSame(60, $exception->retryAfterSeconds);
        }
    }

    public function testNoAnswerIsAnUnreachableInstance(): void
    {
        $this->expectException(SocialNetworkUnreachableException::class);
        $this->expectExceptionMessage('The Mastodon instance could not be reached.');

        $this->pageOf(FetchOutcomeModel::failed(new FeedUnreachableException('https://mastodon.example: timed out')));
    }

    /** @return iterable<string, array{string}> */
    public static function bodiesThatAreNoList(): iterable
    {
        yield 'an object' => ['{"error":"odd"}'];
        yield 'an HTML page' => ['<!doctype html><html lang="en"><body>maintenance</body></html>'];
    }

    #[DataProvider('bodiesThatAreNoList')]
    public function testAnAnswerThatIsNoListReadsAsAnUnreachableNetwork(string $body): void
    {
        $this->expectException(SocialNetworkUnreachableException::class);

        $this->pageOf(self::answered($body));
    }

    private function pageOf(FetchOutcomeModel $outcome): TimelinePageModel
    {
        return $this->source(new RoutedHttpClient([]))->pageOf(self::socialFeed(SocialFeedKind::Home, ''), $outcome);
    }

    /** @param array<array<string>> $headers */
    private static function answered(string $body, int $status = Response::HTTP_OK, array $headers = []): FetchOutcomeModel
    {
        return FetchOutcomeModel::answered(new ApiResponseModel($status, $body, $headers));
    }
```

Break the over-long `answered()` signature onto separate lines.

In `tests/Service/Social/TimelineReader/NetworkTimelineReaderTest.php`, the anonymous source must satisfy the
grown interface. Add these imports:
- `use App\Entity\SocialAccount;`
- `use App\Service\Fetch\Model\ApiRequestModel;`
- `use App\Service\Fetch\Model\FetchOutcomeModel;`

Then add these methods to the anonymous class:

```php
            public function request(SocialFeed $socialFeed): ApiRequestModel
            {
                throw new \LogicException('Not asked by the blocking reader.');
            }

            public function pageOf(SocialFeed $socialFeed, FetchOutcomeModel $outcome): TimelinePageModel
            {
                throw new \LogicException('Not asked by the blocking reader.');
            }

            public function dropSession(SocialAccount $account): void
            {
            }
```

- [ ] **Step 2: Run them to verify they fail**

Run: `php bin/phpunit tests/Service/Social/TimelineSource tests/Service/Social/TimelineReader`
Expected: FAIL with `Call to undefined method …BlueskyTimelineSource::request()` and
`Class "App\Service\Social\Exception\SocialSessionStaleException" not found`.

- [ ] **Step 3: Add the stale exception and the session helpers**

`src/Service/Social/Exception/SocialSessionStaleException.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Social\Exception;

/** The network refused a session as expired or invalid: a fresh session may still be accepted. */
final class SocialSessionStaleException extends \RuntimeException
{
}
```

In `src/Service/Social/BlueskySessions.php`:
- add `public const string SESSION_REFUSED = 'Bluesky refused the session of this account.';`, and use it in
  `accepted()`;
- rename the private static `isStale()` to the public static `isStaleSession()`. It has three call sites in the class:
  `accepted()`, `actionAccepted()` and `sendRetryingStale()`;
- add the two methods below after `fresh()`.

```php
    /**
     * A GET to the account's own service URL carrying its session's token, opening a session when none is cached.
     *
     * @throws SocialCredentialsRejectedException
     * @throws SocialNetworkThrottledException
     * @throws SocialNetworkUnreachableException
     */
    public function authorizedGet(SocialAccount $account, string $path): ApiRequestModel
    {
        return self::authorized(ApiRequestModel::get($account->getServiceUrl() . $path), $this->current($account));
    }

    public function forget(SocialAccount $account): void
    {
        $this->cache->forget($account);
    }
```

In `src/Service/Social/BlueskyApiClient.php`, add `use App\Service\Fetch\Model\FetchOutcomeModel;`, then replace
`answer()` and add `answerOf()` and `unreachable()`:

```php
    /** @throws SocialNetworkUnreachableException only when no answer arrived; any status is returned */
    public function answer(ApiRequestModel $request): ApiResponseModel
    {
        try {
            return $this->api->send($request);
        } catch (FetchException $exception) {
            throw self::unreachable($exception);
        }
    }

    /** @throws SocialNetworkUnreachableException only when no answer arrived; any status is returned */
    public static function answerOf(FetchOutcomeModel $outcome): ApiResponseModel
    {
        try {
            return $outcome->answerOrThrow();
        } catch (FetchException $exception) {
            throw self::unreachable($exception);
        }
    }

    private static function unreachable(FetchException $exception): SocialNetworkUnreachableException
    {
        return new SocialNetworkUnreachableException('Bluesky could not be reached.', previous: $exception);
    }
```

Make the same change in `src/Service/Social/MastodonApiClient.php`, with the message
`'The Mastodon instance could not be reached.'`.

In `src/Service/Social/MastodonAccountApi.php`, add `authorizedGet()` and route `answer()` through a new private
`authorized()`. pdepend cannot parse a `new` chain, so assign the session to a variable first:

```php
    /** @throws SocialCredentialsRejectedException when the stored token no longer opens */
    public function authorizedGet(SocialAccount $account, string $path): ApiRequestModel
    {
        return $this->authorized($account, ApiRequestModel::get($account->getServiceUrl() . $path));
    }
```

```php
    private function answer(SocialAccount $account, ApiRequestModel $request): ApiResponseModel
    {
        return $this->mastodon->answer($this->authorized($account, $request));
    }

    private function authorized(SocialAccount $account, ApiRequestModel $request): ApiRequestModel
    {
        $session = new MastodonSessionModel($account->getServiceUrl(), $this->storedCredential->open($account));

        return $session->authorized($request);
    }
```

- [ ] **Step 4: Grow the source interface and its two implementations**

Replace `src/Service/Social/TimelineSource/TimelineSourceInterface.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Social\TimelineSource;

use App\Entity\SocialAccount;
use App\Entity\SocialFeed;
use App\Service\Fetch\Model\ApiRequestModel;
use App\Service\Fetch\Model\FetchOutcomeModel;
use App\Service\Social\Exception\SocialCredentialsRejectedException;
use App\Service\Social\Exception\SocialNetworkThrottledException;
use App\Service\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\Exception\SocialSessionStaleException;
use App\Service\Social\Model\TimelinePageModel;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/** One network's private feeds: the request a poll sends and the page its answer maps to, keyed by SocialNetwork. */
#[AutoconfigureTag('app.social_timeline_source')]
interface TimelineSourceInterface
{
    public static function network(): string;

    /**
     * @throws SocialCredentialsRejectedException
     * @throws SocialNetworkThrottledException
     * @throws SocialNetworkUnreachableException
     */
    public function page(SocialFeed $socialFeed): TimelinePageModel;

    /**
     * @throws SocialCredentialsRejectedException
     * @throws SocialNetworkThrottledException
     * @throws SocialNetworkUnreachableException
     */
    public function request(SocialFeed $socialFeed): ApiRequestModel;

    /**
     * @throws SocialSessionStaleException when only a fresh session can read it
     * @throws SocialCredentialsRejectedException
     * @throws SocialNetworkThrottledException
     * @throws SocialNetworkUnreachableException
     */
    public function pageOf(SocialFeed $socialFeed, FetchOutcomeModel $outcome): TimelinePageModel;

    /** After a stale answer, so the account's next request opens a fresh session; a network without one keeps none. */
    public function dropSession(SocialAccount $account): void;
}
```

In `BlueskyTimelineSource`, add these imports:
- `use App\Entity\SocialAccount;`
- `use App\Service\Fetch\Model\ApiRequestModel;`
- `use App\Service\Fetch\Model\FetchOutcomeModel;`
- `use App\Service\Social\BlueskyApiClient;`
- `use App\Service\Social\Exception\SocialSessionStaleException;`

Then add, after `page()`:

```php
    public function request(SocialFeed $socialFeed): ApiRequestModel
    {
        return $this->sessions->authorizedGet($socialFeed->getSocialAccount(), self::pathOf($socialFeed));
    }

    public function pageOf(SocialFeed $socialFeed, FetchOutcomeModel $outcome): TimelinePageModel
    {
        $answer = BlueskyApiClient::answerOf($outcome);
        if (BlueskySessions::isStaleSession($answer)) {
            throw new SocialSessionStaleException(BlueskySessions::SESSION_REFUSED);
        }

        return BlueskyTimelinePage::of(self::itemsOf($answer), $socialFeed->getSocialAccount()->getIdentity());
    }

    public function dropSession(SocialAccount $account): void
    {
        $this->sessions->forget($account);
    }
```

In `MastodonTimelineSource`, add these imports:
- `use App\Entity\SocialAccount;`
- `use App\Service\Fetch\Model\ApiRequestModel;`
- `use App\Service\Fetch\Model\ApiResponseModel;`
- `use App\Service\Fetch\Model\FetchOutcomeModel;`
- `use App\Service\Social\MastodonApiClient;`

Then replace `page()` and add the three methods and a shared mapper:

```php
    public function page(SocialFeed $socialFeed): TimelinePageModel
    {
        return self::pageFrom($this->mastodon->get($socialFeed->getSocialAccount(), self::pathOf($socialFeed)));
    }

    public function request(SocialFeed $socialFeed): ApiRequestModel
    {
        return $this->mastodon->authorizedGet($socialFeed->getSocialAccount(), self::pathOf($socialFeed));
    }

    public function pageOf(SocialFeed $socialFeed, FetchOutcomeModel $outcome): TimelinePageModel
    {
        return self::pageFrom(MastodonApiClient::accepted(MastodonApiClient::answerOf($outcome)));
    }

    public function dropSession(SocialAccount $account): void
    {
    }

    private static function pageFrom(ApiResponseModel $accepted): TimelinePageModel
    {
        $statuses = JsonNodeModel::listOf(json_decode($accepted->body, true))
            ?? throw new SocialNetworkUnreachableException('The Mastodon instance answered without a timeline.');

        return MastodonTimelinePage::of($statuses);
    }
```

- [ ] **Step 5: Run the social suites**

Run: `php bin/phpunit tests/Service/Social tests/Service/Refresh`
Expected: PASS, old blocking tests included.

- [ ] **Step 6: Run the gates**

Run: `composer check && composer md`
Expected: clean. No Fetch file imports Social (see Global Constraints).

- [ ] **Step 7: Commit**

From the repo root:

```bash
git add backend/src/Service/Social backend/tests/Service/Social
git commit -m "feat(#1607): split each timeline source into a request and a page mapper"
```

---

### Task 4: The concurrent timeline fetcher

**Files:**
- Create: `backend/src/Service/Social/Model/TimelineOutcome.php`
- Create: `backend/src/Service/Social/Model/TimelineOutcomeModel.php`
- Create: `backend/src/Service/Social/TimelineSources.php`
- Create: `backend/src/Service/Social/Pass/TimelineAccounts.php`
- Create: `backend/src/Service/Social/Pass/TimelineRound.php`
- Create: `backend/src/Service/Social/TimelineFetcher/TimelineFetcherInterface.php`
- Create: `backend/src/Service/Social/TimelineFetcher/ConcurrentTimelineFetcher.php`
- Move: `backend/src/Service/Refresh/Exception/PrivateFeedOrphanedException.php` →
  `backend/src/Service/Social/Exception/PrivateFeedOrphanedException.php`
- Modify: `backend/src/Service/Refresh/SocialFeedRefresher.php` (the import only)
- Modify: `backend/config/services.yaml`
- Modify: `backend/tests/Support/SocialApiWiring.php`
- Create: `backend/tests/Service/Social/TimelineFetcher/ConcurrentTimelineFetcherTest.php`
- Create: `backend/tests/Service/Social/Model/TimelineOutcomeModelTest.php`

**Interfaces:**
- Consumes:
  - Task 2: `ConcurrentRequestEngine::sendAll(…, ?DispatchGateInterface)`, `RequestTicketModel::api()`,
    `FetchOutcomeModel::isWithheld()`, `FetchWiring::requestEngine()`, `FetchWiring::egressProxy()`.
  - Task 3: `TimelineSourceInterface::request/pageOf/dropSession`, `SocialSessionStaleException`.
- Produces:
  - `TimelineFetcherInterface::fetchAll(array<int, SocialFeed> $socialFeedsByFeedId, iterable<int, Feed> $feeds): iterable<int, TimelineOutcomeModel>`.
  - `TimelineOutcome` with the cases `Fetched`, `Throttled`, `Failed`, `AccountThrottled` and `AccountPaused`.
  - `TimelineOutcomeModel` with the factories `fetched(TimelinePageModel)`, `throttled(?int)`,
    `failed(\RuntimeException)`, `accountThrottled()` and `accountPaused()`. It has public `TimelineOutcome $outcome`
    and `?int $retryAfterSeconds`, plus `page(): TimelinePageModel` and `failure(): \RuntimeException`.
  - `TimelineSources::of(SocialAccount): TimelineSourceInterface`.
  - `App\Service\Social\Exception\PrivateFeedOrphanedException`.
  - Container parameter `social_account_concurrency: 3`.
  - `SocialApiWiring::timelineFetcher(HttpClientInterface $http, ArrayAdapter $cache, int $accountConcurrency = 3): ConcurrentTimelineFetcher`
    and `SocialApiWiring::timelineSources(HttpClientInterface $http, ArrayAdapter $cache): TimelineSources`.

- [ ] **Step 1: Write the failing fetcher tests**

Create `tests/Service/Social/Model/TimelineOutcomeModelTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\Model;

use App\Service\Completion\Model\KnownSharesModel;
use App\Service\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\Model\TimelineOutcome;
use App\Service\Social\Model\TimelineOutcomeModel;
use App\Service\Social\Model\TimelinePageModel;
use PHPUnit\Framework\TestCase;

final class TimelineOutcomeModelTest extends TestCase
{
    public function testAFetchedOutcomeCarriesItsPage(): void
    {
        $page = TimelinePageModel::of([], KnownSharesModel::none(), '7');

        $outcome = TimelineOutcomeModel::fetched($page);

        self::assertSame(TimelineOutcome::Fetched, $outcome->outcome);
        self::assertSame($page, $outcome->page());
    }

    public function testAFailedOutcomeCarriesItsFailure(): void
    {
        $failure = new SocialNetworkUnreachableException('The Mastodon instance answered 502.');

        self::assertSame($failure, TimelineOutcomeModel::failed($failure)->failure());
    }

    public function testAThrottledOutcomeCarriesItsDelay(): void
    {
        self::assertSame(120, TimelineOutcomeModel::throttled(120)->retryAfterSeconds);
    }

    public function testAHeldBackOutcomeCarriesNoPage(): void
    {
        $this->expectException(\LogicException::class);

        TimelineOutcomeModel::accountThrottled()->page();
    }

    public function testAFetchedOutcomeCarriesNoFailure(): void
    {
        $this->expectException(\LogicException::class);

        TimelineOutcomeModel::fetched(TimelinePageModel::of([], KnownSharesModel::none(), null))->failure();
    }
}
```

Create `tests/Service/Social/TimelineFetcher/ConcurrentTimelineFetcherTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\TimelineFetcher;

use App\Entity\Feed;
use App\Entity\SocialAccount;
use App\Entity\SocialFeed;
use App\Enum\SocialAccountStatus;
use App\Enum\SocialFeedKind;
use App\Service\Social\Exception\PrivateFeedOrphanedException;
use App\Service\Social\Exception\SocialCredentialsRejectedException;
use App\Service\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\Model\BlueskySessionModel;
use App\Service\Social\Model\TimelineOutcome;
use App\Service\Social\Model\TimelineOutcomeModel;
use App\Service\Social\TimelineFetcher\ConcurrentTimelineFetcher;
use App\Tests\Support\AssignsEntityIds;
use App\Tests\Support\SocialAccounts;
use App\Tests\Support\SocialApiWiring;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Response;

final class ConcurrentTimelineFetcherTest extends TestCase
{
    use AssignsEntityIds;

    private const string MASTODON = 'https://mastodon.example';
    private const string OTHER = 'https://other.example';
    private const string PDS = 'https://pds.example.test';
    private const string SESSION = 'POST https://pds.example.test/xrpc/com.atproto.server.createSession';
    private const string MASTODON_HOME = 'GET https://mastodon.example/api/v1/timelines/home?limit=40';

    private ArrayAdapter $cache;

    /** @var list<string> "METHOD url" of every request, in the order they went out */
    private array $requests = [];

    private int $nextFeedId = 1;

    protected function setUp(): void
    {
        $this->cache = new ArrayAdapter();
    }

    public function testEveryTimelineIsOnTheWireBeforeTheFirstAnswerIsRead(): void
    {
        $fetcher = $this->fetcher(static fn (string $method, string $url): MockResponse => str_starts_with($url, self::PDS)
            ? self::blueskyTimeline()
            : self::statuses());
        $socialFeeds = [
            $this->feedOf(self::mastodonAccount(self::MASTODON, 1)),
            $this->feedOf(self::mastodonAccount(self::OTHER, 2)),
            $this->feedOf($this->blueskyAccount('jwt-1')),
        ];

        $onTheWireAtFirstAnswer = null;
        $outcomes = [];
        foreach ($fetcher->fetchAll(self::byFeedId($socialFeeds), self::feedsOf($socialFeeds)) as $feedId => $outcome) {
            $onTheWireAtFirstAnswer ??= \count($this->requests);
            $outcomes[$feedId] = $outcome;
        }

        self::assertSame(3, $onTheWireAtFirstAnswer);
        self::assertCount(3, $outcomes);
        foreach ($outcomes as $outcome) {
            self::assertSame(TimelineOutcome::Fetched, $outcome->outcome);
        }
    }

    public function testNoAccountHasMoreThanItsCapInFlightWhileAnotherRunsAlongside(): void
    {
        /** @var array<string, int> $inFlight */
        $inFlight = [];
        /** @var array<string, int> $peak */
        $peak = [];
        $fetcher = $this->fetcher(static function (string $method, string $url) use (&$inFlight, &$peak): MockResponse {
            $host = (string) parse_url($url, \PHP_URL_HOST);
            $inFlight[$host] = ($inFlight[$host] ?? 0) + 1;
            $peak[$host] = max($peak[$host] ?? 0, $inFlight[$host]);
            $body = (static function () use (&$inFlight, $host): \Generator {
                yield self::statusesBody();
                --$inFlight[$host];
            })();

            return new MockResponse($body);
        });
        $busy = self::mastodonAccount(self::MASTODON, 1);
        $other = self::mastodonAccount(self::OTHER, 2);
        $socialFeeds = [$this->feedOf($busy)];
        foreach (['1', '2', '3', '4'] as $listId) {
            $socialFeeds[] = $this->feedOf($busy, SocialFeedKind::UserList, $listId);
        }
        $socialFeeds[] = $this->feedOf($other);
        $socialFeeds[] = $this->feedOf($other, SocialFeedKind::UserList, '9');

        self::assertCount(7, self::read($fetcher, $socialFeeds));
        self::assertSame(3, $peak['mastodon.example']);
        self::assertSame(2, $peak['other.example'], 'the other account runs alongside, not behind');
    }

    public function testAThrottledAccountKeepsItsUnsentFeedsBackAndLeavesOthersAlone(): void
    {
        $fetcher = $this->fetcher(static fn (string $method, string $url): MockResponse => str_starts_with($url, self::MASTODON)
            ? self::json(['error' => 'Too many requests'], Response::HTTP_TOO_MANY_REQUESTS, ['Retry-After' => '120'])
            : self::statuses(), accountConcurrency: 1);
        $throttled = self::mastodonAccount(self::MASTODON, 1);
        $home = $this->feedOf($throttled);
        $friends = $this->feedOf($throttled, SocialFeedKind::UserList, '42');
        $family = $this->feedOf($throttled, SocialFeedKind::UserList, '43');
        $other = $this->feedOf(self::mastodonAccount(self::OTHER, 2));

        $outcomes = self::read($fetcher, [$home, $friends, $family, $other]);

        self::assertSame(TimelineOutcome::Throttled, $outcomes[self::idOf($home)]->outcome);
        self::assertSame(120, $outcomes[self::idOf($home)]->retryAfterSeconds);
        self::assertSame(TimelineOutcome::AccountThrottled, $outcomes[self::idOf($friends)]->outcome);
        self::assertSame(TimelineOutcome::AccountThrottled, $outcomes[self::idOf($family)]->outcome);
        self::assertSame(TimelineOutcome::Fetched, $outcomes[self::idOf($other)]->outcome);
        self::assertSame([self::MASTODON_HOME], $this->requestsTo(self::MASTODON));
    }

    public function testARefusedTokenPausesOnlyItsAccount(): void
    {
        $fetcher = $this->fetcher(static fn (string $method, string $url): MockResponse => str_starts_with($url, self::MASTODON)
            ? self::json(['error' => 'The access token is invalid'], Response::HTTP_UNAUTHORIZED)
            : self::statuses(), accountConcurrency: 1);
        $refused = self::mastodonAccount(self::MASTODON, 1);
        $other = self::mastodonAccount(self::OTHER, 2);
        $home = $this->feedOf($refused);
        $friends = $this->feedOf($refused, SocialFeedKind::UserList, '42');
        $otherHome = $this->feedOf($other);

        $outcomes = self::read($fetcher, [$home, $friends, $otherHome]);

        $failure = $outcomes[self::idOf($home)]->failure();
        self::assertInstanceOf(SocialCredentialsRejectedException::class, $failure);
        self::assertSame('The Mastodon instance refused this access token.', $failure->getMessage());
        self::assertSame(TimelineOutcome::AccountPaused, $outcomes[self::idOf($friends)]->outcome);
        self::assertSame(TimelineOutcome::Fetched, $outcomes[self::idOf($otherHome)]->outcome);
        self::assertSame(SocialAccountStatus::NeedsReconnect, $refused->getStatus());
        self::assertSame(SocialAccountStatus::Connected, $other->getStatus());
        self::assertSame([self::MASTODON_HOME], $this->requestsTo(self::MASTODON));
    }

    public function testAStaleBlueskySessionIsRenewedOnceAndItsFeedsAskedAgain(): void
    {
        $fetcher = $this->fetcher(static function (string $method, string $url, array $headers): MockResponse {
            if (self::SESSION === $method . ' ' . $url) {
                return self::json(['did' => 'did:plc:alice', 'handle' => 'alice.bsky.social', 'accessJwt' => 'jwt-2']);
            }
            if (!str_starts_with($url, self::PDS)) {
                return self::statuses();
            }

            return \in_array('Authorization: Bearer stale-jwt', $headers, true)
                ? self::json(['error' => 'ExpiredToken', 'message' => 'Token has expired'], Response::HTTP_BAD_REQUEST)
                : self::blueskyTimeline();
        });
        $bluesky = $this->blueskyAccount('stale-jwt');
        $home = $this->feedOf($bluesky);
        $list = $this->feedOf($bluesky, SocialFeedKind::UserList, 'at://did:plc:bob/app.bsky.graph.list/3l1');
        $other = $this->feedOf(self::mastodonAccount(self::MASTODON, 1));

        $outcomes = self::read($fetcher, [$home, $list, $other]);

        foreach ([$home, $list, $other] as $socialFeed) {
            self::assertSame(TimelineOutcome::Fetched, $outcomes[self::idOf($socialFeed)]->outcome);
        }
        self::assertSame([self::SESSION], array_values(array_filter(
            $this->requests,
            static fn (string $request): bool => str_starts_with($request, 'POST'),
        )));
        self::assertCount(4, $this->requestsTo('GET ' . self::PDS));
        self::assertSame(SocialAccountStatus::Connected, $bluesky->getStatus());
    }

    public function testAStaleAnswerToARenewedSessionRefusesTheAccount(): void
    {
        $fetcher = $this->fetcher(static function (string $method, string $url): MockResponse {
            if (self::SESSION === $method . ' ' . $url) {
                return self::json(['did' => 'did:plc:alice', 'handle' => 'alice.bsky.social', 'accessJwt' => 'jwt-2']);
            }

            return str_starts_with($url, self::PDS)
                ? self::json(['error' => 'ExpiredToken'], Response::HTTP_BAD_REQUEST)
                : self::statuses();
        });
        $bluesky = $this->blueskyAccount('stale-jwt');
        $home = $this->feedOf($bluesky);
        $list = $this->feedOf($bluesky, SocialFeedKind::UserList, 'at://did:plc:bob/app.bsky.graph.list/3l1');
        $other = $this->feedOf(self::mastodonAccount(self::MASTODON, 1));

        $outcomes = self::read($fetcher, [$home, $list, $other]);

        foreach ([$home, $list] as $socialFeed) {
            $failure = $outcomes[self::idOf($socialFeed)]->failure();
            self::assertInstanceOf(SocialCredentialsRejectedException::class, $failure);
            self::assertSame('Bluesky refused the session of this account.', $failure->getMessage());
        }
        self::assertSame(TimelineOutcome::Fetched, $outcomes[self::idOf($other)]->outcome);
        self::assertSame(SocialAccountStatus::NeedsReconnect, $bluesky->getStatus());
        self::assertCount(1, $this->requestsTo(self::SESSION));
    }

    public function testAThrottledSessionOpeningHoldsTheAccountsOtherFeeds(): void
    {
        $fetcher = $this->fetcher(static fn (string $method, string $url): MockResponse => self::SESSION === $method . ' ' . $url
            ? self::json(['error' => 'RateLimitExceeded'], Response::HTTP_TOO_MANY_REQUESTS, ['Retry-After' => '60'])
            : self::statuses());
        $bluesky = $this->blueskyAccount(null);
        $home = $this->feedOf($bluesky);
        $list = $this->feedOf($bluesky, SocialFeedKind::UserList, 'at://did:plc:bob/app.bsky.graph.list/3l1');

        $outcomes = self::read($fetcher, [$home, $list]);

        self::assertSame(TimelineOutcome::Throttled, $outcomes[self::idOf($home)]->outcome);
        self::assertSame(60, $outcomes[self::idOf($home)]->retryAfterSeconds);
        self::assertSame(TimelineOutcome::AccountThrottled, $outcomes[self::idOf($list)]->outcome);
        self::assertSame([self::SESSION], $this->requests);
    }

    public function testAnAccountThatNeedsReconnectingIsNotAsked(): void
    {
        $paused = self::mastodonAccount(self::MASTODON, 1);
        $paused->markNeedsReconnect();
        $home = $this->feedOf($paused);

        $outcomes = self::read($this->fetcher(static fn (): MockResponse => self::statuses()), [$home]);

        self::assertSame(TimelineOutcome::AccountPaused, $outcomes[self::idOf($home)]->outcome);
        self::assertSame([], $this->requests);
    }

    public function testAFeedWithoutItsSocialFeedFailsWithoutAsking(): void
    {
        $orphan = self::withId(new Feed('social:mastodon:999:home'), 99);
        $outcomes = [];

        foreach ($this->fetcher(static fn (): MockResponse => self::statuses())->fetchAll([], [99 => $orphan]) as $feedId => $outcome) {
            $outcomes[$feedId] = $outcome;
        }

        self::assertInstanceOf(PrivateFeedOrphanedException::class, $outcomes[99]->failure());
        self::assertSame([], $this->requests);
    }

    public function testAFetchedPageRecordsTheFeedsNewestStatus(): void
    {
        $home = $this->feedOf(self::mastodonAccount(self::MASTODON, 1));

        self::read($this->fetcher(static fn (): MockResponse => self::statuses()), [$home]);

        self::assertSame('109', $home->getNewestSeenId());
    }

    public function testNoAnswerIsAnUnreachableNetwork(): void
    {
        $home = $this->feedOf(self::mastodonAccount(self::MASTODON, 1));
        $fetcher = $this->fetcher(static fn (): MockResponse => new MockResponse('', ['error' => 'Connection reset by peer']));

        $failure = self::read($fetcher, [$home])[self::idOf($home)]->failure();

        self::assertInstanceOf(SocialNetworkUnreachableException::class, $failure);
        self::assertSame('The Mastodon instance could not be reached.', $failure->getMessage());
    }

    public function testAbandoningTheReadSendsNothingMore(): void
    {
        $account = self::mastodonAccount(self::MASTODON, 1);
        $socialFeeds = [$this->feedOf($account)];
        foreach (['1', '2', '3'] as $listId) {
            $socialFeeds[] = $this->feedOf($account, SocialFeedKind::UserList, $listId);
        }
        $fetcher = $this->fetcher(static fn (): MockResponse => self::statuses(), accountConcurrency: 1);

        foreach ($fetcher->fetchAll(self::byFeedId($socialFeeds), self::feedsOf($socialFeeds)) as $outcome) {
            self::assertSame(TimelineOutcome::Fetched, $outcome->outcome);
            break;
        }

        self::assertCount(1, $this->requests);
    }

    public function testRejectsAnAccountConcurrencyBelowOne(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Per-account concurrency must be at least 1, got 0.');

        $this->fetcher(static fn (): MockResponse => self::statuses(), accountConcurrency: 0);
    }

    /** @param \Closure(string, string, list<string>): MockResponse $answer by method, url and request headers */
    private function fetcher(\Closure $answer, int $accountConcurrency = 3): ConcurrentTimelineFetcher
    {
        $client = new MockHttpClient(function (string $method, string $url, array $options) use ($answer): MockResponse {
            $this->requests[] = $method . ' ' . $url;
            $headers = [];
            foreach (\is_array($options['headers'] ?? null) ? $options['headers'] : [] as $header) {
                $headers[] = \is_string($header) ? $header : '';
            }

            return $answer($method, $url, $headers);
        });

        return SocialApiWiring::timelineFetcher($client, $this->cache, $accountConcurrency);
    }

    /** @return list<string> */
    private function requestsTo(string $prefix): array
    {
        return array_values(array_filter(
            $this->requests,
            static fn (string $request): bool => str_contains($request, $prefix),
        ));
    }

    private function feedOf(SocialAccount $account, SocialFeedKind $kind = SocialFeedKind::Home, string $remoteId = ''): SocialFeed
    {
        $feedId = $this->nextFeedId++;

        return new SocialFeed($account, self::withId(new Feed(sprintf('social:test:%d', $feedId)), $feedId), $kind, $remoteId);
    }

    private function blueskyAccount(?string $cachedJwt): SocialAccount
    {
        $account = self::withId(SocialAccounts::bluesky(), 3);
        if (null !== $cachedJwt) {
            SocialApiWiring::blueskySessionCache($this->cache)
                ->remember($account, new BlueskySessionModel($cachedJwt, 'alice.bsky.social'));
        }

        return $account;
    }

    private static function mastodonAccount(string $instanceUrl, int $accountId): SocialAccount
    {
        return self::withId(SocialAccounts::mastodonOn($instanceUrl), $accountId);
    }

    private static function idOf(SocialFeed $socialFeed): int
    {
        return $socialFeed->getFeed()->requireId();
    }

    /**
     * @param list<SocialFeed> $socialFeeds
     *
     * @return array<int, TimelineOutcomeModel>
     */
    private static function read(ConcurrentTimelineFetcher $fetcher, array $socialFeeds): array
    {
        $outcomes = [];
        foreach ($fetcher->fetchAll(self::byFeedId($socialFeeds), self::feedsOf($socialFeeds)) as $feedId => $outcome) {
            $outcomes[$feedId] = $outcome;
        }

        return $outcomes;
    }

    /**
     * @param list<SocialFeed> $socialFeeds
     *
     * @return array<int, SocialFeed>
     */
    private static function byFeedId(array $socialFeeds): array
    {
        $byFeedId = [];
        foreach ($socialFeeds as $socialFeed) {
            $byFeedId[self::idOf($socialFeed)] = $socialFeed;
        }

        return $byFeedId;
    }

    /**
     * @param list<SocialFeed> $socialFeeds
     *
     * @return array<int, Feed>
     */
    private static function feedsOf(array $socialFeeds): array
    {
        return array_map(static fn (SocialFeed $socialFeed): Feed => $socialFeed->getFeed(), self::byFeedId($socialFeeds));
    }

    private static function statusesBody(): string
    {
        return json_encode([[
            'id' => '109',
            'uri' => 'https://mastodon.example/users/bob/statuses/109',
            'url' => 'https://mastodon.example/@bob/109',
            'created_at' => '2026-10-12T11:59:00Z',
            'content' => '<p>Hello</p>',
            'account' => ['acct' => 'bob', 'display_name' => 'Bob', 'url' => 'https://mastodon.example/@bob'],
        ]], \JSON_THROW_ON_ERROR);
    }

    private static function statuses(): MockResponse
    {
        return new MockResponse(self::statusesBody());
    }

    private static function blueskyTimeline(): MockResponse
    {
        return self::json(['feed' => [[
            'post' => [
                'uri' => 'at://did:plc:bob/app.bsky.feed.post/3abc',
                'author' => ['did' => 'did:plc:bob', 'handle' => 'bob.bsky.social'],
                'record' => ['text' => 'Hello', 'createdAt' => '2026-10-12T10:00:00Z'],
            ],
        ]]]);
    }

    /**
     * @param array<mixed>          $body
     * @param array<string, string> $headers
     */
    private static function json(array $body, int $status = Response::HTTP_OK, array $headers = []): MockResponse
    {
        return new MockResponse(json_encode($body, \JSON_THROW_ON_ERROR), [
            'http_code' => $status,
            'response_headers' => $headers,
        ]);
    }
}
```

Several closure and helper lines above are over 120 columns: the `static fn (…) => … ? … : …` responders, the
`foreach (… fetchAll(...))` lines, `feedOf()`'s signature and return, and `feedsOf()`'s return. Before running phpcs:
- assign each responder closure to a `$respond` variable on its own lines;
- assign `$outcomes = $fetcher->fetchAll(...)` before each `foreach`;
- break the signatures onto one parameter per line.

The behaviour must stay as written.

- [ ] **Step 2: Run them to verify they fail**

Run: `php bin/phpunit --filter 'ConcurrentTimelineFetcherTest|TimelineOutcomeModelTest'`
Expected: FAIL with `Class "App\Service\Social\Model\TimelineOutcomeModel" not found` and
`Call to undefined method App\Tests\Support\SocialApiWiring::timelineFetcher()`.

- [ ] **Step 3: Create the outcome model, and move the orphan exception**

`src/Service/Social/Model/TimelineOutcome.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Social\Model;

enum TimelineOutcome
{
    case Fetched;
    case Throttled;
    case Failed;
    /** Not asked: the account was throttled earlier in this refresh. */
    case AccountThrottled;
    /** Not asked: the account needs reconnecting. */
    case AccountPaused;
}
```

`src/Service/Social/Model/TimelineOutcomeModel.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Social\Model;

/** What one private feed's read came to, for the refresh to store. */
final readonly class TimelineOutcomeModel
{
    private function __construct(
        public TimelineOutcome $outcome,
        private ?TimelinePageModel $page = null,
        private ?\RuntimeException $failure = null,
        public ?int $retryAfterSeconds = null,
    ) {
    }

    public static function fetched(TimelinePageModel $page): self
    {
        return new self(TimelineOutcome::Fetched, page: $page);
    }

    public static function throttled(?int $retryAfterSeconds): self
    {
        return new self(TimelineOutcome::Throttled, retryAfterSeconds: $retryAfterSeconds);
    }

    public static function failed(\RuntimeException $failure): self
    {
        return new self(TimelineOutcome::Failed, failure: $failure);
    }

    public static function accountThrottled(): self
    {
        return new self(TimelineOutcome::AccountThrottled);
    }

    public static function accountPaused(): self
    {
        return new self(TimelineOutcome::AccountPaused);
    }

    public function page(): TimelinePageModel
    {
        return $this->page ?? throw new \LogicException('Only a fetched outcome carries a page.');
    }

    public function failure(): \RuntimeException
    {
        return $this->failure ?? throw new \LogicException('Only a failed outcome carries its failure.');
    }
}
```

From the repo root:

```bash
git mv backend/src/Service/Refresh/Exception/PrivateFeedOrphanedException.php \
  backend/src/Service/Social/Exception/PrivateFeedOrphanedException.php
```

Then in the moved file, change the namespace to `App\Service\Social\Exception`. In
`src/Service/Refresh/SocialFeedRefresher.php`, change the import to
`use App\Service\Social\Exception\PrivateFeedOrphanedException;`. `src/Service/Refresh/Exception/` is then empty;
remove the directory.

- [ ] **Step 4: Create the source locator and the round's two per-call objects**

`src/Service/Social/TimelineSources.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Social;

use App\Entity\SocialAccount;
use App\Service\Social\TimelineSource\TimelineSourceInterface;
use Psr\Container\ContainerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireLocator;

final readonly class TimelineSources
{
    public function __construct(
        #[AutowireLocator('app.social_timeline_source', defaultIndexMethod: 'network')]
        private ContainerInterface $sources,
    ) {
    }

    public function of(SocialAccount $account): TimelineSourceInterface
    {
        $source = $this->sources->get($account->getNetwork()->value);
        \assert($source instanceof TimelineSourceInterface);

        return $source;
    }
}
```

`src/Service/Social/Pass/TimelineAccounts.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Social\Pass;

use App\Entity\SocialAccount;
use App\Enum\SocialAccountStatus;
use App\Service\Social\Exception\SocialCredentialsRejectedException;
use App\Service\Social\Exception\SocialNetworkThrottledException;
use App\Service\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\Exception\SocialSessionStaleException;
use App\Service\Social\Model\TimelineOutcomeModel;

/** One refresh's verdict per account: throttled, paused, or waiting for a fresh session. */
final class TimelineAccounts
{
    /** @var array<int, true> */
    private array $throttledIds = [];

    /** @var array<int, SocialAccount> */
    private array $awaitingSession = [];

    /** @var array<int, true> */
    private array $renewedIds = [];

    public function mayAsk(SocialAccount $account): bool
    {
        return null === $this->heldOutcomeOf($account) && !isset($this->awaitingSession[$account->requireId()]);
    }

    /** Why the account's feeds stay unasked this refresh, or null when nothing holds them. */
    public function heldOutcomeOf(SocialAccount $account): ?TimelineOutcomeModel
    {
        if (SocialAccountStatus::NeedsReconnect === $account->getStatus()) {
            return TimelineOutcomeModel::accountPaused();
        }

        return isset($this->throttledIds[$account->requireId()]) ? TimelineOutcomeModel::accountThrottled() : null;
    }

    /** Null while the account waits for its one renewal; a stale answer to a renewed session is a refusal. */
    public function settleStale(SocialAccount $account, SocialSessionStaleException $stale): ?TimelineOutcomeModel
    {
        $accountId = $account->requireId();
        if (isset($this->renewedIds[$accountId])) {
            return $this->failed(
                $account,
                new SocialCredentialsRejectedException($stale->getMessage(), previous: $stale),
            );
        }
        $this->awaitingSession[$accountId] = $account;

        return null;
    }

    /** @return list<SocialAccount> the accounts that waited, each counted as renewed from now on */
    public function renewSessions(): array
    {
        $accounts = array_values($this->awaitingSession);
        foreach ($accounts as $account) {
            $this->renewedIds[$account->requireId()] = true;
        }
        $this->awaitingSession = [];

        return $accounts;
    }

    public function failed(
        SocialAccount $account,
        SocialCredentialsRejectedException|SocialNetworkUnreachableException $failure,
    ): TimelineOutcomeModel {
        if ($failure instanceof SocialNetworkThrottledException) {
            $this->throttledIds[$account->requireId()] = true;

            return TimelineOutcomeModel::throttled($failure->retryAfterSeconds);
        }
        if ($failure instanceof SocialCredentialsRejectedException) {
            $account->markNeedsReconnect();
        }

        return TimelineOutcomeModel::failed($failure);
    }
}
```

`src/Service/Social/Pass/TimelineRound.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Social\Pass;

use App\Entity\Feed;
use App\Entity\SocialAccount;
use App\Entity\SocialFeed;
use App\Service\Fetch\DispatchGate\DispatchGateInterface;
use App\Service\Fetch\Model\FetchOutcomeModel;
use App\Service\Fetch\Model\RequestTicketModel;
use App\Service\Social\Exception\PrivateFeedOrphanedException;
use App\Service\Social\Exception\SocialCredentialsRejectedException;
use App\Service\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\Exception\SocialSessionStaleException;
use App\Service\Social\Model\TimelineOutcomeModel;
use App\Service\Social\TimelineSources;

/**
 * One refresh's private-feed reads: a ticket per feed, a gate that keeps a held account's waiting tickets off the
 * wire, and each answer settled into an outcome. A feed settled without a request waits in `unasked` until drained.
 */
final class TimelineRound implements DispatchGateInterface
{
    /** @var array<int, TimelineOutcomeModel> */
    private array $unasked = [];

    /** @var list<int> */
    private array $staleFeedIds = [];

    /** @param array<int, SocialFeed> $socialFeedsByFeedId */
    public function __construct(
        private readonly array $socialFeedsByFeedId,
        private readonly TimelineSources $sources,
        private readonly TimelineAccounts $accounts,
    ) {
    }

    /**
     * @param iterable<int, Feed> $feeds
     *
     * @return \Generator<int, RequestTicketModel>
     */
    public function tickets(iterable $feeds): \Generator
    {
        foreach ($feeds as $feedId => $feed) {
            yield from $this->ticketOf($feedId);
        }
    }

    public function hasStaleRetries(): bool
    {
        return [] !== $this->staleFeedIds;
    }

    /**
     * The feeds a stale session held back, sent again once each such account's session was dropped for a fresh one.
     *
     * @return \Generator<int, RequestTicketModel>
     */
    public function staleRetries(): \Generator
    {
        $feedIds = $this->staleFeedIds;
        $this->staleFeedIds = [];
        foreach ($this->accounts->renewSessions() as $account) {
            $this->sources->of($account)->dropSession($account);
        }
        foreach ($feedIds as $feedId) {
            yield from $this->ticketOf($feedId);
        }
    }

    public function admits(int|string $key): bool
    {
        return $this->accounts->mayAsk($this->socialFeedOf($key)->getSocialAccount());
    }

    /** The feed's outcome, or null while it waits for the stale retry wave. */
    public function settle(int $feedId, FetchOutcomeModel $outcome): ?TimelineOutcomeModel
    {
        $socialFeed = $this->socialFeedOf($feedId);
        $account = $socialFeed->getSocialAccount();
        if ($outcome->isWithheld()) {
            return $this->heldOrStale($feedId, $account);
        }

        try {
            $page = $this->sources->of($account)->pageOf($socialFeed, $outcome);
        } catch (SocialSessionStaleException $stale) {
            return $this->staleOutcome($feedId, $account, $stale);
        } catch (SocialCredentialsRejectedException | SocialNetworkUnreachableException $failure) {
            return $this->accounts->failed($account, $failure);
        }
        $socialFeed->recordNewestSeen($page->newestId);

        return TimelineOutcomeModel::fetched($page);
    }

    /** @return \Generator<int, TimelineOutcomeModel> */
    public function drainUnasked(): \Generator
    {
        $unasked = $this->unasked;
        $this->unasked = [];

        yield from $unasked;
    }

    /** @return \Generator<int, RequestTicketModel> */
    private function ticketOf(int $feedId): \Generator
    {
        $socialFeed = $this->socialFeedsByFeedId[$feedId] ?? null;
        if (null === $socialFeed) {
            $this->unasked[$feedId] = TimelineOutcomeModel::failed(new PrivateFeedOrphanedException());

            return;
        }
        $account = $socialFeed->getSocialAccount();
        if (!$this->accounts->mayAsk($account)) {
            $held = $this->heldOrStale($feedId, $account);
            if (null !== $held) {
                $this->unasked[$feedId] = $held;
            }

            return;
        }

        try {
            $request = $this->sources->of($account)->request($socialFeed);
        } catch (SocialCredentialsRejectedException | SocialNetworkUnreachableException $failure) {
            $this->unasked[$feedId] = $this->accounts->failed($account, $failure);

            return;
        }

        yield $feedId => RequestTicketModel::api($request, 'social-account-' . $account->requireId());
    }

    /** The account's held outcome, or null once the feed is queued for the stale retry wave. */
    private function heldOrStale(int $feedId, SocialAccount $account): ?TimelineOutcomeModel
    {
        $held = $this->accounts->heldOutcomeOf($account);
        if (null === $held) {
            $this->staleFeedIds[] = $feedId;
        }

        return $held;
    }

    private function staleOutcome(
        int $feedId,
        SocialAccount $account,
        SocialSessionStaleException $stale,
    ): ?TimelineOutcomeModel {
        $refused = $this->accounts->settleStale($account, $stale);
        if (null === $refused) {
            $this->staleFeedIds[] = $feedId;
        }

        return $refused;
    }

    private function socialFeedOf(int|string $feedId): SocialFeed
    {
        return $this->socialFeedsByFeedId[$feedId]
            ?? throw new \LogicException(sprintf('Feed %s was never ticketed in this round.', $feedId));
    }
}
```

- [ ] **Step 5: Create the fetcher seam and the concurrent fetcher**

`src/Service/Social/TimelineFetcher/TimelineFetcherInterface.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Social\TimelineFetcher;

use App\Entity\Feed;
use App\Entity\SocialFeed;
use App\Service\Social\Model\TimelineOutcomeModel;

interface TimelineFetcherInterface
{
    /**
     * Each private feed's outcome under its feed id, as its answer lands; abandoning the iterator cancels what is in
     * flight.
     *
     * @param array<int, SocialFeed> $socialFeedsByFeedId the refresh's private feeds, their accounts loaded
     * @param iterable<int, Feed>    $feeds               pulled one at a time, as a request may start
     *
     * @return iterable<int, TimelineOutcomeModel>
     */
    public function fetchAll(array $socialFeedsByFeedId, iterable $feeds): iterable;
}
```

`src/Service/Social/TimelineFetcher/ConcurrentTimelineFetcher.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Social\TimelineFetcher;

use App\Service\Fetch\ConcurrentRequestEngine;
use App\Service\Fetch\Model\RequestTicketModel;
use App\Service\Social\Model\TimelineOutcomeModel;
use App\Service\Social\Pass\TimelineAccounts;
use App\Service\Social\Pass\TimelineRound;
use App\Service\Social\TimelineSources;

/**
 * Reads a refresh's private timelines concurrently on the shared request engine, `accountConcurrency` at most per
 * account. A stale Bluesky session sends its feeds again in a second wave, once the account has a fresh session.
 */
final readonly class ConcurrentTimelineFetcher implements TimelineFetcherInterface
{
    public function __construct(
        private ConcurrentRequestEngine $engine,
        private TimelineSources $sources,
        private int $accountConcurrency,
    ) {
        if ($accountConcurrency < 1) {
            throw new \InvalidArgumentException(
                sprintf('Per-account concurrency must be at least 1, got %d.', $accountConcurrency),
            );
        }
    }

    public function fetchAll(array $socialFeedsByFeedId, iterable $feeds): \Generator
    {
        $round = new TimelineRound($socialFeedsByFeedId, $this->sources, new TimelineAccounts());

        yield from $this->wave($round, $round->tickets($feeds));
        if ($round->hasStaleRetries()) {
            yield from $this->wave($round, $round->staleRetries());
        }
    }

    /**
     * @param iterable<int, RequestTicketModel> $tickets
     *
     * @return \Generator<int, TimelineOutcomeModel>
     */
    private function wave(TimelineRound $round, iterable $tickets): \Generator
    {
        foreach ($this->engine->sendAll($tickets, $this->accountConcurrency, $round) as $feedId => $outcome) {
            yield from $round->drainUnasked();
            \assert(\is_int($feedId));
            $settled = $round->settle($feedId, $outcome);
            if (null !== $settled) {
                yield $feedId => $settled;
            }
        }

        yield from $round->drainUnasked();
    }
}
```

In `config/services.yaml`, add under `parameters:`, after `fetch_host_concurrency`:

```yaml
    # In-flight timeline reads per social account: Bluesky and Mastodon ration requests per account (#1607).
    social_account_concurrency: 3
```

and under `services:`, after the `ConcurrentFeedFetcher` block:

```yaml
    App\Service\Social\TimelineFetcher\ConcurrentTimelineFetcher:
        arguments:
            $accountConcurrency: '%social_account_concurrency%'
```

`TimelineFetcherInterface` has one implementation, so autowiring aliases it.

- [ ] **Step 6: Add the test wiring**

In `tests/Support/SocialApiWiring.php`:
- add `private const string OTHER_MASTODON_HOST = 'other.example';` and
  `private const string PUBLIC_ADDRESS = '93.184.216.34';`, and make `guardFor()` use `PUBLIC_ADDRESS`;
- add these imports:
  - `use App\Service\Social\TimelineFetcher\ConcurrentTimelineFetcher;`
  - `use App\Service\Social\TimelineSource\BlueskyTimelineSource;`
  - `use App\Service\Social\TimelineSource\MastodonTimelineSource;`
  - `use App\Service\Social\TimelineSources;`
  - `use Symfony\Component\DependencyInjection\ServiceLocator;`
- add:

```php
    /** The timeline fetcher over the real engine; every test host resolves to one public address. */
    public static function timelineFetcher(
        HttpClientInterface $http,
        ArrayAdapter $cache,
        int $accountConcurrency = 3,
    ): ConcurrentTimelineFetcher {
        $guard = new UrlGuard(new StaticDnsResolver([
            self::BLUESKY_HOST => [self::PUBLIC_ADDRESS],
            self::MASTODON_HOST => [self::PUBLIC_ADDRESS],
            self::OTHER_MASTODON_HOST => [self::PUBLIC_ADDRESS],
        ]), new IpValidator());

        return new ConcurrentTimelineFetcher(
            FetchWiring::requestEngine($http, $guard, FetchWiring::egressProxy(null), 8),
            self::timelineSources($http, $cache),
            $accountConcurrency,
        );
    }

    public static function timelineSources(HttpClientInterface $http, ArrayAdapter $cache): TimelineSources
    {
        return new TimelineSources(new ServiceLocator([
            BlueskyTimelineSource::network() => static fn (): BlueskyTimelineSource => new BlueskyTimelineSource(
                self::blueskySessions($http, $cache),
            ),
            MastodonTimelineSource::network() => static fn (): MastodonTimelineSource => new MastodonTimelineSource(
                self::mastodonAccountApi($http),
            ),
        ]));
    }
```

- [ ] **Step 7: Run the tests**

Run: `bin/console cache:warmup && php bin/phpunit tests/Service/Social tests/Service/Refresh`
Expected: PASS. `ConcurrentTimelineFetcherTest` (13) and `TimelineOutcomeModelTest` (5) are green.
`SocialFeedRefresherTest` still passes on the moved exception.

- [ ] **Step 8: Break-test the two guarantees the design names**

These are temporary edits. Undo each by hand afterwards; never `git checkout --`.
- In `TimelineRound::admits()`, return `true`. Run
  `php bin/phpunit --filter 'testAThrottledAccountKeepsItsUnsentFeedsBack|testARefusedTokenPausesOnlyItsAccount'`.
  Expected: both FAIL; the parked list feeds went out.
- In `ConcurrentTimelineFetcher::fetchAll()`, delete the stale wave's `yield from`. Run
  `php bin/phpunit --filter testAStaleBlueskySessionIsRenewedOnce`. Expected: FAIL.

Restore both edits, then rerun the class. Expected: PASS.

- [ ] **Step 9: Run the gates**

Run: `composer check && composer md && composer test:parallel`
Expected: clean.
- `ServiceRoleRule` accepts `Pass/TimelineRound` implementing Fetch's `DispatchGateInterface`.
- `ServiceModuleCycleRule` is green, because Social imports Fetch only.
- The Global Constraints module-direction grep prints nothing.

- [ ] **Step 10: Commit**

From the repo root:

```bash
git add backend/src/Service/Social backend/src/Service/Refresh backend/config/services.yaml \
  backend/tests/Support/SocialApiWiring.php backend/tests/Service/Social
git commit -m "feat(#1607): read private timelines concurrently, gated per account"
```

---

### Task 5: Store each timeline outcome as it lands

**Files:**
- Modify: `backend/src/Service/Refresh/SocialFeedRefresher.php` (rewrite)
- Modify: `backend/src/Service/Refresh/RefreshPassFetcher.php`
- Delete: `backend/src/Service/Refresh/Pass/SocialRefreshPass.php`
- Create: `backend/tests/Support/StubTimelineFetcher.php`
- Modify: `backend/tests/Support/RefreshRunners.php`
- Modify: `backend/tests/Service/Refresh/SocialFeedRefresherTest.php` (rewrite)
- Modify: `backend/tests/Service/Refresh/SocialFeedRefresherBlueskyTest.php`
- Modify: `backend/tests/Service/Refresh/RefreshRunner/RefreshRunnerSocialTest.php` (rewrite)
- Modify: `docs/security.md`

**Interfaces:**
- Consumes (Task 4): `TimelineFetcherInterface::fetchAll()`, `TimelineOutcomeModel`, `TimelineOutcome`,
  `SocialApiWiring::timelineFetcher()`.
- Produces:
  - `SocialFeedRefresher::__construct(SocialFeedRepository $socialFeeds, TimelineFetcherInterface $timelines, FeedOutcomePersister $outcomePersister)`.
  - `SocialFeedRefresher::refreshAll(RefreshPass $pass, \DateTimeImmutable $now): void`.
  - `StubTimelineFetcher` with `__construct(array<string, TimelineOutcomeModel> $outcomes = [])` by feed URL, and
    public `list<int> $asked` and `array<int, SocialFeed> $socialFeedsByFeedId`.
  - `RefreshRunners::readingTimelinesFrom(TimelineFetcherInterface $timelines): self`.

- [ ] **Step 1: Write the failing tests**

Create `tests/Support/StubTimelineFetcher.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\SocialFeed;
use App\Service\Social\Model\TimelineOutcomeModel;
use App\Service\Social\TimelineFetcher\TimelineFetcherInterface;
use PHPUnit\Framework\Assert;

final class StubTimelineFetcher implements TimelineFetcherInterface
{
    /** @var list<int> */
    public array $asked = [];

    /** @var array<int, SocialFeed> */
    public array $socialFeedsByFeedId = [];

    /** @param array<string, TimelineOutcomeModel> $outcomes by the private feed's URL */
    public function __construct(private readonly array $outcomes = [])
    {
    }

    public function fetchAll(array $socialFeedsByFeedId, iterable $feeds): \Generator
    {
        $this->socialFeedsByFeedId = $socialFeedsByFeedId;
        foreach ($feeds as $feedId => $feed) {
            $this->asked[] = $feedId;

            yield $feedId => $this->outcomes[$feed->getUrl()]
                ?? Assert::fail('No timeline outcome scripted for ' . $feed->getUrl());
        }
    }
}
```

Replace `tests/Service/Refresh/SocialFeedRefresherTest.php`. Keep the present `setUp()` and `page()` verbatim, and
replace everything else:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Refresh;

use App\Entity\Entry;
use App\Entity\Feed;
use App\Entity\SocialAccount;
use App\Entity\SocialFeed;
use App\Enum\SocialFeedKind;
use App\Service\Bluesky\Model\JsonNodeModel;
use App\Service\Refresh\Pass\RefreshPass;
use App\Service\Refresh\SocialFeedRefresher;
use App\Service\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\Model\PrivateFeedModel;
use App\Service\Social\Model\TimelineOutcomeModel;
use App\Service\Social\Model\TimelinePageModel;
use App\Service\Social\Support\MastodonTimelinePage;
use App\Tests\DbTestCase;
use App\Tests\Support\FeedOutcomePersisters;
use App\Tests\Support\PrivateFeedsWiring;
use App\Tests\Support\SocialAccounts;
use App\Tests\Support\SocialFeeds;
use App\Tests\Support\StubTimelineFetcher;
use App\Tests\Support\UserFactory;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class SocialFeedRefresherTest extends DbTestCase
{
    private MockClock $clock;
    private SocialAccount $account;
    private SocialFeed $home;

    // setUp() exactly as before.

    public function testAFetchedPageIsStoredAsTheFeedsEntries(): void
    {
        $pass = $this->refreshed(TimelineOutcomeModel::fetched(self::page()));

        self::assertSame(1, $pass->tally->fetched());
        self::assertSame(1, $pass->tally->entriesCreated());
        self::assertSame(
            1,
            $this->entityManager->getRepository(Entry::class)->count(['feed' => $this->home->getFeed()->requireId()]),
        );
    }

    public function testAThrottledTimelineWaitsOutItsRetryAfter(): void
    {
        $pass = $this->refreshed(TimelineOutcomeModel::throttled(120));

        self::assertSame(1, $pass->tally->throttled());
        self::assertSame(0, $this->home->getFeed()->getConsecutiveFailures());
        self::assertSame('2026-10-12 12:02:00', $this->home->getFeed()->getNextFetchAt()?->format('Y-m-d H:i:s'));
    }

    public function testAFailedReadIsStoredOnTheFeed(): void
    {
        $unreachable = new SocialNetworkUnreachableException('The Mastodon instance answered 502.');

        $pass = $this->refreshed(TimelineOutcomeModel::failed($unreachable));

        self::assertSame(1, $pass->tally->failed());
        self::assertSame(1, $this->home->getFeed()->getConsecutiveFailures());
        $this->entityManager->clear();
        self::assertSame(
            'The Mastodon instance answered 502.',
            $this->entityManager->find(Feed::class, $this->home->getFeed()->requireId())?->getLastErrorMessage(),
        );
    }

    public function testAFeedOfAThrottledAccountKeepsItsSchedule(): void
    {
        $nextFetchAt = $this->home->getFeed()->getNextFetchAt();

        $pass = $this->refreshed(TimelineOutcomeModel::accountThrottled());

        self::assertSame(1, $pass->tally->throttled());
        $this->assertUntouched($nextFetchAt);
    }

    public function testAFeedOfAPausedAccountKeepsItsSchedule(): void
    {
        $nextFetchAt = $this->home->getFeed()->getNextFetchAt();

        $pass = $this->refreshed(TimelineOutcomeModel::accountPaused());

        self::assertSame(1, $pass->tally->failed());
        $this->assertUntouched($nextFetchAt);
    }

    public function testTheFetcherReadsThePassesPrivateFeedsWithTheirAccounts(): void
    {
        $feedId = $this->home->getFeed()->requireId();
        $timelines = new StubTimelineFetcher([$this->home->getFeed()->getUrl() => TimelineOutcomeModel::throttled(null)]);

        $this->refresher($timelines)->refreshAll($this->pass(), $this->clock->now());

        self::assertSame([$feedId], array_keys($timelines->socialFeedsByFeedId));
        self::assertSame(
            $this->account->requireId(),
            $timelines->socialFeedsByFeedId[$feedId]->getSocialAccount()->requireId(),
        );
        self::assertSame([$feedId], $timelines->asked);
    }

    private function assertUntouched(?\DateTimeImmutable $nextFetchAt): void
    {
        $this->entityManager->clear();
        $reloaded = $this->entityManager->find(Feed::class, $this->home->getFeed()->requireId());
        self::assertNotNull($reloaded);
        self::assertSame(0, $reloaded->getConsecutiveFailures());
        self::assertNull($reloaded->getLastErrorMessage());
        self::assertEquals($nextFetchAt, $reloaded->getNextFetchAt());
    }

    private function refreshed(TimelineOutcomeModel $outcome): RefreshPass
    {
        $pass = $this->pass();
        $timelines = new StubTimelineFetcher([$this->home->getFeed()->getUrl() => $outcome]);
        $this->refresher($timelines)->refreshAll($pass, $this->clock->now());

        return $pass;
    }

    private function pass(): RefreshPass
    {
        return new RefreshPass([$this->home->getFeed()], $this->clock, $this->clock->now()->getTimestamp() + 300);
    }

    private function refresher(StubTimelineFetcher $timelines): SocialFeedRefresher
    {
        return new SocialFeedRefresher(
            SocialFeeds::repository($this->entityManager),
            $timelines,
            FeedOutcomePersisters::fromContainer(self::getContainer(), $this->entityManager, $this->clock)->idle(),
        );
    }

    // page() exactly as before.
}
```

Replace the two `// … exactly as before.` lines with the original methods.

Replace `tests/Service/Refresh/RefreshRunner/RefreshRunnerSocialTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Refresh\RefreshRunner;

use App\Entity\Entry;
use App\Entity\Feed;
use App\Entity\SocialAccount;
use App\Entity\Subscription;
use App\Entity\User;
use App\Service\Fetch\Model\FetchResponseModel;
use App\Service\Refresh\Model\RefreshRequestModel;
use App\Service\Social\Model\PrivateFeedModel;
use App\Tests\DbTestCase;
use App\Tests\Support\DuplicateKeyViolation;
use App\Tests\Support\FlushFailingEntityManager;
use App\Tests\Support\PrivateFeedsWiring;
use App\Tests\Support\RefreshRunners;
use App\Tests\Support\RoutedHttpClient;
use App\Tests\Support\SocialAccounts;
use App\Tests\Support\SocialApiWiring;
use App\Tests\Support\SocialFeeds;
use App\Tests\Support\StubFeedFetcher;
use App\Tests\Support\UserFactory;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/** The real concurrent timeline fetcher behind RefreshRunner, over a routed HTTP client. */
final class RefreshRunnerSocialTest extends DbTestCase
{
    private const string RSS_URL = 'https://example.com/feed.xml';
    private const string HOME = 'GET https://mastodon.example/api/v1/timelines/home?limit=40';
    private const string FRIENDS = 'GET https://mastodon.example/api/v1/timelines/list/42?limit=40';
    private const string FAMILY = 'GET https://mastodon.example/api/v1/timelines/list/43?limit=40';

    private MockClock $clock;
    private User $user;
    private SocialAccount $account;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clock = new MockClock('2026-10-12 12:00:00', 'UTC');
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        $this->user = (new UserFactory($this->entityManager, $hasher))->create('refresh-social@example.test');
        $this->account = SocialAccounts::mastodonOf($this->user);
        $this->entityManager->persist($this->account);
        $this->entityManager->flush();
    }

    public function testAPrivateFeedIsReadThroughItsAccountAndNeverFetchedByUrl(): void
    {
        $rss = new Feed(self::RSS_URL);
        $this->entityManager->persist($rss);
        $this->entityManager->persist(new Subscription($this->user, $rss, $this->clock->now()));
        $this->entityManager->flush();
        $homeId = $this->privateFeed(PrivateFeedModel::home())->requireId();
        $feeds = new StubFeedFetcher($this->clock);
        $feeds->willReturnBody(self::RSS_URL, /** @lang TEXT */ <<<XML
            <?xml version="1.0" encoding="UTF-8"?>
            <rss version="2.0"><channel><title>Example</title>
            <item><title>Post</title><link>https://example.com/p</link><guid>p-1</guid></item>
            </channel></rss>
            XML);
        $http = new RoutedHttpClient([self::HOME => self::statuses()]);

        $report = $this->runners($http)->build($feeds, $this->homepages())->run(RefreshRequestModel::allDue(300));

        self::assertSame(2, $report->total);
        self::assertSame(2, $report->fetched);
        self::assertSame([self::RSS_URL], $feeds->fetchedUrls);
        self::assertCount(1, $http->requests);
        self::assertSame(1, $this->entityManager->getRepository(Entry::class)->count(['feed' => $homeId]));
        $this->entityManager->clear();
        $stored = SocialFeeds::repository($this->entityManager)->findWithAccountByFeedIds([$homeId])[$homeId] ?? null;
        self::assertSame('109', $stored?->getNewestSeenId());
    }

    public function testAThrottledAccountIsAskedOnceAPass(): void
    {
        $this->privateFeed(PrivateFeedModel::home());
        $this->privateFeed(PrivateFeedModel::userList('42', 'Friends'));
        $http = new RoutedHttpClient([self::HOME => self::throttled(), self::FRIENDS => self::throttled()]);

        $report = $this->runners($http, accountConcurrency: 1)
            ->build(new StubFeedFetcher($this->clock), $this->homepages())
            ->run(RefreshRequestModel::allDue(300));

        self::assertSame(2, $report->throttled);
        self::assertCount(1, $http->requests);
    }

    public function testTheBudgetGateStopsTheSocialWaveMidPass(): void
    {
        $this->privateFeed(PrivateFeedModel::home());
        $this->privateFeed(PrivateFeedModel::userList('42', 'Friends'));
        $http = new RoutedHttpClient([self::HOME => self::statuses(), self::FRIENDS => self::statuses()]);

        $report = $this->runners($http)
            ->build(new StubFeedFetcher($this->clock), $this->homepages())
            ->run(RefreshRequestModel::allDue(5));

        self::assertSame('partial', $report->status);
        self::assertSame(1, $report->skippedForBudget);
        self::assertSame(1, $report->fetched);
        self::assertCount(1, $http->requests);
    }

    public function testAStoreThatAbortsTheRunStopsReadingTimelines(): void
    {
        $this->privateFeed(PrivateFeedModel::home());
        $this->privateFeed(PrivateFeedModel::userList('42', 'Friends'));
        $this->privateFeed(PrivateFeedModel::userList('43', 'Family'));
        $http = new RoutedHttpClient([
            self::HOME => self::statuses(),
            self::FRIENDS => self::statuses(),
            self::FAMILY => self::statuses(),
        ]);
        $failing = new FlushFailingEntityManager(
            $this->entityManager,
            failingFlush: 2,
            thrown: DuplicateKeyViolation::exception(),
        );

        $report = $this->runners($http, accountConcurrency: 1)
            ->flushingThrough($failing)
            ->build(new StubFeedFetcher($this->clock), $this->homepages())
            ->run(RefreshRequestModel::allDue(300));

        self::assertSame('aborted', $report->status);
        self::assertCount(2, $http->requests, 'the third timeline is never asked once the run aborted');
    }

    private function runners(RoutedHttpClient $http, int $accountConcurrency = 3): RefreshRunners
    {
        return RefreshRunners::fromContainer(self::getContainer(), $this->entityManager, $this->clock)
            ->readingTimelinesFrom(
                SocialApiWiring::timelineFetcher($http->client(), new ArrayAdapter(), $accountConcurrency),
            );
    }

    private function privateFeed(PrivateFeedModel $privateFeed): Feed
    {
        PrivateFeedsWiring::build(self::getContainer(), $this->entityManager, $this->clock)
            ->create($this->account, $privateFeed);
        $socialFeed = SocialFeeds::repository($this->entityManager)
            ->findOneFor($this->account, $privateFeed->kind, $privateFeed->remoteId);
        self::assertNotNull($socialFeed);

        return $socialFeed->getFeed();
    }

    private function homepages(): StubFeedFetcher
    {
        $homepages = new StubFeedFetcher();
        foreach (['https://example.com', 'https://mastodon.example'] as $origin) {
            $homepages->willReturn(
                $origin,
                FetchResponseModel::fetched($origin, false, '<html lang="en"></html>', null, null),
            );
        }

        return $homepages;
    }

    private static function statuses(): MockResponse
    {
        return new MockResponse(json_encode([[
            'id' => '109',
            'uri' => 'https://mastodon.example/users/bob/statuses/109',
            'url' => 'https://mastodon.example/@bob/109',
            'created_at' => '2026-10-12T11:59:00Z',
            'content' => '<p>Hello</p>',
            'account' => ['acct' => 'bob', 'display_name' => 'Bob', 'url' => 'https://mastodon.example/@bob'],
        ]], \JSON_THROW_ON_ERROR));
    }

    private static function throttled(): MockResponse
    {
        return new MockResponse('{}', [
            'http_code' => Response::HTTP_TOO_MANY_REQUESTS,
            'response_headers' => ['Retry-After' => '120'],
        ]);
    }
}
```

In `tests/Service/Refresh/SocialFeedRefresherBlueskyTest.php`:
- replace `refreshed()` and `refresher()` with the methods below;
- in `testATimelinePageIsStoredAsTheRssPathWouldStoreItsPosts`, replace the two result asserts with
  `$pass = $this->refreshed($home);`, `self::assertSame(1, $pass->tally->fetched());` and
  `self::assertSame(3, $pass->tally->entriesCreated());`;
- swap the imports `FeedOutcome` and `FeedRefreshResultModel` for `use App\Service\Refresh\Pass\RefreshPass;`.

```php
    private function refreshed(SocialFeed $socialFeed): RefreshPass
    {
        $clock = new MockClock('2026-10-12 12:00:00', 'UTC');
        $pass = new RefreshPass([$socialFeed->getFeed()], $clock, $clock->now()->getTimestamp() + 300);
        $this->refresher()->refreshAll($pass, $clock->now());

        return $pass;
    }

    private function refresher(): SocialFeedRefresher
    {
        $refresher = self::getContainer()->get(SocialFeedRefresher::class);
        self::assertInstanceOf(SocialFeedRefresher::class, $refresher);

        return $refresher;
    }
```

In `tests/Support/RefreshRunners.php`:
- swap `use App\Service\Social\TimelineReader\TimelineReaderInterface;` for
  `use App\Service\Social\TimelineFetcher\TimelineFetcherInterface;`;
- type the `$timelines` property and the `readingTimelinesFrom()` parameter as `TimelineFetcherInterface`;
- in `fromContainer()`, pass `new StubTimelineFetcher()` instead of `new StubTimelineReader()`.

The `SocialFeedRefresher` construction in `build()` keeps its three arguments.

- [ ] **Step 2: Run them to verify they fail**

Run: `php bin/phpunit --filter 'SocialFeedRefresherTest|SocialFeedRefresherBlueskyTest|RefreshRunnerSocialTest'`
Expected: FAIL with `Call to undefined method App\Service\Refresh\SocialFeedRefresher::refreshAll()`, and a TypeError
for `StubTimelineFetcher` given where `TimelineReaderInterface` is expected.

- [ ] **Step 3: Rewrite the refresher and point the pass fetcher at it**

Replace `src/Service/Refresh/SocialFeedRefresher.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Refresh;

use App\Entity\Feed;
use App\Repository\SocialFeedRepository;
use App\Service\Refresh\Model\FeedOutcome;
use App\Service\Refresh\Model\FeedRefreshResultModel;
use App\Service\Refresh\Model\IncomingFeedModel;
use App\Service\Refresh\Pass\RefreshPass;
use App\Service\Social\Model\TimelineOutcome;
use App\Service\Social\Model\TimelineOutcomeModel;
use App\Service\Social\Model\TimelinePageModel;
use App\Service\Social\TimelineFetcher\TimelineFetcherInterface;

/** Stores each private feed's outcome as it lands, one at a time on the one EntityManager. */
final readonly class SocialFeedRefresher
{
    public function __construct(
        private SocialFeedRepository $socialFeeds,
        private TimelineFetcherInterface $timelines,
        private FeedOutcomePersister $outcomePersister,
    ) {
    }

    /** @throws \DateMalformedStringException */
    public function refreshAll(RefreshPass $pass, \DateTimeImmutable $now): void
    {
        $socialFeeds = $this->socialFeeds->findWithAccountByFeedIds($pass->socialFeedIds());
        foreach ($this->timelines->fetchAll($socialFeeds, $pass->socialFeeds()) as $feedId => $outcome) {
            $feed = $pass->feed($feedId);
            $pass->tally->record($this->store($feed, $outcome, $now), $feed);
            if ($pass->tally->isAborted()) {
                return;
            }
        }
    }

    /** @throws \DateMalformedStringException */
    private function store(Feed $feed, TimelineOutcomeModel $outcome, \DateTimeImmutable $now): FeedRefreshResultModel
    {
        return match ($outcome->outcome) {
            TimelineOutcome::Fetched => $this->storePage($feed, $outcome->page(), $now),
            TimelineOutcome::Throttled => $this->outcomePersister->storeThrottled($feed, $outcome->retryAfterSeconds),
            TimelineOutcome::Failed => $this->outcomePersister->storeFailure($feed, $outcome->failure()),
            // Held back this refresh: the feed keeps its schedule for the next one.
            TimelineOutcome::AccountThrottled => FeedRefreshResultModel::of(FeedOutcome::Throttled),
            TimelineOutcome::AccountPaused => FeedRefreshResultModel::of(FeedOutcome::Failed),
        };
    }

    /** @throws \DateMalformedStringException */
    private function storePage(Feed $feed, TimelinePageModel $page, \DateTimeImmutable $now): FeedRefreshResultModel
    {
        return $this->outcomePersister->storeParsed($feed, new IncomingFeedModel($page->parsed, $page->knownShares), $now);
    }
}
```

`storePage()`'s `return` line is 121 columns. Break the `storeParsed(...)` arguments onto separate lines.

In `src/Service/Refresh/RefreshPassFetcher.php`, replace the social half of `fetchAndPersist()` (from
`$socialPass = …` to the end of its `foreach`) with:

```php
        $this->socialRefresher->refreshAll($pass, $now);
```

From the repo root, delete the replaced pass object:

```bash
git rm backend/src/Service/Refresh/Pass/SocialRefreshPass.php
```

- [ ] **Step 4: Record the boundary in the security doc**

In `docs/security.md`, section "Social accounts":
- Replace the first bullet with:

  ```markdown
  - Every call goes through `UrlGuard`: a one-off call via `GuardedApiClient`, a refresh's timeline reads via the
    shared `ConcurrentRequestEngine`. No redirects, the resolved IP pinned.
  ```

- In the refresh-worker bullet (#1599), after "…replaced once with a fresh session.", add:

  ```markdown
  Timeline reads run concurrently, at most three in flight per account (`social_account_concurrency`, #1607); a
  throttled or refused account's waiting reads are withheld before they reach the wire.
  ```

- In the last bullet, replace "only `SocialFeedRefresher` reads the feed" with "only `ConcurrentTimelineFetcher`
  reads the feed".

- [ ] **Step 5: Run the refresh suites**

Run: `php bin/phpunit tests/Service/Refresh tests/Service/Social tests/Command tests/Service/Maintenance tests/Service/Worker`
Expected: PASS.

- [ ] **Step 6: Break-test the sequential abort**

In `SocialFeedRefresher::refreshAll()`, delete the `if ($pass->tally->isAborted()) { return; }` block. Run
`php bin/phpunit --filter testAStoreThatAbortsTheRunStopsReadingTimelines`. Expected: FAIL; the third timeline is
asked. Restore the block by hand and rerun. Expected: PASS.

- [ ] **Step 7: Run the gates**

Run: `bin/console cache:warmup && composer check && composer md && composer test:parallel`
Expected: clean. `composer md` names neither `SocialFeedRefresher` nor `RefreshPassFetcher`.

- [ ] **Step 8: Commit**

From the repo root:

```bash
git add backend/src/Service/Refresh backend/tests/Support/StubTimelineFetcher.php backend/tests/Support/RefreshRunners.php \
  backend/tests/Service/Refresh docs/security.md
git commit -m "feat(#1607): store private timeline outcomes as they land"
```

---

### Task 6: Delete the blocking timeline path

The single-feed path ruling: nothing reads one private feed outside a pass, so the blocking reader has no caller.

**Files:**
- Delete: `backend/src/Service/Social/TimelineReader/TimelineReaderInterface.php`
- Delete: `backend/src/Service/Social/TimelineReader/NetworkTimelineReader.php`
- Delete: `backend/tests/Service/Social/TimelineReader/NetworkTimelineReaderTest.php`
- Delete: `backend/tests/Service/Social/TimelineReader/TimelineReaderWiringTest.php`
- Delete: `backend/tests/Support/StubTimelineReader.php`
- Create: `backend/tests/Service/Social/TimelineFetcher/TimelineFetcherWiringTest.php`
- Modify: `backend/src/Service/Social/TimelineSource/TimelineSourceInterface.php`
- Modify: `backend/src/Service/Social/TimelineSource/BlueskyTimelineSource.php`
- Modify: `backend/src/Service/Social/TimelineSource/MastodonTimelineSource.php`
- Modify: `backend/src/Service/Social/MastodonAccountApi.php`
- Modify: `backend/tests/Service/Social/TimelineSource/BlueskyTimelineSourceTest.php`
- Modify: `backend/tests/Service/Social/TimelineSource/MastodonTimelineSourceTest.php`

**Interfaces:**
- Consumes (Tasks 3–5): `TimelineFetcherInterface`, `TimelineSourceInterface::request/pageOf/dropSession`.
- Produces: `TimelineSourceInterface` without `page()`. `MastodonAccountApi` without `get()`.

- [ ] **Step 1: Write the container wiring test that replaces `TimelineReaderWiringTest`**

Create `tests/Service/Social/TimelineFetcher/TimelineFetcherWiringTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\TimelineFetcher;

use App\Entity\Feed;
use App\Entity\SocialAccount;
use App\Entity\SocialFeed;
use App\Enum\SocialFeedKind;
use App\Enum\SocialNetwork;
use App\Service\Crypto\InstanceSecretCipher;
use App\Service\Fetch\DnsResolver\NativeDnsResolver;
use App\Service\Social\Crypto\SocialCredentialCipher;
use App\Service\Social\Model\TimelineOutcome;
use App\Service\Social\TimelineFetcher\TimelineFetcherInterface;
use App\Tests\Support\AssignsEntityIds;
use App\Tests\Support\RoutedHttpClient;
use App\Tests\Support\SocialAccounts;
use App\Tests\Support\StaticDnsResolver;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpClient\Response\MockResponse;

final class TimelineFetcherWiringTest extends KernelTestCase
{
    use AssignsEntityIds;

    public function testTheContainerReadsEachNetworksTimelineThroughItsOwnWiring(): void
    {
        self::bootKernel();
        $http = new RoutedHttpClient([
            'GET https://mastodon.example/api/v1/timelines/home?limit=40' => new MockResponse('[]'),
            'POST https://pds.example.test/xrpc/com.atproto.server.createSession' => new MockResponse(
                '{"did":"did:plc:alice","handle":"alice.bsky.social","accessJwt":"jwt-1"}',
            ),
            'GET https://pds.example.test/xrpc/app.bsky.feed.getTimeline?limit=50' => new MockResponse('{"feed":[]}'),
        ]);
        $container = self::getContainer();
        $container->set('http_client.uri_template', $http->client());
        $container->set(
            NativeDnsResolver::class,
            new StaticDnsResolver(['mastodon.example' => ['93.184.216.34'], 'pds.example.test' => ['93.184.216.34']]),
        );
        $fetcher = $container->get(TimelineFetcherInterface::class);
        self::assertInstanceOf(TimelineFetcherInterface::class, $fetcher);
        $mastodon = $this->socialFeed(SocialNetwork::Mastodon, 1);
        $bluesky = $this->socialFeed(SocialNetwork::Bluesky, 2);

        $outcomes = [];
        $read = $fetcher->fetchAll([1 => $mastodon, 2 => $bluesky], [1 => $mastodon->getFeed(), 2 => $bluesky->getFeed()]);
        foreach ($read as $feedId => $outcome) {
            $outcomes[$feedId] = $outcome->outcome;
        }

        self::assertSame([TimelineOutcome::Fetched, TimelineOutcome::Fetched], [$outcomes[1], $outcomes[2]]);
        self::assertCount(3, $http->requests);
    }

    private function socialFeed(SocialNetwork $network, int $id): SocialFeed
    {
        $owner = SocialAccounts::owner();
        $cipher = new SocialCredentialCipher(self::getContainer()->get(InstanceSecretCipher::class));
        $account = SocialNetwork::Mastodon === $network
            ? new SocialAccount(
                $owner,
                $network,
                'alice@mastodon.example',
                '@alice@mastodon.example',
                'https://mastodon.example',
                $cipher->seal($owner->requireId(), SocialAccounts::ACCESS_TOKEN),
                '7890',
                new \DateTimeImmutable('2026-10-12 10:00:00'),
            )
            : new SocialAccount(
                $owner,
                $network,
                'did:plc:alice',
                '@alice.bsky.social',
                'https://pds.example.test',
                $cipher->seal($owner->requireId(), SocialAccounts::APP_PASSWORD),
                'mnop',
                new \DateTimeImmutable('2026-10-12 10:00:00'),
            );
        self::assignId($account, 10 + $id);

        return new SocialFeed($account, self::withId(new Feed('social:x:' . $id . ':home'), $id), SocialFeedKind::Home, '');
    }
}
```

`$read = …` and the last `return` are over 120 columns. Break their arguments onto separate lines.
`InstanceSecretCipher`'s `get()` returns `?object`. If PHPStan rejects it, assert it first, as
`TimelineReaderWiringTest` does.

Run: `php bin/phpunit --filter TimelineFetcherWiringTest`
Expected: PASS. The container already wires the fetcher since Task 4. This test replaces the coverage of the wiring
test that goes in Step 2.

- [ ] **Step 2: Delete the blocking path**

From the repo root:

```bash
git rm -r backend/src/Service/Social/TimelineReader backend/tests/Service/Social/TimelineReader \
  backend/tests/Support/StubTimelineReader.php
```

Then:
- In `TimelineSourceInterface`, delete `page()` with its docblock.
- In `BlueskyTimelineSource`, delete `page()`. `request()`, `pageOf()` and `dropSession()` stay.
- In `MastodonTimelineSource`, delete `page()`. `pageFrom()` keeps its one caller, `pageOf()`.
- In `MastodonAccountApi`, delete `get()`. Its only caller was `MastodonTimelineSource::page()`. Check with
  `grep -rn "mastodon->get(\|AccountApi::get\|->get(\$account" src`, which must print nothing.
- In `BlueskyTimelineSourceTest`, delete every test that calls `->page(`:
  - `testTheHomeTimelineIsReadWithTheSessionsToken`;
  - `testAListOrCustomFeedIsReadByItsUri`;
  - `testASecondPollOpensNoSecondSession`;
  - `testAnExpiredCachedSessionIsReplacedOnceAndThePollSucceeds`;
  - `testATokenRefusedAgainAfterAFreshSessionIsARefusedCredential`;
  - `testACachedSessionRefusedAsUnauthenticatedIsARefusedCredentialWithoutANewSession`;
  - `testASecondExpiredTokenAfterAFreshSessionIsARefusedCredential`;
  - `testACachedSessionsTokenGoesOnlyToTheAccountsOwnService`;
  - `testTooManyRequestsIsAThrottleCarryingItsDelay`;
  - `testAnUnusableAnswerIsAnUnreachableNetwork`, with its `unusableAnswers()` provider.

  Each has a request/pageOf counterpart from Task 3, or a fetcher counterpart from Task 4. The token-only-to-own-service
  guarantee lives on in `testARequestCarriesTheSessionsTokenToTheAccountsOwnServiceOnly`.
- In `MastodonTimelineSourceTest`, delete every test that calls `->page(`:
  - `testTheHomeTimelineIsReadWithTheTokenAndNamesItsNewestStatus`;
  - `testAListAsksOnlyForWhatCameAfterTheNewestSeenStatus`;
  - `testARefusedTokenIsARefusedCredential`;
  - `testAnAnswerThatIsNoListIsAnUnreachableNetwork`;
  - `testAnHtmlAnswerIsAnUnreachableNetwork`;
  - `testAListIdThatIsNotANumberIsNeverRequested`.

  Keep the `foreignListIds()` provider, which `testAForeignListIdIsNeverTurnedIntoARequest` uses.

- [ ] **Step 3: Remove what the deletion orphaned**

Run: `bin/console cache:warmup && composer stan`
Expected: either clean, or only "unused private method/constant" findings in the two source tests (for example
`timeline()`, `TIMELINE`, `statuses()` or `json()`). Delete exactly what PHPStan names, then rerun it until it is
clean.

Then confirm nothing names the blocking path:

```bash
grep -rn "TimelineReader\|StubTimelineReader\|SocialRefreshPass\|->page(\$\|passOver" src tests
```

Expected: no output.

- [ ] **Step 4: Run the suites**

Run: `php bin/phpunit tests/Service/Social tests/Service/Refresh && composer check && composer md && composer test:parallel`
Expected: PASS and clean.

- [ ] **Step 5: Commit**

From the repo root:

```bash
git add -A backend/src/Service/Social backend/tests/Service/Social backend/tests/Support
git commit -m "refactor(#1607): delete the blocking timeline reader"
```

---

### Task 7: Final verification

**Files:** none, unless a gate finds something.

- [ ] **Step 1: Merge develop once more**

From the repo root: `git fetch origin develop && git log --oneline HEAD..origin/develop`. If it is non-empty,
`git merge origin/develop` and resolve.

- [ ] **Step 2: Run every gate**

From `backend/`:

```bash
bin/console cache:warmup
composer check
composer md
composer test:parallel
```

From the repo root, the MySQL leg. Clear the container's compiled cache first, because the Docker dev container can
serve a stale DI container:

```bash
docker compose exec php bin/console cache:clear
docker compose exec php composer test
```

From `backend/`: `git fetch origin develop && composer infection:diff`

Expected:
- `composer check` is clean. That covers cs, stan with every house rule, and tramp.
- `composer md` names no file this branch touches. List them with
  `git diff --name-only origin/develop -- 'backend/src/*.php'`.
- Both suite legs are green. If the MySQL leg fails only in the rate-limiter test, rerun once; that is a known flake.
- `infection:diff` meets `minMsi`. Each escaped mutant on a touched line gets a test. There is one exception. If the
  escapee is the removal of `$response->cancel()` in `ConcurrentRequestEngine::retire()` or in `sendAll()`'s
  `finally`, it is equivalent against `MockHttpClient`, exactly as the existing `MethodCallRemoval` comment in
  `infection.json5` says. Add `'App\\Service\\Fetch\\ConcurrentRequestEngine::retire'` and/or
  `'App\\Service\\Fetch\\ConcurrentRequestEngine::sendAll'` to that ignore list, under its comment. Never lower
  `minMsi`.
- If `composer tramp` fails on a chain with no commit here to explain it, check `composer show larspohlmann/phptramp`
  first. CI runs phptramp's `develop` tip.

- [ ] **Step 3: PhpStorm inspections and the dev log**

Run `mcp__phpstorm__lint_files` on every PHP file the branch changed
(`git diff --name-only origin/develop -- '*.php'`). Block on ERROR or WARNING.

Then scan today's dev log without triggering anything. Do not refresh against the dev database.

```bash
ls -t backend/var/log/dev-*.log | head -1 | xargs tail -n 300 | jq 'select(.level_name != "DEBUG" and .level_name != "INFO")'
```

Expected: no new deprecation or error from Fetch, Social or Refresh.

- [ ] **Step 4: Check the "Done when" and the module direction**

```bash
grep -rnE 'App\\Service\\(Social|Refresh)' backend/src/Service/Fetch
grep -rn 'App\\Service\\Refresh' backend/src/Service/Social
grep -rn "TimelineReader\|SocialRefreshPass\|HostSlots" backend/src backend/tests backend/config
git diff --stat origin/develop -- frontend backend/migrations
```

Expected: all four outputs are empty.

The "Done when" tests are:
- `testEveryTimelineIsOnTheWireBeforeTheFirstAnswerIsRead` (concurrency);
- `testNoAccountHasMoreThanItsCapInFlightWhileAnotherRunsAlongside` (per-account cap);
- `testAThrottledAccountKeepsItsUnsentFeedsBackAndLeavesOthersAlone`, `testARefusedTokenPausesOnlyItsAccount`,
  `testAStaleBlueskySessionIsRenewedOnceAndItsFeedsAskedAgain` and
  `testAStaleAnswerToARenewedSessionRefusesTheAccount` (per-account isolation);
- `testTheBudgetGateStopsTheSocialWaveMidPass` (budget);
- `testAbandoningTheReadSendsNothingMore` and `testAStoreThatAbortsTheRunStopsReadingTimelines` (abort).

All of them pass.

- [ ] **Step 5: Commit any gate fix**

If a gate needed a fix, commit it from the repo root as `fix(#1607): …` or `test(#1607): …`, staging only the files
you changed. Do not push.

## Self-review

- **Spec coverage:**
  - *Decision A* (one engine, all security rules once, RSS unchanged): Task 1. The 28 + proxy + concurrent-refresh
    tests pass with constructor-only changes.
  - The engine's second kind (API tickets, named slots, gate): Task 2.
  - *Social flow:*
    - Step 1 (skip paused or throttled accounts, open a Bluesky session once): `TimelineAccounts::mayAsk`, and the lazy
      `request()` through the session cache (Tasks 3–4).
    - Step 2 (ticket = service URL + path + token, no redirects, per-account cap 3 as a parameter, budget per start):
      `RequestTicketModel::api`, `ApiResponseExchange`, `social_account_concurrency` and the lazy `tickets()`
      (Tasks 2 and 4).
    - Step 3 (pure mappers keep the status mapping; stale → renew once and requeue, second stale → rejected;
      429 → throttle the account; 401 → mark it as needing a reconnect and drop its queue): `pageOf()` (Task 3), and
      `TimelineRound` / `TimelineAccounts` with the gate (Task 4).
    - Step 4 (persist sequentially through the existing persister paths, with abort): `SocialFeedRefresher::refreshAll`
      (Task 5). `recordNewestSeen` happens in `TimelineRound::settle` and is flushed by `storeParsed`.
  - *Keep:*
    - The phase order: `RefreshPassFetcher` still runs RSS first (Task 5).
    - The single-feed path: ruled, deleted (Task 6).
    - The token-only-to-own-service guarantee:
      `BlueskyTimelineSourceTest::testARequestCarriesTheSessionsTokenToTheAccountsOwnServiceOnly` (Task 3).
  - *Done when:* Task 7 Step 4 lists each test.
  - The PostEnricher's blocking calls stay in persist, untouched (out of scope, per the design).
- **Placeholder scan:**
  - Every code step shows its code, and every command has its expected result.
  - The "break lines over 120 columns" notes name the exact lines and keep the behaviour.
  - Task 6 Step 3 deletes exactly what PHPStan names as unused, which is a gate instruction, not missing content.
- **Type consistency:**
  - `ConcurrentRequestEngine::sendAll(iterable, int, ?DispatchGateInterface)`, in Tasks 1, 2 and 4.
  - `RequestTicketModel::feed()` / `::api(ApiRequestModel, string)`, `slotKeyFor()` and `feedTicket()`, in Tasks 1, 2
    and 4, plus `FeedTickets::of()`.
  - `FetchOutcomeModel::answered()`, `withheld()`, `isWithheld()` and `answerOrThrow()`, in Tasks 2, 3 and 4.
  - `ExchangeStepModel::awaitBody()`, `answered()`, `sendNext()`, `$outcome` and `$nextAttempt`, in Tasks 1 and 2.
  - `TimelineSourceInterface::request()`, `pageOf(SocialFeed, FetchOutcomeModel)` and `dropSession(SocialAccount)`,
    in Tasks 3, 4 and 6.
  - `TimelineFetcherInterface::fetchAll(array<int, SocialFeed>, iterable<int, Feed>)`, in Tasks 4, 5 and 6, and
    `StubTimelineFetcher`.
  - `TimelineOutcomeModel` (with `page()`, `failure()`, `retryAfterSeconds` and `outcome`) and `TimelineOutcome`, in
    Tasks 4 and 5.
  - `SocialFeedRefresher::refreshAll(RefreshPass, \DateTimeImmutable)`, in Task 5 and `SocialFeedRefresherBlueskyTest`.
  - `SocialApiWiring::timelineFetcher(HttpClientInterface, ArrayAdapter, int)`, in Tasks 4 and 5.
  - `FetchWiring::requestEngine(...)` and `egressProxy(?ProxyConfigModel)`, in Tasks 1, 2 and 4.
  - `RequestSlots`, in Task 2 onward. `HostSlots` exists only in Task 1.
