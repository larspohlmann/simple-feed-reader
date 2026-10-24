# Drag a tag to nest, un-nest or move it between parents — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Dragging a tag in the sidebar or in /settings/organise reorders it, nests it under a top-level tag, un-nests it to a chosen top-level spot, or moves it to another parent at a chosen spot — by one rule on both screens.

**Architecture:** One pure resolver (`reader/tag-drop.ts`) turns the visible tag headers' boxes, the pointer's Y and the dragged tag into a `TagDrop` (new parent, full new sibling order, and where to draw the cue). One component-provided `TagDragSession` holds a drag (measured layout + pointer) and exposes the resolved drop as a signal. One `ManageActions.moveTag(tag, drop)` persists it. The sidebar and the organise page only measure their headers, feed the pointer in, and draw the cue the drop names. Tag drags no longer enter any CDK drop list; the header lists stay for feed drops.

**Tech Stack:** Angular 20 (standalone, signals), `@angular/cdk/drag-drop`, Jest (jsdom).

**Spec:** GitHub issue #1765 (the rule below is copied from it and is the contract).

## The rule (the resolver's contract)

Every tag header takes any tag.

- **Top or bottom edge** of a header: place the tag at that position in that header's sibling set, re-parenting as needed (reorder, nest, un-nest, move to another parent).
- **Middle** of a top-level header (the half between its top and bottom quarters): nest under it, placed last. A child header has no middle zone — it splits into halves.
- If a top-level header can't take the dragged tag as a child (it *is* the dragged tag, it is the tag's current parent, or the dragged tag has children), its middle splits into halves like a child header's.
- **Bottom edge of a parent whose children are shown** right below its header: the tag becomes its first child. Under a collapsed parent (or one without children) it means "after the parent" in the top-level order.
- **Below the last top-level block** (past `listBottom`): end of the top level.
- Pointer over no header (feeds, gaps between cards): the nearest header by distance decides, by the same zones.
- A tag **with children** only gets top-level positions; nothing nests two levels; a tag never becomes its own child. Such a drop resolves to `null` (no cue, nothing written).
- A drop that leaves parent and order unchanged resolves to `null`.

## Global Constraints

- Frontend only. `PATCH /api/tags/{id}` (`ReaderApi.updateTag`) and `PATCH /api/tags/reorder` (`ReaderApi.reorderTags`) already cover it; the backend puts a re-parented tag last and rejects two levels.
- `reorderTags` takes the **complete** sibling set of one parent (all roots, or all children of one parent).
- Organise under an active filter keeps tag dragging off (unchanged `dragDisabled`).
- Organise's move-up/down arrows stay a sibling swap (unchanged).
- Never nest `cdkDropList`s (CLAUDE.md gotcha).
- Hex colours, ad-hoc `px` spacing and media-query literals are forbidden in component `.scss`; use tokens (`var(--accent)`, `var(--accent-soft)`, `var(--space-*)`).
- Comments: default to none; one line; only when a reader would get the code wrong without it.
- Commit format `type(#1765): …`, no Claude attribution.
- Frontend tests run in the container: `docker compose exec -T frontend npx jest <path>`; the gate is `docker compose exec -T frontend npm run check`, plus `npm run build` (check misses template errors).

## File structure

| File | Responsibility |
|---|---|
| Create `frontend/src/app/reader/tag-drop.ts` | Pure resolver: types `TagHeaderBox`, `TagDropLayout`, `TagDropMark`, `TagDrop`; `resolveTagDrop()` |
| Create `frontend/src/app/reader/tag-drop.spec.ts` | One test per rule line |
| Create `frontend/src/app/reader/tag-drag-session.ts` | `TagDragSession` (component-provided): start/move/end, `drop` signal; `measureTagLayout()` |
| Create `frontend/src/app/reader/tag-drag-session.spec.ts` | Origin offset, end clears, measuring |
| Modify `frontend/src/app/reader/feeds/manage/manage-actions.service.ts` | `moveTag(tag, drop)`; `reorderTags` optimistic body extracted |
| Modify `…/manage-actions.service.spec.ts` | `moveTag` same-parent / re-parent / failure reload |
| Modify `frontend/src/app/reader/shell/sidebar/sidebar.component.{ts,html,scss,spec.ts}` | Tag drags through the session; header lists feed-only; cues |
| Modify `frontend/src/app/settings/organise/organise-section.component.{ts,html,spec.ts}` | Tag drags through the session; gap/nest cues from the drop |
| Modify `frontend/src/app/settings/organise/organise-tag-group.component.{ts,html,scss}` | `nestTarget` input, `headElement` query |
| Delete `frontend/src/app/settings/organise/tag-drop-gap.ts`, `tag-drop-gap.spec.ts`, `tag-drag-gap.service.ts`, `tag-drag-gap.service.spec.ts` | Replaced by the shared session |

---

### Task 1: The resolver

**Files:**
- Create: `frontend/src/app/reader/tag-drop.ts`
- Test: `frontend/src/app/reader/tag-drop.spec.ts`

**Interfaces:**
- Consumes: `TagDto` (`reader/models.ts`), `rootsOf`, `childrenByParentId` (`reader/state/subscription-derivations.ts`), `moveItemInArray` (CDK).
- Produces:
  ```ts
  export interface TagHeaderBox { readonly tagId: number; readonly top: number; readonly bottom: number }
  export interface TagDropLayout { readonly headers: readonly TagHeaderBox[]; readonly listBottom: number }
  export type TagDropMark =
    | { readonly kind: 'nest'; readonly tagId: number }
    | { readonly kind: 'before'; readonly tagId: number }
    | { readonly kind: 'after'; readonly tagId: number };
  export interface TagDrop { readonly parentId: number | null; readonly order: readonly number[]; readonly mark: TagDropMark }
  export function resolveTagDrop(dragged: TagDto, tags: readonly TagDto[], layout: TagDropLayout, pointerY: number): TagDrop | null
  ```
  `headers` are in visual order; `tags` in position order (as `TagsStore.tags()` holds them). `mark` names where the cue goes: `nest` highlights that header, `before` draws a line above that tag's block, `after` below it.

- [ ] **Step 1: Write the failing tests**

