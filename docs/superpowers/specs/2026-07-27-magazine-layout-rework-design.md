# Magazine Layout Rework — Design

**Status:** approved (collaborative grilling session, 2026-07-27)
**Issue:** [#148](https://github.com/larspohlmann/simple-feed-reader/issues/148)
**Supersedes:** the planner and block catalog of
[Plan 5d — Magazine Reading Layout](2026-07-23-05d-magazine-reading-layout-design.md).
That design's premise — "it needs no backend change, every signal already ships
on `EntryDto`" — is the thing this document overturns.

## Goal

Make the magazine layout show the images the feeds already publish, and replace
its strictly periodic block cadence with an authored, non-repeating rhythm.

Two independent defects produce one symptom. The backend extracts an image URL
for ~95% of feed items and then discards it, so the client sees 42%. And the
planner's cadence is a fixed period of four, so what little variety exists
arrives on a metronome.

---

## 1. Measurement

Everything below was measured on 2026-07-27 against the live dev stack: 1200
stored entries paginated from `GET /api/entries`, and a sweep of all 24
subscriptions' raw XML (862 live feed items).

### 1.1 The image is extracted and thrown away

`ItemImageExtractor` reads `<media:thumbnail>`, `<media:content>` (including
inside `<media:group>`), RSS `<enclosure>` and Atom `<link rel="enclosure">`.
`Rss2Parser:67`, `Rss1Parser:66` and `AbstractAtomParser:98` all populate
`ParsedEntry::$imageUrl`.

`Entry` has no image column. `EntryIngestor::ingest()` sets `author`, `summary`,
`contentHtml` and `publishedAt` and never touches `imageUrl`. The only consumer
is `FeedPreviewService:92`, which reduces it to a `hasImage` boolean for the
add-feed preview.

The client therefore falls back to `preview-image.ts`, which DOM-parses
`contentHtml` then `summary` for the first `https://` `<img>`.

| Feed | Client finds | Actually in the XML |
|---|---|---|
| The Guardian | 0 / 233 | 3 × `<media:content>` per item, `width` 140 / 460 / 700 |
| DER SPIEGEL | 0 / 166 | `<enclosure type="image/jpeg">` on every item |
| BBC News | 0 / 147 | `<media:thumbnail>` 240×135 on every item |
| taz.de | 0 / 108 | `<media:content>` 948×474 on every item |
| WIRED | 0 / 24 | `<media:thumbnail>`, median width 2400 |
| Futurism | 0 / 21 | `<enclosure>` on every item |
| DIE ZEIT | 196 / 196 | inline `<img>` **148×84**; `<enclosure>` is `original__640x360` |

Only three active feeds ship no image at all: Dubspot Blog, Synthtopia, Envato
Tuts+. Client-visible coverage is **42%** in All items and **0%** in the Tech
tag, whose two feeds (WIRED, Futurism) are metadata-only.

### 1.2 Variant selection is wrong for multi-variant feeds

`ItemImageExtractor::mediaImageIn()` returns the **first** matching element. The
Guardian publishes three `<media:content>` per item in ascending width order, so
"first" is the **140px** variant — narrower than the 200px `tooSmall` gate that
already suppresses ZEIT's heroes. BBC publishes only a 240×135 thumbnail.

### 1.3 Dimension availability

| Declares | Feeds | Share of items |
|---|---|---|
| `width` + `height` | BBC, taz, WIRED | ~25% |
| `width` only | The Guardian | ~15% |
| neither | SPIEGEL, ZEIT, Futurism, all inline-only feeds | ~60% |

Orientation across the 131 items that declare both: **124 landscape, 7 square,
0 portrait.** A portrait block type has no data to fire on.

### 1.4 The group block hides most of the view

`planMagazine` consumes an entire same-source run (`magazine-planner.ts:72`),
renders `GROUP_SHOW = 3` entries and puts `moreCount` behind a link.

| View | entries | blocks | hidden |
|---|---|---|---|
| All items | 300 | 277 | 7 (2%) |
| news | 300 | 266 | 10 (3%) |
| Music Production | 300 | 151 | **94 (31%)** |
| Tech | 182 | 65 | **85 (47%)** |
| bike | 25 | **2** | **21 (84%)** |
| environment | 25 | **2** | **21 (84%)** |

The mechanism triggers on run length, not on share of the view. In a
single-feed tag every run is the whole view, so it fires at maximum strength
precisely where it has nothing to protect against. `bike` renders as one hero,
three titles and a link — it does not fill the viewport.

### 1.5 The cadence is a fixed period

`HERO_PERIOD = 4` and `sinceHero >= HERO_PERIOD` fire a hero every fourth block
whenever eligible, and it is nearly always eligible: **72 heroes in 277 blocks**
(26%, against a 25% theoretical maximum). Between heroes the choice is a pure
function of one boolean. Most common transition in All items:
`compact → compact`, 17%.

### 1.6 Content signals available for branching

Across 800 entries:

| Signal | Distribution | Usable? |
|---|---|---|
| Inline images per entry | 456 × 0, 343 × 1, **1 × 3+** | No — a gallery block would never fire |
| Snippet length | p10 4, p50 160, p90 613; 117 < 60 chars, 153 > 400 | **Yes** — genuinely tri-modal |
| Title length | p50 67, p90 90 | No — too uniform |
| Author present | 341 / 800 | Weak |
| Read state | — | No — the default view is the Unread filter, inside which it is constant |

### 1.7 Junk snippets

45 of 400 entries (11%), all DIE ZEIT: `summary` is `null` and `contentHtml` is
`<a href="…"><img src="…/wide__148x84"/></a> None`. The literal token `None` is
a Python value leaking from their CMS. `textSnippet(summary || contentHtml)`
renders it as body copy.

### 1.8 Rendering hygiene

Images already carry `loading="lazy"` and `decoding="async"`. The hero `<img>`
has **no `width`/`height` attributes** — so every image load shifts the layout —
and no `srcset`.

---

## 2. Simulation: why content scoring is the wrong lever

Three candidate algorithms were run over the same 300 real entries, with image
availability and width set per feed to the measured XML values (i.e. the
post-fix world, **99%** coverage).

| | block mix | top transition | distinct 3-grams | 3-gram entropy | items/screen |
|---|---|---|---|---|---|
| Tuned period (3–5) | split 70%, hero 21% | `split→split` 47% | 25 | 3.22 | 4.0 |
| Score + budget | split 90% | `split→split` **82%** | 16 | **1.57** | 5.8 |
| **Page templates** | compact 46%, split 20%, thumb 16%, hero 10%, wide 8% | `compact→compact` 20% | **34** | **4.88** | 5.9 |

The result that drives the design: **fixing the data makes content-driven
selection worse.** Score-and-budget assigns the largest widget an entry can
support; when 99% of entries have a large image, a 160-character median snippet
and a 67-character median title, every entry scores the same and every entry
gets the same widget.

Variety must therefore be authored on the **layout** side. Content decides only
whether an entry can *fill* a slot it has been given.

### 2.1 Degradation regimes

The template algorithm was re-run against the two image-poor regimes:

| Regime | Where | Coverage | 3-gram entropy |
|---|---|---|---|
| Archive | past the entries a refresh can still see | 42% | **5.5** |
| Zero-image | Dubspot / Synthtopia / Tuts+, or a single-feed view of one | 0% | collapses to compact |

The archive regime needs no special handling — mixed coverage is *more* varied
than uniform coverage. The zero-image regime does, hence the Kicker block type.

The simulation also exposed a rule rather than a decision: **demotion must be
transitive.** Demoting `hero → wide` in a zero-image view still leaves an image
block with no image; the ladder must be walked to `compact`.

---

## 3. Settled decisions

| # | Decision | Rationale |
|---|---|---|
| 1 | Fix the data pipeline as part of this work | No layout can arrange images that never reach the client |
| 2 | Backfill **opportunistically on refresh**; no `og:image` scraping | Feeds serve 15–50 items against 2238 stored, so a forced re-fetch reaches ~3.5% of the archive. Scraping means 2238 outbound fetches through the SSRF boundary and a worker this app does not have |
| 3 | Persist `imageUrl` + **nullable** `imageWidth`/`imageHeight`; **largest declared variant wins** | Dimensions are free (already in the XML) and buy three things: `width`/`height` attributes that kill layout shift, a plan-time size check so the planner stops emitting dead heroes, and correct `aspect-ratio` boxes. Largest-variant is required or the Guardian persists 140px |
| 4 | Stay a **single column** | Eye travel; mobile is the primary target |
| 5 | Keep the measure at **680px** at every width | Already the top of a comfortable reading measure. The "medium images are too small" complaint is widget proportion (88px in a 680px column), not column width. 680 vs 390 is a 1.74× span, so one geometry serves both |
| 6 | **Eight block types**; no portrait block | §1.3 — zero portrait images. Square images are handled by adaptive `aspect-ratio` on Hero rather than a separate block type, which would fire on ~2% of entries |
| 7 | Group becomes a **bounded digest** (≤ 4 entries, remainder flows on) **and** is suppressed when one source exceeds 40% of the loaded entries | Restores grouping to its actual purpose — de-domination in aggregated views — and removes the black hole |
| 8 | Rhythm from **page templates + height budget** | §2 |
| 9 | Zero-image views get **Kicker**; no synthesised placeholder tiles | A synthesised tile looks like an image slot from a distance and carries no information |
| 10 | **Strict reverse-chronological**, with bounded look-ahead for the largest slot per page | A reader is chronological by contract. Look-ahead generalises what `preferredGroupHero()` already does |
| 11 | ~12 templates + page-indexed shuffle (no reuse within 3 pages) + **seeded slot jitter** | 12 templates alone cycle every ~66 blocks. Two or three seeded binary choices per page multiply that into the hundreds for a fraction of a grammar's cost |

### Explicitly out of scope

Portrait block · gallery block · `og:image` scraping · server-side image
probing · multi-column grid · editorial re-ranking · read state as a prominence
signal · changes to the `list` and `pane` layouts (they inherit better images
for free).

---

## 4. Architecture

### 4.1 Backend

```
backend/src/
  Entity/Entry.php                        EDIT — imageUrl, imageWidth, imageHeight
  Service/Parser/ItemImageExtractor.php   EDIT — largest-variant selection, dimensions
  Service/Parser/ParsedImage.php          NEW  — url + nullable width/height value object
  Service/Parser/ParsedEntry.php          EDIT — ?ParsedImage $image replaces ?string $imageUrl
  Service/Parser/{Rss1,Rss2,AbstractAtom}Parser.php  EDIT — pass the value object through
  Service/EntryIngestor.php               EDIT — persist the image; backfill known entries
  Service/EntrySnippet.php                NEW  — snippet derivation, image markup stripped
  Dto/Entry/EntryResponse.php             EDIT — expose imageUrl/imageWidth/imageHeight
  Service/Preview/FeedPreviewService.php  EDIT — follow the ParsedImage rename
migrations/VersionYYYYMMDDHHMMSS.php      NEW  — three nullable columns
```

`ItemImageExtractor`'s public surface becomes `?ParsedImage` throughout.
"Largest wins" compares declared `width`; an element with no declared width
sorts below any element that declares one, and ties keep document order.
Media RSS and enclosure candidates are pooled before the comparison, so a feed
that ships both a small `<media:thumbnail>` and a large `<enclosure>` gets the
large one — this is what makes BBC (thumbnail-only, 240px) and WIRED
(thumbnail-only, 2400px) both work without per-feed special cases.

**Backfill.** `EntryIngestor::ingest()` currently `continue`s on a known
`guidHash`. It gains a sibling method following the shape of
`correctPublishedDates()`: match stored entries by guid hash against a fresh
parse and fill `imageUrl` **only where it is currently null**. Never overwrites,
so a feed that later drops its images does not erase what we have.

**Snippet.** `EntrySnippet` strips image markup before reducing to text, and
returns `null` when the result is empty or a single junk token. This fixes the
ZEIT `None` case as a general rule rather than a blacklist.

### 4.2 Frontend

```
frontend/src/app/reader/
  models.ts                          EDIT — imageUrl, imageWidth, imageHeight on EntryDto
  preview-image.ts                   EDIT — entryImage(entry) prefers the DTO field,
                                            falls back to inline parsing for archive rows
  magazine/
    magazine-block.ts                NEW  — the MagazineBlock union, one place
    magazine-templates.ts            NEW  — the template library as reviewable data
    magazine-planner.ts              REWRITE — template engine
    entry-hero.component.*           EDIT — adaptive aspect-ratio, width/height, srcset
    entry-wide.component.*           NEW
    entry-split.component.*          NEW
    entry-thumb.component.*          NEW
    entry-quote.component.*          NEW
    entry-kicker.component.*         NEW
    entry-compact.component.*        EDIT — unchanged shape, new block type name
    source-group.component.*         EDIT — bounded digest
  entry-list/entry-list.component.html EDIT — render the new block union
docs/design-language.md              EDIT — the eight block types and their tokens
```

### 4.3 The block catalog

Heights are measured at 390px viewport width.

| # | Block | Shape | Height | Fills when |
|---|---|---|---|---|
| 1 | **Hero** | full-bleed image, `aspect-ratio` from data (fallback 16/9), title, 2-line snippet, actions | ~463px | image ≥ 500px wide |
| 2 | **Wide** | full-width image at 3:1, title only | ~260px | image ≥ 400px wide |
| 3 | **Split** | image at 38% (**148px mobile / 258px desktop**), title + 2-line snippet | ~150px | image ≥ 300px wide |
| 4 | **Thumb** | 88×66 image, title + meta | ~90px | any image |
| 5 | **Quote** | first sentence in `--font-voice`, title as kicker, **image suppressed** | ~180px | snippet ≥ 300 chars |
| 6 | **Kicker** | oversized title, no image, source + time | ~140px | always |
| 7 | **Compact** | title + meta | 66px | always |
| 8 | **Group** | source header + ≤ 3 titles + "more" | ~300px | a bounded same-source run |

Split at 148px mobile / 258px desktop is the direct answer to "the medium
widget shows images too small" — today it is 88px.

Quote's trigger is **inverted** from the obvious one. Gating it on "no image"
was simulated and fired zero times, because after the pipeline fix ~1% of
entries lack an image. It is a template-requested slot filled by any long-text
entry, with its image deliberately suppressed.

### 4.4 The planner

```ts
export interface MagazinePlanInput {
  entries: EntryDto[];
  /** Aggregated view (All / tag / favorites / kept). False in a single feed. */
  grouping: boolean;
  /** False while `hasMore` — holds back a partial trailing page. */
  complete: boolean;
}

export function planMagazine(input: MagazinePlanInput): MagazineBlock[];
```

Per page:

1. Take the next `template.length` entries. If fewer remain and `!complete`,
   **stop** — a partial page is held back so a later fetch cannot rewrite it.
2. Pick the template: `shuffledIndex(pageIndex)`, constrained so no template
   repeats within 3 pages.
3. Apply seeded jitter from `pageIndex` — filler slots choose thumb vs compact,
   split slots choose image-left vs image-right.
4. Assign entries to slots **in order**. The single largest slot may look ahead
   up to 2 positions for an entry that fits it; every other slot takes the next
   entry.
5. Any slot whose entry cannot fill it demotes **transitively** down
   `hero → wide → split → thumb → compact`, and `quote → kicker → compact`.
6. Enforce the height budget: if the page's summed height exceeds the cap,
   demote the largest slot once and re-check.

Grouping runs before templating: a same-source run of ≥ 3 whose source is under
40% of the loaded entries emits one bounded digest consuming at most 4 entries;
everything else flows into the template stream.

**Prefix stability** is preserved at page granularity, which is stronger than
what it replaces: a page is a closed unit, template choice depends only on
`pageIndex`, and jitter is seeded from `pageIndex`. Re-planning a longer list
therefore re-emits an identical prefix.

### 4.5 Testing

- **Prefix stability** — plan `entries[0..n]`, plan `entries[0..2n]`, assert the
  first plan is a prefix of the second. The existing `magazine-planner.spec.ts`
  cases port over.
- **3-gram entropy floor** over a fixed synthetic stream. This is the only test
  that can catch the actual regression being fixed: a golden-sequence snapshot
  passes happily on perfectly periodic output.
- **Degradation** — plan a 0% image stream and assert no image block survives;
  plan a 42% stream and assert entropy stays above the floor.
- **No hidden entries** — for any input, the count of entries reachable across
  all emitted blocks plus digest `moreCount` equals the input length.
- Backend: parser tests per format for largest-variant selection and dimension
  capture; an ingestor test for backfill-only-when-null; **a migration test**,
  since `tests/bootstrap.php` builds schema from ORM metadata and never executes
  a migration.

---

## 5. Native iOS viability

Per the standing constraint in `docs/architecture.md` §6: the new fields are
plain nullable scalars on an existing JSON response, no new endpoint, no
browser-only input, no content negotiation. The planner is presentation logic
and lives in the client — a native client would author its own template library
against the same `EntryDto`, which is exactly the intended boundary. Persisting
`imageWidth`/`imageHeight` in fact *improves* native viability: a native client
can size a cell before the image loads instead of measuring it in a layout pass.
