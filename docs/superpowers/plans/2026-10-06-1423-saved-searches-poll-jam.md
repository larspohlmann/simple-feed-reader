# #1423 A failed saved-searches load jams the counts poll — implementation plan

**Goal:** after `GET /api/saved-searches` fails, the counts poll's next tick past the freshness window
loads the badges again instead of being skipped for the rest of the session.

**Design**

- `SavedSearchesStore.load()` releases `inFlight` when the request fails as well as when it succeeds.
  The badges keep their last known counts; the failure stays silent, as the rest of `load()` is (the
  sidebar has no saved-search error surface, and the next tick is the recovery).
- No change to the freshness window: a failed load still stamps `lastLoadedAt`, so a failing backend is
  asked again once per window, not on every tick.

**Tasks (TDD)**

1. Spec in `saved-searches.store.spec.ts` under "the counts poll (#708)": a load that errors, then
   `reloadIfStale()` once the window has elapsed, sends a second request. Red first.
2. Spec: a failed load leaves the badges from the previous successful load in place.
3. Fix `load()`; the specs go green.
4. Gates: `npm run check:static` and Jest inside the Docker frontend container (`-w 2`, the full parallel
   run OOMs the container).
