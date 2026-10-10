# #1499 — Bluesky embeds from the public AppView

Follow-up to #1495. Bluesky's profile RSS carries only a post's text. Each image, video, quote or other
record embed is replaced by the line `[contains quote post or other embedded content]`. A link card
leaves only the bare URL at the end of the text. This spec fills those embeds from Bluesky's public
AppView after ingest, and retries until the AppView answers.

## Measured (2026-10-10, from the php container)

- An item's `guid` is the post's AT URI: `at://did:plc:…/app.bsky.feed.post/<rkey>`. Its `<description>`
  is plain text with `&#xA;` newlines and holds full URLs.
- `https://public.api.bsky.app/xrpc/app.bsky.feed.getPosts?uris=…&uris=…` answers anonymously. It takes up
  to 25 URIs, and returns `posts[]` without the ones that are deleted or hidden.
- Mother Jones, 54 recent posts: 50 `external#view` (link card), 3 `video#view`, 1 `recordWithMedia#view`.
  The bsky.app account: mostly `record#view` (quotes, starter packs).
- `record.text` truncates link display text (`motherjones.com/politics/2…`) and keeps the URL in a facet.
  The RSS text has the full URL, so **the RSS text stays the body text** and the AppView provides only
  the embed.

## Decisions (agreed)

| Question | Decision |
|---|---|
| Video | Plays in the reader: an inline `<video>` over the HLS playlist, poster = thumbnail |
| Link cards | Rendered: a link block replaces the trailing bare URL; this settles #1502 for Bluesky only |
| AppView down | Retry on later refreshes |
| Which entries | New entries only; nothing already stored is backfilled |
| Shape | A post-ingest enrichment pass (approach A): one path serves the first try and every retry |

## Design

### 1. Ingest: the placeholder never lands

`BlueskyEntryRule` (`Service/Ingest/PlatformEntryRule/`, tagged like `RedditEntryRule`):

- `supports()`: the guid matches `^at://[^/]+/app\.bsky\.feed\.post/[^/]+$`. The guid's shape decides,
  with no host list.
- `apply()`: removes the placeholder line from the summary and the content. If the feed gave no title,
  it derives the title again from the remaining text with `EntryTitle::of`. An image-only post's text is
  empty after the removal, so it becomes untitled, never a title reading `[contains quote post…]`.
  `ParsedEntryModel` gains the copy methods this needs.

This holds even when enrichment never succeeds.

### 2. Pending state: its own table, not an Entry field

`Entry` sits at PHPMD's field limit (#1495 had to split `EntryHeadline` out for that reason). So the
pending state gets its own entity, `PendingPostEnrichment`, in table `pending_post_enrichment`:

- `entry` is a OneToOne link to the entry, `onDelete: CASCADE`, unique.
- `queuedAt` is a naive-UTC datetime.

A row means: this entry is a Bluesky post whose embed is not filled yet. Rows are transient, so they are
not in the backup. A new entry gets a row only through §3. Nothing is backfilled for stored entries,
since there is no migration data step.

### 3. Enrichment pass, after the refresh's flush

A new service module, `Service/Bluesky`, owns the AppView. `Refresh` depends on it, and it depends on
`Fetch`, `Ingest` (image and media helpers) and `Sanitize`. No module depends back on `Refresh`, and
`Ingest` knows nothing about `Bluesky`. The `BlueskyEntryRule` guid pattern is the one shared fact, and
it lives in a `Support/` helper of `Ingest` that `Bluesky` reuses.

`FeedOutcomePersister` calls `PostEnricher::enrich(Feed $feed, list<Entry> $createdEntries)` after the
flush of a fetched outcome (`storeFetched`), and also after a not-modified outcome with no created
entries, so retries still run when the feed answers 304. A fetch failure or parse failure skips the pass.
An exception inside the pass is caught and logged, and never changes the refresh outcome.

`enrich()`:

1. Queues a `PendingPostEnrichment` for each created entry whose guid is an AT post URI.
2. Drops the feed's rows queued more than **3 days** ago. That gives up on them, and the entry keeps
   its RSS text.
3. Loads the feed's remaining rows, oldest first, at most **100** per pass (4 AppView calls).
4. Skips the call if `HostThrottle` reports a wait for `public.api.bsky.app`.
5. Fetches them in chunks of 25 through `FeedFetcherInterface`, the SSRF-guarded fetcher.
   - A `FeedThrottledException` is recorded on `HostThrottle`, as `CommentsLoader` does.
   - Any `FetchException` or invalid JSON leaves that chunk's rows in place for the next refresh and
     logs a warning.
