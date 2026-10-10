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
