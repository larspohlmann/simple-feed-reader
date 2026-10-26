import { Signal, computed } from '@angular/core';
import { PlaybackPosition } from '../chapters/chapter-player';
import { chapterProgress, currentChapterIndex } from '../chapters/current-chapter';
import type { ChapterDto } from '../models';

/** Where previous chapter seeks to, or null when there is nowhere to go: the current chapter's
 *  start once `position` is past it by more than `threshold` (like `previous()` on a track),
 *  else the start of the one before. */
export function previousChapterStart(
  starts: readonly number[],
  position: number,
  threshold: number,
): number | null {
  const index = currentChapterIndex(starts, position);
  if (index === -1) return null;
  if (position - starts[index] > threshold) return starts[index];
  return starts[index - 1] ?? null;
}

/** Where next chapter seeks to: the following chapter's start, or null in the last chapter. */
export function nextChapterStart(starts: readonly number[], position: number): number | null {
  return starts[currentChapterIndex(starts, position) + 1] ?? null;
}

/** The playing track's chapters, followed from the player's position (#1810). */
export class TrackChapters {
  private readonly starts = computed(() => this.chapters().map((chapter) => chapter.startSeconds));
  /** -1 before the first chapter and without chapters. */
  readonly index = computed(() => currentChapterIndex(this.starts(), this.position().seconds));
  readonly current = computed(() => this.chapters()[this.index()] ?? null);
  /** Null without a chapter or while its end is unknown. */
  readonly progress = computed(() => {
    const index = this.index();
    return index === -1 ? null : chapterProgress(this.starts(), index, this.position());
  });
  readonly previousStart = computed(() =>
    previousChapterStart(this.starts(), this.position().seconds, this.restartThreshold),
  );
  readonly nextStart = computed(() => nextChapterStart(this.starts(), this.position().seconds));
  readonly hasPrevious = computed(() => this.previousStart() !== null);
  readonly hasNext = computed(() => this.nextStart() !== null);

  constructor(
    private readonly chapters: Signal<readonly ChapterDto[]>,
    private readonly position: Signal<PlaybackPosition>,
    private readonly restartThreshold: number,
  ) {}
}
