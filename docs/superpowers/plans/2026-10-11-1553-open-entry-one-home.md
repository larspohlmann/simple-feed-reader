# #1553 — One home for the open entry

**Goal:** the open entry lives in `EntriesStore` whether or not the loaded list holds it, so
completions and state patches have one write path each.

## Rulings

- **Architectural fix**, not the narrow patch. `EntriesStore` gains an unlisted slot that
  `entries()` never exposes; `entry(id)` reads a listed row first, then the slot.
  `patchEntries` covers the slot, so `setState` (overlay, own-key revert, `localStatePatch`)
  and `mergeCompleted` reach a deep-linked entry by construction.
- **The body cache stops being an event source.** `EntryBodyService` hands what a body fetch
  completed straight to `EntriesStore.mergeCompleted`; the `completedEntries` stream and
  `ReaderRouteState`'s relay go.
- `ReaderRouteState` resolves the id, fetches an unlisted entry into the slot, and releases
  the slot when no entry is open or the shell is destroyed (the old `fetchedEntry` lifetime).
- The cold fetch's own `completed` part stays unused, as before; the poll fills the entry in.

## Files

- `frontend/src/app/reader/state/entries.store.ts` (+ spec)
- `frontend/src/app/reader/shell/reader-route-state.service.ts`
- `frontend/src/app/reader/shell/entry-state-actions.service.ts`
- `frontend/src/app/reader/shell/pending-entries-poll.service.ts` (+ spec)
- `frontend/src/app/reader/article/content/entry-body.service.ts` (+ spec)
- `frontend/src/app/reader/shell/reader-shell.component.spec.ts`

## Tasks

1. Store specs first: a failed PATCH on a held, completed entry keeps its cards; the slot never
   shows in `entries()`; it resets on identity change; a list reload over an in-flight patch
   keeps the slot's optimistic flag; `entry(id)` prefers the listed row.
2. Slot in `EntriesStore`; `setState` finds either copy.
3. `ReaderRouteState`: delete `fetchedEntry`, `patchFetchedEntry`, `mergeCompleted`,
   `takeCompleted`, the completion subscription.
4. `EntryStateActions`: delete `patchOpen`'s branch; every patch goes through `setState`.
5. `PendingEntriesPoll.merge`: one `store.mergeCompleted`; spec mocks it once.
6. `EntryBodyService`: merge into the store; spec asserts the store row is filled.
7. Shell spec: a failed favourite on a deep-linked completed post keeps its card.
8. `npm run check` in Docker.
