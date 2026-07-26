# Concurrent Feed Fetch — Design

**Date:** 2026-07-26
**Issue:** [#116](https://github.com/larspohlmann/simple-feed-reader/issues/116)
**Branch:** `feature/116-concurrent-feed-fetch`

## Problem

`RefreshRunner::refresh()` walks its due feeds in a strictly sequential
`foreach`, so a sweep costs the **sum** of every feed's network round trip. The
work is network-wait-bound, not CPU-bound, which makes a serial loop the wrong
shape for it.

Measured against a real 24-feed subscription set (warm: 12 of 24 answered
`304`):

```
SEQUENTIAL TOTAL: 5.37s over 24 feeds | slowest single feed 0.72s
```

The full `app:feeds:refresh --user=<id>` run took **5.77s**, so ~93% of it is
network wait — parse, ingest and flush together are ~0.4s. DNS is 4–50ms per
feed and negligible.

Two cases the warm measurement understates:

- **Slow or dead feeds.** A feed that times out costs 10s, and a pathological
  redirect chain up to 120s (5 hops × 20s `max_duration`, per the comment in
  `RefreshFeedsCommand`). Sequentially one such feed eats the entire 25s
  `RefreshController` budget and forces the frontend into another poll round.
- **Cold feed sets.** `resolveFaviconIfMissing()` performs a *second* full HTTP
  fetch — of the site homepage, typically slower than feed XML — inline in the
  per-feed loop. On freshly added feeds this dominates.

## Goal

Fetch concurrently, process serially. With a concurrency cap of 8, wall-clock
becomes roughly `max(5.37/8, 0.72)` ≈ **~1s instead of 5.4s** on the measured
set, and a single timing-out feed delays only itself instead of the whole sweep.

Symfony's HttpClient already multiplexes via `stream()`, so this needs no new
dependency.

## Non-goals

- **The global refresh lock.** `RefreshRunner::LOCK_NAME` is application-wide, so
  two users refreshing at once still serialize completely. Scoping it per user is
  a separate change and interacts with this one (N users × 8 connections is a
  real load question on the Strato host).
- **Any API change.** `/api/refresh` keeps the same report body and the same
  `status`/`remaining` polling contract, so the native-iOS constraint in
  `docs/architecture.md` §6 is unaffected.
- **No schema change**, therefore no migration.

## Architecture

### The fetch layer

`classify()` in `HttpFeedFetcher` is the SSRF/redirect **security boundary**.
There must be exactly one copy of it, so the serial path becomes a special case
of the concurrent one rather than a second implementation living beside it.

New value objects in `Service/Fetch/`:

- **`FetchTicket`** — `final readonly` holding url, etag, lastModified. Also
  retires the current three-positional-argument `fetch()` signature.
- **`FetchOutcome`** — `final readonly`, carrying either a `FetchResponse` or a
  `FetchException`. This is deliberate, and not a violation of "errors are
  exceptions": in a batch you cannot throw for one feed without killing the
  other seven, so failure becomes an explicit typed result the caller unwraps.

New interface:

```php
interface BatchFeedFetcherInterface
{
    /**
     * @param iterable<TKey, FetchTicket> $tickets
     * @return iterable<TKey, FetchOutcome> yielded as each completes
     */
    public function fetchAll(iterable $tickets): iterable;
}
```

Implementation split so no class exceeds its PHPMD budget:

- **`ResponseClassifier`** — the existing `classify()` lifted out unchanged.
  Returns a terminal outcome or the next hop's URL. One copy, shared by both
  paths.
- **`FetchAttempt`** — per-feed hop state (current URL, hop count,
  `permanentRedirect`, the originating ticket). Replaces the current
  by-reference `bool &$permanentRedirect` parameter, which is the kind of hidden
  side effect the house style forbids.
- **`ConcurrentFeedFetcher`** — owns the `stream()` loop. Fills the in-flight set
  to the concurrency cap, then `foreach ($client->stream($inFlight))`: on
  `isFirst()` the headers are available and `ResponseClassifier` decides (3xx →
  `cancel()` and re-enqueue that feed at hop+1; 304/410/error → yield the
  outcome; 2xx → keep streaming); on `isLast()` yield the fetched body.
  `UrlGuard::assertSafe()` still runs before **every** hop — DNS measured at
  4–50ms, so it stays synchronous.

`HttpFeedFetcher::fetch()` then delegates: one ticket through `fetchAll()`,
unwrap, rethrow. Its four existing consumers (`FaviconResolver`,
`FeedDiscovery`, `FeedPreviewService`, `BackfillPublishedDatesCommand`) and
`StubFeedFetcher` are untouched.

Concurrency is a **bound configuration parameter**, not a `const`, so the Strato
host can be tuned without a code change. Default 8; worst case 8 × the 5MB
`MAX_BYTES` cap is 40MB against a 512MB limit. `CurlHttpClient` already caps
per-host parallelism at `max_host_connections=6`.

### RefreshRunner

`RefreshRunner` swaps its `FeedFetcherInterface` dependency for
`BatchFeedFetcherInterface` — no new constructor parameter, so the existing
`@SuppressWarnings("PHPMD.ExcessiveParameterList")` does not get worse. The loop
becomes:

```php
foreach ($this->fetcher->fetchAll($tickets) as $feedId => $outcome) {
    // parse → ingest → flush, serially — unchanged
}
```

Parse, ingest and flush stay serial and unchanged; Doctrine semantics are
untouched.

### Favicon resolution

In scope. A cold sweep of N new feeds currently pays N serial homepage round
trips, enough to hide the whole win from a first-time user. Once `fetchAll()`
exists this is cheap — but it does change *when* a favicon is written, so the
refresh grows an explicit second phase:

1. **Fetch feeds concurrently, process serially.** As above. A feed that still
   has no favicon after ingestion is recorded as needing one instead of being
   resolved inline; everything else about the per-feed flush is unchanged.
2. **Resolve the collected favicons concurrently**, then write and flush them in
   one pass.

Two consequences to respect:

- Ingestion is what fills in `siteUrl`, and the resolver reads it — so phase 2
  must run after phase 1 has flushed, not alongside it.
- If phase 1 returns `Aborted` the EntityManager is closed, so phase 2 is
  skipped entirely, exactly as `countDue` and pruning already are.

`RefreshRunner` is `FaviconResolver`'s only production caller, so there is no
second path to keep in sync and no reason to extract an icon-picking
collaborator: `resolve(?string $baseUrl)` simply becomes a batch method that
takes the base URLs of the feeds needing an icon and returns one icon URL per
feed. The icon-selection rules (`pickIcon`, `largestSize`, `httpsOrigin`) stay
exactly where they are, with one copy, as they are today.

`FaviconResolver` keeps its never-throws contract — a favicon is a nicety, and a
failure must not disturb the refresh that asked for it. A feed whose favicon
resolution fails is simply left without one and retried on the next sweep, which
is today's behaviour too.

## Semantics needing care

1. **Budget.** The deadline stops gating "process feed N" and starts gating
   "*start* a new fetch". Feeds never started become `skippedForBudget`. The
   "first feed is always attempted" invariant must survive: the frontend poll
   loop never terminates if a call can return having touched nothing.
2. **Abort.** A failed persist closes the EntityManager and must stop the run,
   but responses are still in flight. `fetchAll()` must be a generator whose
   `finally` cancels the in-flight set when the caller breaks out of it.
3. **Ordering.** Outcomes arrive by completion, not by feed order. Nothing in
   production depends on order, but `RefreshRunnerTest` asserts on `fetchedUrls`
   as an ordered list and must become order-insensitive.

Because 1 and 2 add real branching, the fan-out bookkeeping belongs in its own
collaborator rather than inside `refresh()`, so `RefreshRunner` stays
PHPMD-clean per the standing rule.

## Error handling

Unchanged in observable behaviour. Every failure mode that today throws a
`FetchException` out of `fetch()` becomes a `FetchOutcome` carrying that same
exception, and `RefreshRunner` maps it to the same `FeedOutcome` and the same
`FeedScheduler` call as before:

- `FeedGoneException` → `recordGone`, `FeedOutcome::Failed`
- `FetchException` / `FeedParseException` → `recordFailure`, `FeedOutcome::Failed`
- `SsrfBlockedException` raised by `assertSafe()` at fill time becomes an
  outcome rather than escaping the batch
- `UniqueConstraintViolationException` / `ORMException` → `FeedOutcome::Aborted`,
  now additionally cancelling in-flight responses

## Testing

`tests/` mirrors `src/`. Direct-invocation tests mislead, so the behaviours that
depend on real wiring get functional coverage.

- `StubFeedFetcher` grows `fetchAll`. Its `secondsPerFetch` + `MockClock` trick
  drives the existing budget tests, and under concurrency 3 feeds × 100s is 100s
  of wall-clock, not 300s. Those tests encode the *old* serial semantics and are
  rewritten against the new "deadline gates starting a fetch" rule, not adjusted
  until green.
- `ConcurrentFeedFetcher` unit coverage against `MockHttpClient`: concurrent
  redirect chains at differing hop depths, mixed 200/304/410/timeout in one
  batch, the size cap firing mid-stream, hop exhaustion, and `break` cancelling
  the in-flight set.
- The abort-cancels-in-flight path is tested through `RefreshRunner`, not only
  against the fetcher in isolation.
- Favicon phase: a feed missing a favicon gets one from the second batch; a feed
  that already has one is never fetched for; a resolution failure leaves the feed
  without a favicon and does not fail the refresh; an aborted phase 1 skips
  phase 2. `FaviconResolverTest` moves to the batch signature, keeping its
  existing icon-selection cases intact.
- Existing `HttpFeedFetcherTest` (14 cases) must pass unchanged — it is the
  regression net proving the delegating serial path kept its behaviour.
- Both suite legs before the PR: `php bin/phpunit` (SQLite) and
  `docker compose exec php vendor/bin/phpunit` (MySQL), plus `composer check`,
  `composer md`, PhpStorm inspections on changed PHP, and a `dev.log` scan.

## Verification

Re-run the same timing measurement after the change so the PR carries a real
before/after rather than a claim. Baseline to beat: **5.37s of fetch time over
24 feeds, 5.77s end-to-end.**
