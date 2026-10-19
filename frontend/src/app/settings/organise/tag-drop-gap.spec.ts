import { gapIndexAt, isNoOpGap, moveToGap } from './tag-drop-gap';

// Three panels whose midpoints sit at 50, 150 and 250 px into the tree.
const MIDDLES = [50, 150, 250];

describe('gapIndexAt', () => {
  it('opens the gap above the first tag when the pointer is above its middle', () => {
    expect(gapIndexAt(10, MIDDLES)).toBe(0);
  });

  it('opens the gap between the two tags whose middles the pointer sits between', () => {
    expect(gapIndexAt(200, MIDDLES)).toBe(2);
  });

  it('opens the gap below the last tag when the pointer is past its middle', () => {
    expect(gapIndexAt(400, MIDDLES)).toBe(3);
  });
});

describe('isNoOpGap', () => {
  it('treats the gaps right above and right below the dragged tag as no move', () => {
    expect(isNoOpGap(1, 1)).toBe(true);
    expect(isNoOpGap(1, 2)).toBe(true);
  });

  it('treats any other gap as a move', () => {
    expect(isNoOpGap(1, 0)).toBe(false);
    expect(isNoOpGap(1, 3)).toBe(false);
  });
});

describe('moveToGap', () => {
  const IDS = [2, 4, 6];

  it('moves a tag up into a gap above it', () => {
    expect(moveToGap(IDS, { from: 2, gap: 0 })).toEqual([6, 2, 4]);
  });

  it('moves a tag down into a gap below it, landing before the panel under the gap', () => {
    expect(moveToGap(IDS, { from: 0, gap: 2 })).toEqual([4, 2, 6]);
  });

  it('moves a tag below the last one', () => {
    expect(moveToGap(IDS, { from: 0, gap: 3 })).toEqual([4, 6, 2]);
  });

  it('leaves the given order untouched', () => {
    moveToGap(IDS, { from: 0, gap: 3 });

    expect(IDS).toEqual([2, 4, 6]);
  });
});