Fixture: roots A(1), B(2), C(3); B has children B1(21), B2(22). Header boxes 40 tall, 10 apart, in visual order A, B, B1, B2, C; `listBottom` = C's bottom.

```ts
import { TagDto } from './models';
import { TagDropLayout, resolveTagDrop } from './tag-drop';

const tag = (id: number, parentId: number | null = null, position = 0): TagDto => ({
  id, name: `t${id}`, color: null, icon: null, position, parentId,
});
const A = tag(1, null, 0);
const B = tag(2, null, 1);
const C = tag(3, null, 2);
const B1 = tag(21, 2, 0);
const B2 = tag(22, 2, 1);
const TAGS = [A, B, C, B1, B2];

/** Each header 40 tall starting at index * 50: A 0–40, B 50–90, B1 100–140, B2 150–190, C 200–240. */
function layout(ids: number[] = [1, 2, 21, 22, 3]): TagDropLayout {
  const headers = ids.map((tagId, index) => ({ tagId, top: index * 50, bottom: index * 50 + 40 }));
  return { headers, listBottom: headers[headers.length - 1].bottom };
}

describe('resolveTagDrop', () => {
  it('reorders top-level tags from a top edge', () => {
    expect(resolveTagDrop(C, TAGS, layout(), 2)).toEqual({
      parentId: null, order: [3, 1, 2], mark: { kind: 'before', tagId: 1 },
    });
  });

  it('nests a childless top-level tag from the middle of another, placed last', () => {
    expect(resolveTagDrop(C, TAGS, layout(), 70)).toEqual({
      parentId: 2, order: [21, 22, 3], mark: { kind: 'nest', tagId: 2 },
    });
  });

  it('nests under a top-level tag that has no children yet', () => {
    expect(resolveTagDrop(C, TAGS, layout(), 20)).toEqual({
      parentId: 1, order: [3], mark: { kind: 'nest', tagId: 1 },
    });
  });

  it('makes the bottom edge of a parent with shown children its first child', () => {
    expect(resolveTagDrop(A, TAGS, layout(), 88)).toEqual({
      parentId: 2, order: [1, 21, 22], mark: { kind: 'before', tagId: 21 },
    });
  });

  it('places after a collapsed parent at the top level from its bottom edge', () => {
    expect(resolveTagDrop(A, TAGS, layout([1, 2, 3]), 88)).toEqual({
      parentId: null, order: [2, 1, 3], mark: { kind: 'after', tagId: 2 },
    });
  });

  it('reorders children inside one parent', () => {
    expect(resolveTagDrop(B2, TAGS, layout(), 105)).toEqual({
      parentId: 2, order: [22, 21], mark: { kind: 'before', tagId: 21 },
    });
  });

  it('un-nests a child to a chosen top-level spot', () => {
    expect(resolveTagDrop(B1, TAGS, layout(), 2)).toEqual({
      parentId: null, order: [21, 1, 2, 3], mark: { kind: 'before', tagId: 1 },
    });
  });

  it("un-nests a child from its own parent's top edge", () => {
    expect(resolveTagDrop(B1, TAGS, layout(), 52)).toEqual({
      parentId: null, order: [1, 21, 2, 3], mark: { kind: 'before', tagId: 2 },
    });
  });

  it('splits its own parent into halves instead of nesting again', () => {
    expect(resolveTagDrop(B2, TAGS, layout(), 75)).toEqual({
      parentId: 2, order: [22, 21], mark: { kind: 'before', tagId: 21 },
    });
  });

  it('moves a child to another parent, placed last, from that parent\'s middle', () => {
    const tags = [...TAGS, tag(11, 1, 0)];
    expect(resolveTagDrop(B1, tags, layout([1, 11, 2, 21, 22, 3]), 20)).toEqual({
      parentId: 1, order: [11, 21], mark: { kind: 'nest', tagId: 1 },
    });
  });

  it('moves a child to another parent at a chosen position', () => {
    const tags = [...TAGS, tag(11, 1, 0)];
    expect(resolveTagDrop(B1, tags, layout([1, 11, 2, 21, 22, 3]), 52)).toEqual({
      parentId: 1, order: [21, 11], mark: { kind: 'before', tagId: 11 },
    });
  });

  it('nests a top-level tag at a chosen position among children', () => {
    expect(resolveTagDrop(C, TAGS, layout(), 155)).toEqual({
      parentId: 2, order: [21, 3, 22], mark: { kind: 'before', tagId: 22 },
    });
  });

  it('places after the last child from its bottom edge', () => {
    expect(resolveTagDrop(A, TAGS, layout(), 185)).toEqual({
      parentId: 2, order: [21, 22, 1], mark: { kind: 'after', tagId: 22 },
    });
  });

  it('puts the tag at the end of the top level below the last block', () => {
    expect(resolveTagDrop(B1, TAGS, layout(), 300)).toEqual({
      parentId: null, order: [1, 2, 3, 21], mark: { kind: 'after', tagId: 3 },
    });
  });

  it('lets the nearest header decide when the pointer is over none', () => {
    // 46 is 4 px above B's top and 6 px below A's foot, so B's top edge applies.
    expect(resolveTagDrop(C, TAGS, layout(), 46)).toEqual({
      parentId: null, order: [1, 3, 2], mark: { kind: 'before', tagId: 2 },
    });
  });

  it('keeps a tag with children at the top level: no nest zone, no child slot', () => {
    expect(resolveTagDrop(B, TAGS, layout(), 5)).toEqual({
      parentId: null, order: [2, 1, 3], mark: { kind: 'before', tagId: 1 },
    });
    expect(resolveTagDrop(B, TAGS, layout(), 155)).toBeNull();
  });

  it('never makes a tag its own child', () => {
    expect(resolveTagDrop(B, TAGS, layout(), 105)).toBeNull();
  });

  it('resolves a drop that changes nothing to null', () => {
    expect(resolveTagDrop(A, TAGS, layout(), 2)).toBeNull();
    expect(resolveTagDrop(A, TAGS, layout(), 38)).toBeNull();
    expect(resolveTagDrop(B1, TAGS, layout(), 120)).toBeNull();
  });
});
```

- [ ] **Step 2: Run to verify they fail**

Run: `docker compose exec -T frontend npx jest src/app/reader/tag-drop.spec.ts`
Expected: FAIL — `Cannot find module './tag-drop'`.

