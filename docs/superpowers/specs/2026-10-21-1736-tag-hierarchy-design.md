# Tag hierarchy: one level of parent tags (#1736)

**Status:** approved design, 2026-10-21.
**Lands after:** #1735 (sidebar group lists), which changes the same four query sites.

## Goal

A tag may have a parent tag. Selecting a parent shows, counts, marks read and
refreshes the feeds of the parent itself and of all its children. The tree is one
level deep. The parent is chosen in the tag form; drag keeps its meaning of
reordering.

## Decisions

1. A parent's list, unread count, mark-read and refresh include its children's feeds.
2. One level. A child cannot have children; a tag with children cannot become a child.
3. A parent is a normal tag that may carry feeds of its own.
4. Tag names stay unique per user (`uniq_tag_user_name` unchanged).
5. Deleting a parent moves its children to the root level; the parent's own feeds
   become untagged, as today.
6. The parent is set in the tag form only. Drag reorders within one sibling set.
7. A drop target accepts a drag only when that drop is allowed, and only an accepting
   target highlights.
8. The scope rule lives in one predicate class applied at every query site; the wire
   keeps `tagId`.

## Backend

### Data model

`App\Entity\Tag` gains a nullable self-reference:

- `#[ORM\ManyToOne(targetEntity: self::class)]`, `#[ORM\JoinColumn(name: 'parent_id',
  nullable: true, onDelete: 'SET NULL')]`, property `private ?Tag $parent = null`,
  with `getParent(): ?Tag` and `setParent(?Tag $parent): void`.
- `position` orders a tag among its siblings: roots among roots, children within
  one parent. Every existing row is a root; no data migration.

One migration, `Version20261021120000`, MySQL and SQLite DDL like
`Version20261019130000`: add `parent_id INT DEFAULT NULL`, foreign key to `tag(id)`
`ON DELETE SET NULL`, index on `parent_id`. SQLite adds the column with an inline
`REFERENCES` clause. `down` drops the index, the constraint and the column. The
existing CI migration leg (migrate from empty on both platforms, then
`doctrine:schema:validate`) is the proof.

### Repository

`TagRepository` gains:

- `findChildren(Tag $parent): list<Tag>` in position, name order.
- `hasChildren(Tag $tag): bool`.

`findForUser` keeps its position, name order; the frontend builds the tree.

### Rules (`Service/Tag/TagEditor`)

`TagDetailsModel` gains `?int $parentId = null`. `CreateTagRequest` and
`UpdateTagRequest` gain `?int $parentId = null` with `#[Assert\Positive]` and pass it
through `toDetails()`.

Resolving a parent fails with the new `InvalidTagParentException` (in
`Service/Tag/Exception/`, carrying a message) when:

- no tag of the user has that id (`TagRepository::findOneForUser` returns null);
- the parent is itself a child (`getParent() !== null`);
- the parent is the tag being edited;
- the tag being edited has children (`hasChildren`) and a parent is requested.

`TagProblems` maps it to `422`, type `invalid_tag_parent`, title "Invalid parent tag",
the exception message as detail, and `errors: {parentId: [message]}` so the form can
show it under the field. Use `Response::HTTP_UNPROCESSABLE_ENTITY`.

`delete(Tag $tag)`: before removing, load `findChildren`, set each child's parent to
null and give it `nextPositionForUser() + index`, so detached children line up after
the last root. Then the existing carrier removal and `remove`.

### Ordering (`Service/Tag/TagOrdering`)

`PATCH /api/tags/reorder` keeps its route and body. The body must list exactly one
sibling set: all the user's roots, or all children of one parent. `reorder` takes the
first id's tag, selects the owned tags that share its parent id (null for roots) and
runs `ExactSetGuard::assertPermutation` against that set with the message
"tagIds must list exactly one tag and all of its siblings.". The response stays the
reordered tags.

### JSON

`TagJson::one` adds `'parentId' => $tag->getParent()?->getId()`. The embedded tags
in `SubscriptionJson` inherit the field through the spread; the frontend
`SubscriptionTagDto` does not declare it.

### Scoped queries

New `App\Repository\TagScopeDql`, final class with a private constructor like
`FeedGroupDql`:

