// Shared reader-endpoint fixtures for the article e2e specs.
import type { SavedSearchWire } from '../../src/app/reader/models';

/** Reasons the backend reports when reader extraction produces no article. */
type ReaderFailureReason = 'no_url' | 'fetch' | 'unextractable' | 'empty';

/**
 * A failed `GET /api/entries/{id}/reader` body that matches the current
 * contract. Since #592 both heroes ride on both branches (see backend
 * `ReaderJson`), so the client reads `originalHero` whatever the status. A stub
 * that omits it leaves the field undefined and the reader view's `hero` computed
 * throws on `.url` — the article then hangs on "Loading…" with empty content.
 * Kept in one place so every article spec forces the same, contract-true shape
 * and the six copies can never drift from the DTO one at a time again.
 */
export function readerFailedJson(reason: ReaderFailureReason = 'fetch') {
  return { status: 'failed' as const, url: null, reason, readerHero: null, originalHero: null };
}

/**
 * A `GET /api/saved-searches` body that matches the current contract.
 *
 * The store does not read a count off the wire — it derives one from
 * `unreadEntryIds` so a read entry drops the badge without another round-trip
 * (#645). A stub that still sends the old scalar `unreadCount` leaves that
 * array undefined, `savedSearches()` throws on `.reduce`, and the whole reader
 * shell renders empty — which reads as a layout bug three assertions later
 * rather than as a stale fixture (#718).
 *
 * Typed as `SavedSearchWire`, so the next field added to the DTO is a red
 * squiggle here instead of a runtime throw in whichever spec fills the array.
 * A spec that needs no saved searches keeps passing `[]` — that path never
 * touches the shape and never rots.
 */
export function savedSearchesJson(...savedSearches: SavedSearchWire[]) {
  return { savedSearches };
}

/** One saved search, contract-true, with the fields a spec cares about
 *  overridable. `unreadEntryIds` drives the sidebar badge. */
export function savedSearchWire(overrides: Partial<SavedSearchWire> = {}): SavedSearchWire {
  return {
    id: 1,
    term: 'number',
    wholeWord: false,
    phrase: false,
    position: 0,
    unreadEntryIds: [1, 2, 3, 4, 5],
    includeInDigest: false,
    ...overrides,
  };
}
