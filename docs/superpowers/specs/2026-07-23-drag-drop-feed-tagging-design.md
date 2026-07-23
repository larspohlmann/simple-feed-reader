# Drag-and-drop feed tagging — Design

**Goal:** Let the user assign or clear a feed's tags by dragging the feed row in
the sidebar onto a tag (assign) or onto the "Feeds" section (clear).

## Interaction

- **Draggable:** every feed row in the sidebar — the untagged rows under "Feeds"
  and the rows nested under a tag. (Scope: *any* feed, not only untagged.)
- **Drop targets** (all auto-connected via one `cdkDropListGroup` on `<nav>`):
  - **A tag node** — the whole tag block (header + its sub rows) is one
    `cdkDropList`. Dropping a feed there **adds** that tag to the feed's existing
    tag set (a feed may hold several tags).
  - **The "Feeds" section** — one `cdkDropList`. Dropping a feed there **clears
    all** of its tags (the feed becomes untagged).
- **No-ops** (ignored, no request): dropping a feed on a tag it already has, or
  dropping an untagged feed back on "Feeds". Detected via
  `previousContainer === container` and the feed's current tag set.

## Always-available un-tag path

If every feed is tagged, the "Feeds" section would be empty and hidden, leaving
nowhere to drop to clear tags. So **while a drag is in progress**, an empty
"Feeds" drop zone is rendered with a hint ("Drop here to remove tags"). Outside a
drag, the section behaves as today (shown only when there are untagged feeds).

## Touch

`cdkDragStartDelay` ≈ 180 ms on touch so a normal swipe still scrolls the sidebar
and a short hold starts a drag; mouse drags start immediately. Row clicks (open
feed, row menu) keep working via CDK's built-in movement threshold.

## Feedback

The hovered drop target gets an accent outline, driven by a `dropHover` signal
set from `cdkDropListEntered` / `cdkDropListExited`. CDK's default drag preview
(a clone of the row) is used as-is.

## Data flow

Reuses the existing "dialog/action writes, then reload the store" pattern.

1. Sidebar `onDrop(event)` reads the dragged `SubscriptionDto` (`cdkDragData`)
   and the target's `DropData` (`cdkDropListData`), computes the new `tagIds`,
   and — only if it changed — emits `retag: { sub, tagIds }`.
2. Shell wires `(retag)` to `ManageActions.retag(sub, tagIds)`.
3. `retag` calls `PATCH /api/subscriptions/{id}` with
   `{ customTitle: sub.customTitle, tagIds }` (the endpoint replaces the whole
   tag set) and on success reloads `SubscriptionsStore`. No optimistic DOM move —
   the store reload re-renders the tag tree.

`DropData = { kind: 'tag'; tag: TagDto } | { kind: 'untagged' }`.

New-tagIds rule:
- target `tag`: `current.includes(tag.id)` → no-op; else `[...current, tag.id]`.
- target `untagged`: `current.length === 0` → no-op; else `[]`.

## Files

- `sidebar.component.ts` — CDK imports (`CdkDropListGroup`, `CdkDropList`,
  `CdkDrag`), drop lists on each tag node and on the Feeds section, `cdkDrag` on
  feed rows, `onDrop` / `dropHover` / `dragging` state, `retag` output, styles.
- `manage-actions.service.ts` — add `retag(sub, tagIds)`.
- `reader-shell.component.ts` — wire `(retag)`.
- Specs: sidebar (`onDrop` tagId computation + emit, no-op cases), manage-actions
  (`retag` calls the API and reloads).

## Out of scope (YAGNI)

Removing a single tag via drag (use Edit feed), reordering feeds, dragging tags
themselves, drag-and-drop of OPML/other entities.