- [ ] **Step 3: Implement**

```ts
import { moveItemInArray } from '@angular/cdk/drag-drop';
import { TagDto } from './models';
import { childrenByParentId, rootsOf } from './state/subscription-derivations';

export interface TagHeaderBox {
  readonly tagId: number;
  readonly top: number;
  readonly bottom: number;
}

/** Headers in visual order; `listBottom` is the foot of the last top-level block. */
export interface TagDropLayout {
  readonly headers: readonly TagHeaderBox[];
  readonly listBottom: number;
}

export type TagDropMark =
  | { readonly kind: 'nest'; readonly tagId: number }
  | { readonly kind: 'before'; readonly tagId: number }
  | { readonly kind: 'after'; readonly tagId: number };

/** Where a dragged tag lands: its new parent, that parent's full new child order, and the cue to draw. */
export interface TagDrop {
  readonly parentId: number | null;
  readonly order: readonly number[];
  readonly mark: TagDropMark;
}

interface Slot {
  readonly parentId: number | null;
  readonly beforeId: number | null;
  readonly mark: TagDropMark;
}

const NEST_FROM = 0.25;
const NEST_TO = 0.75;

export function resolveTagDrop(
  dragged: TagDto,
  tags: readonly TagDto[],
  layout: TagDropLayout,
  pointerY: number,
): TagDrop | null {
  const tree = new TagTree(tags);
  const slot = slotAt(dragged, tree, layout, pointerY);
  if (slot === null || !tree.mayLandUnder(dragged, slot.parentId)) return null;
  const order = tree.orderWith(dragged, slot);
  if (slot.parentId === dragged.parentId && sameOrder(order, tree.siblingIds(slot.parentId))) {
    return null;
  }
  return { parentId: slot.parentId, order, mark: slot.mark };
}

function slotAt(dragged: TagDto, tree: TagTree, layout: TagDropLayout, pointerY: number): Slot | null {
  if (pointerY > layout.listBottom) return tree.topLevelEnd();
  const index = nearestHeaderIndex(layout.headers, pointerY);
  if (index < 0) return null;
  const header = layout.headers[index];
  const anchor = tree.byId(header.tagId);
  if (anchor === undefined) return null;
  const share = (pointerY - header.top) / Math.max(header.bottom - header.top, 1);

  if (anchor.parentId === null && tree.mayNest(dragged, anchor) && share >= NEST_FROM && share < NEST_TO) {
    return { parentId: anchor.id, beforeId: null, mark: { kind: 'nest', tagId: anchor.id } };
  }
  if (share < 0.5) {
    return { parentId: anchor.parentId, beforeId: anchor.id, mark: { kind: 'before', tagId: anchor.id } };
  }
  const next = layout.headers[index + 1];
  const firstChild = next === undefined ? undefined : tree.byId(next.tagId);
  if (firstChild !== undefined && firstChild.parentId === anchor.id) {
    return { parentId: anchor.id, beforeId: firstChild.id, mark: { kind: 'before', tagId: firstChild.id } };
  }
  return {
    parentId: anchor.parentId,
    beforeId: tree.nextSiblingId(anchor),
    mark: { kind: 'after', tagId: anchor.id },
  };
}

function nearestHeaderIndex(headers: readonly TagHeaderBox[], pointerY: number): number {
  let nearest = -1;
  let nearestDistance = Infinity;
  headers.forEach((header, index) => {
    const distance = Math.max(header.top - pointerY, pointerY - header.bottom, 0);
    if (distance < nearestDistance) {
      nearest = index;
      nearestDistance = distance;
    }
  });
  return nearest;
}

function sameOrder(left: readonly number[], right: readonly number[]): boolean {
  return left.length === right.length && left.every((id, index) => id === right[index]);
}

class TagTree {
  private readonly tagsById: ReadonlyMap<number, TagDto>;
  private readonly roots: readonly TagDto[];
  private readonly children: ReadonlyMap<number, TagDto[]>;

  constructor(tags: readonly TagDto[]) {
    this.tagsById = new Map(tags.map((tag) => [tag.id, tag]));
    this.roots = rootsOf(tags);
    this.children = childrenByParentId(tags);
  }

  byId(id: number): TagDto | undefined {
    return this.tagsById.get(id);
  }

  siblingIds(parentId: number | null): number[] {
    const siblings = parentId === null ? this.roots : (this.children.get(parentId) ?? []);
    return siblings.map((sibling) => sibling.id);
  }

  hasChildren(tag: TagDto): boolean {
    return (this.children.get(tag.id) ?? []).length > 0;
  }

  mayNest(dragged: TagDto, parent: TagDto): boolean {
    return parent.id !== dragged.id && parent.id !== dragged.parentId && !this.hasChildren(dragged);
  }

  mayLandUnder(dragged: TagDto, parentId: number | null): boolean {
    return parentId === null || (parentId !== dragged.id && !this.hasChildren(dragged));
  }

  nextSiblingId(tag: TagDto): number | null {
    const ids = this.siblingIds(tag.parentId);
    return ids[ids.indexOf(tag.id) + 1] ?? null;
  }

  topLevelEnd(): Slot | null {
    const last = this.roots[this.roots.length - 1];
    return last === undefined ? null : { parentId: null, beforeId: null, mark: { kind: 'after', tagId: last.id } };
  }

  /** The target set's ids with the dragged tag moved into the slot; `beforeId` is read against the set as it is now. */
  orderWith(dragged: TagDto, slot: Slot): number[] {
    const ids = this.siblingIds(slot.parentId);
    const gap = slot.beforeId === null ? ids.length : ids.indexOf(slot.beforeId);
    const from = ids.indexOf(dragged.id);
    if (from < 0) {
      ids.splice(gap, 0, dragged.id);
      return ids;
    }
    moveItemInArray(ids, from, gap > from ? gap - 1 : gap);
    return ids;
  }
}
```

If PHPMD-style length matters here it doesn't (TS), but keep `slotAt` readable; if ESLint flags complexity, extract the "lower half" branch into `slotBelow(anchor, next, tree)`.

- [ ] **Step 4: Run to verify they pass**

Run: `docker compose exec -T frontend npx jest src/app/reader/tag-drop.spec.ts`
Expected: PASS (all).

