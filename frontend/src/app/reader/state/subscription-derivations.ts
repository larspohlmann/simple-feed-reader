import { SubscriptionDto, TagDto } from '../models';

export interface TagNode {
  tag: TagDto;
  subscriptions: SubscriptionDto[];
  /** The tag's children in position order, each with its own. */
  children: TagNode[];
  unreadCount: number;
  entryCount: number;
  descendantCount: number;
}

/**
 * Build the sidebar tag tree, at every level. Tag NODES and their order come
 * from `orderedTags` (already in `tag.position` order) so every tag shows,
 * including empty ones; each node's feeds come from `subs` carrying the tag.
 * A root's unread count is the API's roll-up; its entry count spans its own
 * feeds and every tag's below it, a feed carrying both counted once. Falls back to
 * the tags embedded on subscriptions, name-ordered, before the tag list has
 * loaded, so tagged feeds never briefly vanish.
 */
export function buildTagTree(
  subscriptions: SubscriptionDto[],
  orderedTags: TagDto[] = [],
  tagUnread: ReadonlyMap<number, number> = new Map(),
): TagNode[] {
  const tags = orderedTags.length > 0 ? orderedTags : embeddedTagsByName(subscriptions);
  const byTagId = bucketByTag(subscriptions);

  const children = childrenByParentId(tags);
  const node = (tag: TagDto): TagNode => {
    const below = (children.get(tag.id) ?? []).map(node);
    const branch: TagNode = {
      tag,
      subscriptions: feedsOf(byTagId, tag.id),
      children: below,
      unreadCount: tagUnread.get(tag.id) ?? 0,
      entryCount: 0,
      descendantCount: countDescendants(below),
    };
    return { ...branch, entryCount: totalEntryCount(distinctFeedsOf(branch)) };
  };
  return rootsOf(tags).map(node);
}

/** Every tag below these nodes, at any depth. */
export function countDescendants(children: readonly TagNode[]): number {
  return children.reduce((count, child) => count + 1 + child.descendantCount, 0);
}

/** Every node, each followed by the tags below it: the order the sidebar shows. */
export function flattenTagTree(nodes: TagNode[]): TagNode[] {
  return nodes.flatMap((node) => [node, ...flattenTagTree(node.children)]);
}

export function tagNodesById(nodes: TagNode[]): Map<number, TagNode> {
  return new Map(flattenTagTree(nodes).map((node) => [node.tag.id, node]));
}

/** A tag whose parent is not among the known ids counts as a root: the fallback tree can lack a feedless parent. */
export function isRootTag(tag: TagDto, knownIds: ReadonlySet<number>): boolean {
  return tag.parentId === null || !knownIds.has(tag.parentId);
}

export function rootsOf(tags: readonly TagDto[]): TagDto[] {
  const knownIds = new Set(tags.map((tag) => tag.id));
  return tags.filter((tag) => isRootTag(tag, knownIds));
}

/** Each parent's children, in the order given. */
export function childrenByParentId(tags: readonly TagDto[]): Map<number, TagDto[]> {
  const byParentId = new Map<number, TagDto[]>();
  for (const tag of tags) {
    if (tag.parentId === null) continue;
    byParentId.set(tag.parentId, [...(byParentId.get(tag.parentId) ?? []), tag]);
  }
  return byParentId;
}

/** Each tag's sibling set (the roots, or one parent's children) as ids in the order given. */
export function siblingIdsByTagId(tags: readonly TagDto[]): Map<number, readonly number[]> {
  const children = childrenByParentId(tags);
  const siblingSets = [rootsOf(tags), ...tags.map((tag) => children.get(tag.id) ?? [])];
  const byTagId = new Map<number, readonly number[]>();
  for (const siblings of siblingSets) {
    const ids = siblings.map((sibling) => sibling.id);
    for (const id of ids) byTagId.set(id, ids);
  }
  return byTagId;
}

/** A fresh, mutable copy of the ids `tag` may be ordered among; empty when `tags` lacks it. */
export function siblingIdsOf(tags: readonly TagDto[], tag: TagDto): number[] {
  return [...(siblingIdsByTagId(tags).get(tag.id) ?? [])];
}

/** Each tag followed by the tags below it, whatever order the caller holds them in. */
export function tagsInTreeOrder(tags: TagDto[]): TagDto[] {
  const children = childrenByParentId(tags);
  const branch = (tag: TagDto): TagDto[] => [tag, ...(children.get(tag.id) ?? []).flatMap(branch)];
  return rootsOf(tags).flatMap(branch);
}

