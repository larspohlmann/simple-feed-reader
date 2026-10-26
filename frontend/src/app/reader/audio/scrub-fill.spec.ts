import { scrubFill } from './scrub-fill';

describe('scrubFill', () => {
  it('returns the played and cached fractions', () => {
    expect(scrubFill(50, 120, 200)).toEqual({ played: 0.25, cached: 0.6 });
  });

  it('clamps to the track', () => {
    expect(scrubFill(-5, 400, 200)).toEqual({ played: 0, cached: 1 });
  });

  it('is empty without a duration', () => {
    expect(scrubFill(50, 120, 0)).toEqual({ played: 0, cached: 0 });
  });
});
