# #748 — Reader: recover the media the extraction drops

## Problem

The reader loses an article's media on ten measured articles. The media is not
gated: every sampled URL returned `200` with the correct content-type and no
cookie, token or auth header. The pipeline loses it for two different reasons,
so one fix does not cover both.

**Mechanism A — the embed is inside the article node.** Readability keeps the
publisher's `<iframe>`, `EntrySanitizer` drops it, and an **empty container is
left at the exact position the media occupied**. Position is therefore already
known; no re-fetch is needed.

**Mechanism B — the media never reaches `ReaderBodyCleaner`.** Either the player
sits outside the article node and readability never sees it (Deutschlandradio,
ARD), or it is inside and readability itself removes it (5 Magazine's SoundCloud
iframe). Either way no container is left, so position is not recoverable from the
body and the candidate must come from the source page. On the out-of-body pages
the media *is* the article: the extracted prose is near-empty.

Measured against the running reader's sanitized body:

| Entry | Source | Mechanism | Observed |
|---|---|---|---|
| 465658 | psytranceportal (OZORA) | A | **10 videos gone**; 10 `h3` over 10 empty `div`/`p` pairs, no links |
| 489867 | npr.org "WATCH LIVE" | A + B | video gone (1 empty `p`); companion audio absent |
| 487567 | heise.de | A or B — **confirm** | companion YouTube video absent; the inline player is a proprietary `<a-video>`, and the durable YouTube reference may sit elsewhere on the page |
| 481606 | rushkoff.substack.com | A | iframe gone; poster survives, and its URL carries the video id |
| 465683 | 5mag.net | **B** | readability drops the SoundCloud iframe *before* the sanitizer sees it, so no container is left |
| 488630 / 489854 / 491093 | Deutschlandradio | B | body **1040 chars**: duration, lead image, a promo, teaser links |
| 491483 | tagesschau.de | B | body **200 chars** — "Video / Stand: … / Zur Startseite" |
| 489312 / 490933 | ndr.de | B | same shape |

## Goals

1. Render the media both mechanisms lose, in place for A and at the top for B.
2. Keep host knowledge in small, tagged classes, so a new publisher is a class
   and never a new ticket.
3. Never bake a signed or expiring URL into the body. The IndexedDB article
   cache has no TTL.
4. Fail closed everywhere. No clean candidate means the body is untouched.
   Never destroy content to add media.

## Non-goals

- **No change to `EntrySanitizer`.** See the two measured findings below.
- No change to any endpoint, to `ExtractionResult`, or to the reader API shape.
- No fix for the junk in the near-empty Deutschlandradio body (a Google
  "Preferred Sources" promo and teaser links). That is extraction quality, the
  #627/#744 family, and it gets its own issue if it survives this work.
- No HLS. `master.m3u8` needs hls.js and is skipped.

## Two measured findings that overturn the ticket

**`EntrySanitizer` already passes `<audio>` and `<video>`.** Probed against the
real service:

```
audio    => '<p>x</p><audio controls preload="none" src="https://…a.mp3"></audio>'
video    => '<p>x</p><video controls preload="none" poster="…" src="…a.mp4"></video>'
source   => '<p>x</p><video controls><source src="…a.mp4" type="video/mp4" /></video>'
autoplay => '<p>x</p><audio controls src="…"></audio>'      ← autoplay dropped for free
iframe   => '<p>x</p>'                                       ← dropped
class    => '<p>x</p>'                                       ← dropped
```

Symfony's `allowSafeElements()` covers `audio`, `video` and `source` with
`controls`, `preload`, `poster`, `src` and `type`, and marks `autoplay` unsafe.
So R3's "sanitizer cost: add `audio`" does not exist. There is no sanitizer cost
for audio or for video.

**`EntrySanitizer` is shared with feed ingest** (`Service/Ingest/EntryIngestor.php:44`).
Allow-listing `iframe` there would let **every feed's own HTML** inject an
arbitrary-host iframe into the entry list. Symfony's sanitizer also cannot
host-restrict `src`, so a host allow-list could not live there either. This
rules out the approach #750 describes.

## Decisions

