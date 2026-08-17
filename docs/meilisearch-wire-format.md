# Meilisearch wire format

Facts about Meilisearch's HTTP API that `MeilisearchIndex` and
`app:search:reindex` are built on, measured directly against a running
instance rather than taken from upstream documentation. These are
**measurements against Meilisearch v1.13**, not quotations from the
Meilisearch docs — re-measure before trusting any of this against a
different version.

## 1. Document writes must pass `?primaryKey=id` explicitly

Meilisearch infers the primary key by looking for exactly one field name
ending in `id`. An entry document carries both `id` and `feedId` — two
candidates — so inference fails. The write still comes back `202
Accepted` with a task enqueued; the task itself then fails:

```
POST /indexes/probe/documents
[{"id": 1, "feedId": 1, "effectiveDate": 100, "title": "...", "summary": "..."}]

-> 202 Accepted, taskUid enqueued
-> GET /tasks/{taskUid}:
{
  "status": "failed",
  "type": "documentAdditionOrUpdate",
  "details": {"receivedDocuments": 1, "indexedDocuments": 0},
  "error": {
    "message": "The primary key inference failed as the engine found 2 fields ending with `id` in their names: 'feedId' and 'id'. Please specify the primary key manually using the `primaryKey` query parameter.",
    "code": "index_primary_key_multiple_candidates_found",
    "type": "invalid_request"
  }
}
```

Passing `primaryKey=id` on the same call succeeds. This is why every
document-add call in `MeilisearchIndex::upsert()` targets
`/indexes/entries/documents?primaryKey=id` and never relies on inference.

## 2. Writes are asynchronous — a 202 is not success

Every write endpoint (`POST .../documents`, `POST .../documents/delete-batch`,
`DELETE .../documents`, `PATCH .../settings`) answers immediately with:

```json
{"taskUid": 3, "indexUid": "entries", "status": "enqueued", "type": "...", "enqueuedAt": "..."}
```

The only way to know a write actually landed is to poll `GET
/tasks/{taskUid}` until `status` becomes `succeeded` or `failed`:

```json
{
  "uid": 3, "status": "succeeded", "type": "documentAdditionOrUpdate",
  "details": {"receivedDocuments": 1, "indexedDocuments": 1},
  "error": null, "duration": "PT0.453895820S"
}
```

`MeilisearchIndex` deliberately does not poll this. An ingest-time write
(`EntryIndexer`) is a side effect of storing an entry and already has a
durable source of truth to repair from — `app:search:reindex` — so it must
not turn a refresh into a wait loop over the search engine's queue.
`app:search:reindex` reports only that every batch was **accepted**, not
that indexing has finished, for the same reason: growing the adapter a
reindex-only polling path would duplicate the one class that knows this
wire format behind a second, less-trusted path.

## 3. `GET /indexes/{uid}/stats` is stale — never use it to verify a write

Measured immediately after a verified-successful `DELETE .../documents`
(the delete task itself had already reached `status: "succeeded"`, and the
index genuinely held 0 documents):

```
GET /indexes/ct3/documents?limit=10   ->  {"total": 0}     (correct)
GET /indexes/ct3/stats                ->  {"numberOfDocuments": 2}   (wrong)
```

`stats.isIndexing` is equally unsafe as a completion signal. Poll `GET
/tasks/{taskUid}` for a terminal status, or count with `GET
/indexes/{uid}/documents`, when a real answer matters — never `stats`.

## 4. `_formatted` carries a field even when `attributesToRetrieve` does not

`attributesToHighlight` is independent of `attributesToRetrieve`: a field
listed only in the former still shows up, highlighted, in the response's
`_formatted` object.

Request:
```json
{
  "q": "receive",
  "attributesToRetrieve": ["id"],
  "attributesToHighlight": ["title", "summary"],
  "highlightPreTag": "<mark>",
  "highlightPostTag": "</mark>"
}
```

Response:
```json
{
  "hits": [{
    "id": 1,
    "_formatted": {
      "title": "How to <mark>receive</mark> a package",
      "summary": "A guide about <mark>receivi</mark>ng packages safely.",
      "id": "1"
    }
  }]
}
```

`title` and `summary` come back in full even though `attributesToRetrieve`
listed only `id`. This is what lets `MeilisearchIndex::searchPayload()` ask
for highlighted title/summary without paying to retrieve either field's
full value on every reply — `attributesToRetrieve` stays `['id']`.

Two side notes worth keeping in mind when reading a hit: `_formatted`
stringifies every field it echoes (`"id": "1"`, not `1`), and a
typo-tolerant prefix match can highlight only the matched prefix of a word
(`<mark>receivi</mark>ng`), not the whole token — cosmetic, but a highlight
boundary can land mid-word.

## The search request the adapter sends

`MeilisearchIndex::searchPayload()` builds this shape:

```json
{
  "q": "<the search terms, space-joined>",
  "filter": "feedId IN [1,2] AND (effectiveDate < 100 OR (effectiveDate = 100 AND id < 5))",
  "sort": ["effectiveDate:desc", "id:desc"],
  "matchingStrategy": "all",
  "limit": 20,
  "attributesToRetrieve": ["id"],
  "attributesToHighlight": ["title", "summary"],
  "highlightPreTag": "[[sfr:hl]]",
  "highlightPostTag": "[[/sfr:hl]]"
}
```

- **`sort`** matches the order `EntryRepository` uses everywhere else
  (newest first, ties broken by id), so a page hydrated from the returned
  ids lines up with what the caller already expects.
- **`matchingStrategy: "all"`** was verified against Meilisearch's default
  (`"last"`): with `"all"`, every query term must match somewhere in the
  document, so a two-word query with one unmatched term returns zero hits.
  With `"last"` (the default), Meilisearch drops trailing terms until
  something matches, turning that same query into a one-word search that
  matches everything — confirmed by probe, and a worse answer than
  reporting no results.
- **`filter`** is the compound keyset-pagination expression: everything
  strictly before the cursor's `effectiveDate`, plus same-date rows with a
  smaller `id`. It was confirmed accepted verbatim as a plain filter
  string (not an array-of-arrays), with every attribute it references
  present in `filterableAttributes`. A malformed expression or a filter on
  a non-filterable attribute both come back `400` with code
  `invalid_search_filter` and a human-readable `message` pinpointing the
  problem — the two cases are not distinguishable by code alone.

## Index settings the adapter applies

`MeilisearchIndex::configure()` sends this via `PATCH
/indexes/entries/settings`, which creates the index if it does not yet
exist:

```json
{
  "searchableAttributes": ["title", "summary", "content", "feedTitle"],
  "filterableAttributes": ["feedId", "effectiveDate", "id"],
  "sortableAttributes": ["effectiveDate", "id"]
}
```

`searchableAttributes`' order is a behavioural contract, not cosmetic:
Meilisearch's attribute-ranking rule ranks a hit by which attribute in this
list it matched, in the order declared here. Title before summary before
content before feed title means a match in the headline outranks the same
word buried in the body or riding in on the feed's own name.

## Other confirmed behaviour

- `POST .../documents/delete-batch` takes a bare JSON array of primary-key
  values (numbers, matching how they were indexed) and returns the same
  async task envelope as every other write.
- Typo tolerance is on by default: a search for `recieve` matched an
  indexed `receive` with no extra configuration.
- Top-level search response keys are `hits`, `query`, `processingTimeMs`,
  `limit`, `offset`, `estimatedTotalHits` — this is the offset/limit
  pagination API, not the `page`/`hitsPerPage` one, so there is no
  `totalHits`/`totalPages`.
