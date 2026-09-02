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
- A URL no earlier source named joins the list, after everything named so far.
- URLs are compared after normalisation (`MediaUrlKind`, `EmbedProviders`),
  which is already what every source emits.
- The cap of `ArticleMedia::MAX_ITEMS` (20) applies to the merged list; the
  page-furniture exclusions stay where they are, inside each source.

Nothing downstream changes: `PageMediaInserter` anchors each candidate by its
prose block, so a candidate that gains its anchor from the page scan lands
after its section instead of at the top, and the lead image is restored
because nothing is top-placed any more.

## Why the corpus permits this

The ownership rule existed so "a later, weaker layer must not append a
scanned file beside a declared one". Measured across every fixture in
`tests/Fixtures/reader/media/` (deutschlandradio, npr, ard, heise,
soundcloud, unseen-publisher, sidebar-teaser): in every case where two
sources yield the same kind, they yield the **same normalised URL**. No
fixture shows the same asset under two URLs. The merge therefore changes no
corpus result, and the one hypothetical the old unit test encoded
(`declared.mp3` beside `scanned.mp3`) becomes the behaviour the issue asks
for: both are unique, both are offered, the declared one first.

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
