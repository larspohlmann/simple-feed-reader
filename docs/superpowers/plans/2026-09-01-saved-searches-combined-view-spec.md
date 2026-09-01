# Spec — Saved searches: a combined view (#769)

Clicking **Saved searches** in the sidebar only expands and collapses the child
list today. It should instead open a combined view of the matches of all saved
searches, in the same style as All items and the tag views, with the
"Mark all read" action and the "All posts / only unread" switch.

## Sidebar

- The label becomes a nav link (`<a [routerLink]="[]" [queryParams]="…">`, like
  every other nav row) and gets the `active` style when the combined view is on
  screen. It is currently a `<button>`.
- The chevron button keeps expand/collapse. No capability is lost.
- The header row stays hidden when the user has no saved search.
- The badge on the header row keeps its current value: the sum of the child
  counts. It is a known and accepted difference — a post that matches two
  searches counts twice there, but once in the view.

## The view

- Selection: new `kind: 'saved-searches'`, `id: null`. URL `?view=saved-searches`,
  plus `unread=1` when the switch is on — the For you encoding, because `view`
  must keep naming which list is shown. Default is "all posts".
- One flat stream, newest first, deduplicated: a post that matches several
  saved searches appears once.
- Every saved search is a member. No new flag. `includeInDigest` stays a mail
  setting and must not gain a second meaning.
- Feeds excluded from All items are **not** hidden: a search ignores
  `includeInAllItems` today (`EntryListRepository`), and the user chose these
  terms on purpose.
- No term marking in the rows, and no whole-word / phrase pills — those
  describe one term's match mode, and this view has many.
- Heading is the existing `reader.savedSearches` label, with the post count in
  the heading and the tab title (#709).
- No scoped refresh button and no "Last refreshed" label: no feed maps to this
  view and it has no single source.
- Empty state when the user has no saved search, or none matches. Deleting the
  last saved search while the view is open must leave a valid empty list, not
  an error.

## Recorded exception

`hasUnreadFilter()` excludes `kind: 'search'` on purpose (#710): a search
already filters on content, so a read-state filter would be a second,
conflicting answer. The combined view is an exception, and single searches keep
their current behaviour. The reason: this view is not a query the user just
typed, it is a standing list they keep, so read state is the correct second
axis.

## Backend

- `GET /api/entries/saved-searches` — same cursor and limit contract as the
  other list reads, same row shape. One query whose WHERE has the per-search
  term predicates OR'd together, so the cursor and the sort work unchanged.
  Do not collect ids per search and merge: that cannot page.
- `POST /api/entries/saved-searches/mark-read { until }` — marks every entry
  matching any saved search that is not newer than the watermark. It ignores
  the "only unread" switch: the switch chooses what is shown, not what is
  marked. One request, not one per saved search.
- Do not overload the single-term search endpoint with a "many terms" mode.

## i18n

Reuse `reader.savedSearches` for the heading. Add one key pair for the empty
state in `public/i18n/en.json` and `public/i18n/de.json`. The sidebar row keeps
its text.

## Tests

- Repository: the OR'd read across a page boundary via the cursor, and a post
  matching two saved searches listed once.
- Endpoints: list and mark-read, including the watermark behaviour.
- Frontend: query/selection round trip for `view=saved-searches` and `unread=1`;
  sidebar specs proving the label navigates and the chevron toggles.
- One Playwright smoke that stubs its own routes and owns its data.

