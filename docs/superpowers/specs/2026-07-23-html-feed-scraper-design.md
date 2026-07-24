# HTML Feed Scraper — Design

**Date:** 2026-07-23
**Branch:** `feature/html-feed-scraper`

## Problem

Some sites the user wants to follow offer no RSS/Atom feed at all (or hide it
so well that discovery finds nothing). Today the Add-a-feed dialog dead-ends:
"no feeds found". Services like Feedspot solve this by fetching the HTML page
and synthesizing a feed from its article listing. We want the same, built in:
if a page has no native feed, scrape its listing and treat the result as a
feed — fetched, refreshed, read, and tagged like any other subscription.

Reference examples:

- `https://www.tagesschau.de/` — repeated teaser cards, each with headline
  **and a short description**; both must survive extraction (verified against
  the live page 2026-07-23: 38 `teaser__link` blocks with `h3` headline +
  `teaser__shorttext` paragraph).
- `https://www.treehugger.com/` — card links with **no headings at all**
  (title is a `<span class="card__title-text">`) and descriptions in `<div>`s,
  not `<p>`s. Note: the site 403s plain HTTP clients (Cloudflare challenge),
  so it cannot be *subscribed* under this design — but its rendered DOM
  (captured via browser 2026-07-23) is a fixture that hardens the extraction
  heuristics against heading-less, div-based cards.
- `https://www.heise.de/` — added as a reference 2026-07-23: the homepage
  serves plain HTTP clients fine and advertises **no** feed `<link>` tags, so
  it is a genuine production target for the scraper. It embeds 61 JSON-LD
  `ItemList` blocks (190 list items, 141 unique URLs — exercising layer 1,
  cross-list dedupe, and the 50-item cap on a real page). Its teaser text
  lives in the schema.org **`abstract`** property (143 items), not
  `description` — so layer 1 reads `description` with `abstract` as
  fallback. Some `itemListElement.item` values are bare strings; the layer
  must tolerate those without crashing.
- `https://www.reuters.com/business/environment/` — the user's original
  example, but Reuters sits behind aggressive bot protection; it is explicitly
  *best-effort only* (see Non-goals).

## Goal

A `scraped` feed type inside the existing pipeline (Approach A, confirmed):

- **Discovery fallback:** when a page advertises no native feeds, run the
  extractor; if it finds a plausible article listing, offer one candidate with
  `format: 'scraped'` in the existing add-feed / preview flow.
- **Refresh:** the normal scheduler refetches the HTML page and re-extracts;
  new articles become new entries via the unchanged ingest pipeline.
- **Entries:** title + link, plus teaser text and image when the listing card
  has them. Full text stays on-demand via the existing reader mode
  (`ArticleExtractor`, readability.php v4).

## Non-goals

- **No headless browser, no anti-bot measures.** Server-rendered pages that
  answer a plain HTTP GET are the target (blogs, magazines, institutional
  news). Bot-protected sites (Reuters/Datadome) fail as ordinary fetch errors,
  honestly surfaced in the UI.
- **No manual selector configuration.** Automatic heuristics only (confirmed).
  If extraction misjudges a page, the preview shows it and the user simply
  doesn't subscribe.
- **No auto-fetching of full article bodies during refresh** (confirmed).
  Reader mode already covers full text on demand; refresh stays one request
  per feed.
- **No scraped candidate when native feeds exist.** Native always wins;
  scraping is a fallback, never an alternative offer.
- **No new schema for entries.** Scraped entries are ordinary `Entry` rows.

## Decisions (confirmed)

1. **Approach A** — a fetch/parse branch inside the pipeline, not an internal
   feed-proxy endpoint (B) or a separate microservice (C). `HttpFeedFetcher`,
   `UrlGuard`, conditional GET, `EntryIngestor`, `EntrySanitizer`, preview,
   reader API, and the frontend all stay untouched or nearly so. This also
   keeps the core API native-iOS-ready (server-side only, no new web coupling).
