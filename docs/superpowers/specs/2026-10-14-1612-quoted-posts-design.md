# #1612 — quoted posts as nested, playable quote cards (design)

Issue: https://github.com/larspohlmann/simple-feed-reader/issues/1612. Builds on #1502 (link cards), #1522 (media
block), #1547 (share data model), #1615 (Mastodon status lookup).
Design agreed 2026-10-14 against a mockup (https://claude.ai/artifact/AZuifoj4FDLTSBQ1V738yk). The issue's original
asks — longer text on every share, media on link shares, shares in list rows — are **out of scope**; #1612 is only about
quoted posts.

## The bug that started it

A Bluesky post quoting a post with a video (entry 594781, subscription 1818) shows as a `link` share: a bsky.app link
card titled with the quoted author, its text cut to 300 chars, and the video's thumbnail as a static image. Bluesky
shows the quoted post nested, with its full text and a playable video.

Cause: `PostEmbedRenderer::quote()` (`Service/Bluesky/PostEmbedRenderer.php`) flattens `app.bsky.embed.record#viewRecord`
into a `LinkCardContent` — author name as title, `value.text` as description (clipped by `StorableLinkCard`),
`quotedImage()` as image. The quoted post's video `playlist`, other images, time and line breaks are dropped at ingest,
so no frontend change can recover them. Mastodon quotes ("RE: <link>", stripped by `QuoteLine`) become a link card
built from the quoted status page's `og:` tags.

## Behaviour

- A post that quotes another post is a **`quote` share**. Its card shows the post's own text, then its own media
  (Bluesky `recordWithMedia`: own media first, as on Bluesky), then the quoted post nested in a bordered box:
  - header: avatar (20px circle), display name, @handle, relative time (`6h`); absolute time in the reader view;
  - the quoted text in full with its line breaks (plain text, `white-space: pre-line`);
  - the quoted post's media: images in the media block's grid (≤ 4, 16:9 cells), video as the native player (HLS via
    hls.js, as the media block), playable in place.
- Clamps in the magazine: the post's own text 6 lines (`card-states(6)`, as the media block); the quoted text 8 lines.
  The reader view clamps nothing.
- Clicking the video plays it. Clicking anywhere else in the nested box opens the **quoted post** on its network (new
  tab). Clicking the rest of the card opens the entry.
- One nesting level only: a quote inside the quoted post renders as one line, "Quoting a post by @handle", linking to it.
- Avatar: hot-linked with `appProxiedImage`; on `imageFailed` it shows the author's initials.
- A quote that is unavailable (Bluesky `viewNotFound`/`viewBlocked`/`viewDetached`, Mastodon quote state other than
  `accepted`, a failed Mastodon lookup) keeps today's behaviour: a `link` share with a link card, or the
  "View embedded content on Bluesky" fallback.
- Views: a new magazine block and the reader view. List rows are unchanged.
- **New entries only.** Stored entries keep their link card; no backfill command.

## Backend

### Storage (`App\Entity`, it is what an entry stores — architecture §8)

- `ShareKind::Quote = 'quote'` (`src/Enum/ShareKind.php`; the column is `length: 8`, fits).
- New embeddable `QuotedPost` on `Entry` (nullable columns, prefix `quoted_`): `url` (2048), `authorName` (255),
  `authorHandle` (255), `authorAvatarUrl` (2048, nullable), `postedAt` (naive UTC, nullable — normalise to UTC before
  persisting, CLAUDE.md gotcha), `text` (TEXT), `media` (JSON, the `EntryMedium` shape incl. `previewImageUrl`; reuse
  `EntryMedium`'s (de)serialisation rather than a second shape), `nestedQuoteUrl` (2048, nullable), `nestedQuoteHandle`
  (255, nullable). An absent quote is the embeddable's empty state, not `null` columns read ad hoc — follow how
  `EntryMedia`/`EntryImage` model "none".
- A carrier value for the parsers, e.g. `App\Entity\QuotedPostContent` (like `LinkCardContent`), so Bluesky and
  Mastodon build the same thing and neither imports the other.
- Migration for SQLite and MySQL; CI's migrate-from-empty leg must pass (tests never run migrations).

### Bluesky

- `PostEmbedRenderer::quote()` returns the structured quote instead of a link card: `RenderedEmbedModel` gains a
  `?QuotedPostContent $quote` (carried by `followedBy`). Read from the `viewRecord`: `uri` → web URL (`AtPostUri`),
  `author.displayName`/`handle`/`avatar`, `indexedAt` or `value.createdAt`, `value.text`, and `embeds[0]`:
  `images#view` → images (fullsize + aspectRatio), `video#view` → video (playlist, thumbnail, aspectRatio),
  `record#view` / `recordWithMedia#view` → its media plus the nested quote URL/handle, `external#view` → no media.
- `viewNotFound` / `viewBlocked` / `viewDetached` / a non-post record → today's fallback.
- `EntryEmbedWriter::fill()` writes the quote onto the entry and sets `ShareKind::Quote` when a quote was rendered.
  The quote's media do **not** enter the entry's own `media` list (that stays the outer post's) — so the media block,
  `sharedMedia()` and the lead image are unaffected. Decide in the plan whether the outer lead image should fall back to
  the quote's first image for list rows; default: no.
- Both paths go through this: Bluesky API feeds (`KnownShareSettler::settleEmbedded`) and public RSS feeds
  (`PostEnricher`).
- The quoted text is not clipped by `StorableLinkCard`'s 300; Bluesky caps posts at 300 graphemes anyway. Cap
  defensively (e.g. 3000 chars) at a word boundary.

### Mastodon

- **Native quotes (API feeds):** `MastodonTimelinePage` reads `originalOf($status)->node('quote')`. When `state` is
  `accepted` and `quoted_status` is a full status, build the `QuotedPostContent` from it (`url`, `account.display_name`,
  `account.acct`, `account.avatar`, `created_at`, `content` → plain text with line breaks, `media_attachments` via the
  existing `mediaOf`, its own `quote` → nested URL/handle). Carry it through `KnownSharesModel` next to the link cards
  and settle it in `KnownShareSettler` (new `settleQuoted`). A shallow quote (`quoted_status_id` only) goes to the
  lookup below.
- **"RE:" quotes and public RSS feeds:** in the background link-card worker (`ShareLinkCards::complete`), before
  reading a page's `og:` card for a target URL, try `MastodonStatusAddress::statusApiUrlOrNull($url)`; when it matches,
  GET the status through `MastodonApiClient` unauthenticated (the `MastodonOriginCountsSource` pattern from #1615:
  guarded client, accept only when the answer's `uri` or `url` equals the address). Success → write the quote and set
  `ShareKind::Quote`; failure → fall through to today's link card. Only the target that the post's "RE:" line names is
  a quote (`QuoteLine` already finds it). Mind the module graph: the lookup lives in `Service/Social`; if `LinkCard`
  calls it, `LinkCard → Social` must not close a cycle (`ServiceModuleCycleRule`) — otherwise `LinkCard` owns an
  interface `Social` implements.
- Strip Mastodon's `<p class="quote-inline">RE: …</p>` fallback from the body when the quote is stored (as `QuoteLine`
  does today for cards).

### Classification

`EntryShareClassifier` stays the gate for "is this a share at all" (own prose ≤ 500). A rendered quote upgrades a
`Link` share to `Quote`; it never turns a non-share into a share.

### JSON (`src/Http/EntryJson.php`)

`completed()` (list and detail) gains `quote: QuoteJson | null`:
`{url, authorName, authorHandle, authorAvatarUrl, postedAt (ISO, UTC), text, media: EntryMedium[], nestedQuote: {url, handle} | null}`
via a new `src/Http/QuotedPostJson` mapper; `share` gains `'quote'`. Bumps nothing else. iOS-viable: plain JSON, no
HTML.

## Frontend

- `models.ts`: `EntryShare = 'link' | 'media' | 'quote'`; `QuotedPostDto`; `EntryDto.quote: QuotedPostDto | null`.
- **Shared component** `reader/quoted-post/` (`app-quoted-post`, inputs `quote`, `layout: 'block' | 'article'`): the
  nested box from the mockup. Reuses the media block's image grid and video markup — extract a shared
  `app-post-media` (or similar) from `entry-media` rather than copy it (third occurrence rule; check design-language.md's
  shared catalog first). The box is an `<a href target="_blank" rel="noopener">`; the video and its controls stop
  propagation, as `entry-media` does. `pauseMedia` / `armHlsStreams` / `releaseHlsStreams` as in `entry-media`.
- **Magazine block** `blocks/entry-quote/`: kicker line, `.title.post` with `card-states(6)`, own media (via the shared
  media component, when present), `app-quoted-post layout="block"`, meta, duplicates. Register `'quote'` in
  `SHARE_KINDS`, `magazine-block.component.html` `@case('quote')`, `magazine-slot-fit.ts` (`sharesQuote`, `shareKind`).
- **Per-entry height** (quote only; link and media keep `BLOCK_HEIGHT` 463): replace the fixed lookup for `quote` with
  a function of the entry — chrome + own-text lines (chars ÷ chars-per-line at the 390px measure, capped at 6) + own
  media height + quote header + quoted-text lines (capped at 8) + quoted media height (from width/height, 16:9
  fallback; grid of 2–4 as one 16:9 block). `layOutPage`/`withinBudget` read heights through it. Calibrate the
  constants against a real render at 390px, as the existing table was ("Measured at 390px viewport width").
- **Reader view** (`reader-view.component.html`): render `app-quoted-post layout="article"` where `<app-link-cards>`
  sits for a quote share; the article's video decorators (`playVideosInline`, cinema toggle) should apply to it.
- Styles in sibling `.scss`, tokens only (no hex, no ad-hoc px, no media-query literals); update
  `docs/design-language.md` (block table: add Quote with its clamps and height rule; shared catalog: the new
  component).
- i18n keys for "Quoting a post by {handle}" and the avatar/video labels.

## Tests

- Backend: `PostEmbedRenderer` fixtures for quote with video, with images, with record-with-media, with a nested quote,
  and the three unavailable views; `MastodonTimelinePage` native quote (accepted, shallow, rejected); the worker's
  status lookup (match, address mismatch, failure → link card); `EntryJson` shape; migration on both dialects.
- Frontend: `entry-quote` and `quoted-post` specs (clamps, click targets, nested line, avatar fallback), slot-fit and
  planner specs for the per-entry height.
- Gates: `composer check`, `composer md` (every touched src file clean), `composer infection:diff`, both PHPUnit legs,
  `npm run check`.
