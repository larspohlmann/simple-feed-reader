# Offer the WordPress REST API as a richer feed alternative during discovery (#518)

## Problem

Many WordPress sites publish an RSS feed that carries only a truncated excerpt,
while the same site exposes a far richer machine-readable post list over the
WordPress REST API: full rendered content, UTC publish timestamps, author,
featured media, and categories, up to 100 items per request. When a user
subscribes to such a site we currently only ever offer the RSS/Atom feed. We
want to detect the REST endpoint during discovery and offer it as an
**alternative** candidate, never a replacement, so the user can choose the
richer source in the existing subscribe dialog.

## Locked decisions

- **Detection signal:** the HTML head link only —
  `<link rel="https://api.w.org/" href="…">`. The HTTP `Link` header is **not**
  used: `FetchResponse` exposes only the body, and surfacing response headers
  would mean a broader change to the shared SSRF-guarded fetcher. Standard
  WordPress emits the head link through `wp_head`, so coverage is broad.
- **Ordering:** when a page offers both, the REST candidate is presented
  **first**, then the RSS/Atom candidates.
- **Badge label:** the REST candidate's format badge reads **"WordPress"**.
- **Page size:** the probe requests `per_page=50`.
- **Card title:** the candidate carries the page `<title>` as its title, so the
  card has a readable name (the posts endpoint carries no site name).
- **Non-pretty root:** a `?rest_route=/` REST root (permalinks off) is left
  unsupported — detection returns "no alternative" for it.

## Architecture

The feature reuses three seams the codebase already has:

1. `SourceFormat` is an **open constants holder**, so a new durable format is
   one new constant.
2. Refresh dispatches a stored body to the parser owning its `sourceFormat`
   through the `app.feed_body_parser` keyed locator, so a new format is **one
   new class** implementing `FeedBodyParserInterface` — no dispatcher edit.
3. Discovery already parses the page body in `FeedLinkScanner` /
   `WellKnownFeedProbe`, so REST detection is a sibling collaborator that reads
   the same body.

### Components

#### 1. New source format
`App\Enum\SourceFormat::WP_JSON = 'wp-json'`. No enum introduced; the value
flows as a plain string exactly like `XML` and `SCRAPED`.

#### 2. `Service/Parser/WordPressJsonParser` (the core unit)
A standalone service that turns a decoded WordPress posts array into a
`ParsedFeed`. It is the reusable core, mirroring how `FeedParser` and
`HtmlItemExtractor` are used directly by both refresh and preview.

Per post → `ParsedEntry`:

| Source field | Target | Note |
|---|---|---|
| `title.rendered` | `title` | decode entities and strip tags — WordPress renders both |
| `link` | `url` | absolute |
| `date_gmt` | `publishedAt` | **parsed explicitly as UTC**; never `date`. Naive-UTC gotcha: a wrong offset makes every entry show up as "now". |
| `content.rendered` | `contentHtml` | raw; `EntryIngestor` sanitizes downstream, same as any feed HTML |
| `excerpt.rendered` | `summary` | nullable |
| `_embedded.author[0].name` | `author` | nullable |
| `_embedded['wp:featuredmedia'][0]` | `image` (`ParsedImage`) | `source_url` + `media_details` width/height (both independently nullable) |
| `guid.rendered` else `(string) id` | `guid` | stable id |

`ParsedFeed`: `title` is `null` (the posts endpoint carries no site name),
`siteUrl`/`description` `null`, `entries` the mapped list.

Robustness: every key access is defensive against a missing or mistyped value.
A body that does not decode to a non-empty array of objects throws
`FeedParseException`. This unit carries the mutation-testing weight.

#### 3. `Service/Refresh/WpJsonBodyParser`
Implements `FeedBodyParserInterface`; `format()` returns `SourceFormat::WP_JSON`;
`parse()` delegates to `WordPressJsonParser`. The exact shape of
`XmlBodyParser` / `ScrapedBodyParser`. Auto-registered by the
`app.feed_body_parser` tag — no dispatcher edit, no registration list. Refresh
fetches the stored posts URL through the SSRF-guarded fetcher unchanged.

#### 4. `Service/Discovery/WordPressRestProbe`
A sibling of `WellKnownFeedProbe`, injected into `FeedDiscovery`. Given the page
body and the page's final URL it returns a `?FeedCandidate`:

1. Read the REST root from `<link rel="https://api.w.org/" href="…">` via
   `HtmlDocumentParser` (as `FeedLinkScanner` does). Absent → `null`, silent —
   the ordinary case, exactly like `WellKnownFeedProbe` returning `null`.
2. Build the posts URL: `{root}wp/v2/posts?per_page=50&_embed`. Guard: only a
   pretty-permalink root (no `?`) is supported; a `?rest_route=` root → `null`.
   Ensure a single trailing slash on the root before appending.
3. Probe once through the **SSRF-guarded fetcher** (`FeedFetcherInterface`).
   Any `FetchException` (includes 401/403), an empty body, or a body that does
   not decode to a non-empty JSON array → `null` ("no alternative").
4. On success → `new FeedCandidate($postsUrl, $pageTitle, SourceFormat::WP_JSON)`.

The probe only validates the shape (non-empty array); it does not build a full
`ParsedFeed`.

#### 5. `Service/Discovery/FeedDiscovery` change
After the body proves not a feed and not a bot-challenge, compute the REST
candidate and **prepend** it to `FeedLinkScanner::scan()`'s result (REST first):

```
$restCandidate = $this->wordPressRest->offer($body, $response->finalUrl);
$scanned       = $this->links->scan($body, $response->finalUrl);
$candidates    = array_values(array_filter([$restCandidate, ...$scanned]));

return [] !== $candidates
    ? FeedDiscoveryResult::candidates($candidates)
    : $this->feedThePageNeverMentions($body, $response->finalUrl, $fallback);
```

