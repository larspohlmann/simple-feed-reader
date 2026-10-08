# #1452 — raise the feed size cap, and say when a feed is too large

**Goal:** finish #1452. #1455 made parse memory follow the largest entry rather than the whole feed, so the
feed cap can rise (option 1). The feeds still over it then get a reason of their own instead of "could not
reach" (option 4).

## Measurement (Docker php, VmHWM above boot, body read included, `FeedParser::parse`, develop @ 381174a66)

| feed | body | items | peak before #1455 (issue) | peak now |
|---|---|---|---|---|
| Lage der Nation | 8.4 MB | 500 | +36 MB | +18 MB |
| BR radioWissen | 13.6 MB | 2,193 | +58 MB | +30 MB |
| Simplecast | 20.4 MB | 2,000 | +88 MB | +48 MB |
| Amperwave | 28.7 MB | 4,490 | +132 MB | +48 MB |

In-flight bodies are buffered by HttpClient in `php://temp` (`TransportResponseTrait`), which spills to disk
past 2 MB. A refresh therefore holds one body string at a time, not `FETCH_CONCURRENCY` of them.

**Cap: 20 MB.** It covers all but 2 of the 198 chart feeds (99 %). A parse at the cap peaks around +50 MB,
which leaves a 200 MB Strato worker plenty of room. 30 MB would cover the last two feeds, but it would put a
30 MB string and its preprocessing copies into the worker for 1 % of feeds.

## Design

1. **`ResponseSizeLimit` enum** (`Service/Fetch/Model`): `Feed = 20_000_000`, `Download = 5_000_000`.
   `ResponseTooLargeException::throwIfExceeded(ResponseSizeLimit $limit, int $observedBytes, ?string $url)`.
   The feed fetcher's wire guard and `ResponseClassifier` both quote `Feed`. `ImageDownloader`, `OriginCookies`
   and `FaviconFetcher` quote `Download`, so images keep today's 5 MB. Until now they borrowed the feed constant.
   The message reads `"<url>: the response is larger than the 20 MB limit"`, because feed health shows it
   verbatim.
2. **`ScrapeFailureReason::TooLarge = 'too_large'`**. `FeedDiscovery::discover()` catches
   `ResponseTooLargeException` before the generic `FetchException` arm.
3. **Preview:** `FeedPreviewService` throws `FeedPreviewException('The feed is larger than the reader accepts.')`
   for an over-size body.
4. **Frontend:** add `'too_large'` to `ScrapeFailureReason`, `failureText()` gets the arm `failTooLarge`, and
   `en.json`/`de.json` get the text.

## Tests

- Unit tests on `ResponseTooLargeException`: the boundary for each limit, and the message.
- The existing `ConcurrentFeedFetcherTest` and `ResponseClassifierTest` over-size tests move to the new cap.
  Images keep their 5 MB test.
- `FeedDiscoveryTest`: an over-size fetch yields `TooLarge`, and other fetch failures still yield `Unreachable`.
- `FeedPreviewServiceTest`: the over-size message.
- Jest: `failureText('too_large')`.

## Gates

`composer check`, `composer md`, `php bin/phpunit`, the MySQL leg, `composer infection:diff`,
`npm run check` in the frontend container, and PhpStorm lint. The PR body says `Closes #1452`. Merge when CI
is green.
