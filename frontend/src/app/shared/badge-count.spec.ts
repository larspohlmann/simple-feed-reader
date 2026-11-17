import { badgeCount } from './badge-count';

describe('badgeCount', () => {
  it('shows small counts as they are', () => {
    expect(badgeCount(0)).toBe('0');
    expect(badgeCount(99)).toBe('99');
  });

  it('caps larger counts at 99+', () => {
    expect(badgeCount(100)).toBe('99+');
  });
});
