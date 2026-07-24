import { HEADER_NEAR_TOP, nextHeaderHidden } from './header-scroll';

describe('nextHeaderHidden', () => {
  it('never hides on a wide (desktop) layout', () => {
    expect(nextHeaderHidden(true, 0, 500, true)).toBe(false);
  });

  it('shows the header near the top regardless of direction', () => {
    expect(nextHeaderHidden(true, 500, HEADER_NEAR_TOP - 1, false)).toBe(false);
  });

  it('hides when scrolling down past the threshold', () => {
    expect(nextHeaderHidden(false, 200, 260, false)).toBe(true);
  });

  it('shows when scrolling up past the threshold', () => {
    expect(nextHeaderHidden(true, 400, 340, false)).toBe(false);
  });

  it('keeps the current state on a tiny scroll jitter', () => {
    expect(nextHeaderHidden(true, 300, 302, false)).toBe(true);
    expect(nextHeaderHidden(false, 300, 298, false)).toBe(false);
  });
});
