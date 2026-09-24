# #1143 — Per-view newest/oldest-first sort toggle

Settled in a grilling session on 2026-09-24. The issue body carries the same
decisions; this file adds what the code survey found afterwards.

## Decisions

- **Control:** a list-header action left of the unread switch, labelled with
  the current state — "Newest first" (`arrow_downward`) / "Oldest first"
  (`arrow_upward`), the Settings → Organise arrow convention. Labelled in the
  wide header, icon-only in the compact one (#1127, no exception). Flipping
  reloads the list from the top of the new order.
- **Views with the toggle:** All, each tag, each feed, Favourites, Kept,
  Recently read (read-time order), the combined saved-searches list, each saved
  search, direct search (one setting shared by every term). **Not** For You.
- **Order:** oldest first is the exact reverse of today — `effectiveDate ASC,
  id ASC`; Recently read `viewedAt ASC, id ASC`. New items land at the bottom.
- **Magazine:** order-agnostic. The first entry in display order leads the
  opener; the planner's "newest" assumptions become "first in display order".
- **Pull-to-refresh:** unchanged.
- **Storage:** `localStorage`, per user and per view; only oldest-first views
  are stored. Survives logout; deleting the account clears that account's
  values on the browser it was deleted from.
- **Identity:** a new `userId` JWT claim, read from the stored token at boot;
  a token issued before the deploy (7-day TTL) falls back to `/api/me`, and the
  first list load waits for it.
- **Unread filter:** moves onto the same per-user storage, still one value for
  all views. The old device-wide `sfr.unread-only` value is dropped (everyone
  starts at "All" once), not migrated.
- **API:** explicit `order=asc|desc` on `GET /api/entries`,
  `/api/entries/saved-searches[/{id}]` and `/api/entries/search`; default
  `desc`; any other value → 422 `validation_error` on `order`. No server-side
  sort state (native-client safe).
- **Mark-read:** "Mark everything above as read" is DOM-position based
  (`collectAboveFoldIds`), so it already follows the displayed order — pinned
  by an e2e in oldest first. "Mark all read" stays whole-view.

## Found during the code survey

- **Meilisearch search paging skips matches today.** The index keeps the
  default ranking rules `words, typo, proximity, attribute, sort, exactness`,
  so relevance picks each page before `sort` does. `IndexedEntrySearch`
  re-sorts the hydrated page by date and resumes past its oldest row, so every
  newer match that ranked lower is skipped for good. Measured on the dev index
  (2026-09-24): "climate change" skips 705 of 812 matches after page one,
  "berlin police" 143 of 244. Oldest-first search rides on the same mechanism,
  so the fix — `sort` first in `rankingRules` — is part of this branch. The UI
  already shows every page in date order, so no visible relevance is lost.
- **The magazine's collapse gate is not prefix-stable in ascending order.**
  `activeSourceCount` anchors its 24-hour window on the newest loaded entry; in
  oldest first that entry changes with every page, so a later page can switch
  collapse off and re-plan blocks the reader already scrolled past. The window
  anchors on the first entry in display order instead.
- **Stubbed e2e specs** that answer `/api/me` without an `id` would never load
  a list once the first load waits for the account; they gain `id: 1`.