2. **Best-effort site coverage** — no headless browser sidecar.
3. **Automatic heuristics only** — no per-site rules, no user-supplied
   selectors.
4. **Teasers are first-class** — when a listing card carries a short
   description (tagesschau pattern), it becomes the entry's content.

---

## Architecture

### Data model

`Feed` gains a `sourceFormat` string column: `'xml'` (default; covers RSS and
Atom, which `FeedParser` already distinguishes internally) or `'scraped'`.
One migration, existing rows backfilled to `'xml'`. Nothing else changes:
scheduler, pruner, subscriptions, tags, and the reader API all operate on
feeds/entries as before.

### Pipeline branch

```
refresh:  HttpFeedFetcher ──► sourceFormat?
                                ├─ 'xml'     ─► FeedParser        ─► ParsedFeed
                                └─ 'scraped' ─► HtmlItemExtractor ─► ParsedFeed
                                                          │
                              EntryIngestor ◄─────────────┘  (unchanged)
```

- The fetch step is byte-identical to today: SSRF guards (`UrlGuard` /
  `IpValidator`), redirect resolution, conditional GET (ETag/Last-Modified
  work fine on HTML pages), size/time limits.
- `HtmlItemExtractor` returns the same `ParsedFeed` / `ParsedEntry` value
  objects `FeedParser` returns, so everything downstream — ingest, sanitize,
  prune, preview, magazine layout — is unchanged.
- `FeedPreviewService` branches the same way. Pre-subscribe there is no `Feed`
  row yet, so the preview request carries the candidate's `format` (the
  frontend already has it on the candidate); `'scraped'` routes the fetched
  page to `HtmlItemExtractor`. The preview of a scraped candidate is thus the
  real extraction result, not a simulation.

### HtmlItemExtractor

Parses with PHP 8.4's `\Dom\HTMLDocument` — the spec-compliant HTML5 parser
readability.php v4 already rides on; no new parsing dependency.

**Open architecture (user requirement 2026-07-23):** the extractor does not
hard-code its strategies. Layers implement `ScrapeLayerInterface`, are
auto-tagged via `#[AutoconfigureTag('app.scrape_layer')]` on the interface,
ordered by `#[AsTaggedItem(priority: …)]`, and injected as a tagged iterator.
A further case — a microformats layer, a site-specific extractor, a smarter
cluster scorer — is one new class with a priority; the facade never changes.
The same openness applies downstream: `Feed.sourceFormat` is an open string,
and the refresh-side format seam is itself tagged + keyed
(`FeedBodyParserInterface`, tag `app.feed_body_parser`, keyed by `format()`;
dispatcher falls back to `'xml'` for unknown values) — a future format (e.g.
JSON Feed) is one new tagged class, with no dispatcher change. *(Amended
2026-07-23 on repeated user emphasis; originally a hard-coded two-way
branch.)*

Three layers ship initially, first success (≥3 items) wins, ordered by
priority (JsonLd 30 > Semantic 20 > Cluster 10):

1. **Structured data.** JSON-LD `ItemList`, or arrays of
   `NewsArticle`/`BlogPosting`/`Article` objects: extract url/headline/
   description/image/datePublished directly. Teaser reads `description`
   with `abstract` as fallback (heise ships teasers as `abstract`); bare
   string entries in `itemListElement` are tolerated and skipped.
2. **Semantic markup.** Repeated `<article>` elements. (Repeated
   heading-anchor patterns without `<article>` wrappers are handled by
   layer 3 — the anchors share a DOM-path signature, so clustering finds
   them without a dedicated rule.)
