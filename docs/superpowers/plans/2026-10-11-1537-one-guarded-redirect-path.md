# #1537 Fetch: one guarded redirect path

**Goal:** one implementation of the SSRF hop guard. `FaviconFetcher` lands through `RedirectFollower`, and
`RedirectBudget` spends hops as well as seconds, so no caller does hop arithmetic.

## Rulings

- `RedirectFollower` keeps one `follow(string $url, array $options, RedirectBudget $budget)`; `followWithin()` and
  `LandedResponse::$hops` go. Every caller passes a `RedirectBudget` (#1536 builds on this API).
- `RedirectBudget` keeps `maxRedirects` for the "more than N redirects" message and counts the redirects it has
  left: `hasRedirectsLeft()` / `spendRedirect()`. The follower spends one per redirect it follows.
- `HtmlPageFetcher::fetch()` holds one budget across redirects and meta refreshes; a meta refresh spends a redirect.
  The "more than 5 redirects" message for the meta-refresh limit stays.
- `FaviconFetcher` keeps today's bound: 3 redirects, `timeout` and `max_duration` 8 s per request (the budget's
  seconds stay unlimited). It keeps its own status and type policy and sends the injected `$userAgent`.
  Every follower failure (blocked hop, too many redirects, no Location, transport) maps to
  `FaviconUnavailableException`, a wire-cap trip anywhere in the chain to `FaviconRejectedException`, as today.
- `FaviconFetcherTest` drops the hop-guard, too-many-redirects, IP-pinning and failover cases (owned by
  `RedirectFollowerTest` and `FailoverRequestSenderTest`) and gains a User-Agent case.
- Item 3: `Image/Support/ImageType::declaredOrSniffed()` is the one "declared type, or sniffed when undeclared"
  reading. `ImageDownloader` uses it too, so `/api/image-proxy` serves an `application/octet-stream` image the
  verifier accepts. A declared type is still checked before the body is read.
- Item 4 (rename `FaviconFetcher`) is out of scope; named in the PR as a follow-up.
- `/api/entries/{id}/reader` output is unchanged, so `ReaderCacheService` VERSION stays.

## Files

- `backend/src/Service/Fetch/Pass/RedirectBudget.php`, `Pass/LandedResponse.php`, `RedirectFollower.php`
- `backend/src/Service/Reader/HtmlPageFetcher.php`, `Reader/Media/MediaLanding.php`, `Image/OriginCookies.php`
- `backend/src/Service/Image/FaviconFetcher/FaviconFetcher.php`, `Image/ImageDownloader.php`,
  new `Image/Support/ImageType.php`
- tests: `RedirectFollowerTest`, new `RedirectBudgetTest`, `FaviconFetcherTest`, `ImageDownloaderTest`,
  `HtmlPageFetcherTest`, new `ImageTypeTest`, wiring helpers

## Tasks

1. `RedirectBudget` counts redirects; `follow()` takes the budget; drop `followWithin()` and `$hops`; update callers.
2. `HtmlPageFetcher::fetch()` on one budget.
3. `FaviconFetcher` on `RedirectFollower` with the User-Agent; trim its tests, add the User-Agent test.
4. `ImageType::declaredOrSniffed()` shared by both image downloads; test the octet-stream proxy case.
5. Gates: cs, stan, tramp, md, tests (SQLite + MySQL), infection:diff.
