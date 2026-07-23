# Reader Mode — Design Spec

**Date:** 2026-07-23
**Status:** Approved (design); pending implementation plan
**Branch:** `feature/reader-mode`

## Goal

When the user opens an article, fetch the full article from its source page and
display it distraction-free in the app's own styling — only the text and images
that belong to the article, like a browser's "reader mode". Extracted content is
cached in the frontend.

## The deciding constraint: CORS

A pure-frontend fetch of an arbitrary article URL does not work: browsers block
cross-origin `fetch()` unless the publisher sends permissive CORS headers, which
news sites never do (`no-cors` returns an opaque, unreadable response). The
source page **must** be retrieved server-side. Extraction and styling can happen
either side, but the fetch is server-side by necessity.

## Decisions (locked during brainstorming)

1. **Extraction site — backend.** The backend fetches, extracts, sanitizes, and
   returns clean JSON. Reuses the existing SSRF guard and sanitizer, and keeps a
   native iOS client viable (it can hit the same JSON endpoint). A pure-frontend
   Readability.js approach would be web-only.
2. **Caching — frontend IndexedDB, backend stateless.** Persistent frontend
   cache (survives reloads, handles many/large articles) with an LRU cap. Backend
   fetches + extracts fresh on each cold request. No DB column, no migration.
3. **Reader UX — auto-fetch on open, Reader/Original toggle, feed fallback.** On
   open, auto-fetch the full article and show it once ready. A toggle switches
   between "Reader" (fetched) and "Original" (the feed's summary / contentHtml).
   If extraction fails, fall back to feed content with a subtle note.
4. **Images — load directly from source (v1).** Render `<img>` pointing at the
   publisher's (absolute-ised) URL. Accepted trade-offs: the publisher sees the
   reader's IP, and http-only images are blocked as mixed content on the https
   app. Image proxying is a clean future add that reuses the SSRF guard.

## Architecture

On open, the frontend asks the backend for entry `{id}`'s full article. The
backend fetches the source page (SSRF-guarded), extracts the main content with
readability.php, sanitizes it, and returns clean JSON. The frontend renders it in
the existing reader-view styling, caches it in IndexedDB, and offers a
Reader/Original toggle.

## Reuse map (from codebase exploration)

**Reused as-is:**
- SSRF core — `backend/src/Service/Fetch/UrlGuard.php`, `IpValidator.php`,
  `NativeDnsResolver`, plus DNS-pinning approach in `HttpFeedFetcher`.
- `backend/src/Service/EntrySanitizer.php` (`symfony/html-sanitizer`) — the XSS
  barrier feed HTML already passes through.
- `frontend/src/app/reader/reader-view/reader-view.component.ts` — the `.content`
  `[innerHTML]` surface (line ~69) and its responsive media styling.
- Bearer-JWT interceptor, root signal-store pattern, `sfr.*` namespaced storage.
- `symfony/http-client` and `symfony/rate-limiter` (already dependencies).

**Net-new:**
- `fivefilters/readability.php` dependency (content extraction — none present).
- An HTML-oriented fetch path (the existing `HttpFeedFetcher` is XML-specific).
- A reader endpoint + `Service/Reader/ArticleExtractor`.
- A frontend `readerContent()` API method + `ReaderContent` model.
- A frontend IndexedDB cache service (project currently uses `localStorage` only).

## Backend design

### Endpoint

`GET /api/entries/{id}/reader` — JWT-protected, ownership-checked. The user can
only reader-ify their own entries; this also avoids exposing an open
"fetch any URL" proxy (SSRF-by-proxy).

Normalized discriminated response (always HTTP 200 so the frontend fallback is
uniform):

```jsonc
// success
{ "status": "ok", "url": "...", "title": "...", "byline": "...|null",
  "siteName": "...|null", "contentHtml": "<sanitized html>",
  "excerpt": "...|null", "extractedAt": "<iso8601>" }

// failure
{ "status": "failed", "url": "...", "reason": "fetch" | "unextractable" | "empty" }
```

Rate-limited via `symfony/rate-limiter` so a user cannot hammer outbound fetches.

### `Service/Reader/ArticleExtractor`

1. **Fetch** — new `Service/Fetch/HttpPageFetcher` that reuses `UrlGuard` /
   `IpValidator` / DNS-pinning, with `Accept: text/html` and its own byte cap.
   A sibling to `HttpFeedFetcher`, not an overload of it.
2. **Extract** — `fivefilters/readability.php` with `FixRelativeURLs` enabled and
   the original URL set, so relative image/link URLs resolve to absolute (this is
   what makes "load images directly" work).
3. **Sanitize** — pass the extracted HTML through `EntrySanitizer`, extending its
   allowlist for article structure: `figure`, `figcaption`, `blockquote`,
   headings (`h1`–`h6`), `pre`, `code`. Same barrier feed HTML already crosses.

**Failure mapping → `status: "failed"` reason:**
- fetch error / SSRF-blocked / oversized → `"fetch"`
- readability returns null / throws → `"unextractable"`
- extracted content empty or below a minimum length → `"empty"`

Backend stays stateless — no persistence of extracted content in v1.

### New dependency note

`fivefilters/readability.php` targets PHP 8.1+ (backend is 8.3). Verify it
installs clean and watch `dev.log` for deprecations on 8.3 during implementation.

## Frontend design

- **`reader-api.ts`** — new `readerContent(entryId: number): Observable<ReaderContent>`.
- **`ReaderContent` model** — mirrors the JSON discriminated shape.
- **`ReaderCacheService` (IndexedDB, net-new):** small hand-rolled store (no new
  npm dependency), keyed by entry id, value `{ content, cachedAt }`, tagged with a
  schema version, with an LRU cap (~100 articles, evict oldest by `cachedAt`).
  Content is immutable per entry, so no staleness logic is needed; the schema
  version busts the cache on format change.
- **`reader-view.component.ts`:** a `mode` signal (`'reader' | 'original'`) with a
  header toggle.
  - On open: cache hit → render instantly. Miss → spinner, call the API, cache +
    render on `ok`; on `failed` flip to Original with a subtle "couldn't load full
    article" note.
  - Reader HTML renders through the existing `.content [innerHTML]` surface and
    styling — no new layout.

## Cross-cutting

- **Security:** SSRF guard reused; fetch limited to entries the user owns;
  server-side sanitize + Angular binding sanitizer; endpoint rate-limited.
- **Native iOS readiness:** the JSON endpoint is native-ready; only the IndexedDB
  cache is web-specific.

## Testing

**Backend:**
- Extractor against saved HTML fixtures (offline, deterministic).
- SSRF-blocked URL → `status: "failed"`, reason `"fetch"`.
- Sanitizer integration (script/dangerous markup stripped; allowed structure kept).
- Controller: auth required, ownership enforced, `ok` and `failed` response shapes.

**Frontend:**
- `ReaderCacheService` via `fake-indexeddb` (put/get, LRU eviction, schema-version bust).
- `reader-view` toggle, loading state, and failed → Original fallback.
- The new `readerContent()` API method.

## Out of scope (v1, YAGNI)

Image proxying; backend / shared caching; JS-rendered-page support (headless
browser); text-to-speech; offline export. All are clean future adds; none block
this.
