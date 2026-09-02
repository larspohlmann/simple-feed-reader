# #782 — A VideoObject whose only playable form is an HLS playlist or a non-allow-listed player yields no player

Source: [issue #782](https://github.com/larspohlmann/simple-feed-reader/issues/782).
The issue names the four entries and the two gaps; this document records the
measurements that decide the two options it leaves open.

## Problem

Four video pages from the 2026-09-02 sweep render a teaser and a poster and
no player. Both pages carry a schema.org `VideoObject`; the candidates die in
`MediaUrlKind::resolve()`:

| host | `contentUrl` | `embedUrl` | what dies |
|---|---|---|---|
| zdfheute.de (491430, 489815) | `…/api/video/<slug>.m3u8` | `ngp.zdf.de/miniplayer/embed/?mediaID=…` (first-party) | `.m3u8` is not a file extension; the miniplayer is not a provider |
| aljazeera.com (469835, 476079) | none | `players.brightcove.net/665003303001/6tKQRAx7lu_default/index.html?videoId=…` | Brightcove is not a provider |

## Measured

- **ZDF's stream is playable cross-origin.** `…/api/video/<slug>.m3u8` answers
  301 to an Akamai `master.m3u8` (variant playlists, 480p–1080p, no query, no
  token) that answers with `Access-Control-Allow-Origin: <requesting origin>`.
  hls.js can fetch it from the reader's origin. Safari and AVPlayer play it
  natively.
- **Brightcove's player page is frameable.** `players.brightcove.net/…/index.html?videoId=…`
  answers 200 with no `X-Frame-Options` and no `Content-Security-Policy`.
  It is cross-origin to the reader, so the existing sandbox reasoning holds.
- **The subscriptions' other video publishers** (one recent video page each,
  fetched): tagesschau and ardmediathek offer mp4 files (already handled;
  ardmediathek offers an HLS master *beside* the mp4s); spektrum, Scientific
  American and Aeon embed YouTube (handled); nautil.us serves a plain `<video
  src=mp4>` (handled); zeit.de uses Brightcove through an in-page script
  player with no URL anywhere in the markup (not catchable by any URL rule);
  BBC's `VideoObject` carries no `contentUrl`/`embedUrl` at all; the Guardian,
  Politico and NYT pages carry no playable URL for an anonymous fetch. So the
  measured set of non-allow-listed third-party players reachable by URL is
  exactly one: Brightcove.
- **The ZDF `VideoObject` has a poster** (`thumbnailUrl` as a two-entry
  array; `JsonLdMediaSource::thumbnailIn()` already reads the first). The Al
  Jazeera one has a string `thumbnailUrl`, and the reader body already shows
  that very image — the poster reconcile can put the player in its place.

## Decisions

### 1. Brightcove becomes an embed provider

`EmbedProviderInterface` is the "what may I frame" allow-list on the backend,
and `media-embeds.ts` `ALLOWED` is its twin on the frontend (defence in depth,
#748). Brightcove joins both:

- Accepted: `https://players.brightcove.net/<account digits>/<player id>/index.html?videoId=<digits>`,
  any other query parameters present. The player id is kept verbatim
  (`6tKQRAx7lu_default` is an embed id, not a suffix to guess).
- Normalised: the same URL with the query reduced to exactly `videoId=<digits>`.
  The interface docblock changes from "implementations drop the entire query"
  to "keep only what identifies the media": Brightcove's video id lives in the
  query and nowhere else.
- No cheap poster from the provider (`poster()` → null), label "Watch the video".
- `JsonLdMediaSource` hands an `Embed` candidate the declared `thumbnailUrl`
  when the provider offers no poster of its own, so `PageMediaInserter`
  reconciles the body's copy of that image into the player (in place, not
  stacked). YouTube keeps its own ytimg poster; nothing changes there.
- Frontend `ALLOWED` gains
  `/^https:\/\/players\.brightcove\.net\/\d+\/[A-Za-z0-9_-]+\/index\.html\?videoId=\d+$/`.
  The sandbox stays as is: the frame is cross-origin.