/** A nested tag reads with its ancestors, so a pill never shows a bare child out of context. */
export function tagLabel(tag: TagDto, tags: readonly TagDto[]): string {
  return tagPath(tag, new Map(tags.map((candidate) => [candidate.id, candidate]))).join(' / ');
}

/** The names from the top-most known ancestor down to the tag. */
export function tagPath(tag: TagDto, byId: ReadonlyMap<number, TagDto>): string[] {
  const parent = tag.parentId === null ? undefined : byId.get(tag.parentId);
  return parent === undefined ? [tag.name] : [...tagPath(parent, byId), tag.name];
}

export const SUGGESTED_TAG_LIMIT = 4;

/** The tags of the most recently added subscriptions, newest first, minus the ones already picked. */
export function suggestedTags(
  subscriptions: readonly SubscriptionDto[],
  selected: ReadonlySet<number>,
  exceptSubscriptionId: number | null,
): TagDto[] {
  const seen = new Set<number>(selected);
  const suggested: TagDto[] = [];
  const newestFirst = subscriptions
    .filter((subscription) => subscription.id !== exceptSubscriptionId)
    .sort((left, right) => (left.createdAt < right.createdAt ? 1 : -1));
  for (const tag of newestFirst.flatMap((subscription) => subscription.tags)) {
    if (suggested.length === SUGGESTED_TAG_LIMIT) break;
    if (seen.has(tag.id)) continue;
    seen.add(tag.id);
    suggested.push(tag);
  }
  return suggested;
}

interface TaggedFeed {
  sub: SubscriptionDto;
  pos: number;
}

/** One pass over subscriptions buckets each feed under every tag it carries, with its per-tag position. */
function bucketByTag(subscriptions: SubscriptionDto[]): Map<number, TaggedFeed[]> {
  const byTagId = new Map<number, TaggedFeed[]>();
  for (const sub of subscriptions) {
    for (const tag of sub.tags) {
      let bucket = byTagId.get(tag.id);
      if (!bucket) {
        bucket = [];
        byTagId.set(tag.id, bucket);
      }
      bucket.push({ sub, pos: tag.position });
    }
  }
  return byTagId;
}

function feedsOf(byTagId: Map<number, TaggedFeed[]>, tagId: number): SubscriptionDto[] {
  return (byTagId.get(tagId) ?? [])
    .sort((left, right) => left.pos - right.pos || left.sub.title.localeCompare(right.sub.title))
    .map((tagged) => tagged.sub);
}

function distinctFeedsOf(node: TagNode): SubscriptionDto[] {
  const byId = new Map<number, SubscriptionDto>();
  for (const feed of subtreeFeeds(node)) byId.set(feed.id, feed);
  return [...byId.values()];
}

function subtreeFeeds(node: TagNode): SubscriptionDto[] {
  return [...node.subscriptions, ...node.children.flatMap(subtreeFeeds)];
}

export function totalEntryCount(subscriptions: SubscriptionDto[]): number {
  return subscriptions.reduce((total, subscription) => total + subscription.entryCount, 0);
}

export interface FeedGroupCounts {
  readonly unreadCount: number;
  readonly entryCount: number;
}

/** Fallback tag set derived from subscriptions (only non-empty tags), by name. */
function embeddedTagsByName(subscriptions: SubscriptionDto[]): TagDto[] {
  const byId = new Map<number, TagDto>();
  for (const subscription of subscriptions) {
    for (const tag of subscription.tags) {
      if (!byId.has(tag.id)) {
        byId.set(tag.id, {
          id: tag.id,
          name: tag.name,
          color: tag.color,
          icon: tag.icon,
          position: 0,
          parentId: tag.parentId,
        });
      }
    }
  }
  return [...byId.values()].sort((left, right) => left.name.localeCompare(right.name));
}

export function taggedSubscriptions(subscriptions: SubscriptionDto[]): SubscriptionDto[] {
  return subscriptions.filter((subscription) => subscription.tags.length > 0);
}

export function untaggedSubscriptions(subscriptions: SubscriptionDto[]): SubscriptionDto[] {
  return subscriptions
    .filter((subscription) => subscription.tags.length === 0)
    .sort((left, right) => left.position - right.position);
}

/** All-items list total: a feed excluded from All items (`includeInAllItems:
 *  false`) contributes nothing. */
export function sumEntries(subscriptions: SubscriptionDto[]): number {
  return subscriptions.reduce(
    (total, subscription) =>
      subscription.includeInAllItems ? total + subscription.entryCount : total,
    0,
  );
}
