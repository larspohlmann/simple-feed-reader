# Compressed outbound fetches under an inflated-size cap (#2157)

## Problem

Every outbound fetch sends `Accept-Encoding: identity`, so pages, feeds and API answers come over the wire 3–6× larger
than they need to (#2157 measures 40–225 ms per reader fetch; feed refreshes pay the same). The header guards against
gzip bombs: the `on_progress` caps count wire bytes, but when Symfony decompresses transparently curl buffers the
inflated body whole.

## Decision

Ask for `gzip, deflate` ourselves. Symfony's `CurlHttpClient` only decodes when the caller sets no `Accept-Encoding`
(`CurlHttpClient.php:213`, `CurlResponse.php:122`), so the body arrives compressed and the existing `on_progress`
caps keep bounding it. After the download, inflate it in 1 KB input slices with `inflate_init()`/`inflate_add()`
and stop the moment the inflated size passes the same limit.

Peak memory is at most the limit twice (compressed plus inflated) plus one slice's worst-case expansion: deflate tops
out near 1032:1, so about 1 MB. A bomb is never inflated whole.

Rejected: a client decorator (`AsyncDecoratorTrait`) that inflates chunks in flight. It would cover every fetcher at
once, but it cannot rewrite response headers, and a cap thrown inside it changes the exception each fetcher reports.
Inflating after the download is equally safe, because the compressed body is already capped, and it keeps each call
site explicit.

## Components

### `App\Service\Fetch\Support\ResponseBody` (new, static)

- `ACCEPT_ENCODING = 'gzip, deflate'`: the header value that goes with the decoder, so the two never drift apart.
- `decoded(ResponseInterface $response, ResponseSizeLimit $limit): string` does three things:
  - reads `getContent(false)`;
  - decodes according to `Content-Encoding`;
  - applies `$limit` to the result for **every** encoding, identity included. The buffered-body checks the call sites
    keep today move in here.
- How `Content-Encoding` is handled:
  - Absent, empty or `identity`: the body is returned as is.
  - `gzip` or `x-gzip`: inflated with `ZLIB_ENCODING_GZIP`; `deflate`: with `ZLIB_ENCODING_DEFLATE` (the zlib
    wrapper HTTP specifies). PHP has no auto-detecting mode for `inflate_init()`, and raw deflate is refused as corrupt.
  - Anything else (`br`, `zstd`, stacked encodings such as `gzip, gzip`): `FeedUnreachableException`, naming the
    encoding. We never advertise these, so a server sending one is misbehaving.
- Failures:
  - A corrupt stream (`inflate_add()` fails or warns): `FeedUnreachableException`.
  - A stream that ends early without its trailer: the bytes inflated so far are returned, as browsers do.
  - Past the limit: `ResponseTooLargeException`, the exception every consumer already catches and
    `FetchRetryPolicy` already treats as final.

### `ResponseSizeLimit::Page = 3_000` (new case)

The reader page limit, 3 MB. It is today's `HtmlPageFetcher::MAX_BYTES = 3_000_000` exactly, now on the enum the
decoder takes.

## Fetchers that switch

| Call site | Change |
|---|---|
| `PageLanding` | Default headers stay `identity`. A caller's `headers` option is merged over them, so a caller can ask for compression. `errorBodySnippet` decodes through `ResponseBody` with `ResponseSizeLimit::Page`; a `FetchException` gives no snippet. |
| `HtmlPageFetcher` | Sends `ResponseBody::ACCEPT_ENCODING`. Reads through `decoded(…, Page)`. `on_progress` quotes `Page->bytes()`. Its own `strlen` check goes. `ResponseTooLargeException` and `FeedUnreachableException` become `PageFetchException`. |
| `ConcurrentRequestEngine::headersOf` | Sends `ResponseBody::ACCEPT_ENCODING`. |
| `FeedResponseExchange::onBody` | Reads through `decoded(…, Feed)`; its own size check goes. |
| `ApiResponseExchange::onBody` | Reads through `decoded(…, Api)`. |
| `GuardedApiClient` | Sends `ResponseBody::ACCEPT_ENCODING`. `answerFrom` decodes with the budget's `sizeLimit`. |
| `ProxyConnectionTester` (boy-scout) | Sends `Accept-Encoding: identity`, so Symfony no longer inflates the echo unbounded. |

These stay on `identity`, and their behaviour does not change:
- `PageHeadFetcher`: it streams and stops at `</head>`, so it would need an in-flight decoder.
- Image downloads, the image probe and `OriginCookies`: images are already compressed.
- The LLM chat and scoring clients (streamed SSE, usually a local endpoint), `OpenAiCompatibleCatalog` and
  `GitHubLatestReleaseReader`: their responses are small or local.

## Tests

- `ResponseBodyTest` (`tests/Service/Fetch/Support`) covers:
  - gzip, x-gzip and zlib-deflate round-trips;
  - identity, a missing header and an empty body;
  - an identity body over the limit;
  - an unknown encoding, a stacked encoding and a corrupt stream;
  - a truncated gzip stream, which returns its prefix;
  - a real bomb (gzip of 100 MB of zeros, about 100 KB, built incrementally by a new `tests/Support/GzipBomb`)
    stopped with `ResponseTooLargeException`, with `memory_get_peak_usage()` growing by less than 3× the limit after
    `memory_reset_peak_usage()`. The prototype measured 4.3 MB of growth against a 3 MB limit.
  - The corrupt case reuses `tests/Support/CorruptGzip`.
- Each switched fetcher's existing test file gets two cases built on `MockResponse`:
  - a gzip body lands exactly like its plain twin;
  - a bomb raises that fetcher's own failure.
  - Where requests are asserted, the outgoing `Accept-Encoding` is checked too.
- A live check from the PHP container: a real gzip site fetched through `HtmlPageFetcher` gives byte-identical HTML,
  and it actually travels compressed (`size_download` well below the body length).
- The 15-article reader benchmark from #2158, run before and after, with the numbers in the PR.

## Out of scope

- Brotli: the extension is not installed.
- In-flight decoding for `PageHeadFetcher`.
- Compression for the image and LLM fetchers.