A first-party player page (`ngp.zdf.de/miniplayer`) is not framed; ZDF is
served by decision 2.

### 2. HLS becomes a distinct kind, played by an explicit frontend capability

- `MediaKind::Stream` (`'stream'`), resolved by `MediaUrlKind` from a
  `STREAM_EXTENSIONS = ['m3u8']` list — never through `VIDEO_EXTENSIONS`, which
  keeps rejecting a playlist as a file. `DurableMediaUrl` applies unchanged
  (https, no query): ZDF's playlist URL passes; a tokenised one would not.
- `MediaKind::isVideo()` is true for `Video` and `Stream`: both play in a
  `<video>` element and both need a poster against the TTL-less cache (the D5
  rule). Every source that builds a `Video` candidate builds a `Stream` one the
  same way; `LinkedFileMediaSource` drops both (no poster). `PageMediaInserter`
  emits a `Stream` as the same `<video controls preload="none" poster src>` it
  emits for a file — the wire shape AVPlayer plays natively, so the native iOS
  client needs nothing new.
- **Streams yield to files.** A page that offers a `Video` candidate and a
  `Stream` candidate (ardmediathek: progressive mp4 beside the HLS master)
  keeps only the files: `ArticleMedia::withoutRedundantStreams()`, applied by
  `PageMediaScanner` after the merge. A file plays everywhere without a
  library; the stream is the fallback for a page that offers nothing else.
- Frontend: `attachHlsStreams(host)` in the reader's post-render pass. For
  each `<video>` whose `src` ends in `.m3u8`: if the browser plays HLS natively
  (`canPlayType('application/vnd.apple.mpegurl')`), do nothing; otherwise
  `import('hls.js')` (dynamic — a lazy chunk, outside the initial-bundle
  budget), `new Hls({ autoStartLoad: false })`, `loadSource`, `attachMedia`,
  and `startLoad()` on the first `play` so `preload="none"` keeps its meaning.
  Instances are destroyed when their `<video>` is no longer connected (the
  body is re-rendered whole). If hls.js reports no MSE support, the video is
  left as it is: the poster shows and the control fails, the same as today's
  fallback for any unplayable file.
- Dependency: `hls.js` ^1.7 (Apache-2.0).

## Non-goals

- A first-party player page as an embed (ZDF miniplayer): per-host, not worth
  framing.
- zeit.de's script-only Brightcove player, BBC's URL-less `VideoObject`, and
  publishers that serve no playable URL to an anonymous fetch.
- DASH (`.mpd`): none measured.

## Acceptance

- Fixtures: a `VideoObject` with an HLS-only `contentUrl` (ZDF shape) yields
  one `Stream` candidate with the declared thumbnail as poster; a `VideoObject`
  with only a Brightcove `embedUrl` (Al Jazeera shape) yields one `Embed`
  candidate carrying the declared thumbnail; a page nobody designed for
  (`<video poster><source src="…/master.m3u8">` and a Brightcove `<iframe>`
  with an autoplay query) yields a `Stream` and a normalised `Embed`; a page
  offering both an mp4 and an HLS master yields the file only.
- `MediaUrlKindTest`: `.m3u8` resolves to `Stream`, never to `Video`.
- End to end through `ArticleExtractor`: the Al Jazeera shape's body holds
  the Brightcove link where the thumbnail image stood, and the ZDF shape's
  body holds a `<video>` with the `.m3u8` source and a poster.
- Frontend: a Brightcove link upgrades to a sandboxed iframe; an `.m3u8`
  `<video>` gets an hls.js instance in a browser without native HLS and is
  left alone in one with it; the instance is destroyed on re-render.
- The four entries render a player after a reload of the article; an
  ardmediathek entry still renders exactly one player.
- `composer check`, `composer md`, `composer infection:diff`, both phpunit
  legs, `npm run check`, and `npm run build` (initial bundle unchanged;
  hls.js in a lazy chunk) are green.
