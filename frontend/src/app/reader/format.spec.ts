// format.spec.ts
import {
  bytesToKb,
  formatCost,
  formatDateOr,
  formatDuration,
  formatLongDate,
  formatTime,
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

describe('formatTime', () => {
  // Renders in the test runner's own timezone, so this asserts shape (not an
  // exact clock value) to stay green regardless of where the suite runs.
  it('renders a zero-padded 24-hour HH:MM clock time', () => {
    expect(formatTime('2026-07-22T09:05:00Z')).toMatch(/^\d{2}:\d{2}$/);
  });

  it('empties on bad input', () => {
    expect(formatTime('nope')).toBe('');
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

describe('formatCost', () => {
  it('renders a price the way the provider writes it in its own logs', () => {
    expect(formatCost(1_370_000, 'en')).toBe('$ 0.00137');
  });

  it('renders an em dash when the provider reported no price at all', () => {
    expect(formatCost(null, 'en')).toBe('—');
  });

  it('renders a cost of zero as zero rather than as unpriced', () => {
    expect(formatCost(0, 'en')).toBe('$ 0.00000');
  });

  it('keeps the symbol leading but the separator local', () => {
    expect(formatCost(1_370_000, 'de')).toBe('$ 0,00137');
  });

  it('rounds a sub-cent remainder to the nearest five-decimal figure', () => {
    expect(formatCost(1_374_700, 'en')).toBe('$ 0.00137');
    expect(formatCost(1_375_100, 'en')).toBe('$ 0.00138');
  });

  it('renders a large total without losing the fixed precision', () => {
    expect(formatCost(918_200_000, 'en')).toBe('$ 0.91820');
  });
});

describe('formatDuration', () => {
  it('pads the seconds so the column reads as a duration', () => {
    expect(formatDuration(47)).toBe('0:47');
  });

  it('rolls a full minute over', () => {
    expect(formatDuration(60)).toBe('1:00');
  });

  it('pads a single-digit seconds remainder', () => {
    expect(formatDuration(127)).toBe('2:07');
  });

  it('renders a run that took no measurable time as zero', () => {
    expect(formatDuration(0)).toBe('0:00');
  });

  it('keeps counting in minutes rather than rolling into hours', () => {
    expect(formatDuration(3_723)).toBe('62:03');
  });

  it('never renders a negative duration', () => {
    expect(formatDuration(-5)).toBe('0:00');
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
