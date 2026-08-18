# #476 — Reader extracts the wrong block on some pages (dual-extract, keep richer)

## Problem

Some articles extract publisher chrome instead of the body. The canonical case is
the ankerherz Shopify blog: the reader renders only the promo banner ("DU MAGST
DEN ANKERHERZ BLOG …") while the real 3.9k-character article ("Hand aufs Herz …")
in `.article__content`/`.rte` is dropped.

## Root cause (measured, current tree)

The issue's original hypothesis — the normalizer's libxml `loadHTML`→`saveHTML`
round-trip degrades extraction — is **stale**. Since the `fivefilters/readability.php`
upgrade to v4.0.0 the round-trip is neutral. The real flipper is the
`unwrapSingleChildDivs` step in `FetchedPageNormalizer`.

Measured on the live ankerherz page (readability text length, first words):

| Stage | Result |
|---|---|
| Raw HTML → readability (no normalizer) | 3859 — correct ("Hand aufs Herz …") |
| + old libxml round-trip only | 3859 — correct |
| + modern `\Dom\HTMLDocument` round-trip only | 3859 — correct |
| + lazy-image / screen-reader / glyph repairs | 3859 — correct |
| **+ `unwrapSingleChildDivs`** | **1084 — wrong** ("DU MAGST DEN ANKERHERZ BLOG …") |

Collapsing the single-child `<div>` wrapper chains redistributes readability's
score propagation and hands the win to the promo block. It is structural, not a
class-weight loss: merging the collapsed wrappers' `class`/`id` onto the survivor
still fails (1084); only *not collapsing* the classed wrappers restores 3859.

`unwrapSingleChildDivs` exists for #235: BBC-style pages wrap every paragraph,
subheading and image in a deep chain of single-child `<div>`s, which dilutes
readability's scoring until the sibling-join phase drops subheadings and figures.
Collapsing the chains is what restores the full body. So the step both **helps**
(#235) and **hurts** (#476), and a class- or depth-based discriminator is fragile:
both cases are classed single-child div chains; a minimum-depth threshold only
separated the two fixtures at depth ≥ 3, tuned on two samples.

## The insight

Across the reader fixtures, unwrap only ever moves the result in one of two ways:

| fixture | raw (no unwrap) | with unwrap |
|---|---|---|
| ankerherz | **3859 — correct** | 1084 — wrong |
| BBC (`article-block-components.html`) | 568 — incomplete (#235 bug) | **1384 — correct** |
| `article.html` | 873 | 873 (identical) |
| `article-lazy-images.html` | 873 | 873 (identical) |
| `article-lead-image.html` | 692 | 692 (identical) |

Unwrap **helps** by making a poorly-extracted page *richer* (BBC 568 → 1384) and
**hurts** by making a well-extracted page *shorter* (ankerherz 3859 → 1084). So the
fix is to stop guessing which pages need it: extract **both** ways and keep the
richer (longer `textContent`) result.

This **strictly dominates** today's always-unwrap behaviour. It differs from
current behaviour *only* on pages where unwrap shortens the result — exactly the
ankerherz failure mode. Pages the current pipeline handles well (unwrap
longer-or-equal) are unchanged, so it cannot newly break a page that works today.

## Design

Split the normalizer's work into two phases and let `ArticleExtractor` choose.

### 1. `FetchedPageNormalizer` — separate the neutral repairs from the collapse

The neutral repairs (raw `<script>`/`<style>` strip, lazy-image source restore
(#467), screen-reader-only label removal (#235), orphan icon-glyph strip (#472))
are score-neutral and always safe; they stay in `normalize()`. The wrapper-chain
collapse becomes its own step, applied only to build the rescue candidate.

`normalize()` returns both candidates from a **single parse** — serialize before
the collapse, collapse in place, serialize again:

- `conservative` — neutral repairs only.
- `collapsed` — neutral repairs **plus** wrapper-chain collapse.

The return shape is a small value object (e.g. `NormalizedCandidates` with
`conservative` and `collapsed` HTML strings). When the collapse changes nothing,
the two strings are identical and the extractor parses once. No boolean flag
parameter is introduced (house rule); the two outputs are named fields.

This branch keeps the existing libxml `parse()` — the `\Dom\HTMLDocument`
migration is tracked separately in #480 and is not the #476 fix.

### 2. `ArticleExtractor` — extract both, keep the richer

Replace the single `readability->parse(...)` with:

1. Parse `conservative` and `collapsed` with readability (skip the second parse
   when the two strings are equal). Each parse is independent — a `ParseException`
   on one candidate falls back to the other; both failing yields
   `failed(url, 'unextractable')`, as today.
2. Keep the `Article` whose trimmed `textContent` is longer ("richer"). On a tie,
   keep `conservative` (fewer structural edits).
3. The rest of the pipeline is unchanged: `MIN_CONTENT_LENGTH` guard →
   `LeadingTitleRemover` → `EntrySanitizer` → `ExtractionResult::ok`.

The selection is a focused private helper (or a tiny `RicherArticle` selector
collaborator) — a service, so it may hold a private method; it stays at one level
of abstraction.

### Data flow

```
fetch (SSRF-guarded)
  → FetchedPageNormalizer::normalize(html)  ->  { conservative, collapsed }
  → readability->parse(conservative)  ->  Article A
  → readability->parse(collapsed)     ->  Article B   (skipped if collapsed === conservative)
  → keep richer(A, B) by trimmed textContent length
  → MIN_CONTENT_LENGTH guard
  → LeadingTitleRemover → EntrySanitizer
  → ExtractionResult::ok | failed
```

### Error handling

- Neither candidate parses → `failed(url, 'unextractable')`.
- Richer candidate below `MIN_CONTENT_LENGTH` → `failed(url, 'empty')`.
- Sanitizer returns null → `failed(url, 'empty')`.
- The normalizer still never throws; an unparseable page yields the raw HTML in
  both fields and the extractor behaves as it would have without normalization.

## Testing

- **#476 regression (new fixture).** A minimal synthetic page reproducing the flip:
  a rich article body in a shallow-wrapped container (`div.article__content >
  div.rte` with many paragraphs) plus a promo block that the wrapper collapse
  would elevate. Assert end-to-end that `ArticleExtractor` keeps the article and
  drops the promo. The fixture must be verified to actually flip under unwrap-only
  (else it does not guard the bug).
- **#235 guard (existing).** `article-block-components.html` must still keep both
  subheadings and the figure — proves the collapse candidate still wins when it is
  the richer one.
- **Selector unit tests.** Given two candidates, the longer `textContent` wins;
  the tie keeps `conservative`; one-candidate-throws falls back to the other;
  both-throw fails. Cover both comparison directions so the mutation gate
  (`composer infection:diff`) has kills for the comparison operator and the tie.
- **Normalizer unit tests.** `normalize()` now returns the two-field object, so
  every existing case adapts to assert against a field: the neutral-repair cases
  (glyph, screen-reader, script, style, lazy image) against `conservative`, the
  wrapper-collapse cases against `collapsed`. Add a case asserting that a page with
  no wrapper chains yields `conservative === collapsed` (so the extractor's
  single-parse shortcut is exercised).
- Run the SQLite and MySQL legs, `composer check`, `composer md`, and PhpStorm
  inspections on every touched PHP file before the PR.

## Out of scope

- The `\Dom\HTMLDocument` migration and round-trip removal — #480.
- Frontend cache: extracted articles are cached client-side (IndexedDB). Existing
  readers keep the old copy until their cache entry refreshes; if a VERSION bump is
  warranted for the corrected extraction, decide during implementation (see the
  reader-article-cache note in project memory / #467).
