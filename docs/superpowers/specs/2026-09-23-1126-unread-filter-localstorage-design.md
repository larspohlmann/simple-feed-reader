# #1126 — Persist the unread filter in localStorage

## Problem

The "All posts / only unread" switch is stored in the URL as `?unread=1`. Every
navigation that does not merge the query string drops it. The saved-search links
(sidebar item, combined-list toggle, membership pills, the fallback after removing
the open saved search) navigate to `/searches/saved/…` without merging, so moving
to a saved search resets the filter to "all".

## Decision

The filter is a **device preference in `localStorage`**, not part of the URL.
The URL names the list; the preference refines it. One value applies to every
list that offers the switch.

- `?unread=1` is no longer read or written. An old bookmark carrying it is
  ignored (no migration).
- Lists without the switch (favorites, kept, viewed, direct search) keep showing
  everything and never change the stored preference, so the preference returns
  on the next list that offers the switch.
- Not synced to the account and not cleared on logout — it is a per-device view
  setting, like the reading layout.
- The backend `unread` parameter stays on `/api/entries`,
  `/api/entries/saved-searches` and `/api/entries/saved-searches/{id}`. It is
  **removed from `/api/entries/search`**: after this change no client sends it
  (see "Backend dead code removed").

## Design

### `UnreadFilterService` (`reader/unread-filter.service.ts`)

Root service in the `ReadingLayoutService` style:

- `readonly unreadOnly = signal<boolean>(…)`, seeded from `localStorage`.
- `set(unreadOnly: boolean)` writes `localStorage` and the signal.
- Key `sfr.unread-only`, value `'1'` / `'0'`. Anything else (missing, garbage)
  reads as `false` ("all").

### Selection

- `selectionFromParams` and `selectionFromRoute` stop reading `unread`; every
  selection they return has `unread: false`.
- New pure function in `query.ts`:
  `withUnreadPreference(selection, unreadOnly): Selection` — returns
  `{ ...selection, unread: unreadOnly }` when `hasUnreadFilter(selection)`,
  otherwise the selection unchanged.
- `ReaderShellComponent.selection` becomes
  `withUnreadPreference(parsed().selection, unreadFilter.unreadOnly())`, still
  with `equal: sameSelection`. The existing list-load effect tracks `selection`,
  so flipping the switch reloads the list without a navigation.
- `Selection.unread` stays: `queryFromSelection`, `sameSelection`,
  `ListScrollMemory` keys and the in-place hide on mark-read all keep reading it.

### The switch

`entry-list.component.html`: the `<a routerLink [queryParams]>` becomes a
`<button type="button" role="switch">` that emits a new output
`unreadOnlyChange = output<boolean>()` with `!selection().unread`. The shell binds
it to `unreadFilter.set($event)`. The `keydown.space` workaround goes away — a
button toggles on Space natively.

### Dead code removed

Everything that existed only because the filter lived in the URL:

- `selectionFromParams`: the `unread` parse and its comment; the comment claiming
  a saved search "never carries the unread refinement here".
- `selectionFromRoute`: the `unread` parse.
- `query.ts`: the `// Unread refines the selected list, so navigation does not
  clear it.` note and any comment explaining URL-level unread handling.
- `queryFromSelection` `'search'` case: the `unread` branch (a direct search never
  has the switch, and saved searches no longer route through `'search'`).
- `ReaderApi.searchEntries`: the `unread` parameter and its forwarding.
- `listSelectionFrom`: no longer carries unread — its spec case "keeps the
  unread refinement the search hid" goes.
- Specs asserting URL-level unread (`query.spec.ts`, `reader-matcher` specs,
  `reader-shell.component.spec.ts` driving `unread: '1'` through `queryParamMap`,
  the entry-list switch's `queryParams` assertion, the e2e `toHaveURL(/unread=1/)`).

### Backend dead code removed

The unread refinement on `GET /api/entries/search` existed only for the old
saved-search-as-`?q=` path. Remove it end to end:

- `EntrySearchRequestFactory`: `'unread'` leaves `ALLOWED_PARAMETERS`, and the
  private `unread()` reader goes. A request that still sends `unread` now gets
  the endpoint's standard 422 `Unknown parameter "unread"` — the factory's
  existing contract for anything it does not understand.
- `EntrySearchQuery`: the `$unread` property goes.
- `EntryScopePredicates::applySearch`: the unread branch goes.
- `IndexedEntrySearch`: `unreadOnly()` and the unread paragraph of the class
  docblock go; `rows` is the hydrated candidates.
- `EntrySearchResult::$continuationRow` goes. Its only purpose was to resume past
  read rows the unread filter dropped; without the filter the last candidate IS
  the last row. `EntryPage::withMatchCount()` loses the `$continuationRow`
  parameter and its docblock paragraph; `SearchPage` stops passing it.
  `$matchCount` stays — ghost ids dropped by hydration still need it.
- Tests: the unread cases in `EntrySearchRequestFactoryTest` (replaced by one
  that asserts `unread` is rejected as unknown), `IndexedEntrySearchTest`,
  `LikeEntrySearchTest`, `EntrySearchWithFallbackTest`,
  `EntrySearchControllerTest`, plus any `EntryPage` test of `$continuationRow`.

Not touched: `SearchMarkReadService` / `unreadMatchingEntryIdsForUser` (mark-read
of a search, independent of the list filter) and the saved-search endpoints'
`onlyUnread`.

## Testing

- `UnreadFilterService`: default false; `set` persists and updates the signal;
  a new instance reads the stored value back; an invalid stored value reads false.
- `withUnreadPreference`: applies to all, tag, subscription, for-you,
  saved-searches, saved-search; leaves favorites, kept, viewed, search at false.
- Shell: with the preference on, moving from a tag to a saved-search path keeps
  `selection().unread === true` and loads the saved search with `unread: true`
  (the reported bug). Flipping the switch reloads the list with no navigation.
- Entry list: clicking the switch emits `unreadOnlyChange` with the negated value.
- e2e `saved-searches-combined.spec.ts`: assert the list narrows after the click
  instead of the URL; add a check that the switch stays on across a navigation
  to a single saved search. Clear the stored key so the spec owns its state.
