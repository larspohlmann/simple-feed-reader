import { toggled } from './toggled-set';

describe('toggled', () => {
  it('adds a value the set lacks', () => {
    expect([...toggled(new Set([1]), 2)]).toEqual([1, 2]);
  });

  it('removes a value the set holds', () => {
    expect([...toggled(new Set([1, 2]), 1)]).toEqual([2]);
  });

  it('leaves the given set untouched', () => {
    const current = new Set(['a']);
    const next = toggled(current, 'b');

    expect(next).not.toBe(current);
    expect([...current]).toEqual(['a']);
  });
});
