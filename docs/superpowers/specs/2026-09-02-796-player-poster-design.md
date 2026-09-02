# #796 — A broadcast page without `og:image` loses its video

Source: [issue #796](https://github.com/larspohlmann/simple-feed-reader/issues/796).

## Problem

tagesschau's broadcast pages (`tagesschau_in_einfacher_sprache/tse-*.html`,
`tagesschau/ts-*.html`) render the audio player and no video. The page holds
both in one `data-v` attribute — the ARD player JSON `AttributeMediaSource`
already reads — but the page carries no `og:image`, and `og:image`
(`ScannedPage::posterUrl`) is the only poster that layer knows. A video with no
poster is dropped by design (D5: a poster-less video rots into a dead frame in
a cache with no TTL); audio needs none, so it survives alone.

## Measured

- Twelve most recent tagesschau entries: every article and `/video/…` page has
  an `og:image`; the two broadcast pages have none (also no `twitter:image`,
  no JSON-LD `image`). Page type, not a regression.
- The player's own still is on the page, one level above the element that
  holds the URL:

  ```
  div.mediaplayer__wrapper
  ├── div.ts-picture__poster-wrapper > picture > img.ts-image  (src=https://images.tagesschau.de/…/sendungsbild-1789662.jpg?…)
  └── div.v-instance[data-v="{…mp3, mp4 renditions, HLS master…}"]
  ```

- A prototype of the rule below, substituted for `AttributeMediaSource` in the
  real scanner and run over 71 files (every `backend/tests/Fixtures/**/*.html`
  plus the #782 survey and live pages), changes exactly one page: this one,
  from `[audio]` to `[video (poster = the sendungsbild still), audio]`. Through
  the full extractor the body carries `<video … poster="…sendungsbild…">` and
  `<audio …>`.

## Decision

**A video whose page has no `og:image` takes the poster from the player's own
markup.** New `PlayerPoster::near(Element $holder): ?string` in
`Service/Reader/Media`: the first `<img src="https://…">` inside the element
that holds the URL, else inside its parent, its grandparent, its
great-grandparent — three levels, never `body` (a shallow holder must not
inherit the page's first image, typically the logo). `AttributeMediaSource`
uses it as the fallback: `og:image` first, so every page that has one is
unchanged; then the nearby still. D5 stays: a video with neither is dropped.

The rule is host-agnostic: it names no class, no host, only "the picture a
player keeps beside itself". `SemanticMediaSource` keeps reading `<video
poster>`; `LinkedFileMediaSource` keeps dropping poster-less videos (a bare link
has no player around it).

Reader cache `VERSION` +1: an already-read broadcast entry holds the audio only.

## Non-goals

- **Rendition choice.** `MediaRelevance` ranks by slug tokens and keeps
  document order on ties, so this page yields the 480p `webs` rendition while
  `ard-video.html` happens to list `webxxl` first. Choosing the largest
  rendition is its own change (a `maxHResolutionPx`/name-token preference),
  not a poster question; noted for a separate issue.
- Reading the poster out of the `data-v` JSON (`meta.images[].url` is a
  `{size}`/`{width}` template): the `<img>` is the rendered form of the same
  still.

## Acceptance

- `PlayerPosterTest`: image inside the holder; in the parent; in the third
  ancestor; not in the fourth; never from `body`; a non-https `src` skipped;
  null when none.
- `AttributeMediaSourceTest`: a page with no `og:image` and no nearby image
  still drops the video (existing test, reworded); a page with no `og:image`
  takes the still beside the player; a page with `og:image` prefers it over the
  nearby still.
- Fixture `ard-broadcast-no-og-image.html` (the shape above, reduced):
  `HostAgnosticDiscoveryTest` yields a video with the sendungsbild poster and
  an audio; `ArticleExtractorTest` body holds both players, the video with
  that poster.
- Every other discovery expectation unchanged.
- Live: entry 496523 shows the video with its still above the audio after a
  reload.
- `composer check`, `composer md`, `composer infection:diff`, both phpunit
  legs, `npm run check` green.
