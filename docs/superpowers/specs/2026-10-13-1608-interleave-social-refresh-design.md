# #1608 — private social feeds share one engine run with RSS (design)

Issue: https://github.com/larspohlmann/simple-feed-reader/issues/1608. Approach A, agreed 2026-10-13.

## Problem

`RefreshPassFetcher::fetchAndPersist` drains the whole RSS batch, then `SocialFeedRefresher::refreshAll` starts the
private Bluesky/Mastodon feeds. Both are gated by the pass deadline (`BudgetedFeedQueue`), so a pass whose RSS batch
reaches the deadline starts no private feed, and the next pass repeats the order. Private timelines can lag pass after
pass, worst on Strato's short cron ticks.

## Decisions

### 1. The engine: a ticket names its own slots

- `ConcurrentRequestEngine::sendAll(iterable $tickets, ?DispatchGateInterface $gate = null)` drops the per-run
  `int $slotCapacity`. Every `RequestTicketModel` carries one or more slots, each a name and a capacity. A ticket may go
  on the wire only when **every** slot it names has room; it holds all of them while in flight and releases all on
  retirement.
- RSS feed ticket: `host:<HostKey>` with capacity `fetch_host_concurrency` (2).
- Private timeline ticket: `social-account:<accountId>` with capacity `social_account_concurrency` (3) **and**
  `social-host:<HostKey of the request URL>` with capacity new parameter `social_host_concurrency: 4` (half the default
  `FETCH_CONCURRENCY` of 8, so one Mastodon instance or Bluesky PDS can never hold the whole run).
- The social host slot is a different name from the RSS host slot: feeds and timelines on one host do not count
  against each other.
- `RequestSlots`/`FetchQueue` keep their behaviour (parking, bounded look-ahead, lazy pull of the ticket source).
  Slot capacity is validated at least one (`CapacityGuard`).
- `ConcurrentFeedFetcher` (single-feed callers: `HttpFeedFetcher`, `WellKnownFeedProbe`, `FaviconResolver`) builds feed
  tickets with the host slot; its behaviour is unchanged.

### 2. The pass: one engine run in staleness order

- `BudgetedFeedQueue` hands out every due feed in `FeedRepository::findDue` order (never fetched first, then most
  overdue), whatever its kind. The deadline check stays per start (a feed counts as started only when a slot opens for
  it) and the first feed of a pass always starts (`mayStartAnother`).
- A per-pass fetcher in `Service/Refresh` builds one ticket stream from that order:
  - an RSS/Atom feed → a feed ticket;
  - a private feed → its `TimelineRound` ticket (Bluesky session opened at most once per account per pass, as today).
    A held account's feeds (throttled / needs reconnect in this pass), a feed whose `SocialFeed` row is gone (orphan),
    and a request that fails before sending settle without a request and are yielded as soon as they settle.
- The dispatch gate withholds only private tickets of a held account; RSS tickets are always admitted.
- Outcomes are routed as they land, by the kind of the feed the key belongs to: RSS → `FeedOutcomePersister::persist`;
  private → `TimelineRound::settle` → `TimelineOutcomeStore`. Persisting stays sequential on the one EntityManager, with
  today's tally, abort and `remaining` semantics (`RefreshPass::abortedDuringOutcomes`, `abortedAfterOutcomes`,
  `finished`).
- The Bluesky stale-session renewal stays a second, small engine run after the main one: only the accounts whose
  session went stale, their feeds sent again once. An abort ends the pass before it.
- `ConcurrentTimelineFetcher`, `TimelineFetcherInterface`, `SocialFeedRefresher` and `PrivateFeedSupply` fold into the
  per-pass fetcher or are deleted when no caller remains. `BatchFeedFetcherInterface` stays for the single-feed paths.
- Service-module direction stays Refresh → Social → Fetch and Refresh → Fetch; no cycle.

### 3. One test seam for a refresh

- The refresh pass depends on one interface in `Service/Refresh` that yields, per feed id, the feed's outcome as it
  lands (an RSS fetch outcome or a timeline outcome). Production implements it over the engine.
- Tests that today replace `BatchFeedFetcherInterface` or `TimelineFetcherInterface` to drive a refresh (controller,
  command, worker, `RefreshRunners`, `SocialFeedRefresherTest`) switch to one stub of that interface, merged from
  `StubFeedFetcher` (its refresh role) and `StubTimelineFetcher`. Tests that stub `BatchFeedFetcherInterface` for the
  single-feed paths keep doing so.
- The production implementation is covered against `MockHttpClient`/`RoutedHttpClient` through the real engine.

### 4. A 429 never earns another route

- `CrossFamilyFailover::isRetryableStatus` excludes 429 (`Response::HTTP_TOO_MANY_REQUESTS`). This covers
  `ApiResponseExchange` (engine API tickets) and `FailoverRequestSender` (`GuardedApiClient`).
- `FetchRetryPolicy::warrantsAnotherFamily` refuses `FeedThrottledException` (RSS 429).
- A 429 then costs exactly one request on every path: no direct fallback after a proxied attempt, no other address
  family. Ships first, as its own task.

## Done when (tests)

- In a mixed pass, RSS and private tickets are on the wire before the first answer is consumed.
- A pass whose RSS feeds exhaust the budget still starts a due private feed within a bounded number of passes: the
  private feed starts once it is the stalest due feed (multi-pass test, clock advancing, RSS feeds reappearing due).
- Account and social-host caps hold inside a mixed run; RSS host caps unchanged.
- A throttled or rejected account withholds only its own private feeds; RSS keeps running in the same run.
- A stale Bluesky session renews once and its feeds run in the second wave.
- Abort cancels everything in flight; `remaining` reflects the work done.
- A 429 costs one request on the engine API path, the `GuardedApiClient` path and the RSS path, proxied and direct.

## Constraints

CLAUDE.md in full: Clean Code, role folders (§10: `Pass/` for per-call objects, `Model/`, `Support/`, interface
folders), no Service-module cycle, phptramp, PHPMD clean on touched `src`, `Response::HTTP_*`, no needless comments,
PHPStan level max. Docs: update `docs/security.md`/`docs/architecture.md` only where they describe the two-phase refresh
or the per-run slot capacity. Commits `type(#1608): …`, no attribution. Merge develop into the branch between tasks.
Never deploy.
