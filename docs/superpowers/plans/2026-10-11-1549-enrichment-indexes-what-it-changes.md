# #1549 Enrichment indexes what it changes; PostEnricher stops signalling failure with null

**Goal:** whoever flushes an enrichment indexes what it changed, so a post filled through
`POST /api/entries/complete` or carded by the link-card pass is searchable on its new text; `PostEnricher`
no longer returns `null` for "the AppView gave no usable answer".

## Rulings

- `PostEnricher::claimAndSettle()` indexes the entries it filled after its flush; `ShareLinkCards::store()`
  indexes the held rows' entries after its flush (the writer does not report whether the text changed, and
  `upsert` is idempotent, so every carded entry is upserted).
- With indexing inside, `enrich()` and `complete()` return nothing: no caller reads a result any more. Tests
  assert on what reached the index writer instead. `enrich()` loses `$createdEntries` and its filter.
- `FeedOutcomePersister` indexes only its created entries; a created-and-filled entry is upserted twice.
- `settle()` returns `list<Entry>` and lets the existing typed `FetchException`/`AppViewAnswerException`
  through; the chunk loop catches, logs the same warning, and leaves the chunk unsettled. No new exception:
  the failure is already typed and namespaced.
- Chunk loop: guard clauses for an empty chunk and a throttled AppView.
- Class docblock that narrates the schedule and repeats the give-up window goes.
- Out of scope: the optional move of the AppView pass out of the persister (lean; the persister no longer
  collects entries for it, which was the coupling the issue named).
- Search in tests has no engine that reads what was written, so the acceptance test asserts the completion
  endpoint upserts the post's new text to the index writer.
- `/api/entries/{id}/reader` is unchanged, so no `ReaderCacheService::VERSION` bump.

## Files

- `backend/src/Service/Bluesky/PostEnricher.php`
- `backend/src/Service/LinkCard/ShareLinkCards.php`
- `backend/src/Service/Refresh/FeedOutcomePersister.php`
- `backend/tests/Support/PostEnrichers.php`, `backend/tests/Support/ShareLinkCardsWiring.php` — take an index
  writer.
- `backend/tests/Service/Bluesky/PostEnricherTest.php`, `backend/tests/Service/LinkCard/ShareLinkCardsTest.php`,
  `backend/tests/Service/Refresh/FeedOutcomePersisterBlueskyTest.php`,
  `backend/tests/Controller/Api/EntryCompletionControllerTest.php`

## Tasks

1. Acceptance test: completion endpoint upserts the filled post's text (red).
2. `PostEnricher`: inject `EntryIndexer`, index after flush, void results, guard-clause loop, throwing `settle()`.
3. `ShareLinkCards::store()` indexes; test it.
4. `FeedOutcomePersister` indexes created entries only; adjust its Bluesky test.
5. Gates, simplify, PR.
