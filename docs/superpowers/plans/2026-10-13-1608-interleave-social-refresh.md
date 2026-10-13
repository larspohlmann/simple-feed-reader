# One engine run across RSS and private feeds (#1608) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A refresh pass starts its due feeds in staleness order, whatever their kind, on one concurrent engine run, so
a pass whose RSS batch fills the budget can no longer starve the private Bluesky and Mastodon feeds pass after pass.

**Architecture:** First a 429 stops earning a second route on every path. Then an engine ticket names its own slots,
each with a capacity: an RSS ticket holds its host's slot, a timeline ticket holds its account's slot and its server's
slot. A new per-pass fetcher in `Service/Refresh/PassFetcher` turns the pass's started feeds into one ticket stream:
an RSS feed becomes a feed ticket, a private feed becomes its `TimelineRound` ticket. It yields each feed's outcome
as it lands, a fetch outcome or a timeline outcome. `RefreshPassFetcher` stores whatever lands through one
`PassOutcomeStore`. The tests that drove a refresh through `BatchFeedFetcherInterface` or `TimelineFetcherInterface`
move to one stub of the new seam. The two-phase path (`SocialFeedRefresher`, `ConcurrentTimelineFetcher` and their
helpers) is then deleted.

**Tech Stack:** Symfony 7.4 / PHP 8.4, Symfony HttpClient (`MockHttpClient`), PHPUnit 12, PHPStan max, PHPMD,
phptramp, Infection.

**Spec:** `docs/superpowers/specs/2026-10-13-1608-interleave-social-refresh-design.md` (binding). Read it before you
start a task.

## Global Constraints

