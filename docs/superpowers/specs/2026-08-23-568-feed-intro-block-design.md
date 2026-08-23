# Feed intro block: description, image and homepage (#568)

**Status:** approved (2026-08-23)
**Issue:** https://github.com/larspohlmann/simple-feed-reader/issues/568

## Problem

A feed document carries channel-level metadata about itself: a description, a
logo or banner image, and the address of the site it belongs to. The reader
shows none of it. Select one feed in the sidebar, and the list header gives a
title, a favicon and a "Last refreshed" hint — nothing that says what the feed
is or where it comes from.

Two of the three values are already stored. `Feed.description` and
`Feed.siteUrl` are written on every fetch by `EntryIngestor`, and `siteUrl`
already reaches the SPA on `SubscriptionDto`. Only the reader never renders
them.

The third value does not exist anywhere. **No parser reads the channel-level
image.** `ItemImageExtractor` is per-entry only, and `Feed.faviconUrl` is not
it either: `RefreshRunner` resolves that from the site's icon, not from the
feed document. A feed's own logo is new data end to end — parser, schema,
backup, API, UI.

## Goal

When the selection is a single feed, show that feed's image, description and
homepage link at the top of the content area, above the first entry row, and
let the block scroll away with the list.

## Decisions

1. **Top of the content, not the list header.** The list header is sticky and
   collapses on scroll; making it taller moves the rows under the finger
   (#419). The entry list already owns an outlet built for exactly this and
   used by nobody: `topBlock`, "rendered at the top of whichever content branch
   is live (empty state, magazine rows, list rows) so it scrolls away with the
   list rather than occupying a permanently reserved bar above it (#321)"
   (`entry-list.component.ts`). This feature is its first consumer and needs no
   new plumbing.

2. **The real feed image, not the favicon.** *(Lars's call, 2026-08-23.)* A
   favicon is a small square site icon; a feed's `<image>` or `<logo>` is the
   publisher's own mark. Enlarging the favicon would have cost nothing, and was
   rejected: it never shows the logo that many feeds actually carry. There is
   no favicon fallback either — a feed with no image shows no image.

3. **A new column, not an embeddable.** `Feed.imageUrl` sits beside
   `faviconUrl` as a plain nullable column. Grouping the two presentation URLs
   into a `FeedBranding` embeddable, in the style of `FetchSchedule`, is
   tidier, and was rejected: it churns the ORM mapping and all five backup
   files for no gain this feature needs.

4. **Only `https://` image URLs survive.** The SPA is served over https, so an
   `http`, protocol-relative, relative or `data:` URL cannot render in an
   `<img>`. This mirrors `FeedPreviewService::httpsImageUrl()` and the reader's
   `firstPreviewImage` rule. A feed document is untrusted input; the guard is
   also what keeps a `javascript:` value out of the DOM.

5. **No backfill.** Existing feeds keep `image_url = NULL` until their next
   successful fetch fills it. A one-off backfill command would re-fetch every
   feed in the instance to populate a decoration.

## Where a feed image comes from

| Format | Node | Result |
|---|---|---|
| RSS 2.0 | `/rss/channel/image/url` | the feed's logo |
| RSS 1.0 (RDF) | `/rdf:RDF/image/url` | the feed's logo |
| Atom | `/feed/logo` | the feed's banner |
| WordPress JSON | — | `null` |
| Scraped HTML | — | `null` |

Atom's `<logo>` is used, not `<icon>`. `<icon>` is favicon-shaped by
specification, and `Feed.faviconUrl` already holds that role; reading both into
one column would make the field mean two different things.

Scraped pages return `null` deliberately. `og:image` is the page's picture, not
the site's mark — using it would put an article photo in the feed header.

## Backend

**Parsing.**

- `ParsedFeed` gains `?string $imageUrl` as a **required** constructor
  parameter. It has six construction sites, and requiring the value makes each
  one fail to compile until it answers for it. A defaulted parameter would let
  a caller forget silently, which is the failure mode this whole ticket is
  about.
- One of those six is not a parser. `FirstFetchRecorder::newest()` rebuilds a
  `ParsedFeed` to cap the entry list on subscribe, copying the other fields
  across by hand — so a brand-new subscription would show no image until its
  second fetch. Rather than teach that copy a fifth field, `ParsedFeed` gains
  `withEntries(array $entries): self`, and `FirstFetchRecorder` calls it. The
  drift then cannot recur when a seventh field arrives.
- New `FeedImageExtractor` in `Service/Parser/`, one static method per format,
  holding the https guard in one place. It mirrors the existing
  `ItemImageExtractor` and keeps `Rss2Parser`, `Rss1Parser` and
  `AbstractAtomParser` thin.
- `WordPressJsonParser` and `HtmlItemExtractor` pass `null` explicitly.

**Persistence.**

- `Feed` gains `imageUrl`, `#[ORM\Column(length: 2048, nullable: true)]`, beside
  `faviconUrl`, with a getter and a setter. Feed is PHPMD-clean today and stays
  clean: PHPMD's `TooManyMethods` ignores accessors.
- A migration adds `image_url`. CI's dedicated migration leg runs it from empty
  on SQLite and MySQL, then validates the schema.
- `EntryIngestor` persists it with the same shape as its `siteUrl` and
  `description` branch: write only when the feed supplies a value, so a later
  fetch that omits the element does not erase a logo that was there.

**Backup.** A backed-up table gained a column, so the projection must follow it
in five places, or #556's guard fails the suite:

1. `AccountBackupExporter` writes the `imageUrl` key on the feed line.
2. `Service/Backup/Dto/FeedLine` reads it.
3. `RestoreLoadPass` sets it on the restored feed.
4. `tests/Support/BackupFieldDeclarations` declares it as backed up.
5. `docs/backup.md` lists it in the feed section.

**API.** `SubscriptionJson::one()` adds `description` and `imageUrl`; `siteUrl`
is already carried. The description goes through `PlainText::fromHtmlBlocks()`
and a 300-character cap: feed descriptions routinely carry markup, plain text
keeps the SPA out of any sanitiser decision, and the cap bounds the sidebar
bootstrap, which returns every subscription in one payload.

## Frontend

- `SubscriptionDto` gains `description: string | null` and
  `imageUrl: string | null`.
- New `app-feed-intro` component in `reader/feed-intro/`, with a sibling
  `.scss`. Inputs: the three values. It renders the image, the description and
  the homepage link.
- `reader-shell.component.html` declares one `<ng-template #feedIntro>` and
  passes `[topBlock]="feedIntro"` to both `<app-entry-list>` instances (the
  wide and narrow layouts). The template renders only when the selection is a
  subscription **and** at least one of the three values is present.

**Layout.**

- Image left, text right, on wide screens; image above the text on narrow ones.
- The image gets a token-based maximum height, automatic width and
  `object-fit: contain`, so an 88×31 RSS button and a 1500 px banner both
  behave.
- `loading="lazy"`, and an `(error)` handler hides the image, so a dead logo
  URL leaves no broken-image box.
- No line clamp on the description; the 300-character server cap already bounds
  the height.
- The homepage link uses the `open_in_new` icon and
  `rel="noopener noreferrer"`, matching the edit-subscription dialog.
- Tokens only: no hex colours and no raw `px` outside `theme/`.

## Testing

- **Parsers**, per format: the image is read from the right node; an `http://`
  URL is dropped to `null`; a missing element gives `null`.
- **`EntryIngestorTest`**: the image is persisted, and a later fetch carrying no
  image does not clear it.
- **`FirstFetchRecorderTest`**: a feed subscribed for the first time keeps its
  image through the entry cap — the regression `withEntries()` exists to
  prevent.
- **Backup**: `BackupSchemaCoverageTest` (the declaration), `GoldenBackupRestoreTest`
  (the round trip), and the frozen corpus — files written before this change
  carry no `imageUrl` and must restore as `null`.
- **Migration**: the existing CI leg, from empty, on SQLite and MySQL.
- **`SubscriptionJson`**: an HTML description is flattened; a long description is
  capped at 300 characters; `null` stays `null`.
- **Frontend**: `feed-intro.component.spec.ts` for each part appearing and each
  part disappearing when its value is `null`; `reader-shell.component.spec.ts`
  for the wiring, including the views that must **not** show the block (All
  items, a tag, a search, Favorites, Kept, Recently read, For you).
- `composer infection:diff` gates the changed backend lines.

## Non-goals

- No favicon fallback when a feed carries no image.
- No backfill of `image_url` for existing feeds.
- No feed image in the add-feed preview dialog or the discover catalog.
- No feed URL in the block; the edit-subscription dialog already shows it.
- No collapse or dismiss control on the block; it scrolls away.
