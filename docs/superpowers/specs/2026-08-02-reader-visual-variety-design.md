# Reader-mode extraction fixes and typographic variety

**Issue:** [#235](https://github.com/larspohlmann/simple-feed-reader/issues/235)
**Date:** 2026-08-02
**Status:** Approved (previewed live on entries 11636/11637, BBC News)

## Problem

Long articles in reader mode often render as an undifferentiated wall of
paragraphs. Investigation showed the dominant cause is an extraction defect,
not a styling gap: block-component sites (BBC News is the canonical case) wrap
every paragraph, subheading and image in its own deep chain of single-child
`<div>` wrappers. The wrapper depth dilutes readability.php's score
propagation, so the sibling-join phase keeps only the strong text blocks and
silently discards subheadline blocks, image blocks — and even some text
blocks. Measured on a BBC article: extraction kept 52 `<p>` and nothing else;
the source had 3 subheadings, 5 figures and 71 paragraphs.

fivefilters/readability.php v4.0.0 (current) has no configuration option that
fixes this (`weightClasses` and `cleanConditionally` were tested; no effect).

A secondary gap is real: the article stylesheet has no styles for tables and
several small elements, and articles offer no reading-effort signal.

## Part 1 — extraction pipeline (backend)

The pipeline in `ArticleExtractor` becomes:

```
fetch (SSRF-guarded)
  → FetchedPageNormalizer::normalize()      (pre-parse, on the fetched page)
  → readability parse
  → LeadingTitleRemover::remove()           (post-parse, on extracted content)
  → EntrySanitizer (unchanged XSS barrier)
```

### FetchedPageNormalizer (new, `Service/Reader/`)

One DOM pass over the fetched page before readability parses it. Two repairs:

1. **Remove screen-reader-only elements.** Elements whose `class` matches
   `visually-hidden` / `visuallyhidden` / `sr-only` / `screen-reader`
   (case-insensitive substring) are removed. These carry labels like
   "Image source," that are invisible on the source site but become visible
   text once the sanitizer strips classes.
2. **Collapse single-child `<div>` chains.** A `<div>` with no own text and
   exactly one element child that is also a `<div>` is replaced by that child,
   bottom-up until stable. With the wrappers gone, score propagation reaches
   the real article container and the whole body is selected.

Never throws: HTML the normalizer cannot parse or serialize is returned
unchanged, and extraction proceeds as before.

Verified against live pages: BBC article gains 3 h2, 5 figures + images and
19 paragraphs; NDR and heise articles are byte-identical with and without the
normalizer.

### LeadingTitleRemover (new, `Service/Reader/`)

Drops the first heading (`h1`/`h2`/`h3`, document order) of extracted content
when its normalized text (whitespace-collapsed, case-folded) equals any title
candidate. Candidates are the readability page title **and** the feed entry's
title — BBC's page `<title>` is an SEO variant that does not match the body
headline, while the entry title matches it verbatim. Without this step the
headline renders twice (readability demotes the page `h1` to `h2` and its own
duplicate check misses headlines in separate wrapper blocks).

Never throws; unparseable content is returned unchanged.

### Interface change

`ArticleExtractorInterface::extract()` gains an optional parameter:

```php
public function extract(string $url, ?string $entryTitle = null): ExtractionResult;
```

The reader endpoint (`EntryController::reader`) passes `$entry->getTitle()`.
Other callers are unaffected (the parameter defaults to null; scraper and
discovery use the separate `Scraper\HtmlItemExtractor`).
`tests/Support/FakeArticleExtractor` must adopt the new signature.

### Known limits (accepted)

- Already-read entries keep the old flat extraction in the client's IndexedDB
  cache until evicted; new reads get the fix immediately.
- The article byline ("Ruth Clegg / Health and wellbeing reporter") stays in
  the body. It is real content and readability offers no reliable way to
  distinguish it.

## Part 2 — typographic variety (frontend)

All content styles live in the `.content ::ng-deep` block of
`reader-view.component.scss`. Theme tokens only — no hex, no ad-hoc px.

1. **Section dividers.** Each `h2` gets `--space-7` above and a full-width
   1px `var(--border)` rule (`::before`) with `--space-4` below it. `h3`+ keep
   the plain heading margin (subordinate headings, not section starts).
2. **Lead paragraph.** The component's post-render pass tags the first
   non-empty `<p>` with class `lead`; CSS renders it at `1.0625em`. A pure CSS
   selector cannot reach it through the wrapper elements feeds and readability
   emit. No drop cap (fragile with quotes, umlauts, leading links).
3. **Blockquotes.** Keep the left border; add `var(--surface-1)` background,
   rounded right corners, `--space-3/--space-4` padding, and remove the last
   child paragraph's bottom margin.
4. **Tables.** `display: block` with `overflow-x: auto` (wide tables scroll
   inside the column), collapsed borders, `--fs-sm` type, cell padding from
   the spacing scale, `var(--border)` row rules with `var(--border-strong)`
   under the header row.
5. **Reading time.** A `computed` signal on the reader view derives minutes
   from `displayHtml()`: strip tags textually (no DOM parse — a detached DOM
   would prefetch images), count whitespace-separated words, divide by
   220 wpm, round; null (hidden) when it rounds below one minute. Rendered in
   the `.meta` line via the Transloco key `reader.readingTime`
   (en "≈ {{minutes}} min", de "≈ {{minutes}} Min."). Recomputes automatically
   on the Reader/Original toggle.
6. **Small elements.** `mark`: accent-tinted background via `color-mix` with
   `var(--accent)`; `sub`/`sup`: `line-height: 0` guard; `dl`/`dt`/`dd`:
   margins and bold terms.

### Out of scope

Synthetic dividers in articles without headings. Any heuristic (every N
paragraphs, topic-shift detection) invents structure the author did not
write.

## Testing

Backend:

- Unit tests for `FetchedPageNormalizer`: wrapper-chain collapse, mixed
  children untouched, text-bearing wrappers untouched, hidden-class removal,
  malformed HTML returned unchanged.
- Unit tests for `LeadingTitleRemover`: removal on entry-title match, removal
  on page-title match, no removal on mismatch, no removal when the heading is
  not first, unparseable content unchanged.
- `ArticleExtractor` test with a stored block-component fixture (BBC-like
  structure, synthetic content): asserts headings and figures survive and the
  duplicated headline is dropped.
- Regression fixture with conventional article markup: asserts structure is
  unchanged by the normalizer.
- Both suite legs (SQLite native, MySQL Docker) before the PR.

Frontend (Jest):

- Word-count/reading-minutes: HTML stripped, entities ignored, empty →
  null, sub-minute → null, rounding.
- Lead-paragraph tagging: first non-empty `<p>` tagged, empty leading `<p>`
  skipped, re-render moves the tag (no stale `lead` classes).
- `npm run check` (Node 22) as the gate.

Quality gates: `composer check`, `composer md` (touched files PHPMD-clean),
PhpStorm inspections on changed PHP, Stylelint/Prettier via `npm run check`.

## Rollout

Single branch `feature/235-reader-visual-variety`, PR into `develop` with
"Closes #235". Client caches need no migration (only new extractions change).
