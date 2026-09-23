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
- The backend API parameter `unread` on `/api/entries*` is unchanged.

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
