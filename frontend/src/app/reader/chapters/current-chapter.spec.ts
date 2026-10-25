import { currentChapterIndex } from './current-chapter';

describe('currentChapterIndex', () => {
  const starts = [0, 60, 180];

  it.each([
    [null, -1],
    [0, 0],
    [59.9, 0],
    [60, 1],
    [179, 1],
    [180, 2],
    [9_999, 2],
  ])('at %p is chapter %p', (seconds, index) => {
    expect(currentChapterIndex(starts, seconds)).toBe(index);
  });

  it('is none before a first chapter that starts late', () => {
    expect(currentChapterIndex([30, 90], 10)).toBe(-1);
  });
});
