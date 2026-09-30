import { HEADER_NEAR_TOP, nextHeaderHidden } from './header-scroll';

describe('nextHeaderHidden', () => {
  it('never hides on a wide (desktop) layout', () => {
    expect(nextHeaderHidden({ previousHidden: true, lastTop: 0, top: 500, isWide: true })).toBe(
      false,
    );
  });

  it('shows the header near the top regardless of direction', () => {
    expect(
      nextHeaderHidden({
        previousHidden: true,
        lastTop: 500,
        top: HEADER_NEAR_TOP - 1,
        isWide: false,
      }),
    ).toBe(false);
  });

  it('hides when scrolling down past the threshold', () => {
    expect(nextHeaderHidden({ previousHidden: false, lastTop: 200, top: 260, isWide: false })).toBe(
      true,
    );
  });

  it('shows when scrolling up past the threshold', () => {
    expect(nextHeaderHidden({ previousHidden: true, lastTop: 400, top: 340, isWide: false })).toBe(
      false,
    );
  });

  it('keeps the current state on a tiny scroll jitter', () => {
    expect(nextHeaderHidden({ previousHidden: true, lastTop: 300, top: 302, isWide: false })).toBe(
      true,
    );
    expect(nextHeaderHidden({ previousHidden: false, lastTop: 300, top: 298, isWide: false })).toBe(
      false,
    );
  });
});
