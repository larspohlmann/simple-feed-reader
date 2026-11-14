export type VoteValue = 1 | 0 | -1;

export function nextVote(current: VoteValue, pressed: 'up' | 'down'): VoteValue {
  const pressedValue = pressed === 'up' ? 1 : -1;
  return current === pressedValue ? 0 : pressedValue;
}

export function steppedScore(score: number, from: VoteValue, to: VoteValue): number {
  return score + to - from;
}