- `predicate(string $subscriptionAlias, string $joinAlias, string $tagAlias): string`
  returns
  `EXISTS (SELECT IDENTITY(<join>.tag) FROM App\Entity\SubscriptionTag <join>
  JOIN <join>.tag <tag> WHERE <join>.subscription = <subscription>
  AND (<tag>.id = :tagId OR <tag>.parent = :tagId))`.
- `restrict(QueryBuilder $qb, string $subscriptionAlias, string $joinAlias,
  string $tagAlias, int $tagId): void` applies `andWhere(predicate)` and binds `:tagId`.

A semi-join, not a join: a feed carrying both a parent and its child must appear once.

Applied at the four sites:

1. `EntryScopePredicates::applyList`: replace the `joinTags` + tag-key equality with
   `TagScopeDql::restrict`. `EntryAliases` gains the two inner aliases the predicate
   needs (`primary()` and `collapse()` each supply distinct ones, so the collapse
   semi-join nests without alias clashes). `joinTags` stays for the counts.
2. `SubscriptionScopeRepository`: new `findForUserInTagScope(int $userId, int $tagId)`
   using `restrict`. `findForUserByTagId` stays exact and keeps serving
   `TagEditor::delete`. `MarkReadService` switches `ReadScopeKind::Tag` to the new
   method.
3. `FeedRepository::restrictToOwnership`: the inner `EXISTS … tst.tag = :tagId` becomes
   `TagScopeDql::predicate('ts', …)` nested inside the existing ownership `EXISTS`.
   `DueFeedCriteria`, `RefreshRequestModel`, `RefreshRunStore` and `UserRefreshScope`
   do not change.
4. `UnreadCountRepository::perTag` returns direct counts for children and a roll-up
   for roots: a second query joins `st.tag t` and `LEFT JOIN t.parent p`, groups by
   `COALESCE(p.id, t.id)` and counts `COUNT(DISTINCT e.id)`; the first query keeps
   the direct count but only for tags with a parent. The merged map is what
   `UnreadTotalsModel::$tags` and `UnreadTotalsJson` already carry. Verify the DQL
   on both platforms with the real statement, not a reduced probe.

The wire does not change: `GET /api/entries?tag=`, `POST /api/refresh?tag=` and the
mark-read `{"scope":"tag"}` body take a parent or a child id alike.

### OPML

Exporter: group subscriptions by root. A root outline holds its own feed outlines,
then one outline per child holding that child's feeds. A feed carrying both parent
and child appears under both, as any multi-tagged feed does today.

Importer: `BulkSubscribeItemModel` gains `?string $parentTagName = null`. A feed under
a top-level group gets that group as its tag; a feed under a nested group gets the
innermost group as its tag (today's rule, so re-importing today's files is unchanged)
and the top-level group as the parent. `BulkSubscriber` creates or reuses the parent
by name and sets the child's parent only when the one-level rule allows it: the
child has no children, the parent is a root. Otherwise the tag is kept and the
parent assignment is dropped silently; import stays lenient.

### Backup

