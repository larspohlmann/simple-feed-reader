import { SubscriptionDto, TagDto } from '../models';

export interface TagNode {
  tag: TagDto;
  subscriptions: SubscriptionDto[];
  /** A root's children in position order; always empty on a child (#1736). */
  children: TagNode[];
  unreadCount: number;
  entryCount: number;
}

/**
 * Build the sidebar tag tree, one level deep. Tag NODES and their order come
 * from `orderedTags` (already in `tag.position` order) so every tag shows,
 * including empty ones; each node's feeds come from `subs` carrying the tag.
 * A root's unread count is the API's roll-up; its entry count spans its own
 * feeds and its children's, a feed carrying both counted once. Falls back to
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
  const knownIds = new Set(tags.map((tag) => tag.id));
  const isRoot = (tag: TagDto): boolean => tag.parentId === null || !knownIds.has(tag.parentId);

  const leaf = (tag: TagDto): TagNode => {
    const feeds = feedsOf(byTagId, tag.id);
    return {
      tag,
      subscriptions: feeds,
      children: [],
      unreadCount: tagUnread.get(tag.id) ?? 0,
      entryCount: totalEntryCount(feeds),
    };
  };

  return tags.filter(isRoot).map((root) => {
    const node = {
      ...leaf(root),
      children: tags.filter((tag) => tag.parentId === root.id).map(leaf),
    };
    return { ...node, entryCount: totalEntryCount(distinctFeedsOf(node)) };
  });
}

/** Every node, each root followed by its children: the order the sidebar shows. */
export function flattenTagTree(nodes: TagNode[]): TagNode[] {
  return nodes.flatMap((node) => [node, ...node.children]);
}

/** Each root followed by its children, whatever order the caller holds them in. */
export function tagsInTreeOrder(tags: TagDto[]): TagDto[] {
  const knownIds = new Set(tags.map((tag) => tag.id));
  return tags
    .filter((tag) => tag.parentId === null || !knownIds.has(tag.parentId))
    .flatMap((root) => [root, ...tags.filter((tag) => tag.parentId === root.id)]);
}

/** A child reads with its parent, so a pill never shows a bare child out of context. */
export function tagLabel(tag: TagDto, tags: readonly TagDto[]): string {
  const parent = tags.find((candidate) => candidate.id === tag.parentId);
  return parent ? `${parent.name} / ${tag.name}` : tag.name;
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
  const feeds = [...node.subscriptions, ...node.children.flatMap((child) => child.subscriptions)];
  for (const feed of feeds) {
    byId.set(feed.id, feed);
  }
  return [...byId.values()];
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
