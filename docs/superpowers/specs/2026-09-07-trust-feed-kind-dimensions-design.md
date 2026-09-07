# Trust feed-declared kind/dimensions in the reader (#914) — design

**Status:** approved scope (user chose "both wins in one PR"), pre-implementation.
**Foundation:** #906 (PR #912) persists feed media kind/mime/width/height on the
entry; #913 (PR #917) added `FeedFallbackPoster` and the `?string $fallbackPoster`
reader seam. This ticket widens that seam and wires the feed data into the reader.

## Problem

The reader re-derives, from scraped HTML, metadata the feed already states:
- **Dimensions** — the reader emits **no** `width`/`height` at all today (the
  rendition machinery uses width only to pick the sharpest URL, then discards it;
  `LazyImageSources` even strips dimensions). Every reader image causes layout
  shift.
- **Kind** — `MediaUrlKind` infers kind from the file extension; a feed
  `<media:content type=/medium=>` states it directly, and an extensionless URL the
  feed enumerates is dropped before it can render.

## Foundation: `FeedMedia` VO

`src/Service/Reader/FeedMedia.php`, `final readonly`, built `fromEntry(Entry)`.
Holds the entry's `media[]` (list<EntryMedium>), `attachments[]`
(list<EntryAttachment>), and lead image URL. Subsumes `FeedFallbackPoster`
(deleted; its one caller moves to `FeedMedia`). API:

- `posterFallback(): ?string` — first video medium's `previewImageUrl`, else the
  lead image (the #913 rule, moved here).
- `declaredMediumFor(string $url): ?EntryMedium` — matches `url` against `media[]`:
  an `image` medium via `ImageIdentity::isSameAsset` (CDN-rendition safe), a
  `video` medium via bare-URL (query-stripped) equality. Callers read
  `kind`/`width`/`height`/`previewImageUrl`.
- `declaredAttachmentFor(string $url): ?EntryAttachment` — bare-URL match against
  `attachments[]`, for `mimeType`/kind of audio/video enclosures.

`FeedMedia::none()` for the no-entry callers.

## Threading

Replace the `?string $fallbackPoster` seam with `?FeedMedia $feedMedia`:
`EntryReaderController` builds `FeedMedia::fromEntry($entry)` →
`ArticleExtractor::extract(url, title, author, ?FeedMedia)` →
`PageMediaScanner::scan(html, url, ?FeedMedia)` **and** →
`ReaderBodyCleaner::clean(..., ?FeedMedia)`. The four non-reader `extract` callers
pass `null` (→ `FeedMedia::none()` inside). `resolvePoster` now reads
`$feedMedia?->posterFallback()`.

## Win 1 — dimensions (additive, one pass)

`FeedDimensionStamper` runs as the final step of `ReaderBodyCleaner::clean`, over
the assembled document before serialize. For each `<img src>` and `<video src>`,
if `FeedMedia::declaredMediumFor(src)` has both `width` and `height`, stamp them
(never overwrite dimensions readability already carried). `EntrySanitizer` already
allow-lists `width`/`height` on `img`; add them for `video`. Covers the restored
lead image, body images, and video posters uniformly in one place — no scatter
across `ReaderLeadImage`/`PageMediaInserter`/`MediaMarkup`.

## Win 2 — kind (keep extension-sniffing as fallback)

`PageMediaScanner`, **after** the merge, reconciles each candidate against
`FeedMedia`: a candidate whose URL matches a feed medium/attachment adopts the
feed-declared kind when it is a safe A/V correction and carries the feed
`mimeType`. Extension-sniffing (`MediaUrlKind::byExtension`) stays the default and
the fallback for non-enumerated media. `MediaCandidate` gains an optional
`?string $mimeType`.

**Deliberately out of scope (→ follow-up / #916):** injecting feed-enumerated A/V
that the page-scan sources never surfaced (extensionless-URL *rescue*). That means
threading feed data back into the stateless sources (the tramp-data shape #913
removed) or synthesising candidates with no page anchor (feed-as-content, #916).
This PR trusts the feed for media the scan already found; it does not turn the
feed into a new discovery source.

## Testing

- `FeedMediaTest` — posterFallback rule; image match via ImageIdentity (rendition
  URLs match, a different asset does not); video match by bare URL; attachment
  match; no-match returns null.
- `FeedDimensionStamperTest` — stamps feed width/height on a matching `<img>`;
  leaves a non-matching image and an already-dimensioned image alone; stamps a
  `<video>`.
- `PageMediaScannerTest` — a matched candidate adopts the feed kind/mime; an
  unmatched one keeps its sniffed kind.
- Reader/controller functional test — a reader response carries feed dimensions on
  the lead image.
- Full suite + `composer check`/`md` green.
