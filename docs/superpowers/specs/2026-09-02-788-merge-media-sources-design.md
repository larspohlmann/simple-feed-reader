# #788 — One early embed hides additional embeds from later sources

Source: [issue #788](https://github.com/larspohlmann/simple-feed-reader/issues/788).
The issue names the cause and the acceptance criteria; this document fixes
the rule and records what was measured.

## Problem

vice.com 495401 carries four YouTube players, one per remix section. The
reader shows one, above the lead, and drops three.

Measured on the fetched page (every source through the real container):

| source (priority) | yields | anchor |
|---|---|---|
| JsonLdMediaSource (100) | embed ZSM3w1v-A_Y (the head's VideoObject) | none |
| MetaMediaSource (90) | embed ZSM3w1v-A_Y (`og:video`) | none |
| PageEmbedSource (80) | embeds ZSM3w1v-A_Y, Zx1_6F-nCaw, CzUDGiZH5q0, GDtn_FtU614 | each names the prose block before it |

`PageMediaScanner::claimUnownedKinds()` lets the first source to yield a
kind own that whole kind, so the JSON-LD candidate — one video, no anchor —
is all that survives, and it is top-placed. The page's players sit inside
`<noscript>` blocks, which readability drops, so the scanner is the only
route to them.

## The rule that replaces ownership

Sources still run in priority order, and the order still means precedence —
but precedence over a **URL**, not over a kind:

- The first source to name a URL sets the candidate: its poster, label,
  anchor, and its place in the list.
- A later source that names the same URL fills the gaps that candidate left
  (a missing poster, label, or prose anchor). It never overrides what was set.
- A URL no earlier source named joins the list — but only from a source that
  **re-confirms** the kind (names at least one URL an earlier source already
  set for that kind), or when no earlier source claimed the kind at all. A
  lower source that shares no URL of an already-claimed kind is describing a
  rendition of the same asset or an unrelated file, not a new player, and its
  unique URLs are dropped (the re-confirm guard, added after ARD, below).
- URLs are compared after normalisation (`MediaUrlKind`, `EmbedProviders`),
  which is already what every source emits.
- The cap of `ArticleMedia::MAX_ITEMS` (20) applies to the merged list; the
  page-furniture exclusions stay where they are, inside each source.

Nothing downstream changes: `PageMediaInserter` anchors each candidate by its
prose block, so a candidate that gains its anchor from the page scan lands
after its section instead of at the top, and the lead image is restored
because nothing is top-placed any more.

## Why the corpus permits the merge — and why the guard is needed

The ownership rule existed so "a later, weaker layer must not append a
scanned file beside a declared one". Across every fixture in
`tests/Fixtures/reader/media/` (deutschlandradio, npr, ard, heise,
soundcloud, unseen-publisher, sidebar-teaser), where two sources yield the
same kind they yield the **same normalised URL**, so the plain merge changes
no corpus result there.

That measurement was incomplete: it did not cover
`tests/Fixtures/reader/article-inline-video.html`, an ARD page that offers
**one** video at two renditions — a JSON-LD `VideoObject` (`…webxxl…mp4`) and
a `data-v` MediaPlayer (`…webs…mp4`). The plain merge keeps both unique URLs
and renders the same video twice; the ARD/ZDF `data-v` pattern is common, so
this is a real regression, not a hypothetical. The **re-confirm guard** above
resolves it: `AttributeMediaSource` re-confirms no URL `JsonLdMediaSource`
set, so its rendition URL is dropped and one player survives — while vice's
`PageEmbedSource` re-confirms the declared YouTube id and so is trusted to add
the other three. The guard overrules the old unit test's `declared.mp3`-beside-
`scanned.mp3` reading: two disjoint same-kind URLs from two sources are one
player (the declared one), because a lower source that shares nothing is
seeing a rendition, not a second asset. No measured page offers two genuinely
different files of one kind across sources; if one appears, the guard's worst
case is one player instead of two — the old behaviour, not a new fault.

## What to build

- `MediaCandidate::completedBy(MediaCandidate $later): self` — the same
  candidate with poster, label and preceding text filled from the later one
  where they were null.
- `PageMediaScanner::scan()` merges by URL as above; `claimUnownedKinds()`
  goes. Docblocks on the scanner and on `MediaCandidateSourceInterface` say
  precedence over a URL, not ownership of a kind.
- A reduced vice-shaped fixture (`tests/Fixtures/reader/media/multi-embed-page.html`):
  head JSON-LD declares video 1 only, `og:video` names it too, four sections
  each with a prose paragraph followed by a YouTube iframe inside
  `<noscript>`, a sidebar iframe inside `<aside>` that must stay excluded.
- Tests: scanner unit tests for the merge; the corpus test yields all four in
  page order, each with an anchor, the sidebar one absent; an end-to-end
  `ArticleExtractor` test proves each player lands under its own section,
  not above the lead.
- Frontend: reader cache `VERSION` 11 → 12; an already-read article would
  keep one player where the page has several.

## Acceptance

- The scanner returns four unique candidates for the fixture.
- Every existing scanner, source, corpus and inserter test stays green, with
  the one ownership test rewritten to the new rule.
- Entry 495401 renders four players, each under its remix section, after a
  reload of the article.
- `composer check`, `composer md`, `composer infection:diff`, both phpunit
  legs, and `npm run check` are green.
