# Feed Preview Before Subscribe — Design

**Date:** 2026-07-23
**Branch:** `feature/feed-preview`

## Problem

When a user enters a site URL (e.g. `www.tagesschau.de`) that exposes more than
one feed, the "Add a feed" dialog lists the candidates by their `<link title>`
text alone — e.g. "ATOM" and "RSS". The user has no way to tell what the
difference will be before subscribing. They want to see, per candidate:

- the recent headlines,
- **how much content each feed carries** — full article text vs. a short
  summary vs. title-only,
- **whether the feed includes images**,
- and supporting signals (item count, dates).

Today none of this is available pre-subscribe: discovery stops at "is this a
feed?", each candidate carries only `url` + optional `title`, and no feed items
are fetched until after subscription (in the refresh pipeline).

## Goal

Add a **feed preview** shown inline in the Add-a-feed dialog. When candidates
are discovered, the app fetches a compact preview of each candidate in parallel
and renders them as side-by-side comparison cards, so the user can pick the feed
whose content shape they want. Subscribing is unchanged (still one click per
candidate); the preview is purely informational and never blocks subscribing.

## Non-goals

- No persistence of preview data (it is fetched fresh, never stored in the DB).
- No change to the refresh / ingest pipeline or the `Entry` entity / schema.
- No preview for the direct-feed subscribe path (entering a feed URL that
  subscribes immediately) — the preview is about *choosing between candidates*.
  The endpoint is generic enough to preview any URL, but the UI only wires it to
  the candidate list.
- No article extraction / reader-mode fetch. "Full text" means the text the feed
  *itself* ships (`contentHtml`), not a scraped article.

## Decisions (confirmed)

1. **Image detection: thorough.** Extend the feed parsers to capture image URLs
   from `<enclosure type="image/*">`, `<media:content>` / `<media:thumbnail>`,
   and inline `<img>` in item content. Scanning content HTML alone would
   under-report the many RSS feeds that attach images via enclosures.
2. **Preview trigger: auto, all candidates at once.** Comparison is the point,
   and there are typically only 2–3 candidates.

---

## Architecture

### Backend

A new endpoint reuses the existing SSRF-guarded fetcher and feed parser; nothing
about the fetch/parse path is reinvented.

```
POST /api/feeds/preview
Body:     { "url": string }          # validated: NotBlank, Url(http/https), max 750
Auth:     required (same firewall as the rest of /api)
Limit:    per-user rate limit (mirror the reader endpoint's limiter)
```

**Flow** (`FeedPreviewController` → a new `FeedPreviewService`):
1. `FeedFetcherInterface::fetch(url)` — SSRF guard (UrlGuard: http/https only,
   public IPs only, DNS-pinned), 5 MB cap, timeout, redirects. Reused as-is.
2. `FeedParser::parse(body)` → `ParsedFeed` (title, entries).
3. Build the preview DTO from `ParsedFeed` + `ParsedEntry[]`:
   - `itemCount` = total parsed entries.
   - Sample the first **4** entries for the item list.
   - Per sampled item, classify content tier and detect image (below).
   - Feed-level `content` verdict = the mode (most frequent) tier across sampled
     items; ties resolve to the *richer* tier.
   - Feed-level `hasImages` = any sampled item has an image.

**Success response** `200`:
```json
{
  "feed": {
    "title": "string | null",
    "itemCount": 42,
    "content": "full | summary | title-only",
    "hasImages": true,
    "items": [
      {
        "title": "string",
        "publishedAt": "ISO-8601 | null",
        "author": "string | null",
        "hasImage": true,
        "textLength": 1834,
        "snippet": "first ~200 chars of plain text"
      }
    ]
  }
}
```

**Failure responses:** validation error → `422` (RFC7807 problem, same as
subscribe). Fetch blocked/failed or unparseable feed → `422` problem with a
clear message (e.g. `"That feed could not be loaded for preview."`). Rate-limit
exceeded → `429`. The frontend degrades gracefully: a candidate whose preview
fails still shows a Subscribe button.

**Content tier classification (per item):**
- Extract plain text: strip tags from `contentHtml` if present, else from
  `summary`.
- `full` — `contentHtml` present **and** its plain-text length ≥ `FULL_TEXT_MIN`
  (600 chars).
- `summary` — some text present but below the full threshold (short content or a
  `summary`).
- `title-only` — no `contentHtml` and no `summary` text.

`textLength` = plain-text length of the item's richest available body
(`contentHtml` preferred, else `summary`). `snippet` = first 200 chars of that
plain text, trimmed on a word boundary, `…` appended if truncated.

**Image detection (thorough) — parser change:**
Add `public ?string $imageUrl` to `ParsedEntry` (parse-time only; the ingest
pipeline ignores it, so no schema/migration change). Each format parser sets it
to the first image it finds, in this precedence:
- **RSS 2.0** (`Rss2Parser`): `<media:thumbnail url>` → `<media:content
  url medium="image"|type="image/*">` → `<enclosure type="image/*" url>` →
  first `<img src>` in `<content:encoded>` / `<description>`.
