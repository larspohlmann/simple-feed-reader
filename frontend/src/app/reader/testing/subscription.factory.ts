import { SubscriptionDto } from '../models';

/**
 * A healthy, minimally-filled `SubscriptionDto` for specs. Pass `partial` to
 * override only the fields a test cares about.
 */
export function makeSubscription(partial: Partial<SubscriptionDto> = {}): SubscriptionDto {
  return {
    id: 1,
    feedId: 1,
    title: 'Feed',
    faviconUrl: null,
    customTitle: null,
    feedUrl: 'https://feed.example/rss',
    siteUrl: null,
    description: null,
    imageUrl: null,
    status: 'active',
    sourceFormat: 'xml',
    createdAt: '2026-01-01T00:00:00Z',
    lastFetchedAt: null,
    lastSuccessfulFetchAt: null,
    consecutiveFailures: 0,
    lastErrorMessage: null,
    position: 0,
    tags: [],
    unreadCount: 0,
    includeInAllItems: true,
    includeInForYou: true,
    ...partial,
  };
}
