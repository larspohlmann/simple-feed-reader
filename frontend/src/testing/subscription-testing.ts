import { SubscriptionDto } from '../app/reader/models';

/** A healthy, untagged RSS subscription with nothing unread; `overrides` name what a test is about. */
export function aSubscription(overrides: Partial<SubscriptionDto> = {}): SubscriptionDto {
  const id = overrides.id ?? 1;
  return {
    id,
    feedId: id,
    title: `Fixture feed ${id}`,
    faviconUrl: null,
    customTitle: null,
    feedUrl: `https://fixtures.invalid/${id}/feed.xml`,
    siteUrl: null,
    description: null,
    imageUrl: null,
    status: 'active',
    sourceFormat: 'xml',
    createdAt: '2026-08-01T00:00:00+00:00',
    lastFetchedAt: '2026-08-01T00:00:00+00:00',
    lastSuccessfulFetchAt: '2026-08-01T00:00:00+00:00',
    lastNewContentAt: null,
    nextFetchAt: null,
    consecutiveFailures: 0,
    lastErrorMessage: null,
    position: 0,
    tags: [],
    unreadCount: 0,
    entryCount: 0,
    includeInAllItems: true,
    includeInForYou: true,
    includeInSavedSearches: true,
    social: null,
    ...overrides,
  };
}
