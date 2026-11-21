import { nextVote, VoteValue } from './vote';

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