`TagLine` and `BackupLines::tagLine` gain `parent` (the parent's name, or null).
`TagLine::fromLine` reads it with `LineFieldWithDefault`, null when absent, so files
written before this change restore every tag as a root. `BackupSchema::VERSION`
stays 3: `BackupReader` refuses any other version outright, and the field is
additive, so a bump would lock out every existing backup for nothing.
`AccountBackupExporter` writes roots before children (stable partition of
`findForUser`). `BackupTally::acceptTag` already keeps the declared names; it also
refuses, in pass 1, a tag whose parent name is not among them yet ("The backup names
parent tag "%s" before declaring it."). `RestoreLoadPass::loadTag` resolves the parent
from `tagsByName` and passes it to `RestoredFoundationFactory::tag`, which sets it on
the built tag.

### Unchanged

`AdminUserJson` (counts only), `SubscriptionTagSync` (a feed may carry parent and
child freely), `AccountWipeRepository` (the FK's `SET NULL` keeps a bulk delete valid;
the wipe test proves it).

## Frontend

### Models and stores

- `TagDto.parentId: number | null`; `TagInput.parentId: number | null`.
- `TagsStore` stays flat and position-ordered; adds `rootTags = computed(…)`.
- `subscription-derivations.ts`: `TagNode` gains `children: TagNode[]`.
  `buildTagTree` returns roots in position order, each with its children in position
  order. A child whose parent id is absent from the list renders as a root. A root's
  `unreadCount` is the API's roll-up, never a sum. A root's `entryCount` sums the
  distinct subscriptions of itself and its children. New `flattenTagTree(nodes)` for
  lookups by id: `ListHeadingService.selectedTagNode` and the reader shell's
  `headerTags`.

### Sidebar

Rendering, inside the existing `.tags` container, all drop lists as top-level
siblings:

1. root tag head (as today);
2. if the root is expanded: its own feed list (`.tagfeeds`), then per child a tag
   head with the `child` modifier, then, if that child is expanded, its feed list
   with the `child` modifier, indented one step further.

The parent's chevron hides both its feeds and its children. `expanded` keeps tag ids
and works for children unchanged. The feed row inside a child list gets the child's
`tagId`.

Drag:

- `acceptOnTagHead` becomes a predicate `(drag, list) => boolean`: a feed drag enters
  every tag head; a tag drag enters only a head whose tag shares the dragged tag's
  `parentId`. A rejected list never fires `entered`, so it never highlights.
- `onTagHeadDrop` for a tag drag builds the id list from the dragged tag's sibling set
  (roots, or the children of its parent) and calls `reorderTags` with it.
- A feed dropped on a child head appends the child tag, as on a root today.
- `.drop-line` and `.drop-active` keep their meaning; only accepting lists can show
  them.

Indentation uses the existing spacing tokens through a `child` modifier on
`.taghead` and `.tagfeeds` in `sidebar.component.scss`. `_sidebar-row.scss` keeps the
one-left-edge rule for names within a level.

### Tag form

`TagFormDialogComponent` gains a parent field: a native `<select>` inside
`app-field`, options "None" plus `TagsStore.rootTags` minus the tag itself. When the
tag has children (any tag in the store has `parentId === tag.id`) the select is
disabled and a hint says why. The body sends `parentId`. A 422 with
`errors.parentId` shows under the field, as the name error does. i18n keys:
`dialog.tagForm.parent`, `dialog.tagForm.noParent`,
`dialog.tagForm.parentLockedHasChildren`.

### Tag picker

Pills in tree order: each root followed by its children. A child pill reads
"Parent / Child". Used unchanged by add-feed, edit-subscription and the bulk tag
dialog, which pass tree-ordered tags.

### Organise page

`OrganiseStore.groups` lists groups in tree order; a child group carries its parent
and renders indented (`child` modifier on `app-organise-tag-group`). `canMoveTagUp`
and `canMoveTagDown` are true only within the sibling set. The gap drag computes gaps
over the sibling set only: `gapIndexAt` runs on the sibling range and a pointer
outside it yields no gap, so no indicator renders there and the drop is a no-op.
`reorderTags` receives the sibling set. The tag filter list follows tree order with
the same indent.

### Unchanged

`MarkReadActionsService`, `RefreshActionsService`, `Selection`, URL params, the
#1735 group lists, `SidebarRowActions`, the row menu.

## Testing

Backend: `TagEditorTest` (each parent rule; delete detaches children and positions
them after the last root), `TagOrderingTest` (sibling set passes, mixed set fails),
`EntryTagScopeTest` functional (parent lists own and children's entries; a feed
carrying parent and child appears once; a child lists only its own),
`UnreadCountRepositoryTest` (root roll-up distinct, child direct),
`SubscriptionScopeRepositoryTest`, `FeedRepositoryTagScopeTest` (due feeds),
`MarkReadService` functional, OPML export nests / import two levels / three levels
keep the innermost tag / conflicting existing tag keeps its state, backup round trip
with a parent, inspector refusal of an undeclared parent, a schema-3 line restores a
root. `composer check`, `composer md` on touched files, `composer infection:diff`.
Both suite legs (SQLite, MySQL).

Frontend: `subscription-derivations.spec` (tree, orphan child, entry roll-up,
flatten), `sidebar.component.spec` (children indented, predicate per drag kind,
sibling id list, no highlight on a rejected head), `tag-form-dialog.spec` (options,
locked with children, body, 422 under the field), `tag-picker.spec` (order, labels),
`organise.store.spec` (order, gap range, move buttons), `list-heading.service.spec`
(child found). `npm run check` in the frontend container.

## Out of scope

Arbitrary depth, drag-to-nest, per-parent name uniqueness, pure folder tags, a
native iOS surface for the tree (the wire already carries `parentId`).
