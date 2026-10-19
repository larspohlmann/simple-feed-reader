# Organise tag drag: an insertion gap between tags (#1708) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Dragging a tag on `/settings/organise` (Tree view) opens a gap *between* tag panels where it will land, instead of lighting up another tag as if dropping into it; dropping moves the tag to that gap, including above the first and below the last tag.

**Architecture:** No nested `cdkDropList` (the standing rule). The tag header's single-item drop list stops accepting tag drags (enter predicate: feeds only), so a tag drag has no CDK drop target and CDK never highlights another tag. Instead the tag group reports its header's drag start / pointer Y / end as outputs; the section measures the tag panels once at drag start (tree-relative midpoints, so page scroll and the opening gap don't feed back), and a section-scoped `TagDragGap` service turns the pointer offset into a gap index. The section renders that index as a `gap-before` class on the panel below the gap (the untagged group always sits last, so "below the last tag" is `gap-before` on it), and on drag end persists `manage.reorderTags()` with the moved order.

**Tech Stack:** Angular 20 (standalone, signals, OnPush), `@angular/cdk/drag-drop`, Jest (jsdom), SCSS with theme tokens.

**Spec:** GitHub issue #1708 (`gh issue view 1708`). Design choice confirmed with the user in chat: own insertion gap, not one nested CDK list.

## Global Constraints

- Never nest `cdkDropList`s (CLAUDE.md Gotchas).
- Feed-onto-tag-header drops keep working (the header still accepts *feed* drags and highlights for them).
- The filter guard stays: no tag reorder is written while `store.filterActive()`; header drag stays disabled under a filter, on a coarse pointer, and for the untagged group (`headerDragDisabled`, unchanged).
- The tag arrow buttons (`moveTag`) are untouched.
- Coarse-pointer (phone) devices keep arrows only — drag there was disabled deliberately and stays so; CDK's touch events on a fine-pointer touch screen take the same code path as the mouse.
- SCSS: no hex colours, no ad-hoc `px` spacing (tokens only; a `1px`/`2px` border width is fine), motion guarded by `prefers-reduced-motion`. Component styles in the sibling `.scss`.
- Comments: default to none; one line when a future reader would get it wrong without it.
- Frontend tests run in the container: `docker compose exec -T frontend npx jest <paths>`; the gate is `docker compose exec -T frontend npm run check`. Never run two Jest processes at once in the container.
- Commits: `feat(#1708): …` / `refactor(#1708): …`, no attribution lines.

## File Structure

| File | Responsibility |
|---|---|
| Create `frontend/src/app/settings/organise/tag-drop-gap.ts` | Pure geometry and order helpers: `TagMove`, `gapIndexAt`, `isNoOpGap`, `moveToGap`, `verticalMiddle` |
| Create `frontend/src/app/settings/organise/tag-drop-gap.spec.ts` | Unit tests for the helpers |
| Create `frontend/src/app/settings/organise/tag-drag-gap.service.ts` | `TagDragGap`: per-section drag state (`start`/`move`/`end`) and the shown `gap` signal |
| Create `frontend/src/app/settings/organise/tag-drag-gap.service.spec.ts` | Its tests |
| Modify `frontend/src/app/settings/organise/organise-tag-group.component.{ts,html,scss,spec.ts}` | Feeds-only enter predicates; header drag outputs; drop the tag branch of `onHeaderDropped` and `reorderDroppedTag`; no accent highlight on the lifted header's own list |
| Modify `frontend/src/app/settings/organise/organise-section.component.{ts,html,scss,spec.ts}` | Provide `TagDragGap`; measure panels; render `gap-before`; persist the reorder on end |
| Modify `frontend/src/app/reader/drag-payload.ts` | Delete `isTagDrag` (its only caller goes away) |

---

### Task 1: Gap geometry and the drag-state service

**Files:**
- Create: `frontend/src/app/settings/organise/tag-drop-gap.ts`
- Create: `frontend/src/app/settings/organise/tag-drop-gap.spec.ts`
- Create: `frontend/src/app/settings/organise/tag-drag-gap.service.ts`
- Create: `frontend/src/app/settings/organise/tag-drag-gap.service.spec.ts`

**Interfaces:**
- Produces:
  - `interface TagMove { readonly from: number; readonly gap: number }` — `from` is the dragged tag's index in the full tag order; `gap` is the gap index, `0` = above the first tag, `n` = below the last of `n` tags.
  - `gapIndexAt(offsetY: number, middles: readonly number[]): number`
  - `isNoOpGap(from: number, gap: number): boolean`
  - `moveToGap(ids: readonly number[], move: TagMove): number[]`
  - `verticalMiddle(element: Element): number` (client coordinates)
  - `@Injectable() class TagDragGap { readonly gap: Signal<number | null>; start(from: number, middles: readonly number[]): void; move(offsetY: number): void; end(): TagMove | null }`

- [ ] **Step 1: Write the failing helper tests**

`frontend/src/app/settings/organise/tag-drop-gap.spec.ts`:

```ts
import { gapIndexAt, isNoOpGap, moveToGap } from './tag-drop-gap';

// Three panels whose midpoints sit at 50, 150 and 250 px into the tree.
const MIDDLES = [50, 150, 250];

describe('gapIndexAt', () => {
  it('opens the gap above the first tag when the pointer is above its middle', () => {
    expect(gapIndexAt(10, MIDDLES)).toBe(0);
  });

  it('opens the gap between the two tags whose middles the pointer sits between', () => {
    expect(gapIndexAt(200, MIDDLES)).toBe(2);
  });

  it('opens the gap below the last tag when the pointer is past its middle', () => {
    expect(gapIndexAt(400, MIDDLES)).toBe(3);
  });
});

describe('isNoOpGap', () => {
  it('treats the gaps right above and right below the dragged tag as no move', () => {
    expect(isNoOpGap(1, 1)).toBe(true);
    expect(isNoOpGap(1, 2)).toBe(true);
  });

  it('treats any other gap as a move', () => {
    expect(isNoOpGap(1, 0)).toBe(false);
    expect(isNoOpGap(1, 3)).toBe(false);
  });
});

describe('moveToGap', () => {
  const IDS = [2, 4, 6];

  it('moves a tag up into a gap above it', () => {
    expect(moveToGap(IDS, { from: 2, gap: 0 })).toEqual([6, 2, 4]);
  });

  it('moves a tag down into a gap below it, landing before the panel under the gap', () => {
    expect(moveToGap(IDS, { from: 0, gap: 2 })).toEqual([4, 2, 6]);
  });

  it('moves a tag below the last one', () => {
    expect(moveToGap(IDS, { from: 0, gap: 3 })).toEqual([4, 6, 2]);
  });

  it('leaves the given order untouched', () => {
    moveToGap(IDS, { from: 0, gap: 3 });

    expect(IDS).toEqual([2, 4, 6]);
  });
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `docker compose exec -T frontend npx jest src/app/settings/organise/tag-drop-gap`
Expected: FAIL — `Cannot find module './tag-drop-gap'`.

- [ ] **Step 3: Implement the helpers**

`frontend/src/app/settings/organise/tag-drop-gap.ts`:

```ts
import { moveItemInArray } from '@angular/cdk/drag-drop';

/** A tag drag's outcome: the tag's index in the full order, and the gap it was dropped in. */
export interface TagMove {
  readonly from: number;
  readonly gap: number;
}

/** The gap the pointer is in: how many panels' middles lie above it. */
export function gapIndexAt(offsetY: number, middles: readonly number[]): number {
  return middles.filter((middle) => middle < offsetY).length;
}

/** The gaps on either side of the dragged tag leave the order as it is. */
export function isNoOpGap(from: number, gap: number): boolean {
  return gap === from || gap === from + 1;
}

export function moveToGap(ids: readonly number[], move: TagMove): number[] {
  const order = [...ids];
  moveItemInArray(order, move.from, move.gap > move.from ? move.gap - 1 : move.gap);
  return order;
}

export function verticalMiddle(element: Element): number {
  const rect = element.getBoundingClientRect();
  return rect.top + rect.height / 2;
}
```

- [ ] **Step 4: Run the helper tests**

Run: `docker compose exec -T frontend npx jest src/app/settings/organise/tag-drop-gap`
Expected: PASS (9 tests).

- [ ] **Step 5: Write the failing service tests**

`frontend/src/app/settings/organise/tag-drag-gap.service.spec.ts`:

```ts
import { TestBed } from '@angular/core/testing';
import { TagDragGap } from './tag-drag-gap.service';

const MIDDLES = [50, 150, 250];

describe('TagDragGap', () => {
  let gap: TagDragGap;

  beforeEach(() => {
    TestBed.configureTestingModule({ providers: [TagDragGap] });
    gap = TestBed.inject(TagDragGap);
  });

  it('shows no gap until the dragged tag moves', () => {
    gap.start(0, MIDDLES);

    expect(gap.gap()).toBeNull();
  });

  it('shows the gap the pointer is in', () => {
    gap.start(0, MIDDLES);
    gap.move(200);

    expect(gap.gap()).toBe(2);
  });

  it('shows no gap while the pointer is beside the dragged tag itself', () => {
    gap.start(1, MIDDLES);
    gap.move(120);

    expect(gap.gap()).toBeNull();
  });

  it('ends with the move to the shown gap and clears it', () => {
    gap.start(0, MIDDLES);
    gap.move(400);

    expect(gap.end()).toEqual({ from: 0, gap: 3 });
    expect(gap.gap()).toBeNull();
  });

  it('ends with no move when the tag is dropped where it was', () => {
    gap.start(1, MIDDLES);
    gap.move(160);

    expect(gap.end()).toBeNull();
  });

  it('ends with no move when no drag started', () => {
    gap.move(400);

    expect(gap.end()).toBeNull();
  });
});
```

- [ ] **Step 6: Run it to verify it fails**

Run: `docker compose exec -T frontend npx jest src/app/settings/organise/tag-drag-gap`
Expected: FAIL — `Cannot find module './tag-drag-gap.service'`.

- [ ] **Step 7: Implement the service**

`frontend/src/app/settings/organise/tag-drag-gap.service.ts`:

```ts
import { Injectable, computed, signal } from '@angular/core';
import { TagMove, gapIndexAt, isNoOpGap } from './tag-drop-gap';

interface TagDrag {
  readonly from: number;
  readonly middles: readonly number[];
}

/** The gap a dragged tag would land in, for the Organise tree that provides it. */
@Injectable()
export class TagDragGap {
  private readonly drag = signal<TagDrag | null>(null);
  private readonly offsetY = signal<number | null>(null);

  readonly gap = computed(() => {
    const drag = this.drag();
    const offsetY = this.offsetY();
    if (drag === null || offsetY === null) return null;
    const gap = gapIndexAt(offsetY, drag.middles);
    return isNoOpGap(drag.from, gap) ? null : gap;
  });

  /** `middles` are the tag panels' midpoints, measured once, relative to the tree's top. */
  start(from: number, middles: readonly number[]): void {
    this.drag.set({ from, middles });
    this.offsetY.set(null);
  }

  move(offsetY: number): void {
    this.offsetY.set(offsetY);
  }

  end(): TagMove | null {
    const drag = this.drag();
    const gap = this.gap();
    this.drag.set(null);
    this.offsetY.set(null);
    return drag === null || gap === null ? null : { from: drag.from, gap };
  }
}
```

- [ ] **Step 8: Run both specs**

Run: `docker compose exec -T frontend npx jest src/app/settings/organise/tag-drop-gap src/app/settings/organise/tag-drag-gap`
Expected: PASS (15 tests).

- [ ] **Step 9: Commit**

```bash
git add frontend/src/app/settings/organise/tag-drop-gap.ts frontend/src/app/settings/organise/tag-drop-gap.spec.ts frontend/src/app/settings/organise/tag-drag-gap.service.ts frontend/src/app/settings/organise/tag-drag-gap.service.spec.ts
git commit -m "feat(#1708): compute the gap a dragged tag would land in"
```

---

### Task 2: The tag header stops taking tag drops and reports its drag instead

**Files:**
- Modify: `frontend/src/app/settings/organise/organise-tag-group.component.ts`
- Modify: `frontend/src/app/settings/organise/organise-tag-group.component.html`
- Modify: `frontend/src/app/settings/organise/organise-tag-group.component.scss`
- Modify: `frontend/src/app/settings/organise/organise-tag-group.component.spec.ts`
- Modify: `frontend/src/app/reader/drag-payload.ts`

**Interfaces:**
- Produces (outputs on `OrganiseTagGroupComponent`, consumed by Task 3):
  - `readonly tagDragStarted = output<void>()`
  - `readonly tagDragMoved = output<number>()` — the pointer's client Y
  - `readonly tagDragEnded = output<void>()`
- Produces (template-facing): `protected readonly acceptsFeeds = (drag: CdkDrag): boolean => isSubscriptionDrag(drag.data)`

- [ ] **Step 1: Replace the tag-reorder tests with the new contract**

In `organise-tag-group.component.spec.ts`, delete these five tests (their behaviour moves to the section):
`'reorders tags when a tag header is dropped on another tag header'`,
`'does nothing when a tag is dropped on its own header'`,
`'ignores a tag-header reorder drop under an active filter, and writes nothing'`,
`'does not reorder tags when a tag is dropped on the untagged group header'`.
Keep `'ignores a dropped tag header instead of mistaking it for a feed'` and `'appends a feed dropped on the header, which shows no list to index into'`.

Add `CdkDrag`, `CdkDropList` to the `@angular/cdk/drag-drop` import (`import { CdkDrag, CdkDropList } from '@angular/cdk/drag-drop';`) and these tests inside the top-level `describe`:

```ts
  describe('drop lists', () => {
    const listsOf = () =>
      fixture.debugElement
        .queryAll(By.directive(CdkDropList))
        .map((list) => list.injector.get(CdkDropList));

    it('lets feeds, and only feeds, into the header and the feed list', async () => {
      await render(GROUP);
      const feedDrag = { data: SUB_A } as CdkDrag;
      const tagDrag = { data: TECH } as CdkDrag;

      for (const list of listsOf()) {
        expect(list.enterPredicate(feedDrag, list)).toBe(true);
        expect(list.enterPredicate(tagDrag, list)).toBe(false);
      }
      expect(listsOf()).toHaveLength(2);
    });
  });

  describe('header drag', () => {
    const header = () => fixture.debugElement.query(By.css('.head'));

    it('reports the drag of its header: start, pointer height, end', async () => {
      const { component } = await render(GROUP);
      const reported: string[] = [];
      component.tagDragStarted.subscribe(() => reported.push('started'));
      component.tagDragMoved.subscribe((clientY) => reported.push(`moved ${clientY}`));
      component.tagDragEnded.subscribe(() => reported.push('ended'));

      header().triggerEventHandler('cdkDragStarted', {});
      header().triggerEventHandler('cdkDragMoved', { pointerPosition: { x: 5, y: 120 } });
      header().triggerEventHandler('cdkDragEnded', {});

      expect(reported).toEqual(['started', 'moved 120', 'ended']);
    });

    it('marks its own header list lifted while its header is dragged', async () => {
      await render(GROUP);
      const headList = () => fixture.debugElement.query(By.css('.head-drop')).nativeElement;

      header().triggerEventHandler('cdkDragStarted', {});
      fixture.detectChanges();
      expect(headList().classList).toContain('lifted');

      header().triggerEventHandler('cdkDragEnded', {});
      fixture.detectChanges();
      expect(headList().classList).not.toContain('lifted');
    });
  });
```

- [ ] **Step 2: Run the spec to verify the new tests fail**

Run: `docker compose exec -T frontend npx jest src/app/settings/organise/organise-tag-group`
Expected: FAIL — the predicate test sees `true` for the tag drag (default predicate), the drag-report test sees `[]` / `tagDragStarted` undefined, the lifted test finds no `lifted` class.

- [ ] **Step 3: Implement in the component class**

In `organise-tag-group.component.ts`:

1. Imports: `import { ChangeDetectionStrategy, Component, computed, inject, input, output, signal } from '@angular/core';`, `import { CdkDrag, CdkDragDrop, CdkDropList, moveItemInArray } from '@angular/cdk/drag-drop';` (unchanged), and `import { isSubscriptionDrag } from '../../reader/drag-payload';` (drop `isTagDrag`). Drop `TagDto` from the models import if nothing else uses it.
2. Add after `readonly moveTagDown = output<void>();`:

```ts
  readonly tagDragStarted = output<void>();
  readonly tagDragMoved = output<number>();
  readonly tagDragEnded = output<void>();
```

3. Add after `headerDragDisabled`:

```ts
  /** A tag never drops into a header or a feed list: the section's gap places it between panels. */
  protected readonly acceptsFeeds = (drag: CdkDrag): boolean => isSubscriptionDrag(drag.data);

  protected readonly headerLifted = signal(false);
```

4. Replace `onHeaderDropped` and delete `reorderDroppedTag` entirely:

```ts
  /** A feed dropped on this group's HEADER moves onto this tag; a header shows
   *  no feed list, so it appends (a null position) rather than landing at an index. */
  onHeaderDropped(event: CdkDragDrop<OrganiseGroup>): void {
    if (!isSubscriptionDrag(event.item.data)) return;
    this.moveFeedHere(event.item.data, event.previousContainer.data, null);
  }

  protected onHeaderDragStarted(): void {
    this.headerLifted.set(true);
    this.tagDragStarted.emit();
  }

  protected onHeaderDragEnded(): void {
    this.headerLifted.set(false);
    this.tagDragEnded.emit();
  }
```

5. Shorten the `onFeedDropped` doc to drop the "a dragged tag header … also lets reach here" clause (the predicate now keeps tags out); keep the `isSubscriptionDrag` guard as the type narrowing it is.

- [ ] **Step 4: Wire the template**

In `organise-tag-group.component.html`, replace the head-drop opening tag and the header `cdkDrag` line:

```html
  <div
    class="head-drop"
    cdkDropList
    [cdkDropListData]="group()"
    [cdkDropListSortingDisabled]="true"
    [cdkDropListEnterPredicate]="acceptsFeeds"
    [class.lifted]="headerLifted()"
    (cdkDropListDropped)="onHeaderDropped($event)"
  >
    <div
      class="head"
      cdkDrag
      [cdkDragData]="group().tag"
      [cdkDragDisabled]="headerDragDisabled()"
      (cdkDragStarted)="onHeaderDragStarted()"
      (cdkDragMoved)="tagDragMoved.emit($event.pointerPosition.y)"
      (cdkDragEnded)="onHeaderDragEnded()"
    >
```

Update the comment above it to one line: `<!-- Its own single-item list, a sibling of the feed list: lists never nest. -->`

And add `[cdkDropListEnterPredicate]="acceptsFeeds"` to the `.feeds` `cdkDropList`.

- [ ] **Step 5: Style the lifted header**

In `organise-tag-group.component.scss`, replace the `.head-drop.cdk-drop-list-dragging, .head-drop:has(.cdk-drag-preview)` rule with:

```scss
// A feed held over a header lights it up: the drop lands in that tag.
.head-drop.cdk-drop-list-dragging:not(.lifted) {
  background: var(--accent-soft);
}

.head-drop .cdk-drag-placeholder {
  opacity: 0.4;
}
```

- [ ] **Step 6: Delete `isTagDrag`**

In `frontend/src/app/reader/drag-payload.ts` delete the `isTagDrag` function and its doc comment; drop `TagDto` from its import. Verify no other caller: `grep -rn "isTagDrag" frontend/src frontend/e2e` → no output.

- [ ] **Step 7: Run the spec**

Run: `docker compose exec -T frontend npx jest src/app/settings/organise/organise-tag-group`
Expected: PASS.

- [ ] **Step 8: Commit**

```bash
git add frontend/src/app/settings/organise/organise-tag-group.component.ts frontend/src/app/settings/organise/organise-tag-group.component.html frontend/src/app/settings/organise/organise-tag-group.component.scss frontend/src/app/settings/organise/organise-tag-group.component.spec.ts frontend/src/app/reader/drag-payload.ts
git commit -m "refactor(#1708): keep tag drags out of the header lists and report them instead"
```

---

### Task 3: The section opens the gap and writes the move

**Files:**
- Modify: `frontend/src/app/settings/organise/organise-section.component.ts`
- Modify: `frontend/src/app/settings/organise/organise-section.component.html` (the `.tree` block, ~line 282)
- Modify: `frontend/src/app/settings/organise/organise-section.component.scss` (after `.tree`, ~line 240)
- Modify: `frontend/src/app/settings/organise/organise-section.component.spec.ts`

**Interfaces:**
- Consumes: `TagDragGap` (`gap`, `start`, `move`, `end`), `moveToGap`, `verticalMiddle` (Task 1); `tagDragStarted` / `tagDragMoved(clientY)` / `tagDragEnded` outputs (Task 2).
- Produces: nothing for later tasks.

- [ ] **Step 1: Write the failing section tests**

In `organise-section.component.spec.ts`:

1. Add next to `TECH`:

```ts
const WORLD: TagDto = { id: 4, name: 'World', color: null, icon: null, position: 1 };
const ART: TagDto = { id: 6, name: 'Art', color: null, icon: null, position: 2 };
```

2. Give `renderWithMocks` a second parameter and use it for the `TagsStore` stub:

```ts
  async function renderWithMocks(subscriptions: SubscriptionDto[] = SUBS, tags: TagDto[] = [TECH]) {
```
```ts
          { provide: TagsStore, useValue: { tags: signal(tags), load: jest.fn() } },
```

3. Add this `describe` block at the end of the top-level `describe`:

```ts
  describe('tag drag gap (#1708)', () => {
    // Tree top at 100; panels 80 px tall, 20 px apart: middles at 140, 240, 340 (+ untagged at 440).
    const TREE_TOP = 100;

    async function renderThreeTags() {
      const rendered = await renderWithMocks(SUBS, [TECH, WORLD, ART]);
      const tree = fixture.debugElement.query(By.css('.tree')).nativeElement as HTMLElement;
      jest.spyOn(tree, 'getBoundingClientRect').mockReturnValue({ top: TREE_TOP } as DOMRect);
      panels().forEach((panel, index) =>
        jest
          .spyOn(panel.nativeElement as HTMLElement, 'getBoundingClientRect')
          .mockReturnValue({ top: TREE_TOP + index * 100, height: 80 } as DOMRect),
      );
      return rendered;
    }

    const panels = () => fixture.debugElement.queryAll(By.directive(OrganiseTagGroupComponent));
    const gapBefore = () =>
      panels().map((panel) => (panel.nativeElement as HTMLElement).classList.contains('gap-before'));

    function drag(fromPanel: number, clientY: number): void {
      panels()[fromPanel].triggerEventHandler('tagDragStarted');
      panels()[fromPanel].triggerEventHandler('tagDragMoved', clientY);
      fixture.detectChanges();
    }

    it('opens the gap above the first tag', async () => {
      await renderThreeTags();

      drag(2, 110);

      expect(gapBefore()).toEqual([true, false, false, false]);
    });

    it('opens the gap below the last tag, above the untagged group', async () => {
      await renderThreeTags();

      drag(0, 400);

      expect(gapBefore()).toEqual([false, false, false, true]);
    });

    it('opens no gap beside the dragged tag itself', async () => {
      await renderThreeTags();

      drag(1, 260);

      expect(gapBefore()).toEqual([false, false, false, false]);
    });

    it('measures against the tree, so a scrolled page keeps the same gap', async () => {
      await renderThreeTags();
      panels()[2].triggerEventHandler('tagDragStarted');
      const tree = fixture.debugElement.query(By.css('.tree')).nativeElement as HTMLElement;
      jest.spyOn(tree, 'getBoundingClientRect').mockReturnValue({ top: TREE_TOP - 300 } as DOMRect);

      panels()[2].triggerEventHandler('tagDragMoved', 110 - 300);
      fixture.detectChanges();

      expect(gapBefore()).toEqual([true, false, false, false]);
    });

    it('moves the tag into the gap on drop and closes the gap', async () => {
      const { manage } = await renderThreeTags();
      drag(0, 400);

      panels()[0].triggerEventHandler('tagDragEnded');
      fixture.detectChanges();

      expect(manage.reorderTags).toHaveBeenCalledWith([WORLD.id, ART.id, TECH.id]);
      expect(gapBefore()).toEqual([false, false, false, false]);
    });

    it('writes nothing when the tag is dropped where it was', async () => {
      const { manage } = await renderThreeTags();
      drag(1, 260);

      panels()[1].triggerEventHandler('tagDragEnded');

      expect(manage.reorderTags).not.toHaveBeenCalled();
    });

    it('writes nothing under an active filter', async () => {
      const { manage, store } = await renderThreeTags();
      drag(0, 400);
      store.titleFilter.set('taz');

      panels()[0].triggerEventHandler('tagDragEnded');

      expect(manage.reorderTags).not.toHaveBeenCalled();
    });
  });
```

`store.titleFilter` is the store's writable title filter (`organise.store.ts:91`); `filterActive` reads it. The panels stay rendered under it until the next change detection, which the test never runs.

- [ ] **Step 2: Run them to verify they fail**

Run: `docker compose exec -T frontend npx jest src/app/settings/organise/organise-section`
Expected: the new tests FAIL (no `gap-before` class; `reorderTags` not called); every existing test still PASSES.

- [ ] **Step 3: Implement in the section class**

In `organise-section.component.ts`:

1. Imports: add `ElementRef`, `viewChild`, `viewChildren` to the `@angular/core` import; add

```ts
import { TagDragGap } from './tag-drag-gap.service';
import { moveToGap, verticalMiddle } from './tag-drop-gap';
```

2. `providers: [OrganiseStore, TagDragGap],`
3. Add with the other fields:

```ts
  protected readonly tagGap = inject(TagDragGap);
  private readonly tree = viewChild<ElementRef<HTMLElement>>('tree');
  private readonly panels = viewChildren(OrganiseTagGroupComponent, { read: ElementRef });
```

4. Add next to `moveTag`:

```ts
  protected startTagDrag(group: OrganiseGroup): void {
    const from = this.tags.tags().findIndex((tag) => tag.id === group.tag?.id);
    const treeTop = this.treeTop();
    const middles = this.panels()
      .filter((_, index) => this.store.groups()[index]?.tag != null)
      .map((panel) => verticalMiddle(panel.nativeElement as HTMLElement) - treeTop);
    this.tagGap.start(from, middles);
  }

  protected moveTagDrag(clientY: number): void {
    this.tagGap.move(clientY - this.treeTop());
  }

  protected endTagDrag(): void {
    const move = this.tagGap.end();
    if (move === null || this.store.filterActive()) return;
    this.manage.reorderTags(moveToGap(this.tags.tags().map((tag) => tag.id), move));
  }

  private treeTop(): number {
    return this.tree()?.nativeElement.getBoundingClientRect().top ?? 0;
  }
```

- [ ] **Step 4: Wire the template**

In `organise-section.component.html`, the tree block becomes:

```html
    <div class="tree" #tree [class.has-bulk-bar]="store.selectedCount() > 0" cdkDropListGroup>
      @for (group of store.groups(); track group.key; let i = $index, last = $last) {
        <app-organise-tag-group
          [group]="group"
          [class.gap-before]="tagGap.gap() === i"
          [canMoveTagUp]="group.tag !== null && i > 0 && !store.filterActive()"
          [canMoveTagDown]="
            group.tag !== null &&
            !last &&
            store.groups()[i + 1].tag !== null &&
            !store.filterActive()
          "
          (moveTagUp)="moveTag(group, -1)"
          (moveTagDown)="moveTag(group, 1)"
          (tagDragStarted)="startTagDrag(group)"
          (tagDragMoved)="moveTagDrag($event)"
          (tagDragEnded)="endTagDrag()"
        />
      }
    </div>
```

- [ ] **Step 5: Style the gap**

In `organise-section.component.scss`, after the `.tree` rule:

```scss
// The gap a dragged tag would land in: the panel below it steps down, and the
// slot it leaves is outlined where the tag will go (#1708).
.tree > app-organise-tag-group {
  position: relative;
}

.tree > .gap-before {
  margin-top: calc(var(--tap-target) + var(--space-4));
}

.tree > .gap-before::before {
  content: '';
  position: absolute;
  inset-inline: 0;
  bottom: calc(100% + var(--space-4));
  height: var(--tap-target);
  border: 2px dashed var(--accent);
  border-radius: var(--radius-lg);
}

@media (prefers-reduced-motion: no-preference) {
  .tree > app-organise-tag-group {
    transition: margin-top 0.12s ease;
  }
}
```

- [ ] **Step 6: Run the section and tag-group specs**

Run: `docker compose exec -T frontend npx jest src/app/settings/organise`
Expected: PASS (all organise specs).

- [ ] **Step 7: Run the gate**

Run: `docker compose exec -T frontend npm run check`
Expected: lint, Prettier, Stylelint, tsc and all Jest suites pass. If Prettier flags a file, `docker compose exec -T frontend npx prettier --write <that file>` and rerun.

- [ ] **Step 8: Commit**

```bash
git add frontend/src/app/settings/organise/organise-section.component.ts frontend/src/app/settings/organise/organise-section.component.html frontend/src/app/settings/organise/organise-section.component.scss frontend/src/app/settings/organise/organise-section.component.spec.ts
git commit -m "feat(#1708): open a gap between tags while a tag is dragged on organise"
```

---

### Task 4: Prove it in the real app

**Files:** none (verification only).

- [ ] **Step 1: Make sure :4200 serves the new code** — `docker compose ps frontend` is up; load `http://localhost:4200/settings/organise` in the built-in browser (Tree view, no filter).

- [ ] **Step 2: Drag a tag with real pointer events** — with `mcp__Claude_Browser__computer` `left_click_drag` (or a slow press/move/move/release sequence) drag the second tag's header to above the first tag. While holding (take a screenshot mid-drag if the tool allows, else use `javascript_tool` to dispatch `pointerdown`/`pointermove` and read `document.querySelectorAll('.gap-before').length` before releasing), confirm:
  - one dashed slot appears between panels and the panels below shift;
  - no other tag header gets the accent background.
  After release the order changed accordingly.

- [ ] **Step 3: Check feed-onto-header still works** — drag a feed row of an expanded tag onto another tag's header: that header lights up, the feed moves there. Move it back afterwards.

- [ ] **Step 4: Restore the order** — drag the tag back (or use the arrows) so the dev account's tag order is as it was found.

- [ ] **Step 5: Scan the browser console** — `mcp__Claude_Browser__read_console_messages` with `onlyErrors: true`: no new errors.

---

## Self-Review

- Spec coverage: gap between tags + shifting (Task 3 Steps 4–5), no tag-onto-tag highlight (Task 2 predicate + `lifted`), drop above first / below last (Task 1 `moveToGap`, Task 3 tests), feed-onto-header kept (Task 2 keeps `onHeaderDropped` feed path and predicate accepts feeds; Task 4 Step 3), filter guard (Task 3 test + `endTagDrag`), arrows untouched, never nested (no new `cdkDropList`). Touch: unchanged arrows on coarse pointers (Global Constraints).
- Names: `TagMove`, `gapIndexAt`, `isNoOpGap`, `moveToGap`, `verticalMiddle`, `TagDragGap.{gap,start,move,end}`, `tagDragStarted/tagDragMoved/tagDragEnded`, `acceptsFeeds`, `headerLifted`, `startTagDrag/moveTagDrag/endTagDrag/treeTop` — used consistently across tasks.