3. **Pattern clustering.** Group anchors by their own class tokens (BEM
   `--modifier` suffixes stripped; one group per token; classless anchors
   share a group), after excluding anchors with `nav`/`header`/`footer`/
   `aside` ancestry; score groups by item count (mean title length as
   tiebreak); take the best group with ≥3 extracted items. *Amended
   in-branch 2026-07-23: the original body-down DOM-path signature
   fragmented identical cards across section wrappers (treehugger's 45
   `mntl-card`s split 24/15/6), stranding items and teasers; anchor-class
   clustering keys on what card systems actually share.* This is the layer
   that handles tagesschau (div-based teaser cards, no `<article>`, no
   JSON-LD list) and treehugger.

**Per-item extraction happens on the card container, not the anchor.** From
each clustered anchor, walk up to the repeating container element, then within
it:

- **link** — anchor `href`, resolved absolute against the final (post-
  redirect) URL; http(s) only.
- **title** — resolved by a fallback chain, because not every card uses
  headings (treehugger uses spans): (1) nearest heading (`h1`–`h4`) in the
  container; (2) an element whose class matches `/title|headline/i`;
  (3) the anchor's first text line. Never the anchor's *full* text — on
  heading-less cards that would mash title, byline, and description together.
- **teaser** — the longest text block in the container that isn't the title,
  minimum 40 characters (so "Read more" labels never qualify). A "text block"
  is a `<p>` or a leaf-ish `<div>`/`<span>` whose own text qualifies —
  tagesschau uses `<p class="teaser__shorttext">`. Fallback (verified needed
  for treehugger, where `card__description` divs are *empty* and the text
  lives in `data-card-description` attributes): a `data-*` attribute on the
  container or any descendant whose name matches `/descri/i` and whose
  value is ≥40 characters (descendant scan — treehugger nests the attribute
  inside the card link). Becomes the entry's content HTML (as a plain
  `<p>`), sanitized by `EntrySanitizer` like all feed content.
- **image** — first `<img>` in the container (`src`/`srcset`/`data-src`),
  http(s) only, feeding the existing item-image plumbing.
- **date** — `<time datetime>` if present; otherwise the entry is undated and
  gets first-seen dating from the existing ingest behavior.

**Normalization:** strip soft hyphens (U+00AD — tagesschau wraps words in
`hyphenate` spans), collapse whitespace, decode entities (the HTML5 parser
does this), trim.

**Guards:**

- Fewer than **3** extracted items ⇒ extraction *fails* (prevents garbage
  feeds from menus/footers).
- Dedupe by resolved URL (tagesschau repeats stories across page sections);
  first occurrence wins.
- Cap at **50** items.
- Drop self-links (link equal to the page URL) and non-http(s) schemes.
- **GUID** = article URL, via the existing `GuidFallback` logic — stable
  across refreshes, so re-extraction doesn't duplicate entries.

**Feed-level metadata:** title from `og:site_name`, else `<title>`; site link
= final page URL; description from `meta[name=description]` when present.

### Discovery & UX

`FeedDiscovery`: after the existing `<link rel=alternate>` scan (and the
direct-feed check) finds nothing, run `HtmlItemExtractor` on the already-
fetched page. ≥3 items ⇒ one `FeedCandidate` with `format: 'scraped'` (the
open-string design in `FeedCandidate` anticipated exactly this value).

**Subscribe is offered only when scraping demonstrably works (confirmed).**
Discovery returns either a usable candidate or a machine-readable failure
reason — never a bare empty list for a scrape-attempted page. When the page
has no native feed, `FeedDiscoveryResult` carries a nullable
`scrapeFailureReason`:

- `null` — extraction succeeded; one `scraped` candidate is present and the
  user may subscribe.
- `'blocked'` — the page fetch was refused (HTTP 401/403/429, the
  bot-protection signature): UI warns "This site blocks automated access —
  it can't be subscribed."
- `'unreachable'` — network/DNS/timeout/5xx/4xx fetch failure: UI warns the
  site couldn't be reached.
- `'not_scrapable'` — page fetched fine but no article list was detected
  (<3 items): UI warns "This page offers no feed and no article list could
  be detected — it can't be subscribed."

