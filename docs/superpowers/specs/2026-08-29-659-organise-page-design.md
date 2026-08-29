# Organise: one page to arrange every feed and tag

Issue: [#659](https://github.com/larspohlmann/simple-feed-reader/issues/659).
Related: #688 / #695 (the two inclusion flags and `SubscriptionTagSync`),
#180 (settings extensibility), #541 / #547 (the settings design system),
#612 (collapsible sidebar sections), #128 (patch versus redesign).

This spec supersedes parts of the issue description. Section "Changes against
the issue" lists every difference.

## Problem

Feeds can only be managed one at a time. The sidebar row menu offers Edit and
Unsubscribe per feed, and the API has only `DELETE /api/subscriptions/{id}` and
`PATCH /api/subscriptions/{id}`. Cleaning up after an OPML import means twenty
confirm dialogs, and tagging fifteen feeds means fifteen dialog round trips.

The sidebar's Organise mode does carry the whole tag tree with drag-and-drop,
but it does it in a 280px column with no multi-select and no bulk write.

Measured scale on the development database: **176 subscriptions, 18 tags**. A
feed can carry more than one tag.

## Goals

- One settings page that shows the whole arrangement and changes many feeds at
  once.
- Multi-select with a bulk bar: add a tag, remove a tag, set the two inclusion
  flags, unsubscribe.
- Two bulk endpoints that flush once and reject a request that names a feed the
  caller does not own.
- No new tag-position rules. The bulk path calls the same `SubscriptionTagSync`
  the single-feed `PATCH` calls.

## Non-goals

- Select mode inside the sidebar.
- "Move to tag" as its own verb — add plus remove covers it.
- Bulk rename, bulk refresh, bulk export.
- Undo. Entries go with the subscription; a soft delete is not worth the schema.
- Deleting `settings/tags` or the sidebar's Organise mode. Both stay for now.
  The three surfaces coexist; one write path keeps them in step.

## Decisions

### The surface

A new settings section: path `organise`, label "Organise", icon `rss_feed`,
`wide: true`, first in the `general` group. It carries **feeds and tags**.

Two views behind one switch:

- **Tree** — tag groups, feeds inside, an "Untagged" group last. Groups are
  **closed** on load, the state persists under a key of its own (not the
  sidebar's). Expand all / Collapse all in the toolbar. A filter opens every
  group that has a match.
- **List** — one flat row per feed, sorted by title or by recently added. No
  handles and no arrows: order is a property of a group, and the flat list has
  no groups. Rows show their tag pills. A title filter and a multi-select tag
  filter with an "Untagged" entry.

The selection survives the view switch and every filter change.

### Selection

Keyed by **subscription id**. A feed that carries two tags renders in both
groups and both rows tick together. Tag group headers carry a tri-state
checkbox that selects the feeds of that group. "Select all" in the toolbar
takes every feed the filter currently shows, in open and closed groups,
counted once. The bulk bar names the hidden count: `7 selected (2 hidden by
the filter)`.

### Rows

| Row | Controls, left to right |
|---|---|
| Tag | chevron, tri-state checkbox, glyph, name, feed count, ▲, ▼, Edit, Delete |
| Feed | drag handle, checkbox, favicon, title, tag pills, ▲, ▼, Edit, `⋯` |

The `⋯` menu holds "Show/Hide in All items", "Show/Hide in For you" and
"Unsubscribe" — rare and destructive actions sit one step away. On a coarse
pointer it opens the existing action sheet.

Tag Edit opens `TagFormDialogComponent`, the dialog the sidebar already uses.
An inline editor here would be a third tag-edit implementation. Tag Create
sits in the page header next to "Add feed".

### Sorting

▲▼ on every tag row and every feed row, always visible and always enabled
except at the ends of a group, where they are disabled. They are the keyboard
path, the touch path and the path a Playwright test can drive.

Drag-and-drop is **pointer only**. Dragging a feed from tag A to tag B
**moves** it: B is added and A is removed. A held modifier copies. Dropping on
"Untagged" removes the tag it came from — which is the single-tag removal the
sidebar has never had.

> This differs from the sidebar, where a drop on a tag adds and never removes,
> and a drop on "Feeds" clears every tag. That behaviour is a consequence of
> the sidebar's narrow column. On a page that shows the whole arrangement, a
> drag from one group to another reads as "put it there".

### Bulk actions

The bar appears at one or more selected. It holds: the count, the hidden
count, **Add tag…**, **Remove tag…**, **Visibility ▾**, **Unsubscribe**,
**Clear**.

- **Add tag…** — a popover of every tag, each with `(n/N)` = how many of the
  selection already carry it. Adds one tag. It does not create tags.
- **Remove tag…** — a popover of only the tags the selection actually carries.
  It names how many feeds lose their last tag and move to Untagged.
- **Visibility ▾** — four explicit commands: Show in All items, Hide from All
  items, Show in For you, Hide from For you. Not a toggle: a toggle over a
  mixed selection has no correct starting position.
- **Unsubscribe** — always confirms. At five or more feeds the confirmation
  uses `ConfirmData.requireText` and the user types the count. The message
  names at most five titles, then "and N more".

### Writes

Not optimistic. A bulk write touches up to 176 rows across several groups;
rebuilding that in the client would be a second copy of the server's
tag-position rules, which is what `SubscriptionTagSync` exists to prevent. The
bar disables while the request is in flight and the store reloads from the
response.

Every write goes through `ManageActions` — the one place a management dialog
opens and its side effects apply. The new page holds no `ReaderApi` call. Its
own store holds the selection, the expanded groups, the view and the filters,
and nothing else.

Success: a toast with the count. The selection **stays** after a tag or flag
write, because tagging twelve feeds is usually followed by a flag change on the
same twelve. It clears after an unsubscribe, because the feeds are gone.

Failure: an error banner at the top of the page, the selection kept, and the
list reloaded so the stale row disappears.

### Phone

The tree, with drag-and-drop off and the arrows doing the sorting. The `⋯`
opens the action sheet. The bulk bar docks to the bottom edge. Drag-and-drop
inside a scrolling page fights the scroll — the sidebar needed a long-press
guard and a whole Organise mode to make it work; the arrows need neither.

### Visual treatment

Chosen from three options in the visual round: **the panel shape of the
settings design system, at the compact row density, with the bulk bar in the
flow rather than floating.**

- Panel per tag group, `--radius-lg`, `--panel-shadow` — the same shape as
  every other settings group, so eighteen closed tags read as a clean stack.
- Feed rows at `--row-pad-y` / `--row-pad-x` (compact), not the comfortable
  pair: panel padding would otherwise cost about 40% of the visible feeds.
- The bulk bar sits in the flow under the toolbar and pushes the list down. A
  floating bar would collide with the toast, which docks bottom-centre, and
  would cover the rows nearest the bottom.

A two-pane "rail and pane" layout was considered and rejected: it makes
retagging by drag easy but it never shows the whole arrangement, it adds a
third navigation model, and it must collapse to the panel layout on a phone
anyway.

## API

### `PATCH /api/subscriptions/bulk`

```json
{
  "subscriptionIds": [12, 44, 91],
  "addTagIds": [4],
  "removeTagIds": [7],
  "includeInAllItems": false,
  "includeInForYou": null
}
```

- `subscriptionIds` — required, 1 to 500 entries, no duplicates.
- `addTagIds`, `removeTagIds` — optional. An id in both is a contradiction.
- `includeInAllItems`, `includeInForYou` — optional. `null` means unchanged,
  matching `UpdateSubscriptionRequest` and `EntryController::updateState`.
- Answers `{"subscriptions": [...]}` with the updated feeds.
- `422` when any subscription id or tag id is not the caller's, when the id
  count is over 500, or when add and remove name the same tag. Nothing is
  written in any of those cases.

The tag change per feed becomes one call to `SubscriptionTagSync::sync` with
that feed's resulting tag id set, so the append-at-next-position rule and the
lost-last-tag rule stay in one place. One flush for the whole request.

### `POST /api/subscriptions/bulk-unsubscribe`

```json
{ "subscriptionIds": [12, 44, 91] }
```

Answers `{"removed": 3}`. Same ownership rule and the same 500 cap.
`OrphanedFeeds::reclaim` runs once per distinct feed **after** the single
flush, not inside the loop.

### The 500 cap

One validation attribute. It bounds one request's memory on the Strato host
(512 MB, 240 s) and no real selection reaches it. This is a bound on a request
body, not a guard against a rare failure mode.

## Changes against the issue

| The issue says | This spec says | Why |
|---|---|---|
| Section named "Feeds" | Named "Organise", path `organise` | It carries tags as well as feeds |
| A flat list only | Tree and List behind one switch | The tree is the arrangement; the list is the finder |
| No tag rows | Tag rows with reorder, edit, delete, create | The page is the arrangement, and tags are half of it |
| No sorting | ▲▼ everywhere, plus pointer drag-and-drop | Sorting is why the page exists; arrows are the accessible path |
| `PATCH /bulk-tags` | `PATCH /bulk`, tags and flags together | Per the issue's own comment on #688; `bulk-tags` would lie |
| No id cap | 500 ids | Bounds one request on the Strato host |
| Confirmation only | Confirmation, plus a typed count at five or more | A mass delete is what `requireText` was added for |
| Extract `SubscriptionTagSync` | Already done in #696 | Nothing to do |
| No add-feed path | "Add feed" in the page header | A manage page with no way to add is a hole |

## Risks

- **Three surfaces write the same data.** The sidebar Organise mode,
  `settings/tags` and this page. The single `ManageActions` write path is the
  only thing that keeps them in step; nothing enforces it mechanically.
- **A feed appears more than once in the tree.** Selecting it in one group
  ticks it in another, which is correct and may still surprise. The group
  header's mixed state must make it visible.
- **The drag semantics differ from the sidebar** (move here, add there). Both
  are defensible in their own surface; the difference is a real cost of
  coexistence.
- **Row count.** Eighteen open groups over 176 feeds with duplicates is roughly
  250 rows of about ten controls. Groups closed by default is the mitigation;
  virtual scrolling is not planned and would be a follow-up if it is needed.
