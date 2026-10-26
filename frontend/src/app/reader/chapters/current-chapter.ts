import { PlaybackPosition } from './chapter-player';

export interface ChapterProgress {
  readonly fraction: number;
  readonly secondsLeft: number;
}

/** The progress of `position` through chapter `index`; null while that chapter's end is unknown. */
export function chapterProgress(
  starts: readonly number[],
  index: number,
  position: PlaybackPosition,
): ChapterProgress | null {
  const start = starts[index];
  const end = starts[index + 1] ?? position.durationSeconds;
  if (end === null || end <= start) return null;
  const seconds = Math.min(end, Math.max(start, position.seconds));
  return { fraction: (seconds - start) / (end - start), secondsLeft: end - seconds };
}

/** The chapter playing at `seconds`, as an index into ascending `starts`; -1 before the first or with no position. */
export function currentChapterIndex(starts: readonly number[], seconds: number | null): number {
  if (seconds === null) return -1;
  let low = 0;
  let high = starts.length - 1;
  let found = -1;
  while (low <= high) {
    const middle = (low + high) >> 1;
    if (starts[middle] <= seconds) {
      found = middle;
      low = middle + 1;
    } else {
      high = middle - 1;
    }
  }
  return found;
}
