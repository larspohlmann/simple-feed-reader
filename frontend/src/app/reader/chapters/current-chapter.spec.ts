import { chapterProgress, currentChapterIndex } from './current-chapter';

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

describe('chapterProgress', () => {
  const starts = [0, 60, 180];
  const at = (seconds: number, durationSeconds: number | null = null) => ({
    seconds,
    durationSeconds,
  });

  it('runs from the chapter start to the next chapter start', () => {
    expect(chapterProgress(starts, 1, at(90))).toEqual({ fraction: 0.25, secondsLeft: 90 });
  });

  it('runs the last chapter to the end of the media', () => {
    expect(chapterProgress(starts, 2, at(240, 300))).toEqual({ fraction: 0.5, secondsLeft: 60 });
  });

  it('has no progress for the last chapter while the duration is unknown', () => {
    expect(chapterProgress(starts, 2, at(240))).toBeNull();
  });

  it('has no progress when the media ends before the last chapter starts', () => {
    expect(chapterProgress(starts, 2, at(170, 170))).toBeNull();
  });

  it.each([
    [at(-5), { fraction: 0, secondsLeft: 120 }],
    [at(400), { fraction: 1, secondsLeft: 0 }],
  ])('stays inside the chapter at %p', (position, progress) => {
    expect(chapterProgress(starts, 1, position)).toEqual(progress);
  });
});
