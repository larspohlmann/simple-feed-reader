import { TagDto } from '../models';

export function tagsById(tags: readonly TagDto[]): ReadonlyMap<number, TagDto> {
  return new Map(tags.map((tag) => [tag.id, tag]));
}

/** The tag first, then its parent, its parent's parent and so on, up to the top-most known one. */
export function withAncestors(tag: TagDto, byId: ReadonlyMap<number, TagDto>): TagDto[] {
  const chain = [tag];
  let parent = tag.parentId === null ? undefined : byId.get(tag.parentId);
  while (parent !== undefined && !chain.includes(parent)) {
    chain.push(parent);
    parent = parent.parentId === null ? undefined : byId.get(parent.parentId);
  }
  return chain;
}
