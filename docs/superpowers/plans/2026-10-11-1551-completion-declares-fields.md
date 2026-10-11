# #1551 Completion declares which fields it may change

**Goal:** `POST /api/entries/complete` answers with exactly the fields a completion can change, and the SPA
merges everything it receives. The hand-kept `COMPLETED_KEYS` goes, and `share` reaches the store and the
open entry.

## Rulings

- Option (c), with (a) folded in: `EntryJson::completed()` declares the shape, the client spreads it.
- The shape is what `PostEnricher`/`EntryEmbedWriter` and `ShareLinkCards` write, read off the code: `summary`,
  `excerpt` (derived from summary, body and share), `imageUrl`, `imageWidth`, `imageHeight`, `imageRenditions`,
  `media`, `linkCards`, `share`, plus `id` and `pending` (the `regresses()` guard needs it).
- `title` and `imageAspectRatio` leave the merged set: no completion writes a title, and the aspect ratio is a
  function of the URL, which completion never touches.
- `listRow()` starts from `completed()`, so the two shapes cannot drift apart.
- A round that fills nothing still keeps the list's identity (the `sameValue` comparison stays, over the
  received keys).
- The only client consumer is `PendingEntriesPoll` via `ReaderApi.complete`; its type becomes
  `CompletedEntryDto`. Store specs that relied on the answer carrying user state are reshaped or dropped, since
  the answer can no longer carry it.

## Files

- `backend/src/Http/EntryJson.php` — `CompletedEntryRow` alias, `completed()`, `listRow()` built on it.
- `backend/src/Controller/Api/EntryCompletionController.php` — maps with `completed()`.
- `backend/tests/Http/EntryJsonTest.php`, `backend/tests/Controller/Api/EntryCompletionControllerTest.php` —
  pin the declared key set.
- `frontend/src/app/reader/models.ts` — `CompletedEntryDto`.
- `frontend/src/app/reader/reader-api.ts`, `state/completed-entry.ts`, `state/entries.store.ts`,
  `shell/reader-route-state.service.ts`, `shell/pending-entries-poll.service.ts` — take `CompletedEntryDto`.
- Specs: store, poll, shell (acceptance: a `share` change reaches the store and the open entry).

## Tasks

1. Backend test: the completion answer carries exactly the declared keys; then `completed()`.
2. Frontend shell spec: a completion that turns `share` to `media` reaches the listed row and the open
   (fetched) entry; then the client type and merge.
3. Gates, simplify, PR with the docs/architecture.md §6 checklist result.