- [ ] **Step 5: Commit**

```bash
git add frontend/src/app/reader/tag-drop.ts frontend/src/app/reader/tag-drop.spec.ts
git commit -m "feat(#1765): one resolver for where a dragged tag lands"
```

### Task 2: The drag session

**Files:**
- Create: `frontend/src/app/reader/tag-drag-session.ts`
- Test: `frontend/src/app/reader/tag-drag-session.spec.ts`

**Interfaces:**
- Consumes: `resolveTagDrop`, `TagDrop`, `TagDropLayout`, `TagHeaderBox` (Task 1); `TagsStore` (`reader/state/tags.store.ts`).
- Produces:
  ```ts
  export interface TagHeaderElement { readonly tagId: number; readonly element: Element }
  export function measureTagLayout(headers: readonly TagHeaderElement[], listEnd: Element | undefined, originTop: number): TagDropLayout
  @Injectable() export class TagDragSession {
    readonly drop: Signal<TagDrop | null>;
    start(dragged: TagDto, layout: TagDropLayout): void;  // layout relative to the origin
    move(offsetY: number): void;                          // pointer Y relative to the origin
    end(): { readonly tag: TagDto; readonly drop: TagDrop } | null;  // clears the session
  }
  ```

- [ ] **Step 1: Write the failing tests**

```ts
import { TestBed } from '@angular/core/testing';
import { signal } from '@angular/core';
import { TagsStore } from './state/tags.store';
import { TagDto } from './models';
import { TagDragSession, measureTagLayout } from './tag-drag-session';

const tag = (id: number, position: number): TagDto => ({
  id, name: `t${id}`, color: null, icon: null, position, parentId: null,
});
const A = tag(1, 0);
const B = tag(2, 1);
const LAYOUT = {
  headers: [{ tagId: 1, top: 0, bottom: 40 }, { tagId: 2, top: 50, bottom: 90 }],
  listBottom: 90,
};

describe('TagDragSession', () => {
  let session: TagDragSession;

  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [TagDragSession, { provide: TagsStore, useValue: { tags: signal([A, B]) } }],
    });
    session = TestBed.inject(TagDragSession);
  });

  it('resolves nothing before the pointer moves', () => {
    session.start(B, LAYOUT);
    expect(session.drop()).toBeNull();
  });

  it('resolves the drop under the pointer', () => {
    session.start(B, LAYOUT);
    session.move(2);
    expect(session.drop()).toEqual({ parentId: null, order: [2, 1], mark: { kind: 'before', tagId: 1 } });
  });

  it('hands the tag and its drop over on end and clears itself', () => {
    session.start(B, LAYOUT);
    session.move(2);
    expect(session.end()).toEqual({ tag: B, drop: expect.objectContaining({ order: [2, 1] }) });
    expect(session.drop()).toBeNull();
    expect(session.end()).toBeNull();
  });

  it('ends with null when the drop changes nothing', () => {
    session.start(B, LAYOUT);
    session.move(70);
    expect(session.end()).toBeNull();
  });
});

describe('measureTagLayout', () => {
  const box = (top: number, height: number): Element =>
    ({ getBoundingClientRect: () => ({ top, bottom: top + height, height }) as DOMRect }) as Element;

  it('measures headers and the list foot relative to the origin', () => {
    expect(measureTagLayout([{ tagId: 1, element: box(110, 40) }], box(100, 300), 100)).toEqual({
      headers: [{ tagId: 1, top: 10, bottom: 50 }],
      listBottom: 300,
    });
  });

  it('falls back to the last header when there is no list foot', () => {
    expect(measureTagLayout([{ tagId: 1, element: box(110, 40) }], undefined, 100).listBottom).toBe(50);
  });
});
```

- [ ] **Step 2: Run to verify they fail**

Run: `docker compose exec -T frontend npx jest src/app/reader/tag-drag-session.spec.ts`
Expected: FAIL — module not found.

- [ ] **Step 3: Implement**

```ts
import { Injectable, computed, inject, signal } from '@angular/core';
import { TagDto } from './models';
import { TagsStore } from './state/tags.store';
import { TagDrop, TagDropLayout, TagHeaderBox, resolveTagDrop } from './tag-drop';

export interface TagHeaderElement {
  readonly tagId: number;
  readonly element: Element;
}

interface TagDrag {
  readonly tag: TagDto;
  readonly layout: TagDropLayout;
}

/** Measured once per drag: a re-measure mid-drag would read the cue's own layout shift. */
export function measureTagLayout(
  headers: readonly TagHeaderElement[],
  listEnd: Element | undefined,
  originTop: number,
): TagDropLayout {
  const boxes: TagHeaderBox[] = headers.map(({ tagId, element }) => {
    const rect = element.getBoundingClientRect();
    return { tagId, top: rect.top - originTop, bottom: rect.bottom - originTop };
  });
  const listBottom =
    listEnd === undefined
      ? (boxes[boxes.length - 1]?.bottom ?? 0)
      : listEnd.getBoundingClientRect().bottom - originTop;
  return { headers: boxes, listBottom };
}

@Injectable()
export class TagDragSession {
  private readonly tags = inject(TagsStore);
  private readonly drag = signal<TagDrag | null>(null);
  private readonly offsetY = signal<number | null>(null);

  readonly drop = computed<TagDrop | null>(() => {
    const drag = this.drag();
    const offsetY = this.offsetY();
    if (drag === null || offsetY === null) return null;
    return resolveTagDrop(drag.tag, this.tags.tags(), drag.layout, offsetY);
  });

  start(tag: TagDto, layout: TagDropLayout): void {
    this.drag.set({ tag, layout });
    this.offsetY.set(null);
  }

  move(offsetY: number): void {
    this.offsetY.set(offsetY);
  }

  end(): { readonly tag: TagDto; readonly drop: TagDrop } | null {
    const tag = this.drag()?.tag;
    const drop = this.drop();
    this.drag.set(null);
    this.offsetY.set(null);
    return tag === undefined || drop === null ? null : { tag, drop };
  }
}
```

(Check that `TagsStore.tags` is a `WritableSignal<TagDto[]>` — it is, `ManageActions` calls `this.tags.tags.update`.)

- [ ] **Step 4: Run to verify they pass** — same command, PASS.

- [ ] **Step 5: Commit**

