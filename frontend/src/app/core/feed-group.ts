export const FEED_GROUPS = ['tags', 'feeds'] as const;

export type FeedGroupName = (typeof FEED_GROUPS)[number];

export function isFeedGroupName(value: string | null): value is FeedGroupName {
  return FEED_GROUPS.some((group) => group === value);
}
