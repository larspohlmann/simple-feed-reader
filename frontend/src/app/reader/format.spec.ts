// format.spec.ts
import { relativeTime } from './format';
describe('relativeTime', () => {
  const now = new Date('2026-07-22T12:00:00Z');
  it('formats buckets', () => {
    expect(relativeTime('2026-07-22T11:59:30Z', now)).toBe('just now');
    expect(relativeTime('2026-07-22T11:30:00Z', now)).toBe('30 min ago');
    expect(relativeTime('2026-07-22T09:00:00Z', now)).toBe('3 h ago');
    expect(relativeTime('2026-07-20T12:00:00Z', now)).toBe('2 d ago');
  });
  it('handles bad input', () => expect(relativeTime('nope', now)).toBe(''));
});