```bash
git add frontend/src/app/reader/tag-drag-session.ts frontend/src/app/reader/tag-drag-session.spec.ts
git commit -m "feat(#1765): a tag drag session both screens share"
```

### Task 3: `ManageActions.moveTag`

**Files:**
- Modify: `frontend/src/app/reader/feeds/manage/manage-actions.service.ts` (around `reorderTags`, ~line 149)
- Test: `frontend/src/app/reader/feeds/manage/manage-actions.service.spec.ts`

**Interfaces:**
- Consumes: `TagDrop` (Task 1); `ReaderApi.updateTag(id, TagInput)`, `ReaderApi.reorderTags(ids)`.
- Produces: `moveTag(tag: TagDto, drop: TagDrop): void`.

- [ ] **Step 1: Write the failing tests** — read the spec file's existing `reorderTags` tests first and follow their setup (API mock, `TagsStore` stub). Add:

```ts
describe('moveTag', () => {
  const drop = (parentId: number | null, order: number[]): TagDrop => ({
    parentId, order, mark: { kind: 'before', tagId: order[0] },
  });

  it('only reorders when the parent stays', () => {
    // tags: A(1, root), B(2, root)
    service.moveTag(B, drop(null, [2, 1]));
    expect(api.reorderTags).toHaveBeenCalledWith([2, 1]);
    expect(api.updateTag).not.toHaveBeenCalled();
  });

  it('re-parents, then writes the new sibling order, then reloads tags and feeds', () => {
    service.moveTag(B, drop(1, [2]));
    expect(api.updateTag).toHaveBeenCalledWith(2, { name: B.name, color: B.color, icon: B.icon, parentId: 1 });
    expect(api.reorderTags).toHaveBeenCalledWith([2]);
    expect(tagsStore.load).toHaveBeenCalled();
    expect(subscriptionsStore.load).toHaveBeenCalled();
  });

  it('shows the new parent at once', () => {
    service.moveTag(B, drop(1, [2]));
    expect(tagsStore.tags().find((tag) => tag.id === 2)).toEqual(expect.objectContaining({ parentId: 1, position: 0 }));
  });

  it('skips the reorder and still reloads when the re-parent is refused', () => {
    api.updateTag.mockReturnValue(throwError(() => new HttpErrorResponse({ status: 422 })));
    service.moveTag(B, drop(1, [2]));
    expect(api.reorderTags).not.toHaveBeenCalled();
    expect(tagsStore.load).toHaveBeenCalled();
  });
});
```

Adjust variable names (`service`, `api`, `tagsStore`, `subscriptionsStore`) to what the spec file already uses; `api.updateTag`/`api.reorderTags` must return `of({...})` by default.

- [ ] **Step 2: Run to verify they fail**

Run: `docker compose exec -T frontend npx jest src/app/reader/feeds/manage/manage-actions.service.spec.ts`
Expected: FAIL — `service.moveTag is not a function`.

- [ ] **Step 3: Implement** — extract the optimistic body of `reorderTags` into `placeTags(tagIds, parentId)` and add `moveTag`:

```ts
  /** Persist a new order for one sibling set. Optimistic: reposition the listed tags, keep the
   *  rest, reconcile on response. */
  reorderTags(tagIds: number[]): void {
    this.placeTags(tagIds);
    this.reloadAfter(this.api.reorderTags(tagIds), () => this.tags.load());
  }

  /** A new parent is a PATCH (the server puts the tag last), then the order the drop chose. */
  moveTag(tag: TagDto, drop: TagDrop): void {
    const order = [...drop.order];
    if (drop.parentId === tag.parentId) {
      this.reorderTags(order);
      return;
    }
    this.placeTags(order, drop.parentId);
    const body: TagInput = { name: tag.name, color: tag.color, icon: tag.icon, parentId: drop.parentId };
    this.reloadAfter(
      this.api.updateTag(tag.id, body).pipe(switchMap(() => this.api.reorderTags(order))),
      () => {
        this.tags.load();
        this.subs.load();
      },
    );
  }

  private placeTags(tagIds: number[], parentId?: number | null): void {
    this.tags.tags.update((current) => {
      const listed = new Set(tagIds);
      const byId = new Map(current.map((tag) => [tag.id, tag]));
      const repositioned = tagIds.map((id, index): TagDto => {
        const placed = { ...(byId.get(id) ?? { id, name: '', color: null, icon: null, parentId: null }), position: index };
        return parentId === undefined ? placed : { ...placed, parentId };
      });
      return [...current.filter((tag) => !listed.has(tag.id)), ...repositioned].sort(compareTagPositions);
    });
  }
```

`parentId?` is an optional value, not a boolean flag — but if the reviewer reads it as one, split into `placeTags(ids)` + `placeTagsUnder(ids, parentId)` sharing a `repositioned` helper. Import `TagDrop` from `../../tag-drop` and `TagInput` from `../../models` (check existing imports; `switchMap` is already imported).

- [ ] **Step 4: Run to verify they pass** — same command, PASS (including the old `reorderTags` tests).

- [ ] **Step 5: Commit**

```bash
git add frontend/src/app/reader/feeds/manage/manage-actions.service.ts frontend/src/app/reader/feeds/manage/manage-actions.service.spec.ts
git commit -m "feat(#1765): moveTag re-parents and orders a dragged tag"
```

### Task 4: Sidebar through the session

**Files:**
- Modify: `frontend/src/app/reader/shell/sidebar/sidebar.component.ts` (predicate ~165, drag state ~245–303)
- Modify: `frontend/src/app/reader/shell/sidebar/sidebar.component.html` (`.tags` ~148, `#tagHead` template ~238–303)
- Modify: `frontend/src/app/reader/shell/sidebar/sidebar.component.scss` (`.drop-line` ~183–195, `.tag.cdk-drag-placeholder` ~252–262)
- Test: `frontend/src/app/reader/shell/sidebar/sidebar.component.spec.ts` (`drag-and-drop reordering` ~521, `child tags (#1736)` ~627)

**Interfaces:**
- Consumes: `TagDragSession`, `measureTagLayout`, `TagHeaderElement` (Task 2); `ManageActions.moveTag` (Task 3).
- Produces: nothing new for later tasks.

