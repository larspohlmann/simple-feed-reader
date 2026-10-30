import { sameIds } from './same-ids';

describe('sameIds', () => {
  it('matches the same ids in the same order only', () => {
    expect(sameIds([1, 2], [1, 2])).toBe(true);
    expect(sameIds([], [])).toBe(true);
    expect(sameIds([1, 2], [2, 1])).toBe(false);
    expect(sameIds([1], [1, 2])).toBe(false);
  });
});
