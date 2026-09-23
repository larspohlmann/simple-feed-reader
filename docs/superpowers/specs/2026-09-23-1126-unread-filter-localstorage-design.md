# #1126 — Persist the unread filter in localStorage

## Problem

The "All posts / only unread" switch is stored in the URL as `?unread=1`. Every
navigation that does not merge the query string drops it. The saved-search links
(sidebar item, combined-list toggle, membership pills, the fallback after removing
the open saved search) navigate to `/searches/saved/…` without merging, so moving
to a saved search resets the filter to "all".

## Decision

The filter keeps working exactly as today; only **where its state lives**
changes. It moves from the URL to a **device preference in `localStorage`**. The
URL names the list; the preference refines it. One value applies to every list
that offers the switch, so it survives every navigation.

- `?unread=1` is no longer read or written. An old bookmark carrying it is
  ignored (no migration).
- **A direct search now offers the switch too**, so the filter holds across
  every browsable view. The search endpoint's existing `unread` parameter carries
  it; no backend change.
- Favorites, kept and viewed stay without the switch: each is already a filter on
  entry state, and "kept, but only unread" is not a list this reader offers. They
  show everything and never change the stored preference, so it returns on the
  next list.
- Not synced to the account and not cleared on logout — it is a per-device view
  setting, like the reading layout.

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
- `hasUnreadFilter` adds `kind === 'search'`.
- New pure function in `query.ts`:
  `withUnreadPreference(selection, unreadOnly): Selection` — returns
  `{ ...selection, unread: unreadOnly }` when `hasUnreadFilter(selection)`,
  otherwise the selection unchanged.
- `ReaderShellComponent.selection` becomes
  `withUnreadPreference(parsed().selection, unreadFilter.unreadOnly())`, still
  with `equal: sameSelection`. The existing list-load effect tracks `selection`,
  so flipping the switch reloads the list without a navigation.
- `Selection.unread` stays: `queryFromSelection` (all kinds, including `search`,
  which already forwards `unread: true`), `sameSelection`, `ListScrollMemory`
  keys and the in-place hide on mark-read all keep reading it.

### The switch

`entry-list.component.html`: the `<a routerLink [queryParams]>` becomes a
`<button type="button" role="switch">` that emits a new output
`unreadOnlyChange = output<boolean>()` with `!selection().unread`. The shell binds
it to `unreadFilter.set($event)`. The `keydown.space` workaround goes away — a
button toggles on Space natively.

### Dead code removed

Everything that existed only because the filter state lived in the URL:

- `selectionFromParams`: the `unread` parse and its comments, including the one
  forcing a direct search to `unread: false` and the one claiming a saved search
  "never carries the unread refinement here".
- `selectionFromRoute`: the `unread` parse.
- `query.ts`: the `// Unread refines the selected list, so navigation does not
  clear it.` note and every comment explaining URL-level unread handling
  (e.g. on `hasUnreadFilter` "a direct search is temporary", on the `'search'`
  case of `queryFromSelection` "only a saved-search result ever carries unread").
- `listSelectionFrom`: no longer carries unread — its spec case "keeps the
  unread refinement the search hid" goes.
- Specs asserting URL-level unread (`query.spec.ts`, `reader-matcher` specs,
  `reader-shell.component.spec.ts` driving `unread: '1'` through `queryParamMap`,
  the entry-list switch's `queryParams` assertion, the e2e `toHaveURL(/unread=1/)`).

The backend is unchanged.

## Testing

- `UnreadFilterService`: default false; `set` persists and updates the signal;
  a new instance reads the stored value back; an invalid stored value reads false.
- `withUnreadPreference`: applies to all, tag, subscription, for-you,
  saved-searches, saved-search, search; leaves favorites, kept, viewed at false.
- Shell: with the preference on, moving from a tag to a saved-search path keeps
  `selection().unread === true` and loads the saved search with `unread: true`
  (the reported bug). A direct search loads with `unread: true`. Flipping the
  switch reloads the list with no navigation. `?unread=1` in the URL has no effect.
- Entry list: the switch renders for a direct search; clicking it emits
  `unreadOnlyChange` with the negated value.
- e2e `saved-searches-combined.spec.ts`: assert the list narrows after the click
  instead of the URL; add a check that the switch stays on across a navigation
  to a single saved search. Clear the stored key so the spec owns its state.
