# #1524 — Link and media entries from any feed use the post cards

## Goal

Any feed entry that is really a shared link or a shared image/video gets the magazine's
`link` block (#1502) or `media` block (#1522), whatever site it comes from — not only the
entries whose title was derived from their text.

## Decisions (agreed 2026-10-11)

- **One classification, every feed.** A persisted `entry.share` (`link` | `media` | null)
  decides the card. `titleDerived` goes back to meaning only "the headline came from the
  body"; `isShort` no longer gates a card (it only selects the Shorts player).
- **Short own text only.** A share keeps its card only while its own prose is at most
  **500 characters** (Mastodon's post limit). Applies to posts too.
- **Media is decided by the target, never by the body's shape.** Body shape cannot tell a
  media entry from a news teaser (measured below). A share is `media` when its target *is*
  media or the target page *declares* itself media.
- **Titled cards show title + text.** The real title is the headline; the cleaned own
  text shows beneath it.
- **Backfill:** a one-off script against the local Docker DB. Nothing committed for it, no
  migration of data, no production backfill.
- **No Reddit-specific work** (Lars, 2026-10-11): Reddit turns its RSS off within weeks.
  `RedditEntryRule` stays as it is; Reddit entries (url-less threads) stay articles.
- **Own-URL video: only Shorts.** A YouTube Short keeps the media card as today; other
  videos whose own URL is a player (channel feeds) stay articles.

## Measurements behind the decisions (dev DB, 2026-10-11)

- Body shape fails for media: entries with an image and < 280 chars of text are the normal
  news teaser (Zeit 1970, SZ 1952, heise 1921, tagesschau 1802 …); image with *no* text
  still claims 787 (World News) + 138 (CBC) teasers.
- Off-site entry URL alone fails for links: ZDF→3sat/phoenix (243), tagesschau→ndr/mdr
  (129), politico.eu→.com (106). Requiring an **on-site home** removes ZDF, tagesschau and
  NDR (opaque GUIDs); politico.eu (home on site) falls to the prose rule (avg 5037 chars).
- Page declarations: all 40 sampled HN targets declare `og:type` article/website or
  nothing; Vimeo declares `video.other` + `twitter:card=player`; `i.redd.it` answers
  `Content-Type: image/png`; `v.redd.it` and Reddit galleries declare nothing.
- Card share at ≤ 500 chars: HN 97 %, Reddit image 68 %, video 25 %, gallery 44 %,
  Daring Fireball 25 %, Mastodon ≈ 100 %.

## Classification

An entry is a **share** when all three hold.

1. **It has a target.**
   - *Post* (derived title): the links its body shares — today's `SharedLinks`.
   - *Titled entry*: its `url` is off-site (its `HostKey` differs from the home's) **and**
     it has a home on the feed's site — its `discussion_url`, a URL GUID, or a `tag:` URI
     GUID whose authority's `HostKey` equals the feed `site_url`'s.
2. **Its own prose is ≤ 500 characters**: the body's text without link-only lines and
   `Label: number` metadata lines.
3. **Its kind is known or can be learned.**
   - `media` at once: the target is a media file (image by extension, or `MediaUrlKind`
     video/stream), the target resolves through `EmbedProviders`, a post carries its own
     media and shares no link, or the entry's own URL is a YouTube Short
     (`YouTubeShortUrl`) — the one own-URL case, kept as today.
   - otherwise `link`. The card pass may later turn it into `media` (below).

## Components

### Storage

- `App\Enum\EntryShare` (`link`, `media`); `entry.share` nullable, `entry.share_url`
  nullable (the target of a titled share — a post's targets stay in its body). One schema
  migration, verified by the CI migrate-from-empty leg.

### Ingest — new module `Service/Share`

- `EntryShareClassifier` (root service) applies the classification to the
  `ParsedEntryModel` after `PlatformEntryRules`, returning a `ShareModel` (kind, target URL
  or null).
- `Support/OwnProse` — one helper both the 500-char measure and the card text use: drops
  link-only lines and `Label: number` lines, returns the text.
- `IngestedEntryFactory` persists `share`/`share_url`; for a titled share the `summary`
  becomes the own prose, so the existing excerpt carries the card text.

### Enrichment — rename the post-only names

- `PostEnrichmentQueue` → `ShareEnrichmentQueue`: queues card enrichment for every `link`
  share (posts and titled alike). `PostEnrichment`/`PostEnrichmentKind` →
  `ShareEnrichment`/`ShareEnrichmentKind` (table rename in the same migration);
  `PostLinkCards` → `ShareLinkCards`. Bluesky's AppView kind is unchanged in behaviour.
- A titled share's targets are `[share_url]`; a post's stay `SharedLinks::of(body)`. One
  `ShareTargets` support answers both, used by the queue and the budget.
- `PageCardReader` returns the card **and** what the page declares: `Content-Type`
  image/video, `og:type video.*`, `twitter:card` player/photo, `og:video`, oEmbed type
  photo/video. A titled `link` share whose target declares media becomes `media`, its
  medium added to `entry.media` (the file itself, `og:video` with `og:image` as poster, or
  `og:image` for a photo page). `HtmlPageFetcher::fetchHead` exposes the response's
  content type for this.
- `EntryEmbedWriter` (Bluesky) sets `share` from what the embed brings: media → `media`,
  cards or an awaited card → `link`.

### API and frontend

- `EntryJson` adds `share: 'link' | 'media' | null`; `isShort` stays for the player and
  the Short pill.
- `magazine-slot-fit.ts`: `isLinkPost` = `share === 'link'` and (cards or pending);
  `isMediaPost` = `share === 'media'` and `postMedia` non-empty. `titleDerived`/`isShort`
  leave both.
- `entry-link` and `entry-media`: headline as today (`entryHeadline`); a titled share also
  shows its excerpt beneath (a derived post's excerpt already *is* its headline, so it is
  not repeated). The media caption follows the same rule.
- The list layout and the reader view are unchanged.

## Out of scope

- Reddit (RSS ends soon): no `[link]` targets, full-size images, `v.redd.it` video or
  galleries.
- Photo blogs whose entries link only to their own pages (no measurable signal; none
  subscribed in the dev DB).
- Production backfill.

## Testing

- Unit: `EntryShareClassifier` against real body fixtures from the dev DB — HN, Daring
  Fireball (short and long), ZDF→3sat, tagesschau→ndr, politico.eu, a Reddit thread (stays
  an article), a Bluesky post, a Mastodon post of 450 and 520 chars, a YouTube Short and a
  regular YouTube video. `OwnProse`, `PageCardReader` declarations (fixtures for
  each declaration), `ShareEnrichmentQueue`, the media flip in `ShareLinkCards`.
- Functional: ingest → queue → completion → `EntryJson.share` end to end.
- Frontend: Jest for slot-fit and both blocks (titled share shows title + text).
- Real run: the one-off backfill on the local Docker DB, then the magazine checked in the
  browser on HN, Daring Fireball, Mastodon and a Bluesky feed.