| # | Decision | Why |
|---|---|---|
| D1 | Discovery reads the **raw source page**, in its own scanner | `FetchedPageNormalizer::repair()` strips every `<script>` from the raw string *before* parsing (`FetchedPageNormalizer.php:119`, pattern at `:65`). ARD's MP4 renditions live in the embedded player JSON, so a normalizer-based scanner would silently find nothing for ARD while appearing to work for Deutschlandradio. |
| D2 | Embeds reach the DOM as a **link in the body, upgraded to an iframe at render** | No sanitizer change on either side; Angular's body sanitizer stays on; the §6 native checklist passes; revoking a provider takes effect on already-cached articles. |
| D3 | `href` is the provider's **embed URL**, uniform across providers | All six embed URLs are openable pages in their own right. One shape means the frontend check is "is this href on the allow-list" with the src used verbatim, so there is no second derivation to drift. |
| D4 | **No runtime probe** for durability | The "strip the query, request the bare path" test is a research rule. Encoding it per adapter avoids a network round trip and a timeout path on every reader extraction. |
| D5 | ARD video renders as **inline `<video>` with a mandatory poster** | The poster is what turns depublication into a still with a play button that errors, instead of a black frame in a cache that never expires. A video candidate with no poster is dropped. |
| D6 | All recovered media renders, in source order, **capped at 20** | OZORA's ten sets *are* the article; truncating them re-creates the bug. The cap is a runaway guard, not an editorial choice, so it sits clear of the largest measured case rather than exactly on it. |
| D7 | A media candidate **satisfies `MIN_CONTENT_LENGTH`** | tagesschau extracts to ~200 characters and sits on the gate. A page with durable media is an article even with little prose. |
| D8 | Six providers ship, but **only with a real captured page each** | Only YouTube and SoundCloud have measured samples. Vimeo, Bandcamp, Spotify and Mixcloud come from a speculative list; a provider without a fixture does not ship. |

## Architecture

Discovery reads the source page. Rendering mutates the extracted body. The two
never share a class.

```
HtmlPageFetcher ──▶ PageResponse{finalUrl, html}
                          │
        ┌─────────────────┴──────────────────┐
        │                                    │
  PageMediaScanner                   FetchedPageNormalizer
  (raw html, scripts intact)          (existing, untouched)
        │                                    │
   ArticleMedia                         readability
        │                                    │
        └──────────────▶ ReaderBodyCleaner ◀─┘
                              │
                        EntrySanitizer (unchanged)
                              │
                      Angular [innerHTML] (sanitizer stays ON)
                              │
                        upgradeMediaEmbeds()  ← anchor becomes an iframe
```

### Discovery — `PageMediaScanner`

Called by `ArticleExtractor` with `$page->html` and `$page->finalUrl`. Parses its
own document, so `<script>` payloads are reachable. Runs the tagged
`MediaCandidateSourceInterface` set and returns `ArticleMedia`.

```php
interface MediaCandidateSourceInterface
{
    /** @return list<MediaCandidate> */
    public function find(string $pageHtml, string $pageUrl): array;
}

final readonly class MediaCandidate
{
    public function __construct(
        public MediaKind $kind,      // Audio | Video | Embed
        public string $url,
        public ?string $posterUrl,
    ) {
    }
}
```

`ArticleMedia` holds the ordered, deduplicated list, capped at 20 per D6. Tag:
`app.media_candidate_source`, wired the way `app.feed_parser` is, with a wiring
test.

The extra parse costs a few milliseconds. `collapseWrapperChains()` already
accepts exactly that trade for exactly that reason
(`FetchedPageNormalizer.php:100-105`).

### Rendering — inside `ReaderBodyCleaner`

`ReaderBodyCleaner` runs **before** `EntrySanitizer`, so an in-body `<iframe>` is
still present with its `src`. New order:

```
1. InBodyEmbedRewriter     iframe → anchor          (mechanism A)
2. SubstackPosterLink      img    → wrapped anchor   (mechanism A)
3. navigationTrimmer / titleRemover / boilerplateTrimmer
4. leadImage->restore
5. PageMediaInserter       audio/video at position 0 (mechanism B)
```

Steps 1–2 run **first** for two reasons: a trimmer must not remove a block that
now holds recovered media, and `ReaderLeadImage` must see the poster as a real
body image rather than restoring a duplicate over it.

Rewriting the iframe before the sanitizer also **removes the ghost containers for
free**. OZORA's ten empty `div`/`p` pairs are empty only because the sanitizer
dropped the iframe inside them. Nothing is dropped once an anchor is there.

