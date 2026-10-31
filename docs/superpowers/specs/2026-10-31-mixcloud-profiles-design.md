# Mixcloud profiles: subscribe, play and seek

Refs #1932. Subscribes to a `www.mixcloud.com/<user>/` profile through Mixcloud's public cloudcasts API
and plays each show in the internal audio player through the Mixcloud widget, seekable like SoundCloud.

## Decisions

| Question | Decision |
|---|---|
| Where Mixcloud fits | A JSON feed format behind `FeedBodyParser`, like WordPress REST — not a social network, not a subscribe route |
| Subscribe-by-format branches | Merged into one `SourceFormat::VERBATIM` path (Mixcloud would be the third hard-coded arm) |
| Subscriber-exclusive shows | Skipped: never become entries |
| Show descriptions | None: the list endpoint carries none, and fetching each show's detail is not worth a request per show |
| Chapters | Out of scope |
| Out of scope (v1) | Chapters, descriptions, exclusive-show placeholders, Mixcloud login/Premium, stream URLs outside the widget |

## Facts this rests on

- **No RSS.** A profile page advertises only an oEmbed alternate and an Android app link.
- **The cloudcasts API is public and keyless.** `https://api.mixcloud.com/<user>/cloudcasts/` lists 20
  shows newest first, `content-type: text/javascript`, no ETag or Last-Modified. A list item carries `key`,
  `url`, `name`, `created_time`, `updated_time`, `audio_length`, `pictures`, `tags`, `user`,
  `is_exclusive` — no `description`. The top-level `name` is "John Digweed's Cloudcasts"; `user.name` is
  "John Digweed".
- **Chapters would never appear.** 0 of 67 recent shows across 10 large accounts had a `sections`
  tracklist with start times, or 3+ timestamps in the description (measured 2026-10-31).
- **Exclusive shows don't play.** The widget stays buffering with a null duration; 4 of Digweed's last 20
  uploads are exclusive, none of NTS's or Worldwide FM's.
- **The widget seeks.** Headless Chromium, three free shows: `seek` to 1800/300/5000/30 s all landed and
  played on. `seek` resolved `true`, but twice its answer took over 5 s while the seek had already landed.
  Playback emits `play → pause → play` while starting.
- **Our sandbox suffices.** With `sandbox="allow-scripts allow-same-origin allow-presentation"`,
  `referrerpolicy="strict-origin-when-cross-origin"` and only `allow="autoplay"`, a free show played and
  seeked. The widget's encrypted-media requests are blocked and harmless.
- **The widget protocol** (`widget.mixcloud.com/media/js/widgetApi.js`): JSON strings over `postMessage`,
  origin `https://player-widget.mixcloud.com`. Incoming `{mixcloud: "playerWidget", type, data}`; types
  `ready`, `api` (`{methods, events}`), `event` (`{eventName, args}`), `methodResponse`
  (`{methodId, value}`). Outgoing `{type: "getApi"}` and
  `{type: "method", data: {methodId, methodName, args}}`. Events used: `progress(position, duration)` in
  seconds, `play`, `pause`, `ended`, `error`, `buffering`.

## Backend

### Format — `App\Enum\SourceFormat`

`MIXCLOUD_API = 'mixcloud-api'`, and `VERBATIM = [SCRAPED, WP_JSON, MIXCLOUD_API]`: the formats a
discovery candidate subscribes and previews by, without re-running discovery.

### Parsing — `Service/Refresh/FeedBodyParser/MixcloudBodyParser`

A `FeedBodyParserInterface` with `format()` = `mixcloud-api`. It delegates to a parser in
`Service/Parser` (as `WpJsonBodyParser` delegates to `WordPressJsonParser`), which reads the body into a
`ParsedFeedModel`:

- feed title: the first show's `user.name`; feed link: `user.url`
- one entry per show with `is_exclusive` false: title `name`, link `url`, guid `key`, published
  `created_time` (`YYYY-MM-DDTHH:MM:SSZ`) normalised to UTC, image `pictures.extra_large`, categories the
  tag names, author `user.name`
- an item missing `key`, `url` or `name` is skipped; a body that is not a JSON object with a `data` list
  throws `FeedParseException`