The existing well-known / scrape fallback is unchanged and still runs only when
nothing (REST or scanned) was found. `FeedDiscovery` gains one constructor
collaborator (eight total; well under PHPMD's parameter/field thresholds and
consistent with the existing coordinator shape).

#### 6. `Service/Preview/FeedPreviewService` change
Add a `wp-json` branch that parses through `WordPressJsonParser`, mirroring the
existing direct use of `FeedParser` / `HtmlItemExtractor`. No permission gate —
`assertMayScrape` stays scraped-only. A wrong or empty endpoint surfaces as an
unavailable preview (`FeedPreviewException`), the same guarantee every candidate
gets.

#### 7. `Service/Subscription/SubscriptionService` change
Add a `wp-json` branch that stores the URL **verbatim** with
`SourceFormat::WP_JSON`, skipping re-discovery — the candidate URL is a JSON
endpoint, so re-running discovery on it would fail. This is the same shortcut
as `scraped` but with **no** `assertMayScrape`. The two verbatim branches share
one small private helper to stay DRY. Like `scraped`, there is no first-fetch
content: the feed shows 0 unread until the first scheduled refresh populates it.

#### 8. Frontend `reader/add-feed/add-feed-dialog`
- `formatLabel()`: `'wp-json'` → `'WordPress'`.
- `pick()` and `loadPreviews()`: pass the candidate format through for
  `wp-json` as they already do for `scraped`, so both subscribe and preview
  carry it. (`SubscribeRequest.format` / `PreviewFeedRequest.format` are open
  nullable strings, max 20 chars — `wp-json` fits and needs no validation
  change.)
- No new i18n keys: the badge is hardcoded like RSS/Atom.

### Data flow (subscribe)

1. User enters a site URL → `FeedDiscovery::discover()` → REST candidate (posts
   URL, format `wp-json`) prepended → candidate list returned.
2. Dialog previews each card: for the WordPress card,
   `previewFeed(url, 'wp-json')` → `FeedPreviewService` → `WordPressJsonParser`
   → the "full content" badge distinguishes it from the RSS card.
3. User clicks Subscribe on the WordPress card → `subscribe(url, 'wp-json')` →
   `SubscriptionService` stores `Feed(url = postsUrl, sourceFormat = wp-json)`
   verbatim.
4. Refresh fetches the posts URL through the guarded fetcher → `FeedBodyParser`
   → `WpJsonBodyParser` → `WordPressJsonParser` → `ParsedFeed` → `EntryIngestor`
   (sanitizes `content.rendered`).

### Error handling

- Detection signal absent → silent, no candidate (ordinary case).
- Probe fails (network / 401 / 403 / empty / non-array) → `null` → offer only
  the RSS candidate.
- Refresh parse failure later → `WordPressJsonParser` throws
  `FeedParseException` → `RefreshRunner`'s existing `recordFailure` / backoff /
  Erroring handling applies, same as any feed.
- Preview parse failure → `FeedPreviewException` → dialog shows "preview
  unavailable".

### Constraints respected

- **SSRF boundary:** the probe fetch and every refresh fetch go through
  `FeedFetcherInterface`, which reguards every redirect hop. No new outbound
  path bypasses the guard.
- **Native iOS readiness:** JSON in, JSON out, Bearer auth; no browser-only
  coupling.

## Testing

- **Unit — `WordPressJsonParser`** (mutation-critical): `date_gmt` parsed as
  UTC; missing/mistyped keys; embedded author and featured media; entity/tag
  handling in the title; empty or non-array body → `FeedParseException`.
- **Unit — `WordPressRestProbe`**: head-link read; posts-URL build (trailing
  slash, `?rest_route` → null); probe over a stubbed fetcher; 401/403/empty/
  non-array → null; success → candidate with page title.
- **Unit — `WpJsonBodyParser`**: `format()` and delegation.
- **Integration — `FeedDiscovery`**: the REST candidate is prepended alongside
  the RSS candidate (REST first).
- **Integration — `FeedPreviewService`**: renders the REST candidate;
  gated/empty endpoint → unavailable preview.
- **Integration — `SubscriptionService`**: `wp-json` stores verbatim with
  `SourceFormat::WP_JSON`, no re-discovery, no permission gate.
- **Frontend spec** (`add-feed-dialog.component.spec.ts`): "WordPress" badge;
  subscribe and preview pass the `wp-json` format.
- **Discovery/e2e spec**: owns its data — stub the `wp-json` route rather than
  reading whatever the seeded account holds.
- **Mutation:** `composer infection:diff` gates the touched files.

## Out of scope

- Custom post types and non-default REST namespaces — `wp/v2/posts` only.
- Sites that disable or gate the REST API (401/403/empty) — "no alternative".
- Pagination beyond the first page — one request of `per_page` items, matching
  how the RSS path behaves today.
- The `?rest_route=/` non-pretty REST root.

## Files touched

- `backend/src/Enum/SourceFormat.php`
- `backend/src/Service/Parser/WordPressJsonParser.php` (new)
- `backend/src/Service/Refresh/WpJsonBodyParser.php` (new)
- `backend/src/Service/Discovery/WordPressRestProbe.php` (new)
- `backend/src/Service/Discovery/FeedDiscovery.php`
- `backend/src/Service/Preview/FeedPreviewService.php`
- `backend/src/Service/Subscription/SubscriptionService.php`
- `frontend/src/app/reader/add-feed/add-feed-dialog.component.ts`
- `frontend/src/app/reader/add-feed/add-feed-dialog.component.html`
- `frontend/src/app/reader/add-feed/add-feed-dialog.component.spec.ts`
- New backend tests mirroring the units above.
