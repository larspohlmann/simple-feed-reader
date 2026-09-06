# A feed moved between tags honors the drop position

Issue: #874 (follow-up to #872)
Date: 2026-09-06

## Problem

Dragging a feed from one tag onto another shows a drop indicator, but the feed
always lands at the **bottom** of the target tag. The same happens on
Settings -> Organise. The indicated position is ignored.

## Root cause (both surfaces)

A drag is a *from-list -> to-list at an index* gesture, but the cross-tag path
models it as a tag-**set** replacement and discards `event.currentIndex`.

- Reader sidebar: `sidebar.component.ts` `moveBetweenTags` -> `ManageActions.retag(sub, tagIds)`
  -> `PATCH /api/subscriptions/{id}` with `tagIds` only.
- Organise: `organise/organise-tag-group.component.ts` `onFeedDropped` cross-group
  branch -> the same `retag` path.

The backend then appends: `Service/Subscription/SubscriptionTagSync::sync()` adds
a newly requested tag with `SubscriptionTagPositions::nextForTag()`, the end of
that tag's list.

Within-tag reorder already honors the index on both surfaces
(`PATCH /api/tags/{id}/feed-order`, `PATCH /api/subscriptions/reorder`); only the
cross-list move loses it.

## Decisions

- Backend fix: the drop index travels to the server and the feed is placed there
  atomically (chosen over a two-call frontend dance).
- A drop into the untagged "Feeds" list honors the index too (when the feed
  thereby becomes untagged).
- A drop on a collapsed tag header (no visible list) appends.

## Design

### New endpoint

`PATCH /api/subscriptions/{id}/move-to-tag`, body:

```
{ fromTagId: int|null, toTagId: int|null, position: int|null }
```

- `fromTagId` — the list the feed was dragged out of (null = the untagged list).
- `toTagId` — the list it was dropped on (null = the untagged list).
- `position` — the drop index within the destination list (`event.currentIndex`);
  null = append.

Request DTO `Dto/Subscription/MoveFeedToTagRequest` (nullable ints, positive).
The controller action stays thin: resolve the owned subscription, delegate to the
service, return the updated subscription JSON.

### Service `Service/Subscription/FeedTagMove`

`move(Subscription $subscription, ?Tag $fromTag, ?Tag $toTag, ?int $position, int $userId)`:

1. When `fromTag` is set, `removeTag(fromTag)`. The source tag keeps ascending
   order; a gap is harmless (display uses order, not contiguity).
2. Destination placement, densely renumbered so siblings shift:
   - `toTag` set: ensure the feed's `SubscriptionTag` join exists, then place it at
     `position` among that tag's feeds and renumber `0..n-1`. A feed already in the
     tag is repositioned, not duplicated.
   - `toTag` null and the feed now has no tags: place it at `position` among the
     user's untagged feeds and renumber `Subscription.position` `0..n-1`.
   - `toTag` null and the feed still has other tags: nothing to place (it only lost
     `fromTag`).
3. `position` null clamps to the end (append). Out-of-range positions clamp into
   `[0, count]`.

Placement reuses the "list the destination's current members in order, drop the
moved one, insert at the clamped index, renumber" shape the reorder endpoints
already apply. A repository accessor returns the destination's ordered members.

Errors: unknown/foreign subscription -> 404; foreign `fromTagId`/`toTagId` -> 422;
nothing is written in either case. JSON in, `application/problem+json` out, no
browser-only input — a native iOS client can drive it (`docs/architecture.md` §6).

The set-based `PATCH /api/subscriptions/{id}` (edit dialog, flag toggles) is
untouched.

### Frontend (both surfaces)

- `ReaderApi.moveFeedToTag(id, { fromTagId, toTagId, position })` -> the new PATCH.
- `ManageActions.moveFeedToTag(sub, fromTagId, toTagId, position)` — optimistic
  update of the feed's tags and the destination order, then reload. The backend
  owns placement, so there is no permutation/422 risk.
- Sidebar `onDrop` (into a tag feed list or the untagged list) passes
  `event.currentIndex`; `onTagHeadDrop` passes null. Organise `onFeedDropped`
  cross-list branch passes `event.currentIndex`; the header drop passes null.
- The set-based `moveBetweenTags` (sidebar) and `tagIdsAfterMove` (Organise) drag
  wiring is replaced by the move call. The `retag` set path remains for the edit
  dialog and flag toggles.

## Testing

- Backend unit (`FeedTagMove`): insert-at-index renumber, append (null position),
  dedupe (already in target), remove-source, untagged placement when the last tag
  is removed, out-of-range clamp.
- Backend functional (endpoint): a move places the feed at the index; a foreign
  tag id -> 422 and no write; an unknown subscription -> 404.
- Frontend Jest: the move call and payload on the sidebar and Organise drop
  handlers (tag list, untagged list, header = append, dedupe); `ReaderApi` and
  `ManageActions` method.
- Gates: `composer check`, `composer md`, `php bin/phpunit` (SQLite + MySQL),
  `npm run check`.

## Out of scope

- The edit-dialog tag multiselect and flag toggles (set-based, unchanged).
- Bulk tag add/remove in Organise's action bar (not a positioned drag).