### Player in each entry — `Service/Reader/Media/EmbedProvider/MixcloudEmbedProvider`

- `target()` takes `https://www.mixcloud.com/<user>/<show>/` (and the `player-widget` URL itself) and
  returns the canonical widget URL
  `https://player-widget.mixcloud.com/widget/iframe/?feed=<rawurlencoded key>`. The URL is rebuilt from
  the key, so no `autoplay` or other flag survives; label "Listen on Mixcloud".
- `frames()`: one anchored pattern for that canonical URL, `EmbedKind::Audio`, `EmbedShape::Strip`.
- `sourceHosts()`: `player-widget.mixcloud.com`.

`VideoPageEntryRule` already prepends the player link to an entry whose URL a provider resolves, so every
Mixcloud entry gets its player with no new ingest code. The same provider makes Mixcloud iframes in other
sites' articles playable, through `InBodyEmbedRewriter` and the generated frontend allow-list.

### Discovery — `Service/Discovery/FeedOffer/MixcloudProfileFeed`

A `FeedOfferInterface` that recognises a `www.mixcloud.com/<user>/` page URL (one path segment, not a
reserved top-level path such as `discover`, `upload`, `select`) and offers
`https://api.mixcloud.com/<user>/cloudcasts/` as a `mixcloud-api` candidate, titled from the page's
`<title>`. It decides from the URL; it does not fetch the API.

### Verbatim subscribe and preview, merged

- `SubscriptionService::subscribe`: one branch for `SourceFormat::VERBATIM`. The scrape gate
  (`assertMayScrape`) stays, for `scraped` only; the candidate title passes through for every verbatim
  format.
- `FeedPreviewService`: `scraped` keeps the extractor; every other format parses through the
  `FeedBodyParser` locator, keyed by format, instead of a hand-written arm per format.

### Tests

Parser tests on a recorded cloudcasts fixture (an exclusive show, a show missing optional fields, a
non-UTC-looking time); provider tests (show page, widget URL, foreign hosts, flags dropped); offer tests
(profile URL, show URL, reserved paths, other hosts); subscribe and preview tests for each verbatim format
through the merged path.

## Frontend

### Protocol — `reader/media/mixcloud-widget-protocol.ts`

The widget origin, builders for `getApi` and `method` messages (with a counter for `methodId`), a reader
that accepts only `{mixcloud: "playerWidget", type, data}` JSON from that origin, and `postToMixcloudFrame`.

### Playback — `reader/audio/playback/mixcloud-playback.ts`

`MixcloudPlayback extends FramePlayback`:

- ready: sends `getApi` on frame load and again on `ready`; the `api` message is the ready message
- `start` → `play`, `stop` → `pause`, `seekTo` → `seek`
- position and duration come from `progress` events, never from `seek`'s answer
- `play`, `ended`, `error` report playing, ended and failure; `pause` reports paused, except a `pause`
  between a `start` and the first `progress` after it (the start-up flicker)
- a start position is sought once the first `progress` after `start` arrives

### Wiring

- `PlaybackKind` gains `'mixcloud'`; `embedMediaOfUrl` recognises an allow-listed Mixcloud widget URL
  (no poster); `providerPlayback()` returns `MixcloudPlayback`; `pauseEmbedFrame` gains a `mixcloud` case;
  `withScriptablePlayer` gives the visible frame `allow: 'autoplay'`.
- The add-feed dialog's format handling reads one verbatim-format list and labels `mixcloud-api`
  "Mixcloud".
- `embed-frame-allowlist.generated.json` is regenerated with `app:embed:dump-frame-allowlist`.

### Tests

Jest: the protocol reader (wrong origin, wrong shape, non-JSON ignored); `MixcloudPlayback` against
scripted widget messages (ready, progress, the start-up flicker, the start seek, ended, error); the new
branches in `embedMediaOfUrl`, `providerPlayback`, `pauseEmbedFrame` and the add-feed dialog.

## Done means

- Gates green: `composer check`, `composer md`, both PHPUnit legs, `infection:diff`, `npm run check`,
  `ng build`.
- Live: subscribe to a Mixcloud profile on :4200, play a free show and seek it in Lars's Brave and on the
  iPhone. Headless Chromium cannot answer either.
