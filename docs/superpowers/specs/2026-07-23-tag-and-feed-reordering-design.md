# Tag & feed reordering (drag-and-drop, backend-persisted) — Design

**Goal:** Let the user reorder tags in the sidebar, and reorder feeds *within each
tag* (per-tag order) and within the untagged "Feeds" list, by drag-and-drop.
Order persists on the backend and syncs across devices / a future native client.

## Data model (proper, normalised — no JSON workaround)

Promote the `subscription_tag` many-to-many join to a first-class association
entity so it can carry a per-tag position.

- **New entity `SubscriptionTag`** — table `subscription_tag`, composite PK
  `(subscription_id, tag_id)`, plus `position INT NOT NULL`. Both FKs
  `onDelete: CASCADE` (a deleted feed or tag takes its join rows with it).
- **`Subscription`**: replace `#[ManyToMany] $tags` with
  `#[OneToMany] $subscriptionTags: Collection<SubscriptionTag>`.
  - `getTags(): Collection<Tag>` still works — derived from `$subscriptionTags`,
    ordered by `SubscriptionTag.position` — so existing read sites are untouched.
  - `getSubscriptionTags()` exposes the position-carrying rows (for the serializer).
  - `addTag(Tag, ?int position)` / `removeTag(Tag)` manage join rows; add appends
    at `max(position)+1` within that tag when no position given.
- **`Tag`**: add `position INT` — order of the tag in the sidebar list.
- **`Subscription`**: add `position INT` — order in the untagged "Feeds" list.

### The three orders
| List | Ordered by |
|------|-----------|
| Tags in the sidebar | `tag.position` |
| Feeds within a tag | `subscription_tag.position` (per-tag) |
| Feeds in the untagged "Feeds" list | `subscription.position` |

## Migration (SQLite dev/CI + MySQL prod)

One migration, portable across both engines (follow the existing platform-branch
style). Adds the three `position` columns, then backfills so the current display
order is preserved as the initial custom order:
- `tag.position`: per user, tags ordered by `name`.
- `subscription.position`: per user, subscriptions ordered by `createdAt, id`.
- `subscription_tag.position`: per tag, its subscriptions ordered by
  `subscription.createdAt, subscription.id`.

Backfill runs in PHP via `$this->connection` (portable; no window-function
dependency). Gets its own migration test (schema built from migrations, not
metadata — a broken migration must fail CI).

## Serialization

- `TagJson::one` gains `position` (= `tag.position`).
- `SubscriptionJson::one` gains top-level `position` (= `subscription.position`),
  and builds embedded tags from `getSubscriptionTags()` as
  `{id, name, color, icon, position}` where `position` = `SubscriptionTag.position`
  (this feed's order **within that tag**). It no longer routes embedded tags
  through `TagJson::one` (different `position` meaning).

## Repositories

- `TagRepository::findForUser` → order by `position ASC, name ASC`.
- `SubscriptionRepository`: the four `leftJoin/innerJoin('s.tags', 't')` sites
  become `join('s.subscriptionTags', 'st') join('st.tag', 't')`. Eager-load
  `st` so `getTags()`/serialisation don't N+1.
- New helpers: `maxPositionForUser` (tags, subscriptions), and per-tag max.
- New tag / new subscription / OPML-created tag & subscription get an appended
  `position`.

## Endpoints (all `PATCH`, bearer JWT, `application/problem+json` on error)

- `PATCH /api/tags/reorder` — body `{ "tagIds": [...] }`. Ids must be exactly the
  user's owned tag set; sets `tag.position = index`. Returns the ordered tags.
- `PATCH /api/subscriptions/reorder` — body `{ "subscriptionIds": [...] }`. Ids
  owned by the user; sets `subscription.position = index` (untagged order).
- `PATCH /api/tags/{id}/feed-order` — body `{ "subscriptionIds": [...] }`. The tag
  must be owned; each listed subscription must currently carry the tag; sets that
  tag's `SubscriptionTag.position = index`.

DTOs: `ReorderTagsRequest`, `ReorderSubscriptionsRequest`, `TagFeedOrderRequest`
(all `list<int>`, validated non-empty, ints).

## Frontend (Angular)

**Models**
- `TagDto` gains `position` (tag order).
- `SubscriptionDto` gains `position` (untagged order).
- `SubscriptionDto.tags: SubscriptionTagDto[]` where `SubscriptionTagDto` =
  `{id, name, color, icon, position}` and `position` = this feed's order within
  that tag. (Named distinctly from `TagDto` to avoid the two `position` meanings
  colliding.)

**Ordering (`subscriptions.store.ts`)**
- `buildTagTree(subs, tagOrder)` orders tag nodes by `tag.position` (via the
  ordered tag list from `TagsStore`), and orders each node's subscriptions by the
  embedded `SubscriptionTagDto.position`.
- `untaggedSubs` sorts by `subscription.position`.

**API + actions**
- `reader-api`: `reorderTags(ids)`, `reorderSubscriptions(ids)`,
  `setTagFeedOrder(tagId, ids)`.
- `ManageActions`: `reorderTags`, `reorderUntagged`, `reorderTagFeeds` — each
  PATCHes then reloads the affected store(s).

**Sidebar drag-and-drop** — three interactions coexisting via
`cdkDropListEnterPredicate` type-gates (so a mis-aimed drop is rejected, never
wrong):
- **Tag reorder**: tag nodes are `cdkDrag` inside a single tags `cdkDropList`,
  initiated from a **grip handle** (`drag_indicator`, hover-revealed) so it never
  conflicts with the tag link / expander / feed-drop-target. Sorting enabled.
  Predicate: tag drags only. On drop → emit `reorderTags(orderedIds)`.
- **Feed reorder**: feed lists (each tag's subs, and the untagged list) get
  sorting **enabled**. In `onDrop`, `previousContainer === container` now means
  *reorder* (emit `reorderTagFeeds(tagId, ids)` or `reorderUntagged(ids)`), while
  a different container still means assign/clear (the shipped retag feature).
  Predicate: feed drags only.
- Tags are dragged by their handle, feeds by their row → gestures never start
  ambiguously; the group's predicates keep the two item types in their own lists.

## Quality gates

Backend: `composer` cs + phpstan + phpmd (touched files clean) + PhpStorm
inspections + phpunit incl. the new migration test. Frontend: jest + `ng build`
+ eslint + stylelint. End-to-end via the Docker stack (watch backend dev.log).
Adversarial subagent review of the join-entity refactor and the reorder
endpoints before finishing.

## Out of scope (YAGNI)

Nesting/parenting tags; dragging a feed between two tags to reorder *and* retag in
one gesture (assign keeps append semantics); reordering the fixed All/Favorites/
Kept rows.
