// format.spec.ts
import {
  bytesToKb,
  formatDateOr,
  formatLongDate,
  relativeTime,
  trialDaysRemaining,
  trialExpired,
} from './format';

describe('relativeTime', () => {
  const now = new Date('2026-07-22T12:00:00Z');

  it('formats buckets in English', () => {
    expect(relativeTime('2026-07-22T11:59:30Z', 'en', now)).toBe('now');
    const min = relativeTime('2026-07-22T11:30:00Z', 'en', now);
    expect(min).toContain('30');
    expect(min).toContain('ago');
    expect(relativeTime('2026-07-22T09:00:00Z', 'en', now)).toContain('ago');
    expect(relativeTime('2026-07-20T12:00:00Z', 'en', now)).toContain('ago');
  });

  it('formats in German', () => {
    expect(relativeTime('2026-07-22T11:59:30Z', 'de', now)).toBe('jetzt');
    const min = relativeTime('2026-07-22T11:30:00Z', 'de', now);
    expect(min).toContain('vor');
    expect(min).toContain('30');
  });

  it('handles bad input', () => expect(relativeTime('nope', 'en', now)).toBe(''));
});

describe('formatLongDate', () => {
  it('renders a localised long date and empties on bad input', () => {
    expect(formatLongDate('2026-07-22T12:00:00Z', 'en')).toContain('2026');
    expect(formatLongDate('2026-07-22T12:00:00Z', 'de')).toContain('2026');
    expect(formatLongDate('nope', 'en')).toBe('');
  });
});

describe('formatDateOr', () => {
  it('renders the localised date when one is present', () => {
    expect(formatDateOr('2026-07-22T12:00:00Z', 'en', 'never')).toContain('2026');
  });

  it('renders the given fallback, not the date formatter’s own empty string, when there is none', () => {
    expect(formatDateOr(null, 'en', 'never')).toBe('never');
  });
});

describe('trialExpired', () => {
  const now = new Date('2026-07-22T12:00:00Z').getTime();

  it('is false when there is no trial at all', () => {
    expect(trialExpired(null, now)).toBe(false);
  });

  it('is true once the trial end date has passed', () => {
    expect(trialExpired('2026-07-22T11:59:59Z', now)).toBe(true);
  });

  it('is true at the exact moment the trial ends', () => {
    expect(trialExpired('2026-07-22T12:00:00Z', now)).toBe(true);
  });

  it('is false while the trial is still running', () => {
    expect(trialExpired('2026-07-23T12:00:00Z', now)).toBe(false);
  });
});

describe('trialDaysRemaining', () => {
  const now = new Date('2026-07-22T12:00:00Z').getTime();

  it('is null when there is no trial at all', () => {
    expect(trialDaysRemaining(null, now)).toBeNull();
  });

  it('is null once the trial end date has passed', () => {
    expect(trialDaysRemaining('2026-07-22T11:59:59Z', now)).toBeNull();
  });

  it('rounds up to whole days for a still-running trial', () => {
    const endsAt = new Date(now + 2.5 * 86_400_000).toISOString();
    expect(trialDaysRemaining(endsAt, now)).toBe(3);
  });

  it('reads exactly one day left as 1, not 0', () => {
    const endsAt = new Date(now + 86_400_000).toISOString();
    expect(trialDaysRemaining(endsAt, now)).toBe(1);
  });
});

describe('bytesToKb', () => {
  it('rounds to the nearest whole KB', () => {
    expect(bytesToKb(2048)).toBe(2);
    expect(bytesToKb(1536)).toBe(2);
    expect(bytesToKb(1000)).toBe(1);
  });

  it('floors at 1 KB for any nonzero byte count', () => {
    expect(bytesToKb(1)).toBe(1);
    expect(bytesToKb(100)).toBe(1);
  });

  it('floors at 1 KB even for zero bytes', () => {
    expect(bytesToKb(0)).toBe(1);
  });
});
