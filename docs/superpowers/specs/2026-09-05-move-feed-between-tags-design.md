# Move a feed between tags, don't duplicate it

Issue: #872
Date: 2026-09-05

## Problem

In the reader sidebar, dragging a feed from one tag onto another **adds** the
target tag while keeping the source tag. The feed then appears under **both**
tags. A drag between tags should **move** the feed: remove the tag it came
from, add the tag it lands on.

## Root cause

The drag path is frontend-only, and both drop handlers already discard the
source list.

`frontend/src/app/reader/sidebar/sidebar.component.ts`:

- `onDrop()` (a feed dropped into a tag's feed list) and `onTagHeadDrop()` (a
  feed dropped on a tag header) both call `assignOrClear(sub, target)`, passing
  only the **target** drop list.
- `assignOrClear()` builds the new tag set by **appending** the target to the
  feed's current tags (`tagIds = [...current, target.tag.id]`). It never removes
  the tag the feed was dragged **from**.

The backend already supports the fix and needs no change:

- `PATCH /api/subscriptions/{id}` (`SubscriptionController::update`) treats the
  request `tagIds` as the authoritative full set.
- `Service/Subscription/SubscriptionTagSync::sync()` diffs that set against the
  feed's current tags: it removes every tag not in the set and appends every new
  one. So the sidebar only needs to send the correct set.

Multi-tag membership stays a supported data model. `SubscriptionTag` carries an
independent `position` per tag ("a feed's order within one tag is independent of
its order within another"). Putting a feed in a **second** tag is done in
Settings → Organise (bulk add/remove tag, #659). The sidebar drag is a pure
move.

## Decisions

Confirmed with the user before this spec:

1. **Drag is a pure move.** Dragging a feed onto a tag removes the source tag and
   adds the target. The sidebar no longer offers "add to a second tag"; that is
   Settings → Organise.
2. **Dropping on the untagged "Feeds" bucket removes only the source tag.** A
   feed still in other tags stays tagged; a feed with no remaining tags becomes
   untagged.
3. **Dropping on a tag the feed already has removes the source tag (dedupe).**

## Behavior — one rule

All cases follow a single rule:

```
newTags = (current tags − source tag) + target tag
```

The sidebar emits a retag only when `newTags` differs from the current set
(the single no-op guard). The source tag is empty when the drag starts in the
untagged bucket; the target tag is empty when the drop lands on the untagged
bucket.

| Drag | current tags | result |
|---|---|---|
| News → Tech | {News, Sport} | {Tech, Sport} — moved |
| Untagged → Tech | {} | {Tech} — added, leaves the untagged list |
| News → Feeds | {News, Sport} | {Sport} — only the source tag removed |
| News → Feeds | {News} | {} — becomes untagged |
| News → Tech (feed already in both) | {News, Tech} | {Tech} — source removed, deduped |
| Untagged → Feeds | {} | {} — no-op |

This replaces the two current early-returns in `assignOrClear`
(`current.includes(target.tag.id)` and `current.length === 0`) with the single
"no-op when unchanged" guard.

## Architecture

`DropData` (sidebar.component.ts) already models a drop target as
`{ kind: 'tag'; tag } | { kind: 'untagged' }`. Both feed **source** lists bind a
`DropData` too: the tag feed list and the tag header bind `tagDrop(node.tag)`,
the untagged list binds `untaggedDrop`. So `event.previousContainer.data` at both
call sites is a `DropData` identifying the source.

The change is a single private method plus the two call sites that feed it the
source:

- Replace `assignOrClear(sub: SubscriptionDto, target: DropData)` with a method
  that also receives the source `DropData` — for example
  `moveBetween(sub, source, target)`. It computes `newTags` by the rule above and
  emits `retag` only when the set changes.
- `onDrop()` (line ~353): pass `event.previousContainer.data` as the source.
- `onTagHeadDrop()` (line ~316): pass `event.previousContainer.data` as the
  source.

Deriving a tag id from a `DropData`: `data.kind === 'tag' ? data.tag.id : null`.
This reads twice (source and target); a small local helper keeps the method a
single level of abstraction.

No change to `manage-actions.service.ts` — its `retag(sub, tagIds)` already
rewrites `sub.tags` to exactly `tagIds` and PATCHes that set. No change to
`ReaderApi`, `SubscriptionController`, `SubscriptionTagSync`, or the entities.

## Data flow

1. User drops a feed row on a tag header, a tag's feed list, or the untagged
   bucket.
2. CDK fires `onTagHeadDrop` or `onDrop`. Same-container drops stay reorders and
   are unaffected.
3. For a cross-list feed drop, the handler reads source =
   `event.previousContainer.data` and target = `event.container.data`, then calls
   the move method.
4. The move method computes `newTags` and, when it differs from the current set,
   emits `retag({ sub, tagIds: newTags })`.
5. `reader-shell.component.html` forwards to `manage.retag(sub, tagIds)`, which
   optimistically sets `sub.tags` and PATCHes `{ tagIds }`.
6. `SubscriptionTagSync::sync()` removes the dropped tag and adds the new one,
   each at its computed position.

## Testing

- **`frontend/src/app/reader/sidebar/sidebar.component.spec.ts`** — the drop
  cases carry the behavior. Cover: tag→tag move (source removed), multi-tag feed
  keeps its other tags, untagged→tag add, tag→Feeds removes only the source,
  tag→Feeds on a single-tag feed becomes untagged, drop on an already-present tag
  dedupes, and the no-op (unchanged set emits nothing). Assert the emitted
  `retag` payload (`tagIds`), and assert no emission for the no-op.
- **`manage-actions.service.spec.ts`** — unchanged; `retag` already treats
  `tagIds` as the authoritative set.
- **Backend** — `SubscriptionTagSync::sync()` removal is already covered; add a
  functional assertion only if a gap exists that a sidebar move would expose
  (the PATCH removing a tag). Not expected to need new code.
- `npm run check` green; sidebar unit tests green.

## Out of scope

- Adding a feed to a second tag from the sidebar (Settings → Organise, #659).
- Any backend, API, or data-model change.
- Optimistic-update flicker (#172, already handled by the `retag` reload path).
- Touch-drag ergonomics and the sidebar mobile redesign (#185).
