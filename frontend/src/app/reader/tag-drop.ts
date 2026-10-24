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

interface DropContext {
  readonly dragged: TagDto;
  readonly tree: TagTree;
  readonly layout: TagDropLayout;
}

const NEST_FROM = 0.25;
const NEST_TO = 0.75;

// The spec fixes this four-argument call, so the max-params limit yields here.
// eslint-disable-next-line @typescript-eslint/max-params
export function resolveTagDrop(
  dragged: TagDto,
  tags: readonly TagDto[],
  layout: TagDropLayout,
  pointerY: number,
): TagDrop | null {
  const tree = new TagTree(tags);
  const slot = slotAt({ dragged, tree, layout }, pointerY);
  if (slot === null || !tree.mayLandUnder(dragged, slot.parentId)) return null;
  const order = tree.orderWith(dragged, slot);
  if (slot.parentId === dragged.parentId && sameOrder(order, tree.siblingIds(slot.parentId))) {
    return null;
  }
  return { parentId: slot.parentId, order, mark: slot.mark };
}

function slotAt({ dragged, tree, layout }: DropContext, pointerY: number): Slot | null {
  if (pointerY > layout.listBottom) return tree.topLevelEnd();
  const index = nearestHeaderIndex(layout.headers, pointerY);
  if (index < 0) return null;
  const header = layout.headers[index];
  const anchor = tree.byId(header.tagId);
  if (anchor === undefined) return null;
  const share = (pointerY - header.top) / Math.max(header.bottom - header.top, 1);

  if (
    anchor.parentId === null &&
    tree.mayNest(dragged, anchor) &&
    share >= NEST_FROM &&
    share < NEST_TO
  ) {
    return { parentId: anchor.id, beforeId: null, mark: { kind: 'nest', tagId: anchor.id } };
  }
  if (share < 0.5) {
    return {
      parentId: anchor.parentId,
      beforeId: anchor.id,
      mark: { kind: 'before', tagId: anchor.id },
    };
  }
  return slotBelow(anchor, layout.headers[index + 1], tree);
}

/** The lower half of a header: the first child of a parent whose children follow it, else the slot after it. */
function slotBelow(anchor: TagDto, nextHeader: TagHeaderBox | undefined, tree: TagTree): Slot {
  const firstChild = nextHeader === undefined ? undefined : tree.byId(nextHeader.tagId);
  if (firstChild !== undefined && firstChild.parentId === anchor.id) {
    return {
      parentId: anchor.id,
      beforeId: firstChild.id,
      mark: { kind: 'before', tagId: firstChild.id },
    };
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
    return last === undefined
      ? null
      : { parentId: null, beforeId: null, mark: { kind: 'after', tagId: last.id } };
  }

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
