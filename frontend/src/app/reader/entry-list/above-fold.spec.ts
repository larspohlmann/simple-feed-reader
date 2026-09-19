import { entriesAboveFold } from './above-fold';

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