**Open reason set (third openness application, 2026-07-24):** the reason
union above is the *known* set, not a closed contract. The frontend types
the field as an open string with the union documented, and `failureText()`
falls back to a generic "This page can't be subscribed." for any unknown
reason — so a future backend reason ships without a frontend release, and
an out-of-date frontend degrades to a correct (if less specific) warning
instead of an empty box.

Frontend add-feed flow: the candidate card shows a "Scraped" format badge
where RSS/Atom badges show today, plus a one-line hint ("No feed found —
generated from the page's article list"). The existing preview cards then
show exactly which items extraction found — headlines, teasers, images — so
the user judges quality before subscribing. On failure reasons the dialog
shows the warning above instead of the generic "no feed found" text, and no
subscribe/add action is rendered. Subscribe persists the feed with
`sourceFormat: 'scraped'`.

The feed-detail/management UI shows the same badge so scraped feeds are
distinguishable later.

### Error handling

- **Fetch errors** (403 from bot protection, timeouts, DNS): identical to
  today's fetch-error path; the feed's error state and UI messaging apply
  unchanged.
- **Extraction failure on refresh** (<3 items, e.g. after a site redesign):
  treated exactly like an XML parse failure — feed enters its error state,
  existing entries are kept, pruner rules unchanged.
- **Extraction drift** (site redesign changes the winning cluster): entries
  keyed by article URL, so a changed cluster yields new-but-valid entries or
  an extraction failure — never duplicate GUIDs.
- **Discovery fallback never throws:** any unexpected extractor error during
  discovery degrades to the `'not_scrapable'` failure reason — the user
  always gets a definite answer (candidate or warning), never a blank
  result or a 500.

### Security

- Same SSRF surface as today: the page fetch goes through `UrlGuard`; item
  links/images are data, not fetched during refresh.
- Teaser HTML is generated by us (plain `<p>` + text), then still passed
  through `EntrySanitizer` — defense in depth, and identical treatment to
  feed-shipped content.
- Extracted URLs restricted to http(s) before they reach the DB or client.

## Testing

- **Unit — extractor:** saved real-page fixtures in
  `backend/tests/Fixtures/scraped/`: `tagesschau-2026-07-23.html`
  (clustering, `<p>` teasers, images, umlauts, soft hyphens),
  `treehugger-rendered-2026-07-23.html` (heading-less cards, class-hint
  titles, `<div>` teasers; rendered-DOM capture since the live site 403s),
  a JSON-LD site, a semantic-`<article>` blog, and hostile fixtures
  (nav-heavy page with no articles ⇒ must fail with <3 items; page whose
  biggest cluster is a footer link list ⇒ must not win).
- **Unit — layer precedence:** structured data beats semantics beats
  clustering; dedupe, cap, normalization, guard behavior.
- **Functional — through the dispatcher/pipeline** (per our
  direct-invocation-tests rule): refresh a `scraped` feed end-to-end into
  `Entry` rows; second refresh with identical HTML creates no duplicates;
  discovery endpoint returns a `scraped` candidate for a feedless fixture
  page, none when a native feed exists, and the correct
  `scrapeFailureReason` (`blocked` / `unreachable` / `not_scrapable`) for
  403 responses, network failures, and article-free pages respectively.
- **Migration:** covered by the dedicated migrations CI leg.
- **E2E (Docker):** the warning path — add an unreachable URL → warning
  shown, no subscribe action. The scrape-*success* path cannot run over
  live HTTP in e2e because `UrlGuard` (SSRF protection) rightly refuses
  locally served fixture pages; it is covered end-to-end at the functional
  layer instead (stubbed fetcher through the real dispatcher/pipeline).
- **Quality gates:** phpcs, phpstan, phpmd (touched files clean), PhpStorm
  inspections, backend tests in the Docker php container; watch `dev.log`.

## Rollout

Single feature branch `feature/html-feed-scraper` off `develop`; no flag —
the feature is inert unless discovery finds no native feed. Deployment plan
(plan 6) is unaffected.
