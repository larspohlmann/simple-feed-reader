# Persist and display feed-declared entry categories (#953)

## Summary

Feeds declare per-entry categories (RSS `<category>`, Dublin Core `<dc:subject>`,
Atom `<category term=>`). The parser drops all of them today. This work parses,
normalizes, and stores them on the entry, and shows them to the reader as a
muted, comma-separated row in the article footer.

Delivered as **one PR** covering both the groundwork (parse/normalize/store,
#953's stated scope) and the reader surface (API field + footer). #953's own
text calls UI and API "out of scope"; that boundary is intentionally crossed
here at the owner's direction, so the PR note must record it.

## Feasibility (measured against the live library, 2026-09-13)

All 192 active feeds were fetched and counted. 98 of 191 fetched feeds (51%)
carry per-entry categories; ~88 of those give diverse, useful values. The
no-coverage half includes Substack (28 feeds, never emit categories) and most
German public radio. The values are worth storing: named entities (NYT),
hierarchical taxonomies (Wired `Gear / Buying Guides`), topic tags (Cloudflare,
netzpolitik, Ars Technica). Full findings are recorded on the issue.

## Decisions settled before design

- **Storage**: a normalized `category` table plus an `entry_category` join
  entity — not a JSON column. (The house per-entry-collection precedent is a
  JSON column, `EntryMedia`; a normalized shape was chosen so later grouping
  needs no migration.)
- **Identity scope**: global. One `(canonical_key, scheme)` is one `category`
  row shared across all feeds. Enables future cross-feed grouping.
- **Display label**: stored per link on `entry_category`, not on `category`.
  The footer shows exactly what this feed called the category, in declared
  order. Global identity affects grouping only, never display.
- **Scheme/domain**: kept when present (RSS `domain`, Atom `scheme`); stored as
  `''` when absent so the unique key deduplicates (MySQL and SQLite both treat
  NULL as distinct in a unique index).
- **Formats this ticket**: RSS 2.0, RSS 1.0/Dublin Core, Atom 1.0/0.3.
  **WordPress-JSON is deferred** to a follow-up: its `_fields` allow-list is a
  constant baked into each stored feed URL at discovery, so it would need the
  constant widened, the existing feed URLs rewritten, and category-ID→name
  resolution (WP returns numeric IDs without `_embed`, which the code drops for
  size). Numeric IDs also cannot join a global identity space, because an ID is
  only meaningful per site.
- **Ingest**: write-once, at entry creation only. This matches the existing
  discipline exactly — `EntryIngestor` skips deduplicated entries and never
  updates them (the one exception is opportunistic image backfill).
- **`Entry` entity**: untouched. It sits at the PHPMD field ceiling (15/15), so
  categories are read through their own query, never a field on `Entry`.

## Data model

### `category` (global identity / lookup)

| column | type | notes |
|---|---|---|
| `id` | int, PK, auto | |
| `canonical_key` | varchar(128) | lower-cased, whitespace-collapsed identity |
| `scheme` | varchar(255) | RSS `domain` / Atom `scheme`; `''` when absent |

- `UNIQUE(canonical_key, scheme)`.
- Modeled on `CatalogCategory` (unique key + cascade in the tree).

### `entry_category` (per-entry link, join entity)

| column | type | notes |
|---|---|---|
| `entry_id` | int, FK → entry, `ON DELETE CASCADE` | |
| `category_id` | int, FK → category | |
| `position` | smallint | feed-declared order, 0-based |
| `label` | varchar(128) | this feed's declared label for this entry |

- Composite PK `(entry_id, category_id)`.
- Modeled on `SubscriptionTag` (composite-key join entity carrying `position`).
- No collection field is added to `Entry`; the link is a standalone join entity.

## Parser layer

- New VO `ParsedCategory { string $label; ?string $scheme }` — trimmed, raw.
- `ParsedEntry` gains `list<ParsedCategory> $categories = []`, defaulted so
  every existing constructor call keeps compiling.
- Each format parser reads its own element inside its existing item loop, the
  way `dc:creator` and media are already read (see `ItemMediaExtractor` for the
  "read repeated child elements into a bundle" precedent):
  - `Rss2Parser`: `<category>` text, `domain` attribute → scheme.
  - `Rss1Parser`: `<dc:subject>` (Dublin Core namespace).
  - `AbstractAtomParser`: `<category>` `term` attribute → label, `scheme`
    attribute → scheme.

## Normalization — one pure service

`CategoryNormalizer` turns raw `ParsedCategory[]` into the rows to persist:

- Trim whitespace; drop empties.
- Canonical key = lower-cased, whitespace-collapsed label.
- De-duplicate within the entry on `(canonical_key, scheme)`,
  case-insensitively; the first-seen label wins.
- Absent scheme → `''`.
- Caps (defensive against abusive feeds): at most **30** categories per entry;
  label and canonical key truncated to **128** characters; scheme to **255**.
- Pure, no I/O; fully unit-tested.

## Ingest — write-once

- Categories are set only when an entry is first created. Deduplicated entries
  are skipped exactly as today; there is no update path and no churn.
- `CategoryResolver` does global get-or-create: an in-process cache keyed by
  `(canonical_key, scheme)`, a DB lookup on miss, an insert on a real miss. It
  is race-safe against concurrent workers by catching the unique-constraint
  violation and re-reading. New global `category` rows are cheap and rare.
- `EntryIngestor` delegates category work to a collaborator so it stays thin
  (`ThinControllerRule` is controllers only, but PHPMD codesize still applies).
  It writes `entry_category` rows with `position` and `label` for new entries.

## Read path (for the footer)

`EntryJson::one` is shared by the entry list (`EntryPage`), the recommendation
feed (`RecommendationFeedJson`), and the single-entry endpoint
(`EntryController::get`). The reader footer reads its `EntryDto` from the
already-loaded list, so categories must be present in the list payload and must
be **batch-loaded per page**, never per row.

- `EntryCategoryLoader` loads categories for a page of entries in one query
  (`entry_id IN (…)`, ordered by `position`), grouped by entry.
- `EntryListRow` gains `array $categories = []` plus a `withCategories()`
  wither (mirrors the existing `withDuplicates()`); the loader enriches rows at
  the hydration boundary so all three consumers get them with no N+1.
- `EntryJson::one` emits `'categories' => list<string>` (declared labels, in
  order); an entry with none emits `[]`.

## Frontend

- `EntryDto` (`frontend/src/app/reader/models.ts`) gains `categories: string[]`.
- `reader-view.component.html`: a new muted row **before `</article>`** (a true
  footer, after `.content`), rendered only `@if (e.categories?.length)`, labels
  joined with `, `. Non-clickable — filtering stays out of scope.
- `reader-view.component.scss`: styled like `.meta` —
  `font-size: var(--fs-sm); color: var(--text-muted); margin-top: var(--space-3)`.
  No hex, no ad-hoc `px`, sibling `.scss` only.

## Migration

- One platform-aware migration (MySQL / SQLite branches, `isTransactional()`
  false) creating both tables, following `Version20260907112243` (the media
  columns) and the `CatalogCategory` table shape.
- Exercised by the migrate-from-empty CI leg on both engines;
  `doctrine:schema:validate` must stay green.

## Testing

- **Unit**: `CategoryNormalizer` (trim, case-insensitive dedup, first-label
  wins, caps, scheme→`''`); per-format extraction (RSS2 `domain`, RSS1
  `dc:subject`, Atom `term`+`scheme`).
- **Ingest / integration**: a refresh creates categories and links; a **second
  refresh adds no duplicate categories and no new links** (write-once proof);
  two feeds sharing a category resolve to **one** global `category` row; the
  batch loader returns each entry's categories with no per-row query.
- **Frontend unit**: the footer renders the list when present and is absent when
  empty.
- **Migration**: covered by the migrate-from-empty CI leg (no test runs a
  migration directly).
- **Gates**: `composer check` + `composer md` clean on touched files;
  `infection:diff` over the changed lines; PhpStorm inspections clean on
  changed PHP; frontend `npm run check` clean.

## Out of scope (unchanged from #953)

- Filtering, per-category views, sidebar chips.
- WordPress-JSON category capture (separate follow-up).
- Recommendation-engine use of categories.
- Backfill of historical entries; categories arrive going forward only.
