// format.spec.ts
import { formatLongDate, relativeTime } from './format';

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
