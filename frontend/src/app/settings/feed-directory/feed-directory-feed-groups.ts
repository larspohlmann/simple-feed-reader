import { FeedDirectoryFeed } from './feed-directory-rows';
import { hostOf, parseUrl } from './feed-directory-url';

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
  const parsed = parseUrl(feedUrl);
  if (parsed === null || parsed.hostname === '') return feedUrl;
  const path = `${parsed.pathname}${parsed.search}`;
  return hostOf(parsed) === siteHost ? path : `${parsed.hostname}${path}`;
}
