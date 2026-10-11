# #1550 — one enrichment pass replaces the four EntryListRow loaders

**Goal:** `EntryListRowEnricher::enrich()` collects a page's entry ids once, runs the four
batch lookups as repository methods, and applies one `EntryListRowEnrichment` per row in a
single `mapWithDuplicates()`. The list JSON shape does not change.

## Rulings

- **Category query** moves to a new `EntryCategoryRepository::labelsByEntryIds()`;
  `EntryCategory` gets it as its `repositoryClass`. No schema change, no migration.
- **Pending stays top-level only, explicitly:** the pending lookup asks about the page's
  top-level entry ids only, so a duplicate never reads as pending.
- **Id handling is one rule:** every lookup takes ids from `EntryListRow::entryIdsOf()` /
  the top-level rows with null ids skipped; `requireId()` is gone from the pass.
- **One wither remains:** `EntryListRow::withEnrichment()`, since the enricher must swap the
  enrichment on an existing row. The four `withX()` on both classes and `enrichment()` go.
- `EntryListRow` holds `public EntryListRowEnrichment $enrichment`; `EntryJson` reads it.
- The four loader tests fold into `EntryListRowEnricherTest`: declared category order,
  grouping per entry, duplicates enriched, top-level-only pending, and one query per lookup
  (four in all, none for an empty page).
- `EntryJsonTest` pins the list-row key set so the client-facing shape cannot drift.
- Reader output is untouched, so `ReaderCacheService::VERSION` stays.

## Files

- new `backend/src/Repository/EntryCategoryRepository.php`
- `backend/src/Entity/EntryCategory.php` (repositoryClass)
- `backend/src/Repository/EntryListRow.php`, `EntryListRowEnrichment.php`, `EntryListRowEnricher.php`
- `backend/src/Http/EntryJson.php`
- delete `EntryCategoryLoader`, `LinkCardLoader`, `EntryPendingLoader`, `SavedSearchMembershipLoader` and their tests
- tests: `EntryListRowEnricherTest`, `EntryJsonTest`, `SavedSearchPageTest`

## Tasks

1. Pin the list-row key set in `EntryJsonTest` (green on develop).
2. Add `EntryCategoryRepository`, rewrite the enricher on the repositories, reshape `EntryListRow`.
3. Fold the loader tests into `EntryListRowEnricherTest`; update `SavedSearchPageTest`, `EntryJsonTest`.
4. Gates: cs, stan, tramp, md, test:parallel, infection:diff, MySQL leg; simplify pass.
