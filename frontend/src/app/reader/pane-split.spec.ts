import {
  clampListPercent,
  DEFAULT_LIST_PERCENT,
  MAX_LIST_PERCENT,
  MIN_LIST_PERCENT,
} from './pane-split';

describe('clampListPercent', () => {
  it('returns a value inside the band unchanged', () => {
    expect(clampListPercent(50)).toBe(50);
  });

  it('clamps a below-min value up to the minimum', () => {
    expect(clampListPercent(MIN_LIST_PERCENT - 10)).toBe(MIN_LIST_PERCENT);
  });

  it('clamps an above-max value down to the maximum', () => {
    expect(clampListPercent(MAX_LIST_PERCENT + 10)).toBe(MAX_LIST_PERCENT);
  });

  it('returns the exact bounds unchanged', () => {
    expect(clampListPercent(MIN_LIST_PERCENT)).toBe(MIN_LIST_PERCENT);
    expect(clampListPercent(MAX_LIST_PERCENT)).toBe(MAX_LIST_PERCENT);
  });

  it('falls back to the default for NaN', () => {
    expect(clampListPercent(Number.NaN)).toBe(DEFAULT_LIST_PERCENT);
  });

  it('falls back to the default for infinities', () => {
    expect(clampListPercent(Number.POSITIVE_INFINITY)).toBe(DEFAULT_LIST_PERCENT);
    expect(clampListPercent(Number.NEGATIVE_INFINITY)).toBe(DEFAULT_LIST_PERCENT);
  });
});
