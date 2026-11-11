import { FeedDirectoryFeed } from './feed-directory-rows';
import { feedDirectoryHost } from './feed-directory-url';

export interface FeedDirectoryFeedGroup {
  readonly category: string;
  readonly feeds: readonly FeedDirectoryFeed[];
}

export function feedGroups(feeds: readonly FeedDirectoryFeed[]): FeedDirectoryFeedGroup[] {
  const byCategory = new Map<string, FeedDirectoryFeed[]>();
  for (const feed of feeds) {
    byCategory.set(feed.category, [...(byCategory.get(feed.category) ?? []), feed]);
  }
  return [...byCategory].map(([category, members]) => ({ category, feeds: members }));
}

export function feedPath(feedUrl: string, siteHost: string): string {
  const host = feedDirectoryHost(feedUrl);
  if (host === '') return feedUrl;
  const parsed = new URL(feedUrl.trim());
  const path = `${parsed.pathname}${parsed.search}`;
  return host === siteHost ? path : `${parsed.hostname}${path}`;
}
