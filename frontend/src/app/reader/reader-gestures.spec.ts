import {
  OVERSCROLL_BACK_MIN,
  SWIPE_BACK_MIN_X,
  atBottom,
  isBackSwipe,
  overscrollTriggersBack,
  rubberBand,
} from './reader-gestures';

describe('isBackSwipe', () => {
  it('accepts a decisive rightward, mostly-horizontal swipe', () => {
    expect(isBackSwipe(SWIPE_BACK_MIN_X + 10, 20)).toBe(true);
  });

  it('rejects a swipe that has not travelled far enough', () => {
    expect(isBackSwipe(SWIPE_BACK_MIN_X - 1, 0)).toBe(false);
  });

  it('rejects a leftward swipe', () => {
    expect(isBackSwipe(-120, 0)).toBe(false);
  });

  it('rejects a swipe dominated by vertical movement', () => {
    expect(isBackSwipe(80, 120)).toBe(false);
  });
});

describe('overscrollTriggersBack', () => {
  it('is true only once the pull passes the threshold', () => {
    expect(overscrollTriggersBack(OVERSCROLL_BACK_MIN)).toBe(true);
    expect(overscrollTriggersBack(OVERSCROLL_BACK_MIN - 1)).toBe(false);
  });
});

describe('atBottom', () => {
  it('is true within the tolerance of the scroll end', () => {
    expect(atBottom(900, 100, 1001)).toBe(true); // 900+100 = 1000, within 2 of 1001
  });

  it('is false while there is more to scroll', () => {
    expect(atBottom(500, 100, 1000)).toBe(false);
  });
});

describe('rubberBand', () => {
  it('is zero at rest and grows with pull', () => {
    expect(rubberBand(0, 120)).toBe(0);
    expect(rubberBand(100, 120)).toBeGreaterThan(0);
  });

  it('never reaches the maximum, staying damped', () => {
    expect(rubberBand(100000, 120)).toBeLessThan(120);
    // larger pulls translate less per pixel than smaller ones (diminishing return)
    expect(rubberBand(200, 120) - rubberBand(100, 120)).toBeLessThan(
      rubberBand(100, 120) - rubberBand(0, 120),
    );
  });
});