Behaviour:
- Header drop lists take feeds only: `[cdkDropListEnterPredicate]="isFeedDrag"`; delete `acceptOnTagHead`, the tag branch of `onTagHeadDrop` (keep the feed branch; return early for a non-feed payload), `dragKind`, the `drop-line` binding, and the `isTagDrag`/`siblingIdsOf`/`moveItemInArray` imports if they become unused.
- `drop-active` on a header now means: a feed hovers it (`dropHover() === 'tag-' + id`, set only by feed drags) **or** `tagDropMark()` is `{kind:'nest', tagId: id}`.
- Tag drags: `(cdkDragStarted)="startTagDrag(node.tag)"`, `(cdkDragMoved)="moveTagDrag($event.pointerPosition.y)"`, `(cdkDragEnded)="endTagDrag()"`.
- `providers: [TagDragSession]` on the component.
- Each `.taghead` gets `#tagHeadRow` and `[attr.data-tag-id]="node.tag.id"`; `.tags` gets `#tagList`.
- `.tag-group` gets `[class.drop-before]="isMarked('before', group.node.tag.id)"` and `[class.drop-after]="isMarked('after', group.node.tag.id)"`; `.tag-child` the same with `child.node.tag.id`.
- After a drop that nests, the new parent opens (`expanded` gains `drop.parentId`).

- [ ] **Step 1: Write the failing tests** — replace the tag-reorder tests in `drag-and-drop reordering` and `child tags (#1736)` that call `onTagHeadDrop` with a tag payload or assert `acceptOnTagHead` (they test the removed path) with these, which drive the session the way the template does. Header boxes are mocked like the organise spec does (`jest.spyOn(element, 'getBoundingClientRect')`).

```ts
describe('dragging tags (#1765)', () => {
  const root = (id: number, position: number): TagDto => ({ id, name: `t${id}`, color: null, icon: null, position, parentId: null });
  const child = (id: number, parentId: number, position: number): TagDto => ({ ...root(id, position), parentId });
  const node = (tag: TagDto, children: TagNode[] = []): TagNode => ({ tag, subscriptions: [], children, unreadCount: 0, entryCount: 0 });

  /** Headers 40 tall at index * 50 inside a list whose top is 0. */
  function mountTree(tags: TagDto[], tree: TagNode[], expanded: number[] = []) {
    tagsStore.tags.set(tags); // use whatever tags-store stub the spec already provides
    const fixture = mount({ tagTree: tree });
    fixture.componentInstance.expanded.set(new Set(expanded));
    fixture.detectChanges();
    const list = fixture.debugElement.query(By.css('.tags')).nativeElement as HTMLElement;
    const heads = fixture.debugElement.queryAll(By.css('.taghead'));
    jest.spyOn(list, 'getBoundingClientRect').mockReturnValue({ top: 0, bottom: heads.length * 50 } as DOMRect);
    heads.forEach((head, index) =>
      jest.spyOn(head.nativeElement as HTMLElement, 'getBoundingClientRect')
        .mockReturnValue({ top: index * 50, bottom: index * 50 + 40, height: 40 } as DOMRect),
    );
    return fixture;
  }

  function dragTo(fixture: ComponentFixture<SidebarComponent>, dragged: TagDto, pointerY: number): void {
    fixture.componentInstance.startTagDrag(dragged);
    fixture.componentInstance.moveTagDrag(pointerY);
    fixture.detectChanges();
  }

  it('nests a tag dropped on the middle of another top-level tag and opens the parent', () => {
    const [a, b] = [root(1, 0), root(2, 1)];
    const fixture = mountTree([a, b], [node(a), node(b)]);
    dragTo(fixture, b, 20);
    expect(fixture.debugElement.query(By.css('.taghead.drop-active'))).not.toBeNull();

    fixture.componentInstance.endTagDrag();

    expect(manageActions.moveTag).toHaveBeenCalledWith(b, { parentId: 1, order: [2], mark: { kind: 'nest', tagId: 1 } });
    expect(fixture.componentInstance.expanded().has(1)).toBe(true);
  });

  it('draws a line above the block a tag will land before', () => {
    const [a, b] = [root(1, 0), root(2, 1)];
    const fixture = mountTree([a, b], [node(a), node(b)]);
    dragTo(fixture, b, 2);
    const groups = fixture.debugElement.queryAll(By.css('.tag-group'));
    expect(groups.map((group) => group.nativeElement.classList.contains('drop-before'))).toEqual([true, false]);
  });

  it('un-nests a child dropped on the top edge of a top-level tag', () => {
    const [a, b] = [root(1, 0), root(2, 1)];
    const b1 = child(21, 2, 0);
    const fixture = mountTree([a, b, b1], [node(a), node(b, [node(b1)])], [2]);
    dragTo(fixture, b1, 2);
    fixture.componentInstance.endTagDrag();
    expect(manageActions.moveTag).toHaveBeenCalledWith(b1, expect.objectContaining({ parentId: null, order: [21, 1, 2] }));
  });

  it('writes nothing for a drop that changes nothing', () => {
    const [a, b] = [root(1, 0), root(2, 1)];
    const fixture = mountTree([a, b], [node(a), node(b)]);
    dragTo(fixture, a, 2);
    fixture.componentInstance.endTagDrag();
    expect(manageActions.moveTag).not.toHaveBeenCalled();
  });

  it('keeps tag drags out of the header drop lists', () => {
    const fixture = mountTree([root(1, 0)], [node(root(1, 0))]);
    const accept = fixture.componentInstance.isFeedDrag;
    expect(accept({ data: root(2, 1) } as CdkDrag)).toBe(false);
  });
});
```

Add `moveTag: jest.fn()` to the spec's `manageActions` mock. Keep every feed-drop test (they must still pass unchanged).

- [ ] **Step 2: Run to verify they fail**

Run: `docker compose exec -T frontend npx jest src/app/reader/shell/sidebar/sidebar.component.spec.ts`
Expected: FAIL — `startTagDrag is not a function`.

- [ ] **Step 3: Implement** — in `sidebar.component.ts`:

