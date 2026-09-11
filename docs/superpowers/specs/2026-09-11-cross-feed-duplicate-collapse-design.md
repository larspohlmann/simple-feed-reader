# Cross-feed duplicate articles: collapse per user, show provenance

- **Issue:** #496
- **Depends on:** #484 (merged — supplies `UrlNormalizer` and the `entry.url_hash` column)
- **Date:** 2026-09-11
- **Status:** Design approved in brainstorming; ready for an implementation plan.

## Problem

The same article reaches one user through two different feeds (NDR regional
feeds republishing one tagesschau article). Both rows carry the same URL. The
rows are per-feed and shared by every user, and a user subscribed to only one
feed must still see the article, so the extra row cannot be merged or dropped at
ingest. Deduplication can only be a read-time, per-user rule. See #496 for the
dev-database evidence (62 cross-feed groups, 7 of them with different headlines
for the same URL).

## What changed from the written issue

The issue chose to hide the extra copies **silently** ("no duplicate badge, no
dimming") and to stay **server-side only**. During brainstorming we chose the
**additive** presentation the issue explicitly deferred: still one card, but the
card shows the article also arrived through other feeds, and the reader can open
those other copies. This design therefore supersedes the issue's silent approach
and adds a frontend. A note recording this has been added to #496.

Decisions taken:

1. **Identity reuses `entry.url_hash`** (Option A). No new column, no ingest
   change, no backfill.
2. **Presentation C:** a footer line on the card names each collapsed copy with
   its own time; clicking a copy opens a popover with that copy's full entry
   card; the popover card is clickable and opens that copy in the reader.
3. **The footer names only the copies collapsed in this list's own scope.** The
   unread view will not advertise a copy that is already read; All items shows
   every copy.
4. **Read and viewed mirror across the group; favorite and kept do not** — from
   any copy, including the popover.

## Identity

Reuse `entry.url_hash` = `sha256(UrlNormalizer::normalize($url))`, already set on
every row ingested since #484. Rows with no URL, and rows ingested before #484,
keep `url_hash = NULL` and never group — acceptable, since we only care about
newly fetched articles. In practice this collapses duplicates fetched since #484
(about three weeks at time of writing), a harmless superset of "from now on".

Add one index on `Entry`: `idx_entry_url_hash (url_hash, id)`, declared on the
entity mapping and created by a DDL-only migration. The index serves both the
collapse semi-join and the sibling-enrichment query.

We do **not** touch ingest dedup (`EntryDeduplicator`), which also reads
`url_hash`. Because no backfill runs, ingest behaviour is unchanged and #484's
forward-only choice is preserved. Read-time collapse and ingest dedup share the
same column but only read it; neither writes on behalf of the other.

## Collapse

### `EntryAliases` and `EntryScopePredicates`

The scope filters currently applied inline in `EntryListRepository::listForUser`
(subscription id, tag join, excluded-feeds, view) and its `applyView` /
`applyUnreadFilter` / `applyTerms` move into an `EntryScopePredicates`
collaborator that applies them to a **given alias set**, described by a small
`EntryAliases` value object (the entry / state / subscription / tag-join alias
names). The outer query uses the primary aliases (`e`/`es`/`s`/`st`); the collapse
semi-join uses a second set (`e2`/`es2`/`s2`/`st2`). Extracting this keeps the
one definition of "scope" and prevents `EntryListRepository` — already split once
for size — from regrowing. DQL query parameters are query-global, so the outer
and inner applications bind the same parameter names (identical values); only the
join aliases differ.

### `DuplicateCollapseDql`

A class beside `UnreadDql`, following its fixed-alias pattern. It produces:

```
e.urlHash IS NULL OR NOT EXISTS (
    SELECT 1 FROM Entry e2
      JOIN Subscription s2 WITH s2.feed = e2.feed AND s2.user = :user
      LEFT JOIN EntryState es2 WITH es2.entry = e2 AND es2.user = :user
    WHERE e2.urlHash = e.urlHash AND e2.id < e.id
      -- plus the same scope predicates, applied to the e2/es2/s2 alias set
)
```

The survivor is the lowest in-scope id in the group — total, deterministic,
stable across pages, and a plain `WHERE` predicate that composes with the keyset
cursor. Two rules:

- **The cursor is never carried into the semi-join.** The survivor must be the
  same on every page. This is what keeps a page at exactly `limit` visible rows.
- **A read path's row-selection filter is scope and must be carried in.** For
  `rowsByIdsForUser` (Meilisearch hydration), the matched `id IN (:ids)` set is
  the scope. If a search matched only the regional copy (different headline) and
  the semi-join looked past the matched set to a lower-id copy that did not
  match, the article would vanish from search. So the semi-join groups only
  within the matched ids.

### The five read paths

| Path | Scope carried into the semi-join |
|---|---|
| `EntryListRepository::listForUser` | subscription id, tag, excluded-feeds, view/unread — not the cursor |
| `EntryListRepository::searchForUser` | term match, unread flag — not the cursor |
| `EntryListRepository::rowsByIdsForUser` | the `id IN (:ids)` set |
| `EntryStateRepository::unreadCountsForUser` | subscribed + unread |
| `RecommendationCandidateLoader::load` | `includeInForYou`, the favorite/kept/viewed exclusion, the `since` window |

`oneRowForUser` is **not** collapsed: a deep link must open the exact entry, even
a hidden copy. `unreadCountsForUser` and `load` supply their own small
scope-appliers rather than share the list scope, because their query shapes
differ (Subscription-rooted counts; the For-You subscription gate).

## Provenance payload

The three card-returning paths (`listForUser`, `searchForUser`,
`rowsByIdsForUser`) enrich each survivor with the copies it collapsed, so the
frontend can render the footer and the popover.

- **Which copies:** exactly the rows the semi-join hid — the other copies **in
  this query's own scope** (decision 3). This reuses `EntryScopePredicates`, so
  the sibling set and the collapse stay in lockstep.
- **How:** one extra query per page. Collect the survivors' non-null
  `url_hash`es; select the in-scope rows sharing those hashes that are not the
  survivors, hydrated through the same list-row projection; group by `url_hash`
  and attach to each survivor.
- **Shape:** the siblings are full list rows (they render a full card and open in
  the reader), carried as `EntryListRow::$duplicates` and serialized as
  `EntryDto.duplicates: EntryDto[]`. Siblings are flattened — a sibling's own
  `duplicates` is always empty, so the payload never nests.
- The count path and the recommendation path carry **no** payload; they only
  collapse.

## State: read and viewed mirror across the group

`PATCH /api/entries/{id}/state` gains an `EntryStateUpdater` service
(`Service/Reader/`, beside `EntryStateResolver`). It applies the request DTO to
the target state, then mirrors `isHidden` (read) and `isViewed` onto every
sibling — same `url_hash`, in a feed the caller subscribes to — creating missing
rows through `EntryStateResolver` fed from `rowsByIdsForUser` (which returns the
`EntryListRow` the resolver needs). `isFavorite` and `isKept` do not mirror; they
are deliberate acts on a specific copy. The mirror set is every subscribed copy,
not only the in-scope ones — reading the article is an article-level act.

`ViewedImpliesHiddenListener` (#482) still applies: mirroring `isViewed` on a
sibling lets that listener hide it on flush, which is correct.

Moving the four flag branches into the service also satisfies `ThinControllerRule`
for `EntryController::updateState`.

## Mark-all-read (recorded, not changed)

`MarkReadService` is watermark-based and unchanged.

- **Scope `all`** advances the watermark on every All-items feed and flips
  existing rows read, so every copy of a syndicated article becomes read; the
  collapsed card and its footer disappear and no badge sticks.
- **Feed- or tag-scoped** mark-all-read marks only that scope's copies. The
  scoped collapse then surfaces the still-unread sibling as the new survivor, so
  the article re-appears once, attributed to the unread feed. This is consistent
  with "you marked that feed, not the whole article".
- The card's own read action clears the whole group (via the mirror above); a
  feed-scoped bulk mark stays feed-scoped.

## Migration

One migration: add `idx_entry_url_hash (url_hash, id)`. Platform-aware, verified
on SQLite and MySQL through the migrations CI leg. No data touched.

## Frontend

- **`EntryDto.duplicates: EntryDto[]`** (absent/empty when the article has no
  in-scope copies). Populated by the row projection, mirroring the precedent of
  `savedSearchTerm` being set from the combined list's provenance map.
- **Footer element:** a shared `entry-duplicates` piece rendered by each magazine
  block (below `app-entry-meta`) and by the plain `entry-row`. It shows
  `⧉ Also in ⟨source⟩ · ⟨time⟩`, one chip per `duplicates` entry, using
  `--radius-pill`, `--fs-xs`, and muted text tokens — no hex, no raw px, styles
  in a sibling `.scss`.
- **Popover:** a CDK overlay anchored to a chip, showing that copy's full entry
  card (image, kicker, title, dek, actions). Block-scroll defaults are handled
  per the CDK-dialog note. The popover card body is clickable and opens that copy
  in the reader (the existing open path).
- **Actions in the popover:** favorite and keep `PATCH` that copy's state and do
  not mirror; read/opened `PATCH` mirrors through the same `EntryStateUpdater`
  path, so acting read on any copy clears the group.
- Standalone components and signals; no `cdkDropList` nesting; the footer's
  spacing owned by the footer element, not a sibling rule.

## API and native iOS

`duplicates` is a clean additive JSON array of the existing entry shape. No CSRF
token, no browser-only input, `application/problem+json` on error unchanged. A
native client can render the footer or ignore the field. The design-time
checklist in architecture.md §6 is satisfied: the collapse and the payload are
server-computed and stateless.

## Testing

**Backend (satisfy `composer infection:diff`):**

- `UrlNormalizer` → key mapping is already covered by #484; add only what the
  collapse needs.
- `DuplicateCollapseDql` scope composition: cross-feed, tag-filtered,
  single-feed, and each view; the survivor is the lowest in-scope id; the cursor
  does not enter the semi-join; the `id`-set path groups within the matched set.
- **A user subscribed to only one side of a group is completely unaffected** —
  tested explicitly at each read path.
- Sibling enrichment: the survivor carries exactly the in-scope hidden copies;
  the payload does not nest; the count and recommendation paths carry none.
- `EntryStateUpdater`: read and viewed mirror to subscribed siblings; favorite
  and kept do not; missing sibling rows are created seeded from the watermark.
- `unreadCountsForUser` counts one per group.
- A migration test that the index exists after migrating from empty on both
  SQLite and MySQL.

**Frontend (`docker compose exec -T frontend npm test`):**

- The footer renders one chip per `duplicates` entry with source and time, and
  nothing when the list is empty.
- The popover opens the copy's card, opens the reader on body click, and issues
  the right `PATCH` (mirror on read, no mirror on favorite/keep).

**Visual rounds (the reason for the browser session):** with the Docker stack up,
verify on the account that subscribes to both sides of a known group — one card
with the footer in the All-items list and in **magazine view** (no gap, no broken
masonry across the block types), the popover positioned correctly, the sidebar
badge showing one, and reading the card (or a popover copy) clearing the group.
Repeat per view (All items, a tag, a single feed, unread, search).

## Out of scope

- No per-user toggle; the behaviour is always on. A preference can be added later
  against `user_preferences`.
- No change to ingest, to `EntryDeduplicator`, or to the search index.
- Performance: the short-circuit rests on the `(url_hash, id)` index. If a future
  feed set makes duplicates common, the semi-join and the enrichment query cost
  needs measuring (recorded in #496).

## Files touched (indicative)

**Backend**

- `src/Entity/Entry.php` — add the `idx_entry_url_hash` index mapping.
- `migrations/Version<ts>.php` — create the index.
- `src/Repository/EntryAliases.php` — new value object.
- `src/Repository/EntryScopePredicates.php` — extracted scope application.
- `src/Repository/DuplicateCollapseDql.php` — new.
- `src/Repository/EntryListRepository.php` — apply collapse + enrichment at the
  three card paths; use `EntryScopePredicates`.
- `src/Repository/EntryStateRepository.php` — collapse in `unreadCountsForUser`.
- `src/Service/Recommendation/RecommendationCandidateLoader.php` — collapse in
  `load`.
- `src/Repository/EntryListRow.php` + `src/Http/EntryJson.php` — carry and
  serialize `duplicates`.
- `src/Service/Reader/EntryStateUpdater.php` — new; `EntryController::updateState`
  delegates to it.

**Frontend**

- `src/app/reader/models.ts` — `EntryDto.duplicates`.
- `src/app/reader/magazine/entry-duplicates.component.*` — new shared footer.
- each magazine block template + `entry-row.component.html` — render the footer.
- a popover component (CDK overlay) + its `.scss`.
- store/provenance wiring to populate `duplicates`.