Step 5 runs last so the media lands above a restored lead image. It also reads
whether step 1 acted: if `InBodyEmbedRewriter` recovered an embed in place, step 5
skips every `Embed` candidate and inserts only `Audio` and `Video` ones. That is
the `PageEmbedSource` suppression guard described below.

`clean()` is at 3 parameters after #627's rework; `ArticleMedia` makes 4, which
stays under the phptramp and PHPMD line. A `ReaderBodyContext` is deliberately
*not* introduced — it earns its place only at a fifth parameter.

## Rules

### Providers — `EmbedProviderInterface`

```php
interface EmbedProviderInterface
{
    public function matches(string $url): bool;
    public function normalize(string $url): ?string;   // the canonical embed URL
    public function poster(string $url): ?string;
}
```

Every provider **drops the entire query string**. One rule kills `?si=`, `rel`,
`showinfo` and SoundCloud's `auto_play=true` together.

| Provider | Accepts | Emits | Poster | Sample |
|---|---|---|---|---|
| YouTube | `youtube.com/embed/{id}`, `youtube-nocookie.com/embed/{id}`, `youtu.be/{id}`, `watch?v={id}`; id `[A-Za-z0-9_-]{11}` | `https://www.youtube-nocookie.com/embed/{id}` | `https://i.ytimg.com/vi/{id}/hqdefault.jpg` | measured |
| SoundCloud | `w.soundcloud.com/player/?url=…api.soundcloud.com/tracks/{digits}` | `https://w.soundcloud.com/player/?url=https%3A%2F%2Fapi.soundcloud.com%2Ftracks%2F{id}` | none → text link | measured |
| Vimeo | `player.vimeo.com/video/{digits}` | same, query dropped | none → text link | **needed** |
| Bandcamp | `bandcamp.com/EmbeddedPlayer/…` | keep only `album`/`track`/`size`/`bgcol`/`linkcol`/`artwork` path segments | none → text link | **needed** |
| Spotify | `open.spotify.com/embed/(track\|album\|playlist\|episode\|show)/{22 base62}` | same | none → text link | **needed** |
| Mixcloud | `mixcloud.com/widget/iframe/?feed={encoded path}` | same, only `feed` kept | none → text link | **needed** |

Anything that does not match exactly is left alone, and the sanitizer drops it as
today. Tag: `app.embed_provider`, with a wiring test.

### Candidate sources

**`DeutschlandradioAudio`** — hosts `deutschlandfunk.de`,
`deutschlandfunkkultur.de`, `deutschlandfunknova.de`. Takes the **first**
`data-audio-src` pointing at `*.dradio.de` or `download.deutschlandfunk.de`.
Confirmed correct on all four sample pages by slug match. Rejects the live stream
`st0N.sslstream.dlf.de/…/stream.mp3`. Takes only the first, because the trailing
MP3s are related teasers, not this article.

**`NprAudio`** — `ondemand.npr.org/anon.npr-mp3/…`. Strips the entire query. The
params (`t=progseg`, `sc=siteplayer`, `aw_0_1st.playerid`) look like a signature
and are not: the bare path returns an identical `200 audio/mpeg`.

**`ArdVideo`** — `*.ard-mcdn.de` progressive MP4 from the embedded player JSON,
highest labelled rendition. Poster from the page's og:image. Per D5, no poster
means no candidate.

**`PageEmbedSource`** — host-agnostic, and the reason SoundCloud works. It scans
the raw page for any `<iframe>` whose src an `EmbedProviderInterface` accepts,
and emits `MediaKind::Embed` candidates. This covers every case where readability
removes the embed before `ReaderBodyCleaner` can see it (5 Magazine), which
`InBodyEmbedRewriter` structurally cannot reach.

It carries the one exclusion the whole-page scan needs. A page-wide scan will
also find sidebar and related-teaser embeds, which R4 bars. The guard:
**`PageEmbedSource` candidates are inserted only when `InBodyEmbedRewriter`
recovered nothing.** A body that already carries its own embed keeps only what
was positioned in it. This is fail-closed, it matches the evidence — 5 Magazine's
extracted body holds no embed at all — and it removes any chance of rendering an
embed twice.

### `DurableMediaUrl`

One shared guard, applied to every candidate after its adapter normalizes it:

