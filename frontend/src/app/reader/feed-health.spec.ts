import { SubscriptionDto } from './models';
import { feedHealthReason, isUnhealthy, unhealthyFeeds } from './feed-health';

const make = (over: Partial<SubscriptionDto>): SubscriptionDto => ({
  id: 1,
  feedId: 10,
  title: 't',
  faviconUrl: null,
  customTitle: null,
  feedUrl: 'https://f/1',
  siteUrl: null,
  description: null,
  imageUrl: null,
  status: 'active',
  sourceFormat: 'xml',
  createdAt: 'x',
  lastFetchedAt: null,
  lastSuccessfulFetchAt: null,
  consecutiveFailures: 0,
  lastErrorMessage: null,
  position: 0,
  tags: [],
  unreadCount: 0,
  includeInAllItems: true,
  includeInForYou: true,
  ...over,
});

describe('feed health', () => {
  const now = new Date('2026-02-10T00:00:00Z');

  it('treats erroring and gone as unhealthy, active as healthy', () => {
    expect(isUnhealthy(make({ status: 'active' }))).toBe(false);
    expect(isUnhealthy(make({ status: 'erroring' }))).toBe(true);
    expect(isUnhealthy(make({ status: 'gone' }))).toBe(true);
  });

  it('lists gone before erroring, then by title', () => {
    const subs = [
      make({ id: 1, title: 'Bravo', status: 'erroring' }),
      make({ id: 2, title: 'Alpha', status: 'gone' }),
      make({ id: 3, title: 'Charlie', status: 'active' }),
      make({ id: 4, title: 'Alpha', status: 'erroring' }),
    ];
    expect(unhealthyFeeds(subs).map((s) => s.id)).toEqual([2, 4, 1]);
  });

  it('describes a gone feed as no longer available', () => {
    expect(feedHealthReason(make({ status: 'gone' }), now)).toEqual({
      key: 'settings.health.reason.gone',
    });
  });

  it('describes an erroring feed by days since last success', () => {
    expect(
      feedHealthReason(
        make({ status: 'erroring', lastSuccessfulFetchAt: '2026-02-04T00:00:00Z' }),
        now,
      ),
    ).toEqual({ key: 'settings.health.reason.noUpdate', params: { days: 6 } });
  });

  it('describes an erroring feed that never succeeded by its failure streak', () => {
    expect(feedHealthReason(make({ status: 'erroring', consecutiveFailures: 3 }), now)).toEqual({
      key: 'settings.health.reason.failedAttempts',
      params: { count: 3 },
    });
  });
});
