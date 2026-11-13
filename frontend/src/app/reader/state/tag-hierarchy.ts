import { TagDto } from '../models';
import { isRootTag } from './subscription-derivations';

/** Levels below the top a tag may sit; a root is at 0. Mirrors the backend's `Tag::MAX_DEPTH`. */
export const MAX_TAG_DEPTH = 2;

export class TagHierarchy {
  private readonly parentIds: ReadonlyMap<number, number | null>;
  private readonly childIds: ReadonlyMap<number, readonly number[]>;

  constructor(tags: readonly TagDto[]) {
    const knownIds = new Set(tags.map((tag) => tag.id));
    this.parentIds = new Map(
      tags.map((tag) => [tag.id, isRootTag(tag, knownIds) ? null : tag.parentId]),
    );
    const childIds = new Map<number, number[]>();
    for (const [id, parentId] of this.parentIds) {
      if (parentId !== null) childIds.set(parentId, [...(childIds.get(parentId) ?? []), id]);
    }
    this.childIds = childIds;
  }

  depthOf(tagId: number): number {
    const parentId = this.parentIds.get(tagId) ?? null;
    return parentId === null ? 0 : this.depthOf(parentId) + 1;
  }

  heightOf(tagId: number): number {
    return (this.childIds.get(tagId) ?? []).reduce(
      (height, childId) => Math.max(height, this.heightOf(childId) + 1),
      0,
    );
  }

  ancestorIdsOf(tagId: number): number[] {
    const parentId = this.parentIds.get(tagId) ?? null;
    return parentId === null ? [] : [parentId, ...this.ancestorIdsOf(parentId)];
  }

  /** Whether `tagId` is `ancestorId` itself or sits anywhere below it. */
  isWithin(tagId: number, ancestorId: number): boolean {
    if (tagId === ancestorId) return true;
    const parentId = this.parentIds.get(tagId) ?? null;
    return parentId !== null && this.isWithin(parentId, ancestorId);
  }

  /** Whether a tag (null: a new, childless one) may sit under `parentId` (null: the top level). */
  mayPlaceUnder(tagId: number | null, parentId: number | null): boolean {
    if (parentId === null) return true;
    if (tagId !== null && this.isWithin(parentId, tagId)) return false;
    const height = tagId === null ? 0 : this.heightOf(tagId);
    return this.depthOf(parentId) + 1 + height <= MAX_TAG_DEPTH;
  }
}
