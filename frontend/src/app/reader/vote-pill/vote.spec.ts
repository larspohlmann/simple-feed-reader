import { nextVote, steppedScore, VoteValue } from './vote';

describe('nextVote', () => {
  it.each<[VoteValue, 'up' | 'down', VoteValue]>([
    [0, 'up', 1],
    [1, 'up', 0],
    [-1, 'up', 1],
    [0, 'down', -1],
    [-1, 'down', 0],
    [1, 'down', -1],
  ])('from %i pressing %s gives %i', (current, pressed, expected) => {
    expect(nextVote(current, pressed)).toBe(expected);
  });
});

describe('steppedScore', () => {
  it.each<[VoteValue, VoteValue, number]>([
    [0, 1, 11],
    [1, -1, 8],
    [-1, 0, 11],
  ])('moves a score of 10 from %i to %i giving %i', (from, to, expected) => {
    expect(steppedScore(10, from, to)).toBe(expected);
  });
});
