import { pluralKey } from '../core/plural-key';
import { RefreshReport, SubscriptionDto } from './models';

export interface HealthReason {
  key: string;
  params?: Record<string, number>;
}

const MS_PER_DAY = 86_400_000;

export function isUnhealthy(sub: SubscriptionDto): boolean {
  return sub.status !== 'active';
}

/** A dead feed: the server has given up refreshing it, not merely erroring. */
export function isGone(sub: SubscriptionDto): boolean {
  return sub.status === 'gone';
}

/** A manual refresh recovered the feed when nothing failed and at least one
 *  fetch landed — a 304 Not-Modified counts, since it resurrects the feed. */
export function isFeedRecovered(report: RefreshReport): boolean {
  return report.failed === 0 && report.fetched + report.notModified >= 1;
}

/** Gone feeds first (dead, act now), then erroring; each alphabetical by title. */
export function unhealthyFeeds(subs: SubscriptionDto[]): SubscriptionDto[] {
  const rank = (s: SubscriptionDto): number => (isGone(s) ? 0 : 1);
  return subs
    .filter(isUnhealthy)
    .sort((a, b) => rank(a) - rank(b) || a.title.localeCompare(b.title));
}

export function daysSince(iso: string, now: Date): number {
  const elapsed = now.getTime() - new Date(iso).getTime();
  return Math.max(0, Math.floor(elapsed / MS_PER_DAY));
}

export function feedHealthReason(sub: SubscriptionDto, now: Date): HealthReason {
  if (isGone(sub)) return { key: 'settings.health.reason.gone' };
  if (sub.lastSuccessfulFetchAt !== null) {
    const days = daysSince(sub.lastSuccessfulFetchAt, now);
    if (days === 0) return { key: 'settings.health.reason.noUpdateToday' };
    return { key: pluralKey('settings.health.reason.noUpdate', days), params: { days } };
  }
  return {
    key: pluralKey('settings.health.reason.failedAttempts', sub.consecutiveFailures),
    params: { count: sub.consecutiveFailures },
  };
}
