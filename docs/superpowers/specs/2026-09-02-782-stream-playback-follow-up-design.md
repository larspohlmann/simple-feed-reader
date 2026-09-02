# #782 follow-up — the shipped players do not play

Source: the live check of [PR #793](https://github.com/larspohlmann/simple-feed-reader/pull/793)
on 2026-09-02. Three defects, each traced to its boundary in Lars's Chrome and
in the backend; one further gap recorded as a non-goal.

## Measured

| entry | page | symptom | boundary |
|---|---|---|---|
| 491430, 489815, 496263 (zdfheute.de) | `contentUrl` = `https://www.zdfheute.de/api/video/<slug>.m3u8` | player shows, loading forever | (a) Chrome answers `"maybe"` to `canPlayType('application/vnd.apple.mpegurl')` and then never plays the playlist (`readyState` stays 0); `attachHlsStreams` took that answer as native support and skipped hls.js. (b) The playlist URL answers **301 without `Access-Control-Allow-Origin`** to an Akamai `master.m3u8`; a cross-origin `fetch()` of it fails in Chrome (`Failed to fetch`, `redirect: 'manual'` too), while the Akamai master, its variant playlists and its segments all answer 200 with CORS. |
| 495829, 493987 (aljazeera.com) | one `VideoObject` with `contentUrl` (mp4 on Akamai) **and** `embedUrl` (Brightcove) | two players | `JsonLdMediaSource::collect()` emits every URL key of a node; both survive the scanner's merge because they are different URLs of different kinds. One node describes one asset. |
| 469835, 476079, 495936 (aljazeera.com) | `embedUrl` only | "error" | Not reproducible with PIA disconnected: the Brightcove player plays in Chrome and in the WebKit pane, and Brightcove's playback API answers a `localhost` origin with `Access-Control-Allow-Origin: *`. VPN egress, no code defect. |
| 493958 (theguardian.com) | `<div data-component="youtube-atom" data-video-id="pz8VRrI0p0U">` | no video | No URL anywhere in the markup; the id sits in a data attribute. A second publisher (WDR, `<div id="yt-…" data-video-id="…">`) carries the same shape. Non-goal here — see below. |

The in-app Browser pane is WebKit: it plays HLS natively and never fetches the
playlist by script, which is why the #782 acceptance looked green there.

## Decisions

### 1. hls.js takes every stream where Media Source Extensions exist

`canPlayType` is not a usable signal: Chrome claims HLS and delivers nothing.
`attachHlsStreams` attaches hls.js to every `.m3u8` video whenever
`Hls.isSupported()`; only a browser without MSE (iOS Safari) is left to play
the playlist natively. This is the order hls.js's own documentation
prescribes. Cost: desktop Safari loads the lazy chunk it did not need.

### 2. A stream is emitted at the URL that finally serves it

The playlist is fetched by script, so a redirect hop without a CORS header
kills it, and the browser cannot see past that hop. The backend can: a new
`StreamLocationResolver` follows every `Stream` candidate to where it lands
(GET, body cancelled, every hop through the SSRF guard) and emits the landing
URL when `MediaUrlKind` still resolves it to a durable `Stream`. A chain that
fails, lands on a non-2xx status, or lands on a tokenised or otherwise
non-durable URL keeps the declared URL: the native client follows redirects on
its own, and nothing is lost against today.

The redirect loop already lives in `HtmlPageFetcher` (a copy of the rules
`ResponseClassifier` documents as an SSRF control). Rather than a third copy it
is extracted to `Service/Fetch/RedirectFollower` — per-hop `UrlGuard`,
`max_redirects` forced to 0, `Location` resolved through `UrlResolver` — and
both the page fetch and the stream resolver use it. `HtmlPageFetcher` keeps
its byte cap, status check and `PageFetchException` contract unchanged.

Applied in `ArticleExtractor` right after `PageMediaScanner::scan()`, so the
scanner and its sources stay pure. One extra round trip per stream candidate,
on the rare page that carries one.

### 3. One schema.org node, one candidate

`JsonLdMediaSource` yields **one** candidate per node: the first of
`contentUrl`, `embedUrl` that resolves to a playable candidate. A file beats
the player page of the same asset; the player page remains the fallback for a
node whose file is refused (no poster, a player page under `contentUrl`, a
tokenised stream). Nothing changes for nodes that declare a single key, which
is every existing fixture.

### 4. Reader cache version 14 → 15

A v14 record holds a ZDF stream at its declared URL and an Al Jazeera page with
two players.

## Non-goals

- **A YouTube id declared in a `data-video-id` attribute** (Guardian
  `youtube-atom`, WDR `yt-…`). Two publishers share the shape, so a generic
  rule exists — an element whose own attributes name YouTube and carry an
  11-character id — but it is a new candidate source, not a repair of #782.
  Its own issue.
- The stream guard's `$bare !== $url` over-refusal of a port or fragment
  (already noted at #793's merge).
- The ZDF page's duplicated title, byline and stray `|` above the player:
  reader chrome, unrelated to media.

## Acceptance

- `RedirectFollowerTest`: follows a relative `Location` to the landing URL and
  status; refuses a hop the guard blocks (one request made, none to the
  blocked host); more than the allowed hops throws; a redirect without
  `Location` throws; `max_redirects` reaches the client as 0 whatever the
  caller passed.
- `HtmlPageFetcherTest` unchanged in behaviour (new constructor).
- `StreamLocationResolverTest`: a stream is re-emitted at its 2xx landing;
  the declared URL is kept on a 4xx landing, a tokenised landing, a transport
  failure; a file, an embed and an audio candidate make no request.
- `JsonLdMediaSourceTest`: a node with `contentUrl` (mp4 + thumbnail) and
  `embedUrl` (provider) yields one `Video`; a node whose `contentUrl` is
  refused (no thumbnail) and whose `embedUrl` is a provider yields one
  `Embed`.
- `ArticleExtractorTest`: the ZDF shape's body holds the Akamai master as the
  `<video>` src after a mocked 301; the Al Jazeera shape with both keys holds
  one `<video>` and no Brightcove link.
- `hls-streams.spec.ts`: hls.js attaches even when `canPlayType` answers
  `'maybe'`; a browser where `Hls.isSupported()` is false is left alone.
- Live, in Chrome with PIA off: 491430, 489815, 496263 play; 495829 and
  493987 show one player; 469835 still plays; an ardmediathek entry still
  shows exactly one player.
- `composer check`, `composer md`, `composer infection:diff`, both phpunit
  legs, `npm run check`, `npm run build` (initial bundle unchanged) green.
