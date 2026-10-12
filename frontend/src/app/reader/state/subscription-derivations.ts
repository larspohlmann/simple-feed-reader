import { SubscriptionDto, TagDto } from '../models';

export interface TagNode {
  tag: TagDto;
  subscriptions: SubscriptionDto[];
  unreadCount: number;
  entryCount: number;
}

/**
 * Build the sidebar tag tree. Tag NODES and their order come from
 * `orderedTags` (already in `tag.position` order) so every tag shows,
 * including empty ones; each node's feeds come from `subs` carrying the tag.
 * Falls back to the tags embedded on subscriptions, name-ordered, before the
 * tag list has loaded, so tagged feeds never briefly vanish.
 */
export function buildTagTree(
  subscriptions: SubscriptionDto[],
  orderedTags: TagDto[] = [],
): TagNode[] {
  const tags = orderedTags.length > 0 ? orderedTags : embeddedTagsByName(subscriptions);

  // One pass over subscriptions buckets each feed under every tag it carries,
  // capturing the per-tag position up front so the sort reads it once.
  const byTagId = new Map<number, { sub: SubscriptionDto; pos: number }[]>();
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

  return tags.map((tag) => {
    const feeds = (byTagId.get(tag.id) ?? [])
      .sort((left, right) => left.pos - right.pos || left.sub.title.localeCompare(right.sub.title))
      .map((tagged) => tagged.sub);
    return {
      tag,
      subscriptions: feeds,
      unreadCount: feeds.reduce((total, subscription) => total + subscription.unreadCount, 0),
      entryCount: feeds.reduce((total, subscription) => total + subscription.entryCount, 0),
    };
  });
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
        });
      }
    }
  }
  return [...byId.values()].sort((left, right) => left.name.localeCompare(right.name));
}

export function untaggedSubscriptions(subscriptions: SubscriptionDto[]): SubscriptionDto[] {
  return subscriptions
    .filter((subscription) => subscription.tags.length === 0)
    .sort((left, right) => left.position - right.position);
}

/** All-items badge total: a feed excluded from All items (`includeInAllItems:
 *  false`) contributes nothing here, though it still counts under its own
 *  per-feed row and any tag it carries (see `buildTagTree`). */
export function sumUnread(subscriptions: SubscriptionDto[]): number {
  return subscriptions.reduce(
    (total, subscription) =>
      subscription.includeInAllItems ? total + subscription.unreadCount : total,
    0,
  );
}

/** All-items list total: the same inclusion rule as `sumUnread`, over entry
 *  counts instead of unread counts. */
export function sumEntries(subscriptions: SubscriptionDto[]): number {
  return subscriptions.reduce(
    (total, subscription) =>
      subscription.includeInAllItems ? total + subscription.entryCount : total,
    0,
  );
}