```ts
  private readonly tagSession = inject(TagDragSession);
  private readonly tagList = viewChild<ElementRef<HTMLElement>>('tagList');
  private readonly tagHeadRows = viewChildren<ElementRef<HTMLElement>>('tagHeadRow');
  protected readonly tagDropMark = computed(() => this.tagSession.drop()?.mark ?? null);

  protected isMarked(kind: 'before' | 'after', tagId: number): boolean {
    const mark = this.tagDropMark();
    return mark !== null && mark.kind === kind && mark.tagId === tagId;
  }

  startTagDrag(tag: TagDto): void {
    const headers = this.tagHeadRows().map(({ nativeElement }) => ({
      tagId: Number(nativeElement.dataset['tagId']),
      element: nativeElement,
    }));
    this.tagSession.start(tag, measureTagLayout(headers, this.tagList()?.nativeElement, this.tagListTop()));
  }

  moveTagDrag(clientY: number): void {
    this.tagSession.move(clientY - this.tagListTop());
  }

  endTagDrag(): void {
    const ended = this.tagSession.end();
    if (ended === null) return;
    this.manage.moveTag(ended.tag, ended.drop);
    if (ended.drop.parentId !== null) this.expanded.update((open) => new Set(open).add(ended.drop.parentId!));
  }

  private tagListTop(): number {
    return this.tagList()?.nativeElement.getBoundingClientRect().top ?? 0;
  }
```

Avoid the `!`: capture `const parentId = ended.drop.parentId;` and test it. Keep `onDragEnd()` for feed drags; tag drags no longer call `onDragStart('tag')`/`onDragEnd()`.

In `sidebar.component.scss`: rename `.drop-line::before` to `.drop-before::before` (same rules) and add `.drop-after::after` mirroring it at the bottom (`bottom: calc(var(--space-0) * -1)` instead of `top`); make sure `.tag-group` and `.tag-child` are `position: relative`. Replace the `.tag.cdk-drag-placeholder` zero-height block (its reason — the placeholder hopping between lists — is gone) with `opacity: 0.4`, so the header keeps its size and the measured layout stays true. Keep the "skip the settle animation" rule below it.

- [ ] **Step 4: Run to verify they pass** — same command, PASS (all, including the feed-drop tests).

- [ ] **Step 5: Commit**

```bash
git add frontend/src/app/reader/shell/sidebar/
git commit -m "feat(#1765): sidebar tag drags nest, un-nest and move between parents"
```

### Task 5: Organise through the session

**Files:**
- Modify: `frontend/src/app/settings/organise/organise-section.component.ts` (drag block ~238–322, providers, imports)
- Modify: `frontend/src/app/settings/organise/organise-section.component.html` (panel template ~289–310)
- Modify: `frontend/src/app/settings/organise/organise-tag-group.component.{ts,html,scss}`
- Test: `frontend/src/app/settings/organise/organise-section.component.spec.ts` (`tag drag gap (#1708)` ~564 and the child-gap tests ~753–770)
- Delete: `tag-drop-gap.ts`, `tag-drop-gap.spec.ts`, `tag-drag-gap.service.ts`, `tag-drag-gap.service.spec.ts`

**Interfaces:**
- Consumes: `TagDragSession`, `measureTagLayout` (Task 2); `ManageActions.moveTag` (Task 3).
- Produces: `OrganiseTagGroupComponent.nestTarget = input(false)`, `OrganiseTagGroupComponent.headElement` (`viewChild.required('head', { read: ElementRef })`).

Behaviour:
- `providers: [OrganiseStore, TagDragSession]` (drop `TagDragGap`).
- `startTagDrag(group)`: measure every tag panel's header (`panelGroups()` filtered to `group().tag !== null`, element = `headElement().nativeElement`), `listEnd` = the last **root** tag panel's element (the untagged group is not a tag), origin = the tree's top; `tagSession.start(tag, layout)`.
- `moveTagDrag(clientY)` → `tagSession.move(clientY - treeTop())`.
- `endTagDrag()` → `const ended = tagSession.end(); if (ended !== null && !store.filterActive()) manage.moveTag(ended.tag, ended.drop);`.
- `gapBeforeKey` = mark `before`'s tagId, `gapAfterKey` = mark `after`'s tagId, new `nestKey` = mark `nest`'s tagId; template binds `[nestTarget]="nestKey() === group.key"`.
- `OrganiseTagGroupComponent`: `#head` on the `.head` div; `[class.nest-target]="nestTarget()"` on `.head-drop`; scss `.head-drop.nest-target { background: var(--accent-soft); outline: 2px solid var(--accent); outline-offset: -2px; border-radius: inherit; }` (match the panel's radius token if `inherit` doesn't reach).
- `canMoveTag`/`moveTag`/`persistTagOrder` (arrows) stay; `siblingIds`, `spanOf`, `dragSiblingKeys`, `draggedTag` go.

- [ ] **Step 1: Write the failing tests** — rewrite `tag drag gap (#1708)` to measure headers instead of panels and add the parent cases. Header boxes: mock each panel's `.head` element; tree top 100; headers 40 tall at `100 + index * 100`; the last root panel's bottom drives `listBottom`.

