# #800 — Media the page names only by a sibling id

Source: [issue #800](https://github.com/larspohlmann/simple-feed-reader/issues/800).

## Problem

zdfheute article 1374175 holds four videos; the reader shows one. Only one is
declared with a URL (a JSON-LD `VideoObject`, `contentUrl`
`https://www.zdfheute.de/api/video/taktik-…-video-100.m3u8`). The other three
exist only as player configs in the Next.js server-component payload — a
content id and a start image, no URL anywhere:

```
"config":{"isPriority":…,"content":"reaktion-deutschland-russland-drohnen-anschlag-video-100","startImage":{…,"layouts":{"1140x120":"https://www.zdfheute.de/assets/…~1140x120?cb=…",…}}}
```

The publisher's script builds the URL in the browser. Every candidate source
works from a URL, so nothing catches them; their stills survive into the body
as figures.

## Measured

- The naive derivation ("every other value under the same key") yields 28–34
  ids per ZDF page: the `sophoraId` key also names the navigation. Adding the
  context (previous key, key, next key) still leaves the navigation on video
  pages, because the seed's own teaser occurrence shares the navigation's
  shape.
- Two generic filters remove every false sibling in the corpus: **a context
  with more than five siblings is a list, not the article's media**, and **a
  sibling needs a poster within reach** (D5 demands one anyway; a navigation
  entry has none).
- With both, over 75 files (every `backend/tests/Fixtures/**/*.html` plus the
  #782 survey and live pages) the rule fires on exactly this page and yields
  exactly the three missing videos, each with a poster that `ImageIdentity`
  matches to a body figure. The three derived playlist URLs answer 301 to
  valid Akamai masters.
- tagesschau's `data-v` lists yesterday's clip beside today's under the same
  key: excluded, because the page names that id inside a URL — the URL-based
  sources already saw it and chose.

## Decision

A post-scan step, **derive then verify**, host-agnostic: no host, no key
name, no URL template in `src/`. The page supplies all three.

### Derive (pure text, `SiblingIdRule`)

1. **Seed**: a found file or stream candidate (never an embed) whose URL's last
   path segment stem is an id, `^[A-Za-z0-9_-]{6,}$`.
2. **Keyed occurrence**: the seed id standing as a keyed value in the raw page
   — `"key":"id"` in a script payload (escaped quotes allowed) or `key="id"`
   on an element. Its context is the previous key, the key, the next key.
   An occurrence inside a URL path has no key and is ignored.
3. **Siblings**: other values under the same key whose own occurrence shares
   the context, same character class, and the seed's trailing `-\d+` suffix if
   it has one. Excluded: an id the page names inside any URL. A context with
   more than five siblings is skipped whole.
4. **Poster**: the largest-by-`WxH` https image URL (image extension or a
   `\d+x\d+` token; never a playlist, media file, script or stylesheet) within
   2000 characters after the sibling's occurrence. No poster, no candidate.
5. **Candidate**: the seed's URL with the id swapped, the seed's kind, that
   poster, no prose anchor — the poster reconciles the player into the figure
   that already shows the still.

### Verify (network, `SiblingMediaExtender`)

A derived URL is a guess until the network confirms it: it is followed through
`RedirectFollower` (the landing logic `StreamLocationResolver` already has,
extracted into `MediaLanding` so both use one copy) and kept only when the
chain lands 2xx on a URL `MediaUrlKind` classes as the seed's kind, emitted at
the landing — the reason #797 emits streams at their landing. Applied by
`ArticleExtractor` after the stream location step, where the media is
consumed, so a page that fails extraction never pays for it. `ArticleMedia`
keeps its cap.

Cost: requests only on a page with a keyed seed id and at most five
posterised siblings — one page in the corpus, three requests.

Reader cache `VERSION` +1.

## Non-goals

- A publisher-specific URL template (rejected in favour of this rule).
- Pages whose extra videos carry neither a keyed id nor a poster.
- Choosing among several seeds' templates when they disagree: each seed
  derives its own siblings; the by-URL merge dedupes.

## Acceptance

- `KeyedOccurrencesTest`: the escaped-JSON form and the attribute form yield
  key, previous key, next key; a path occurrence yields nothing.
- `NearbyPosterTest`: largest rendition wins; an extension-only image counts;
  playlists, files, scripts are never posters; null beyond the window.
- `SiblingIdRuleTest`: the ZDF shape yields three candidates (seed kind,
  swapped URL, poster, no anchor); a six-sibling context yields none; a
  sibling named in a URL is skipped; a poster-less sibling is skipped; an
  embed seed and a hash-stemmed seed derive nothing; a sibling whose context
  differs (another previous key) is skipped.
- `MediaLandingTest`: 2xx landing URL; null on 4xx, on a failed chain.
- `StreamLocationResolverTest`: unchanged behaviour on the new constructor.
- `SiblingMediaExtenderTest`: a derived stream kept at its 2xx landing; dropped
  on 404; dropped when the landing is a file, not a stream; the cap holds.
- Fixture `zdf-sibling-video-configs.html`: `HostAgnosticDiscoveryTest` still
  yields one candidate from the scan (derivation is not a source);
  `ArticleExtractorTest` with mocked 301/200 pairs: four `<video>` at four
  Akamai landings, each in place of its figure, no navigation id derived.
- Corpus: every other discovery expectation unchanged.
- Live: entry 1374175 (production) / its local counterpart shows four players
  after a reload.
- `composer check`, `composer md`, `composer infection:diff`, both phpunit
  legs, `npm run check` green.
