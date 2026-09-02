# #795 — A YouTube id declared only in `data-video-id` yields no player

Source: [issue #795](https://github.com/larspohlmann/simple-feed-reader/issues/795).

## Problem

Guardian video pages (493958, and the survey page b6 from #782) render the
standfirst and no player. The page carries no playable URL anywhere — no
`VideoObject`, no `contentUrl`/`embedUrl`, no `<iframe>`, no `<video>`,
`og:video:url` points at the page itself. The id sits in data attributes:

```html
<gu-island name="YoutubeBlockComponent" props="{…&quot;assetId&quot;:&quot;pz8VRrI0p0U&quot;…}">
  <div data-component="youtube-atom" data-atom-id="…" data-video-id="pz8VRrI0p0U" data-video-unique-id="pz8VRrI0p0U-0">
```

Every candidate source works from a URL (`MediaUrlKind::resolve()`,
`EmbedProviders::resolve()`), so nothing catches it.

## Measured

- The shape is a publisher pattern, not a one-off: zeit.de spells it
  `<div id="yt-JSrAQkrp1JI0" data-video-id="JSrAQkrp1JI">`. On zeit.de it sits
  inside a `<script type="text/template">`, which the HTML parser keeps as
  text, so a DOM scan cannot reach it there; the Guardian's is real DOM.
- `data-video-id` alone is ambiguous: Brightcove's in-page embed
  (`<video-js data-account data-player data-video-id>`) and Vimeo players use
  the same attribute with numeric ids. The YouTube id shape (11 characters of
  `[A-Za-z0-9_-]`) plus the element naming YouTube itself is what separates them.
- A prototype of the rule below, run over every fixture under
  `backend/tests/Fixtures/**` plus the 16 survey pages and the live pages of
  #782 (70 files), yields a candidate on exactly the two Guardian pages and
  nothing else.
- Through the real pipeline with the prototype added to the scanner, 493958's
  body opens with the YouTube link carrying the ytimg poster, followed by the
  standfirst; one `<img>` in the body, no stacked lead figure (the top-placed
  player suppresses `ReaderLeadImage::restore()`, the ARD path).

## Decision

A new candidate source, `YouTubeIdAttributeSource`, host-agnostic:

- Every element with a `data-video-id` attribute, outside `PageFurniture`.
- The value matches `^[A-Za-z0-9_-]{11}$`.
- The element names YouTube in its own tag name or any attribute name/value:
  `youtube` anywhere, or a `yt-`/`yt_` token (`data-component="youtube-atom"`,
  `id="yt-…"`, `class="embed--youtube"`).
- The id becomes `https://www.youtube.com/watch?v=<id>` and goes through
  `EmbedProviders::resolve()`, so the existing provider normalises the URL and
  supplies the poster — the same candidate a JSON-LD or iframe declaration
  would produce. Anchor: `PageTextBlocks::before($element)`.
- Priority 55: below `AttributeMediaSource` (60), above `LinkedFileMediaSource`
  (50). It is the weakest signal, so under the #788 re-confirm guard a page
  whose iframe or JSON-LD already claims an embed lets a `data-video-id`
  teaser in only if it re-confirms one.

No frontend change: YouTube is already on the embed allow-list. Reader cache
`VERSION` +1 (an already-read Guardian video article keeps no player).

## Non-goals

- The zeit.de occurrence inside a `<script type="text/template">`: reaching it
  means parsing template scripts as markup, a different change with its own
  blast radius.
- Other providers' attribute embeds (Brightcove `<video-js>`, Vimeo): none
  measured on a subscribed page; the source is named for what it matches.
- The `assetId` in the `gu-island` props JSON: the atom `<div>` beneath it
  carries the same id in a plainer form.

## Acceptance

- `YouTubeIdAttributeSourceTest`: the Guardian shape yields one YouTube embed
  with the ytimg poster; the zeit.de shape (`id="yt-…"`) in real DOM yields the
  same; a numeric `data-video-id` on a `<video-js data-account>` yields
  nothing; an 11-character id on an element that does not name YouTube yields
  nothing; an occurrence inside `<aside>` yields nothing; the id repeated on
  two elements yields one candidate; the prose block before the element is
  the anchor.
- `HostAgnosticDiscoveryTest`: the Guardian fixture, through the real
  container, yields exactly one candidate, the YouTube embed.
- `ArticleExtractorTest`: the Guardian fixture's body opens with the YouTube
  link (poster `i.ytimg.com/vi/<id>/hqdefault.jpg`), holds one `<img>`, and
  the standfirst follows.
- Corpus: every other fixture's candidates unchanged (the discovery test set
  stays green).
- Live: 493958 shows the player after a reload.
- `composer check`, `composer md`, `composer infection:diff`, both phpunit
  legs, `npm run check` green.