- `https` only;
- **no query string left at all** — adapters strip; anything remaining is
  rejected as a possible signature;
- reject a `/tts/` path segment or a filename ending `…Neural.mp3`. This is
  Substack's machine narration of the article the user is already reading:
  public, unsigned, `200 audio/mpeg`, and wrong. It is the case that proves the
  bare-path test alone is not sufficient;
- reject a live or stream host.

## Rendering output

Mechanism A, in place:

```html
<figure><a href="{embed url}"><img src="{poster}" alt="…"></a></figure>
```

or, for a provider with no cheap poster, `<p><a href="{embed url}">Listen on
SoundCloud</a></p>`. Link text is hardcoded English, following the precedent
`SubstackGatedVideoPlaceholder` set.

`SubstackPosterLink` wraps an `img` whose src matches
`substackcdn.com/image/youtube/{transform}/{[A-Za-z0-9_-]{11}}` and which is not
already inside an `<a>`. The poster URL carries the video id, so this needs no
re-fetch and no new host trust. Nothing else is touched.

Mechanism B, at body position 0, in source order:

```html
<audio controls preload="none" src="…"></audio>
<video controls preload="none" poster="…" src="…"></video>
```

Both already pass both sanitizers, measured. `autoplay` is stripped for free.

An `Embed` candidate from `PageEmbedSource` renders as the **same anchor shape**
mechanism A produces, so one frontend rule upgrades both. The only difference is
position: a recovered-in-place embed keeps the publisher's position, a discovered
one goes to the top because its position is not knowable.

## Frontend

**`src/app/reader/media-embeds.ts`** — `upgradeMediaEmbeds(host: HTMLElement)`,
called in the existing post-render effect beside `markInsetCards`
(`reader-view.component.ts:346`). For each `a[href]` matching its own strict
allow-list, it replaces the anchor with an iframe built by `createElement` and
`setAttribute` — never `innerHTML`:

- `src` = the href verbatim
- `loading="lazy"`, `referrerpolicy="strict-origin-when-cross-origin"`
- `sandbox="allow-scripts allow-same-origin allow-presentation"`, `allowfullscreen`

`allow-scripts` with `allow-same-origin` is safe **here specifically** because the
framed document is cross-origin, so the frame gets its own origin and cannot
reach the reader. That reasoning belongs in the code as the *why*.

The pass is idempotent by construction: the anchor is gone after the first run,
and a re-render replaces the whole `innerHTML`.

The frontend allow-list is defence in depth, not duplication. The backend decides
*what the media is*; the frontend decides *what it will frame*.

**`reader-view.component.scss`** — a `.reader-embed` aspect-ratio box, full-width
`audio` and `video`. Tokens only; no hex, no raw px.

**`reader-cache.service.ts`** — bump `VERSION`, so cached bodies re-extract.
#627 already took 7 (commit `31ba6d0a`), so #748 takes **8**. Read the current
value before editing rather than assuming; this number moves whenever a reader
change invalidates stored extractions.

Known limitation: `playsinline` is in neither sanitizer's allow-list, so an inline
`<video>` goes fullscreen on iOS Safari. Accepted.

## Native iOS — architecture.md §6

All boxes pass. No endpoint changes, `ExtractionResult` untouched, and the JSON
body carries only `a`, `img`, `audio` and `video`. A native client receives a
working link where the SPA shows a player, and can map it to its own player.

The trade-off, stated plainly: an embed never appears in `contentHtml` itself, so
the digest email and a future native client see the link, not the player. That is
deliberate.

## Relationship to #627 and #750

**#627** was reworked while this spec was written, and the shape below is
**verified against the landed code** (`0b2148d6`, `612d78fe`), not against an
announcement. Its Task 5 seam moved pre-readability into
`FetchedPageNormalizer::repair()`, because `unwrapSingleChildDivs()` replaces a
wrapper `div` with its sole `div` child (`FetchedPageNormalizer.php:214-218`) and
so destroys the class a post-readability rule keys on. The rework:

- deleted `GatedMediaContext` and `GatedMediaPlaceholderInterface`;
- dropped the `app.gated_media_placeholder` tag;
- returned `clean()` to `clean(string $contentHtml, array $titleCandidates, LeadImageCandidate $leadImage): string` — **3 parameters, confirmed**, which is what leaves room for `ArticleMedia` as a 4th without a context object;
- rewrote `SubstackGatedVideoPlaceholder` as a plain normalizer cleaner with
  `replaceIn(HTMLDocument $page): void`, injected into `FetchedPageNormalizer`.

