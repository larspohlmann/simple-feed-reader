import type { ChapterDto } from '../models';

/** Where chapter marks sit on a scrubber, as percentages; a chapter starting at 0 draws no mark. */
export function scrubMarks(chapters: readonly ChapterDto[], duration: number): string[] {
  if (duration <= 0) return [];
  return chapters
    .map((chapter) => chapter.startSeconds)
    .filter((start) => start > 0 && start < duration)
    .map((start) => `${(start / duration) * 100}%`);
}
