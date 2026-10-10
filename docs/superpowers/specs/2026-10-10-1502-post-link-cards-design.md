# #1502 — Link cards for the links a post shares

Follow-up to #1495 and #1499. A post (an entry whose title was derived from its text, such as a Mastodon
status) often exists to share a link. Today the reader and the magazine show that link as a URL in the
post text. This spec gives each shared link a card (image, headline, description, site). The card is
stored as data, shown in the reader under the post text, and shown directly in the magazine list as a new
entry block.

## Decisions (agreed 2026-10-10)

| Question | Decision |
|---|---|
| When | At ingest, after each refresh, best effort. Opening the post builds whatever is still missing. |
| Shape | Structured rows in their own table (`Entry` is at PHPMD's field limit), sent as `linkCards` in the entry JSON |
| Which links | Every link the post shares, in order, at most 5 |
| Bluesky | #1499's AppView `external` embed is stored as the same structured card, with no page fetch |
| Magazine | A new link-post block, layout **A, stacked** (mockup approved): kicker, post text, then a card with the image full width on top and the site, headline and two-line description below, then the actions |

## Measured

- Local Mastodon feed (`mastodon.social/@Mastodon.rss`): 19 of 20 entries contain a URL.
  - Sanitizing strips Mastodon's `class` attributes (`mention`, `hashtag`, `invisible`).
  - A shared link therefore looks like `<a href="https://joinmastodon.org/sponsors#donate"><span>https://</span><span>joinmastodon.org/sponsors#dona</span><span>te</span></a>`.
  - A hashtag looks like `<a href=".../tags/Mastodon">#<span>Mastodon</span></a>`, and a mention like `<a href="…/&#64;iftas">&#64;<span>iftas</span></a>`.
  - A quote opens the post as `<p>RE: <a href="<post url>">…</a></p>`.
- Mother Jones on Bluesky: almost every post is a link card from the AppView.
- No service reads Open Graph for a card today. The pieces exist: `Reader\HtmlPageFetcher` (SSRF-guarded, redirects, 3 MB cap), `Html\Support\MetaProperty`, `HtmlDocumentParser`, `Url\Support\HttpsImageUrl` and `Fetch\Support\HostKey`.

## Design

### 1. Storage

- Entity `LinkCard`, table `link_card`.
  - `entry`: ManyToOne, `onDelete: CASCADE`.
  - `position`: smallint, with a unique pair `(entry, position)`.
  - `url`, `title`, `description` (nullable), `image_url` (nullable) and `site_name`.
  - The URL and image columns are wide enough for 2048-character URLs.
- `Entry` gets no new field. The cards are read through the repository.
- The backup carries the cards like other entry data, if the backup writes entries' derived data. Otherwise they are rebuilt on open.

### 2. Which links a post shares — `LinkCard\Support\SharedLinks`

- A shared link is an `<a href>` with an http(s) URL whose visible text, without whitespace, reads as that URL:
  - it starts with `http://` or `https://`, or with the href's host;
  - it may end in `…`.
- Hashtags and mentions are excluded by this rule alone, with no class and no host list.
- A link whose paragraph text before it is exactly `RE:` is a quote, not a share.
- Bare URLs in text nodes outside an `<a>` are linked (as Bluesky RSS text and #1502's Mother Jones example carry them), and then count. This reuses `Text\Support\LinkedPlainText`'s URL pattern.
- Duplicates count once, in document order, with at most 5.

### 3. Reading a card — `LinkCard\PageCardReader`

- Fetches through `HtmlPageFetcher` and reads:
  - **title:** `og:title` → `twitter:title` → `<title>`;
  - **description:** `og:description` → `twitter:description` → `meta[name=description]`, capped at 300 characters on a word boundary;
  - **image:** `og:image` → `twitter:image`, resolved against the final URL, through `HttpsImageUrl::orNullUpgrading`;
  - **site:** `og:site_name`, else `HostKey::forUrl`.
- No title means no card. The card's `url` is the shared URL, not the final one.

### 4. Building cards — `LinkCard\PostLinkCards`

- `forCreated(list<Entry>)` runs at ingest, after the refresh flush in `FeedOutcomePersister`, beside `PostEnricher`.
  - It handles posts that are not Bluesky AT posts.
  - It spends at most **10 page fetches per feed refresh**. Posts beyond the cap are left for the open fallback.
- `forOpened(int $entryId)` is the fallback, called from `GET /api/entries/{id}` after the ownership check.
  - It runs only for a post with no cards that is not yet marked tried.
- Both paths:
  1. Link the bare URLs in the body, and store the body if it changed.
  2. Build a card for each shared URL. A failed page is skipped and logged at warning, and never fails the refresh or the GET.
  3. Replace the entry's cards.
  4. Mark the entry tried in the cache pool `post_link_cards.cache` (filesystem, 30 days).
- So a post is fetched for at most once, and a lost marker only repeats the work.
- The entry image is unchanged; the magazine card shows the card image itself.

### 5. Bluesky

- `EntryEmbedWriter` no longer appends the `external#view` card's HTML to the body.
  - It stores a `LinkCard` (position 0) from the AppView's uri, title, description and thumb.
  - It still removes the trailing bare URL, and still writes the lead image.
- `PostEmbedRenderer` stops rendering `external#view` HTML. `recordWithMedia` and the other embed types are unchanged.
- Bluesky entries stored before this change keep their body HTML card. Nothing is backfilled, as in #1499.

### 6. API

- `EntryJson::listRow` and `detail` gain `linkCards: list<{url, title, description, imageUrl, siteName}>`, in position order.
- The list query loads the page's cards in one extra query, keyed by entry id, not one per row.
- `imageUrl` goes through the same image proxy rule the client applies to `imageUrl` today.
- Native-client checklist (architecture §6): plain JSON, no HTML-only data. The card is structured.

### 7. Frontend

- `EntryDto.linkCards`.
- **Reader:** under a post's body, one card per entry in `linkCards`, with the markup and styles of the existing `.link-card` (#1499). Each card opens its URL in a new tab.
  - The body itself is still rendered as today.
  - Old Bluesky bodies that already carry a `figure.link-card` have no rows, so nothing doubles.
- **Magazine:** a new block, `entry-link`, used for every post whose `linkCards` is not empty, whatever slot the planner chose.
  - It renders the kicker line, the post text (`.title.post`), the first card stacked (the image at 1.91:1 with `object-fit: cover`, then the site, headline and description clamped to two lines), then `app-entry-meta` and duplicates.
  - The card itself links to the shared URL (new tab, propagation stopped). The rest of the block opens the post, as other blocks do.
  - Its planner height is the hero's.
- Styles in sibling `.scss` with tokens only, light and dark.

## Out of scope

- Previews for links inside ordinary articles.
- Generating cards for pages without metadata.
- Backfilling stored Bluesky HTML cards.
- Changing the list (non-magazine) rows.

## Testing

- **Unit:**
  - `SharedLinks`, with rows from the measured Mastodon markup: hashtag, mention, `RE:`, text link, bare URL, punctuation, duplicates, the cap.
  - `PageCardReader`: every fallback, relative image, http upgrade, no title, escaping, the description cap.
- **Integration:**
  - `PostLinkCards` with `MockHttpClient`: ingest fills rows; the cap leaves the rest; the marker stops a second fetch; a failure skips the card; Bluesky and non-posts are untouched.
  - `EntryEmbedWriter` stores a row instead of HTML.
- **Functional:** `GET /api/entries` and `/api/entries/{id}` carry `linkCards`. An unowned id 404s with no outbound request.
- **Migration:** the CI migration leg on SQLite and MySQL.
- **Frontend:** Jest for the planner rule (a post with cards gets `entry-link`), the block's render, and the reader's cards.
- **Real render:** the magazine and the reader at phone and desktop width, light and dark, with Mastodon and Mother Jones on Bluesky.

## 8. Entries still being completed (added 2026-10-10)

The user reported: right after subscribing to a Bluesky feed, the list shows posts whose embeds are not filled yet,
with nothing saying they will be. Enrichment waits for the feed's next scheduled refresh, which can be minutes away.
The first fetch (`FirstFetchRecorder`) builds no link cards either.

| Question | Decision (agreed) |
|---|---|
| Scope | Part of #1502 |
| Indicator | A placeholder card where the card or embed will go, with a subtle shimmer and a muted "Loading preview…", in the magazine and in the reader |
| Update | The client polls while visible entries are pending, and swaps in the finished entry without a reload |

### Pending, as data

`EntryJson` gains `pending: bool`. An entry is pending when either of these holds:
- a `PendingPostEnrichment` row exists for it (a Bluesky post waiting for the AppView), or
- it is a post that is not an AT post, was created within the last **3 days** (Bluesky's give-up window), has no
  link cards, is not marked tried, and `SharedLinks::in` finds at least one link in its body. The window bounds the
  rollout and the 30-day marker's expiry: an older post never shows as pending, and still gets its cards on open.

A `PendingLoader` batch-attaches the flag to list rows with one query for the Bluesky rows and one cache `getItems`
for the markers. That makes 1 query plus 1 cache read per page.

### Completing on demand

`POST /api/entries/complete`, with the body `{ "ids": [..] }`, takes at most 25 ids. Ids the caller does not own are
dropped silently, as the list already scopes them.

1. Bluesky: the caller's pending AT posts among the ids are filled through the AppView (one call, at most 25 URIs),
   the same way `PostEnricher` settles a chunk: fill, or drop the row when the post is omitted. A throttled host
   or an AppView failure leaves the rows queued.
2. Link cards: the remaining pending posts are built as on open, sharing a budget of **5** page fetches per call.
3. The response is `{ "entries": [listRow…] }` for the requested owned ids, each with fresh `linkCards` and `pending`.

It has its own limiter, `entry_completion` (sliding window, 120 per 5 minutes per user), so polling never spends
the reader view's budget. A POST with side effects suits a native client, and nothing in it
is browser-only.

### Frontend

- **Placeholder:**
  - In the magazine, a pending post renders as the `entry-link` block with a placeholder card instead of the first
    card. The placeholder is the image area as a shimmer block at 1.91:1, followed by a muted "Loading preview…".
  - In the reader, the same placeholder appears under the body.
  - The shimmer respects `prefers-reduced-motion`.
- **Polling:** while the current list holds pending entries, the client posts their ids (the first 25, oldest
  first) every **3 s**, at most **20** rounds per list load. It merges the returned rows into the list store and
  the open entry.
  - It stops when nothing is pending, when the list changes, or when the tab is hidden; it resumes when the tab is
    shown.
  - A failed call counts as a round.
- i18n: en "Loading preview…", de "Vorschau wird geladen…".

### Testing (additions)

- **Backend:**
  - `PendingLoader`: the Bluesky row, a link post that is untried versus tried, and a non-post.
  - The `complete` endpoint, with `MockHttpClient` for the AppView and the pages: it fills and returns pending
    false, drops unowned ids, enforces the 5-fetch budget, leaves a throttled AppView queued, and rejects more
    than 25 ids with 422.
- **Frontend:** placeholder rendering, and a polling service test with fake timers: it stops when nothing is
  pending, at 20 rounds, and on a list change.
- **Real render:** subscribe to a new Bluesky feed in Docker, and watch the placeholders fill in without a reload.
