import { entriesAboveFold, foldedGroupTailsAbove } from './above-fold';

describe('entriesAboveFold', () => {
  const items = [
    { id: 1, bottom: 40 },
    { id: 2, bottom: 90 },
    { id: 3, bottom: 150 }, // straddles the fold
    { id: 4, bottom: 260 },
  ];

  it('returns entries fully above the fold, keeping the boundary unread', () => {
    // fold at 100: 1 and 2 are fully above; 3 straddles (bottom 150 > 100).
    expect(entriesAboveFold(items, 100)).toEqual([1, 2]);
  });

  it('treats an entry whose bottom sits exactly on the fold as above', () => {
    expect(entriesAboveFold(items, 90)).toEqual([1, 2]);
  });

  it('returns nothing at the top of the list', () => {
    expect(entriesAboveFold(items, 0)).toEqual([]);
  });
});

describe('foldedGroupTailsAbove', () => {
  // A collapsed widget renders its preview (1, 2, 3) and folds 4..6 behind
  // "Show more"; only rendered rows are ever measured.
  const group = { entries: [1, 2, 3, 4, 5, 6].map((id) => ({ id })) };
  const rendered = new Set([1, 2, 3]);

  it('adds the folded tail once every rendered row of the group is above the fold', () => {
    expect(foldedGroupTailsAbove(new Set([1, 2, 3]), rendered, [group])).toEqual([4, 5, 6]);
  });

  it('keeps the tail while a rendered row of the group still straddles the fold', () => {
    expect(foldedGroupTailsAbove(new Set([1, 2]), rendered, [group])).toEqual([]);
  });

  it('ignores a group none of whose rows are rendered', () => {
    expect(foldedGroupTailsAbove(new Set([1, 2, 3]), new Set([9]), [group])).toEqual([]);
  });

  it('adds nothing for an expanded group, whose rows are all rendered', () => {
    const all = new Set([1, 2, 3, 4, 5, 6]);
    expect(foldedGroupTailsAbove(all, all, [group])).toEqual([]);
  });

  it('walks every group in order', () => {
    const other = { entries: [7, 8].map((id) => ({ id })) };
    const above = new Set([1, 2, 3, 7]);
    const measured = new Set([1, 2, 3, 7]);
    expect(foldedGroupTailsAbove(above, measured, [group, other])).toEqual([4, 5, 6, 8]);
  });
});
