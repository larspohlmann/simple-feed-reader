import { TestBed } from '@angular/core/testing';
import { TrendingBuiltAt } from './trending-built-at';

describe('TrendingBuiltAt (#2264)', () => {
  let builtAt: TrendingBuiltAt;

  beforeEach(() => {
    builtAt = TestBed.inject(TrendingBuiltAt);
  });

  it('knows no build time for a window it has not seen', () => {
    expect(builtAt.at('day')).toBeNull();
  });

  it("keeps each window's build time apart", () => {
    builtAt.record('day', '2026-11-16T07:30:00+00:00');
    builtAt.record('week', '2026-11-16T07:00:00+00:00');

    expect(builtAt.at('day')).toBe('2026-11-16T07:30:00+00:00');
    expect(builtAt.at('week')).toBe('2026-11-16T07:00:00+00:00');
  });

  it('forgets a build time the server no longer reports', () => {
    builtAt.record('day', '2026-11-16T07:30:00+00:00');
    builtAt.record('day', null);

    expect(builtAt.at('day')).toBeNull();
  });
});