```ts
describe('tag drag (#1708, #1765)', () => {
  const TREE_TOP = 100;
  const panels = () => fixture.debugElement.queryAll(By.directive(OrganiseTagGroupComponent));
  const heads = () => fixture.debugElement.queryAll(By.css('app-organise-tag-group .head'));

  async function renderTags(tags: TagDto[] = [TECH, WORLD, ART]) {
    const rendered = await renderWithMocks(SUBS, tags);
    const tree = fixture.debugElement.query(By.css('.tree')).nativeElement as HTMLElement;
    jest.spyOn(tree, 'getBoundingClientRect').mockReturnValue({ top: TREE_TOP } as DOMRect);
    heads().forEach((head, index) =>
      jest.spyOn(head.nativeElement as HTMLElement, 'getBoundingClientRect')
        .mockReturnValue({ top: TREE_TOP + index * 100, bottom: TREE_TOP + index * 100 + 40 } as DOMRect),
    );
    panels().forEach((panel, index) =>
      jest.spyOn(panel.nativeElement as HTMLElement, 'getBoundingClientRect')
        .mockReturnValue({ top: TREE_TOP + index * 100, bottom: TREE_TOP + index * 100 + 80 } as DOMRect),
    );
    return rendered;
  }

  const gapMarks = () =>
    panels().map((panel) => {
      const classes = (panel.nativeElement as HTMLElement).classList;
      if (classes.contains('gap-before')) return 'before';
      return classes.contains('gap-after') ? 'after' : '-';
    });
  const nestMarks = () =>
    panels().map((panel) => (panel.nativeElement.querySelector('.head-drop.nest-target') ? 'nest' : '-'));

  function drag(fromPanel: number, clientY: number): void {
    panels()[fromPanel].triggerEventHandler('tagDragStarted');
    panels()[fromPanel].triggerEventHandler('tagDragMoved', clientY);
    fixture.detectChanges();
  }

  it('opens the gap above the first tag', async () => {
    await renderTags();
    drag(2, 102);
    expect(gapMarks()).toEqual(['before', '-', '-', '-']);
  });

  it('opens the gap below the last tag, above the untagged group', async () => {
    await renderTags();
    drag(0, 395);
    expect(gapMarks()).toEqual(['-', '-', 'after', '-']);
  });

  it('opens no gap beside the dragged tag itself', async () => {
    await renderTags();
    drag(1, 202);
    expect(gapMarks()).toEqual(['-', '-', '-', '-']);
  });

  it('measures against the tree, so a scrolled page keeps the same gap', async () => {
    await renderTags();
    panels()[2].triggerEventHandler('tagDragStarted');
    const tree = fixture.debugElement.query(By.css('.tree')).nativeElement as HTMLElement;
    jest.spyOn(tree, 'getBoundingClientRect').mockReturnValue({ top: TREE_TOP - 300 } as DOMRect);
    panels()[2].triggerEventHandler('tagDragMoved', 102 - 300);
    fixture.detectChanges();
    expect(gapMarks()).toEqual(['before', '-', '-', '-']);
  });

  it('highlights the header a tag will nest under', async () => {
    await renderTags();
    drag(2, 120);
    expect(nestMarks()).toEqual(['nest', '-', '-', '-']);
    expect(gapMarks()).toEqual(['-', '-', '-', '-']);
  });

  it('moves the tag on drop through moveTag and clears the cue', async () => {
    const { manage } = await renderTags();
    drag(2, 120);
    panels()[2].triggerEventHandler('tagDragEnded');
    fixture.detectChanges();
    expect(manage.moveTag).toHaveBeenCalledWith(ART, { parentId: TECH.id, order: [ART.id], mark: { kind: 'nest', tagId: TECH.id } });
    expect(nestMarks()).toEqual(['-', '-', '-', '-']);
  });
});
```

Replace the old child-gap tests (~753–770, "only within its sibling range", "after the last child inside its parent's panel") with: a child dragged to a top-level header's top edge un-nests (`moveTag` with `parentId: null`), and a child dragged to another root's middle nests there. Use the spec's existing child fixture; panel order in a tree with a child is root, child, …. Add `moveTag: jest.fn()` to the `manage` mock; keep the arrow (`moveTag(group, ±1)` → `reorderTags`) tests as they are.

- [ ] **Step 2: Run to verify they fail**

Run: `docker compose exec -T frontend npx jest src/app/settings/organise`
Expected: FAIL — nest tests fail (`nest-target` never set) and `manage.moveTag` not called.

- [ ] **Step 3: Implement** — in `organise-section.component.ts` replace the drag block:

```ts
  private readonly tagSession = inject(TagDragSession);
  private readonly dropMark = computed(() => this.tagSession.drop()?.mark ?? null);

  protected readonly gapBeforeKey = computed(() => this.markedKey('before'));
  protected readonly gapAfterKey = computed(() => this.markedKey('after'));
  protected readonly nestKey = computed(() => this.markedKey('nest'));

  protected startTagDrag(group: OrganiseGroup): void {
    if (group.tag === null) return;
    const tagPanels = this.panelGroups().filter((panel) => panel.group().tag !== null);
    const headers = tagPanels.map((panel) => ({
      tagId: panel.group().tag!.id,
      element: panel.headElement().nativeElement,
    }));
    const lastRoot = [...tagPanels].reverse().find((panel) => panel.group().depth === 0);
    const listEnd = lastRoot === undefined ? undefined : this.panelByKey().get(lastRoot.group().key);
    this.tagSession.start(group.tag, measureTagLayout(headers, listEnd, this.treeTop()));
  }

  protected moveTagDrag(clientY: number): void {
    this.tagSession.move(clientY - this.treeTop());
  }

  protected endTagDrag(): void {
    const ended = this.tagSession.end();
    if (ended === null || this.store.filterActive()) return;
    this.manage.moveTag(ended.tag, ended.drop);
  }

  private markedKey(kind: TagDropMark['kind']): GroupKey | null {
    const mark = this.dropMark();
    return mark !== null && mark.kind === kind ? mark.tagId : null;
  }
```

Replace `panel.group().tag!.id` with a `flatMap` that skips a null tag, so no non-null assertion is needed. Delete the four `tag-drop-gap`/`tag-drag-gap` files and their imports. In `organise-tag-group.component.ts`: `readonly nestTarget = input(false);` and `readonly headElement = viewChild.required<string, ElementRef<HTMLElement>>('head', { read: ElementRef });`.

- [ ] **Step 4: Run to verify they pass**

Run: `docker compose exec -T frontend npx jest src/app/settings/organise`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add -A frontend/src/app/settings/organise/
git commit -m "feat(#1765): organise tag drags share the sidebar's rule"
```

### Task 6: Gates and live check

- [ ] **Step 1:** `docker compose exec -T frontend npm run check` → all green (ESLint, Prettier, Stylelint, tsc, Jest).
- [ ] **Step 2:** `docker compose exec -T frontend npm run build` → succeeds (template errors).
- [ ] **Step 3:** Live check on http://localhost:4200 (the stack must be current: memory "check the container is current"), desktop viewport, sidebar: nest a childless root (middle), reorder roots (top/bottom edges, cue on the right edge), un-nest a child to a top-level spot, move a child to another parent (middle and a child edge), bottom edge of an open parent → first child, end of top level below the last tag. Then the same set in /settings/organise. Then the sidebar in Organise mode on a phone viewport (handle drag). Restore the tag tree to how it was found.
- [ ] **Step 4:** Commit any fix-ups with `fix(#1765): …`; open the PR into `develop` with `Closes #1765`.
