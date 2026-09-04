import { RefreshReport } from './models';
import { makeSubscription as make } from './testing/subscription.factory';
import {
  feedHealthReason,
  isFeedRecovered,
  isGone,
  isUnhealthy,
  unhealthyFeeds,
} from './feed-health';

const report = (over: Partial<RefreshReport>): RefreshReport => ({
  status: 'completed',
  progress: { done: 1, total: 1 },
  fetched: 0,
  notModified: 0,
  failed: 0,
  throttled: 0,
  skippedForBudget: 0,
  remaining: 0,
  pruned: 0,
  ...over,
});

describe('feed health', () => {
  const now = new Date('2026-02-10T00:00:00Z');

  it('treats erroring and gone as unhealthy, active as healthy', () => {
    expect(isUnhealthy(make({ status: 'active' }))).toBe(false);
    expect(isUnhealthy(make({ status: 'erroring' }))).toBe(true);
    expect(isUnhealthy(make({ status: 'gone' }))).toBe(true);
  });

  it('reports only a gone feed as gone', () => {
    expect(isGone(make({ status: 'gone' }))).toBe(true);
    expect(isGone(make({ status: 'erroring' }))).toBe(false);
    expect(isGone(make({ status: 'active' }))).toBe(false);
  });

  it('counts a refresh as recovered only when nothing failed and something landed', () => {
    expect(isFeedRecovered(report({ fetched: 1 }))).toBe(true);
    expect(isFeedRecovered(report({ notModified: 1 }))).toBe(true);
    expect(isFeedRecovered(report({ fetched: 1, failed: 1 }))).toBe(false);
    expect(isFeedRecovered(report({ fetched: 0, notModified: 0 }))).toBe(false);
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
    ).toEqual({ key: 'settings.health.reason.noUpdateOther', params: { days: 6 } });
  });

  it('describes an erroring feed that last succeeded today as updated today', () => {
    expect(
      feedHealthReason(
        make({ status: 'erroring', lastSuccessfulFetchAt: '2026-02-10T00:00:00Z' }),
        now,
      ),
    ).toEqual({ key: 'settings.health.reason.noUpdateToday' });
  });

  it('singularises the no-update reason at one day', () => {
    expect(
      feedHealthReason(
        make({ status: 'erroring', lastSuccessfulFetchAt: '2026-02-09T00:00:00Z' }),
        now,
      ),
    ).toEqual({ key: 'settings.health.reason.noUpdateOne', params: { days: 1 } });
  });

  it('describes an erroring feed that never succeeded by its failure streak', () => {
    expect(feedHealthReason(make({ status: 'erroring', consecutiveFailures: 3 }), now)).toEqual({
      key: 'settings.health.reason.failedAttemptsOther',
      params: { count: 3 },
    });
  });

  it('singularises the failure streak at one attempt', () => {
    expect(feedHealthReason(make({ status: 'erroring', consecutiveFailures: 1 }), now)).toEqual({
      key: 'settings.health.reason.failedAttemptsOne',
      params: { count: 1 },
    });
  });
});
