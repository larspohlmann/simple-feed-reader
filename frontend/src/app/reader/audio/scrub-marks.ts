import type { ChapterDto } from '../models';

/** Where chapter marks sit on a scrubber, as fractions of its length; a chapter starting at 0 draws no mark. */
export function scrubMarks(chapters: readonly ChapterDto[], duration: number): number[] {
  if (duration <= 0) return [];
  return chapters
    .map((chapter) => chapter.startSeconds)
    .filter((start) => start > 0 && start < duration)
    .map((start) => start / duration);
}