6. For each answered URI, fills the entry (§4) and deletes the row. A URI the AppView omits (deleted or
   hidden post) also deletes the row, and the entry stays as it is.
7. Flushes.

### 4. Filling an entry

`PostEmbedRenderer` turns one post view's `embed` into an HTML fragment plus a lead image and a media
list. The enricher then sets the following on the stored entry:

- `contentHtml`: the existing body, minus the trailing bare URL when it equals the link card's URI,
  followed by the fragment. Text from the AppView is HTML-escaped, and the whole result goes through
  `EntrySanitizer`.
- `summary`: `EntrySnippet` of the body text without the fragment.
- Image: the lead image goes through `EntryImageWriter::write`, but only if the entry has no image yet.
- Media: the rendered media go through `EntryMediaAssembler` into `setMedia`.

The title is not touched.

How each embed renders:

| Embed `$type` | Fragment | Lead image | Media |
|---|---|---|---|
| `images#view` | One `<img src=fullsize alt=alt width height>` per image, in `<figure class="post-images">` | First image | One Image medium per image |
| `video#view` | `<video controls preload="none" playsinline poster=thumbnail src=playlist>` in `<figure class="post-video">`, then a "Watch on Bluesky" link to the post | Thumbnail | One Video medium: url = playlist, preview = thumbnail |
| `external#view` | `<figure class="link-card">` holding a link to `uri` with title, description and host | `thumb`, when present | — |
| `record#view` → `viewRecord` of an `app.bsky.feed.post` | `<figure class="quote-post"><blockquote>` with the quoted text and the author's display name and handle, linking to the quoted post on bsky.app | — | — |
| `recordWithMedia#view` | The quote fragment followed by the media fragment, each rendered as above | From the media | From the media |
| Anything else (`viewNotFound`, `viewBlocked`, `viewDetached`, feed generator, list, starter pack, unknown types) | One "View embedded content on Bluesky" link to the post | — | — |

- A quoted post's own embed is not rendered. The quote links to it.
- The bsky.app post URL is `https://bsky.app/profile/<did>/post/<rkey>`, built from the AT URI.
- Every URL from the AppView must be https, or it is dropped (`HttpsImageUrl`).

### 5. Sanitizer and the reader

- `EntrySanitizer` must let through:
  - `video` with `src`, `poster`, `controls`, `preload` and `playsinline`;
  - the `class` values `post-images`, `post-video`, `link-card` and `quote-post` on `figure`.
- Any allowance the current config lacks is added. A test pins each allowance by sanitizing the
  renderer's output.
- The SPA renders a post's stored body as today (#1495). The article styles gain rules for:
  - `.link-card`: a bordered block with the title, description and host;
  - `.quote-post`: an indented blockquote with an author line;
  - `.post-images`: a grid for multiple images;
  - `.post-video`: a video at full width, honouring the poster's aspect ratio.
- Chrome and Safari play HLS natively. A browser that cannot play it shows the poster, and the "Watch on
  Bluesky" link remains.
- Cards in the list gain the entry image with no further change.

## Out of scope

- Threads (self-replies), reposts, Bluesky custom feeds and lists.
- Mastodon link previews, which stay in #1502.
- Backfilling stored entries.
- Facet rendering (mentions, hashtags).

## Testing

- **Unit:**
  - `BlueskyEntryRule`: guid matching; placeholder removal from summary and content; the image-only
    post becomes untitled.
  - `PostEmbedRenderer`: one row per embed type, built from **recorded AppView JSON** saved under
    `tests/Fixtures/Bluesky/`. Includes the unknown-type fallback, escaping of hostile text, and
    dropping non-https URLs.
- **Integration** (`PostEnricher`, with a stubbed `FeedFetcherInterface`):
  - fills and dequeues;
  - an omitted URI dequeues;
  - a fetch failure keeps the row;
  - an old row is dropped;
  - 30 pending rows make 2 calls;
  - a throttled host makes no call;
  - an existing image is not overwritten.
- **Functional:** a refresh of a Bluesky feed fixture through `FeedOutcomePersister` stores the
  filled entry, and the 304 path still retries.
- **Sanitizer:** the renderer's output for every embed type survives `EntrySanitizer` unchanged in
  its allowed parts.
- **Migration:** the CI migration leg on SQLite and MySQL.
- **Frontend:** Jest for any new rendering logic; the styles are verified on the real render.
- **Live smoke:** the e2e-admin account with Mother Jones (link cards, video) and bsky.app (quotes,
  starter packs). Check the reader in Chrome.