- CLAUDE.md applies in full: Clean Code, no abbreviations, guard clauses, `final readonly` by default, role folders
  (docs/architecture.md §10: `Pass/` for per-call objects, `Model/` for data, an interface's folder named after it),
  `Response::HTTP_*` for every status in `src` and `tests`, PSR-12, `declare(strict_types=1)`.
- **No needless comments.** At most one line where a future reader would get the code wrong without it. Delete a
  comment that a moved line made false (for example "the favicon phase fetches through this same fetcher").
- Every line is **at most 120 columns**. Wrap what phpcs reports (one argument per line) without changing behaviour.
- Every `src` file you touch is PHPMD-clean (`composer md`): `TooManyPublicMethods` is 10.
- `composer check` stays green, and with it:
  - phptramp: a value forwarded through 4 or more methods across 2 or more classes fails. Hold it in a field.
  - `ServiceRoleRule`: a root service's constructor takes only collaborators and configured scalars.
  - `ServiceModuleCycleRule`: `Service/Fetch` imports nothing from `Service/Social` or `Service/Refresh`, and
    `Service/Social` imports nothing from `Service/Refresh`. Check after each task with
    `grep -rnE 'App\\Service\\(Social|Refresh)' src/Service/Fetch; grep -rn 'App\\Service\\Refresh' src/Service/Social`.
    Both must print nothing.
  - `AbbreviatedNameRule`, `NoCollaboratorDefaultRule`, `InvocationMatchersOnThisRule`.
- **The lazy-start invariant.** A feed counts as started only when the engine pulls its ticket. `FetchQueue` defers
  `next()` on the ticket source until the next ticket is wanted. Never map, buffer or `iterator_to_array` a ticket
  stream ahead of the engine in `src`.
- **Every started feed yields exactly one outcome**, a private feed that settles without a request included (held
  account, orphan, pre-send failure). `remaining` and the tally depend on it.
- Avoid `(new X())->call()` in `src`: pdepend, which `composer md` runs, cannot parse it. Assign to a variable first.
- Commands run from `backend/` unless a step says "from the repo root". **Git commands run from the repo root.**
- Commits are `type(#1608): …`. No `Co-Authored-By`, no "Generated with", no session link. **Do not push.**
- **At the start of every task after Task 1:** from the repo root, run `git status`, then
  `git fetch origin develop && git merge --no-edit origin/develop`. Resolve any conflict, then rerun the previous
  task's filtered tests before you write code.
- Concurrent sessions share this checkout. Never `git checkout --`, `git reset` or `git stash`. Undo a break test by
  editing the line back by hand.
- **No migration, and never touch the dev database.** If a step seems to need either, stop and report it.
- `composer stan` needs a warm cache: run `bin/console cache:warmup` after every `config/` change.
- Never run two test suites at once (paratest and the Docker MySQL leg share nothing, but they compete for memory).

## File structure

| File | Task | Responsibility |
|---|---|---|
| `src/Service/Fetch/CrossFamilyFailover.php`, `src/Service/Fetch/FetchRetryPolicy.php` (modify) | 1 | a 429 earns no other route |
| `src/Service/Fetch/Model/RequestSlotModel.php` (create) | 2 | one capped slot: named, or the host the attempt reached |
| `src/Service/Fetch/Model/RequestTicketModel.php` (modify) | 2 | a ticket carries its slots |
| `src/Service/Fetch/Pass/RequestSlots.php` (modify) | 2 | a ticket goes out only while every slot it names has room |
| `src/Service/Fetch/ConcurrentRequestEngine.php` (modify) | 2 | `sendAll()` loses the per-run slot capacity |
| `src/Service/Fetch/BatchFeedFetcher/ConcurrentFeedFetcher.php` (modify) | 2 | builds host-slotted feed tickets |
| `src/Service/Social/Model/TimelineSlotsModel.php` (create) | 2 | a timeline ticket's account and server slots |
| `src/Service/Social/TimelineRounds.php` (create) | 2 | starts one refresh's `TimelineRound` with both caps |
| `src/Service/Social/Pass/TimelineRound.php` (modify) | 2, 5 | builds its tickets through `TimelineSlotsModel`; `ticketFor()` |
| `src/Service/Refresh/Pass/PassFeeds.php` (create) | 3 | a pass's started feeds and its private feeds' rows |
| `src/Service/Refresh/Pass/PassTickets.php` (create) | 3 | one mixed ticket stream, the gate, settling |
| `src/Service/Refresh/PassFetcher/PassFetcherInterface.php` (create) | 3 | the refresh's one test seam |
| `src/Service/Refresh/PassFetcher/ConcurrentPassFetcher.php` (create) | 3 | the seam over the engine, renewal wave included |
| `src/Service/Refresh/Pass/{BudgetedFeedQueue,RefreshPass}.php` (modify) | 3, 5 | `startedFeeds()` in due order; old per-kind streams go |
| `src/Service/Refresh/RefreshPassFetcher.php` (modify) | 4 | one loop over the seam |
| `src/Service/Refresh/Pass/TimelineOutcomeStore.php` → `PassOutcomeStore.php` (rename) | 4 | stores both kinds |
| `src/Service/Refresh/{SocialFeedRefresher}.php`, `Refresh/Pass/PrivateFeedSupply.php` (delete) | 5 | the second phase |
| `src/Service/Social/TimelineFetcher/*` (delete) | 5 | the social-only engine client |
| `config/services.yaml`, `config/services_test.yaml` (modify) | 2, 3, 4, 5 | caps and the seam's alias |
| `tests/Support/StubPassFetcher.php`, `tests/Support/RefreshFetchers.php` (create) | 4 | the refresh stub |
| `tests/Support/{StubTimelineFetcher}.php` (delete), `tests/Support/StubFeedFetcher.php` (simplify) | 5 | |
| `docs/security.md` (modify) | 1, 2, 5 | 429, server cap, who reads a private feed |

---

### Task 1: A 429 never earns another route

Spec §4, on its own. A 429 rations the client, not the route: no direct fallback after a proxied attempt, no other
address family, on the engine API path, the `GuardedApiClient` path and the RSS path.

Today three places re-drive a 429:
- `CrossFamilyFailover::isRetryableStatus(429)` is true, so `ApiResponseExchange::onHeaders()` and
  `FailoverRequestSender` ask again over the next route;
- `FetchRetryPolicy::nextAttemptAfter()` hands a proxied RSS 429 (`FeedThrottledException`) its direct fallback before
  it ever asks `warrantsAnotherFamily()`;
- `warrantsAnotherFamily()` returns true for a direct RSS 429, so it goes over the next family.

**Files:**
- Modify: `backend/src/Service/Fetch/CrossFamilyFailover.php`
- Modify: `backend/src/Service/Fetch/FetchRetryPolicy.php`
- Modify: `docs/security.md` (the "Every call goes through `UrlGuard`" bullet, around line 330)
- Test: `backend/tests/Service/Fetch/CrossFamilyFailoverTest.php`
- Test: `backend/tests/Service/Fetch/FailoverRequestSenderTest.php`
- Test: `backend/tests/Service/Fetch/FailoverRequestSenderProxyTest.php`
- Test: `backend/tests/Service/Fetch/GuardedApiClientTest.php`
- Test: `backend/tests/Service/Fetch/ConcurrentRequestEngineTest.php`
- Test: `backend/tests/Service/Fetch/BatchFeedFetcher/ConcurrentFeedFetcherTest.php`
- Test: `backend/tests/Service/Fetch/BatchFeedFetcher/ConcurrentFeedFetcherProxyTest.php`

**Interfaces:**
- Consumes: nothing new.
- Produces: no signature change. `CrossFamilyFailover::isRetryableStatus(Response::HTTP_TOO_MANY_REQUESTS)` is false;
  `FetchRetryPolicy::nextAttemptAfter($attempt, FeedThrottledException)` is null.

- [ ] **Step 1: Write the failing tests**

`CrossFamilyFailoverTest.php`: add `use Symfony\Component\HttpFoundation\Response;` and:

```php
    public function testTooManyRequestsWarrantsNoOtherRoute(): void
    {
        self::assertFalse($this->failover->isRetryableStatus(Response::HTTP_TOO_MANY_REQUESTS));
    }
```

`FailoverRequestSenderTest.php` (the `GuardedApiClient` route logic, direct): add
`use Symfony\Component\HttpFoundation\Response;` and:

```php
    public function testATooManyRequestsAnswerIsNotAskedAgainOverTheNextFamily(): void
    {
        $client = new MockHttpClient(
            static fn (): MockResponse => new MockResponse('', ['http_code' => Response::HTTP_TOO_MANY_REQUESTS]),
        );

        $response = $this->sender($client)->send(
            'GET',
            'https://dual.example.com/post',
            new GuardedUrlModel('dual.example.com', self::DUAL_STACK),
            [],
        );

        self::assertSame(Response::HTTP_TOO_MANY_REQUESTS, $response->getStatusCode());
        self::assertSame(1, $client->getRequestsCount());
    }
```

`FailoverRequestSenderProxyTest.php` (proxied, direct fallback on): add `use Symfony\Component\HttpFoundation\Response;`
and:

```php
    public function testAProxiedTooManyRequestsAnswerIsNotAskedAgainDirectly(): void
    {
        $calls = [];
        $client = new MockHttpClient(function (
            string $method,
            string $url,
            array $options,
        ) use (&$calls): MockResponse {
            $calls[] = $options;

            return new MockResponse('', ['http_code' => Response::HTTP_TOO_MANY_REQUESTS]);
        });
        $sender = FetchWiring::failoverSender($client, $this->egressProxy($this->proxy()));

        $status = $sender->send('GET', 'https://page.example', $this->guarded(), [])->getStatusCode();

        self::assertSame(Response::HTTP_TOO_MANY_REQUESTS, $status);
        self::assertCount(1, $calls);
        self::assertArrayHasKey('proxy', $calls[0]);
    }
```

`GuardedApiClientTest.php` (the whole `GuardedApiClient` path): in `client()`, add a dual-stack host to the
resolver map: `'dual.example.test' => ['2606:2800:220:1:248:1893:25c8:1946', '93.184.216.34']`. Then add:

```php
    public function testATooManyRequestsAnswerCostsOneRequestOverEveryFamily(): void
    {
        $http = new MockHttpClient(
            static fn (): MockResponse => new MockResponse('{}', ['http_code' => Response::HTTP_TOO_MANY_REQUESTS]),
        );

        $response = $this->client($http)->send(ApiRequestModel::get('https://dual.example.test/x'));

        self::assertSame(Response::HTTP_TOO_MANY_REQUESTS, $response->status);
        self::assertSame(1, $http->getRequestsCount());
    }

    public function testAProxiedTooManyRequestsAnswerCostsOneRequest(): void
    {
        $http = new MockHttpClient(
            static fn (): MockResponse => new MockResponse('{}', ['http_code' => Response::HTTP_TOO_MANY_REQUESTS]),
        );
        $proxy = new ProxyConfigModel(ProxyType::Socks5, 'p', 1080, null, null);

        $response = $this->client($http, $proxy)->send(ApiRequestModel::get('https://api.example.test/x'));

        self::assertSame(Response::HTTP_TOO_MANY_REQUESTS, $response->status);
        self::assertSame(1, $http->getRequestsCount());
    }
```

`ConcurrentRequestEngineTest.php` (the engine API path; `sendAll` still takes its slot capacity in this task):

```php
    public function testATooManyRequestsAnswerIsNotAskedAgainOverTheNextFamily(): void
    {
        $requests = 0;
        $client = new MockHttpClient(static function () use (&$requests): MockResponse {
            ++$requests;

            return new MockResponse('{}', ['http_code' => Response::HTTP_TOO_MANY_REQUESTS]);
        });

        $answer = $this->onlyAnswer($client, self::DUAL)->answerOrThrow();

        self::assertSame(Response::HTTP_TOO_MANY_REQUESTS, $answer->status);
        self::assertSame(1, $requests);
    }

    public function testAProxiedTooManyRequestsAnswerIsNotAskedAgainDirectly(): void
    {
        $requests = 0;
        $client = new MockHttpClient(static function () use (&$requests): MockResponse {
            ++$requests;

            return new MockResponse('{}', ['http_code' => Response::HTTP_TOO_MANY_REQUESTS]);
        });
        $engine = $this->engine($client, new ProxyConfigModel(ProxyType::Socks5, 'p', 1080, null, null));

        $answer = self::collect($engine->sendAll([1 => self::ticket()], 3))[1]->answerOrThrow();

        self::assertSame(Response::HTTP_TOO_MANY_REQUESTS, $answer->status);
        self::assertSame(1, $requests);
    }
```

`ConcurrentFeedFetcherTest.php` (RSS, direct): add `use App\Service\Fetch\Exception\FeedThrottledException;` and
`use Symfony\Component\HttpFoundation\Response;`, then:

```php
    public function testATooManyRequestsAnswerIsNotFailedOverToAnotherFamily(): void
    {
        $requests = 0;
        $fetcher = $this->fetcher(
            static function () use (&$requests): MockResponse {
                ++$requests;

                return new MockResponse('', ['http_code' => Response::HTTP_TOO_MANY_REQUESTS]);
            },
            dnsOverrides: ['dual.example.com' => ['2606:2800:220:1:248:1893:25c8:1946', '93.184.216.34']],
        );

        $outcomes = $this->collect($fetcher->fetchAll([1 => new FetchTicketModel('https://dual.example.com/feed')]));

        self::assertInstanceOf(FeedThrottledException::class, $outcomes[1]->failure());
        self::assertSame(1, $requests);
    }
```

`ConcurrentFeedFetcherProxyTest.php` (RSS, proxied, direct fallback on): add
`use App\Service\Fetch\Exception\FeedThrottledException;` and `use Symfony\Component\HttpFoundation\Response;`, then:

```php
    public function testAProxiedTooManyRequestsAnswerIsNotRetriedDirectly(): void
    {
        $proxy = new ProxyConfigModel(ProxyType::Socks5, 'p', 1080, null, null);
        /** @var list<array<string, mixed>> $seenOptions */
        $seenOptions = [];
        $fetcher = $this->fetcher(
            function (string $method, string $url, array $options) use (&$seenOptions): MockResponse {
                $seenOptions[] = $options;

                return new MockResponse('', ['http_code' => Response::HTTP_TOO_MANY_REQUESTS]);
            },
            $proxy,
        );

        $outcomes = $this->collect($fetcher->fetchAll([1 => new FetchTicketModel('https://example.com/feed')]));

        self::assertInstanceOf(FeedThrottledException::class, $outcomes[1]->failure());
        self::assertCount(1, $seenOptions);
        self::assertSame('socks5://p:1080', $seenOptions[0]['proxy']);
    }
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php bin/phpunit --filter 'TooManyRequests'`
Expected: FAIL. Every new test except `testTooManyRequestsWarrantsNoOtherRoute` counts 2 requests (or 2 options).
`testTooManyRequestsWarrantsNoOtherRoute` fails on `assertFalse`.

- [ ] **Step 3: Write the implementation**

`CrossFamilyFailover.php`. Current:

```php
    private const array MISSING_RESOURCE_STATUSES = [Response::HTTP_NOT_FOUND, Response::HTTP_GONE];
…
    /** A 4xx or 5xx can be tied to the source address, so another route may answer differently; a missing resource cannot. */
    public function isRetryableStatus(int $statusCode): bool
    {
        return $statusCode >= Response::HTTP_BAD_REQUEST
            && !\in_array($statusCode, self::MISSING_RESOURCE_STATUSES, true);
    }
```

becomes:

```php
    private const array ROUTE_INDEPENDENT_STATUSES = [
        Response::HTTP_NOT_FOUND,
        Response::HTTP_GONE,
        Response::HTTP_TOO_MANY_REQUESTS,
    ];
…
    /**
     * A 4xx or 5xx can be tied to the source address, so another route may answer differently. A missing resource
     * cannot, and a 429 rations the client whatever route it takes.
     */
    public function isRetryableStatus(int $statusCode): bool
    {
        return $statusCode >= Response::HTTP_BAD_REQUEST
            && !\in_array($statusCode, self::ROUTE_INDEPENDENT_STATUSES, true);
    }
```

`FetchRetryPolicy.php`: add `use App\Service\Fetch\Exception\FeedThrottledException;`. Current:

```php
    /**
     * The next attempt this failure earns, or null when it is terminal. A still-proxied attempt (no direct fallback)
     * never retries over another family: that would re-send the same proxied request once per family.
     */
    public function nextAttemptAfter(FetchAttemptModel $attempt, FetchException $failure): ?FetchAttemptModel
    {
        $fallback = $this->directFallbackFor($attempt);
```

becomes:

```php
    /**
     * The next attempt this failure earns, or null when it is terminal. A 429 is terminal on any route. A still-proxied
     * attempt (no direct fallback) never retries over another family: that would re-send the same proxied request once
     * per family.
     */
    public function nextAttemptAfter(FetchAttemptModel $attempt, FetchException $failure): ?FetchAttemptModel
    {
        if ($failure instanceof FeedThrottledException) {
            return null;
        }

        $fallback = $this->directFallbackFor($attempt);
```

Leave `warrantsAnotherFamily()` as it is: the guard above runs first, so it never sees a 429.

- [ ] **Step 4: Run the tests to verify they pass**

Run: `php bin/phpunit tests/Service/Fetch`
Expected: PASS, every test in the folder (the new ones and the existing failover tests: 403, 503 and resets still
fail over).

- [ ] **Step 5: Update the doc**

`docs/security.md`, the bullet that starts "Every call goes through `UrlGuard`". It ends "…with a direct pinned call as
the fallback when direct fallback is on." Append one sentence: "A 429 is never asked again over another route, neither
directly nor over another address family (#1608)."

- [ ] **Step 6: Gates for the touched files**

Run: `composer cs && composer stan && composer md`
Expected: clean.

- [ ] **Step 7: Commit**

From the repo root:

```bash
git add backend/src/Service/Fetch/CrossFamilyFailover.php backend/src/Service/Fetch/FetchRetryPolicy.php \
  backend/tests/Service/Fetch docs/security.md
git commit -m "fix(#1608): a 429 costs one request on every route"
```

---

### Task 2: A ticket names its own slots

Spec §1. `sendAll()` drops its per-run `int $slotCapacity`. Every ticket carries one or more `RequestSlotModel`s. It
goes on the wire only when every slot it names has room, holds them all in flight and releases them all on
retirement. RSS tickets hold `host:<HostKey>` at `fetch_host_concurrency` (2), following redirects as today. Timeline
tickets hold `social-account:<id>` at `social_account_concurrency` (3) and `social-host:<HostKey of the request URL>`
at the new `social_host_concurrency` (4). The existing engine, fetcher and timeline tests stay green.

**Files:**
- Create: `backend/src/Service/Fetch/Model/RequestSlotModel.php`
- Modify: `backend/src/Service/Fetch/Model/RequestTicketModel.php`
- Modify: `backend/src/Service/Fetch/Pass/RequestSlots.php`
- Modify: `backend/src/Service/Fetch/ConcurrentRequestEngine.php` (`sendAll()` only)
- Modify: `backend/src/Service/Fetch/BatchFeedFetcher/ConcurrentFeedFetcher.php`
- Create: `backend/src/Service/Social/Model/TimelineSlotsModel.php`
- Create: `backend/src/Service/Social/TimelineRounds.php`
- Modify: `backend/src/Service/Social/Pass/TimelineRound.php`
- Modify: `backend/src/Service/Social/TimelineFetcher/ConcurrentTimelineFetcher.php`
- Modify: `backend/config/services.yaml`
- Modify: `docs/security.md` (the refresh worker bullet, around line 345)
- Modify: `backend/tests/Support/FeedTickets.php`, `backend/tests/Support/SocialApiWiring.php`
- Create: `backend/tests/Service/Fetch/Model/RequestSlotModelTest.php`, `backend/tests/Service/Social/TimelineRoundsTest.php`
- Modify (tests): `tests/Service/Fetch/Model/RequestTicketModelTest.php`, `tests/Service/Fetch/Pass/RequestSlotsTest.php`,
  `tests/Service/Fetch/Pass/FetchQueueTest.php`, `tests/Service/Fetch/ConcurrentRequestEngineTest.php`,
  `tests/Service/Social/Pass/TimelineRoundTest.php`

**Interfaces:**
- Consumes: Task 1 (no signature).
- Produces:
  - `RequestSlotModel::named(string $name, int $capacity): self`, `RequestSlotModel::perHost(int $capacity): self`,
    `RequestSlotModel::keyFor(string $url): string`, `public int $capacity`. A capacity below one throws
    `\InvalidArgumentException('Slot capacity must be at least 1, got N.')`.
  - `RequestTicketModel::feed(FetchTicketModel $ticket, int $hostCapacity): self`;
    `RequestTicketModel::api(ApiRequestModel $request, array $slots): self` with `@param non-empty-list<RequestSlotModel>`;
    `public array $slots` (`non-empty-list<RequestSlotModel>`). `slotKeyFor()` is gone.
  - `new RequestSlots()` (no argument).
  - `ConcurrentRequestEngine::sendAll(iterable $tickets, ?DispatchGateInterface $gate = null): \Generator`.
  - `TimelineSlotsModel::__construct(int $perAccount, int $perHost)`,
    `ticketFor(SocialAccount $account, ApiRequestModel $request): RequestTicketModel`.
  - `TimelineRounds::__construct(TimelineSources $sources, int $accountConcurrency, int $hostConcurrency)`,
    `start(): TimelineRound`. Validation messages: `Per-account concurrency must be at least 1, got N.` and
    `Per-server concurrency must be at least 1, got N.`
  - `TimelineRound::__construct(TimelineSources $sources, TimelineAccounts $accounts, TimelineSlotsModel $slots)`.
  - `ConcurrentTimelineFetcher::__construct(ConcurrentRequestEngine $engine, TimelineRounds $rounds)` (deleted in
    Task 5).
  - Tests: `FeedTickets::of(string $url, ?string $etag = null, ?string $lastModified = null)` (host capacity 100),
    `FeedTickets::cappedAt(string $url, int $hostCapacity)`,
    `SocialApiWiring::timelineRounds(HttpClientInterface $http, ArrayAdapter $cache, int $accountConcurrency = 3)`.

- [ ] **Step 0: Merge develop**

From the repo root: `git status && git fetch origin develop && git merge --no-edit origin/develop`. Then
`php bin/phpunit tests/Service/Fetch` (from `backend/`) must pass.

- [ ] **Step 1: Write the failing model and slot tests**

Create `tests/Service/Fetch/Model/RequestSlotModelTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Fetch\Model;

use App\Service\Fetch\Model\RequestSlotModel;
use PHPUnit\Framework\TestCase;

final class RequestSlotModelTest extends TestCase
{
    public function testANamedSlotsKeyIsItsNameWhateverTheUrl(): void
    {
        $slot = RequestSlotModel::named('social-account:7', 3);

        self::assertSame('social-account:7', $slot->keyFor('https://one.example.com/a'));
        self::assertSame('social-account:7', $slot->keyFor('https://two.example.com/b'));
    }

    public function testAPerHostSlotsKeyIsTheHostTheUrlHasReached(): void
    {
        $slot = RequestSlotModel::perHost(2);

        self::assertSame('host:example.com', $slot->keyFor('https://www.example.com:443/feed'));
        self::assertSame('host:other.example.com', $slot->keyFor('https://other.example.com/feed'));
    }

    public function testCarriesItsCapacity(): void
    {
        self::assertSame(4, RequestSlotModel::named('social-host:example.com', 4)->capacity);
        self::assertSame(2, RequestSlotModel::perHost(2)->capacity);
    }

    public function testRejectsACapacityBelowOne(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Slot capacity must be at least 1, got 0.');

        RequestSlotModel::perHost(0);
    }
}
```

`tests/Service/Fetch/Model/RequestTicketModelTest.php`: add `use App\Service\Fetch\Model\RequestSlotModel;`. Every
`RequestTicketModel::feed(new FetchTicketModel(…))` gets a second argument `2`. Replace
`testAFeedTicketsSlotIsTheHostOfTheUrlItHasReached`, `testANamedSlotIgnoresTheHostAndNeverMeetsAHostSlot` and
`testASlotKeyNamesWhetherItIsAHostOrANamedSlot` with:

```php
    public function testAFeedTicketHoldsOneSlotOnTheHostItsAttemptHasReached(): void
    {
        $ticket = RequestTicketModel::feed(new FetchTicketModel('https://www.example.com/feed'), 2);

        self::assertCount(1, $ticket->slots);
        self::assertSame(2, $ticket->slots[0]->capacity);
        self::assertSame('host:example.com', $ticket->slots[0]->keyFor('https://www.example.com:443/b'));
        self::assertSame('host:other.example.com', $ticket->slots[0]->keyFor('https://other.example.com/a'));
    }

    public function testAnApiTicketHoldsEverySlotItNamesInOrder(): void
    {
        $account = RequestSlotModel::named('social-account:7', 3);
        $server = RequestSlotModel::named('social-host:example.com', 4);

        $ticket = RequestTicketModel::api(ApiRequestModel::get('https://example.com/a'), [$account, $server]);

        self::assertSame([$account, $server], $ticket->slots);
    }
```

In `testAnApiTicketIsReadAsAnAnswer` and `testAnApiTicketCarriesNoFeedTicket`, replace the `'account-7'` argument
with `[RequestSlotModel::named('account-7', 3)]`.

Replace `tests/Service/Fetch/Pass/RequestSlotsTest.php` with:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Fetch\Pass;

use App\Service\Fetch\Model\ApiRequestModel;
use App\Service\Fetch\Model\FetchAttemptModel;
use App\Service\Fetch\Model\RequestSlotModel;
use App\Service\Fetch\Model\RequestTicketModel;
use App\Service\Fetch\Pass\RequestSlots;
use App\Tests\Support\FeedTickets;
use PHPUnit\Framework\TestCase;

final class RequestSlotsTest extends TestCase
{
    private static function attempt(string $url, int $hostCapacity = 2): FetchAttemptModel
    {
        return FetchAttemptModel::start(1, FeedTickets::cappedAt($url, $hostCapacity));
    }

    /** @param non-empty-list<RequestSlotModel> $slots */
    private static function apiAttempt(string $url, array $slots): FetchAttemptModel
    {
        return FetchAttemptModel::start(1, RequestTicketModel::api(ApiRequestModel::get($url), $slots));
    }

    public function testAFreshHostHasCapacity(): void
    {
        $slots = new RequestSlots();

        self::assertTrue($slots->hasCapacityFor(self::attempt('https://example.com/feed')));
    }

    public function testCapacityRemainsUntilTheCapIsReached(): void
    {
        $slots = new RequestSlots();
        $attempt = self::attempt('https://example.com/feed');

        $slots->acquire($attempt);
        self::assertTrue($slots->hasCapacityFor($attempt), 'one of two slots taken');

        $slots->acquire($attempt);
        self::assertFalse($slots->hasCapacityFor($attempt), 'both slots taken');
    }

    public function testReleasingASlotRestoresCapacity(): void
    {
        $slots = new RequestSlots();
        $attempt = self::attempt('https://example.com/feed');

        $slots->acquire($attempt);
        $slots->acquire($attempt);
        $slots->release($attempt);

        self::assertTrue($slots->hasCapacityFor($attempt));
    }

    public function testASingleReleaseFreesExactlyOneSlot(): void
    {
        $slots = new RequestSlots();
        $attempt = self::attempt('https://example.com/feed');

        $slots->acquire($attempt);
        $slots->acquire($attempt);
        $slots->release($attempt);
        $slots->acquire($attempt);

        self::assertFalse($slots->hasCapacityFor($attempt));
    }

    public function testReleasingAnIdleSlotBanksNoCapacity(): void
    {
        $slots = new RequestSlots();
        $attempt = self::attempt('https://example.com/feed', 1);

        $slots->release($attempt);
        $slots->acquire($attempt);

        self::assertFalse($slots->hasCapacityFor($attempt));
    }

    public function testHostsAreCountedIndependently(): void
    {
        $slots = new RequestSlots();
        $one = self::attempt('https://one.example.com/feed', 1);
        $two = self::attempt('https://two.example.com/feed', 1);

        $slots->acquire($one);

        self::assertFalse($slots->hasCapacityFor($one));
        self::assertTrue($slots->hasCapacityFor($two));
    }

    public function testNormalisedHostsShareTheSameSlots(): void
    {
        $slots = new RequestSlots();

        $slots->acquire(self::attempt('https://www.example.com:443/feed', 1));

        self::assertFalse($slots->hasCapacityFor(self::attempt('https://example.com/other', 1)));
    }

    public function testANamedSlotIsSharedWhateverTheHost(): void
    {
        $slots = new RequestSlots();

        $slots->acquire(self::apiAttempt('https://one.example.com/a', [RequestSlotModel::named('account-7', 1)]));

        self::assertFalse(
            $slots->hasCapacityFor(self::apiAttempt('https://two.example.com/b', [RequestSlotModel::named('account-7', 1)])),
        );
        self::assertTrue(
            $slots->hasCapacityFor(self::apiAttempt('https://one.example.com/a', [RequestSlotModel::named('account-8', 1)])),
        );
    }

    public function testANamedSlotNeverTakesAHostsCapacity(): void
    {
        $slots = new RequestSlots();

        $slots->acquire(self::apiAttempt('https://example.com/a', [RequestSlotModel::named('example.com', 1)]));

        self::assertTrue($slots->hasCapacityFor(self::attempt('https://example.com/feed', 1)));
    }

    public function testATicketWaitsWhileAnyOfItsSlotsIsFull(): void
    {
        $slots = new RequestSlots();
        $slots->acquire(self::apiAttempt('https://a.example/x', [
            RequestSlotModel::named('account:1', 3),
            RequestSlotModel::named('server:a', 1),
        ]));

        self::assertFalse($slots->hasCapacityFor(self::apiAttempt('https://a.example/y', [
            RequestSlotModel::named('account:2', 3),
            RequestSlotModel::named('server:a', 1),
        ])), 'its account has room, its server has none');
        self::assertTrue($slots->hasCapacityFor(self::apiAttempt('https://b.example/y', [
            RequestSlotModel::named('account:1', 3),
            RequestSlotModel::named('server:b', 1),
        ])), 'its account and its server both have room');
    }

    public function testReleasingATicketFreesEverySlotItHeld(): void
    {
        $slots = new RequestSlots();
        $held = self::apiAttempt('https://a.example/x', [
            RequestSlotModel::named('account:1', 1),
            RequestSlotModel::named('server:a', 1),
        ]);
        $slots->acquire($held);
        $slots->release($held);

        self::assertTrue(
            $slots->hasCapacityFor(self::apiAttempt('https://a.example/y', [RequestSlotModel::named('account:1', 1)])),
        );
        self::assertTrue(
            $slots->hasCapacityFor(self::apiAttempt('https://a.example/z', [RequestSlotModel::named('server:a', 1)])),
        );
    }

    public function testEachSlotIsCappedAtItsOwnCapacity(): void
    {
        $slots = new RequestSlots();
        $roomy = [RequestSlotModel::named('account:1', 2)];
        $slots->acquire(self::apiAttempt('https://a.example/x', $roomy));

        self::assertTrue($slots->hasCapacityFor(self::apiAttempt('https://a.example/y', $roomy)));
        self::assertFalse(
            $slots->hasCapacityFor(self::apiAttempt('https://a.example/y', [RequestSlotModel::named('account:1', 1)])),
        );
    }
}
```

The old `testRejectsACapBelowOne` moved to `RequestSlotModelTest`. Wrap any line over 120 columns.

`tests/Support/FeedTickets.php` becomes:

```php
/** The engine ticket for a feed URL, as ConcurrentFeedFetcher maps it. */
final class FeedTickets
{
    private const int UNCAPPED = 100;

    private function __construct()
    {
    }

    public static function of(string $url, ?string $etag = null, ?string $lastModified = null): RequestTicketModel
    {
        return RequestTicketModel::feed(new FetchTicketModel($url, $etag, $lastModified), self::UNCAPPED);
    }

    public static function cappedAt(string $url, int $hostCapacity): RequestTicketModel
    {
        return RequestTicketModel::feed(new FetchTicketModel($url), $hostCapacity);
    }
}
```

`tests/Service/Fetch/Pass/FetchQueueTest.php`:
- `queue()`: `$slots ?? new RequestSlots(100)` becomes `$slots ?? new RequestSlots()`.
- `testTheTicketSourceIsNotAdvancedUntilTheNextItemIsWanted`: `new RequestSlots(100)` becomes `new RequestSlots()`.
- The three tests that build `new RequestSlots(1)` (`testParksATicketWhoseHostIsFullAndServesAFreeHostFirst`,
  `testAParkedTicketIsServedOnceItsHostFrees`, `testLookAheadPastAFullHostIsBounded`) build `new RequestSlots()`, and
  each **busy-host** ticket they queue becomes `FeedTickets::cappedAt(<same url>, 1)`. The in-flight `attempt()` they
  acquire stays as it is: only its key counts. The free-host ticket stays `FeedTickets::of(…)`.

- [ ] **Step 2: Run them to verify they fail**

Run: `php bin/phpunit --filter 'RequestSlotModelTest|RequestTicketModelTest|RequestSlotsTest|FetchQueueTest'`
Expected: FAIL / error: `Class "App\Service\Fetch\Model\RequestSlotModel" not found`, and `feed()` called with too
many arguments.

- [ ] **Step 3: Write the slot model, the ticket and the slots**

Create `src/Service/Fetch/Model/RequestSlotModel.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Fetch\Model;

use App\Service\Fetch\Support\CapacityGuard;
use App\Service\Fetch\Support\HostKey;

/** One cap a ticket holds while in flight: a named slot, or the host of whatever URL its attempt has reached. */
final readonly class RequestSlotModel
{
    private function __construct(private ?string $name, public int $capacity)
    {
        CapacityGuard::atLeastOne($capacity, 'Slot capacity');
    }

    public static function named(string $name, int $capacity): self
    {
        return new self($name, $capacity);
    }

    public static function perHost(int $capacity): self
    {
        return new self(null, $capacity);
    }

    public function keyFor(string $url): string
    {
        return $this->name ?? 'host:' . HostKey::forUrl($url);
    }
}
```

`src/Service/Fetch/Model/RequestTicketModel.php`. Current constructor, factories and slot key:

```php
    private function __construct(
        public ResponseExchangeKind $exchange,
        public ApiRequestModel $request,
        private ?string $slotName,
        private ?FetchTicketModel $feedTicket,
    ) {
    }

    public static function feed(FetchTicketModel $ticket): self
…
    /** Any status comes back as the answer; tickets sharing a slot name share its capacity, whatever their host. */
    public static function api(ApiRequestModel $request, string $slotName): self
…
    public function slotKeyFor(string $url): string
```

become (`use App\Service\Fetch\Support\HostKey;` goes, `validatorHeaders()` and `feedTicket()` stay):

```php
/** One request the concurrent engine sends: what goes on the wire, which exchange reads the answer, what it holds. */
final readonly class RequestTicketModel
{
    /** @param non-empty-list<RequestSlotModel> $slots */
    private function __construct(
        public ResponseExchangeKind $exchange,
        public ApiRequestModel $request,
        public array $slots,
        private ?FetchTicketModel $feedTicket,
    ) {
    }

    public static function feed(FetchTicketModel $ticket, int $hostCapacity): self
    {
        return new self(
            ResponseExchangeKind::Feed,
            ApiRequestModel::get($ticket->url, self::validatorHeaders($ticket)),
            [RequestSlotModel::perHost($hostCapacity)],
            $ticket,
        );
    }

    /**
     * Any status comes back as the answer; the ticket goes out only while every slot it names has room.
     *
     * @param non-empty-list<RequestSlotModel> $slots
     */
    public static function api(ApiRequestModel $request, array $slots): self
    {
        return new self(ResponseExchangeKind::Api, $request, $slots, null);
    }
```

Delete `slotKeyFor()`.

`src/Service/Fetch/Pass/RequestSlots.php` becomes:

```php
<?php

declare(strict_types=1);

namespace App\Service\Fetch\Pass;

use App\Service\Fetch\Model\FetchAttemptModel;
use App\Service\Fetch\Model\RequestSlotModel;

/**
 * In-flight requests per slot key. A ticket goes out only while every slot it names has room, so it waits for a full
 * slot instead of earning a 429, and it holds all of them until it retires.
 */
final class RequestSlots
{
    /** @var array<string, int> */
    private array $inFlightBySlot = [];

    public function hasCapacityFor(FetchAttemptModel $attempt): bool
    {
        foreach ($attempt->ticket->slots as $slot) {
            if ($this->inFlightIn($slot->keyFor($attempt->url)) >= $slot->capacity) {
                return false;
            }
        }

        return true;
    }

    public function acquire(FetchAttemptModel $attempt): void
    {
        foreach ($this->keysOf($attempt) as $key) {
            $this->inFlightBySlot[$key] = $this->inFlightIn($key) + 1;
        }
    }

    public function release(FetchAttemptModel $attempt): void
    {
        foreach ($this->keysOf($attempt) as $key) {
            $this->inFlightBySlot[$key] = max(0, $this->inFlightIn($key) - 1);
        }
    }

    private function inFlightIn(string $key): int
    {
        return $this->inFlightBySlot[$key] ?? 0;
    }

    /** @return list<string> */
    private function keysOf(FetchAttemptModel $attempt): array
    {
        return array_map(
            static fn (RequestSlotModel $slot): string => $slot->keyFor($attempt->url),
            $attempt->ticket->slots,
        );
    }
}
```

- [ ] **Step 4: Run them to verify they pass**

Run: `php bin/phpunit --filter 'RequestSlotModelTest|RequestTicketModelTest|RequestSlotsTest|FetchQueueTest'`
Expected: PASS.

- [ ] **Step 5: Write the failing engine test**

`tests/Service/Fetch/ConcurrentRequestEngineTest.php`: add `use App\Service\Fetch\Model\RequestSlotModel;`. The helper
`ticket()` becomes:

```php
    private static function ticket(
        string $url = self::API,
        string $slotName = 'account-1',
        int $capacity = 3,
    ): RequestTicketModel {
        return RequestTicketModel::api(
            ApiRequestModel::get($url, ['Authorization' => 'Bearer jwt-1']),
            [RequestSlotModel::named($slotName, $capacity)],
        );
    }
```

Then, in the whole file (`grep -n "sendAll(\|RequestTicketModel::api(" tests/Service/Fetch/ConcurrentRequestEngineTest.php`):
- every `->sendAll(<tickets>, 3)` becomes `->sendAll(<tickets>)`, and `->sendAll(<tickets>, 3, $gate)` becomes
  `->sendAll(<tickets>, $gate)`; that includes `onlyAnswer()` and the two tests Task 1 added;
- `testANamedSlotCapsItsRequestsWhateverTheirHost`: its three tickets become `self::ticket(self::API, 'account-1', 1)`,
  `self::ticket(self::OTHER, 'account-1', 1)`, `self::ticket(self::API, 'account-1', 1)`, and `sendAll([...], 1)`
  becomes `sendAll([...])`;
- `testTheGateDecidesWhenAParkedTicketWouldGoOut`: `sendAll([1 => self::ticket(), 2 => self::ticket()], 1, $gate)`
  becomes `sendAll([1 => self::ticket(capacity: 1), 2 => self::ticket(capacity: 1)], $gate)`;
- the two `RequestTicketModel::api($request, 'account-1')` / `RequestTicketModel::api(ApiRequestModel::get(…), 'account-1')`
  calls take `[RequestSlotModel::named('account-1', 3)]` instead of `'account-1'`.

Add:

```php
    public function testATicketGoesOutOnlyWhileEverySlotItNamesHasRoom(): void
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
        $onTheSharedServer = static fn (string $account): RequestTicketModel => RequestTicketModel::api(
            ApiRequestModel::get(self::API),
            [RequestSlotModel::named($account, 3), RequestSlotModel::named('server', 1)],
        );

        $outcomes = self::collect($this->engine($client)->sendAll([
            1 => $onTheSharedServer('account-1'),
            2 => $onTheSharedServer('account-2'),
            3 => $onTheSharedServer('account-3'),
        ]));

        self::assertCount(3, $outcomes, 'every slot a ticket held is released when it retires');
        self::assertSame(1, $peak, 'three accounts with room share one full server slot');
    }
```

- [ ] **Step 6: Run it to verify it fails**

Run: `php bin/phpunit tests/Service/Fetch/ConcurrentRequestEngineTest.php`
Expected: FAIL / error: `sendAll()` still demands its `int $slotCapacity` (a `TypeError`: a `DispatchGateInterface`
or nothing where an int is expected).

- [ ] **Step 7: Change the engine and the RSS fetcher**

`ConcurrentRequestEngine::sendAll()`. Current:

```php
    /**
     * A failed request arrives as a failed outcome, never a throw, so one bad ticket cannot abandon the others;
     * abandoning the generator cancels whatever is still in flight. A ticket the gate refuses is withheld.
     *
     * @param iterable<int|string, RequestTicketModel> $tickets
     *
     * @return \Generator<int|string, FetchOutcomeModel>
     */
    public function sendAll(iterable $tickets, int $slotCapacity, ?DispatchGateInterface $gate = null): \Generator
…
        $queue = new FetchQueue(
            $this->iterator($tickets),
            new RequestSlots($slotCapacity),
            $lookAhead,
            $batchProxy,
        );
```

becomes:

```php
    /**
     * A failed request arrives as a failed outcome, never a throw, so one bad ticket cannot abandon the others;
     * abandoning the generator cancels whatever is still in flight. A ticket goes out only while every slot it names
     * has room. A ticket the gate refuses is withheld.
     *
     * @param iterable<int|string, RequestTicketModel> $tickets
     *
     * @return \Generator<int|string, FetchOutcomeModel>
     */
    public function sendAll(iterable $tickets, ?DispatchGateInterface $gate = null): \Generator
…
        $queue = new FetchQueue(
            $this->iterator($tickets),
            new RequestSlots(),
            $lookAhead,
            $batchProxy,
        );
```

`ConcurrentFeedFetcher.php`. Current `fetchAll()` returns
`$this->engine->sendAll($this->requestTickets($tickets), $this->hostConcurrency);` and `requestTickets()` yields
`RequestTicketModel::feed($ticket)`. They become `return $this->engine->sendAll($this->requestTickets($tickets));` and
`yield $key => RequestTicketModel::feed($ticket, $this->hostConcurrency);`. The constructor and its `CapacityGuard`
stay.

- [ ] **Step 8: Run the Fetch tests**

Run: `php bin/phpunit tests/Service/Fetch`
Expected: PASS, the host-cap tests in `ConcurrentFeedFetcherTest` (`testCapsConcurrentRequestsToOneHost`,
`testAPerHostCapOfOneSerialisesTheHost`, `testAHostAtCapacityDoesNotBlockOtherHosts`) included: RSS host caps are
unchanged.

- [ ] **Step 9: Write the failing timeline-slot test**

Create `tests/Service/Social/TimelineRoundsTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Social;

use App\Entity\Feed;
use App\Entity\SocialFeed;
use App\Enum\SocialFeedKind;
use App\Service\Fetch\Model\RequestTicketModel;
use App\Service\Social\TimelineRounds;
use App\Tests\Support\AssignsEntityIds;
use App\Tests\Support\SocialAccounts;
use App\Tests\Support\SocialApiWiring;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;

final class TimelineRoundsTest extends TestCase
{
    use AssignsEntityIds;

    private const string HOME = 'https://mastodon.example/api/v1/timelines/home?limit=40';

    public function testATimelineTicketHoldsItsAccountsSlotAndItsServersSlot(): void
    {
        $rounds = SocialApiWiring::timelineRounds(new MockHttpClient(), new ArrayAdapter());
        $account = self::withId(SocialAccounts::mastodonOn('https://mastodon.example'), 7);
        $socialFeed = new SocialFeed(
            $account,
            self::withId(new Feed('social:mastodon:7:home'), 1),
            SocialFeedKind::Home,
            '',
        );

        $tickets = iterator_to_array($rounds->start()->tickets([1 => $socialFeed]));

        self::assertSame(
            ['social-account:7' => 3, 'social-host:mastodon.example' => 4],
            self::capacitiesByKey($tickets[1]),
        );
    }

    public function testRejectsAnAccountConcurrencyBelowOne(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Per-account concurrency must be at least 1, got 0.');

        new TimelineRounds(SocialApiWiring::timelineSources(new MockHttpClient(), new ArrayAdapter()), 0, 4);
    }

    public function testRejectsAServerConcurrencyBelowOne(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Per-server concurrency must be at least 1, got 0.');

        new TimelineRounds(SocialApiWiring::timelineSources(new MockHttpClient(), new ArrayAdapter()), 3, 0);
    }

    /** @return array<string, int> */
    private static function capacitiesByKey(RequestTicketModel $ticket): array
    {
        $capacities = [];
        foreach ($ticket->slots as $slot) {
            $capacities[$slot->keyFor(self::HOME)] = $slot->capacity;
        }

        return $capacities;
    }
}
```

`tests/Support/SocialApiWiring.php`: add `use App\Service\Social\TimelineRounds;` and:

```php
    public static function timelineRounds(
        HttpClientInterface $http,
        ArrayAdapter $cache,
        int $accountConcurrency = 3,
    ): TimelineRounds {
        return new TimelineRounds(self::timelineSources($http, $cache), $accountConcurrency, 4);
    }
```

and `timelineFetcher()` becomes:

```php
    /** The timeline fetcher over the real engine; every test host resolves to one public address. */
    public static function timelineFetcher(
        HttpClientInterface $http,
        ArrayAdapter $cache,
        int $accountConcurrency = 3,
    ): ConcurrentTimelineFetcher {
        $guard = self::guardFor(self::BLUESKY_HOST, self::MASTODON_HOST, self::OTHER_MASTODON_HOST);

        return new ConcurrentTimelineFetcher(
            FetchWiring::requestEngine($http, $guard, FetchWiring::egressProxy(null), 8),
            self::timelineRounds($http, $cache, $accountConcurrency),
        );
    }
```

`tests/Service/Social/Pass/TimelineRoundTest.php`: add `use App\Service\Social\Model\TimelineSlotsModel;`, and
`new TimelineRound($sources, new TimelineAccounts())` becomes
`new TimelineRound($sources, new TimelineAccounts(), new TimelineSlotsModel(3, 4))`.

- [ ] **Step 10: Run it to verify it fails**

Run: `php bin/phpunit --filter 'TimelineRoundsTest|TimelineRoundTest'`
Expected: error, `Class "App\Service\Social\TimelineRounds" not found`.

- [ ] **Step 11: Write the timeline slots and the round factory**

Create `src/Service/Social/Model/TimelineSlotsModel.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Social\Model;

use App\Entity\SocialAccount;
use App\Service\Fetch\Model\ApiRequestModel;
use App\Service\Fetch\Model\RequestSlotModel;
use App\Service\Fetch\Model\RequestTicketModel;
use App\Service\Fetch\Support\HostKey;

/**
 * A timeline read holds its account's slot and its server's. The server slot is not an RSS host slot: a feed and a
 * timeline on one host never count against each other.
 */
final readonly class TimelineSlotsModel
{
    public function __construct(private int $perAccount, private int $perHost)
    {
    }

    public function ticketFor(SocialAccount $account, ApiRequestModel $request): RequestTicketModel
    {
        return RequestTicketModel::api($request, [
            RequestSlotModel::named(sprintf('social-account:%d', $account->requireId()), $this->perAccount),
            RequestSlotModel::named('social-host:' . HostKey::forUrl($request->url), $this->perHost),
        ]);
    }
}
```

Create `src/Service/Social/TimelineRounds.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Social;

use App\Service\Fetch\Support\CapacityGuard;
use App\Service\Social\Model\TimelineSlotsModel;
use App\Service\Social\Pass\TimelineAccounts;
use App\Service\Social\Pass\TimelineRound;

/** Starts one refresh's private-feed round, its reads capped per account and per Mastodon instance or Bluesky PDS. */
final readonly class TimelineRounds
{
    public function __construct(
        private TimelineSources $sources,
        private int $accountConcurrency,
        private int $hostConcurrency,
    ) {
        CapacityGuard::atLeastOne($accountConcurrency, 'Per-account concurrency');
        CapacityGuard::atLeastOne($hostConcurrency, 'Per-server concurrency');
    }

    public function start(): TimelineRound
    {
        return new TimelineRound(
            $this->sources,
            new TimelineAccounts(),
            new TimelineSlotsModel($this->accountConcurrency, $this->hostConcurrency),
        );
    }
}
```

`src/Service/Social/Pass/TimelineRound.php`: add `use App\Service\Social\Model\TimelineSlotsModel;`. The constructor
becomes:

```php
    public function __construct(
        private readonly TimelineSources $sources,
        private readonly TimelineAccounts $accounts,
        private readonly TimelineSlotsModel $slots,
    ) {
    }
```

and the last line of `ticketOf()`,
`yield $feedId => RequestTicketModel::api($request, sprintf('social-account-%d', $account->requireId()));`, becomes
`yield $feedId => $this->slots->ticketFor($account, $request);`. Drop the now unused `RequestTicketModel` import only
if PHPStan or phpcs says it is unused (it is still named in the `@return` docblocks, so keep it).

`src/Service/Social/TimelineFetcher/ConcurrentTimelineFetcher.php`. Current:

```php
    public function __construct(
        private ConcurrentRequestEngine $engine,
        private TimelineSources $sources,
        private int $accountConcurrency,
    ) {
        CapacityGuard::atLeastOne($accountConcurrency, 'Per-account concurrency');
    }

    /** @return \Generator<int|string, TimelineOutcomeModelInterface> */
    public function fetchAll(iterable $socialFeeds): \Generator
    {
        $round = new TimelineRound($this->sources, new TimelineAccounts());
…
        foreach ($this->engine->sendAll($tickets, $this->accountConcurrency, $round) as $feedId => $outcome) {
```

becomes (imports of `CapacityGuard`, `TimelineAccounts` and `TimelineSources` go, `TimelineRounds` comes):

```php
    public function __construct(private ConcurrentRequestEngine $engine, private TimelineRounds $rounds)
    {
    }

    /** @return \Generator<int|string, TimelineOutcomeModelInterface> */
    public function fetchAll(iterable $socialFeeds): \Generator
    {
        $round = $this->rounds->start();
…
        foreach ($this->engine->sendAll($tickets, $round) as $feedId => $outcome) {
```

Its class docblock: replace "`accountConcurrency` at most per account" with "capped per account and per server".

`config/services.yaml`, `parameters:` — after `social_account_concurrency: 3` add:

```yaml
    # In-flight timeline reads per Mastodon instance or Bluesky PDS, all its accounts together: half the default
    # FETCH_CONCURRENCY, so one server can never hold a whole refresh (#1608).
    social_host_concurrency: 4
```

and under `services:` replace

```yaml
    App\Service\Social\TimelineFetcher\ConcurrentTimelineFetcher:
        arguments:
            $accountConcurrency: '%social_account_concurrency%'
```

with

```yaml
    App\Service\Social\TimelineRounds:
        arguments:
            $accountConcurrency: '%social_account_concurrency%'
            $hostConcurrency: '%social_host_concurrency%'
```

`docs/security.md`, the refresh worker bullet: "Timeline reads run concurrently, at most three in flight per account
(`social_account_concurrency`, #1607); a throttled…" becomes "Timeline reads run concurrently, at most three in
flight per account (`social_account_concurrency`, #1607) and four per Mastodon instance or Bluesky PDS across its
accounts (`social_host_concurrency`, #1608); a throttled…".

- [ ] **Step 12: Run the timeline and fetch tests**

Run: `bin/console cache:warmup && php bin/phpunit tests/Service/Fetch tests/Service/Social tests/Service/Refresh`
Expected: PASS. `ConcurrentTimelineFetcherTest::testRejectsAnAccountConcurrencyBelowOne` now throws from
`TimelineRounds`, with the same message.

- [ ] **Step 13: Gates and module direction**

Run: `composer check && composer md`, then the two greps from Global Constraints.
Expected: clean; both greps print nothing.

- [ ] **Step 14: Commit**

```bash
git add backend/src/Service/Fetch backend/src/Service/Social backend/config/services.yaml \
  backend/tests/Service/Fetch backend/tests/Service/Social backend/tests/Support/FeedTickets.php \
  backend/tests/Support/SocialApiWiring.php docs/security.md
git commit -m "feat(#1608): an engine ticket names its own slots, and a timeline holds its server's too"
```

---

### Task 3: The per-pass fetcher over one engine run

Spec §2 and §3, production side. This task adds the seam and its engine implementation with full tests. Nothing in the
refresh uses it yet, so a reviewer can judge the mixed ticket stream on its own; Task 4 switches the refresh over.

The flow: `PassFeeds` is one pass's started feeds plus each private feed's `SocialFeed` row. `PassTickets` turns them
into one ticket stream in due order, gates only private tickets, and settles each landed answer by the kind of its
feed. `ConcurrentPassFetcher` runs it on the engine, then the stale-session renewal as a second small run.

**Files:**
- Modify: `backend/src/Service/Refresh/Pass/BudgetedFeedQueue.php` (add `startedFeeds()`)
- Modify: `backend/src/Service/Refresh/Pass/RefreshPass.php` (add `startedFeeds()`)
- Create: `backend/src/Service/Refresh/Pass/PassFeeds.php`
- Create: `backend/src/Service/Refresh/Pass/PassTickets.php`
- Create: `backend/src/Service/Refresh/PassFetcher/PassFetcherInterface.php`
- Create: `backend/src/Service/Refresh/PassFetcher/ConcurrentPassFetcher.php`
- Modify: `backend/config/services.yaml`
- Modify: `backend/tests/Support/SocialApiWiring.php`
- Modify: `backend/tests/Service/Refresh/Pass/BudgetedFeedQueueTest.php`
- Create: `backend/tests/Service/Refresh/Pass/PassFeedsTest.php`, `backend/tests/Service/Refresh/Pass/PassTicketsTest.php`
- Create: `backend/tests/Service/Refresh/PassFetcher/ConcurrentPassFetcherTest.php` (copied from
  `tests/Service/Social/TimelineFetcher/ConcurrentTimelineFetcherTest.php`, then extended)

**Interfaces:**
- Consumes (Task 2): `RequestTicketModel::feed(FetchTicketModel, int)`, `TimelineRounds::start(): TimelineRound`,
  `ConcurrentRequestEngine::sendAll(iterable, ?DispatchGateInterface)`, `SocialApiWiring::timelineRounds(...)`.
  Existing: `TimelineRound::tickets(iterable<int, SocialFeed>): \Generator<int|string, RequestTicketModel>`,
  `admits(int|string): bool`, `settle(int|string, FetchOutcomeModel): ?TimelineOutcomeModelInterface`,
  `drainUnasked(): \Generator<int|string, TimelineOutcomeModelInterface>`, `startRenewalWave(): \Generator`.
- Produces:
  - `BudgetedFeedQueue::startedFeeds(): \Generator<int, Feed, mixed, void>` and the same on `RefreshPass`.
  - `PassFeeds::__construct(RefreshPass $pass, array<int, SocialFeed> $socialFeedsByFeedId)`,
    `started(): \Generator<int, Feed, mixed, void>`, `isPrivate(int|string $feedId): bool`,
    `socialFeedOf(int $feedId): SocialFeed` (throws `PrivateFeedOrphanedException`).
  - `PassTickets::__construct(PassFeeds $feeds, TimelineRound $round, int $hostCapacity)` implements
    `DispatchGateInterface`; `tickets()`, `renewalTickets()`, `admits()`,
    `settle(int|string, FetchOutcomeModel): FetchOutcomeModel|TimelineOutcomeModelInterface|null`, `drainUnasked()`.
  - `PassFetcherInterface::fetchAll(PassFeeds $feeds): iterable<int|string, FetchOutcomeModel|TimelineOutcomeModelInterface>`.
  - `ConcurrentPassFetcher::__construct(ConcurrentRequestEngine $engine, TimelineRounds $timelineRounds, int $hostConcurrency)`.
  - Tests: `SocialApiWiring::RSS_HOST = 'news.example'`,
    `SocialApiWiring::passFetcher(HttpClientInterface $http, ArrayAdapter $cache, int $accountConcurrency = 3): ConcurrentPassFetcher`.

- [ ] **Step 0: Merge develop**

From the repo root: `git status && git fetch origin develop && git merge --no-edit origin/develop`. Then
`php bin/phpunit tests/Service/Fetch tests/Service/Social` must pass.

- [ ] **Step 1: Write the failing queue and pass-feeds tests**

`tests/Service/Refresh/Pass/BudgetedFeedQueueTest.php`, add:

```php
    public function testStartsEveryKindOfFeedInTheOrderItWasGiven(): void
    {
        $clock = new MockClock('2026-07-26 12:00:00', 'UTC');
        $timeline = self::withId(self::socialFeed('social:bluesky:1:home'), 2);
        $rss = self::withId(new Feed('https://feed1.example.com/rss'), 1);
        $queue = new BudgetedFeedQueue([$timeline, $rss], $clock, $clock->now()->getTimestamp() + 300);

        self::assertSame([2 => $timeline, 1 => $rss], iterator_to_array($queue->startedFeeds()));
        self::assertSame([2, 1], $queue->startedFeedIds());
    }

    public function testAFeedCountsAsStartedOnlyOnceItIsPulled(): void
    {
        $clock = new MockClock('2026-07-26 12:00:00', 'UTC');
        $queue = new BudgetedFeedQueue($this->feeds(3), $clock, $clock->now()->getTimestamp() + 300);

        $queue->startedFeeds()->current();

        self::assertSame([1], $queue->startedFeedIds());
    }

    public function testStartedFeedsStopOnceTheDeadlineIsWithinTheSafetyMargin(): void
    {
        $clock = new MockClock('2026-07-26 12:00:00', 'UTC');
        $queue = new BudgetedFeedQueue($this->feeds(3), $clock, $clock->now()->getTimestamp() + 100);

        foreach ($queue->startedFeeds() as $feed) {
            $clock->sleep(95);
        }

        self::assertSame(1, $queue->startedCount());
        self::assertSame(2, $queue->skippedCount());
    }
```

Create `tests/Service/Refresh/Pass/PassFeedsTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Refresh\Pass;

use App\Entity\Feed;
use App\Entity\SocialFeed;
use App\Enum\SocialFeedKind;
use App\Enum\SourceFormat;
use App\Service\Refresh\Pass\PassFeeds;
use App\Service\Refresh\Pass\RefreshPass;
use App\Service\Social\Exception\PrivateFeedOrphanedException;
use App\Tests\Support\AssignsEntityIds;
use App\Tests\Support\SocialAccounts;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

final class PassFeedsTest extends TestCase
{
    use AssignsEntityIds;

    public function testTellsAPrivateFeedFromAnRssFeed(): void
    {
        $rss = self::withId(new Feed('https://news.example/feed.xml'), 1);
        $timeline = self::privateFeed(2);

        $feeds = self::passFeeds([$rss, $timeline->getFeed()], [2 => $timeline]);

        self::assertFalse($feeds->isPrivate(1));
        self::assertTrue($feeds->isPrivate(2));
    }

    public function testFindsAPrivateFeedsSocialFeed(): void
    {
        $timeline = self::privateFeed(2);

        self::assertSame($timeline, self::passFeeds([$timeline->getFeed()], [2 => $timeline])->socialFeedOf(2));
    }

    public function testAPrivateFeedWithoutItsSocialFeedIsOrphaned(): void
    {
        $orphan = self::privateFeed(2)->getFeed();

        $this->expectException(PrivateFeedOrphanedException::class);

        self::passFeeds([$orphan], [])->socialFeedOf(2);
    }

    public function testStartsWhatThePassStarts(): void
    {
        $rss = self::withId(new Feed('https://news.example/feed.xml'), 1);
        $timeline = self::privateFeed(2);

        $started = iterator_to_array(self::passFeeds([$timeline->getFeed(), $rss], [2 => $timeline])->started());

        self::assertSame([2, 1], array_keys($started));
    }

    /**
     * @param list<Feed>             $feeds
     * @param array<int, SocialFeed> $socialFeedsByFeedId
     */
    private static function passFeeds(array $feeds, array $socialFeedsByFeedId): PassFeeds
    {
        $clock = new MockClock('2026-10-13 12:00:00', 'UTC');

        return new PassFeeds(
            new RefreshPass($feeds, $clock, $clock->now()->getTimestamp() + 300),
            $socialFeedsByFeedId,
        );
    }

    private static function privateFeed(int $feedId): SocialFeed
    {
        $feed = self::withId(new Feed(sprintf('social:mastodon:7:%d', $feedId)), $feedId);
        $feed->setSourceFormat(SourceFormat::MASTODON_API);

        return new SocialFeed(
            self::withId(SocialAccounts::mastodonOn('https://mastodon.example'), 7),
            $feed,
            SocialFeedKind::Home,
            '',
        );
    }
}
```

- [ ] **Step 2: Run them to verify they fail**

Run: `php bin/phpunit --filter 'BudgetedFeedQueueTest|PassFeedsTest'`
Expected: error, `Call to undefined method …BudgetedFeedQueue::startedFeeds()` and
`Class "App\Service\Refresh\Pass\PassFeeds" not found`.

- [ ] **Step 3: Write `startedFeeds()` and `PassFeeds`**

`BudgetedFeedQueue.php`, add after `socialFeeds()` (Task 5 folds `startedAmong()` into it):

```php
    /** @return \Generator<int, Feed, mixed, void> every kind of feed, in the order the pass was given */
    public function startedFeeds(): \Generator
    {
        yield from $this->startedAmong(static fn (): bool => true);
    }
```

`RefreshPass.php`, add after `socialFeeds()`:

```php
    /** @return \Generator<int, Feed, mixed, void> */
    public function startedFeeds(): \Generator
    {
        return $this->queue->startedFeeds();
    }
```

Create `src/Service/Refresh/Pass/PassFeeds.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Refresh\Pass;

use App\Entity\Feed;
use App\Entity\SocialFeed;
use App\Enum\SourceFormat;
use App\Service\Social\Exception\PrivateFeedOrphanedException;

/** One pass's feeds as its budget starts them, and the social feed behind each private one. */
final readonly class PassFeeds
{
    /** @param array<int, SocialFeed> $socialFeedsByFeedId each with its account loaded */
    public function __construct(private RefreshPass $pass, private array $socialFeedsByFeedId)
    {
    }

    /** @return \Generator<int, Feed, mixed, void> */
    public function started(): \Generator
    {
        return $this->pass->startedFeeds();
    }

    public function isPrivate(int|string $feedId): bool
    {
        return SourceFormat::isSocial($this->pass->feed($feedId)->getSourceFormat());
    }

    /** @throws PrivateFeedOrphanedException when the private feed has lost its social feed */
    public function socialFeedOf(int $feedId): SocialFeed
    {
        return $this->socialFeedsByFeedId[$feedId] ?? throw new PrivateFeedOrphanedException();
    }
}
```

- [ ] **Step 4: Run them to verify they pass**

Run: `php bin/phpunit --filter 'BudgetedFeedQueueTest|PassFeedsTest|RefreshPassTest'`
Expected: PASS.

- [ ] **Step 5: Write the failing ticket-stream test**

Create `tests/Service/Refresh/Pass/PassTicketsTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Refresh\Pass;

use App\Entity\Feed;
use App\Entity\SocialAccount;
use App\Entity\SocialFeed;
use App\Enum\SocialFeedKind;
use App\Enum\SourceFormat;
use App\Service\Fetch\Model\FetchOutcomeModel;
use App\Service\Fetch\Model\RequestTicketModel;
use App\Service\Fetch\Model\ResponseExchangeKind;
use App\Service\Refresh\Pass\PassFeeds;
use App\Service\Refresh\Pass\PassTickets;
use App\Service\Refresh\Pass\RefreshPass;
use App\Service\Social\Exception\PrivateFeedOrphanedException;
use App\Service\Social\Model\TimelineOutcome\AccountPausedTimelineModel;
use App\Service\Social\Model\TimelineOutcome\FailedTimelineModel;
use App\Tests\Support\AssignsEntityIds;
use App\Tests\Support\SocialAccounts;
use App\Tests\Support\SocialApiWiring;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;

final class PassTicketsTest extends TestCase
{
    use AssignsEntityIds;

    private const string HOME = 'https://mastodon.example/api/v1/timelines/home?limit=40';

    private RefreshPass $pass;

    public function testAnRssFeedBecomesAFeedTicketOnItsHostCarryingItsValidators(): void
    {
        $rss = self::withId(new Feed('https://news.example/feed.xml'), 1);
        $rss->recordCacheValidators('"v1"', 'Mon, 20 Jul 2026 08:30:00 GMT');

        $tickets = iterator_to_array($this->tickets([$rss], [])->tickets());

        self::assertSame(ResponseExchangeKind::Feed, $tickets[1]->exchange);
        self::assertSame('https://news.example/feed.xml', $tickets[1]->request->url);
        self::assertSame(
            ['If-None-Match' => '"v1"', 'If-Modified-Since' => 'Mon, 20 Jul 2026 08:30:00 GMT'],
            $tickets[1]->request->headers,
        );
        self::assertSame(['host:news.example' => 2], self::capacitiesByKey($tickets[1], $rss->getUrl()));
    }

    public function testAPrivateFeedBecomesItsTimelineTicketOnItsAccountAndServer(): void
    {
        $timeline = self::privateFeed(self::account(), 2);

        $tickets = iterator_to_array($this->tickets([$timeline->getFeed()], [$timeline])->tickets());

        self::assertSame(ResponseExchangeKind::Api, $tickets[2]->exchange);
        self::assertSame(
            ['social-account:7' => 3, 'social-host:mastodon.example' => 4],
            self::capacitiesByKey($tickets[2], self::HOME),
        );
    }

    public function testFeedsAreTicketedInTheOrderThePassStartsThemWhateverTheirKind(): void
    {
        $account = self::account();
        $home = self::privateFeed($account, 1);
        $rss = self::withId(new Feed('https://news.example/feed.xml'), 2);
        $list = self::privateFeed($account, 3, SocialFeedKind::UserList, '42');

        $tickets = $this->tickets([$home->getFeed(), $rss, $list->getFeed()], [$home, $list])->tickets();

        self::assertSame([1, 2, 3], array_keys(iterator_to_array($tickets)));
    }

    public function testAFeedCountsAsStartedOnlyWhenTheEngineWantsItsTicket(): void
    {
        $one = self::withId(new Feed('https://news.example/one.xml'), 1);
        $two = self::withId(new Feed('https://news.example/two.xml'), 2);

        $this->tickets([$one, $two], [])->tickets()->current();

        self::assertSame([1], $this->pass->startedFeedIds());
    }

    public function testAnOrphanSettlesWithoutATicketAndTheNextFeedStillStarts(): void
    {
        $orphan = self::privateFeed(self::account(), 1)->getFeed();
        $rss = self::withId(new Feed('https://news.example/feed.xml'), 2);
        $passTickets = $this->tickets([$orphan, $rss], []);

        $tickets = iterator_to_array($passTickets->tickets());
        $unasked = iterator_to_array($passTickets->drainUnasked());

        self::assertSame([2], array_keys($tickets));
        self::assertSame([1], array_keys($unasked));
        self::assertInstanceOf(FailedTimelineModel::class, $unasked[1]);
        self::assertInstanceOf(PrivateFeedOrphanedException::class, $unasked[1]->failure);
        self::assertSame([1, 2], $this->pass->startedFeedIds());
    }

    public function testAPausedAccountsFeedSettlesWithoutATicket(): void
    {
        $paused = self::account();
        $paused->markNeedsReconnect();
        $timeline = self::privateFeed($paused, 1);
        $passTickets = $this->tickets([$timeline->getFeed()], [$timeline]);

        self::assertSame([], iterator_to_array($passTickets->tickets()));
        self::assertInstanceOf(AccountPausedTimelineModel::class, iterator_to_array($passTickets->drainUnasked())[1]);
    }

    public function testTheGateAdmitsEveryRssFeedAndAsksTheRoundAboutAPrivateOne(): void
    {
        $account = self::account();
        $timeline = self::privateFeed($account, 1);
        $rss = self::withId(new Feed('https://news.example/feed.xml'), 2);
        $passTickets = $this->tickets([$timeline->getFeed(), $rss], [$timeline]);
        iterator_to_array($passTickets->tickets());

        $account->markNeedsReconnect();

        self::assertTrue($passTickets->admits(2));
        self::assertFalse($passTickets->admits(1));
    }

    public function testAnRssOutcomeIsPassedThroughAndAPrivateOneIsSettledByItsRound(): void
    {
        $account = self::account();
        $timeline = self::privateFeed($account, 1);
        $rss = self::withId(new Feed('https://news.example/feed.xml'), 2);
        $passTickets = $this->tickets([$timeline->getFeed(), $rss], [$timeline]);
        iterator_to_array($passTickets->tickets());
        $account->markNeedsReconnect();
        $rssOutcome = FetchOutcomeModel::withheld();

        self::assertSame($rssOutcome, $passTickets->settle(2, $rssOutcome));
        self::assertInstanceOf(AccountPausedTimelineModel::class, $passTickets->settle(1, FetchOutcomeModel::withheld()));
    }

    /**
     * @param list<Feed>       $feeds       in due order
     * @param list<SocialFeed> $socialFeeds the private feeds' rows
     */
    private function tickets(array $feeds, array $socialFeeds): PassTickets
    {
        $clock = new MockClock('2026-10-13 12:00:00', 'UTC');
        $this->pass = new RefreshPass($feeds, $clock, $clock->now()->getTimestamp() + 300);
        $socialFeedsByFeedId = [];
        foreach ($socialFeeds as $socialFeed) {
            $socialFeedsByFeedId[$socialFeed->getFeed()->requireId()] = $socialFeed;
        }
        $round = SocialApiWiring::timelineRounds(new MockHttpClient(), new ArrayAdapter())->start();

        return new PassTickets(new PassFeeds($this->pass, $socialFeedsByFeedId), $round, 2);
    }

    private static function account(): SocialAccount
    {
        return self::withId(SocialAccounts::mastodonOn('https://mastodon.example'), 7);
    }

    private static function privateFeed(
        SocialAccount $account,
        int $feedId,
        SocialFeedKind $kind = SocialFeedKind::Home,
        string $remoteId = '',
    ): SocialFeed {
        $feed = self::withId(new Feed(sprintf('social:mastodon:7:%d', $feedId)), $feedId);
        $feed->setSourceFormat(SourceFormat::MASTODON_API);

        return new SocialFeed($account, $feed, $kind, $remoteId);
    }

    /** @return array<string, int> */
    private static function capacitiesByKey(RequestTicketModel $ticket, string $url): array
    {
        $capacities = [];
        foreach ($ticket->slots as $slot) {
            $capacities[$slot->keyFor($url)] = $slot->capacity;
        }

        return $capacities;
    }
}
```

`FailedTimelineModel::$failure` is its public promoted `\RuntimeException` property.

- [ ] **Step 6: Run it to verify it fails**

Run: `php bin/phpunit tests/Service/Refresh/Pass/PassTicketsTest.php`
Expected: error, `Class "App\Service\Refresh\Pass\PassTickets" not found`.

- [ ] **Step 7: Write `PassTickets`**

Create `src/Service/Refresh/Pass/PassTickets.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Refresh\Pass;

use App\Service\Fetch\DispatchGate\DispatchGateInterface;
use App\Service\Fetch\Model\FetchOutcomeModel;
use App\Service\Fetch\Model\FetchTicketModel;
use App\Service\Fetch\Model\RequestTicketModel;
use App\Service\Social\Exception\PrivateFeedOrphanedException;
use App\Service\Social\Model\TimelineOutcome\FailedTimelineModel;
use App\Service\Social\Model\TimelineOutcome\TimelineOutcomeModelInterface;
use App\Service\Social\Pass\TimelineRound;

/**
 * One pass's engine tickets in due order: an RSS or Atom feed by its URL, a private feed through its round. Only a
 * held account's private tickets are withheld. A private feed that settles without a request waits until drained.
 */
final class PassTickets implements DispatchGateInterface
{
    /** @var array<int, TimelineOutcomeModelInterface> */
    private array $orphaned = [];

    public function __construct(
        private readonly PassFeeds $feeds,
        private readonly TimelineRound $round,
        private readonly int $hostCapacity,
    ) {
    }

    /** @return \Generator<int|string, RequestTicketModel> */
    public function tickets(): \Generator
    {
        foreach ($this->feeds->started() as $feedId => $feed) {
            if ($this->feeds->isPrivate($feedId)) {
                yield from $this->privateTicketOf($feedId);
                continue;
            }

            yield $feedId => RequestTicketModel::feed(
                new FetchTicketModel($feed->getUrl(), $feed->getEtag(), $feed->getLastModified()),
                $this->hostCapacity,
            );
        }
    }

    /** @return \Generator<int|string, RequestTicketModel> */
    public function renewalTickets(): \Generator
    {
        return $this->round->startRenewalWave();
    }

    public function admits(int|string $key): bool
    {
        return !$this->feeds->isPrivate($key) || $this->round->admits($key);
    }

    /** The feed's outcome, or null while a private feed waits for the renewal wave. */
    public function settle(
        int|string $feedId,
        FetchOutcomeModel $outcome,
    ): FetchOutcomeModel|TimelineOutcomeModelInterface|null {
        return $this->feeds->isPrivate($feedId) ? $this->round->settle($feedId, $outcome) : $outcome;
    }

    /** @return \Generator<int|string, TimelineOutcomeModelInterface> */
    public function drainUnasked(): \Generator
    {
        $orphaned = $this->orphaned;
        $this->orphaned = [];

        yield from $orphaned;
        yield from $this->round->drainUnasked();
    }

    /** @return \Generator<int|string, RequestTicketModel> */
    private function privateTicketOf(int $feedId): \Generator
    {
        try {
            $socialFeed = $this->feeds->socialFeedOf($feedId);
        } catch (PrivateFeedOrphanedException $orphaned) {
            $this->orphaned[$feedId] = new FailedTimelineModel($orphaned);

            return;
        }

        yield from $this->round->tickets([$feedId => $socialFeed]);
    }
}
```

- [ ] **Step 8: Run it to verify it passes**

Run: `php bin/phpunit tests/Service/Refresh/Pass`
Expected: PASS.

- [ ] **Step 9: Write the failing pass-fetcher test**

Copy the timeline fetcher's test, which stays until Task 5 deletes it:

```bash
mkdir -p tests/Service/Refresh/PassFetcher
cp tests/Service/Social/TimelineFetcher/ConcurrentTimelineFetcherTest.php \
  tests/Service/Refresh/PassFetcher/ConcurrentPassFetcherTest.php
```

In the copy:

1. The namespace becomes `App\Tests\Service\Refresh\PassFetcher`, the class `ConcurrentPassFetcherTest`. Replace
   `use App\Service\Social\TimelineFetcher\ConcurrentTimelineFetcher;` with:

   ```php
   use App\Enum\SocialNetwork;
   use App\Enum\SourceFormat;
   use App\Service\Fetch\IpValidator;
   use App\Service\Fetch\Model\FetchOutcomeModel;
   use App\Service\Fetch\Model\FetchResponseModel;
   use App\Service\Fetch\UrlGuard;
   use App\Service\Refresh\Pass\PassFeeds;
   use App\Service\Refresh\Pass\RefreshPass;
   use App\Service\Refresh\PassFetcher\ConcurrentPassFetcher;
   use App\Service\Social\Exception\PrivateFeedOrphanedException;
   use App\Tests\Support\FetchWiring;
   use App\Tests\Support\StaticDnsResolver;
   use Symfony\Component\Clock\MockClock;
   use Symfony\Component\HttpClient\MockHttpClient;
   ```

2. Add the constant `private const string NEWS = 'https://' . SocialApiWiring::RSS_HOST;`.

3. Replace the helpers `fetcher()`, `onTheWireAtFirstAnswer()`, `feedOf()`, `read()` and `byFeedId()` with:

   ```php
    /** @param \Closure(string, string, list<string>): MockResponse $answer by method, url and request headers */
    private function fetcher(\Closure $answer, int $accountConcurrency = 3): ConcurrentPassFetcher
    {
        $this->http = RoutedHttpClient::answering($answer);

        return SocialApiWiring::passFetcher(
            $this->recording($this->http->client()),
            $this->cache,
            $accountConcurrency,
        );
    }

    /** @param list<Feed|SocialFeed> $entries */
    private function onTheWireAtFirstAnswer(ConcurrentPassFetcher $fetcher, array $entries): ?int
    {
        foreach ($fetcher->fetchAll(self::passFeeds($entries)) as $outcome) {
            self::assertNotNull($outcome);

            return \count($this->http->requests);
        }

        return null;
    }

    private function feedOf(
        SocialAccount $account,
        SocialFeedKind $kind = SocialFeedKind::Home,
        string $remoteId = '',
    ): SocialFeed {
        $feedId = $this->nextFeedId++;
        $feed = self::withId(new Feed(sprintf('social:test:%d', $feedId)), $feedId);
        $feed->setSourceFormat(
            SocialNetwork::Bluesky === $account->getNetwork() ? SourceFormat::BLUESKY_API : SourceFormat::MASTODON_API,
        );

        return new SocialFeed($account, $feed, $kind, $remoteId);
    }

    private function rssFeed(string $url): Feed
    {
        $feedId = $this->nextFeedId++;

        return self::withId(new Feed($url), $feedId);
    }

    /**
     * @param list<Feed|SocialFeed> $entries
     *
     * @return array<int|string, FetchOutcomeModel|TimelineOutcomeModelInterface> in the order they landed
     */
    private static function read(ConcurrentPassFetcher $fetcher, array $entries): array
    {
        $outcomes = [];
        foreach ($fetcher->fetchAll(self::passFeeds($entries)) as $feedId => $outcome) {
            $outcomes[$feedId] = $outcome;
        }

        return $outcomes;
    }

    /** @param list<Feed|SocialFeed> $entries in due order: an RSS feed as itself, a private feed as its social feed */
    private static function passFeeds(array $entries): PassFeeds
    {
        $clock = new MockClock('2026-10-13 12:00:00', 'UTC');
        $feeds = [];
        $socialFeedsByFeedId = [];
        foreach ($entries as $entry) {
            $feed = $entry instanceof SocialFeed ? $entry->getFeed() : $entry;
            $feeds[] = $feed;
            if ($entry instanceof SocialFeed) {
                $socialFeedsByFeedId[$feed->requireId()] = $entry;
            }
        }

        return new PassFeeds(
            new RefreshPass($feeds, $clock, $clock->now()->getTimestamp() + 300),
            $socialFeedsByFeedId,
        );
    }

    private static function feedResponseOf(FetchOutcomeModel|TimelineOutcomeModelInterface $outcome): FetchResponseModel
    {
        self::assertInstanceOf(FetchOutcomeModel::class, $outcome);

        return $outcome->responseOrThrow();
    }

    private static function rss(): MockResponse
    {
        return new MockResponse('<rss/>');
    }
   ```

4. `pageOf()`, `delayOf()` and `failureOf()` take `FetchOutcomeModel|TimelineOutcomeModelInterface $outcome`; their
   bodies already assert the concrete class first.

5. In `testAbandoningTheReadSendsNothingMore` and `testAbandoningTheReadCancelsWhatIsInFlight`,
   `->fetchAll(self::byFeedId($socialFeeds))` becomes `->fetchAll(self::passFeeds($socialFeeds))`, and the
   `assertInstanceOf(FetchedTimelineModel::class, $outcome)` inside the loop stays.

6. `testRejectsAnAccountConcurrencyBelowOne` stays: `SocialApiWiring::timelineRounds()` throws it.

Then add the mixed-pass tests (spec "Done when"):

```php
    public function testRssAndPrivateTicketsAreOnTheWireBeforeTheFirstAnswerIsRead(): void
    {
        $respond = static fn (string $method, string $url): MockResponse => match (true) {
            str_starts_with($url, self::NEWS) => self::rss(),
            str_starts_with($url, self::PDS) => self::blueskyTimeline(),
            default => self::statuses(),
        };
        $entries = [
            $this->rssFeed(self::NEWS . '/one.xml'),
            $this->feedOf(self::mastodonAccount(self::MASTODON, 1)),
            $this->rssFeed(self::NEWS . '/two.xml'),
            $this->feedOf($this->blueskyAccount('jwt-1')),
        ];

        self::assertSame(4, $this->onTheWireAtFirstAnswer($this->fetcher($respond), $entries));
    }

    public function testAFeedAndATimelineOnOneHostNeverCountAgainstEachOther(): void
    {
        $respond = static fn (string $method, string $url): MockResponse => str_ends_with($url, '.rss')
            ? self::rss()
            : self::statuses();
        $first = self::mastodonAccount(self::MASTODON, 1);
        $second = self::mastodonAccount(self::MASTODON, 2);
        $entries = [
            $this->rssFeed(self::MASTODON . '/@bob.rss'),
            $this->rssFeed(self::MASTODON . '/@carol.rss'),
            $this->rssFeed(self::MASTODON . '/@dave.rss'),
            $this->feedOf($first),
            $this->feedOf($first, SocialFeedKind::UserList, '1'),
            $this->feedOf($second),
            $this->feedOf($second, SocialFeedKind::UserList, '2'),
        ];

        self::assertSame(
            6,
            $this->onTheWireAtFirstAnswer($this->fetcher($respond), $entries),
            'two feeds at the host cap and four timelines at the server cap',
        );
    }

    public function testTheServerCapHoldsAcrossAccountsWhileRssRunsAlongside(): void
    {
        $respond = static fn (string $method, string $url): MockResponse => str_starts_with($url, self::NEWS)
            ? self::rss()
            : self::statuses();
        $entries = [];
        foreach ([1, 2, 3] as $accountId) {
            $account = self::mastodonAccount(self::MASTODON, $accountId);
            $entries[] = $this->feedOf($account);
            $entries[] = $this->feedOf($account, SocialFeedKind::UserList, (string) $accountId);
        }
        $entries[] = $this->rssFeed(self::NEWS . '/one.xml');

        self::assertSame(
            5,
            $this->onTheWireAtFirstAnswer($this->fetcher($respond), $entries),
            'four timelines on one server, the feed behind them alongside',
        );
    }

    public function testAccountServerAndFeedHostCapsAllHoldThroughAMixedRun(): void
    {
        /** @var array<string, int> $inFlight */
        $inFlight = [];
        /** @var array<string, int> $peak */
        $peak = [];
        $respond = static function (string $method, string $url) use (&$inFlight, &$peak): MockResponse {
            $isFeed = str_ends_with($url, '.rss');
            $lane = ($isFeed ? 'feeds on ' : 'timelines on ') . (string) parse_url($url, \PHP_URL_HOST);
            $inFlight[$lane] = ($inFlight[$lane] ?? 0) + 1;
            $peak[$lane] = max($peak[$lane] ?? 0, $inFlight[$lane]);
            $body = (static function () use (&$inFlight, $lane, $isFeed): \Generator {
                yield $isFeed ? '<rss/>' : self::statusesBody();
                --$inFlight[$lane];
            })();

            return new MockResponse($body);
        };
        $busy = self::mastodonAccount(self::MASTODON, 1);
        $other = self::mastodonAccount(self::MASTODON, 2);
        $entries = [$this->feedOf($busy)];
        foreach (['1', '2', '3', '4'] as $listId) {
            $entries[] = $this->feedOf($busy, SocialFeedKind::UserList, $listId);
        }
        $entries[] = $this->feedOf($other);
        $entries[] = $this->feedOf($other, SocialFeedKind::UserList, '9');
        foreach (['bob', 'carol', 'dave', 'erin'] as $name) {
            $entries[] = $this->rssFeed(sprintf('%s/@%s.rss', self::MASTODON, $name));
        }

        self::assertCount(11, self::read($this->fetcher($respond), $entries));
        self::assertSame(4, $peak['timelines on mastodon.example']);
        self::assertSame(2, $peak['feeds on mastodon.example']);
    }

    public function testAThrottledAccountWithholdsOnlyItsOwnFeedsWhileRssKeepsRunning(): void
    {
        $respond = static fn (string $method, string $url): MockResponse => str_starts_with($url, self::NEWS)
            ? self::rss()
            : self::json(['error' => 'Too many requests'], Response::HTTP_TOO_MANY_REQUESTS, ['Retry-After' => '120']);
        $throttled = self::mastodonAccount(self::MASTODON, 1);
        $home = $this->feedOf($throttled);
        $one = $this->rssFeed(self::NEWS . '/one.xml');
        $friends = $this->feedOf($throttled, SocialFeedKind::UserList, '42');
        $two = $this->rssFeed(self::NEWS . '/two.xml');

        $outcomes = self::read($this->fetcher($respond, accountConcurrency: 1), [$home, $one, $friends, $two]);

        self::assertInstanceOf(ThrottledTimelineModel::class, $outcomes[self::idOf($home)]);
        self::assertInstanceOf(AccountThrottledTimelineModel::class, $outcomes[self::idOf($friends)]);
        foreach ([$one, $two] as $rss) {
            self::assertSame('<rss/>', self::feedResponseOf($outcomes[$rss->requireId()])->modifiedBody());
        }
        self::assertSame([self::MASTODON_HOME], $this->requestsTo(self::MASTODON));
    }

    public function testARefusedAccountWithholdsOnlyItsOwnFeedsWhileRssKeepsRunning(): void
    {
        $respond = static fn (string $method, string $url): MockResponse => str_starts_with($url, self::NEWS)
            ? self::rss()
            : self::json(['error' => 'The access token is invalid'], Response::HTTP_UNAUTHORIZED);
        $refused = self::mastodonAccount(self::MASTODON, 1);
        $home = $this->feedOf($refused);
        $one = $this->rssFeed(self::NEWS . '/one.xml');
        $friends = $this->feedOf($refused, SocialFeedKind::UserList, '42');

        $outcomes = self::read($this->fetcher($respond, accountConcurrency: 1), [$home, $one, $friends]);

        self::assertInstanceOf(SocialCredentialsRejectedException::class, self::failureOf($outcomes[self::idOf($home)]));
        self::assertInstanceOf(AccountPausedTimelineModel::class, $outcomes[self::idOf($friends)]);
        self::assertSame('<rss/>', self::feedResponseOf($outcomes[$one->requireId()])->modifiedBody());
        self::assertSame([self::MASTODON_HOME], $this->requestsTo(self::MASTODON));
    }

    public function testAStaleSessionsFeedsRunInASecondWaveAfterTheRssFeedsLanded(): void
    {
        $renewing = self::renewingStaleSessions();
        /** @param list<string> $headers */
        $respond = static function (string $method, string $url, array $headers) use ($renewing): MockResponse {
            if (str_starts_with($url, self::NEWS)) {
                return self::rss();
            }

            return $renewing($method, $url, $headers);
        };
        $home = $this->feedOf($this->blueskyAccount('stale-jwt'));
        $rss = $this->rssFeed(self::NEWS . '/one.xml');

        $outcomes = self::read($this->fetcher($respond), [$home, $rss]);

        self::assertSame([$rss->requireId(), self::idOf($home)], array_keys($outcomes));
        self::assertInstanceOf(FetchedTimelineModel::class, $outcomes[self::idOf($home)]);
        self::assertSame([self::SESSION], $this->requestsTo('POST'));
    }

    public function testAnOrphanIsYieldedBeforeTheNextOutcomeThatLands(): void
    {
        $orphan = $this->feedOf(self::mastodonAccount(self::MASTODON, 1))->getFeed();
        $rss = $this->rssFeed(self::NEWS . '/one.xml');

        $outcomes = self::read($this->fetcher(static fn (): MockResponse => self::rss()), [$orphan, $rss]);

        self::assertSame([$orphan->requireId(), $rss->requireId()], array_keys($outcomes));
        self::assertInstanceOf(PrivateFeedOrphanedException::class, self::failureOf($outcomes[$orphan->requireId()]));
    }

    public function testAnOrphanAloneIsStillYielded(): void
    {
        $orphan = $this->feedOf(self::mastodonAccount(self::MASTODON, 1))->getFeed();

        $outcomes = self::read($this->fetcher(static fn (): MockResponse => self::rss()), [$orphan]);

        self::assertSame([$orphan->requireId()], array_keys($outcomes));
        self::assertSame([], $this->http->requestLines());
    }

    public function testEveryStartedFeedYieldsExactlyOneOutcomeWhetherOrNotItNeedsARequest(): void
    {
        $paused = self::mastodonAccount(self::OTHER, 2);
        $paused->markNeedsReconnect();
        $orphan = $this->feedOf(self::mastodonAccount(self::MASTODON, 1))->getFeed();
        $pausedHome = $this->feedOf($paused);
        $rss = $this->rssFeed(self::NEWS . '/one.xml');
        $home = $this->feedOf(self::mastodonAccount(self::MASTODON, 3));
        $respond = static fn (string $method, string $url): MockResponse => str_starts_with($url, self::NEWS)
            ? self::rss()
            : self::statuses();

        $outcomes = self::read($this->fetcher($respond), [$orphan, $pausedHome, $rss, $home]);

        self::assertCount(4, $outcomes);
        self::assertInstanceOf(AccountPausedTimelineModel::class, $outcomes[self::idOf($pausedHome)]);
        self::assertInstanceOf(FetchedTimelineModel::class, $outcomes[self::idOf($home)]);
        self::assertCount(2, $this->http->requestLines());
    }

    public function testAbandoningAMixedPassCancelsEveryRequestInFlight(): void
    {
        $account = self::mastodonAccount(self::MASTODON, 1);
        $entries = [
            $this->rssFeed(self::NEWS . '/one.xml'),
            $this->feedOf($account),
            $this->feedOf($account, SocialFeedKind::UserList, '1'),
        ];
        $respond = static fn (string $method, string $url): MockResponse => str_starts_with($url, self::NEWS)
            ? self::rss()
            : self::statuses();
        $outcomes = $this->fetcher($respond)->fetchAll(self::passFeeds($entries));

        foreach ($outcomes as $outcome) {
            self::assertNotNull($outcome);
            break;
        }
        unset($outcomes);

        self::assertCount(3, $this->responses);
        foreach ($this->responses as $response) {
            self::assertTrue($response->getInfo('canceled'));
        }
    }

    public function testRejectsAFeedHostConcurrencyBelowOne(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Per-host concurrency must be at least 1, got 0.');

        new ConcurrentPassFetcher(
            FetchWiring::requestEngine(
                new MockHttpClient(),
                new UrlGuard(new StaticDnsResolver([]), new IpValidator()),
                FetchWiring::egressProxy(null),
            ),
            SocialApiWiring::timelineRounds(new MockHttpClient(), $this->cache),
            0,
        );
    }
```

`tests/Support/SocialApiWiring.php`: add `use App\Service\Refresh\PassFetcher\ConcurrentPassFetcher;`, the constant
`public const string RSS_HOST = 'news.example';`, and:

```php
    /** The refresh's pass fetcher over the real engine; every test host, RSS_HOST included, resolves to one address. */
    public static function passFetcher(
        HttpClientInterface $http,
        ArrayAdapter $cache,
        int $accountConcurrency = 3,
    ): ConcurrentPassFetcher {
        $guard = self::guardFor(self::BLUESKY_HOST, self::MASTODON_HOST, self::OTHER_MASTODON_HOST, self::RSS_HOST);

        return new ConcurrentPassFetcher(
            FetchWiring::requestEngine($http, $guard, FetchWiring::egressProxy(null), 8),
            self::timelineRounds($http, $cache, $accountConcurrency),
            2,
        );
    }
```

- [ ] **Step 10: Run it to verify it fails**

Run: `php bin/phpunit tests/Service/Refresh/PassFetcher/ConcurrentPassFetcherTest.php`
Expected: error, `Class "App\Service\Refresh\PassFetcher\ConcurrentPassFetcher" not found`.

- [ ] **Step 11: Write the seam and its engine implementation**

Create `src/Service/Refresh/PassFetcher/PassFetcherInterface.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Refresh\PassFetcher;

use App\Service\Fetch\Model\FetchOutcomeModel;
use App\Service\Refresh\Pass\PassFeeds;
use App\Service\Social\Model\TimelineOutcome\TimelineOutcomeModelInterface;

interface PassFetcherInterface
{
    /**
     * Each started feed's outcome under its feed id, as it lands: a fetch outcome for an RSS or Atom feed, a timeline
     * outcome for a private feed. Every started feed yields exactly one. Abandoning the iterator cancels what is in
     * flight.
     *
     * @return iterable<int|string, FetchOutcomeModel|TimelineOutcomeModelInterface>
     */
    public function fetchAll(PassFeeds $feeds): iterable;
}
```

Create `src/Service/Refresh/PassFetcher/ConcurrentPassFetcher.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Refresh\PassFetcher;

use App\Service\Fetch\ConcurrentRequestEngine;
use App\Service\Fetch\Model\FetchOutcomeModel;
use App\Service\Fetch\Model\RequestTicketModel;
use App\Service\Fetch\Support\CapacityGuard;
use App\Service\Refresh\Pass\PassFeeds;
use App\Service\Refresh\Pass\PassTickets;
use App\Service\Social\Model\TimelineOutcome\TimelineOutcomeModelInterface;
use App\Service\Social\TimelineRounds;

/**
 * Reads a pass's due feeds on one engine run, in due order whatever their kind. A stale Bluesky session sends its
 * feeds again in a second, small run once the account has a fresh session.
 */
final readonly class ConcurrentPassFetcher implements PassFetcherInterface
{
    public function __construct(
        private ConcurrentRequestEngine $engine,
        private TimelineRounds $timelineRounds,
        private int $hostConcurrency,
    ) {
        CapacityGuard::atLeastOne($hostConcurrency, 'Per-host concurrency');
    }

    /** @return \Generator<int|string, FetchOutcomeModel|TimelineOutcomeModelInterface> */
    public function fetchAll(PassFeeds $feeds): \Generator
    {
        $tickets = new PassTickets($feeds, $this->timelineRounds->start(), $this->hostConcurrency);

        yield from $this->run($tickets, $tickets->tickets());
        yield from $this->run($tickets, $tickets->renewalTickets());
    }

    /**
     * @param iterable<int|string, RequestTicketModel> $requests
     *
     * @return \Generator<int|string, FetchOutcomeModel|TimelineOutcomeModelInterface>
     */
    private function run(PassTickets $tickets, iterable $requests): \Generator
    {
        foreach ($this->engine->sendAll($requests, $tickets) as $feedId => $outcome) {
            yield from $tickets->drainUnasked();
            $settled = $tickets->settle($feedId, $outcome);
            if (null !== $settled) {
                yield $feedId => $settled;
            }
        }

        yield from $tickets->drainUnasked();
    }
}
```

`config/services.yaml`, after the `App\Service\Fetch\BatchFeedFetcher\ConcurrentFeedFetcher` block:

```yaml
    App\Service\Refresh\PassFetcher\ConcurrentPassFetcher:
        arguments:
            $hostConcurrency: '%fetch_host_concurrency%'
```

- [ ] **Step 12: Run it to verify it passes**

Run: `bin/console cache:warmup && php bin/phpunit tests/Service/Refresh tests/Service/Social`
Expected: PASS, the copied timeline tests and the new mixed ones alike.

- [ ] **Step 13: Gates and module direction**

Run: `composer check && composer md`, then the two greps from Global Constraints. Also
`php bin/phpunit --filter EveryApplicationServiceBuildsTest`.
Expected: clean; both greps empty; `ConcurrentPassFetcher` builds.

- [ ] **Step 14: Commit**

```bash
git add backend/src/Service/Refresh backend/config/services.yaml backend/tests/Service/Refresh \
  backend/tests/Support/SocialApiWiring.php
git commit -m "feat(#1608): one engine run reads a pass's RSS and private feeds in due order"
```

---

### Task 4: The refresh reads through the one seam

Spec §2 (routing, persisting) and §3 (one stub). `RefreshPassFetcher` loops once over `PassFetcherInterface` and
stores each outcome by its kind: an RSS fetch through `FeedOutcomePersister::persist`, a timeline through the existing
arms. The tests that drove a refresh by replacing `BatchFeedFetcherInterface` or `TimelineFetcherInterface` move to
`StubPassFetcher`. The tests that stub `BatchFeedFetcherInterface` for a single-feed path keep it: discovery,
preview, comments, subscriptions, favicons, app view and profile images (`SubscriptionControllerTest`,
`FeedPreviewControllerTest`, `EntryCommentsControllerTest`, `tests/Service/Discovery/**`, `FaviconResolverTest`,
`MissingFaviconResolverTest`, `AppViewClientTest`, `PostEnricherTest`, `CommentsLoaderTest`, `ProfileImageSweepTest`,
`FeedPreviewServiceTest`, `tests/Support/PostEnrichers.php`).

A refresh still resolves favicons through `BatchFeedFetcherInterface` (`MissingFaviconResolver` → `FaviconResolver`):
every refresh test keeps a homepage stub there.

The old classes (`SocialFeedRefresher`, `PrivateFeedSupply`, `ConcurrentTimelineFetcher`…) stay in the tree, unused,
until Task 5.

**Files:**
- Modify: `backend/src/Service/Refresh/RefreshPassFetcher.php`
- Rename: `backend/src/Service/Refresh/Pass/TimelineOutcomeStore.php` → `PassOutcomeStore.php`
- Modify: `backend/config/services.yaml`, `backend/config/services_test.yaml`
- Create: `backend/tests/Support/StubPassFetcher.php`, `backend/tests/Support/RefreshFetchers.php`
- Modify: `backend/tests/Support/RefreshRunners.php`
- Rename: `tests/Service/Refresh/SocialFeedRefresherTest.php` → `tests/Service/Refresh/RefreshPassFetcherTest.php`
- Rename: `tests/Service/Refresh/SocialFeedRefresherBlueskyTest.php` → `tests/Service/Refresh/RefreshPassFetcherBlueskyTest.php`
- Modify (tests): `tests/Service/Refresh/RefreshRunner/{RefreshRunnerTest,RefreshRunnerOrphanSweepTest,RefreshRunnerConcurrentFetchTest,RefreshRunnerSocialTest}.php`,
  `tests/Service/Maintenance/MaintenanceTickTest.php`, `tests/Controller/MaintenanceControllerTest.php`,
  `tests/Controller/Api/RefreshControllerTest.php`, `tests/Command/RefreshFeedsCommandTest.php`,
  `tests/Service/Worker/RefreshDueFeedsHandlerTest.php`

**Interfaces:**
- Consumes (Task 3): `PassFetcherInterface::fetchAll(PassFeeds)`, `PassFeeds`, `ConcurrentPassFetcher`,
  `SocialApiWiring::passFetcher(...)`, `SocialApiWiring::timelineRounds(...)`, `SocialApiWiring::RSS_HOST`.
- Produces:
  - `RefreshPassFetcher::__construct(PassFetcherInterface $fetcher, FeedOutcomePersister $outcomePersister, SocialFeedRepository $socialFeeds)`;
    `fetchAndPersist(RefreshPass $pass, \DateTimeImmutable $now): void` (unchanged signature).
  - `PassOutcomeStore::__construct(FeedOutcomePersister $outcomePersister, \DateTimeImmutable $now)`,
    `store(Feed $feed, FetchOutcomeModel|TimelineOutcomeModelInterface $outcome): FeedRefreshResultModel`.
  - Tests: `StubPassFetcher::__construct(?MockClock $clock = null, int $concurrency = 8)`, `willReturn(string, FetchResponseModel)`,
    `willReturnBody(string, string)`, `willThrow(string, FetchException)`, `willReadTimeline(string, TimelineOutcomeModelInterface)`,
    public `$fetchedUrls`, `$asked`, `$socialFeedsByFeedId`, `$secondsPerFetch`.
    `RefreshFetchers::install(ContainerInterface $container, StubPassFetcher $passes, string $homepage): void`.
    `RefreshRunners::build(PassFetcherInterface $passFetcher, BatchFeedFetcherInterface $homepageFetcher): RefreshRunner`.

- [ ] **Step 0: Merge develop**

From the repo root: `git status && git fetch origin develop && git merge --no-edit origin/develop`. Then
`php bin/phpunit tests/Service/Refresh` must pass.

- [ ] **Step 1: Write the stub**

Create `tests/Support/StubPassFetcher.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\Feed;
use App\Entity\SocialFeed;
use App\Service\Fetch\Exception\FetchException;
use App\Service\Fetch\Model\FetchOutcomeModel;
use App\Service\Fetch\Model\FetchResponseModel;
use App\Service\Refresh\Pass\PassFeeds;
use App\Service\Refresh\PassFetcher\PassFetcherInterface;
use App\Service\Social\Exception\PrivateFeedOrphanedException;
use App\Service\Social\Model\TimelineOutcome\FailedTimelineModel;
use App\Service\Social\Model\TimelineOutcome\TimelineOutcomeModelInterface;
use PHPUnit\Framework\Assert;
use Symfony\Component\Clock\MockClock;

/** A refresh pass scripted by URL: each RSS feed's answer and each private feed's timeline outcome, in waves. */
final class StubPassFetcher implements PassFetcherInterface
{
    /** @var array<string, FetchResponseModel|FetchException> */
    private array $responses = [];

    /** @var array<string, TimelineOutcomeModelInterface> */
    private array $timelines = [];

    /** @var list<string> */
    public array $fetchedUrls = [];

    /** @var list<int> */
    public array $asked = [];

    /** @var array<int, SocialFeed> */
    public array $socialFeedsByFeedId = [];

    /** Wall-clock cost of one wave of concurrent fetches, not of one fetch. */
    public int $secondsPerFetch = 0;

    public function __construct(private readonly ?MockClock $clock = null, private readonly int $concurrency = 8)
    {
    }

    public function willReturn(string $url, FetchResponseModel $response): void
    {
        $this->responses[$url] = $response;
    }

    public function willReturnBody(string $url, string $body): void
    {
        $this->willReturn(
            $url,
            FetchResponseModel::fetched($url, permanentRedirect: false, body: $body, etag: null, lastModified: null),
        );
    }

    public function willThrow(string $url, FetchException $exception): void
    {
        $this->responses[$url] = $exception;
    }

    public function willReadTimeline(string $url, TimelineOutcomeModelInterface $outcome): void
    {
        $this->timelines[$url] = $outcome;
    }

    /** @return \Generator<int, FetchOutcomeModel|TimelineOutcomeModelInterface> */
    public function fetchAll(PassFeeds $feeds): \Generator
    {
        $wave = [];
        foreach ($feeds->started() as $feedId => $feed) {
            $wave[$feedId] = $feed;
            if (\count($wave) < $this->concurrency) {
                continue;
            }

            yield from $this->runWave($feeds, $wave);
            $wave = [];
        }

        if ([] !== $wave) {
            yield from $this->runWave($feeds, $wave);
        }
    }

    /**
     * @param array<int, Feed> $wave
     *
     * @return \Generator<int, FetchOutcomeModel|TimelineOutcomeModelInterface>
     */
    private function runWave(PassFeeds $feeds, array $wave): \Generator
    {
        // Before the yield: a caller holding the first outcome has paid the whole wave, as with the real engine.
        if ($this->secondsPerFetch > 0) {
            $this->clock?->sleep($this->secondsPerFetch);
        }

        foreach ($wave as $feedId => $feed) {
            yield $feedId => $feeds->isPrivate($feedId) ? $this->timelineOf($feeds, $feedId) : $this->fetched($feed);
        }
    }

    private function fetched(Feed $feed): FetchOutcomeModel
    {
        $url = $feed->getUrl();
        $this->fetchedUrls[] = $url;
        $response = $this->responses[$url] ?? throw new \LogicException('No stubbed result for ' . $url);

        return $response instanceof FetchException
            ? FetchOutcomeModel::failed($response)
            : FetchOutcomeModel::succeeded($response);
    }

    private function timelineOf(PassFeeds $feeds, int $feedId): TimelineOutcomeModelInterface
    {
        try {
            $socialFeed = $feeds->socialFeedOf($feedId);
        } catch (PrivateFeedOrphanedException $orphaned) {
            return new FailedTimelineModel($orphaned);
        }
        $this->asked[] = $feedId;
        $this->socialFeedsByFeedId[$feedId] = $socialFeed;
        $url = $socialFeed->getFeed()->getUrl();

        return $this->timelines[$url] ?? Assert::fail('No timeline outcome scripted for ' . $url);
    }
}
```

Create `tests/Support/RefreshFetchers.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Service\Fetch\BatchFeedFetcher\BatchFeedFetcherInterface;
use App\Service\Fetch\Model\FetchResponseModel;
use App\Service\Refresh\PassFetcher\PassFetcherInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/** Puts both of a container refresh's fetches behind stubs: the pass's feeds, and the favicon pass's homepage. */
final class RefreshFetchers
{
    private function __construct()
    {
    }

    public static function install(ContainerInterface $container, StubPassFetcher $passes, string $homepage): void
    {
        $homepages = new StubFeedFetcher();
        $homepages->willReturn(
            $homepage,
            FetchResponseModel::fetched($homepage, false, '<html lang="en"></html>', null, null),
        );
        $container->set(PassFetcherInterface::class, $passes);
        $container->set(BatchFeedFetcherInterface::class, $homepages);
    }
}
```

- [ ] **Step 2: Rewrite the refresher test against the new seam**

From the repo root:

```bash
git mv backend/tests/Service/Refresh/SocialFeedRefresherTest.php backend/tests/Service/Refresh/RefreshPassFetcherTest.php
git mv backend/tests/Service/Refresh/SocialFeedRefresherBlueskyTest.php \
  backend/tests/Service/Refresh/RefreshPassFetcherBlueskyTest.php
```

Replace the body of `RefreshPassFetcherTest.php` with:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Refresh;

use App\Entity\Entry;
use App\Entity\Feed;
use App\Entity\SocialAccount;
use App\Entity\SocialFeed;
use App\Enum\SocialFeedKind;
use App\Enum\SourceFormat;
use App\Service\Bluesky\Model\JsonNodeModel;
use App\Service\Fetch\Model\FetchResponseModel;
use App\Service\Refresh\Pass\RefreshPass;
use App\Service\Refresh\RefreshPassFetcher;
use App\Service\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\Model\PrivateFeedModel;
use App\Service\Social\Model\TimelineOutcome\AccountPausedTimelineModel;
use App\Service\Social\Model\TimelineOutcome\AccountThrottledTimelineModel;
use App\Service\Social\Model\TimelineOutcome\FailedTimelineModel;
use App\Service\Social\Model\TimelineOutcome\FetchedTimelineModel;
use App\Service\Social\Model\TimelineOutcome\ThrottledTimelineModel;
use App\Service\Social\Model\TimelineOutcome\TimelineOutcomeModelInterface;
use App\Service\Social\Model\TimelinePageModel;
use App\Service\Social\Support\MastodonTimelinePage;
use App\Tests\DbTestCase;
use App\Tests\Support\FeedOutcomePersisters;
use App\Tests\Support\PrivateFeedsWiring;
use App\Tests\Support\SocialAccounts;
use App\Tests\Support\SocialFeeds;
use App\Tests\Support\StubPassFetcher;
use App\Tests\Support\UserFactory;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class RefreshPassFetcherTest extends DbTestCase
{
    private MockClock $clock;
    private SocialAccount $account;
    private SocialFeed $home;
    private StubPassFetcher $passes;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clock = new MockClock('2026-10-12 12:00:00', 'UTC');
        $this->passes = new StubPassFetcher();

        /** @var UserPasswordHasherInterface $hasher */
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        $user = (new UserFactory($this->entityManager, $hasher))->create('pass-fetcher@example.test');
        $this->account = SocialAccounts::mastodonOf($user);
        $this->entityManager->persist($this->account);
        $this->entityManager->flush();
        PrivateFeedsWiring::build(self::getContainer(), $this->entityManager, $this->clock)
            ->create($this->account, PrivateFeedModel::home());
        $home = SocialFeeds::repository($this->entityManager)->findOneFor($this->account, SocialFeedKind::Home, '');
        self::assertNotNull($home);
        $this->home = $home;
    }

    public function testAFetchedPageIsStoredAsTheFeedsEntries(): void
    {
        $pass = $this->refreshed(new FetchedTimelineModel(self::page()));

        self::assertSame(1, $pass->tally->fetched());
        self::assertSame(1, $pass->tally->entriesCreated());
        self::assertSame(
            1,
            $this->entityManager->getRepository(Entry::class)->count(['feed' => $this->home->getFeed()->requireId()]),
        );
    }

    public function testAThrottledTimelineWaitsOutItsRetryAfter(): void
    {
        $pass = $this->refreshed(new ThrottledTimelineModel(120));

        self::assertSame(1, $pass->tally->throttled());
        self::assertSame(0, $this->home->getFeed()->getConsecutiveFailures());
        self::assertSame('2026-10-12 12:02:00', $this->home->getFeed()->getNextFetchAt()?->format('Y-m-d H:i:s'));
    }

    public function testAFailedReadIsStoredOnTheFeed(): void
    {
        $unreachable = new SocialNetworkUnreachableException('The Mastodon instance answered 502.');

        $pass = $this->refreshed(new FailedTimelineModel($unreachable));

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

        $pass = $this->refreshed(new AccountThrottledTimelineModel());

        self::assertSame(1, $pass->tally->throttled());
        $this->assertUntouched($nextFetchAt);
    }

    public function testAFeedOfAPausedAccountKeepsItsSchedule(): void
    {
        $nextFetchAt = $this->home->getFeed()->getNextFetchAt();

        $pass = $this->refreshed(new AccountPausedTimelineModel());

        self::assertSame(1, $pass->tally->failed());
        $this->assertUntouched($nextFetchAt);
    }

    public function testThePassesPrivateFeedsAreReadWithTheirAccounts(): void
    {
        $feed = $this->home->getFeed();
        $feedId = $feed->requireId();
        $this->passes->willReadTimeline($feed->getUrl(), new ThrottledTimelineModel(null));

        $this->fetcher()->fetchAndPersist($this->pass([$feed]), $this->clock->now());

        self::assertSame([$feedId], array_keys($this->passes->socialFeedsByFeedId));
        self::assertSame(
            $this->account->requireId(),
            $this->passes->socialFeedsByFeedId[$feedId]->getSocialAccount()->requireId(),
        );
        self::assertSame([$feedId], $this->passes->asked);
    }

    public function testAnRssOutcomeIsStoredThroughTheFeedPath(): void
    {
        $rss = $this->rssFeed();
        $this->passes->willReturn($rss->getUrl(), FetchResponseModel::notModified($rss->getUrl(), false, null, null));
        $pass = $this->pass([$rss]);

        $this->fetcher()->fetchAndPersist($pass, $this->clock->now());

        self::assertSame(1, $pass->tally->notModified());
        self::assertSame([$rss->getUrl()], $this->passes->fetchedUrls);
    }

    public function testBothKindsOfOutcomeAreTalliedInOnePass(): void
    {
        $rss = $this->rssFeed();
        $this->passes->willReturn($rss->getUrl(), FetchResponseModel::notModified($rss->getUrl(), false, null, null));
        $this->passes->willReadTimeline($this->home->getFeed()->getUrl(), new FetchedTimelineModel(self::page()));
        $pass = $this->pass([$this->home->getFeed(), $rss]);

        $this->fetcher()->fetchAndPersist($pass, $this->clock->now());

        self::assertSame(1, $pass->tally->fetched());
        self::assertSame(1, $pass->tally->notModified());
        self::assertSame(2, $pass->tally->processed());
    }

    public function testAPrivateFeedThatLostItsSocialFeedFailsWithoutAsking(): void
    {
        $orphan = $this->orphan();

        $this->assertOrphanFailsWithoutAsking($orphan, [$orphan, $this->home->getFeed()]);
    }

    public function testAnOrphanAfterEveryAskedFeedIsStoredToo(): void
    {
        $orphan = $this->orphan();

        $this->assertOrphanFailsWithoutAsking($orphan, [$this->home->getFeed(), $orphan]);
    }

    /** @param list<Feed> $feeds */
    private function assertOrphanFailsWithoutAsking(Feed $orphan, array $feeds): void
    {
        $home = $this->home->getFeed();
        $this->passes->willReadTimeline($home->getUrl(), new FetchedTimelineModel(self::page()));
        $pass = $this->pass($feeds);

        $this->fetcher()->fetchAndPersist($pass, $this->clock->now());

        self::assertSame([$home->requireId()], $this->passes->asked);
        self::assertSame(1, $pass->tally->failed());
        self::assertSame(1, $pass->tally->fetched());
        $this->entityManager->clear();
        self::assertSame(
            'This private feed has lost its social account.',
            $this->entityManager->find(Feed::class, $orphan->requireId())?->getLastErrorMessage(),
        );
    }

    private function orphan(): Feed
    {
        $orphan = new Feed('social:mastodon:999:home');
        $orphan->setSourceFormat(SourceFormat::MASTODON_API);
        $this->entityManager->persist($orphan);
        $this->entityManager->flush();

        return $orphan;
    }

    private function rssFeed(): Feed
    {
        $rss = new Feed('https://example.com/feed.xml');
        $this->entityManager->persist($rss);
        $this->entityManager->flush();

        return $rss;
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

    private function refreshed(TimelineOutcomeModelInterface $outcome): RefreshPass
    {
        $this->passes->willReadTimeline($this->home->getFeed()->getUrl(), $outcome);
        $pass = $this->pass([$this->home->getFeed()]);
        $this->fetcher()->fetchAndPersist($pass, $this->clock->now());

        return $pass;
    }

    /** @param list<Feed> $feeds */
    private function pass(array $feeds): RefreshPass
    {
        return new RefreshPass($feeds, $this->clock, $this->clock->now()->getTimestamp() + 300);
    }

    private function fetcher(): RefreshPassFetcher
    {
        return new RefreshPassFetcher(
            $this->passes,
            FeedOutcomePersisters::fromContainer(self::getContainer(), $this->entityManager, $this->clock)->idle(),
            SocialFeeds::repository($this->entityManager),
        );
    }

    private static function page(): TimelinePageModel
    {
        return MastodonTimelinePage::of([JsonNodeModel::of([
            'id' => '109',
            'uri' => 'https://mastodon.example/users/bob/statuses/109',
            'url' => 'https://mastodon.example/@bob/109',
            'created_at' => '2026-10-12T11:59:00Z',
            'content' => '<p>Hello</p>',
            'account' => ['acct' => 'bob', 'display_name' => 'Bob', 'url' => 'https://mastodon.example/@bob'],
        ])]);
    }
}
```

`SocialFeedRefresherTest::testAPassWithoutPrivateFeedsNeverStartsTheTimelineFetcher` is gone: there is no separate
timeline phase left to start.

`RefreshPassFetcherBlueskyTest.php`: the class becomes `RefreshPassFetcherBlueskyTest`;
`use App\Service\Refresh\SocialFeedRefresher;` becomes `use App\Service\Refresh\RefreshPassFetcher;`; in `refreshed()`,
`$this->refresher()->refreshAll($pass, $clock->now());` becomes `$this->fetcher()->fetchAndPersist($pass, $clock->now());`;
and `refresher()` becomes:

```php
    private function fetcher(): RefreshPassFetcher
    {
        $fetcher = self::getContainer()->get(RefreshPassFetcher::class);
        self::assertInstanceOf(RefreshPassFetcher::class, $fetcher);

        return $fetcher;
    }
```

- [ ] **Step 3: Run them to verify they fail**

Run: `php bin/phpunit --filter 'RefreshPassFetcherTest|RefreshPassFetcherBlueskyTest'`
Expected: `RefreshPassFetcherTest` errors (`RefreshPassFetcher::__construct()` gets a `StubPassFetcher` where it wants
a `BatchFeedFetcherInterface`). `RefreshPassFetcherBlueskyTest` may already pass: it goes through the container.

- [ ] **Step 4: Switch the refresh over**

From the repo root:
`git mv backend/src/Service/Refresh/Pass/TimelineOutcomeStore.php backend/src/Service/Refresh/Pass/PassOutcomeStore.php`.

`PassOutcomeStore.php`: the class becomes `PassOutcomeStore`, its docblock "Stores one refresh's outcomes as they land,
each as of the moment the refresh started." Add `use App\Service\Fetch\Model\FetchOutcomeModel;`. `store()` becomes:

```php
    /** @throws \DateMalformedStringException */
    public function store(Feed $feed, FetchOutcomeModel|TimelineOutcomeModelInterface $outcome): FeedRefreshResultModel
    {
        return match (true) {
            $outcome instanceof FetchOutcomeModel => $this->outcomePersister->persist($feed, $outcome, $this->now),
            $outcome instanceof FetchedTimelineModel => $this->outcomePersister->storeParsed(
```

(the remaining arms unchanged).

`RefreshPassFetcher.php` becomes:

```php
<?php

declare(strict_types=1);

namespace App\Service\Refresh;

use App\Repository\SocialFeedRepository;
use App\Service\Refresh\Pass\PassFeeds;
use App\Service\Refresh\Pass\PassOutcomeStore;
use App\Service\Refresh\Pass\RefreshPass;
use App\Service\Refresh\PassFetcher\PassFetcherInterface;

/** Stores each of a pass's outcomes as it lands, whatever its feed's kind, one at a time on the one EntityManager. */
final readonly class RefreshPassFetcher
{
    public function __construct(
        private PassFetcherInterface $fetcher,
        private FeedOutcomePersister $outcomePersister,
        private SocialFeedRepository $socialFeeds,
    ) {
    }

    /** @throws \DateMalformedStringException */
    public function fetchAndPersist(RefreshPass $pass, \DateTimeImmutable $now): void
    {
        $outcomes = new PassOutcomeStore($this->outcomePersister, $now);
        foreach ($this->fetcher->fetchAll($this->feedsOf($pass)) as $feedId => $outcome) {
            $feed = $pass->feed($feedId);
            $pass->tally->record($outcomes->store($feed, $outcome), $feed);
            if ($pass->tally->isAborted()) {
                return;
            }
        }
    }

    private function feedsOf(RefreshPass $pass): PassFeeds
    {
        return new PassFeeds($pass, $this->socialFeeds->findWithAccountByFeedIds($pass->socialFeedIds()));
    }
}
```

`src/Service/Refresh/SocialFeedRefresher.php` still names `TimelineOutcomeStore`: change its two uses to
`PassOutcomeStore` (`use App\Service\Refresh\Pass\PassOutcomeStore;` and `new PassOutcomeStore(...)`). Task 5 deletes
the class.

`config/services.yaml`, after the `BatchFeedFetcherInterface` alias line:

```yaml
    App\Service\Refresh\PassFetcher\PassFetcherInterface: '@App\Service\Refresh\PassFetcher\ConcurrentPassFetcher'
```

`config/services_test.yaml`, after the `ImageProxyInterface` block:

```yaml
    # Public: the refresh tests replace it, and its one consumer would otherwise inline it away.
    App\Service\Refresh\PassFetcher\PassFetcherInterface:
        alias: App\Service\Refresh\PassFetcher\ConcurrentPassFetcher
        public: true
```

`tests/Support/RefreshRunners.php`:
- remove the `$timelines` constructor parameter, its argument in `fromContainer()` (`new StubTimelineFetcher()`) and in
  every `with…`/`…ing…` copy, and delete `readingTimelinesFrom()`;
- replace the imports of `TimelineFetcherInterface` and `SocialFeedRefresher` with
  `use App\Service\Refresh\PassFetcher\PassFetcherInterface;`;
- `build()` becomes:

```php
    public function build(
        PassFetcherInterface $passFetcher,
        BatchFeedFetcherInterface $homepageFetcher,
    ): RefreshRunner {
        /** @var FeedRepository $feedRepository */
        $feedRepository = $this->entityManager->getRepository(Feed::class);
        $indexer = new EntryIndexer($this->indexWriter, new NullLogger());
        $outcomePersister = (new FeedOutcomePersisters($this->entityManager, $this->clock, $this->bodyParser))
            ->flushingThrough($this->flushingEntityManager)
            ->indexingInto($this->indexWriter)
            ->idle();

        return new RefreshRunner(
            $feedRepository,
            new RefreshPassFetcher($passFetcher, $outcomePersister, SocialFeeds::repository($this->entityManager)),
            new MissingFaviconResolver(
                new FaviconResolver($homepageFetcher, new NullLogger()),
                $this->flushingEntityManager,
            ),
```

(the rest unchanged).

- [ ] **Step 5: Run the refresher tests**

Run: `bin/console cache:warmup && php bin/phpunit --filter 'RefreshPassFetcherTest|RefreshPassFetcherBlueskyTest'`
Expected: PASS.

- [ ] **Step 6: Move every refresh test to the stub**

Each of these drives a refresh. Swap the feed role to `StubPassFetcher`; a homepage stub stays a `StubFeedFetcher`.

`tests/Service/Refresh/RefreshRunner/RefreshRunnerTest.php`: add `use App\Tests\Support\StubPassFetcher;`;
`private StubFeedFetcher $fetcher;` becomes `private StubPassFetcher $fetcher;`; each
`new StubFeedFetcher($this->clock` (setUp and the four `concurrency: 1` tests, `grep -n "new StubFeedFetcher(\$this->clock"`)
becomes `new StubPassFetcher($this->clock`. `$this->faviconFetcher` stays a `StubFeedFetcher`.

`tests/Service/Refresh/RefreshRunner/RefreshRunnerOrphanSweepTest.php`: the same for `$this->fetcher` (type and
`new StubFeedFetcher($this->clock)` in `setUp()`).

`tests/Service/Refresh/RefreshRunner/RefreshRunnerConcurrentFetchTest.php`: imports
`ConcurrentFeedFetcher` → `App\Service\Refresh\PassFetcher\ConcurrentPassFetcher`, plus
`App\Tests\Support\SocialApiWiring` and `Symfony\Component\Cache\Adapter\ArrayAdapter`. `runner(ConcurrentFeedFetcher $fetcher)`
becomes `runner(ConcurrentPassFetcher $fetcher)`. `concurrentFetcher()` returns `ConcurrentPassFetcher` and ends:

```php
        return new ConcurrentPassFetcher(
            FetchWiring::requestEngine($httpClient, $urlGuard, $this->noEgressProxy(), $concurrency, $this->clock),
            SocialApiWiring::timelineRounds($httpClient, new ArrayAdapter()),
            $hostConcurrency,
        );
```

In its class docblock and in the comment near line 168, "ConcurrentFeedFetcher" becomes "ConcurrentPassFetcher".

`tests/Service/Refresh/RefreshRunner/RefreshRunnerSocialTest.php`: replace the file with:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Refresh\RefreshRunner;

use App\Entity\Entry;
use App\Entity\Feed;
use App\Entity\SocialAccount;
use App\Entity\Subscription;
use App\Entity\User;
use App\Enum\SocialAccountStatus;
use App\Service\Fetch\Model\FetchResponseModel;
use App\Service\Refresh\Model\RefreshRequestModel;
use App\Service\Refresh\RefreshRunner\RefreshRunner;
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
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/** The real pass fetcher behind RefreshRunner, RSS and private feeds alike, over a routed HTTP client. */
final class RefreshRunnerSocialTest extends DbTestCase
{
    private const string RSS_URL = 'https://news.example/feed.xml';
    private const string RSS = 'GET https://news.example/feed.xml';
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
        $this->rssFeed();
        $homeId = $this->privateFeed(PrivateFeedModel::home())->requireId();
        $http = new RoutedHttpClient([self::RSS => self::rssBody(), self::HOME => self::statuses()]);

        $report = $this->runner($http)->run(RefreshRequestModel::allDue(300));

        self::assertSame(2, $report->total);
        self::assertSame(2, $report->fetched);
        self::assertEqualsCanonicalizing([self::RSS, self::HOME], $http->requestLines());
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

        $report = $this->runner($http, accountConcurrency: 1)->run(RefreshRequestModel::allDue(300));

        self::assertSame(2, $report->throttled);
        self::assertCount(1, $http->requests);
    }

    public function testARefusedTokenPausesTheAccountForGoodAndSparesItsOtherFeeds(): void
    {
        $this->privateFeed(PrivateFeedModel::home());
        $this->privateFeed(PrivateFeedModel::userList('42', 'Friends'));
        $http = new RoutedHttpClient([self::HOME => self::refused(), self::FRIENDS => self::refused()]);

        $report = $this->runner($http, accountConcurrency: 1)->run(RefreshRequestModel::allDue(300));

        $this->entityManager->clear();
        self::assertSame(
            SocialAccountStatus::NeedsReconnect,
            $this->entityManager->find(SocialAccount::class, $this->account->requireId())?->getStatus(),
        );
        self::assertSame(2, $report->failed);
        self::assertCount(1, $http->requests);
    }

    public function testTheBudgetGateStopsThePassMidway(): void
    {
        $this->privateFeed(PrivateFeedModel::home());
        $this->privateFeed(PrivateFeedModel::userList('42', 'Friends'));
        $http = new RoutedHttpClient([self::HOME => self::statuses(), self::FRIENDS => self::statuses()]);

        $report = $this->runner($http)->run(RefreshRequestModel::allDue(5));

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

        $report = $this->runner($http, accountConcurrency: 1, flushing: $failing)->run(RefreshRequestModel::allDue(300));

        self::assertSame('aborted', $report->status);
        self::assertCount(2, $http->requests, 'the third timeline is never asked once the run aborted');
    }

    private function runner(
        RoutedHttpClient $http,
        int $accountConcurrency = 3,
        ?EntityManagerInterface $flushing = null,
    ): RefreshRunner {
        return RefreshRunners::fromContainer(self::getContainer(), $this->entityManager, $this->clock)
            ->flushingThrough($flushing ?? $this->entityManager)
            ->build(
                SocialApiWiring::passFetcher($http->client(), new ArrayAdapter(), $accountConcurrency),
                $this->homepages(),
            );
    }

    private function rssFeed(): Feed
    {
        $rss = new Feed(self::RSS_URL);
        $this->entityManager->persist($rss);
        $this->entityManager->persist(new Subscription($this->user, $rss, $this->clock->now()));
        $this->entityManager->flush();

        return $rss;
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
        foreach (['https://news.example', 'https://mastodon.example'] as $origin) {
            $homepages->willReturn(
                $origin,
                FetchResponseModel::fetched($origin, false, '<html lang="en"></html>', null, null),
            );
        }

        return $homepages;
    }

    private static function rssBody(): MockResponse
    {
        return new MockResponse(/** @lang TEXT */ <<<XML
            <?xml version="1.0" encoding="UTF-8"?>
            <rss version="2.0"><channel><title>Example</title>
            <item><title>Post</title><link>https://news.example/p</link><guid>p-1</guid></item>
            </channel></rss>
            XML);
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

    private static function refused(): MockResponse
    {
        return new MockResponse('{}', ['http_code' => Response::HTTP_UNAUTHORIZED]);
    }
}
```

`tests/Service/Maintenance/MaintenanceTickTest.php` (add `use App\Tests\Support\StubPassFetcher;`):
- the aborted-refresh test (around line 83): `$fetcher = new StubFeedFetcher($clock);` becomes
  `$passes = new StubPassFetcher($clock);`, its `willReturn(...)` call moves to `$passes`, and
  `->build($fetcher, $fetcher)` becomes `->build($passes, new StubFeedFetcher())`;
- `profilePicturesCheckedByATickWhoseRefreshTakes()` (around line 179):

  ```php
        $passes = new StubPassFetcher($clock);
        $passes->secondsPerFetch = $refreshSeconds;
        $passes->willReturnBody(
            'https://one.example.com/feed',
            /** @lang TEXT */ '<?xml version="1.0"?><rss version="2.0"><channel><title>F</title></channel></rss>',
        );
        $appView = new StubFeedFetcher();
        $appView->willReturnBody(
            'https://public.api.bsky.app/xrpc/app.bsky.actor.getProfiles?actors=tick.bsky.social',
            '{"profiles":[]}',
        );
        $refreshRunner = RefreshRunners::fromContainer(self::getContainer(), $this->entityManager, $clock)
            ->build($passes, $appView);
  ```

  and `tickAround(…, $clock, $fetcher)` becomes `tickAround(…, $clock, $appView)`;
- `assertLinkCardSweepOfATickWhoseRefreshTakes()` (around line 209): `$fetcher = new StubFeedFetcher($clock);` becomes
  `$passes = new StubPassFetcher($clock);`, `secondsPerFetch` and `willReturnBody` move to `$passes`, and
  `->build($fetcher, $fetcher)` becomes `->build($passes, new StubFeedFetcher())`.

These two sweep tests stay correct: before, the homepage lookup through the same clocked stub also slept
`$refreshSeconds`, so an 18-second refresh spent 36 seconds; now it spends 18. With the 25-second window, 7 seconds
remain: below one profile call (20 s) and one link-card fetch (8 s), so both still expect 0.

`tests/Controller/MaintenanceControllerTest.php` (imports: add `App\Tests\Support\RefreshFetchers` and
`App\Tests\Support\StubPassFetcher`; keep `StubFeedFetcher`; drop `BatchFeedFetcherInterface` if unused):
- `testRefreshesWithValidToken`, `testAcceptsTokenViaHeader` and `testTickReturnsBothHalvesWithValidToken`: replace
  the `$stub = new StubFeedFetcher(); … self::getContainer()->set(BatchFeedFetcherInterface::class, $stub);` block
  (with its "same fetcher" comment) with:

  ```php
        $passes = new StubPassFetcher();
        $passes->willReturn($feed->getUrl(), FetchResponseModel::notModified($feed->getUrl(), false, null, null));
        RefreshFetchers::install(self::getContainer(), $passes, 'https://maint.example.com');
  ```

- `testAnAbortedRefreshReturnsServerError`: `$fetcher = new StubFeedFetcher();` becomes
  `$passes = new StubPassFetcher();`, its `willReturn(...)` call moves to `$passes`, and `->build($fetcher, $fetcher)`
  becomes `->build($passes, new StubFeedFetcher())`.

`tests/Controller/Api/RefreshControllerTest.php` (imports: `RefreshFetchers`, `StubPassFetcher`; drop
`StubFeedFetcher` and `BatchFeedFetcherInterface` if unused). The three blocks around lines 98, 168 and 202 become:

```php
        $passes = new StubPassFetcher();
        $passes->willReturn(<feed url>, FetchResponseModel::notModified(<feed url>, false, null, null));
        RefreshFetchers::install(self::getContainer(), $passes, <homepage>);
```

with, in order: `'https://example.com/mine.xml'` / `'https://example.com'`; `'https://example.com/tagged.xml'` /
`'https://example.com'`; `NetworkFeedSubscriptions::MASTODON_PROFILE` / `'https://mastodon.social'`. Drop the
"Swap in a stub fetcher", "StubFeedFetcher throws LogicException" and "same fetcher" comments.

`tests/Command/RefreshFeedsCommandTest.php`, `testRefreshesDueFeedsAndPrintsReport` (imports as above):

```php
        $passes = new StubPassFetcher();
        $passes->willReturn($feed->getUrl(), FetchResponseModel::notModified($feed->getUrl(), false, null, null));
        RefreshFetchers::install(self::getContainer(), $passes, 'https://cli.example.com');
```

and the final assertion (with its comment) becomes `self::assertSame([$feed->getUrl()], $passes->fetchedUrls);`.

`tests/Service/Worker/RefreshDueFeedsHandlerTest.php` (imports as above):
- `testFiringRefreshesADueFeedAndMovesItsLastFetchedAt`:

  ```php
        $passes = new StubPassFetcher();
        $passes->willReturn(
            $feed->getUrl(),
            FetchResponseModel::fetched($feed->getUrl(), false, $this->rss(), null, null),
        );
        RefreshFetchers::install(self::getContainer(), $passes, 'https://example.com');
  ```

- `testAFreshWorkersFirstFiringTakesOnlyTheWarmUpBatch`: `$fetcher = new StubFeedFetcher();` becomes
  `$passes = new StubPassFetcher();`, the loop's `willReturn` goes to `$passes`, and the homepage `willReturn` plus
  `set(BatchFeedFetcherInterface::class, $fetcher)` become
  `RefreshFetchers::install(self::getContainer(), $passes, 'https://example.com');`.

- [ ] **Step 7: Prove nothing drives a refresh through the old seams**

```bash
grep -rn "set(BatchFeedFetcherInterface::class" tests
grep -rln "RefreshRunners::\|RefreshRunner::class\|RefreshRunnerInterface::class" tests | \
  xargs grep -ln "new StubFeedFetcher(\$this->clock\|new StubFeedFetcher(\$clock"
```

Expected: the first prints only `SubscriptionControllerTest.php` (discovery, single-feed path) and
`tests/Support/RefreshFetchers.php`; the second prints nothing.

- [ ] **Step 8: Run every refresh-driving test**

Run:

```bash
php bin/phpunit tests/Service/Refresh tests/Service/Maintenance tests/Service/Worker \
  tests/Controller/MaintenanceControllerTest.php tests/Controller/Api/RefreshControllerTest.php \
  tests/Command/RefreshFeedsCommandTest.php tests/Service/Social
```

Expected: PASS.

- [ ] **Step 9: Gates**

Run: `bin/console cache:warmup && composer check && composer md`, then the two module greps.
Expected: clean.

- [ ] **Step 10: Commit**

```bash
git add backend/src/Service/Refresh backend/config backend/tests
git commit -m "feat(#1608): a refresh stores each feed's outcome from one pass fetcher, whatever its kind"
```

---

### Task 5: Delete the two-phase path

Spec §2, "fold into the per-pass fetcher or are deleted when no caller remains". Nothing calls them after Task 4.

**Files:**
- Delete: `backend/src/Service/Refresh/SocialFeedRefresher.php`, `backend/src/Service/Refresh/Pass/PrivateFeedSupply.php`
- Delete: `backend/src/Service/Social/TimelineFetcher/ConcurrentTimelineFetcher.php`,
  `backend/src/Service/Social/TimelineFetcher/TimelineFetcherInterface.php`
- Delete: `backend/tests/Service/Social/TimelineFetcher/ConcurrentTimelineFetcherTest.php`, `backend/tests/Support/StubTimelineFetcher.php`
- Move: `backend/tests/Service/Social/TimelineFetcher/TimelineFetcherWiringTest.php` →
  `backend/tests/Service/Refresh/PassFetcher/PassFetcherWiringTest.php`
- Modify: `backend/src/Service/Refresh/Pass/{BudgetedFeedQueue,RefreshPass}.php`, `backend/src/Service/Social/Pass/TimelineRound.php`,
  `backend/src/Service/Refresh/Pass/PassTickets.php`
- Modify: `backend/tests/Support/{SocialApiWiring,StubFeedFetcher}.php`
- Modify: `backend/tests/Service/Refresh/Pass/{BudgetedFeedQueueTest,RefreshPassTest}.php`, `backend/tests/Service/Social/TimelineRoundsTest.php`
- Modify: `docs/security.md` (the `social:` URL bullet, around line 354)

**Interfaces:**
- Consumes: Tasks 3–4.
- Produces: `TimelineRound::ticketFor(int $feedId, SocialFeed $socialFeed): \Generator<int|string, RequestTicketModel>`
  replaces `tickets(iterable)`. `BudgetedFeedQueue` and `RefreshPass` lose `tickets()` and `socialFeeds()`; they keep
  `startedFeeds()`, `socialFeedIds()`, `startedFeedIds()`.

- [ ] **Step 0: Merge develop**

From the repo root: `git status && git fetch origin develop && git merge --no-edit origin/develop`. Then
`php bin/phpunit tests/Service/Refresh` must pass.

- [ ] **Step 1: Move the wiring test**

From the repo root:

```bash
git mv backend/tests/Service/Social/TimelineFetcher/TimelineFetcherWiringTest.php \
  backend/tests/Service/Refresh/PassFetcher/PassFetcherWiringTest.php
```

In it: the namespace becomes `App\Tests\Service\Refresh\PassFetcher`, the class `PassFetcherWiringTest`. Imports:
drop `ConcurrentTimelineFetcher`, `TimelineFetcherInterface`; add `App\Enum\SourceFormat`,
`App\Service\Refresh\Pass\PassFeeds`, `App\Service\Refresh\Pass\RefreshPass`,
`App\Service\Refresh\PassFetcher\ConcurrentPassFetcher`, `App\Service\Refresh\PassFetcher\PassFetcherInterface`,
`Symfony\Component\Clock\MockClock`. Then:

- the first test is renamed `testTheContainerReadsEachNetworksTimelineThroughItsOwnWiring` (unchanged) and its fetch
  becomes:

  ```php
        $fetcher = $container->get(PassFetcherInterface::class);
        self::assertInstanceOf(PassFetcherInterface::class, $fetcher);
        $mastodon = $this->socialFeed(SocialNetwork::Mastodon, 1);
        $bluesky = $this->socialFeed(SocialNetwork::Bluesky, 2);
        $clock = new MockClock('2026-10-13 12:00:00', 'UTC');
        $pass = new RefreshPass(
            [$mastodon->getFeed(), $bluesky->getFeed()],
            $clock,
            $clock->now()->getTimestamp() + 300,
        );

        $outcomes = [];
        foreach ($fetcher->fetchAll(new PassFeeds($pass, [1 => $mastodon, 2 => $bluesky])) as $feedId => $outcome) {
            $outcomes[$feedId] = $outcome::class;
        }
  ```

- the second test becomes:

  ```php
    public function testARefreshAndASingleFeedFetchUseTheOneRequestEngine(): void
    {
        $container = self::getContainer();
        $passes = $container->get(PassFetcherInterface::class);
        $feeds = $container->get(BatchFeedFetcherInterface::class);
        self::assertInstanceOf(ConcurrentPassFetcher::class, $passes);
        self::assertInstanceOf(ConcurrentFeedFetcher::class, $feeds);

        self::assertSame(
            self::engineOf(ConcurrentFeedFetcher::class, $feeds),
            self::engineOf(ConcurrentPassFetcher::class, $passes),
        );
    }
  ```

- in `socialFeed()`, the `Feed` gets its format before it is wrapped:

  ```php
        $feed = self::withId(new Feed('social:x:' . $id . ':home'), $id);
        $feed->setSourceFormat(SocialNetwork::Mastodon === $network ? SourceFormat::MASTODON_API : SourceFormat::BLUESKY_API);

        return new SocialFeed($account, $feed, SocialFeedKind::Home, '');
  ```

- [ ] **Step 2: Delete the dead classes and tests**

From the repo root:

```bash
git rm backend/src/Service/Refresh/SocialFeedRefresher.php backend/src/Service/Refresh/Pass/PrivateFeedSupply.php \
  backend/src/Service/Social/TimelineFetcher/ConcurrentTimelineFetcher.php \
  backend/src/Service/Social/TimelineFetcher/TimelineFetcherInterface.php \
  backend/tests/Service/Social/TimelineFetcher/ConcurrentTimelineFetcherTest.php \
  backend/tests/Support/StubTimelineFetcher.php
```

`tests/Support/SocialApiWiring.php`: delete `timelineFetcher()` and its `ConcurrentTimelineFetcher` import.

- [ ] **Step 3: Fold the per-kind streams away**

`BudgetedFeedQueue.php`: delete `tickets()`, `socialFeeds()` and `startedAmong()`, and the `FetchTicketModel` import.
`startedFeeds()` becomes:

```php
    /** @return \Generator<int, Feed, mixed, void> every kind of feed, in the order the pass was given */
    public function startedFeeds(): \Generator
    {
        foreach ($this->feeds as $feed) {
            if (!$this->mayStartAnother()) {
                return;
            }

            $this->startedFeedIds[] = $feed->requireId();

            yield $feed->requireId() => $feed;
        }
    }
```

Its class docblock becomes: "Starts a pass's due feeds, whatever their kind, in the order the pass was given while
the time budget allows. The engine pulls one per free slot, so the deadline is checked when a fetch would start, and a
wave that finishes early buys the next feed its chance."

`RefreshPass.php`: delete `tickets()` and `socialFeeds()`, and the `FetchTicketModel` import.

`TimelineRound.php`: replace `tickets(iterable $socialFeeds)` with:

```php
    /** @return \Generator<int|string, RequestTicketModel> none when the feed settles without a request */
    public function ticketFor(int $feedId, SocialFeed $socialFeed): \Generator
    {
        $this->socialFeedsByFeedId[$feedId] = $socialFeed;

        yield from $this->ticketOf($feedId);
    }
```

`PassTickets::privateTicketOf()`: `yield from $this->round->tickets([$feedId => $socialFeed]);` becomes
`yield from $this->round->ticketFor($feedId, $socialFeed);`.

`tests/Service/Social/TimelineRoundsTest.php`: `iterator_to_array($rounds->start()->tickets([1 => $socialFeed]))`
becomes `iterator_to_array($rounds->start()->ticketFor(1, $socialFeed))`.

`tests/Service/Refresh/Pass/RefreshPassTest.php`: every `$pass->tickets()` becomes `$pass->startedFeeds()`
(`sed -i '' 's/\$pass->tickets()/$pass->startedFeeds()/' tests/Service/Refresh/Pass/RefreshPassTest.php`, then
`grep -n "tickets()" tests/Service/Refresh/Pass/RefreshPassTest.php` prints nothing).

`tests/Service/Refresh/Pass/BudgetedFeedQueueTest.php`:
- `testYieldsEveryFeedWhenTheBudgetIsAmple`, `testStopsYieldingOnceTheDeadlineIsWithinTheSafetyMargin`,
  `testAlwaysYieldsTheFirstFeedEvenWithNoBudgetLeft` and `testAnEmptyFeedListStartsAndSkipsNothing`: `$queue->tickets()`
  becomes `$queue->startedFeeds()`;
- delete `testCarriesTheFeedsConditionalGetHeaders` (now `PassTicketsTest::testAnRssFeedBecomesAFeedTicketOnItsHostCarryingItsValidators`),
  `testASocialFeedGetsNoTicketButItsOwnTurnAfterTheTickets` (now `testStartsEveryKindOfFeedInTheOrderItWasGiven`) and
  the Task 3 duplicate `testStartedFeedsStopOnceTheDeadlineIsWithinTheSafetyMargin`;
- `testASocialFeedWaitsForTheBudgetLikeAnyOther` becomes:

  ```php
    public function testAPrivateFeedWaitsForTheBudgetLikeAnyOther(): void
    {
        $clock = new MockClock('2026-07-26 12:00:00', 'UTC');
        $rss = self::withId(new Feed('https://feed1.example.com/rss'), 1);
        $timeline = self::withId(self::socialFeed('social:bluesky:1:home'), 2);
        $queue = new BudgetedFeedQueue([$rss, $timeline], $clock, $clock->now()->getTimestamp() + 5);

        self::assertSame([1 => $rss], iterator_to_array($queue->startedFeeds()));
        self::assertSame(1, $queue->skippedCount());
    }
  ```

- [ ] **Step 4: Simplify the single-feed stub**

Run: `grep -rn "new StubFeedFetcher(\$\|secondsPerFetch\|concurrency:" tests | grep -v "Support/StubFeedFetcher.php\|StubPassFetcher\|BatchFeedFetcher/ConcurrentFeedFetcherTest"`

Expected: nothing on a `StubFeedFetcher`. If so, in `tests/Support/StubFeedFetcher.php` delete the constructor (its
`MockClock` and `concurrency`), `$secondsPerFetch` and `runWave()`, and `fetchAll()` becomes:

```php
    /** @return \Generator<int|string, FetchOutcomeModel> */
    public function fetchAll(iterable $tickets): \Generator
    {
        foreach ($tickets as $key => $ticket) {
            $this->fetchedUrls[] = $ticket->url;

            $result = $this->results[$ticket->url]
                ?? $this->fallbackResult
                ?? throw new \LogicException('No stubbed result for ' . $ticket->url);

            yield $key => $result instanceof FetchException
                ? FetchOutcomeModel::failed($result)
                : FetchOutcomeModel::succeeded($result);
        }
    }
```

Drop its `MockClock` import. If the grep finds a caller, leave the stub as it is and say so in the task report.

- [ ] **Step 5: Update the doc**

`docs/security.md`, the bullet "A private feed's synthetic `social:` URL never reaches `UrlGuard` or the feed fetcher:
only `ConcurrentTimelineFetcher` reads the feed, through its account." becomes "…only the refresh's
`ConcurrentPassFetcher` reads it, through its account, on the same engine run as the RSS feeds (#1608)."

- [ ] **Step 6: Prove nothing is left behind**

```bash
grep -rn "SocialFeedRefresher\|PrivateFeedSupply\|TimelineOutcomeStore\|TimelineFetcher\|StubTimelineFetcher\|slotKeyFor\|slotCapacity\|->socialFeeds()" \
  src tests config ../docs --include=*.php --include=*.yaml --include=*.md | grep -v "docs/superpowers"
```

Expected: nothing.

- [ ] **Step 7: Run the suites this touched**

Run: `bin/console cache:warmup && php bin/phpunit tests/Service/Refresh tests/Service/Social tests/Service/Fetch tests/Service/Maintenance`
Expected: PASS.

- [ ] **Step 8: Gates**

Run: `composer check && composer md`, then the two module greps.
Expected: clean.

- [ ] **Step 9: Commit**

```bash
git add -A backend/src backend/tests backend/config docs/security.md
git commit -m "refactor(#1608): delete the separate private-feed phase"
```

`git add -A` is scoped to these paths so the deletions and the move are staged; check `git status` first and leave
anything another session changed outside them.

---

### Task 6: The "Done when" tests across passes and a docs sweep

The remaining "Done when" items, at the runner: the staleness promise over several passes, `remaining` in a mixed
pass (completed, throttled, aborted). Then a sweep for docs that still describe two phases or a per-run slot cap.

**Files:**
- Create: `backend/tests/Service/Refresh/RefreshRunner/RefreshRunnerStalenessTest.php`
- Modify: `backend/tests/Service/Refresh/RefreshRunner/RefreshRunnerSocialTest.php`
- Modify only if the sweep finds something: `docs/architecture.md`, `docs/security.md`

**Interfaces:**
- Consumes: `StubPassFetcher`, `RefreshRunners::build(PassFetcherInterface, BatchFeedFetcherInterface)`,
  `SocialApiWiring::passFetcher(...)`, `PrivateFeedsWiring::build(...)->create(...)`.
- Produces: tests only.

- [ ] **Step 0: Merge develop**

From the repo root: `git status && git fetch origin develop && git merge --no-edit origin/develop`. Then
`php bin/phpunit tests/Service/Refresh` must pass.

- [ ] **Step 1: Write the staleness test**

Create `tests/Service/Refresh/RefreshRunner/RefreshRunnerStalenessTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Refresh\RefreshRunner;

use App\Entity\Feed;
use App\Entity\SocialAccount;
use App\Entity\Subscription;
use App\Entity\User;
use App\Enum\SocialFeedKind;
use App\Service\Fetch\Model\FetchResponseModel;
use App\Service\Refresh\Model\RefreshRequestModel;
use App\Service\Refresh\RefreshRunner\RefreshRunner;
use App\Service\Social\Model\PrivateFeedModel;
use App\Service\Social\Model\TimelineOutcome\ThrottledTimelineModel;
use App\Tests\DbTestCase;
use App\Tests\Support\PrivateFeedsWiring;
use App\Tests\Support\RefreshRunners;
use App\Tests\Support\SocialAccounts;
use App\Tests\Support\SocialFeeds;
use App\Tests\Support\StubFeedFetcher;
use App\Tests\Support\StubPassFetcher;
use App\Tests\Support\UserFactory;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/** A pass whose budget fits one feed still reaches a due private feed once it is the stalest one (#1608). */
final class RefreshRunnerStalenessTest extends DbTestCase
{
    private const string SITE = 'https://staleness.example';

    private MockClock $clock;
    private User $subscriber;
    private SocialAccount $account;
    private StubPassFetcher $passes;
    private StubFeedFetcher $homepages;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clock = new MockClock('2026-10-13 12:00:00', 'UTC');
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        $this->subscriber = (new UserFactory($this->entityManager, $hasher))->create('staleness@example.test');
        $this->account = SocialAccounts::mastodonOf($this->subscriber);
        $this->entityManager->persist($this->account);
        $this->entityManager->flush();
        $this->passes = new StubPassFetcher($this->clock, concurrency: 1);
        $this->passes->secondsPerFetch = 100;
        $this->homepages = new StubFeedFetcher();
        $this->homepages->willReturn(
            self::SITE,
            FetchResponseModel::fetched(self::SITE, false, '<html lang="en"></html>', null, null),
        );
    }

    public function testAPrivateFeedStartsOnceItIsTheStalestDueFeedWhileRssFeedsFillEveryPass(): void
    {
        foreach (['-3 hours', '-2 hours', '-90 minutes'] as $index => $overdue) {
            $this->rssFeedDue(sprintf('%s/feed-%d.xml', self::SITE, $index), $overdue);
        }
        $privateFeedId = $this->privateFeedDue('-1 hour')->requireId();
        $this->entityManager->flush();

        $startedByPass = [];
        for ($passNumber = 1; $passNumber <= 4; ++$passNumber) {
            $report = $this->runner()->run(RefreshRequestModel::allDue(60));
            $startedByPass[] = $report->total - $report->skippedForBudget;
            $this->clock->modify('+1 day');
        }

        self::assertSame([1, 1, 1, 1], $startedByPass, 'each pass fits one feed, and every feed is due again');
        self::assertCount(3, $this->passes->fetchedUrls, 'the three stalest RSS feeds took the first three passes');
        self::assertSame([$privateFeedId], $this->passes->asked, 'the private feed took the fourth');
    }

    private function runner(): RefreshRunner
    {
        return RefreshRunners::fromContainer(self::getContainer(), $this->entityManager, $this->clock)
            ->build($this->passes, $this->homepages);
    }

    private function rssFeedDue(string $url, string $overdue): void
    {
        $feed = new Feed($url);
        $feed->scheduleNextFetchAt($this->clock->now()->modify($overdue));
        $this->entityManager->persist($feed);
        $this->entityManager->persist(new Subscription($this->subscriber, $feed, $this->clock->now()));
        $this->passes->willReturn($url, FetchResponseModel::notModified($url, false, null, null));
    }

    private function privateFeedDue(string $overdue): Feed
    {
        PrivateFeedsWiring::build(self::getContainer(), $this->entityManager, $this->clock)
            ->create($this->account, PrivateFeedModel::home());
        $socialFeed = SocialFeeds::repository($this->entityManager)->findOneFor($this->account, SocialFeedKind::Home, '');
        self::assertNotNull($socialFeed);
        $feed = $socialFeed->getFeed();
        $feed->scheduleNextFetchAt($this->clock->now()->modify($overdue));
        $this->passes->willReadTimeline($feed->getUrl(), new ThrottledTimelineModel(null));

        return $feed;
    }
}
```

`FeedScheduler` caps a healthy feed's interval at 2 hours (`CEILING_MINUTES`), so after `+1 day` every RSS feed is due
again in each pass, later than the private feed's original slot.

- [ ] **Step 2: Run it**

Run: `php bin/phpunit tests/Service/Refresh/RefreshRunner/RefreshRunnerStalenessTest.php`
Expected: PASS (Tasks 3–5 already deliver the behaviour).

- [ ] **Step 3: Break what it guards, then restore by hand**

In `src/Service/Refresh/Pass/BudgetedFeedQueue.php`, `startedFeeds()`, temporarily replace
`foreach ($this->feeds as $feed) {` with the old order, RSS first:

```php
        $isPrivate = static fn (Feed $feed): bool => SourceFormat::isSocial($feed->getSourceFormat());
        $rssFirst = [
            ...array_filter($this->feeds, static fn (Feed $feed): bool => !$isPrivate($feed)),
            ...array_filter($this->feeds, $isPrivate),
        ];
        foreach ($rssFirst as $feed) {
```

Run: `php bin/phpunit tests/Service/Refresh/RefreshRunner/RefreshRunnerStalenessTest.php`
Expected: FAIL, quoted: `the three stalest RSS feeds took the first three passes` (4 URLs fetched) or
`the private feed took the fourth` (`[]` asked).

Restore the line to `foreach ($this->feeds as $feed) {` **by editing it back** (never `git checkout --`), delete the
two added statements, and rerun: PASS. `git diff src/Service/Refresh/Pass/BudgetedFeedQueue.php` must be empty.

- [ ] **Step 4: Add the mixed-pass runner tests**

`RefreshRunnerSocialTest.php`, add:

```php
    public function testAMixedPassReadsFeedsAndTimelinesInOneRunAndLeavesNothingRemaining(): void
    {
        $this->rssFeed();
        $this->privateFeed(PrivateFeedModel::home());
        $this->privateFeed(PrivateFeedModel::userList('42', 'Friends'));
        $http = new RoutedHttpClient([
            self::RSS => self::rssBody(),
            self::HOME => self::statuses(),
            self::FRIENDS => self::statuses(),
        ]);

        $report = $this->runner($http)->run(RefreshRequestModel::allDue(300));

        self::assertSame('completed', $report->status);
        self::assertSame(3, $report->fetched);
        self::assertSame(0, $report->remaining);
    }

    public function testAThrottledAccountsWithheldFeedIsTalliedSoNothingStaysRemaining(): void
    {
        $this->rssFeed();
        $this->privateFeed(PrivateFeedModel::home());
        $this->privateFeed(PrivateFeedModel::userList('42', 'Friends'));
        $http = new RoutedHttpClient([
            self::RSS => self::rssBody(),
            self::HOME => self::throttled(),
            self::FRIENDS => self::throttled(),
        ]);

        $report = $this->runner($http, accountConcurrency: 1)->run(RefreshRequestModel::allDue(300));

        self::assertSame('completed', $report->status);
        self::assertSame(1, $report->fetched);
        self::assertSame(2, $report->throttled);
        self::assertSame(0, $report->remaining);
        self::assertEqualsCanonicalizing([self::RSS, self::HOME], $http->requestLines());
    }

    public function testAnAbortInAMixedPassLeavesEveryUnprocessedFeedRemaining(): void
    {
        $this->rssFeed();
        $this->privateFeed(PrivateFeedModel::home());
        $this->privateFeed(PrivateFeedModel::userList('42', 'Friends'));
        $http = new RoutedHttpClient([
            self::RSS => self::rssBody(),
            self::HOME => self::statuses(),
            self::FRIENDS => self::statuses(),
        ]);
        $failing = new FlushFailingEntityManager(
            $this->entityManager,
            failingFlush: 2,
            thrown: DuplicateKeyViolation::exception(),
        );

        $report = $this->runner($http, flushing: $failing)->run(RefreshRequestModel::allDue(300));

        self::assertSame('aborted', $report->status);
        self::assertSame(2, $report->remaining, 'three due, one stored before the failing one');
    }
```

The abort cancelling what is in flight is proven where it happens, by
`ConcurrentPassFetcherTest::testAbandoningAMixedPassCancelsEveryRequestInFlight` (Task 3).

- [ ] **Step 5: Run them**

Run: `php bin/phpunit tests/Service/Refresh/RefreshRunner`
Expected: PASS. If `remaining` in the abort test reads otherwise, read `RefreshPass::abortedDuringOutcomes()` and
`FlushFailingEntityManager` before you change the expected value: it is `count(feeds) - processed`, and the
failing flush is the second one. Report the reasoning if you change it.

- [ ] **Step 6: Sweep the docs**

```bash
grep -rn "RSS first\|after the RSS\|second phase\|two-phase\|slot capacity\|per-run\|social_account_concurrency\|TimelineFetcher\|SocialFeedRefresher" \
  ../docs ../README.md ../CLAUDE.md 2>/dev/null | grep -v "docs/superpowers"
```

Expected: only `docs/security.md`'s refresh worker bullet (updated in Task 2). Update any other hit that describes the
two-phase refresh or a per-run slot capacity; leave everything else.

- [ ] **Step 7: Commit**

```bash
git add backend/tests/Service/Refresh/RefreshRunner docs
git commit -m "test(#1608): a private feed starts once it is the stalest, and a mixed pass leaves the right remaining"
```

---

### Task 7: Final gates

**Files:** none, unless a gate finds something.

- [ ] **Step 1: Merge develop once more**

From the repo root: `git fetch origin develop && git merge --no-edit origin/develop`.

- [ ] **Step 2: Static gates**

From `backend/`:

```bash
bin/console cache:warmup
composer check
composer md
```

Expected: `composer check` clean (cs, stan with every house rule, tramp). `composer md` names no file this branch
touches (`git diff --name-only origin/develop -- 'backend/src/*.php'`). If `composer tramp` fails on a chain no commit
here explains, check `composer show larspohlmann/phptramp` first: CI runs phptramp's `develop` tip.

- [ ] **Step 3: PhpStorm inspections**

Load the tool with ToolSearch `select:mcp__phpstorm__lint_files`, then lint every PHP file the branch changed
(`git diff --name-only origin/develop -- '*.php'`). ERROR and WARNING block; weak warnings are advisory.

- [ ] **Step 4: Both suite legs, one after the other**

From `backend/`: `composer test:parallel`. Expected: green.

Then, from the repo root, the MySQL leg (clear the container's compiled cache first; the Docker dev container can serve
a stale DI container):

```bash
docker compose exec php bin/console cache:clear
docker compose exec php composer test
```

Expected: green. A failure only in the rate-limiter test is a known flake: rerun once.

- [ ] **Step 5: Mutation gate**

From `backend/`: `git fetch origin develop && composer infection:diff`

Expected: `minMsi` met. Each escaped mutant on a touched line gets a test. Two known equivalents: removing
`$response->cancel()` in `ConcurrentRequestEngine::retire()` or in `sendAll()`'s `finally` is equivalent against
`MockHttpClient`; if one escapes, add the method to the `MethodCallRemoval` ignore list in `infection.json5` under its
existing comment. Never lower `minMsi`. If the initial run OOMs at 512M, rerun.

- [ ] **Step 6: The dev log**

Scan today's dev log without triggering a refresh (do not refresh against the dev database):

```bash
ls -t backend/var/log/dev-*.log | head -1 | xargs tail -n 300 | jq 'select(.level_name != "DEBUG" and .level_name != "INFO")'
```

Expected: no new deprecation or error from Fetch, Social or Refresh.

- [ ] **Step 7: Module direction and leftovers**

```bash
grep -rnE 'App\\Service\\(Social|Refresh)' backend/src/Service/Fetch
grep -rn 'App\\Service\\Refresh' backend/src/Service/Social
git diff --stat origin/develop -- frontend backend/migrations
```

Expected: all three empty.

- [ ] **Step 8: Commit any gate fix**

If a gate needed a fix, commit it as `fix(#1608): …` or `test(#1608): …`, staging only the files you changed. Do not
push.

## Self-review

- **Spec coverage:**
  - §1 (a ticket names its own slots; RSS `host:` at 2; timeline `social-account:` at 3 and `social-host:` at a new
    `social_host_concurrency: 4`; distinct names; `CapacityGuard`; `ConcurrentFeedFetcher` unchanged in behaviour):
    Task 2.
  - §2 (`findDue` order whatever the kind; deadline per start; first always starts; one ticket stream; held, orphan
    and pre-send failures yielded as they settle; gate withholds only private tickets; outcomes routed by kind and
    persisted sequentially with today's tally, abort and `remaining`; renewal as a second small run, skipped on abort;
    the old classes folded or deleted; Refresh → Social → Fetch): Tasks 3, 4, 5.
  - §3 (one seam in `Service/Refresh`; refresh-driving tests on one stub merged from `StubFeedFetcher`'s refresh role
    and `StubTimelineFetcher`; single-feed paths keep `BatchFeedFetcherInterface`; production covered over
    `RoutedHttpClient` through the real engine): Tasks 3, 4.
  - §4 (429 on every path, proxied and direct): Task 1.
  - "Done when":
    - on the wire before the first answer: `ConcurrentPassFetcherTest::testRssAndPrivateTicketsAreOnTheWireBeforeTheFirstAnswerIsRead`;
    - stalest private feed within bounded passes: `RefreshRunnerStalenessTest`, with a break test;
    - caps: `testAccountServerAndFeedHostCapsAllHoldThroughAMixedRun`, `testTheServerCapHoldsAcrossAccountsWhileRssRunsAlongside`,
      `testAFeedAndATimelineOnOneHostNeverCountAgainstEachOther`, plus the unchanged `ConcurrentFeedFetcherTest` host caps;
    - throttled or rejected account isolates only itself: `testAThrottledAccountWithholdsOnlyItsOwnFeedsWhileRssKeepsRunning`,
      `testARefusedAccountWithholdsOnlyItsOwnFeedsWhileRssKeepsRunning`;
    - stale session renews once, second wave: `testAStaleSessionsFeedsRunInASecondWaveAfterTheRssFeedsLanded` and the
      ported renewal tests;
    - abort and `remaining`: `testAbandoningAMixedPassCancelsEveryRequestInFlight`,
      `testAnAbortInAMixedPassLeavesEveryUnprocessedFeedRemaining`, `testAThrottledAccountsWithheldFeedIsTalliedSoNothingStaysRemaining`;
    - 429 one request: the seven Task 1 tests.
  - Constraints: docs (Tasks 1, 2, 5, 6), merges (Step 0 of Tasks 2–6), gates (Task 7).
- **Placeholder scan:** every code step shows its code; every command has its expected result. The instructions that
  edit an existing test name the file, the method or a grep, and the exact replacement.
- **Type consistency:**
  - `RequestSlotModel::named/perHost/keyFor/$capacity` (Tasks 2, 3).
  - `RequestTicketModel::feed(FetchTicketModel, int)`, `::api(ApiRequestModel, non-empty-list<RequestSlotModel>)`,
    `$slots` (Tasks 2, 3); `FeedTickets::of()` / `cappedAt()` (Task 2).
  - `ConcurrentRequestEngine::sendAll(iterable, ?DispatchGateInterface)` (Tasks 2, 3).
  - `TimelineSlotsModel(int, int)::ticketFor(SocialAccount, ApiRequestModel)`, `TimelineRounds(TimelineSources, int, int)::start()`,
    `TimelineRound(TimelineSources, TimelineAccounts, TimelineSlotsModel)` (Tasks 2, 3); `TimelineRound::tickets()` until
    Task 5, then `ticketFor(int, SocialFeed)`.
  - `PassFeeds(RefreshPass, array<int, SocialFeed>)` with `started()`, `isPrivate()`, `socialFeedOf()` (Tasks 3, 4, 5).
  - `PassTickets(PassFeeds, TimelineRound, int)` (Tasks 3, 5).
  - `PassFetcherInterface::fetchAll(PassFeeds)`, `ConcurrentPassFetcher(ConcurrentRequestEngine, TimelineRounds, int)`
    (Tasks 3, 4, 5).
  - `RefreshPassFetcher(PassFetcherInterface, FeedOutcomePersister, SocialFeedRepository)`,
    `PassOutcomeStore(FeedOutcomePersister, \DateTimeImmutable)::store(Feed, FetchOutcomeModel|TimelineOutcomeModelInterface)`
    (Task 4).
  - `StubPassFetcher`, `RefreshFetchers::install(ContainerInterface, StubPassFetcher, string)`,
    `RefreshRunners::build(PassFetcherInterface, BatchFeedFetcherInterface)` (Tasks 4, 6).
  - `SocialApiWiring::timelineRounds()` (Task 2), `passFetcher()` and `RSS_HOST` (Task 3), `timelineFetcher()` deleted
    (Task 5).

## Preflight notes

Spec ambiguities and conflicts found while reading the code, and how this plan resolves them:

1. **§4 names the wrong guard for a proxied RSS 429.** `FetchRetryPolicy::nextAttemptAfter()` hands a proxied attempt
   its direct fallback before it ever calls `warrantsAnotherFamily()`. Refusing `FeedThrottledException` only there would
   still spend a second request on a proxied RSS 429. The refusal sits at the top of `nextAttemptAfter()` instead,
   which covers both routes; `warrantsAnotherFamily()` is unchanged.
2. **"Routed by the kind of the feed the key belongs to" (§2).** The kind decides in `PassTickets::settle()` (RSS passes
   through, private goes to `TimelineRound::settle()`). The seam then yields typed outcomes, so `RefreshPassFetcher`
   routes by outcome type through one store. The two always agree.
3. **`TimelineOutcomeStore` (named in §2) is renamed `PassOutcomeStore`**: it now stores RSS outcomes too.
4. **Two caps for a timeline need a home.** A root service takes only collaborators and configured scalars, and
   `TimelineRound` is a per-call object. So a new root service `Social/TimelineRounds` holds both configured caps and
   starts each round with a `TimelineSlotsModel`. The spec does not name it.
5. **`social-host:` is fixed at ticket creation** (the request URL), as the spec says. API tickets never follow a
   redirect. The RSS `host:` slot keeps following each redirect hop, as today (`RequestSlotModel::perHost()`).
6. **Held accounts in practice.** `FeedRepository::findDue` already leaves out the private feeds of an account that
   needs reconnecting (`restrictToConnectedAccounts`). So a "held" feed in a pass is one whose account got throttled or
   refused earlier in the same pass. The code handles a paused account from the start too, and the unit tests cover
   both.
7. **Private feeds' rows are loaded once, before the pass starts** (`findWithAccountByFeedIds` over every social feed
   id), instead of after the RSS phase. That is one query per pass, as before.
8. **The bounded look-ahead stays (§1).** Many parked tickets of one busy account can fill `FetchQueue`'s look-ahead
   (the engine concurrency, 8) and hold later feeds back until a slot frees. The spec keeps this behaviour; no change.
9. **Dropped test:** `SocialFeedRefresherTest::testAPassWithoutPrivateFeedsNeverStartsTheTimelineFetcher` has no
   meaning once there is no separate timeline phase.
10. **MaintenanceTickTest timing.** The old shared, clocked `StubFeedFetcher` also charged the favicon lookup a wave's
    seconds. Splitting the roles halves the simulated refresh time; Task 4 shows why both sweep assertions still hold.
11. **`StubFeedFetcher` loses its waves and clock** (Task 5) only if no caller is left; the plan checks with a grep
    first.
