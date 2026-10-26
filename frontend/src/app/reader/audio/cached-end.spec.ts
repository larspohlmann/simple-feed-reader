import { timeRanges } from '../../../testing/time-ranges-testing';
import { cachedEnd } from './cached-end';

describe('cachedEnd', () => {
  it('is the end of the range the playhead sits in', () => {
    expect(cachedEnd(timeRanges([0, 10], [20, 75], [90, 100]), 30)).toBe(75);
  });

  it('is the playhead itself outside every range', () => {
    expect(cachedEnd(timeRanges([0, 10], [90, 100]), 50)).toBe(50);
  });

  it('is the playhead itself with nothing cached', () => {
    expect(cachedEnd(timeRanges(), 12)).toBe(12);
  });

  it('counts a range the playhead ends on', () => {
    expect(cachedEnd(timeRanges([0, 10]), 10)).toBe(10);
  });
});