**#627 also created an alt-text contract, and #748 must stay off it.**
`reader-view.component.scss` (commit `145262d9`) paints the play badge with

```scss
.content ::ng-deep a:has(> img[alt='Video — open the original article to watch'])
```

Its own comment gives the reason: the sanitizer strips `class`, `style` and
`data-*`, so alt text is the only stable hook. That is the same constraint this
spec's frontend design works around, and it confirms it independently.

Two consequences. #748 poster anchors must use **different** alt text, or CSS
paints a badge on an element `upgradeMediaEmbeds()` is about to replace with an
iframe. And #748 keys on `href`, not on alt text, which is the stronger hook —
an embed URL is structured and validatable, a prose string is neither. Do not
extend the alt-text convention to the embed rules.

**One interaction to keep.** That cleaner now inserts its own poster as
`<a><img></a>` before the first paragraph of at least 80 characters, *pre*-readability,
so the anchor is present in the extracted body by the time `SubstackPosterLink`
runs. `SubstackPosterLink` must not touch it. The existing "not already inside an
`<a>`" guard covers this, and a test must pin it, because the two rules now write
similar markup into the same body from opposite ends of the pipeline.

**#748 is therefore purely additive.** It renames nothing, deletes nothing, and
widens no existing seam. The E1–E4 extension described in the issue is void.
Merge order: #627 first, #748 from `develop` afterwards.

**#750** is implemented in full here, by D2 rather than by the sanitizer change
that issue proposes. The spec and PR close both.

## Testing

- Per provider: match, normalize, poster, and a malformed-id rejection.
- Per candidate source: a captured fixture, including where the media URL sits
  relative to the script strip.
- `DurableMediaUrl`: the `/tts/` case, the live stream, a leftover query.
- `InBodyEmbedRewriter`: the OZORA fixture — ten embeds, positions preserved
  relative to their ten `h3` headings, and no empty containers left.
- `SubstackPosterLink`: valid id wraps; already inside an `<a>` untouched; an
  ordinary `substackcdn.com` image untouched; a short id untouched.
- `PageEmbedSource`: the 5 Magazine fixture recovers the SoundCloud track; the
  suppression guard holds, so a page whose body already yielded an in-place embed
  contributes no discovered ones and nothing renders twice.
- `ReaderBodyCleaner`: the new step order, and that a trimmer does not remove a
  block holding recovered media.
- `ArticleExtractor`: a candidate satisfies `MIN_CONTENT_LENGTH`.
- Wiring tests for `app.media_candidate_source` and `app.embed_provider`.
- Frontend Jest for `upgradeMediaEmbeds`: an allowed href upgrades, a
  non-allow-listed href is untouched, the pass is idempotent, and no `innerHTML`
  is used.
- Verification: re-run the #744 audit sweep, then check the ten measured entries
  in the running Docker stack — not only in fixtures.

## Risks

1. **Four of six providers have no sample article.** Vimeo, Bandcamp, Spotify and
   Mixcloud come from a speculative list. Capturing one real page each is a
   blocking step; a provider without a fixture does not ship. This is the largest
   risk in the work.
2. **Where each host's media lives** is confirmed for Deutschlandradio
   (attribute), ARD (JSON) and 5 Magazine (page iframe, removed by readability),
   but not for NPR, and **heise is contradicted by its own evidence**: the issue
   body files it as mechanism A, while an earlier comment reports the inline
   player is a proprietary `<a-video>` element with the durable YouTube reference
   elsewhere on the page. Resolve both against a captured page before writing
   their adapters. Whether each candidate survives the script strip decides only
   *how* the scanner reads it, not whether the scanner can — it works from the
   raw HTML either way.
3. `EdgeBoilerplateTrimmer` could still trim an edge block that now holds a
   recovered anchor. Needs a guard and a test.
4. A depublished ARD MP4 stays in the TTL-less cache. D5's mandatory poster caps
   the damage; the `VERSION` bump clears the store once but does not address
   future rot. Accepted.
5. `PageMediaScanner` parses the raw page a second time. Measured precedent says
   the cost is a few milliseconds against a readability pass of tens.