- **Atom 1.0 / 0.3** (`Atom10Parser`, `Atom03Parser`): `<media:thumbnail>` /
  `<media:content>` → `<link rel="enclosure" type="image/*" href>` → first
  `<img src>` in content.
- **RSS 1.0** (`Rss1Parser`): `<media:*>` if present → first `<img src>` in
  `<description>` / `content:encoded`.

The parsers already reject DTDs (anti-XXE) and operate on the parsed document;
media/content namespaces are read by local name to avoid namespace-prefix
fragility. `imageUrl` is resolved to an absolute URL against the feed/site base
where a resolver is already available; otherwise left as-is. Per-item `hasImage`
= `imageUrl !== null`.

### Frontend

Extend the existing Add-a-feed dialog; no new route or page.

- **Model** (`models.ts`): add `FeedPreview` and `FeedPreviewItem` interfaces
  matching the response above. Also fix the existing `FeedCandidate.title` to be
  `string | null` (backend already emits null; template already guards).
- **API** (`reader-api.ts`): add
  `previewFeed(url: string): Observable<{ feed: FeedPreview }>` →
  `POST /api/feeds/preview`.
- **Dialog** (`add-feed-dialog.component.ts`): when `candidates` is populated,
  start a preview request for each candidate URL in parallel. Track per-URL
  preview state in a signal map: `'loading' | { error: string } | FeedPreview`.
  Render each candidate as a **card**:
  - header: candidate title (or feed title from the preview) + item count,
  - badges: content tier (**Full text** / **Summary only** / **Titles only**)
    and images (**With images** / **No images**),
  - a short list of 2–3 sample headlines with their dates,
  - a **Subscribe** button (calls the existing `pick(url)` → `subscribe(url)`).
  - loading state: skeleton/placeholder while the preview is in flight.
  - error state: a muted "Preview unavailable" note; Subscribe still works.

The subscribe action itself is unchanged — clicking Subscribe on a card
re-submits `subscribe(candidateUrl)`, which hits the direct-feed path and creates
the subscription, closing the dialog.

## Data flow

```
User enters site URL
   → POST /api/subscriptions {url}
   → 200 { candidates:[{url,title}, …] }        (existing behavior)
   → dialog renders one card per candidate
   → for each candidate, in parallel:
        POST /api/feeds/preview {url:candidate.url}
        → 200 { feed:{ title,itemCount,content,hasImages,items[…] } }
        → card fills in badges + sample headlines
   → user clicks Subscribe on the chosen card
   → POST /api/subscriptions {url:candidate.url}
   → 201 { subscription } → dialog closes
```

## Error handling

| Case | Backend | Frontend |
|---|---|---|
| Invalid/oversized URL | 422 problem | (shouldn't occur — URLs come from discovery) |
| SSRF-blocked / fetch fail | 422 problem, generic message | card shows "Preview unavailable", Subscribe works |
| Unparseable body | 422 problem | same as above |
| Rate limit hit | 429 | card shows "Preview unavailable" |
| Preview OK but 0 items | 200, `itemCount:0`, empty items | card shows "No recent items" |

## Testing

**Backend**
- `FeedPreviewService`: full-text feed → `content:"full"`, `hasImages` per
  fixture; summary-only feed → `"summary"`; title-only feed → `"title-only"`;
  item count; sample capped at 4; snippet truncation + word boundary.
- Parser image extraction, one test per format & source: RSS2 enclosure,
  RSS2 media:thumbnail, RSS2 inline `<img>`, Atom enclosure link, Atom inline
  `<img>`, feed with no image → `imageUrl` null. Reuse the real-UrlGuard +
  stub-DNS pattern from the existing fetcher/extractor tests where a fetch is
  involved; parser tests operate on fixture strings directly.
- Controller: happy path returns the documented shape; blocked/failed fetch →
  422; unauthenticated → 401; rate-limit path → 429.
- SSRF: a candidate URL resolving to a private IP is rejected (covered by the
  reused UrlGuard, assert the controller surfaces 422).

**Frontend**
- `previewFeed` API method hits the right URL/method/body.
- Dialog: given candidates, fires one preview request per candidate; renders
  badges from a loaded preview; shows loading then error state on a failed
  preview while keeping the Subscribe button; Subscribe still calls
  `subscribe(url)` and closes on success.

## Quality gates (per project standing rules)

- Any touched backend `src` file must be phpmd-clean before commit.
- Run PhpStorm inspections (mcp `lint_files`) on changed PHP; block on
  ERROR/WARNING.
- cs-fixer / phpstan / phpmd / markdownlint as usual.
- Backend tests run in the Docker `php` container.
- Native-iOS readiness: the endpoint is a stateless bearer-auth JSON API with no
  browser-only coupling — native-ready. Note it in the readiness memory.
