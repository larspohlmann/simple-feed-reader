# Title-less posts (#1495) — design

Feed items without a `<title>` (Mastodon, Bluesky, any microblog) are stored as
`(untitled)`, so a magazine of posts shows a wall of identical headlines. This design
gives such an entry a title derived from its text, remembers that the title was
derived, and lets the SPA and the reader treat the entry as a post.

## Decisions

- **Hybrid model.** The entry stores a derived title *and* a flag `titleDerived`. Every
  consumer of `title` (search, digest, recommendations, tab title, URL slug, audio title
  fallback, iOS) keeps working unchanged; the SPA uses the flag to render the post text
  instead of a headline.
- **Generic, no host list.** The trigger is the item's shape (no title, a non-empty
  body), never the host: Mastodon runs on thousands of hosts.
- **No backfill.** Entries already stored as `(untitled)` stay as they are and age out.
- **Out of scope** (follow-up #1499): Bluesky images, video and quote posts from the
  public AppView, including the `[contains quote post or other embedded content]`
  placeholder, which stays in the body for now.

## Backend

### Deriving the title

A static helper `Service/Text/Support/DerivedTitle` turns an item's body into a title:

1. Plain text of the body (content, else summary) via `PlainText::linesFromHtmlBlocks`,
   one line per block.
2. Drop leading lines that hold no words once links are removed — a bare link, or a
   short label followed by a link, as in Mastodon's quote-post line `RE: https://…`.
3. Take the first remaining line, then its first sentence (ends at `.`, `!`, `?` or `…`
   followed by whitespace). A first sentence of a single word is a number or an
   abbreviation (`1.`, `e.g.`, `Dr.`), so it needs at least two words; otherwise the
   whole line is taken.
4. Cut to at most 80 characters at a word boundary and append `…` when cut.
5. No text left → no derived title.

The four parsers use it in place of their `'(untitled)'` fallback: `Rss2Parser`,
`Rss1Parser`, `AbstractAtomParser` and `WordPressJsonParser`. (The scraper's
`HtmlItemExtractor` always has a title — the link text — and stays as it is.) When the body is empty too, the title
stays `(untitled)` and the flag stays false.

`ParsedEntryModel` gains `bool $titleDerived` (default false), carried through its copy
methods (`withContentHtml`, `asDiscussionThread`, `withMedia`). `GuidFallback` keeps
receiving the raw (null) title, so guids do not change. Because the derivation sits in
the parsers, feed preview and discovery show readable titles too.

### Storage

- `entry.title_derived BOOLEAN DEFAULT 0 NOT NULL`, one migration, verified from empty on
  SQLite and MySQL.
- The title and the flag live together in the `EntryHeadline` embeddable (keeping `Entry`
  under the PHPMD field limit); `Entry` delegates `isTitleDerived()`/`markTitleDerived()`
  to it, `IngestedEntryFactory` marks it from the parsed model, and storing a new title
  resets the flag.
- Backup carries it: `BackupLines`, `EntryLine`, `EntryBatchInserter`,
  `BackupFieldDeclarations`, `FullyPopulatedAccount`, `docs/backup.md` (the #1140 file set).

### API

`EntryJson::commonFields` exposes `titleDerived: bool` (list rows, detail, for-you list
wherever `commonFields` is used). The array-shape PHPDocs follow.

### Reader

A title-derived entry *is* the post; its page is an app shell with nothing to extract.

- `EntryReaderController` returns `ExtractionResultModel::failed(null,
  ExtractionFailure::FeedBodyIsPost)` without fetching — a new case
  `FeedBodyIsPost = 'feed_body_is_post'` — so a native client gets the same answer.
  Ownership is checked first (an unowned id still 404s); a post is answered before the
  reader rate limiter, so it spends no reader budget.
- The reader audit sampler (`ReaderAuditRepository`) skips title-derived entries.

## Frontend

- `EntryDto.titleDerived: boolean`.
- **Reader.** `ArticleSource.open()` treats a title-derived entry like a url-less one:
  no reader request, original mode, the feed body shown, no fallback note. The url stays,
  so "open original" still leads to the post on its platform. The `h1` stays as the focus
  target but is visually hidden (`sr-only`) for a post; the byline leads, then the body. The mini title and bar title keep the
  derived title.
- **List row.** With `titleDerived`, the row renders the excerpt in the title's place —
  body font, clamped to about four lines, search highlighting kept — and no separate
  snippet.
- **Magazine.** `EntryBlockBase` gains `headline` (title, or the clamped excerpt for a
  post, the title when the excerpt is empty) and renders `entryDek()` as its dek, empty
  for a post; every block template binds `headline` and applies a body-font class for
  posts. The planner and slot fits read `entryDek()` too, so a post is never offered a
  `kicker` or `quote` (neither would show its copy), drops to compact or image blocks,
  and does not make a view text-rich.

## Testing

- Backend: `DerivedTitle` rows (sentence, one-word sentence, line, link-only lines,
  `RE:` line, truncation, empty); each parser with a title-less item; `ParsedEntryModel` copy methods keep the
  flag; ingest stores it; reader controller answers `feed_body_is_post` with no fetch and no reader budget;
  `EntryJson` field; backup round trip; migration leg in CI.
- Frontend (Jest, in the container): row, magazine block and reader rendering for a post;
  `ArticleSource` makes no request for a post.
